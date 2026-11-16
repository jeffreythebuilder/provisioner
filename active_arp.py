#!/usr/bin/env python3
"""Best-effort, bounded on-link ARP enrichment; never a provisioning gate."""
import ipaddress
import json
from pathlib import Path
import re
import subprocess
import sys
from concurrent.futures import ThreadPoolExecutor


def private_ip(value):
    address = ipaddress.IPv4Address(value)
    if not any(address in ipaddress.ip_network(cidr) for cidr in ('10.0.0.0/8', '172.16.0.0/12', '192.168.0.0/16')):
        raise ValueError('Only RFC1918 IPv4 targets are allowed')
    return str(address)


def responses(output, ip):
    macs = set()
    for line in output.splitlines():
        match = re.search(r'(?:Unicast|Broadcast) reply from\s+(\d+\.\d+\.\d+\.\d+)\s+\[([a-f0-9:]{17})\]', line, re.I)
        if match and match[1] == ip:
            mac = match[2].replace(':', '').lower()
            if mac not in ('000000000000', 'ffffffffffff') and not int(mac[:2], 16) & 1:
                macs.add(mac)
    return sorted(macs)


def probe(target, binary, ip_binary, run=subprocess.run):
    ip = private_ip(target['ip'])
    result = {'ip': ip, 'status': 'unavailable', 'macs': []}
    try:
        route = run([ip_binary, '-j', 'route', 'get', ip], stdout=subprocess.PIPE, stderr=subprocess.PIPE, universal_newlines=True, timeout=2, check=True)
        route = json.loads(route.stdout)[0]
        interface = route.get('dev', '')
        if route.get('gateway') or route.get('type') in ('local', 'broadcast', 'unreachable') or not re.fullmatch(r'[a-zA-Z0-9_.:-]{1,32}', interface):
            return dict(result, status='not_on_link')
        addresses = run([ip_binary, '-j', '-4', 'addr', 'show', 'dev', interface], stdout=subprocess.PIPE, stderr=subprocess.PIPE, universal_newlines=True, timeout=2, check=True)
        on_link = any(ipaddress.IPv4Address(ip) in ipaddress.ip_network('%s/%s' % (a['local'], a['prefixlen']), strict=False)
                      for device in json.loads(addresses.stdout) for a in device.get('addr_info', []) if a.get('family') == 'inet')
        if not on_link:
            return dict(result, status='not_on_link')
        reply = run([binary, '-I', interface, '-c', '2', '-w', '2', ip], stdout=subprocess.PIPE, stderr=subprocess.PIPE, universal_newlines=True, timeout=4, check=False)
        macs = responses(reply.stdout, ip)
        return dict(result, status='observed' if macs else 'no_response' if reply.returncode in (0, 1) else 'unavailable', macs=macs)
    except (OSError, ValueError, IndexError, KeyError, subprocess.SubprocessError):
        return result


def main():
    targets = json.loads(Path(sys.argv[1]).read_text())
    if not isinstance(targets, list) or len(targets) > 30:
        raise ValueError('ARP refresh is limited to one rollout wave')
    binary = next((p for p in ('/usr/sbin/arping', '/usr/bin/arping', '/sbin/arping', '/bin/arping') if Path(p).is_file()), None)
    ip_binary = next((p for p in ('/usr/sbin/ip', '/usr/bin/ip', '/sbin/ip', '/bin/ip') if Path(p).is_file()), None)
    if not binary or not ip_binary:
        print(json.dumps({'results': [], 'status': 'unavailable'}))
        return
    with ThreadPoolExecutor(max_workers=8) as executor:
        results = list(executor.map(lambda target: probe(target, binary, ip_binary), targets))
    print(json.dumps({'results': results}))


if __name__ == '__main__':
    main()
