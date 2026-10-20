import contextlib
import http.client
import json
import os
from pathlib import Path
import re
import secrets
import socket
import ssl
import subprocess
import tempfile
import threading
import time
import unittest
from urllib.parse import urlsplit

import discover_sip
import send_sip_notify_bulk


ROOT = Path(__file__).resolve().parents[1]


class Phone:
    """A local handset that records every received SIP request."""
    def __init__(self, transport='udp', callback=None, context=None, forged=False):
        self.transport = transport
        self.callback = callback
        self.context = context
        self.forged = forged
        self.packets = []
        self.errors = []
        self.closed = threading.Event()
        self.socket = socket.socket(socket.AF_INET, socket.SOCK_DGRAM if transport == 'udp' else socket.SOCK_STREAM)
        self.socket.bind(('127.0.0.1', 0))
        self.port = self.socket.getsockname()[1]
        self.socket.settimeout(0.1)
        if transport != 'udp':
            self.socket.listen(5)
        self.thread = threading.Thread(target=self.serve, daemon=True)

    def __enter__(self):
        self.thread.start()
        return self

    def __exit__(self, *_):
        self.closed.set()
        self.socket.close()
        self.thread.join(timeout=3)
        if self.errors:
            raise self.errors[0]

    def reply(self, packet):
        self.packets.append(packet.decode())
        method = packet.decode().split(' ', 1)[0]
        call_id = re.search(r'(?im)^Call-ID: (.+)\r$', packet.decode()).group(1)
        return ('SIP/2.0 200 OK\r\nCall-ID: %s\r\nCSeq: 1 %s\r\n'
                'User-Agent: ALE M7 2.14.26.000.2646 3c28a621329e\r\n'
                'Allow: OPTIONS, NOTIFY\r\nContent-Length: 0\r\n\r\n' % (call_id, method)).encode()

    def serve(self):
        while not self.closed.is_set():
            try:
                if self.transport == 'udp':
                    packet, peer = self.socket.recvfrom(16384)
                    response = self.reply(packet)
                    if self.forged:
                        with socket.socket(socket.AF_INET, socket.SOCK_DGRAM) as other:
                            other.sendto(response.replace(b'3c28a621329e', b'3c28a6210000'), peer)
                        self.socket.sendto(response.replace(b'discovery-', b'wrong-call-'), peer)
                    self.socket.sendto(response, peer)
                else:
                    connection, _ = self.socket.accept()
                    with connection:
                        connection.settimeout(2)
                        if self.context:
                            connection = self.context.wrap_socket(connection, server_side=True)
                        with connection:
                            packet = b''
                            while b'\r\n\r\n' not in packet:
                                packet += connection.recv(16384)
                            head, body = packet.split(b'\r\n\r\n', 1)
                            length = int(re.search(rb'Content-Length: (\d+)', head).group(1))
                            while len(body) < length:
                                chunk = connection.recv(16384)
                                body += chunk
                                packet += chunk
                            connection.sendall(self.reply(packet))
                if self.callback:
                    self.callback(packet.decode())
            except socket.timeout:
                continue
            except OSError:
                if not self.closed.is_set():
                    self.errors.append(RuntimeError('Fake phone socket failed'))
                break
            except Exception as error:
                self.errors.append(error)
                break


@contextlib.contextmanager
def receiver(directory):
    with socket.socket() as reserve:
        reserve.bind(('127.0.0.1', 0))
        port = reserve.getsockname()[1]
    process = subprocess.Popen(['php', '-S', '127.0.0.1:%d' % port, '-t', str(ROOT)],
                               env=dict(os.environ, TMPDIR=str(directory), PROVISIONER_DISCOVERY_DIRECTORY=str(directory / ('provisioner-discovery-%d' % os.geteuid()))),
                               stdout=subprocess.DEVNULL, stderr=subprocess.DEVNULL)
    try:
        deadline = time.monotonic() + 5
        while True:
            try:
                with socket.create_connection(('127.0.0.1', port), timeout=0.1):
                    break
            except OSError:
                if process.poll() is not None or time.monotonic() >= deadline:
                    raise RuntimeError('Test receiver did not start')
                time.sleep(0.02)
        yield 'http://127.0.0.1:%d/discovery_receiver.php' % port
    finally:
        process.terminate()
        process.wait(timeout=5)


def get(url, user_agent):
    parts = urlsplit(url)
    connection = http.client.HTTPConnection(parts.hostname, parts.port, timeout=3)
    try:
        connection.request('GET', parts.path, headers={'User-Agent': user_agent})
        response = connection.getresponse()
        return response.status, response.read(), dict(response.getheaders())
    finally:
        connection.close()


class DiscoveryTests(unittest.TestCase):
    @classmethod
    def setUpClass(cls):
        cls.certificates = tempfile.TemporaryDirectory(dir=os.environ.get('TMPDIR'))
        directory = Path(cls.certificates.name)
        subprocess.run(['openssl', 'req', '-x509', '-newkey', 'rsa:2048', '-nodes', '-days', '1',
                        '-subj', '/CN=local-test-phone', '-keyout', str(directory / 'key.pem'),
                        '-out', str(directory / 'cert.pem')], check=True, stdout=subprocess.DEVNULL, stderr=subprocess.DEVNULL)
        cls.tls = ssl.SSLContext(ssl.PROTOCOL_TLS_SERVER)
        cls.tls.load_cert_chain(str(directory / 'cert.pem'), str(directory / 'key.pem'))

    @classmethod
    def tearDownClass(cls):
        cls.certificates.cleanup()

    def test_scan_transmits_only_bodyless_options_and_tracks_real_replies(self):
        with tempfile.TemporaryDirectory(dir=os.environ.get('TMPDIR')) as temporary, Phone(forged=True) as udp, Phone('tcp') as tcp:
            result = discover_sip.run({'type': 'scan', 'payload': {
                'subnet': '127.0.0.1/32', 'source_ip': '127.0.0.1', 'ports': [udp.port, tcp.port], 'tls_ports': []
            }}, Path(temporary))
            self.assertEqual(4, result['done'])
            self.assertEqual(2, len(result['responses']))
            self.assertEqual({'udp', 'tcp'}, {row['transport'] for row in result['responses']})
            for packet in udp.packets + tcp.packets:
                self.assertTrue(packet.startswith('OPTIONS sip:127.0.0.1:'))
                self.assertNotIn('Event:', packet)
                self.assertEqual('', packet.split('\r\n\r\n', 1)[1])
            self.assertTrue(all('3c28a621329e' in row['headers']['user-agent'] for row in result['responses']))
            self.assertEqual(0o600, (Path(temporary) / 'progress.json').stat().st_mode & 0o777)

    def test_tls_scan_is_options_only(self):
        with Phone('tls', context=self.tls) as phone:
            reply = discover_sip.exchange('127.0.0.1', {'ip': '127.0.0.1', 'port': phone.port, 'transport': 'tls'})
            self.assertEqual('tls', reply['transport'])
            self.assertIn('Via: SIP/2.0/TLS', phone.packets[0])
            self.assertTrue(phone.packets[0].startswith('OPTIONS '))

    def test_identify_uses_only_selected_ip_and_isolated_404_receiver(self):
        with tempfile.TemporaryDirectory(dir=os.environ.get('TMPDIR')) as temporary:
            root = Path(temporary)
            job_id, nonce = secrets.token_hex(16), secrets.token_hex(16)
            directory = root / ('provisioner-discovery-%d' % os.geteuid()) / job_id
            directory.mkdir(parents=True, mode=0o700)
            fetched = []
            agent = 'Asterisk A20 2.4.0.5722 000fd3cf40af'
            def fetch(packet):
                url = packet.split('\r\n\r\n', 1)[1]
                for filename in ('f0A20hw1.100.cfg', '000fd3cf40af.cfg'):
                    fetched.append(get(url + filename, agent))
            with receiver(root) as url, Phone(callback=fetch) as selected, Phone() as unselected:
                target = {'ip': '127.0.0.1', 'port': selected.port, 'transport': 'udp', 'nonce': nonce}
                job = {'id': job_id, 'status': 'running', 'type': 'identify', 'expires': time.time() + 60,
                       'payload': {'source_ip': '127.0.0.1', 'receiver_url': url, 'targets': [target]}}
                discover_sip.write_json(directory / 'job.json', job)
                result = discover_sip.run(job, directory)
                self.assertEqual(1, result['done'])
                self.assertEqual('000fd3cf40af', result['identities'][0]['identity']['mac'])
                self.assertEqual(2, len(result['identities'][0]['identity']['requests']))
                self.assertEqual([], unselected.packets)
                self.assertTrue(selected.packets[0].startswith('NOTIFY sip:127.0.0.1:'))
                self.assertIn('Event: ua-profile', selected.packets[0])
                self.assertEqual(url + '/' + job_id + '/' + nonce + '/', selected.packets[0].split('\r\n\r\n', 1)[1])
                for text in ('000fd3cf40af', 'ACTION-URI', 'reboot', 'check-sync', '/provisioning/'):
                    self.assertNotIn(text, selected.packets[0])
                self.assertEqual([404, 404], [status for status, _, _ in fetched])
                self.assertTrue(all(body == b'Not Found\n' for _, body, _ in fetched))
                self.assertTrue(all(headers['Cache-Control'] == 'no-store' for _, _, headers in fetched))

    def test_receiver_always_returns_404_even_for_invalid_and_config_like_paths(self):
        with tempfile.TemporaryDirectory(dir=os.environ.get('TMPDIR')) as temporary, receiver(Path(temporary)) as url:
            for suffix in ('', '/000fd3cf40af.cfg', '/' + secrets.token_hex(16) + '/' + secrets.token_hex(16) + '/f0A20hw1.100.cfg'):
                status, body, _ = get(url + suffix, 'Asterisk A20 2.4.0.5722 000fd3cf40af')
                self.assertEqual(404, status)
                self.assertEqual(b'Not Found\n', body)
            self.assertEqual([], list(Path(temporary).rglob('receipt-*.json')))

    def test_normal_push_uses_discovered_port_and_transport(self):
        for transport in ('udp', 'tcp', 'tls'):
            with self.subTest(transport=transport), Phone(transport, context=self.tls if transport == 'tls' else None) as phone:
                result = send_sip_notify_bulk.send_notify_to_phone({
                    'phone_ip': '127.0.0.1', 'pbx_ip': '127.0.0.1', 'mac': '000fd3cf40af',
                    'uri': 'http://127.0.0.1:2001/provisioning/', 'port': phone.port, 'transport': transport
                })
                self.assertTrue(result['success'])
                self.assertTrue(phone.packets[0].startswith('NOTIFY sip:127.0.0.1:%d ' % phone.port))
                self.assertIn('Via: SIP/2.0/' + transport.upper(), phone.packets[0])

    def test_stream_reply_has_a_wall_clock_deadline(self):
        stop = threading.Event()
        with socket.socket() as listener:
            listener.bind(('127.0.0.1', 0))
            listener.listen(1)
            def drip():
                connection, _ = listener.accept()
                with connection:
                    try:
                        while not stop.is_set():
                            connection.sendall(b'S')
                            stop.wait(0.05)
                    except OSError:
                        pass
            thread = threading.Thread(target=drip, daemon=True)
            thread.start()
            started = time.monotonic()
            try:
                result = discover_sip.exchange('127.0.0.1', {'ip': '127.0.0.1', 'port': listener.getsockname()[1], 'transport': 'tcp'})
                self.assertIsNone(result)
                self.assertLess(time.monotonic() - started, 2)
            finally:
                stop.set()
                thread.join(timeout=3)

    def test_scan_rejects_unbounded_ranges_invalid_ports_and_wrong_source(self):
        with tempfile.TemporaryDirectory(dir=os.environ.get('TMPDIR')) as temporary:
            for payload in ({'subnet': '10.0.0.0/8'}, {'subnet': '127.0.0.1/32', 'ports': [0]},
                            {'subnet': '127.0.0.1/32', 'source_ip': '203.0.113.254'}):
                with self.subTest(payload=payload), self.assertRaises((ValueError, OSError)):
                    discover_sip.run({'type': 'scan', 'payload': payload}, Path(temporary))
            with self.assertRaises(ValueError):
                discover_sip.exchange('127.0.0.1', {'transport': 'udp'}, 'OPTIONS', 'http://invalid/')


if __name__ == '__main__':
    unittest.main()
