#!/usr/bin/python3
import json, socket, ssl, sys, time
from concurrent.futures import ThreadPoolExecutor, as_completed

def send_notify_to_phone(phone):
    phone_ip = phone['phone_ip']
    pbx_ip = phone['pbx_ip']
    mac = phone['mac'].lower()
    uri = phone['uri']
    phone_port = phone.get('port', 5060)
    transport = phone.get('transport', 'udp').lower()
    if transport not in ('udp', 'tcp', 'tls'):
        return {'mac': mac, 'success': False, 'ip': phone_ip}
    event = phone.get('event', 'ua-profile')
    to_user = phone.get('to_user') or mac.upper()

    branch = mac[:8] if len(mac) >= 8 else mac
    call_id = "pnp-%s-%d" % (mac, int(time.time() * 1000))

    if event.upper() == 'ACTION-URI':
        body = uri if uri and uri != '-' else 'key=Autop'
        notify = "\r\n".join((
            "NOTIFY sip:%s@%s:%d SIP/2.0" % (to_user, phone_ip, phone_port),
            "Via: SIP/2.0/{transport} %s:{src_port};branch=z9hG4bK-%s" % (pbx_ip, branch),
            "From: <sip:provisioner@%s>;tag=%s" % (pbx_ip, branch),
            "To: <sip:%s@%s>" % (to_user, phone_ip),
            "Call-ID: %s" % call_id,
            "CSeq: 1 NOTIFY",
            "Allow-Events: message-summary, refer, dialog, line-seize, presence, call-info, as-feature-event, calling-name, ua-profile",
            "Max-Forwards: 70",
            "Contact: <sip:provisioner@%s:{src_port}>" % pbx_ip,
            "Event: ACTION-URI",
            "Content-Type: message/sipfrag",
            "Content-Length: %d" % len(body.encode()),
            "",
            body,
        ))
    else:
        notify = "\r\n".join((
            "NOTIFY sip:%s:%d SIP/2.0" % (phone_ip, phone_port),
            "Via: SIP/2.0/{transport} %s:{src_port};branch=z9hG4bK-%s" % (pbx_ip, branch),
            "Max-Forwards: 20",
            "Contact: <sip:provisioner@%s>" % pbx_ip,
            "To: <sip:%s@%s>" % (mac.upper(), pbx_ip),
            "From: <sip:provisioner@%s>" % pbx_ip,
            "Call-ID: %s" % call_id,
            "CSeq: 1 NOTIFY",
            "Content-Type: application/url",
            "Event: ua-profile",
            "Content-Length: %d" % len(uri),
            "",
            uri,
        ))

    s = socket.socket(socket.AF_INET, socket.SOCK_DGRAM if transport == 'udp' else socket.SOCK_STREAM)
    success = False
    try:
        s.setsockopt(socket.SOL_SOCKET, socket.SO_REUSEADDR, 1)
        s.bind((pbx_ip, 0))
        src_port = s.getsockname()[1]
        packet = notify.format(src_port=src_port, transport=transport.upper()).encode()
        s.settimeout(0.5)
        if transport != 'udp':
            s.settimeout(2)
            s.connect((phone_ip, phone_port))
            if transport == 'tls':
                context = ssl.SSLContext(ssl.PROTOCOL_TLS_CLIENT)
                context.check_hostname = False
                context.verify_mode = ssl.CERT_NONE
                s = context.wrap_socket(s, server_hostname=phone_ip)
            s.sendall(packet)
        deadline = time.monotonic() + 5
        data = b''
        for i in range(10):
            if time.monotonic() >= deadline:
                break
            s.settimeout(min(0.5, max(0.01, deadline - time.monotonic())))
            try:
                if transport == 'udp':
                    s.sendto(packet, (phone_ip, phone_port))
                    data, addr = s.recvfrom(16384)
                    if addr != (phone_ip, phone_port):
                        continue
                else:
                    chunk = s.recv(16384 - len(data))
                    if not chunk:
                        break
                    data += chunk
                if b"SIP/2.0 200" in data and b"CSeq: 1 NOTIFY" in data and ("Call-ID: " + call_id).encode() in data:
                    success = True
                    break
                if transport != 'udp' and (b'\r\n\r\n' in data or len(data) >= 16384):
                    break
            except socket.timeout:
                pass
    except OSError:
        pass
    finally:
        s.close()
    return {'mac': mac, 'success': success, 'ip': phone_ip}

def main():
    if len(sys.argv) < 2:
        print(json.dumps({'error': 'Usage: send_sip_notify_bulk.py <json_file>', 'results': {}}))
        sys.exit(1)

    with open(sys.argv[1]) as f:
        phones = json.load(f)

    results = {}
    with ThreadPoolExecutor(max_workers=50) as executor:
        futures = {executor.submit(send_notify_to_phone, p): p for p in phones}
        for future in as_completed(futures):
            r = future.result()
            results[r['mac']] = {'success': r['success'], 'ip': r['ip']}

    print(json.dumps({'results': results}))

if __name__ == '__main__':
    main()
