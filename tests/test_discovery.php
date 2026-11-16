<?php

use FreePBX\modules\Provisioner\SipDiscovery;

test('diagnostic summary uses an explicit safe field list and keeps troubleshooting evidence', function () {
    require_once dirname(__DIR__) . '/lib/DiagnosticSummary.php';
    $data = ['module_version'=>'1.4.46','php_version'=>'8.2','collected_at'=>'2026-10-24T13:00:00+08:00',
        'device'=>['extension'=>'200','mac'=>'000fd3cf406b','brand'=>'digium','description'=>'SECRET_DESCRIPTION','password'=>'SECRET_PASSWORD'],
        'config'=>['filename'=>'000fd3cf406b.cfg','contents'=>'SECRET_CONFIG','path'=>'SECRET_PATH','valid'=>true,'exists'=>true],
        'target'=>['selected_ip'=>'192.168.188.80','selected_source'=>'pjsip','resolution_mode'=>'cross_subnet','pjsip_status'=>'Avail','next_action'=>'push',
            'candidates'=>[['source'=>'discovery','ip'=>'192.168.188.80','model'=>'A20','firmware'=>'2.10.0.6746','mac_evidence'=>'Available SIP registration MAC',
                'user_agent'=>'SECRET_AGENT','nonce'=>'SECRET_NONCE','identity_source'=>'token=SECRET_TOKEN']]],
        'last_push'=>['status'=>'verified','fetch_status'=>'downloaded','verify_status'=>'verified'],
        'verification_job'=>['status'=>'complete','error'=>'SECRET_WORKER_OUTPUT'],
        'provisioning_url'=>'http://user:SECRET_URL_PASSWORD@pbx.invalid/?token=SECRET_QUERY'];
    $text = \FreePBX\modules\Provisioner\DiagnosticSummary::build($data);
    foreach (['SECRET_DESCRIPTION','SECRET_PASSWORD','SECRET_CONFIG','SECRET_PATH','SECRET_AGENT','SECRET_NONCE','SECRET_TOKEN','SECRET_WORKER_OUTPUT','SECRET_URL_PASSWORD','SECRET_QUERY'] as $secret) assertTrue(strpos($text,$secret)===false, $secret . ' must not be copied');
    foreach (['192.168.188.80','000fd3cf406b','A20','2.10.0.6746','Available SIP registration MAC','verified','Next action: push','[URL redacted]'] as $value) assertTrue(strpos($text,$value)!==false);
});

test('target guidance distinguishes discovery requirements from mapping and identity conflicts', function () {
    [$module] = makeFixture();
    $device=['mac_address'=>'000fd3000700','extension'=>'200','ip_address'=>'192.168.100.118'];
    $resolve=function($device,$contacts=[]) use ($module) {return invokePrivate($module,'resolveDeviceTarget',$device,$contacts,[],[],false,['172.16.138.0/24'],[]);};
    assertSame('discovery',$resolve($device)['next_action']);
    assertSame('mapping',$resolve(array_replace($device,['extension'=>'']))['next_action']);
    assertSame('diagnostics',$resolve($device,['200'=>['ip'=>'192.168.100.118','mac'=>'000fd3000701','status'=>'Avail']])['next_action']);
    assertSame('push',$resolve($device,['200'=>['ip'=>'192.168.100.118','mac'=>'000fd3000700','status'=>'Avail']])['next_action']);
});

test('diagnostics endpoint includes a safe versioned summary without copying phone config contents', function () {
    [$module,$db,$directory] = makeFixture();
    $mac='000fd3000799';
    insertDevice($db,$mac,'919999');
    file_put_contents($directory . '/' . $mac . '.cfg',digiumConfig('919999') . "\nSIP1 Register Pswd :DO_NOT_COPY_THIS_SECRET\n");
    $result=$module->getDeviceDiagnostics($mac);
    assertTrue($result['success']);
    assertSame('discovery',$result['target']['next_action']);
    assertTrue(strpos($result['diagnostic_summary'],'919999')!==false);
    assertTrue(strpos($result['diagnostic_summary'],$mac)!==false);
    assertTrue(strpos($result['diagnostic_summary'],'DO_NOT_COPY_THIS_SECRET')===false);
    assertSame((string)simplexml_load_file(dirname(__DIR__) . '/module.xml')->version,$result['module_version']);
});

test('callback self-test distinguishes forbidden authentication redirects generic 404 and real receiver response', function () {
    require_once dirname(__DIR__) . '/lib/CallbackDiagnostics.php';
    $class = '\\FreePBX\\modules\\Provisioner\\CallbackDiagnostics';
    $challenge = str_repeat('a', 32);
    $ok = $class::assess(404, "Not Found\n", $challenge, $challenge);
    assertTrue($ok['success']);
    assertTrue(strpos($ok['next_step'], 'Handsets must also reach') !== false);
    foreach ([403 => 'callback_forbidden', 401 => 'callback_authentication', 302 => 'callback_redirect',
        404 => 'callback_unexpected', 200 => 'callback_unexpected', 500 => 'callback_unexpected', 0 => 'callback_unreachable'] as $status => $code) {
        $result = $class::assess($status, 'generic web page', '', $challenge);
        assertTrue(!$result['success']); assertSame($code, $result['code']);
        assertTrue(strlen($result['next_step']) > 20);
    }
    assertTrue(!$class::assess(404, "Not Found\n", str_repeat('b',32), $challenge)['success']);
    assertSame('callback_configuration', $class::check('invalid',2001)['code']);
    assertSame('callback_configuration', $class::check('127.0.0.1','2001/evil')['code']);
    [$module] = makeFixture();
    $setting = [];
    assertTrue($module->ajaxRequest('testDiscoveryCallback', $setting));
});

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
    assertSame([], glob($directory . '/jobs/' . $job['id'] . '/callback-*.json'));
    assertTrue(!$receive('192.168.188.68', $nonce, 'Panasonic 1.2.3 0080f04d2444'));
    $diagnosticPath = $store->path($job['id'], 'callback-' . $nonce . '.json');
    $diagnostic = json_decode(file_get_contents($diagnosticPath), true);
    assertSame('identity_unsupported', $diagnostic['code']);
    assertSame(['ip','code','seen_at'], array_keys($diagnostic));
    assertSame(0600, fileperms($diagnosticPath) & 0777);
    assertTrue(!$receive('192.168.188.68', $nonce, 'Asterisk A20 2.4.0.5722', 'f0A20hw1.100.cfg'));
    assertSame('identity_invalid', json_decode(file_get_contents($diagnosticPath), true)['code']);
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

test('identification worker retains actionable failures and does not count unrelated identities as success', function () {
    [$module, $db, $directory] = makeFixture();
    $old = getenv('PROVISIONER_DISCOVERY_DIRECTORY');
    putenv('PROVISIONER_DISCOVERY_DIRECTORY=' . $directory . '/jobs');
    try {
        $store = new SipDiscovery();
        $ip = '192.168.100.118';
        $rows = [['id'=>'IP:'.$ip, 'ip'=>$ip, 'mac'=>'', 'seen_now'=>true],
            ['id'=>'000FD3000119', 'ip'=>'192.168.100.119', 'mac'=>'000FD3000119', 'seen_now'=>false]];
        $scan = $store->create('scan', []); $scan['status']='complete'; $scan['result']=['devices'=>$rows]; $store->save($scan);
        $job = $store->create('identify', ['scan_id'=>$scan['id'], 'original_devices'=>$rows, 'targets'=>[['ip'=>$ip]]]);
        $progress = ['done'=>1,'total'=>1,'identities'=>[['ip'=>$ip,'identity'=>null,'code'=>'callback_timeout','sip_status'=>200,
            'error'=>'Handset accepted NOTIFY; no validated callback.','next_step'=>'Run Test Callback Access.']]];
        $script = $directory . '/fake-python';
        file_put_contents($script, "#!/bin/sh\nprintf '%s' " . escapeshellarg(json_encode($progress)) . ' > "$(dirname "$2")/progress.json"' . "\n");
        chmod($script,0700);
        $property = new ReflectionProperty(\FreePBX\modules\Provisioner::class,'python3BinaryCache'); $property->setAccessible(true); $property->setValue($module,$script);
        assertTrue($module->processDiscoveryJob($job['id']));
        $status = $module->getDiscoveryJobStatus($job['id']);
        assertTrue($status['success'] && $status['complete']);
        assertSame(0,$status['identified_count']); assertSame(1,$status['failed_count']); assertSame(1,$status['attempted_count']);
        assertSame('callback_timeout',$status['errors'][0]['code']);
        assertSame(200,$status['errors'][0]['sip_status']);
        assertSame('Run Test Callback Access.',$status['errors'][0]['next_step']);
        assertSame(2,(int)$db->query("SELECT COUNT(*) FROM provisioner_logs WHERE action='discovery' AND status='warning'")->fetchColumn());
    } finally {
        putenv($old === false ? 'PROVISIONER_DISCOVERY_DIRECTORY' : 'PROVISIONER_DISCOVERY_DIRECTORY=' . $old);
    }
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
    $python = new ReflectionProperty(\FreePBX\modules\Provisioner::class, 'python3BinaryCache'); $python->setAccessible(true); $python->setValue($module, $bin . '/python');
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

test('Apache identity receiver exception ships as a narrowly scoped module dotfile', function () {
    $path = dirname(__DIR__) . '/.htaccess';
    assertTrue(is_file($path), 'Module .htaccess is required on FreePBX Apache installations');
    $rules = file_get_contents($path);
    assertSame(1, preg_match('/<Files\s+"discovery_receiver\.php">(.*?)<\/Files>/s', $rules, $match));
    assertTrue(strpos($match[1], 'AcceptPathInfo On') !== false);
    assertTrue(strpos($match[1], 'Require all granted') !== false);
    assertTrue(strpos($match[1], 'Allow from all') !== false);
    assertSame(1, substr_count($rules, 'Require all granted'), 'Only the identity receiver may gain public access');
    assertTrue(strpos($rules, '<FilesMatch') === false, 'Do not broaden access to other module scripts');
    assertSame($rules, file_get_contents(dirname(__DIR__) . '/lib/discovery-receiver.htaccess.dist'));
});

test('official install and upgrade hooks restore access after Module Admin drops dotfiles', function () {
    require_once dirname(__DIR__) . '/lib/ReceiverAccess.php';
    global $tempDirs;
    $root = dirname(__DIR__);
    $stage = sys_get_temp_dir() . '/provisioner-upload-' . bin2hex(random_bytes(6));
    mkdir($stage . '/lib', 0700, true);
    $tempDirs[] = $stage;
    // Simulate the actual Module Admin copy: only non-hidden files survive.
    copy($root . '/lib/discovery-receiver.htaccess.dist', $stage . '/lib/discovery-receiver.htaccess.dist');
    assertTrue(!file_exists($stage . '/.htaccess'));
    foreach (['install.php', 'upgrade.php'] as $hook) {
        $script = file_get_contents($root . '/' . $hook);
        assertTrue(strpos($script, "require_once __DIR__ . '/lib/ReceiverAccess.php'") !== false);
        assertTrue(strpos($script, 'ReceiverAccess::install(__DIR__)') !== false);
        assertTrue(strpos($script, "DELETE FROM provisioner_settings WHERE key_name='force_push_without_mac'") !== false);
        \FreePBX\modules\Provisioner\ReceiverAccess::install($stage);
        assertSame(file_get_contents($root . '/.htaccess'), file_get_contents($stage . '/.htaccess'));
        clearstatcache();
        assertSame(0644, fileperms($stage . '/.htaccess') & 0777);
    }
    file_put_contents($stage . '/.htaccess', '# existing custom rules');
    $rejected = false;
    try { \FreePBX\modules\Provisioner\ReceiverAccess::install($stage); }
    catch (RuntimeException $error) { $rejected = true; }
    assertTrue($rejected);
    assertSame('# existing custom rules', file_get_contents($stage . '/.htaccess'));
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

test('removed force push option cannot be enabled by stale clients and is absent from settings UI', function () {
    [$module, $db] = makeFixture();
    $module->setSetting('force_push_without_mac', '1');
    assertTrue(!array_key_exists('force_push_without_mac', $module->getSettings()));
    foreach (['0', '1', 'yes', [], 2] as $value) {
        $result = $module->saveSettings(['force_push_without_mac' => $value, 'pbx_ip' => '192.168.100.99']);
        assertTrue(!$result['success']);
        assertTrue(strpos($result['error'], 'removed') !== false);
        assertSame('192.0.2.10', $module->getSetting('pbx_ip'));
    }
    assertTrue($module->saveSettings(['pbx_ip' => '192.0.2.10'])['success']);
    $view = file_get_contents(dirname(__DIR__) . '/views/main.php');
    $js = file_get_contents(dirname(__DIR__) . '/assets/js/provisioner.js');
    foreach (['force_push_without_mac', 'settingsForcePushWithoutMac', 'forcePushActiveBanner'] as $id) {
        assertTrue(strpos($view, $id) === false && strpos($js, $id) === false);
    }
});

test('legacy enabled override cannot resolve routed targets without fresh matching MAC evidence', function () {
    [$module, $db] = makeFixture();
    $module->setSetting('force_push_without_mac', '1');
    $local = ['192.168.114.0/24'];
    foreach (['digium', 'alcatel', 'yealink'] as $index => $brand) {
        $mac = '000fd30006' . sprintf('%02d', $index);
        $ip = '192.168.100.' . (118 + $index);
        insertDevice($db, $mac, (string)(200 + $index), $brand);
        $device = ['mac_address' => $mac, 'extension' => (string)(200 + $index), 'ip_address' => $ip];
        $resolved = invokePrivate($module, 'resolveDeviceTarget', $device, [], [], [], false, $local, []);
        assertSame(null, $resolved['selected_ip']);
        assertTrue($resolved['mac_confirmation_required']);
        assertTrue(invokePrivate($module, 'getPushIdentityError', $ip, $mac, [], [], $local) !== null);
        $static = array_replace($device, ['static_ip' => $ip]);
        assertSame(null, invokePrivate($module, 'resolveDeviceTarget', $static, [], [], [], false, $local, [])['selected_ip']);
        unset($device['ip_address']);
        assertSame(null, invokePrivate($module, 'resolveDeviceTarget', $device, [], [], [], false, $local, [])['selected_ip']);
        $contacts = [$device['extension'] => ['ip' => $ip, 'status' => 'Avail']];
        assertSame(null, invokePrivate($module, 'resolveDeviceTarget', $device, $contacts, [], [], false, $local, [])['selected_ip']);
        assertSame(null, invokePrivate($module, 'resolveDeviceTarget', $device, [], [], [$mac => $ip], false, $local, [])['selected_ip']);
        $contacts[$device['extension']]['mac'] = $mac;
        $normal = invokePrivate($module, 'resolveDeviceTarget', $device, $contacts, [], [], false, $local, []);
        assertSame('pjsip', $normal['selected_source']);
        assertSame($ip, $normal['selected_ip']);
        assertSame(null, invokePrivate($module, 'getPushIdentityError', $ip, $mac, $contacts, [], $local));
    }
});

test('legacy override cannot authorize stale discovery or conflicting handset identities', function () {
    [$module, $db] = makeFixture();
    $module->setSetting('force_push_without_mac', '1');
    $mac = '000fd3000609'; $ip = '192.168.100.118'; $local = ['192.168.114.0/24'];
    insertDevice($db, $mac, '209');
    $device = ['mac_address' => $mac, 'extension' => '209', 'ip_address' => $ip];
    foreach (['8.8.8.8', '224.0.0.1', 'invalid', ''] as $invalid) {
        assertTrue(invokePrivate($module, 'getPushIdentityError', $invalid, $mac, [], [], $local) !== null);
        assertSame(null, invokePrivate($module, 'resolveDeviceTarget', array_replace($device, ['ip_address' => $invalid]), [], [], [], false, $local, [])['selected_ip']);
    }
    assertTrue(invokePrivate($module, 'getPushIdentityError', $ip, '000fd3000610', [], [], $local) !== null);
    $contacts = ['209' => ['ip' => $ip, 'mac' => '000fd3000610', 'status' => 'Avail']];
    assertTrue(invokePrivate($module, 'getPushIdentityError', $ip, $mac, $contacts, [], $local) !== null);
    assertSame(null, invokePrivate($module, 'resolveDeviceTarget', $device, $contacts, [], [], false, $local, [])['selected_ip']);
    $other = ['000fd3000610' => ['ip_address' => $ip, 'last_seen' => date('Y-m-d H:i:s')]];
    assertTrue(invokePrivate($module, 'getPushIdentityError', $ip, $mac, [], $other, $local) !== null);
    assertSame(null, invokePrivate($module, 'resolveDeviceTarget', $device, [], [], [], false, $local, $other)['selected_ip']);
    $observations = [$mac => ['ip_address' => $ip, 'sip_port' => 5060, 'source' => 'Handset callback', 'last_seen' => date('Y-m-d H:i:s', time() - SipDiscovery::IDENTITY_MAX_AGE - 1)]];
    assertSame(null, invokePrivate($module, 'resolveDeviceTarget', $device, [], [], [], false, $local, $observations)['selected_ip']);
    assertTrue(invokePrivate($module, 'getPushIdentityError', $ip, $mac, [], $observations, $local) !== null);
    $observations[$mac]['last_seen'] = date('Y-m-d H:i:s');
    assertSame($ip, invokePrivate($module, 'resolveDeviceTarget', $device, [], [], [], false, $local, $observations)['selected_ip']);
    assertSame(null, invokePrivate($module, 'getPushIdentityError', $ip, $mac, [], $observations, $local));
    assertTrue(invokePrivate($module, 'getPushIdentityError', $ip, $mac, [], [], $local) !== null);
    assertSame(null, invokePrivate($module, 'resolveDeviceTarget', $device, [], [], [], false, $local, [])['selected_ip']);
});

test('bulk and single push cannot dispatch unidentified targets even with a legacy override enabled', function () {
    [$module, $db, $dir] = makeFixture();
    $module->setSetting('force_push_without_mac', '1');
    $stub = $dir . '/fake-python';
    $capture = $dir . '/dispatch.json';
    file_put_contents($stub, "#!/bin/sh\ncat \"\$2\" > " . escapeshellarg($capture) . "\nprintf '%s' '{\"results\":{}}'\n");
    chmod($stub, 0700);
    $property = new ReflectionProperty(\FreePBX\modules\Provisioner::class, 'python3BinaryCache');
    $property->setAccessible(true);
    $property->setValue($module, $stub);
    $phones = [];
    foreach (['000fd3000611', '000fd3000612'] as $i => $mac) {
        insertDevice($db, $mac, (string)(211 + $i));
        $phones[] = ['mac' => $mac, 'ip' => '10.254.253.' . (241 + $i), 'source' => 'stored'];
    }
    $result = invokePrivate($module, 'pushConfigToPhonesBulk', $phones);
    assertTrue(!file_exists($capture), 'No notification script may run for unidentified targets');
    assertSame(2, count($result['failed']));
    foreach ($result['failed'] as $failed) assertTrue(strpos($failed['error'], 'MAC/IP') !== false);
    foreach ($phones as $phone) assertTrue(!$module->sendSipNotify($phone['ip'], $phone['mac'])['success']);
    assertTrue(!file_exists($capture));
    assertSame([], $result['verified']);
});

test('IP-only cross-subnet push and retries cannot bypass the MAC identity gate', function () {
    [$module, $db, $directory] = makeFixture();
    $mac = '000fd3000502';
    $module->setSetting('force_push_without_mac', '1');
    insertDevice($db, $mac, 'identity-test');
    $db->exec("UPDATE provisioner_devices SET ip_address='10.254.253.252'");
    file_put_contents($directory . '/' . $mac . '.cfg', digiumConfig('identity-test'));
    assertTrue(!$module->sendSipNotify('10.254.253.252', $mac)['success']);
    $bulk = invokePrivate($module, 'pushConfigToPhonesBulk', [['ip' => '10.254.253.252', 'mac' => $mac]]);
    assertSame([], $bulk['sent']);
    assertSame(1, count($bulk['failed']));
    assertTrue(strpos($bulk['failed'][0]['error'], 'MAC/IP') !== false);
    assertSame('discovery', $bulk['failed'][0]['next_action']);
    $retry = $module->retryPushForPhones([$mac]);
    assertSame([], $retry['sent']);
    assertTrue(strpos($retry['failed'][0]['error'], 'MAC/IP') !== false);
    assertSame('discovery', $retry['failed'][0]['next_action']);
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
