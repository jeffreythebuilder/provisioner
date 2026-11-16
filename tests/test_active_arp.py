import importlib.util
from pathlib import Path
import json
import subprocess
import unittest

spec = importlib.util.spec_from_file_location('active_arp', Path(__file__).resolve().parents[1] / 'active_arp.py')
arp = importlib.util.module_from_spec(spec)
spec.loader.exec_module(arp)


class ActiveArpTests(unittest.TestCase):
    def test_parsing_matches_sender_ip_and_valid_unicast_macs_only(self):
        output = ('Unicast reply from 192.168.1.50 [00:0F:D3:CF:40:6B] 1ms\n'
                  'Unicast reply from 192.168.1.51 [00:0F:D3:CF:40:6C] 1ms\n'
                  'Unicast reply from 192.168.1.50 [FF:FF:FF:FF:FF:FF] 1ms\n')
        self.assertEqual(['000fd3cf406b'], arp.responses(output, '192.168.1.50'))

    def test_routed_target_never_invokes_arping(self):
        calls = []
        def run(command, **kwargs):
            calls.append(command)
            return subprocess.CompletedProcess(command, 0, json.dumps([{'dev': 'eth0', 'gateway': '192.168.1.1'}]), '')
        self.assertEqual('not_on_link', arp.probe({'ip': '192.168.2.50'}, '/mock/arping', '/mock/ip', run)['status'])
        self.assertEqual(1, len(calls))

    def test_on_link_probe_is_bounded_and_uses_the_selected_interface(self):
        calls = []
        def run(command, **kwargs):
            self.assertNotIn('capture_output', kwargs)
            self.assertNotIn('text', kwargs)
            self.assertTrue(kwargs['universal_newlines'])
            calls.append((command, kwargs))
            if 'route' in command:
                result = json.dumps([{'dev': 'eth0'}])
            elif 'addr' in command:
                result = json.dumps([{'addr_info': [{'family': 'inet', 'local': '192.168.1.13', 'prefixlen': 24}]}])
            else:
                result = 'Unicast reply from 192.168.1.50 [00:0F:D3:CF:40:6B] 1ms\n'
            return subprocess.CompletedProcess(command, 0, result, '')
        result = arp.probe({'ip': '192.168.1.50'}, '/mock/arping', '/mock/ip', run)
        self.assertEqual('observed', result['status'])
        self.assertEqual(['000fd3cf406b'], result['macs'])
        self.assertEqual(['/mock/arping', '-I', 'eth0', '-c', '2', '-w', '2', '192.168.1.50'], calls[-1][0])
        self.assertLessEqual(calls[-1][1]['timeout'], 4)

    def test_permission_failure_and_no_reply_are_nonfatal_results(self):
        for code, expected in [(1, 'no_response'), (2, 'unavailable')]:
            def run(command, **kwargs):
                if 'route' in command:
                    return subprocess.CompletedProcess(command, 0, '[{"dev":"eth0"}]', '')
                if 'addr' in command:
                    return subprocess.CompletedProcess(command, 0, '[{"addr_info":[{"family":"inet","local":"192.168.1.13","prefixlen":24}]}]', '')
                return subprocess.CompletedProcess(command, code, '', 'permission denied')
            self.assertEqual(expected, arp.probe({'ip': '192.168.1.50'}, '/mock/arping', '/mock/ip', run)['status'])

    def test_public_loopback_and_shell_inputs_are_rejected_before_execution(self):
        for address in ['8.8.8.8', '127.0.0.1', '192.168.1.1; touch /tmp/bad']:
            with self.assertRaises(ValueError):
                arp.probe({'ip': address}, '/mock/arping', '/mock/ip', lambda *args, **kwargs: self.fail('Must not execute'))


if __name__ == '__main__':
    unittest.main()
