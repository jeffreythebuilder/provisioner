#!/usr/bin/env python3
"""SIP enrichment after the worker's local ping/ARP sweep; explicit identification uses NOTIFY."""
import argparse
import ipaddress
import json
import os
import re
import secrets
import socket
import ssl
import time
from concurrent.futures import ThreadPoolExecutor, as_completed
from pathlib import Path


def write_json(path, data):
    temporary = str(path) + '.' + secrets.token_hex(6)
    with open(temporary, 'w') as stream:
        json.dump(data, stream)
    os.chmod(temporary, 0o600)
    os.replace(temporary, path)


def parse_reply(raw, call_id, method):
    lines = raw.decode('utf-8', 'replace').split('\r\n')
    if not re.match(r'^SIP/2\.0\s+[2-6]\d{2}\b', lines[0]):
        return None
    headers = {}
    aliases = {'i': 'call-id', 'm': 'contact', 'l': 'content-length'}
    for line in lines[1:]:
        if not line:
            break
        if ':' in line:
            key, value = line.split(':', 1)
            key = aliases.get(key.lower().strip(), key.lower().strip())
            headers[key] = value.strip()[:1024]
    if headers.get('call-id') != call_id or not re.match(r'^1\s+' + method + r'$', headers.get('cseq', ''), re.I):
        return None
    keep = {'user-agent', 'server', 'contact', 'allow', 'supported', 'mac', 'x-mac', 'x-mac-address'}
    return {'status': lines[0], 'headers': {key: value for key, value in headers.items() if key in keep}}


def exchange(source, target, method='OPTIONS', url=None):
    if method not in ('OPTIONS', 'NOTIFY') or (method == 'NOTIFY' and not url) or (method == 'OPTIONS' and url):
        raise ValueError('Invalid discovery request')
    token = secrets.token_hex(16)
    call_id = 'discovery-' + token + '@' + source
    transport = target['transport']
    if transport not in ('udp', 'tcp', 'tls'):
        raise ValueError('Invalid SIP transport')
    tcp = transport != 'udp'
    sock = socket.socket(socket.AF_INET, socket.SOCK_STREAM if tcp else socket.SOCK_DGRAM)
    start = time.monotonic()
    try:
        sock.bind((source, 0))
        port = sock.getsockname()[1]
        uri = 'sip:%s:%d' % (target['ip'], target['port'])
        headers = [
            '%s %s SIP/2.0' % (method, uri),
            'Via: SIP/2.0/%s %s:%d;branch=z9hG4bK-%s;rport' % (transport.upper(), source, port, token),
            'Max-Forwards: 20',
            'From: <sip:provisioner-discovery@%s>;tag=%s' % (source, token),
            'To: <%s>' % uri,
            'Call-ID: ' + call_id,
            'CSeq: 1 ' + method,
            'Contact: <sip:provisioner-discovery@%s:%d>' % (source, port),
        ]
        body = url or ''
        if method == 'NOTIFY':
            headers += ['Event: ua-profile', 'Content-Type: application/url']
        headers += ['Content-Length: %d' % len(body.encode()), '', body]
        packet = '\r\n'.join(headers).encode()
        sock.settimeout(0.8 if method == 'OPTIONS' else 2)
        if tcp:
            deadline = start + (1.5 if method == 'OPTIONS' else 3)
            sock.connect((target['ip'], target['port']))
            if transport == 'tls':
                # Discovery reads self-signed handset SIP identities, never configuration.
                context = ssl.SSLContext(ssl.PROTOCOL_TLS_CLIENT)
                context.check_hostname = False
                context.verify_mode = ssl.CERT_NONE
                sock = context.wrap_socket(sock, server_hostname=target['ip'])
            sock.settimeout(max(0.01, deadline - time.monotonic()))
            sock.sendall(packet)
            raw = b''
            while b'\r\n\r\n' not in raw and len(raw) < 16384 and time.monotonic() < deadline:
                sock.settimeout(max(0.01, deadline - time.monotonic()))
                chunk = sock.recv(16384 - len(raw))
                if not chunk:
                    break
                raw += chunk
            reply = parse_reply(raw, call_id, method)
        else:
            reply = None
            for window in ((0.4, 0.8) if method == 'OPTIONS' else (1.2, 1.2)):
                sock.sendto(packet, (target['ip'], target['port']))
                deadline = time.monotonic() + window
                while time.monotonic() < deadline:
                    sock.settimeout(max(0.01, deadline - time.monotonic()))
                    try:
                        raw, peer = sock.recvfrom(16384)
                    except socket.timeout:
                        break
                    if peer != (target['ip'], target['port']):
                        continue
                    reply = parse_reply(raw, call_id, method)
                    if reply:
                        break
                if reply:
                    break
        if reply:
            reply.update(ip=target['ip'], port=target['port'], transport=transport, seen_at=time.time(), elapsed_ms=round((time.monotonic() - start) * 1000, 1))
        return reply
    except OSError:
        return None
    finally:
        sock.close()


def identify(job, target, directory):
    url = '%s/%s/%s/' % (job['payload']['receiver_url'].rstrip('/'), job['id'], target['nonce'])
    reply = exchange(job['payload']['source_ip'], target, 'NOTIFY', url)
    receipt = directory / ('receipt-' + target['nonce'] + '.json')
    deadline = time.monotonic() + 12
    while time.monotonic() < deadline:
        try:
            identity = json.loads(receipt.read_text())
            if identity.get('ip') == target['ip'] and identity.get('http_status') == 404 and identity.get('mac'):
                # Give the handset's per-MAC request a moment to follow its model request.
                time.sleep(0.4)
                identity = json.loads(receipt.read_text())
                return {'ip': target['ip'], 'identity': identity, 'sip_response': reply}
        except (OSError, ValueError):
            pass
        if reply and not reply['status'].startswith('SIP/2.0 200'):
            break
        time.sleep(0.1)
    return {'ip': target['ip'], 'identity': None, 'sip_response': reply, 'error': 'No supported MAC identity was received from this IP.'}


def run(job, directory):
    payload = job['payload']
    if job['type'] == 'scan':
        network = ipaddress.ip_network(payload['subnet'], strict=False)
        if network.version != 4 or network.num_addresses > 1024:
            raise ValueError('Scan is limited to an IPv4 /22 or smaller range')
        ports = sorted(set(payload.get('ports', [5060, 5160])))
        tls_ports = sorted(set(payload.get('tls_ports', [5061])))
        if not ports or len(ports) > 3 or len(tls_ports) > 2 or any(not 1 <= port <= 65535 for port in ports + tls_ports):
            raise ValueError('Invalid discovery ports')
        endpoints = [(port, transport) for port in ports for transport in ('udp', 'tcp')] + [(port, 'tls') for port in tls_ports]
        targets = [{'ip': str(ip), 'port': port, 'transport': transport} for ip in network.hosts() for port, transport in endpoints]
        # Fail visibly if the configured PBX source cannot be bound.
        with socket.socket(socket.AF_INET, socket.SOCK_DGRAM) as check:
            check.bind((payload['source_ip'], 0))
        function = lambda target: exchange(payload['source_ip'], target)
        key = 'responses'
        workers = 64
    elif job['type'] == 'identify':
        targets = payload['targets']
        if not targets or len(targets) > 32:
            raise ValueError('Select between 1 and 32 unidentified devices')
        function = lambda target: identify(job, target, directory)
        key = 'identities'
        workers = 16
    else:
        raise ValueError('Invalid discovery action')
    result = {'phase': 'sip_options' if job['type'] == 'scan' else 'identify', 'done': 0, 'total': len(targets), key: []}
    path = directory / 'progress.json'
    write_json(path, result)
    with ThreadPoolExecutor(max_workers=workers) as pool:
        futures = [pool.submit(function, target) for target in targets]
        for future in as_completed(futures):
            reply = future.result()
            if reply:
                result[key].append(reply)
            result['done'] += 1
            # Avoid a filesystem write for every unanswered port.
            if reply or result['done'] % 16 == 0 or result['done'] == result['total']:
                write_json(path, result)
    return result


def main():
    parser = argparse.ArgumentParser()
    parser.add_argument('job_file')
    args = parser.parse_args()
    path = Path(args.job_file)
    job = json.loads(path.read_text())
    if job.get('status') != 'running' or job.get('expires', 0) < time.time():
        raise SystemExit('Inactive discovery job')
    os.umask(0o077)
    run(job, path.parent)


if __name__ == '__main__':
    main()
