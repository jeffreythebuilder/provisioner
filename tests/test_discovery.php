<?php

use FreePBX\modules\Provisioner\SipDiscovery;

test('handset identity requires a supported model and matching MAC evidence', function () {
    $digium = SipDiscovery::identity('Asterisk A20 2.4.0.5722 000fd3cf40af', 'f0A20hw1.100.cfg');
    assertSame('000fd3cf40af', $digium['mac']);
    assertSame('digium', $digium['brand']);
    assertSame('A20', $digium['model']);
    assertSame('2.4.0.5722', $digium['firmware']);
    $ale = SipDiscovery::identity('ALE M3s 2.16.03.000.3164 3c:28:a6:21:5f:53 SER251106192');
    assertSame('3c28a6215f53', $ale['mac']);
    assertSame('alcatel', $ale['brand']);
    assertSame('000fd3cf40af', SipDiscovery::identity('Asterisk A20 Phone 2.4.0.5722', '000fd3cf40af.cfg')['mac']);
    assertSame(null, SipDiscovery::identity('Asterisk PBX 18.9.0 000fd3cf40af'));
    assertSame(null, SipDiscovery::identity('Yealink T46 1.2.3 805ec0000030'));
    assertSame(null, SipDiscovery::identity('ALE M7 2.14.26.000.2646 000fd3cf40af'));
    assertSame(null, SipDiscovery::identity('Asterisk A20 2.4.0.5722 000fd3cf40af', '000fd3cf40aa.cfg'));
});

test('identity receiver is bound to an active job, selected IP, nonce, and handset MAC', function () {
    [$module, $db, $directory] = makeFixture();
    $store = new SipDiscovery($directory . '/jobs');
    $nonce = bin2hex(random_bytes(16));
    $job = $store->create('identify', ['scan_id' => bin2hex(random_bytes(16)), 'targets' => [
        ['nonce' => $nonce, 'ip' => '192.168.188.68', 'port' => 5060, 'transport' => 'udp']
    ]]);
    $agent = 'Asterisk A20 2.4.0.5722 000fd3cf40af';
    $receive = function ($ip, $token, $ua = null, $filename = '000fd3cf40af.cfg') use ($store, &$job, $agent) {
        return $store->receive($job['id'], $token, $ip, $ua ?? $agent, $filename);
    };
    assertTrue(!$receive('192.168.188.68', $nonce), 'Queued jobs cannot accept callbacks');
    $job['status'] = 'running'; $store->save($job);
    assertTrue(!$receive('192.168.188.67', $nonce));
    assertTrue(!$receive('192.168.188.68', bin2hex(random_bytes(16))));
    assertTrue(!$receive('192.168.188.68', $nonce, 'Panasonic 1.2.3 0080f04d2444'));
    assertTrue(!$receive('192.168.188.68', $nonce, null, '../000fd3cf40af.cfg'));
    assertTrue($receive('192.168.188.68', $nonce, null, 'f0A20hw1.100.cfg'));
    assertTrue($receive('192.168.188.68', $nonce));
    $receipt = json_decode(file_get_contents($store->path($job['id'], 'receipt-' . $nonce . '.json')), true);
    assertSame(404, $receipt['http_status']);
    assertSame('000fd3cf40af', $receipt['mac']);
    assertSame(2, count($receipt['requests']));
    assertTrue(!$receive('192.168.188.68', $nonce, 'Asterisk A20 2.4.0.5722 000fd3cf40aa', '000fd3cf40aa.cfg'));
    $job['status'] = 'complete'; $store->save($job);
    assertTrue(!$receive('192.168.188.68', $nonce));
    $job['status'] = 'running'; $job['expires'] = time() - 1; $store->save($job);
    assertTrue(!$receive('192.168.188.68', $nonce));
    assertSame(0, (int)$db->query('SELECT COUNT(*) FROM provisioner_devices')->fetchColumn());
    assertSame(['jobs'], array_values(array_diff(scandir($directory), ['.', '..'])), 'Receiver creates no config files');
});

test('discovery execution limits concurrent jobs and duplicate identify actions', function () {
    [, , $directory] = makeFixture();
    $store = new SipDiscovery($directory . '/jobs');
    $scan = $store->create('scan', []);
    $identify = $store->create('identify', ['scan_id' => $scan['id']]);
    $rejected = 0;
    foreach ([['scan', []], ['identify', ['scan_id' => $scan['id']]]] as $request) {
        try { $store->create($request[0], $request[1]); } catch (RuntimeException $error) { $rejected++; }
    }
    assertSame(2, $rejected);
    assertSame(0700, fileperms($store->directory()) & 0777);
    assertSame(0600, fileperms($store->path($scan['id'])) & 0777);
    $identify['status'] = 'complete'; $store->save($identify);
    assertSame('scan', $store->create('scan', [])['type']);
});

test('SIP scan keeps unknown IPs distinct and prioritizes supported identities over generic replies', function () {
    [$module, $db] = makeFixture();
    insertDevice($db, '3c28a6215f53', '2000', 'alcatel');
    $reply = function ($ip, $agent = '', $transport = 'udp') {
        return ['ip' => $ip, 'port' => 5060, 'transport' => $transport, 'status' => 'SIP/2.0 200 OK',
            'headers' => ['user-agent' => $agent, 'allow' => 'INVITE, ACK, OPTIONS, NOTIFY']];
    };
    $responses = [$reply('192.168.188.68'), $reply('192.168.188.67'),
        $reply('192.168.188.84', 'ALE M3s 2.16.03.000.3164 3c28a6215f53', 'tcp'), $reply('192.168.188.84'),
        $reply('192.168.188.80', 'Asterisk A20 Phone 2.10.0.6746'),
        $reply('192.168.188.54', 'Panasonic_KX-UT123X/01.294 (0080f04d2444)'),
        $reply('192.168.188.64', 'Asterisk PBX 18.9.0-8.0.1')];
    $rows = invokePrivate($module, 'formatSipDiscoveryResponses', $responses);
    assertSame(4, count($rows));
    $byIp = array_column($rows, null, 'ip');
    assertSame('IP:192.168.188.68', $byIp['192.168.188.68']['id']);
    assertSame('IP:192.168.188.67', $byIp['192.168.188.67']['id']);
    assertTrue($byIp['192.168.188.80']['can_identify']);
    assertSame('3C28A6215F53', $byIp['192.168.188.84']['mac']);
    assertSame('tcp', $byIp['192.168.188.84']['sip_transport']);
    assertTrue($byIp['192.168.188.84']['in_provisioner']);
    $registrations = [['ip' => '192.168.188.84', 'mac' => '3C28A6215F53', 'brand' => 'alcatel', 'extension' => '2000', 'model' => '', 'firmware' => '', 'source' => 'PJSIP User-Agent']];
    $merged = invokePrivate($module, 'formatSipDiscoveryResponses', $responses, $registrations);
    assertSame('M3s', array_column($merged, null, 'ip')['192.168.188.84']['model']);
});

test('SIP discovery reports excluded responders with a plain-language reason', function () {
    [$module] = makeFixture();
    $reply = function ($ip, $status, $agent, $allow) {
        return ['ip'=>$ip,'port'=>5060,'transport'=>'udp','status'=>$status,
            'headers'=>['user-agent'=>$agent,'allow'=>$allow]];
    };
    $responses = [
        $reply('192.168.188.60','SIP/2.0 200 OK','','INVITE, NOTIFY'),
        $reply('192.168.188.61','SIP/2.0 200 OK','Yealink T46 1.2.3','INVITE, NOTIFY'),
        $reply('192.168.188.62','SIP/2.0 403 Forbidden','',''),
        $reply('192.168.188.63','SIP/2.0 200 OK','','INVITE, ACK')
    ];
    $excluded = [];
    $args = [$responses, [], [], &$excluded];
    $method = new ReflectionMethod($module, 'formatSipDiscoveryResponses');
    $method->setAccessible(true);
    $rows = $method->invokeArgs($module, $args);
    assertSame(1,count($rows)); assertSame('IP:192.168.188.60',$rows[0]['id']);
    assertSame(3,count($excluded));
    assertSame('Unrecognized or unsupported SIP User-Agent.',$excluded[0]['reason']);
    assertSame('SIP response was not 200 OK.',$excluded[1]['reason']);
    assertSame('SIP responder did not advertise NOTIFY.',$excluded[2]['reason']);
});

test('local discovery sweeps only directly connected portions of the requested range', function () {
    [$module] = makeFixture();
    $local = ['172.16.138.0/24', '172.16.139.0/24', '172.16.138.128/25'];
    assertSame(['172.16.138.0/24'], invokePrivate($module, 'getLocalDiscoverySubnets', '172.16.138.13/24', $local));
    assertSame(['172.16.138.128/25'], invokePrivate($module, 'getLocalDiscoverySubnets', '172.16.138.150/25', $local));
    assertSame(['172.16.138.50/32'], invokePrivate($module, 'getLocalDiscoverySubnets', '172.16.138.50/32', $local));
    assertSame(['172.16.138.50/31'], invokePrivate($module, 'getLocalDiscoverySubnets', '172.16.138.50/31', $local));
    assertSame(['172.16.138.0/24', '172.16.139.0/24'], invokePrivate($module, 'getLocalDiscoverySubnets', '172.16.136.0/22', $local));
    assertSame([], invokePrivate($module, 'getLocalDiscoverySubnets', '192.168.188.0/24', $local));
    assertSame([], invokePrivate($module, 'getLocalDiscoverySubnets', '172.16.128.0/21', $local));
});

test('local ARP discovers SIP-silent phones and supplements anonymous replies without a fetch', function () {
    [$module, $db, $directory] = makeFixture();
    insertDevice($db, '000fd3000600', '200');
    $db->exec("UPDATE provisioner_devices SET last_push_status='verified'");
    file_put_contents($directory . '/000fd3000600.cfg', 'Existing handset configuration');
    $before = $db->query('SELECT * FROM provisioner_devices')->fetchAll(PDO::FETCH_ASSOC);
    $neighbors = invokePrivate($module, 'parseSubnetNeighborEntries', implode("\n", [
        '172.16.138.50 dev eth0 lladdr 00:0f:d3:00:06:00 REACHABLE',
        '172.16.138.51 dev eth0 lladdr 00:0f:d3:00:06:01 DELAY',
        '172.16.138.52 dev eth0 lladdr 3c:28:a6:00:06:02 REACHABLE',
        '172.16.138.53 dev eth0 lladdr 00:0f:d3:00:06:03 STALE',
        '172.16.138.54 dev eth0 lladdr 80:5e:c0:00:06:04 REACHABLE',
        '172.16.138.55 dev eth0 lladdr 3c:28:a6:00:06:05 STALE'
    ]), '172.16.138.0/24');
    $local = invokePrivate($module, 'formatLocalDiscoveryNeighbors', $neighbors);
    assertSame(5, count($local), 'Only Digium/ALE ARP identities are offered');
    $reply = function ($ip, $agent = '', $transport = 'udp') {
        return ['ip' => $ip, 'port' => 5160, 'transport' => $transport, 'status' => 'SIP/2.0 200 OK',
            'headers' => ['user-agent' => $agent, 'allow' => 'OPTIONS, NOTIFY']];
    };
    $rows = invokePrivate($module, 'formatSipDiscoveryResponses', [
        $reply('172.16.138.51'),
        $reply('172.16.138.52', 'ALE M7 2.14.26.000.2646 3c28a6000602', 'tcp'),
        $reply('172.16.138.53')
    ], [], $local);
    $byIp = array_column($rows, null, 'ip');
    assertSame('000FD3000600', $byIp['172.16.138.50']['mac'], 'A SIP-silent, unregistered phone is still found');
    assertTrue($byIp['172.16.138.50']['in_provisioner']);
    assertSame('Local ping/ARP', $byIp['172.16.138.50']['source']);
    assertSame('000FD3000601', $byIp['172.16.138.51']['mac']);
    assertTrue(!$byIp['172.16.138.51']['can_identify']);
    assertTrue(strpos($byIp['172.16.138.51']['source'], 'Local ping/ARP + SIP OPTIONS') === 0);
    assertSame('M7', $byIp['172.16.138.52']['model']);
    assertSame('2.14.26.000.2646', $byIp['172.16.138.52']['firmware']);
    assertSame('tcp', $byIp['172.16.138.52']['sip_transport']);
    assertSame(5160, $byIp['172.16.138.52']['sip_port']);
    assertSame('IP:172.16.138.53', $byIp['172.16.138.53']['id'], 'A stale ARP MAC cannot identify this responder');
    assertTrue(!$byIp['172.16.138.55']['seen_now']);
    assertTrue(!isset($byIp['172.16.138.54']));
    invokePrivate($module, 'saveDiscoveryObservations', $rows);
    $observed = invokePrivate($module, 'getDiscoveryObservations');
    assertSame(3, count($observed), 'Only current, confirmed local identities are persisted');
    assertSame($before, $db->query('SELECT * FROM provisioner_devices')->fetchAll(PDO::FETCH_ASSOC));
    assertSame('Existing handset configuration', file_get_contents($directory . '/000fd3000600.cfg'));
    $remembered = invokePrivate($module, 'formatLocalDiscoveryNeighbors', $neighbors);
    assertSame('M7', array_column($remembered, null, 'ip')['172.16.138.52']['model'], 'ARP-only refreshes retain known model and endpoint information');
    assertSame(5160, array_column($remembered, null, 'ip')['172.16.138.52']['sip_port']);
    $registrations = [['ip' => '172.16.138.50', 'mac' => '000FD3000600', 'brand' => 'digium', 'extension' => '200', 'source' => 'PJSIP + ARP']];
    $merged = invokePrivate($module, 'formatSipDiscoveryResponses', [], $registrations, $local);
    assertSame('200', array_column($merged, null, 'ip')['172.16.138.50']['extension']);
});

test('local ARP and SIP identity conflicts are visible and cannot refresh MAC/IP evidence', function () {
    [$module] = makeFixture();
    $neighbors = invokePrivate($module, 'parseSubnetNeighborEntries',
        "172.16.138.50 dev eth0 lladdr 00:0f:d3:00:06:10 REACHABLE\n172.16.138.51 dev eth0 lladdr 00:0f:d3:00:06:11 STALE", '172.16.138.0/24');
    $local = invokePrivate($module, 'formatLocalDiscoveryNeighbors', $neighbors);
    $responses = [['ip' => '172.16.138.50', 'port' => 5060, 'transport' => 'udp', 'status' => 'SIP/2.0 200 OK',
        'headers' => ['user-agent' => 'Asterisk A20 2.4.0.5722 000fd3000612']]];
    $registration = ['ip' => '172.16.138.50', 'mac' => '000FD3000612', 'brand' => 'digium', 'extension' => '200', 'source' => 'PJSIP User-Agent'];
    $rows = invokePrivate($module, 'formatSipDiscoveryResponses', $responses, [$registration], $local);
    $row = array_column($rows, null, 'ip')['172.16.138.50'];
    assertTrue($row['identity_conflict']);
    assertTrue(!$row['is_supported'] && !$row['can_identify']);
    assertTrue(empty($row['extension']), 'Do not attach the other handset registration');
    invokePrivate($module, 'saveDiscoveryObservations', $rows);
    assertSame([], invokePrivate($module, 'getDiscoveryObservations'));
    // Current identity supersedes a stale different MAC without borrowing its model.
    $registration['ip'] = '172.16.138.51';
    $rows = invokePrivate($module, 'formatSipDiscoveryResponses', [], [$registration], $local);
    $row = array_column($rows, null, 'ip')['172.16.138.51'];
    assertSame('000FD3000612', $row['mac']);
    assertTrue($row['seen_now']);
});

test('asynchronous discovery runs three local ARP passes before SIP and skips them for routed scans', function () {
    [$module, $db, $directory] = makeFixture();
    $bin = $directory . '/bin'; mkdir($bin);
    $commands = $directory . '/ping-commands';
    file_put_contents($bin . '/ping', "#!/bin/sh\nprintf '%s\\n' \"\$*\" >> " . escapeshellarg($commands) . "\n");
    file_put_contents($bin . '/ip', "#!/bin/sh\ncase \"\$*\" in\n"
        . "'-o link show') echo '2: testeth0: <BROADCAST,MULTICAST,UP,LOWER_UP> mtu 1500 state UP' ;;\n"
        . "'-o -f inet addr show up') echo '2: testeth0 inet 172.16.138.13/24 brd 172.16.138.255 scope global testeth0' ;;\n"
        . "'neigh show to 172.16.138.48/29')\n"
        . "  if test -f " . escapeshellarg($commands) . "; then echo '172.16.138.50 dev testeth0 lladdr 00:0f:d3:00:06:20 REACHABLE'; fi\n"
        . "  echo '172.16.138.51 dev testeth0 lladdr 3c:28:a6:00:06:21 STALE' ;;\nesac\n");
    file_put_contents($bin . '/asterisk', "#!/bin/sh\nexit 0\n");
    file_put_contents($bin . '/python', <<<'PHP'
#!/usr/bin/php
<?php
$directory = dirname($argv[2]);
$job = json_decode(file_get_contents($argv[2]), true);
if ($job['payload']['local_subnets']) {
    $progress = json_decode(file_get_contents($directory . '/progress.json'), true);
    if ($progress['phase'] !== 'local_arp' || $progress['done'] !== 3 || count($job['payload']['local_devices']) !== 2) exit(1);
    $ip = '172.16.138.50';
} else {
    if ($job['payload']['local_devices']) exit(1);
    $ip = '192.168.188.68';
}
file_put_contents($directory . '/progress.json', json_encode(['phase' => 'sip_options', 'done' => 1, 'total' => 1, 'responses' => [
    ['ip' => $ip, 'port' => 5060, 'transport' => 'udp', 'status' => 'SIP/2.0 200 OK', 'headers' => ['allow' => 'OPTIONS, NOTIFY']]
]]));
PHP
    );
    foreach (glob($bin . '/*') as $file) chmod($file, 0700);
    $python = new ReflectionProperty($module, 'python3BinaryCache'); $python->setAccessible(true); $python->setValue($module, $bin . '/python');
    $path = getenv('PATH'); $jobs = getenv('PROVISIONER_DISCOVERY_DIRECTORY');
    putenv('PATH=' . $bin . ':' . $path); putenv('PROVISIONER_DISCOVERY_DIRECTORY=' . $directory . '/jobs');
    try {
        $store = new SipDiscovery();
        $payload = ['source_ip' => '127.0.0.1', 'ports' => [5060], 'tls_ports' => [], 'registrations' => []];
        $local = $store->create('scan', $payload + ['subnet' => '172.16.138.48/29']);
        assertTrue($module->processDiscoveryJob($local['id']));
        $status = $module->getDiscoveryJobStatus($local['id']);
        assertTrue($status['complete']); assertSame('local', $status['scan_mode']); assertSame('sip_options', $status['phase']);
        assertSame('000FD3000620', array_column($status['devices'], null, 'ip')['172.16.138.50']['mac']);
        assertSame(18, count(file($commands)), 'All six hosts receive three ping passes');
        $routed = $store->create('scan', $payload + ['subnet' => '192.168.188.0/24']);
        assertTrue($module->processDiscoveryJob($routed['id']));
        $status = $module->getDiscoveryJobStatus($routed['id']);
        assertTrue($status['complete']); assertSame('routed', $status['scan_mode']);
        assertSame('IP:192.168.188.68', $status['devices'][0]['id']);
        assertSame(18, count(file($commands)), 'No routed targets were sent a local ping/ARP sweep');
        assertSame(0, (int)$db->query('SELECT COUNT(*) FROM provisioner_devices')->fetchColumn());
    } finally {
        putenv('PATH=' . $path);
        putenv($jobs === false ? 'PROVISIONER_DISCOVERY_DIRECTORY' : 'PROVISIONER_DISCOVERY_DIRECTORY=' . $jobs);
    }
});

test('MAC/IP evidence persists independently of mappings without changing configs or push status', function () {
    [$module, $db, $directory] = makeFixture();
    $mac = '000fd3cf40af';
    insertDevice($db, $mac, '200');
    $db->exec("UPDATE provisioner_devices SET last_push_status='verified', ip_address='192.168.188.99'");
    file_put_contents($directory . '/' . $mac . '.cfg', 'Existing handset settings');
    $before = $db->query('SELECT * FROM provisioner_devices')->fetchAll(PDO::FETCH_ASSOC);
    $row = ['mac' => strtoupper($mac), 'ip' => '192.168.188.68', 'brand' => 'digium', 'model' => 'A20',
        'firmware' => '2.4.0.5722', 'sip_port' => 5160, 'sip_transport' => 'tcp', 'source' => 'SIP discovery fetch (HTTP 404)',
        'seen_now' => true, 'last_seen' => date('Y-m-d H:i:s')];
    invokePrivate($module, 'saveDiscoveryObservations', [$row]);
    $observed = invokePrivate($module, 'getDiscoveryObservations')[$mac];
    assertSame('192.168.188.68', $observed['ip_address']);
    assertSame('tcp', $observed['sip_transport']);
    assertSame('5160', (string)$observed['sip_port']);
    assertSame($before, $db->query('SELECT * FROM provisioner_devices')->fetchAll(PDO::FETCH_ASSOC));
    assertSame('Existing handset settings', file_get_contents($directory . '/' . $mac . '.cfg'));
    // A late result cannot overwrite newer handset/IP evidence or refresh its timestamp.
    $old = array_replace($row, ['ip' => '192.168.188.69', 'last_seen' => date('Y-m-d H:i:s', time() - 60)]);
    invokePrivate($module, 'saveDiscoveryObservations', [$old]);
    assertSame($observed, invokePrivate($module, 'getDiscoveryObservations')[$mac]);
    $newOwner = array_replace($row, ['mac' => '000fd3cf40aa']);
    invokePrivate($module, 'saveDiscoveryObservations', [$newOwner]);
    assertTrue(!isset(invokePrivate($module, 'getDiscoveryObservations')[$mac]), 'An IP has only one current MAC owner');
});

test('cross-subnet pushes require a mapped extension and fresh matching handset MAC/IP evidence', function () {
    [$module, $db] = makeFixture();
    $mac = '000fd3000500'; $ip = '192.168.188.68';
    insertDevice($db, $mac, '200');
    $device = ['mac_address' => $mac, 'extension' => '200', 'ip_address' => $ip, 'static_ip' => $ip, 'needs_changes' => 1];
    $local = ['172.16.138.0/24'];
    $contacts = ['200' => ['ip' => $ip, 'status' => 'Avail']];
    $resolve = function ($contacts, $observations) use ($module, $device, $local) {
        return invokePrivate($module, 'resolveDeviceTarget', $device, $contacts, [], [], false, $local, $observations);
    };
    $blocked = $resolve($contacts, []);
    assertSame(null, $blocked['selected_ip']);
    assertTrue($blocked['mac_confirmation_required']);
    assertTrue(strpos($blocked['blocked_reason'], 'Identify MAC') !== false);
    assertTrue(invokePrivate($module, 'getPushIdentityError', $ip, $mac, $contacts, [], $local) !== null);
    $contacts['200']['mac'] = $mac;
    assertSame($ip, $resolve($contacts, [])['selected_ip']);
    assertSame(null, invokePrivate($module, 'getPushIdentityError', $ip, $mac, $contacts, [], $local));
    $contacts['200']['status'] = 'Unavail';
    assertSame(null, $resolve($contacts, [])['selected_ip']);
    $observations = [$mac => ['ip_address' => $ip, 'sip_port' => 5160, 'sip_transport' => 'tcp', 'source' => 'SIP discovery fetch', 'last_seen' => date('Y-m-d H:i:s')]];
    $identified = $resolve([], $observations);
    assertSame($ip, $identified['selected_ip']);
    assertSame(5160, $identified['selected_port']);
    assertSame('tcp', $identified['selected_transport']);
    assertSame('mac_identified', $identified['confidence']);
    assertSame(null, invokePrivate($module, 'getPushIdentityError', $ip, $mac, [], $observations, $local));
    $observations[$mac]['last_seen'] = date('Y-m-d H:i:s', time() - SipDiscovery::IDENTITY_MAX_AGE - 1);
    assertSame(null, $resolve([], $observations)['selected_ip']);
    $observations[$mac]['last_seen'] = date('Y-m-d H:i:s');
    $contacts['200'] = ['ip' => $ip, 'status' => 'Avail', 'mac' => '000fd3000501'];
    assertSame(null, $resolve($contacts, $observations)['selected_ip']);
    assertTrue(strpos($resolve($contacts, $observations)['blocked_reason'], 'different handset MAC') !== false);
    assertTrue(invokePrivate($module, 'getPushIdentityError', $ip, $mac, $contacts, $observations, $local) !== null);
    $db->exec("UPDATE provisioner_devices SET extension=''");
    assertTrue(strpos(invokePrivate($module, 'getPushIdentityError', $ip, $mac, [], $observations, $local), 'Assign') === 0);
    $db->exec("UPDATE provisioner_devices SET extension='200'");
    assertSame(null, invokePrivate($module, 'getPushIdentityError', '172.16.138.50', $mac, [], [], $local));
});

test('IP-only cross-subnet push and retries cannot bypass the MAC identity gate', function () {
    [$module, $db, $directory] = makeFixture();
    $mac = '000fd3000502';
    insertDevice($db, $mac, 'identity-test');
    $db->exec("UPDATE provisioner_devices SET ip_address='10.254.253.252'");
    file_put_contents($directory . '/' . $mac . '.cfg', digiumConfig('identity-test'));
    assertTrue(!$module->sendSipNotify('10.254.253.252', $mac)['success']);
    $bulk = invokePrivate($module, 'pushConfigToPhonesBulk', [['ip' => '10.254.253.252', 'mac' => $mac]]);
    assertSame([], $bulk['sent']);
    assertSame(1, count($bulk['failed']));
    assertTrue(strpos($bulk['failed'][0]['error'], 'MAC/IP') !== false);
    $retry = $module->retryPushForPhones([$mac]);
    assertSame([], $retry['sent']);
    assertTrue(strpos($retry['failed'][0]['error'], 'MAC/IP') !== false);
});

test('scan and identify reject invalid ranges or selections before starting a worker', function () {
    global $tempDirs;
    [$module] = makeFixture();
    foreach (['not-a-subnet', '192.168.0.0/21', '203.0.113.0/24', '192.168.188.0/24,10.0.0.0/24'] as $subnet) {
        assertTrue(!$module->startDiscoveryScan($subnet)['success']);
    }
    assertSame(['192.168.188.68/32'], invokePrivate($module, 'normalizeScanSubnets', '192.168.188.68/32', 32));
    assertTrue(!$module->startIdentifyMac('invalid', ['IP:192.168.188.68'])['success']);
    $store = new SipDiscovery();
    $scan = $store->create('scan', []);
    $tempDirs[] = dirname($store->path($scan['id']));
    $scan['status'] = 'complete';
    $scan['result']['devices'] = [['id' => 'IP:10.254.253.252', 'ip' => '10.254.253.252', 'mac' => '', 'can_identify' => true, 'seen_now' => true]];
    $store->save($scan);
    foreach ([['IP:10.254.253.251'], ['000FD3CF40AF'], [['IP:10.254.253.252']], array_fill(0, 33, 'IP:10.254.253.252')] as $ids) {
        assertTrue(!$module->startIdentifyMac($scan['id'], $ids)['success'], 'Only the approved scan selection can become a NOTIFY target');
    }
    $scan['expires'] = time() - 1; $store->save($scan);
    assertTrue(!$module->startIdentifyMac($scan['id'], ['IP:10.254.253.252'])['success']);
    $setting = null;
    foreach (['startDiscoveryScan', 'startIdentifyMac', 'getDiscoveryJobStatus'] as $command) assertTrue($module->ajaxRequest($command, $setting));
});
