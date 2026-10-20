<?php

interface BMO {}

require_once dirname(__DIR__) . '/Provisioner.class.php';

use FreePBX\modules\Provisioner;

final class TestFreePBX {
    private $db;
    public function __construct($db) { $this->db = $db; }
    public function Database() { return $this->db; }
}

$tests = [];
$tempDirs = [];

function test($name, $callback) {
    global $tests;
    $tests[$name] = $callback;
}

function assertTrue($condition, $message = 'Expected condition to be true') {
    if (!$condition) throw new RuntimeException($message);
}

function assertSame($expected, $actual, $message = '') {
    if ($expected !== $actual) {
        throw new RuntimeException(($message ? $message . ': ' : '') . 'expected ' . var_export($expected, true) . ', got ' . var_export($actual, true));
    }
}

function invokePrivate($object, $method, ...$args) {
    $reflection = new ReflectionMethod($object, $method);
    $reflection->setAccessible(true);
    return $reflection->invokeArgs($object, $args);
}

function makeFixture() {
    global $tempDirs;
    $dir = sys_get_temp_dir() . '/provisioner-tests-' . bin2hex(random_bytes(6));
    if (!mkdir($dir, 0770, true)) throw new RuntimeException('Could not create fixture directory');
    $tempDirs[] = $dir;
    $db = new PDO('sqlite::memory:');
    $db->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    $db->sqliteCreateFunction('NOW', function () { return date('Y-m-d H:i:s'); });
    $db->exec("CREATE TABLE provisioner_settings (key_name TEXT PRIMARY KEY, value TEXT)");
    $db->exec("CREATE TABLE provisioner_logs (id INTEGER PRIMARY KEY AUTOINCREMENT, timestamp TEXT, action TEXT, details TEXT, status TEXT)");
    $db->exec("CREATE TABLE provisioner_devices (
        id INTEGER PRIMARY KEY AUTOINCREMENT, mac_address TEXT UNIQUE, extension TEXT, description TEXT,
        brand TEXT DEFAULT 'digium', created_at TEXT, updated_at TEXT, needs_changes INTEGER DEFAULT 1,
        previous_extension TEXT, previous_description TEXT, custom_config INTEGER DEFAULT 0,
        ip_address TEXT, last_ack_at TEXT, last_push_status TEXT, last_push_attempt_at TEXT,
        last_fetch_at TEXT, last_fetch_ip TEXT, last_fetch_status TEXT,
        last_verify_at TEXT, last_verify_ip TEXT, last_verify_status TEXT,
        static_ip TEXT, static_mask TEXT, static_gw TEXT,
        previous_static_ip TEXT, previous_static_mask TEXT, previous_static_gw TEXT
    )");
    $db->exec("CREATE TABLE sip (id TEXT, keyword TEXT, data TEXT)");
    $db->exec("CREATE TABLE pjsip (id TEXT, keyword TEXT, data TEXT)");
    $db->exec('CREATE TABLE featurecodes (modulename TEXT, featurename TEXT, defaultcode TEXT, customcode TEXT, enabled INTEGER)');
    $codes = $db->prepare('INSERT INTO featurecodes VALUES (?,?,?,?,1)');
    foreach ([['donotdisturb','dnd_on','*78'],['donotdisturb','dnd_off','*79'],
        ['callforward','cfon','*72'],['callforward','cfoff','*73'],['callforward','cfbon','*90'],
        ['callforward','cfboff','*91'],['callforward','cfuon','*52'],['callforward','cfuoff','*53']] as $code) {
        $codes->execute(array_merge($code,['']));
    }
    $stmt = $db->prepare("INSERT INTO provisioner_settings (key_name, value) VALUES (?, ?)");
    foreach ([['prov_dir', $dir], ['pbx_ip', '192.0.2.10'], ['sip_port', '5060'], ['transport', 'UDP'], ['deploy_mode', 'dhcp']] as $setting) {
        $stmt->execute($setting);
    }
    return [new Provisioner(new TestFreePBX($db)), $db, $dir];
}

function insertDevice($db, $mac, $extension, $brand = 'digium', $custom = 0, $pending = 0) {
    $stmt = $db->prepare("INSERT INTO provisioner_devices (mac_address, extension, description, brand, custom_config, needs_changes) VALUES (?, ?, ?, ?, ?, ?)");
    $stmt->execute([$mac, $extension, 'Test phone', $brand, $custom, $pending]);
}

function digiumConfig($extension) {
    return "<<VOIP CONFIG FILE>>\n<GLOBAL CONFIG MODULE>\nAccount Label : Test\n<SIP CONFIG MODULE>\nSIP1 Register User : $extension\nSIP1 Phone Number : $extension\n" . str_repeat('Padding : value\n', 8) . "<<END OF FILE>>\n";
}

function makeBackgroundJob($module, $phones, $groups = []) {
    $id = bin2hex(random_bytes(16));
    $snapshots = invokePrivate($module, 'capturePushJobSnapshots', array_keys($phones), $id);
    foreach ($phones as $mac => $ip) {
        invokePrivate($module, 'setPushTriggered', $mac, $ip, $id);
        invokePrivate($module, 'setPushDownloaded', $mac, $ip);
    }
    assertTrue(invokePrivate($module, 'createPushVerificationJob', $id, $phones, $snapshots, $groups, false));
    return $id;
}

function removeTree($path) {
    if (!is_dir($path)) return;
    foreach (scandir($path) as $item) {
        if ($item !== '.' && $item !== '..') {
            $target = $path . '/' . $item;
            is_dir($target) ? removeTree($target) : @unlink($target);
        }
    }
    @rmdir($path);
}

test('brand detection and supported brands', function () {
    [$module] = makeFixture();
    assertSame('digium', $module->getBrandByMac('00:0F:D3:00:00:01'));
    assertSame('alcatel', $module->getBrandByMac('00-80-9F-00-00-01'));
    assertSame('yealink', $module->getBrandByMac('80:5e:c0:00:00:01'));
    assertSame(null, $module->getBrandByMac('12:34:56:00:00:01'));
    assertTrue(in_array('digium', $module->getBrands(), true));
});

test('CSV export optionally substitutes available private PJSIP contact IPs', function () {
    [$module, $db] = makeFixture();
    insertDevice($db, '000fd3000070', '1000', 'digium');
    insertDevice($db, '000fd3000071', '2000', 'digium');
    insertDevice($db, '000fd3000072', '3000', 'digium', 0, 1);
    insertDevice($db, '000fd3000073', '4000', 'digium');
    $db->exec("UPDATE provisioner_devices SET static_ip='10.0.0.10', static_mask='255.255.255.0', static_gw='10.0.0.1' WHERE extension='1000'");
    $db->exec("UPDATE provisioner_devices SET static_ip='10.0.0.20' WHERE extension='2000'");
    $db->exec("UPDATE provisioner_devices SET previous_extension='2500' WHERE extension='3000'");
    $contacts = [
        '1000' => ['ip' => '192.168.10.10', 'status' => 'Avail'],
        '2000' => ['ip' => '192.168.10.20', 'status' => 'Unavail'],
        '2500' => ['ip' => '172.16.20.30', 'status' => 'Avail'],
        '3000' => ['ip' => '172.16.20.31', 'status' => 'Avail'],
        '4000' => ['ip' => '203.0.113.40', 'status' => 'Avail']
    ];

    $default = $module->exportCsv(false, $contacts);
    $defaultRows = array_map('str_getcsv', array_filter(explode("\n", trim($default['csv']))));
    assertSame('10.0.0.10', $defaultRows[1][4]);
    assertSame('provisioner_devices.csv', $default['filename']);
    assertSame(0, $default['pjsip_used']);

    $result = $module->exportCsv(true, $contacts);
    $rows = array_map('str_getcsv', array_filter(explode("\n", trim($result['csv']))));
    assertSame('192.168.10.10', $rows[1][4]);
    assertSame('255.255.255.0', $rows[1][5]);
    assertSame('10.0.0.1', $rows[1][6]);
    assertSame('10.0.0.20', $rows[2][4]);
    assertSame('172.16.20.30', $rows[3][4]);
    assertSame('', $rows[4][4]);
    assertSame(2, $result['pjsip_used']);
    assertSame('provisioner_devices-pjsip-static.csv', $result['filename']);
});

test('PJSIP static export option is unchecked and warns about network fields', function () {
    $root = dirname(__DIR__);
    $view = file_get_contents($root . '/views/main.php');
    $javascript = file_get_contents($root . '/assets/js/provisioner.js');
    assertTrue((bool)preg_match('/<input type="checkbox" id="exportUsePjsipIp">/', $view));
    assertTrue(strpos($view, 'Verify DHCP reservations, subnet masks, and gateways') !== false);
    assertTrue(strpos($javascript, 'use_pjsip_ip') !== false);
    assertTrue(strpos($javascript, "res.filename || 'provisioner_devices.csv'") !== false);
});

test('cloud phonebook settings share a brand selector', function () {
    $root = dirname(__DIR__);
    $view = file_get_contents($root . '/views/main.php');
    $javascript = file_get_contents($root . '/assets/js/provisioner.js');
    assertTrue(strpos($view, 'id="settingsPhonebookBrand"') !== false);
    assertTrue(strpos($view, 'id="settingsPhonebookName"') !== false);
    assertTrue(strpos($view, 'id="savePhonebookBtn"') !== false);
    assertTrue(strpos($javascript, 'phonebookSettings.digium') !== false);
    assertTrue(strpos($javascript, 'phonebookSettings.alcatel') !== false);
    assertTrue(strpos($javascript, 'phonebookActiveBrand') !== false);
    assertTrue(strpos($javascript, 'savePhonebookBtn') !== false);
});

test('brand-specific validation and extension extraction', function () {
    [$module, $db] = makeFixture();
    insertDevice($db, '000fd3000001', '1000', 'digium');
    insertDevice($db, '00809f000001', '2000', 'alcatel');
    insertDevice($db, '805ec0000001', '3000', 'yealink');
    $alcatel = '<?xml version="1.0"?><settings><group>' . str_repeat('<setting id="Padding" value="value"/>', 4)
        . '<setting id="SIPGroup1AuthenticationName" value="2000"/></group></settings>';
    $yealink = "#!version:1.0.0.1\naccount.1.user_name = 3000\n" . str_repeat("account.1.label = Test Phone\n", 5);
    foreach ([
        ['000fd3000001', digiumConfig('1000'), '1000'],
        ['00809f000001', $alcatel, '2000'],
        ['805ec0000001', $yealink, '3000']
    ] as $case) {
        assertTrue(invokePrivate($module, 'isValidPhoneConfig', $case[0], $case[1]));
        assertSame($case[2], invokePrivate($module, 'extractConfigExtension', $case[0], $case[1]));
    }
    assertTrue(!invokePrivate($module, 'isValidPhoneConfig', '000fd3000001', '<html>' . str_repeat('x', 150) . '</html>'));
});

test('custom regeneration archives pulled config', function () {
    [$module, $db, $dir] = makeFixture();
    $mac = '000fd3000002';
    insertDevice($db, $mac, '1000', 'digium', 1, 0);
    $db->prepare("INSERT INTO pjsip (id, keyword, data) VALUES (?, 'password', ?)")->execute(['1000', 'secret-value']);
    $custom = digiumConfig('1000');
    file_put_contents("$dir/$mac.cfg", $custom);
    $confirm = $module->generateAllConfigs(false);
    assertTrue(!empty($confirm['requires_confirmation']));
    $result = $module->generateAllConfigs(true);
    assertTrue($result['success']);
    assertSame(1, $result['custom_replaced']);
    assertSame($custom, file_get_contents("$dir/$mac.cfg.custom.bak"));
    assertTrue(file_get_contents("$dir/$mac.cfg") !== $custom);
    assertSame('0', (string)$db->query("SELECT custom_config FROM provisioner_devices")->fetchColumn());
});

test('matching restore preserves archive and marks device pending', function () {
    [$module, $db, $dir] = makeFixture();
    $mac = '000fd3000003';
    insertDevice($db, $mac, '1000');
    $generated = digiumConfig('1000') . 'Generated : yes\n';
    $archive = digiumConfig('1000') . 'Pulled : yes\n';
    file_put_contents("$dir/$mac.cfg", $generated);
    file_put_contents("$dir/$mac.cfg.custom.bak", $archive);
    $info = $module->getCustomBackupInfo($mac);
    assertTrue($info['success'] && $info['valid'] && $info['extension_matches']);
    $result = $module->restoreCustomBackup($mac);
    assertTrue($result['success']);
    assertSame($archive, file_get_contents("$dir/$mac.cfg"));
    assertSame($archive, file_get_contents("$dir/$mac.cfg.custom.bak"));
    assertSame($generated, file_get_contents("$dir/$mac.cfg.bak"));
    $row = $db->query("SELECT custom_config, needs_changes FROM provisioner_devices")->fetch(PDO::FETCH_ASSOC);
    assertSame('1', (string)$row['custom_config']);
    assertSame('1', (string)$row['needs_changes']);
});

test('mismatched restore requires force and remains atomic', function () {
    [$module, $db, $dir] = makeFixture();
    $mac = '000fd3000004';
    insertDevice($db, $mac, '2000');
    $generated = digiumConfig('2000');
    $archive = digiumConfig('1000');
    file_put_contents("$dir/$mac.cfg", $generated);
    file_put_contents("$dir/$mac.cfg.custom.bak", $archive);
    $blocked = $module->restoreCustomBackup($mac);
    assertTrue(!$blocked['success'] && $blocked['requires_confirmation']);
    assertSame($generated, file_get_contents("$dir/$mac.cfg"));
    assertSame('0', (string)$db->query("SELECT custom_config FROM provisioner_devices")->fetchColumn());
    $forced = $module->restoreCustomBackup($mac, true);
    assertTrue($forced['success']);
    assertSame($archive, file_get_contents("$dir/$mac.cfg"));
});

test('invalid archive cannot be restored', function () {
    [$module, $db, $dir] = makeFixture();
    $mac = '000fd3000005';
    insertDevice($db, $mac, '1000');
    $active = digiumConfig('1000');
    file_put_contents("$dir/$mac.cfg", $active);
    file_put_contents("$dir/$mac.cfg.custom.bak", '<html>' . str_repeat('x', 200) . '</html>');
    $result = $module->restoreCustomBackup($mac, true);
    assertTrue(!$result['success']);
    assertSame($active, file_get_contents("$dir/$mac.cfg"));
});

test('revert restores generated backup and marks pending', function () {
    [$module, $db, $dir] = makeFixture();
    $mac = '000fd3000006';
    insertDevice($db, $mac, '1000', 'digium', 1, 0);
    $generated = digiumConfig('1000') . 'Generated : yes\n';
    file_put_contents("$dir/$mac.cfg", digiumConfig('1000') . 'Custom : yes\n');
    file_put_contents("$dir/$mac.cfg.bak", $generated);
    $result = $module->revertConfigTemplate($mac);
    assertTrue($result['success']);
    assertSame($generated, file_get_contents("$dir/$mac.cfg"));
    $row = $db->query("SELECT custom_config, needs_changes FROM provisioner_devices")->fetch(PDO::FETCH_ASSOC);
    assertSame('0', (string)$row['custom_config']);
    assertSame('1', (string)$row['needs_changes']);
    assertSame($generated, file_get_contents("$dir/$mac.cfg.bak"));
    assertSame([], glob($dir . '/.revert-*'));
});

test('revert without backup safely removes active config', function () {
    [$module, $db, $dir] = makeFixture();
    $mac = '000fd3000008';
    insertDevice($db, $mac, '1000', 'digium', 1, 0);
    file_put_contents("$dir/$mac.cfg", digiumConfig('1000') . 'Custom : yes\n');
    $result = $module->revertConfigTemplate($mac);
    assertTrue($result['success']);
    assertTrue(!file_exists("$dir/$mac.cfg"));
    $row = $db->query("SELECT custom_config, needs_changes FROM provisioner_devices")->fetch(PDO::FETCH_ASSOC);
    assertSame('0', (string)$row['custom_config']);
    assertSame('1', (string)$row['needs_changes']);
    assertSame([], glob($dir . '/.revert-*'));
});

test('revert rejects invalid generated backup without state changes', function () {
    [$module, $db, $dir] = makeFixture();
    $mac = '000fd3000009';
    insertDevice($db, $mac, '1000', 'digium', 1, 0);
    $custom = digiumConfig('1000') . 'Custom : yes\n';
    file_put_contents("$dir/$mac.cfg", $custom);
    file_put_contents("$dir/$mac.cfg.bak", '<html>' . str_repeat('x', 200) . '</html>');
    $result = $module->revertConfigTemplate($mac);
    assertTrue(!$result['success']);
    assertSame($custom, file_get_contents("$dir/$mac.cfg"));
    assertSame('1', (string)$db->query("SELECT custom_config FROM provisioner_devices")->fetchColumn());
    assertSame([], glob($dir . '/.revert-*'));
});

test('revert filesystem failure leaves database state unchanged', function () {
    [$module, $db, $dir] = makeFixture();
    $mac = '000fd3000010';
    insertDevice($db, $mac, '1000', 'digium', 1, 0);
    mkdir("$dir/$mac.cfg");
    file_put_contents("$dir/$mac.cfg.bak", digiumConfig('1000'));
    $result = $module->revertConfigTemplate($mac);
    assertTrue(!$result['success']);
    assertTrue(is_dir("$dir/$mac.cfg"));
    $row = $db->query("SELECT custom_config, needs_changes FROM provisioner_devices")->fetch(PDO::FETCH_ASSOC);
    assertSame('1', (string)$row['custom_config']);
    assertSame('0', (string)$row['needs_changes']);
    assertSame([], glob($dir . '/.revert-*'));
});

test('revert database failure restores original active config', function () {
    [$module, $db, $dir] = makeFixture();
    $mac = '000fd3000011';
    insertDevice($db, $mac, '1000', 'digium', 1, 0);
    $custom = digiumConfig('1000') . 'Custom : yes\n';
    $generated = digiumConfig('1000') . 'Generated : yes\n';
    file_put_contents("$dir/$mac.cfg", $custom);
    file_put_contents("$dir/$mac.cfg.bak", $generated);
    $db->exec("CREATE TRIGGER fail_revert BEFORE UPDATE OF custom_config ON provisioner_devices WHEN NEW.custom_config=0 BEGIN SELECT RAISE(FAIL, 'forced update failure'); END");
    $result = $module->revertConfigTemplate($mac);
    assertTrue(!$result['success']);
    assertTrue(strpos($result['error'], 'active custom config was restored') !== false);
    assertSame($custom, file_get_contents("$dir/$mac.cfg"));
    assertSame($generated, file_get_contents("$dir/$mac.cfg.bak"));
    $row = $db->query("SELECT custom_config, needs_changes FROM provisioner_devices")->fetch(PDO::FETCH_ASSOC);
    assertSame('1', (string)$row['custom_config']);
    assertSame('0', (string)$row['needs_changes']);
    assertSame([], glob($dir . '/.revert-*'));
});

test('revert refuses concurrent phone operation', function () {
    [$module, $db, $dir] = makeFixture();
    $mac = '000fd3000012';
    insertDevice($db, $mac, '1000', 'digium', 1, 0);
    $custom = digiumConfig('1000') . 'Custom : yes\n';
    file_put_contents("$dir/$mac.cfg", $custom);
    file_put_contents("$dir/$mac.cfg.bak", digiumConfig('1000'));
    $uid = function_exists('posix_geteuid') ? posix_geteuid() : getmyuid();
    $lock = fopen(sys_get_temp_dir() . "/provisioner-fetch-$uid-$mac.lock", 'c');
    assertTrue($lock !== false && flock($lock, LOCK_EX | LOCK_NB));
    try {
        $result = $module->revertConfigTemplate($mac);
    } finally {
        flock($lock, LOCK_UN);
        fclose($lock);
    }
    assertTrue(!$result['success']);
    assertSame($custom, file_get_contents("$dir/$mac.cfg"));
    assertSame('1', (string)$db->query("SELECT custom_config FROM provisioner_devices")->fetchColumn());
});

test('config grid exposes state and restore action', function () {
    [$module, $db, $dir] = makeFixture();
    $mac = '000fd3000007';
    insertDevice($db, $mac, '1000');
    file_put_contents("$dir/$mac.cfg", digiumConfig('1000'));
    file_put_contents("$dir/$mac.cfg.custom.bak", digiumConfig('1000'));
    $rows = $module->getConfigFileGrid();
    assertSame(1, count($rows));
    assertSame('1000', (string)$rows[0]['assigned_extension']);
    assertSame('1000', (string)$rows[0]['config_extension']);
    assertTrue(strpos($rows[0]['state'], 'Extension match') !== false);
    assertTrue(strpos($rows[0]['state'], 'Pulled backup') !== false);
    assertTrue(strpos($rows[0]['actions'], 'restore-pulled-btn') !== false);
});

test('config grid reads assigned and file extensions for every brand', function () {
    [$module, $db, $dir] = makeFixture();
    insertDevice($db, '000fd3000020', '1000', 'digium');
    insertDevice($db, '00809f000020', '2000', 'alcatel');
    insertDevice($db, '805ec0000020', '3000', 'yealink');
    file_put_contents("$dir/000fd3000020.cfg", digiumConfig('1000'));
    $alcatel = '<?xml version="1.0"?><settings><group>' . str_repeat('<setting id="Padding" value="value"/>', 4)
        . '<setting id="SIPGroup1AuthenticationName" value="2000"/></group></settings>';
    file_put_contents("$dir/config.00809f000020.xml", $alcatel);
    $yealink = "#!version:1.0.0.1\naccount.1.auth_name = 3000\n" . str_repeat("account.1.label = Test Phone\n", 5);
    file_put_contents("$dir/805ec0000020.cfg", $yealink);
    $rows = [];
    foreach ($module->getConfigFileGrid() as $row) $rows[$row['mac']] = $row;
    assertSame('1000', (string)$rows['000fd3000020']['config_extension']);
    assertSame('2000', (string)$rows['00809f000020']['config_extension']);
    assertSame('3000', (string)$rows['805ec0000020']['config_extension']);
    foreach ($rows as $row) assertTrue(strpos($row['state'], 'Extension match') !== false);
});

test('pending extension change shows old to new and generated push target', function () {
    [$module, $db, $dir] = makeFixture();
    $mac = '000fd3000021';
    insertDevice($db, $mac, '2000', 'digium', 0, 1);
    $db->prepare("UPDATE provisioner_devices SET previous_extension=? WHERE mac_address=?")->execute(['1000', $mac]);
    file_put_contents("$dir/$mac.cfg", digiumConfig('1000'));
    $device = $module->getDeviceGrid()[0];
    assertTrue(strpos($device['extension_display'], '1000') !== false && strpos($device['extension_display'], '2000') !== false);
    assertTrue(strpos($device['config_sync'], 'Will generate 2000 on Push') !== false);
    $config = $module->getConfigFileGrid()[0];
    assertSame('2000', (string)$config['assigned_extension']);
    assertSame('1000', (string)$config['config_extension']);
    assertTrue(strpos($config['state'], 'Will generate 2000 on Push') !== false);
});

test('custom extension mismatch identifies value that push will use', function () {
    [$module, $db, $dir] = makeFixture();
    $mac = '000fd3000022';
    insertDevice($db, $mac, '2000', 'digium', 1, 1);
    file_put_contents("$dir/$mac.cfg", digiumConfig('1000'));
    $device = $module->getDeviceGrid()[0];
    assertTrue(strpos($device['config_sync'], 'Custom Mismatch: file uses 1000') !== false);
    $config = $module->getConfigFileGrid()[0];
    assertSame('2000', (string)$config['assigned_extension']);
    assertSame('1000', (string)$config['config_extension']);
    assertTrue(strpos($config['state'], 'Push uses config 1000') !== false);
});

test('mapping grid reports missing and unknown config extensions', function () {
    [$module, $db, $dir] = makeFixture();
    insertDevice($db, '000fd3000023', '1000', 'digium');
    insertDevice($db, '000fd3000024', '2000', 'digium');
    $unknown = "<<VOIP CONFIG FILE>>\n<GLOBAL CONFIG MODULE>\n<SIP CONFIG MODULE>\n" . str_repeat('Padding : value\n', 8) . "<<END OF FILE>>\n";
    file_put_contents("$dir/000fd3000024.cfg", $unknown);
    $rows = [];
    foreach ($module->getDeviceGrid() as $row) $rows[$row['mac_address']] = $row;
    assertTrue(strpos($rows['000fd3000023']['config_sync'], 'Config Missing') !== false);
    assertTrue(strpos($rows['000fd3000024']['config_sync'], 'Extension Unknown') !== false);
    $config = $module->getConfigFileGrid()[0];
    assertTrue(strpos($config['config_extension'], 'Unknown') !== false);
});

test('swap grouping detects rotations but rejects duplicate origins', function () {
    [$module] = makeFixture();
    $rotation = [
        ['mac_address' => 'a', 'previous_extension' => '1000', 'extension' => '2000'],
        ['mac_address' => 'b', 'previous_extension' => '2000', 'extension' => '3000'],
        ['mac_address' => 'c', 'previous_extension' => '3000', 'extension' => '1000']
    ];
    assertSame([['a', 'b', 'c']], invokePrivate($module, 'getPendingExtensionSwapGroups', $rotation));
    $rotation[] = ['mac_address' => 'd', 'previous_extension' => '1000', 'extension' => '4000'];
    assertSame([], invokePrivate($module, 'getPendingExtensionSwapGroups', $rotation));
});

test('selected generation includes swap partners and leaves unrelated pending configs alone', function () {
    [$module, $db, $dir] = makeFixture();
    $ale = '3c28a6000110';
    $digium = '000fd3000111';
    $other = '000fd3000112';
    insertDevice($db, $ale, '200', 'alcatel', 0, 1);
    insertDevice($db, $digium, '2000', 'digium', 0, 1);
    insertDevice($db, $other, '300', 'digium', 0, 1);
    $db->exec("UPDATE provisioner_devices SET previous_extension='2000' WHERE mac_address='$ale'");
    $db->exec("UPDATE provisioner_devices SET previous_extension='200' WHERE mac_address='$digium'");
    foreach (['200', '2000', '300'] as $ext) $db->exec("INSERT INTO sip VALUES ('$ext', 'secret', 'test-secret')");
    file_put_contents("$dir/$other.cfg", 'Preserve this unrelated config');
    assertSame([$digium, $ale], invokePrivate($module, 'expandPushSelection', ['00:0F:D3:00:01:11']));
    assertSame(2, count($module->getPushProgress([$digium])['devices']));
    $result = $module->applyChanges(true, false, false, [$digium]);
    assertTrue($result['success']);
    assertSame(2, count($result['generated']));
    assertSame('200', invokePrivate($module, 'extractConfigExtension', $ale, file_get_contents("$dir/config.$ale.xml")));
    assertSame('2000', invokePrivate($module, 'extractConfigExtension', $digium, file_get_contents("$dir/$digium.cfg")));
    assertSame('Preserve this unrelated config', file_get_contents("$dir/$other.cfg"));
    assertSame(3, (int)$db->query('SELECT COUNT(*) FROM provisioner_devices WHERE needs_changes=1')->fetchColumn());
});

test('selected push regenerates missing configs even without a pending flag', function () {
    [$module, $db, $dir] = makeFixture();
    $mac = '000fd3000113';
    insertDevice($db, $mac, '200', 'digium');
    $db->exec("INSERT INTO sip VALUES ('200', 'secret', 'test-secret')");
    assertTrue(invokePrivate($module, 'pushSelectionNeedsGeneration', [$mac]));
    $result = $module->pushToPhones([$mac]);
    assertTrue($result['success']);
    assertSame([$mac], $result['generated']);
    assertTrue(is_file("$dir/$mac.cfg"));
    assertSame('200', invokePrivate($module, 'extractConfigExtension', $mac, file_get_contents("$dir/$mac.cfg")));
    assertSame([$mac], $result['skipped']);
});

test('pending cross-subnet push targets the MAC-identified source before the new static destination', function () {
    [$module] = makeFixture();
    $device = ['mac_address' => '000fd3000114', 'extension' => '2000', 'previous_extension' => '200', 'needs_changes' => 1,
        'ip_address' => '192.168.188.80', 'static_ip' => '192.168.188.84'];
    $contacts = ['200' => ['ip' => '192.168.188.84', 'mac' => '3c28a6000115', 'status' => 'Avail']];
    $observations = ['000fd3000114' => ['ip_address' => '192.168.188.80', 'sip_port' => 5060, 'source' => 'SIP discovery fetch', 'last_seen' => date('Y-m-d H:i:s')]];
    $target = invokePrivate($module, 'resolveDeviceTarget', $device, $contacts, [], [], false, ['172.16.138.0/24'], $observations);
    assertSame('192.168.188.80', $target['selected_ip']);
    assertSame('discovery', $target['selected_source']);
    $device['ip_address'] = null;
    $target = invokePrivate($module, 'resolveDeviceTarget', $device, $contacts, [], [], false, ['172.16.138.0/24']);
    assertSame(null, $target['selected_ip'], 'Do not notify an address known to belong to another handset');
});

test('mapping edits preserve the last-known address when the UI omits it', function () {
    [$module, $db] = makeFixture();
    $mac = '000fd3000116';
    insertDevice($db, $mac, '200', 'digium');
    $db->exec("UPDATE provisioner_devices SET ip_address='192.168.188.80'");
    $id = (int)$db->query('SELECT id FROM provisioner_devices')->fetchColumn();
    assertTrue($module->updateDevice($id, $mac, '2000', 'Test phone', 'digium', '', '192.168.188.84', '255.255.255.0', '192.168.188.254')['success']);
    assertSame('192.168.188.80', $db->query('SELECT ip_address FROM provisioner_devices')->fetchColumn());
});

test('subnet normalization and AJAX commands are guarded', function () {
    [$module] = makeFixture();
    assertSame(['192.168.10.0/24', '10.0.0.0/8'], invokePrivate($module, 'normalizeScanSubnets', '192.168.10.77/24, 10.4.5.6/8'));
    assertSame(null, invokePrivate($module, 'normalizeScanSubnets', '192.168.1.1/99'));
    $setting = null;
    assertTrue($module->ajaxRequest('restoreCustomBackup', $setting));
    assertTrue($module->ajaxRequest('autoAddSupportedDiscoveredDevices', $setting));
    assertTrue($module->ajaxRequest('verifyPushStatus', $setting));
    assertTrue($module->ajaxRequest('getPushProgress', $setting));
    assertTrue(!$module->ajaxRequest('arbitraryCommand', $setting));
});

test('network scan rejects invalid and oversized subnets before probing', function () {
    [$module] = makeFixture();
    $invalid = $module->scanNetwork('not-a-subnet');
    assertTrue(!$invalid['success']);
    assertTrue(strpos($invalid['error'], 'valid IPv4 subnet') !== false);
    $oversized = $module->scanNetwork('192.168.0.0/21');
    assertTrue(!$oversized['success']);
    assertTrue(strpos($oversized['error'], '/22') !== false);
});

test('neighbor parser distinguishes current and stale devices', function () {
    [$module] = makeFixture();
    $output = implode("\n", [
        '192.168.10.2 dev eth0 lladdr 00:0f:d3:00:00:02 REACHABLE',
        '192.168.10.3 dev eth0 lladdr 00:80:9f:00:00:03 STALE',
        '192.168.10.4 dev eth0 lladdr 80:5e:c0:00:00:04 router DELAY',
        '192.168.11.5 dev eth0 lladdr 00:0f:d3:00:00:05 REACHABLE',
        '192.168.10.6 dev eth0 FAILED'
    ]);
    $entries = invokePrivate($module, 'parseSubnetNeighborEntries', $output, '192.168.10.0/24');
    assertSame(3, count($entries));
    assertTrue($entries['000fd3000002']['seen_now']);
    assertTrue(!$entries['00809f000003']['seen_now']);
    assertTrue($entries['805ec0000004']['seen_now']);
    assertSame('DELAY', $entries['805ec0000004']['neighbor_state']);
});

test('local ARP helpers avoid cache flushes and discovery retains session observations', function () {
    $root = dirname(__DIR__);
    $php = file_get_contents($root . '/Provisioner.class.php');
    $javascript = file_get_contents($root . '/assets/js/provisioner.js');
    assertTrue(strpos($php, 'ip neigh flush') === false);
    assertTrue(strpos($php, 'pingSweepSubnet($subnet, $ping, 3, 50)') !== false);
    assertTrue(strpos($javascript, 'Not Seen This Scan') !== false);
    assertTrue(strpos($javascript, 'loadDiscoveredRows(rows, true)') !== false);
    assertTrue(strpos($javascript, 'Current results') !== false);
});

test('push resolution uses a reliable three-pass subnet sweep', function () {
    $root = dirname(__DIR__);
    $php = file_get_contents($root . '/Provisioner.class.php');
    assertTrue(strpos($php, 'pingSweepSubnet($subnet, $ping, 3, 50)') !== false);
    assertTrue(strpos($php, 'if ($pass + 1 < $passes) usleep(500000);') !== false);
});

test('generated configs apply PBX NTP and fixed Singapore timezone', function () {
    [$module, $db, $dir] = makeFixture();
    insertDevice($db, '000fd3000060', '1000', 'digium');
    insertDevice($db, '00809f000060', '2000', 'alcatel');
    insertDevice($db, '805ec0000060', '3000', 'yealink');
    $secret = $db->prepare("INSERT INTO pjsip (id, keyword, data) VALUES (?, 'password', ?)");
    foreach (['1000', '2000', '3000'] as $extension) $secret->execute([$extension, 'secret-' . $extension]);
    $result = $module->generateAllConfigs(true);
    assertTrue($result['success']);
    assertSame(3, $result['generated']);

    $digium = file_get_contents($dir . '/000fd3000060.cfg');
    assertTrue(strpos($digium, 'SNTP Server        :192.0.2.10') !== false);
    assertTrue(strpos($digium, 'Time Zone          :32') !== false);
    assertTrue(strpos($digium, 'Time Zone Name     :UTC+8') !== false);
    assertTrue(strpos($digium, 'Enable DST         :0') !== false);

    $alcatel = simplexml_load_file($dir . '/config.00809f000060.xml');
    assertTrue($alcatel !== false);
    $ntp = $alcatel->xpath("//setting[@id='LocalEnetcfgSntp']");
    $ntp2 = $alcatel->xpath("//setting[@id='LocalEnetcfgSntp2']");
    $refresh = $alcatel->xpath("//setting[@id='LocalEnetcfgSntpRefreshPeriod']");
    $offset = $alcatel->xpath("//setting[@id='LocalAdmcfgTimeZoneUtoffset']");
    $location = $alcatel->xpath("//setting[@id='LocalAdmcfgTimeZoneLocation']");
    $dst = $alcatel->xpath("//setting[@id='LocalAdmcfgDstEnable']");
    $mwiUri = $alcatel->xpath("//setting[@id='Account1MwiUri']");
    $vmNumber = $alcatel->xpath("//setting[@id='Account1VmNumber']");
    assertSame('192.0.2.10', (string)$ntp[0]['value']);
    assertSame('time.nist.gov', (string)$ntp2[0]['value']);
    assertSame('3600', (string)$refresh[0]['value']);
    assertSame('+8:00', (string)$offset[0]['value']);
    assertSame('Brunei', (string)$location[0]['value']);
    assertSame('0', (string)$dst[0]['value']);
    assertSame('', (string)$mwiUri[0]['value']);
    assertSame('true', (string)$mwiUri[0]['override']);
    assertSame('*97', (string)$vmNumber[0]['value']);
    assertSame('true', (string)$vmNumber[0]['override']);
    assertSame([], $alcatel->xpath("//setting[starts-with(@id, 'DmAdmcfgTimeZone')]"));
    assertSame([], $alcatel->xpath("//setting[@id='DmAdmcfgDstEnable']"));
    assertSame([], $alcatel->xpath("//setting[@id='DmEnetcfgSntp']"));
    assertSame([], $alcatel->xpath("//setting[@id='DmEnetcfgSntp1']"));
    assertSame([], $alcatel->xpath("//setting[@id='DmEnetcfgSntpRefreshPeriod']"));

    $yealink = file_get_contents($dir . '/805ec0000060.cfg');
    assertTrue(strpos($yealink, 'local_time.ntp_server1 = 192.0.2.10') !== false);
});

test('Digium cloud phonebook settings are rendered with authentication', function () {
    [$module, $db, $dir] = makeFixture();
    insertDevice($db, '000fd3000061', '1001', 'digium');
    $db->prepare("INSERT INTO pjsip (id, keyword, data) VALUES (?, 'password', ?)")->execute(['1001', 'secret-1001']);
    $settings = $db->prepare('INSERT INTO provisioner_settings (key_name, value) VALUES (?, ?)');
    foreach ([
        ['digium_phonebook_name', 'Cloud Directory'],
        ['digium_phonebook_url', 'https://directory.example.test/contacts.xml?format=digium'],
        ['digium_phonebook_username', 'directory-user'],
        ['digium_phonebook_password', 'directory-password']
    ] as $setting) $settings->execute($setting);

    $result = $module->generateAllConfigs(true);
    assertTrue($result['success']);
    $config = file_get_contents($dir . '/000fd3000061.cfg');
    assertTrue(strpos($config, 'XML-PBook1 Name    :Cloud Directory') !== false);
    assertTrue(strpos($config, 'XML-PBook1 Addr    :https://directory.example.test/contacts.xml?format=digium') !== false);
    assertTrue(strpos($config, 'XML-PBook1 Auth    :directory-user:directory-password') !== false);
});

test('Digium default phonebook URL explicitly selects Digium XML', function () {
    [$module, $db, $dir] = makeFixture();
    insertDevice($db, '000fd3000062', '1002', 'digium');
    $db->prepare("INSERT INTO pjsip (id, keyword, data) VALUES (?, 'password', ?)")->execute(['1002', 'secret-1002']);

    $result = $module->generateAllConfigs(true);
    assertTrue($result['success']);
    $config = file_get_contents($dir . '/000fd3000062.cfg');
    assertTrue(strpos($config, 'XML-PBook1 Addr    :http://192.0.2.10:2001/phonebook.php?format=digium') !== false);
});

test('Alcatel M3s remote phonebook settings use documented XML keys', function () {
    [$module, $db, $dir] = makeFixture();
    insertDevice($db, '00809f000063', '1003', 'alcatel');
    $db->prepare("INSERT INTO pjsip (id, keyword, data) VALUES (?, 'password', ?)")->execute(['1003', 'secret-1003']);
    $settings = $db->prepare('INSERT INTO provisioner_settings (key_name, value) VALUES (?, ?)');
    foreach ([
        ['alcatel_phonebook_name', 'ALE Directory'],
        ['alcatel_phonebook_url', 'https://directory.example.test/alcatel.xml'],
        ['alcatel_phonebook_username', 'ale-user'],
        ['alcatel_phonebook_password', 'ale-password']
    ] as $setting) $settings->execute($setting);

    $result = $module->generateAllConfigs(true);
    assertTrue($result['success']);
    $xml = simplexml_load_file($dir . '/config.00809f000063.xml');
    assertTrue($xml !== false);
    $value = function ($id) use ($xml) {
        $settings = $xml->xpath("//setting[@id='" . $id . "']");
        return $settings ? (string)$settings[0]['value'] : null;
    };
    assertSame('true', $value('RemotePhoneBookEnable'));
    assertSame('1', $value('RemotePhoneBookForceUpdateMode'));
    assertSame('true', $value('RemotePhoneBookPeriodUpdateEnable'));
    assertSame('3600', $value('RemotePhoneBookInterval'));
    assertSame('ALE Directory', $value('RemotePhoneBook1GroupName'));
    assertSame('https://directory.example.test/alcatel.xml', $value('RemotePhoneBook1Url'));
    assertSame('ale-user', $value('RemotePhoneBook1AuthName'));
    assertSame('ale-password', $value('RemotePhoneBook1AuthPwd'));
    assertSame('0;1', $value('DirectoryList'));
    assertSame('true', $value('FeatureActionUriEnable'));
    assertSame('false', $value('FeatureActionUriPromptEnable'));
    assertSame('192.0.2.10', $value('FeatureActionUriLimitIp'));
    assertSame('http://192.0.2.10:2001/provisioning/', $value('LocalEnetcfgDmUrl'));
    assertSame('http://192.0.2.10:2001/provisioning/', $value('DeviceProvisionServerUrl'));
});

test('Alcatel uses remote config fetch while Digium uses ua-profile', function () {
    [$module, $db] = makeFixture();
    insertDevice($db, '3c28a6000063', '1063', 'alcatel');
    insertDevice($db, '000fd3000063', '2063', 'digium');

    $alcatel = invokePrivate($module, 'getNotifyDefinition', '3c28a6000063', '192.0.2.10');
    assertSame('ua-profile', $alcatel['event']);
    assertSame('http://192.0.2.10:2001/provisioning/', $alcatel['uri']);
    assertSame('ALE config fetch', $alcatel['label']);

    $digium = invokePrivate($module, 'getNotifyDefinition', '000fd3000063', '192.0.2.10');
    assertSame('ua-profile', $digium['event']);
    assertSame('http://192.0.2.10:2001/provisioning/', $digium['uri']);
    assertSame('NOTIFY', $digium['label']);
});

test('Digium and ALE render PBX DND and forwarding codes without provisioning feature state or destination', function () {
    [$module,$db,$dir] = makeFixture();
    foreach (['000fd3000081'=>'digium','3c28a6000082'=>'alcatel'] as $mac=>$brand) insertDevice($db,$mac,'1001',$brand);
    $db->exec("INSERT INTO pjsip VALUES ('1001','password','test-secret')");
    $check = function ($expected) use ($module,$dir) {
        assertTrue($module->generateAllConfigs(true)['success']);
        $digium = file_get_contents($dir.'/000fd3000081.cfg');
        $ale = simplexml_load_file($dir.'/config.3c28a6000082.xml');
        assertTrue($ale !== false);
        $xmlValues = [];
        foreach ($ale->setting as $setting) $xmlValues[(string)$setting['id']] = (string)$setting['value'];
        $textValues = [];
        foreach (explode("\n",$digium) as $line) {
            if (strpos($line,':') !== false) { [$key,$value] = explode(':',$line,2); $textValues[trim($key)] = trim($value); }
        }
        assertSame('1',$textValues['SIP1 SvcCode Mode']);
        foreach (['FeatureDndMode','FeatureDNDMethod','FeatureFwdMode','FeatureFwdMethod'] as $key) assertSame('0',$xmlValues[$key]);
        $pairs = [['DNDOn','FeatureDndOnCode'],['DNDOff','FeatureDndOffCode'],['CFUOn','FeatureImmFwdOnCode'],['CFUOff','FeatureImmFwdOffCode'],
            ['CFBOn','FeatureBusyFwdOnCode'],['CFBOff','FeatureBusyFwdOffCode'],['CFNOn','FeatureNoReplyFwdOnCode'],['CFNOff','FeatureNoReplyFwdOffCode']];
        foreach ($pairs as $i=>$pair) {
            assertSame($expected[$i],$textValues['SIP1 '.$pair[0].' SvcCode']);
            assertSame($expected[$i],$xmlValues[$pair[1]]);
        }
        assertTrue(strpos($digium,'{{') === false);
        foreach (['P1 Enable DND','SIP1 Always FWD','SIP1 Busy FWD','SIP1 No Answer FWD','SIP1 Always FWD Num','SIP1 Busy FWD Num','SIP1 NoAnswer FWD N'] as $key) assertTrue(!isset($textValues[$key]));
        foreach (['FeatureDndEnable','FeatureImmFwdEnable','FeatureBusyFwdEnable','FeatureNoReplyFwdEnable','FeatureImmFwdNumber','FeatureBusyFwdNumber','FeatureNoReplyFwdNumber'] as $key) assertTrue(!isset($xmlValues[$key]));
    };
    $check(['*78','*79','*72','*73','*90','*91','*52','*53']);
    $db->exec("UPDATE featurecodes SET customcode='*881#' WHERE featurename='dnd_on'");
    $db->exec("UPDATE featurecodes SET customcode='*882',enabled=0 WHERE featurename='dnd_off'");
    $db->exec("UPDATE featurecodes SET customcode='0' WHERE featurename='cfon'");
    $db->exec("DELETE FROM featurecodes WHERE featurename='cfuoff'");
    $check(['*881#','','0','*73','*90','*91','*52','']);
    $db->exec('DELETE FROM featurecodes');
    $check(['','','','','','','','']);
});

test('invalid feature codes fail generation without replacing active configs or importing extra handset settings', function () {
    [$module,$db,$dir] = makeFixture();
    insertDevice($db,'000fd3000083','1001','digium');
    $db->exec("INSERT INTO pjsip VALUES ('1001','password','test-secret')");
    $path = $dir.'/000fd3000083.cfg';
    file_put_contents($path,'original active config');
    $stmt = $db->prepare("UPDATE featurecodes SET customcode=? WHERE featurename='dnd_on'");
    foreach (["*78\nSIP1 Always FWD:1",str_repeat('1',33),'*78"/>'] as $invalid) {
        $stmt->execute([$invalid]);
        $result = $module->generateAllConfigs(true);
        assertTrue(!$result['success']);
        assertSame('original active config',file_get_contents($path));
    }
    $db->exec("UPDATE provisioner_devices SET custom_config=1");
    assertTrue($module->generateAllConfigs(false)['success']);
    assertSame('original active config',file_get_contents($path));
});

test('time placeholders are synchronized in active and default templates', function () {
    $root = dirname(__DIR__);
    foreach (['digium', 'alcatel'] as $brand) {
        assertSame(
            file_get_contents($root . '/templates/' . $brand . '/default.cfg'),
            file_get_contents($root . '/templates/' . $brand . '/default.cfg.dist'),
            "$brand active and default templates differ"
        );
    }
});

test('auto add discovery is limited to Digium and Alcatel-ALE', function () {
    [$module] = makeFixture();
    assertSame('digium', invokePrivate($module, 'getSupportedDiscoveryBrand', '000fd3000030'));
    assertSame('alcatel', invokePrivate($module, 'getSupportedDiscoveryBrand', '00809f000030'));
    assertSame(null, invokePrivate($module, 'getSupportedDiscoveryBrand', '805ec0000030'));
    assertSame(null, invokePrivate($module, 'getSupportedDiscoveryBrand', '000c41000030'));
    assertSame(null, invokePrivate($module, 'getSupportedDiscoveryBrand', '123456000030'));
});

test('auto add is rejected outside SIP NOTIFY mode', function () {
    [$module] = makeFixture();
    $result = $module->autoAddSupportedDiscoveredDevices([
        ['mac' => '000fd3000031', 'ip' => '192.0.2.31']
    ], []);
    assertTrue(!$result['success']);
    assertTrue(strpos($result['error'], 'SIP NOTIFY') !== false);
});

test('auto add includes supported unmatched phones and skips unsupported devices', function () {
    [$module, $db] = makeFixture();
    $db->exec("UPDATE provisioner_settings SET value='sip_notify' WHERE key_name='deploy_mode'");
    $devices = [
        ['mac' => '000fd3000032', 'ip' => '192.0.2.32'],
        ['mac' => '00809f000032', 'ip' => '192.0.2.33'],
        ['mac' => '805ec0000032', 'ip' => '192.0.2.34'],
        ['mac' => '000c41000032', 'ip' => '192.0.2.35'],
        ['mac' => '123456000032', 'ip' => '192.0.2.36']
    ];
    $matches = [
        ['mac' => '000fd3000032', 'extension' => '1000'],
        ['mac' => '805ec0000032', 'extension' => '3000']
    ];
    $result = $module->autoAddSupportedDiscoveredDevices($devices, $matches);
    assertTrue($result['success']);
    assertSame(2, $result['added_count']);
    assertSame(1, $result['added_with_extension']);
    assertSame(1, $result['added_without_extension']);
    assertSame(3, $result['unsupported_count']);
    $rows = $db->query("SELECT mac_address, extension, brand, needs_changes FROM provisioner_devices ORDER BY mac_address")->fetchAll(PDO::FETCH_ASSOC);
    assertSame(2, count($rows));
    $byMac = [];
    foreach ($rows as $row) $byMac[$row['mac_address']] = $row;
    assertSame('1000', $byMac['000fd3000032']['extension']);
    assertSame('', $byMac['00809f000032']['extension']);
    assertTrue(!isset($byMac['805ec0000032']));
    assertSame('alcatel', $byMac['00809f000032']['brand']);
    foreach ($rows as $row) assertSame('1', (string)$row['needs_changes']);
});

test('auto add leaves conflicting PJSIP extensions unassigned', function () {
    [$module, $db] = makeFixture();
    $db->exec("UPDATE provisioner_settings SET value='sip_notify' WHERE key_name='deploy_mode'");
    insertDevice($db, '000fd3000040', '1000', 'digium', 0, 0);
    $devices = [
        ['mac' => '000fd3000041', 'ip' => '192.0.2.41'],
        ['mac' => '000fd3000042', 'ip' => '192.0.2.42'],
        ['mac' => '000fd3000043', 'ip' => '192.0.2.43']
    ];
    $matches = [
        ['mac' => '000fd3000041', 'extension' => '1000'],
        ['mac' => '000fd3000042', 'extension' => '2000'],
        ['mac' => '000fd3000043', 'extension' => '2000']
    ];
    $result = $module->autoAddSupportedDiscoveredDevices($devices, $matches);
    assertSame(3, $result['added_count']);
    assertSame(0, $result['added_with_extension']);
    assertSame(3, count($result['extension_conflicts']));
    $extensions = $db->query("SELECT extension FROM provisioner_devices WHERE mac_address <> '000fd3000040'")->fetchAll(PDO::FETCH_COLUMN);
    assertSame(['', '', ''], $extensions);
});

test('auto add refreshes only the IP of existing mappings', function () {
    [$module, $db] = makeFixture();
    $db->exec("UPDATE provisioner_settings SET value='sip_notify' WHERE key_name='deploy_mode'");
    insertDevice($db, '000fd3000050', '5000', 'digium', 1, 1);
    $db->exec("UPDATE provisioner_devices SET ip_address='192.0.2.50', previous_extension='4000', description='Preserve me' WHERE mac_address='000fd3000050'");
    $result = $module->autoAddSupportedDiscoveredDevices([
        ['mac' => '000fd3000050', 'ip' => '192.0.2.51']
    ], [['mac' => '000fd3000050', 'extension' => '9999']]);
    assertSame(0, $result['added_count']);
    assertSame(1, $result['refreshed_count']);
    $row = $db->query("SELECT extension, description, brand, ip_address, custom_config, needs_changes, previous_extension FROM provisioner_devices")->fetch(PDO::FETCH_ASSOC);
    assertSame('5000', $row['extension']);
    assertSame('Preserve me', $row['description']);
    assertSame('digium', $row['brand']);
    assertSame('192.0.2.51', $row['ip_address']);
    assertSame('1', (string)$row['custom_config']);
    assertSame('1', (string)$row['needs_changes']);
    assertSame('4000', $row['previous_extension']);
});

test('auto add discovery controls are SIP mode aware', function () {
    $root = dirname(__DIR__);
    $view = file_get_contents($root . '/views/main.php');
    $javascript = file_get_contents($root . '/assets/js/provisioner.js');
    assertTrue(strpos($view, 'id="autoAddSupportedBtn"') !== false);
    assertTrue(strpos($javascript, "discoveryMode === 'sip_notify'") !== false);
    assertTrue(strpos($javascript, 'autoAddSupportedDiscoveredDevices') !== false);
    assertTrue(strpos($javascript, 'Review MAC Mapping') !== false);
});

test('phone status keeps push telemetry in diagnostics instead of extra table columns', function () {
    $root = dirname(__DIR__);
    $view = file_get_contents($root . '/views/main.php');
    $javascript = file_get_contents($root . '/assets/js/provisioner.js');
    assertTrue(strpos($view, 'data-field="last_fetch"') === false);
    assertTrue(strpos($view, 'data-field="last_verify"') === false);
    assertTrue(strpos($view, 'data-field="last_ack"') === false);
    assertTrue(strpos($view, 'id="verifyPushStatusBtn"') !== false);
    assertTrue(strpos($javascript, "ajax('verifyPushStatus'") !== false);
});

test('phone status relies on live updates and refreshes when returning while paused', function () {
    $root = dirname(__DIR__);
    $view = file_get_contents($root . '/views/main.php');
    $javascript = file_get_contents($root . '/assets/js/provisioner.js');
    assertTrue(strpos($view, 'id="refreshPhoneBtn"') === false);
    assertTrue(strpos($javascript, "if (!liveUpdates.enabled) refreshPhoneTable();") !== false);
});

test('diagnostic push stages separate labels, states, and timestamps', function () {
    $root = dirname(__DIR__);
    $view = file_get_contents($root . '/views/main.php');
    $javascript = file_get_contents($root . '/assets/js/provisioner.js');
    assertTrue(strpos($view, '.diagnostic-push-timeline {') !== false);
    assertTrue(strpos($view, '.diagnostic-push-details span') !== false);
    assertTrue(strpos($view, '.diagnostic-push-stage-label') !== false);
    assertTrue(strpos($view, '.diagnostic-push-stage-status') !== false);
    assertTrue(strpos($view, '.diagnostic-push-stage-time') !== false);
    assertTrue(strpos($view, '.diagnostic-push-stages') !== false);
    assertTrue(strpos($view, '#deviceDiagnosticsModal .diagnostic-latest-push') !== false);
    assertTrue(strpos($javascript, 'diagnostic-push-stage-label') !== false);
    assertTrue(strpos($javascript, 'diagnostic-push-stage-time') !== false);
    assertTrue(strpos($javascript, 'class="diagnostic-push-details"') !== false);
    assertTrue(strpos($javascript, 'Downloaded from ') !== false);
});

test('phone status combines push retry and reboot scopes into context-aware actions', function () {
    $view = file_get_contents(dirname(__DIR__) . '/views/main.php');
    $javascript = file_get_contents(dirname(__DIR__) . '/assets/js/provisioner.js');
    assertTrue(strpos($view, 'class="phone-status-toolbar-row"') !== false);
    assertTrue(strpos($view, 'class="phone-status-toolbar-actions"') !== false);
    assertTrue(strpos($view, 'id="applyChangesPhoneBtn"') !== false);
    assertTrue(strpos($view, 'id="rebootActionBtn"') !== false);
    assertTrue(strpos($view, 'id="retryFailedBtn"') === false);
    assertTrue(strpos($view, 'id="rebootSelectedBtn"') === false);
    assertTrue(strpos($view, 'id="rebootAllBtn"') === false);
    assertTrue(strpos($view, 'btn-default btn-sm" id="liveToggleBtn"') !== false);
    assertTrue(strpos($javascript, 'push_retryable') !== false);
    assertTrue(strpos($javascript, 'Retry Push') !== false);
    assertTrue(strpos($javascript, 'Reboot Selected') !== false);
    assertTrue(strpos($javascript, 'No eligible phones to reboot.') !== false);
});

test('phone status exposes structured action eligibility for the UI', function () {
    $root = dirname(__DIR__);
    $php = file_get_contents($root . '/Provisioner.class.php');
    assertTrue(strpos($php, "'push_retryable' =>") !== false);
    assertTrue(strpos($php, "'push_in_progress' =>") !== false);
    assertTrue(strpos($php, "'reboot_eligible' =>") !== false);
});

test('bulk handler uses one simple navigation and focused panels', function () {
    $view = file_get_contents(dirname(__DIR__) . '/views/main.php');
    $javascript = file_get_contents(dirname(__DIR__) . '/assets/js/provisioner.js');
    assertTrue(strpos($view, 'bulk-handler-nav') !== false);
    assertTrue(strpos($view, 'class="bulk-input-grid"') !== false);
    assertTrue(strpos($view, 'class="bulk-report-controls"') !== false);
    assertTrue(strpos($view, 'id="batchHistoryTable"') !== false);
    assertTrue(strpos($view, 'id="batchReportTable"') !== false);
    assertTrue(strpos($view, 'id="batchReportFilter"') !== false);
    assertTrue(strpos($view, 'bulk-saved-panel') !== false);
    assertTrue(strpos($view, 'provisioner-summary-group bulk-report-summary') !== false);
    assertTrue(strpos($view, 'id="backToBatchHistoryBtn"') !== false);
    assertTrue(strpos($view, 'id="exportCsvBtn"><i class="fa fa-download"></i> Download CSV') !== false);
    assertTrue(strpos($javascript, 'function activateBulkSection') !== false);
    assertTrue(strpos($view, 'bulk-report-history-table') !== false);
    assertTrue(strpos($view, 'bulk-report-header') !== false);
    assertTrue(strpos($view, 'bulk-report-table') !== false);
    assertTrue(strpos($javascript, "$('#backToBatchHistoryBtn').on('click'") !== false);
});

test('bulk handler closes before the next top-level tab', function () {
    $view = file_get_contents(dirname(__DIR__) . '/views/main.php');
    assertTrue(strpos($view, "      </div>\n    </div>\n\n    <!-- ===================== PROVISIONING TAB") !== false);
});

test('import review is contained in a readable modal instead of the Bulk Handler page', function () {
    $root = dirname(__DIR__);
    $view = file_get_contents($root . '/views/main.php');
    $javascript = file_get_contents($root . '/assets/js/provisioner.js');
    $modal = strpos($view, 'id="importReviewModal"');
    $results = strpos($view, 'id="importResults"');
    $bulk = strpos($view, 'id="bulkImportSection"');
    assertTrue($modal !== false && $results !== false && $results > $modal);
    assertTrue(substr_count($view, 'id="importResults"') === 1);
    assertTrue(strpos(substr($view, $bulk, $modal - $bulk), 'id="importResults"') === false);
    assertTrue(strpos($view, 'id="importReviewLoading"') !== false);
    assertTrue(strpos($view, 'id="importReviewStatus"') !== false);
    assertTrue(strpos($view, 'id="cancelImportReviewBtn"') !== false);
    assertTrue(strpos($javascript, 'applyImportPreviewFilter') !== false);
    assertTrue(strpos($javascript, "$('#importReviewModal').modal('show')") !== false);
});

test('unidentified cross-subnet ALE target is blocked without being classified as manual IP', function () {
    [$module, $db] = makeFixture();
    $mac = '3c28a6000001';
    insertDevice($db, $mac, '1000', 'alcatel');
    $db->exec("UPDATE provisioner_devices SET static_ip='192.168.0.100', static_mask='255.255.255.0', static_gw='192.168.0.1' WHERE mac_address='$mac'");
    // A static destination does not establish the MAC of the current handset.
    $res = invokePrivate($module, 'pushConfigToPhonesBulk', [['mac' => $mac, 'ip' => '192.168.0.33', 'source' => 'pjsip']]);
    $all = array_merge($res['sent'], $res['failed']);
    assertSame(1, count($all));
    assertTrue(empty($res['failed'][0]['manual_ip'] ?? null), 'ALE must not be pre-classified as manual IP');
    assertTrue(strpos($res['failed'][0]['error'], 'MAC/IP') !== false);
});

test('manual IP message names the held and configured addresses', function () {
    [$module] = makeFixture();
    $msg = invokePrivate($module, 'manualIpMessage', '192.168.0.33', '192.168.0.100');
    assertTrue(strpos($msg, '192.168.0.33') !== false);
    assertTrue(strpos($msg, '192.168.0.100') !== false);
    assertTrue(strpos($msg, 'factory-reset') !== false);
});

test('manual IP flag only after a successful push with a mismatched held IP', function () {
    [$module] = makeFixture();
    $pending = [
        'brand' => 'alcatel', 'static_ip' => '192.168.0.100',
        'ip' => '192.168.0.33', 'last_push_status' => null
    ];
    assertTrue(!invokePrivate($module, 'isAleManualIp', $pending));
    $failed = $pending;
    $failed['last_push_status'] = 'failed';
    assertTrue(!invokePrivate($module, 'isAleManualIp', $failed));
    $matched = $pending;
    $matched['last_push_status'] = 'success';
    assertTrue(invokePrivate($module, 'isAleManualIp', $matched));
    $atStatic = $matched;
    $atStatic['ip'] = '192.168.0.100';
    assertTrue(!invokePrivate($module, 'isAleManualIp', $atStatic));
    $nonAle = $matched;
    $nonAle['brand'] = 'digium';
    assertTrue(!invokePrivate($module, 'isAleManualIp', $nonAle));
    $pendingChange = $matched;
    $pendingChange['needs_changes'] = 1;
    assertTrue(!invokePrivate($module, 'isAleManualIp', $pendingChange));
});

test('provisioning fetch log parser records successful and failed downloads', function () {
    [$module] = makeFixture();
    $success = invokePrivate(
        $module,
        'parseProvisioningFetchLog',
        'pbx:2001 192.168.188.89 - - [12/Oct/2026:16:00:00 +0800] "GET /provisioning/config.3c28a6215f53.xml HTTP/1.1" 200 3380 "-" "ALE M3s"',
        'config.3c28a6215f53.xml'
    );
    assertTrue($success !== null);
    assertTrue($success['success']);
    assertSame('192.168.188.89', $success['ip']);
    assertSame('access_log', $success['source']);

    $failed = invokePrivate(
        $module,
        'parseProvisioningFetchLog',
        'pbx:2001 192.168.188.89 - - [12/Oct/2026:16:00:01 +0800] "GET /provisioning/config.3c28a6215f53.xml HTTP/1.1" 404 568 "-" "ALE M3s"',
        'config.3c28a6215f53.xml'
    );
    assertTrue($failed !== null);
    assertTrue(!$failed['success']);
    assertSame(404, $failed['status_code']);
});

test('download observation sees file access from another worker after an empty poll', function () {
    [$module, $db, $dir] = makeFixture();
    $mac = '3c28a6000080';
    insertDevice($db, $mac, '1080', 'alcatel');
    $path = "$dir/config.$mac.xml";
    file_put_contents($path, '<?xml version="1.0"?><settings>' . str_repeat(' ', 200) . '</settings>');
    $originalMtime = filemtime($path);
    $observation = invokePrivate($module, 'beginFetchObservation', $mac);
    $observation['logs'] = [];
    assertTrue($observation['armed'], 'The unprivileged observation must be armed');
    $check = new ReflectionMethod($module, 'checkFetchObservation');
    $check->setAccessible(true);
    $finish = new ReflectionMethod($module, 'finishFetchObservation');
    $finish->setAccessible(true);

    try {
        assertSame(null, $check->invokeArgs($module, [&$observation]));
        // Apache reads the file in a different process. The polling PHP
        // worker must not keep using the stat values cached by its first poll.
        $command = escapeshellarg(PHP_BINARY) . ' -r '
            . escapeshellarg('if (file_get_contents($argv[1]) === false) exit(1);')
            . ' ' . escapeshellarg($path);
        exec($command, $output, $status);
        assertSame(0, $status, 'The separate worker must read the file');
        $fetch = $check->invokeArgs($module, [&$observation]);
        assertTrue($fetch !== null, 'A fresh file stat must detect the download');
        assertTrue($fetch['success']);
        assertSame('file_access', $fetch['source']);
    } finally {
        $finish->invokeArgs($module, [&$observation]);
    }
    clearstatcache(true, $path);
    assertSame($originalMtime, filemtime($path), 'Restore the config modification timestamp');
});

test('mixed-brand verification still checks a downloaded phone when another times out', function () {
    [$module, $db, $dir] = makeFixture();
    $ale = '3c28a6000082';
    $digium = '000fd3000083';
    insertDevice($db, $ale, '9182', 'alcatel', 0, 1);
    insertDevice($db, $digium, '9183', 'digium', 0, 1);
    $db->exec("UPDATE provisioner_devices SET static_ip='192.168.188.83' WHERE mac_address='$digium'");
    file_put_contents("$dir/config.$ale.xml", '<?xml version="1.0"?><settings>' . str_repeat(' ', 200) . '</settings>');
    file_put_contents("$dir/$digium.cfg", digiumConfig('9183'));
    $observations = [
        $ale => invokePrivate($module, 'beginFetchObservation', $ale),
        $digium => invokePrivate($module, 'beginFetchObservation', $digium)
    ];
    $observations[$ale]['logs'] = [];
    $observations[$digium]['observed'] = invokePrivate($module, 'parseProvisioningFetchLog',
        '192.168.188.83 - - [12/Oct/2026:17:00:00 +0800] "GET /provisioning/' . $digium . '.cfg HTTP/1.1" 200 1505', $digium . '.cfg');
    invokePrivate($module, 'setPushTriggered', $ale, '192.168.188.82');
    invokePrivate($module, 'setPushTriggered', $digium, '192.168.188.83');
    $wait = new ReflectionMethod($module, 'waitForPushVerifications');
    $wait->setAccessible(true);
    $results = $wait->invokeArgs($module, [[$ale => '192.168.188.82', $digium => '192.168.188.83'], &$observations, microtime(true) - 1]);
    assertSame('triggered', $results[$ale]['status']);
    assertTrue(!$results[$ale]['downloaded']);
    assertSame('verified', $results[$digium]['status']);
    assertTrue($results[$digium]['downloaded']);
    $rows = $db->query('SELECT mac_address, last_push_status, needs_changes FROM provisioner_devices')->fetchAll(PDO::FETCH_ASSOC);
    assertSame('triggered', $rows[0]['last_push_status']);
    assertSame('verified', $rows[1]['last_push_status']);
    foreach ($rows as $row) assertSame('1', (string)$row['needs_changes'], 'Finalization stays with the push/swap coordinator');
});

test('push progress filters MACs without changing state or exposing credentials', function () {
    [$module, $db] = makeFixture();
    insertDevice($db, '000fd3000084', '9184', 'digium');
    insertDevice($db, '3c28a6000085', '9185', 'alcatel', 0, 1);
    $db->exec("UPDATE provisioner_devices SET last_push_status='downloaded', last_fetch_status='downloaded', last_verify_status='pending' WHERE extension='9185'");
    $db->exec("INSERT INTO provisioner_settings VALUES ('phone_admin_pass', 'private-password')");
    $progress = $module->getPushProgress(['3C:28:A6:00:00:85']);
    assertTrue($progress['success']);
    assertSame(1, count($progress['devices']));
    assertSame('3c28a6000085', $progress['devices'][0]['mac']);
    assertSame('downloaded', $progress['devices'][0]['status']);
    assertTrue(strpos(json_encode($progress), 'private-password') === false);
    assertSame('1', (string)$db->query("SELECT needs_changes FROM provisioner_devices WHERE extension='9185'")->fetchColumn());
});

test('static verification rejects the old IP after a config download', function () {
    [$module, $db] = makeFixture();
    $mac = '3c28a6000015';
    insertDevice($db, $mac, '1015', 'alcatel');
    $db->exec("UPDATE provisioner_devices SET static_ip='192.168.188.88', static_mask='255.255.255.0', static_gw='192.168.188.254' WHERE mac_address='$mac'");
    $old = invokePrivate($module, 'verifyPhoneAfterFetch', $mac, '192.168.188.89', ['ip' => '192.168.188.89']);
    assertTrue(!$old['verified']);
    $target = invokePrivate($module, 'verifyPhoneAfterFetch', $mac, '192.168.188.88', ['ip' => '192.168.188.88']);
    assertTrue($target['verified']);
    assertSame('config_fetch', $target['method']);
});

test('PJSIP contact identity is joined by contact hash and IP', function () {
    [$module] = makeFixture();
    $summary = "Contact: 200/sip:200@192.168.188.80:5060 abcdef1234 Avail 6.1\n"
        . "Contact: 2000/sip:2000@192.168.188.84:5060 1234567890 Avail 20.1\n";
    $registrar = '/registrar/contact/200;@abcdef12340000000000000000000000: '
        . json_encode(['endpoint' => '200', 'uri' => 'sip:200@192.168.188.80:5060', 'user_agent' => 'Asterisk A20 Phone 2.10.0.6746 000FD3CF406B']) . "\n"
        . '/registrar/contact/2000;@12345678900000000000000000000000: '
        . json_encode(['endpoint' => '2000', 'uri' => 'sip:2000@192.168.188.84:5060', 'user_agent' => 'ALE M3s 2.16.03.000.3164 3c:28:a6:21:5f:53']) . "\n"
        . '/registrar/contact/200;@ffffffffffffffffffffffffffffffff: '
        . json_encode(['endpoint' => '200', 'uri' => 'sip:200@192.168.188.80:5060', 'user_agent' => 'Old phone aabbccddeeff']);
    $contacts = invokePrivate($module, 'getPjsipContacts', $summary, true, $registrar);
    assertSame('000fd3cf406b', $contacts['200']['mac']);
    assertSame('3c28a6215f53', $contacts['2000']['mac']);
    $mismatchedIp = str_replace('192.168.188.80', '192.168.188.81', $registrar);
    $contacts = invokePrivate($module, 'getPjsipContacts', $summary, true, $mismatchedIp);
    assertTrue(empty($contacts['200']['mac']), 'Do not attach an identity from a different contact IP');
});

test('PJSIP discovery finds registered cross-subnet phones without ARP', function () {
    [$module, $db] = makeFixture();
    insertDevice($db, '3c28a6000091', '2000', 'alcatel');
    $contacts = [
        '200' => ['ip' => '192.168.188.80', 'status' => 'Avail', 'mac' => '000fd3000092'],
        '2000' => ['ip' => '192.168.188.84', 'status' => 'Avail', 'mac' => '3c28a6000091'],
        '300' => ['ip' => '192.168.189.80', 'status' => 'Avail', 'mac' => '000fd3000093'],
        '400' => ['ip' => '192.168.188.85', 'status' => 'Unavail', 'mac' => '000fd3000094'],
        'trunk' => ['ip' => '192.168.188.1', 'status' => 'Avail']
    ];
    $result = $module->autoDiscoverFromPjsip($contacts, [], '192.168.188.0/24', ['172.16.138.0/24']);
    assertTrue($result['success']);
    assertSame(2, $result['count']);
    $ale = $result['matches'][1];
    assertSame('3C28A6000091', $ale['mac']);
    assertSame('2000', $ale['extension']);
    assertSame('alcatel', $ale['brand']);
    assertSame('PJSIP User-Agent', $ale['source']);
    assertTrue($ale['seen_now'] && $ale['is_supported'] && $ale['in_provisioner']);
    assertTrue(!$result['matches'][0]['in_provisioner']);
});

test('PJSIP discovery uses ARP fallback only for directly connected contacts', function () {
    [$module] = makeFixture();
    $contacts = [
        '500' => ['ip' => '172.16.138.50', 'status' => 'Avail'],
        '600' => ['ip' => '192.168.188.60', 'status' => 'Avail'],
        '700' => ['ip' => '192.168.188.70', 'status' => 'Avail', 'mac' => '3c28a6000095']
    ];
    $arp = ['000fd3000096' => '172.16.138.50', '000fd3000097' => '192.168.188.60', '000fd3000098' => '192.168.188.70'];
    $result = $module->autoDiscoverFromPjsip($contacts, $arp, '', ['172.16.138.0/24']);
    assertSame(2, $result['count']);
    assertSame('500', $result['matches'][0]['extension']);
    assertSame('PJSIP + ARP', $result['matches'][0]['source']);
    assertSame('3C28A6000095', $result['matches'][1]['mac']);
    assertSame('PJSIP User-Agent', $result['matches'][1]['source']);
});

test('network and PJSIP discovery merge by MAC and keep current registration details', function () {
    [$module] = makeFixture();
    $devices = [
        ['mac' => '000FD3000099', 'ip' => '192.168.188.79', 'source' => 'Neighbor', 'seen_now' => false],
        ['mac' => '805EC0000099', 'ip' => '192.168.188.81', 'source' => 'Neighbor', 'seen_now' => true]
    ];
    $matches = [
        ['mac' => '000FD3000099', 'ip' => '192.168.188.80', 'extension' => '200', 'source' => 'PJSIP User-Agent', 'seen_now' => true],
        ['mac' => '3C28A6000099', 'ip' => '192.168.188.84', 'extension' => '2000', 'source' => 'PJSIP User-Agent', 'seen_now' => true]
    ];
    $merged = invokePrivate($module, 'mergePjsipDiscoveryDevices', $devices, $matches);
    assertSame(3, count($merged));
    assertSame('192.168.188.80', $merged[0]['ip']);
    assertSame('200', $merged[0]['extension']);
    assertTrue($merged[0]['seen_now']);
    assertSame('805EC0000099', $merged[1]['mac']);
    assertSame('2000', $merged[2]['extension']);
});

test('pending swap target resolution follows the handset MAC before and after the swap', function () {
    [$module] = makeFixture();
    $device = ['mac_address' => '3c28a6000100', 'extension' => '200', 'previous_extension' => '2000', 'needs_changes' => 1, 'static_ip' => '192.168.188.80'];
    $contacts = [
        '200' => ['ip' => '192.168.188.80', 'status' => 'Avail', 'mac' => '000fd3000101'],
        '2000' => ['ip' => '192.168.188.84', 'status' => 'Avail', 'mac' => $device['mac_address']]
    ];
    $before = invokePrivate($module, 'resolveDeviceTarget', $device, $contacts, [], [], false, ['172.16.138.0/24']);
    assertSame('192.168.188.84', $before['selected_ip']);
    $contacts['200']['mac'] = $device['mac_address'];
    $contacts['2000']['mac'] = '000fd3000101';
    $after = invokePrivate($module, 'resolveDeviceTarget', $device, $contacts, [], [], false, ['172.16.138.0/24']);
    assertSame('192.168.188.80', $after['selected_ip']);
    assertSame('pjsip', $after['selected_source']);
});

test('full extension and IP swap verifies the new handset identities', function () {
    [$module, $db] = makeFixture();
    $ale = '3c28a6000086';
    $digium = '000fd3000087';
    insertDevice($db, $ale, '200', 'alcatel', 0, 1);
    insertDevice($db, $digium, '2000', 'digium', 0, 1);
    $db->exec("UPDATE provisioner_devices SET previous_extension='2000', static_ip='192.168.188.80' WHERE mac_address='$ale'");
    $db->exec("UPDATE provisioner_devices SET previous_extension='200', static_ip='192.168.188.84' WHERE mac_address='$digium'");
    $old = [
        '200' => ['ip' => '192.168.188.80', 'status' => 'Avail', 'mac' => $digium],
        '2000' => ['ip' => '192.168.188.84', 'status' => 'Avail', 'mac' => $ale]
    ];
    $new = [
        '200' => ['ip' => '192.168.188.80', 'status' => 'Avail', 'mac' => $ale],
        '2000' => ['ip' => '192.168.188.84', 'status' => 'Avail', 'mac' => $digium]
    ];
    foreach ([$ale => '192.168.188.84', $digium => '192.168.188.80'] as $mac => $sourceIp) {
        $fetch = ['success' => true, 'ip' => $sourceIp];
        assertTrue(!invokePrivate($module, 'verifyPhoneAfterFetch', $mac, $sourceIp, $fetch, $old, [])['verified'], 'The old extension/IP pairs belong to the wrong handsets');
        $verified = invokePrivate($module, 'verifyPhoneAfterFetch', $mac, $sourceIp, $fetch, $new, []);
        assertTrue($verified['verified'], 'Verify the assigned extension, target IP, and MAC together');
        assertSame('pjsip_mac', $verified['method']);
    }
});

test('extension changes cannot be verified by an old contact or a fetch alone', function () {
    [$module, $db] = makeFixture();
    $mac = '3c28a6000088';
    insertDevice($db, $mac, '9188', 'alcatel', 0, 1);
    $db->exec("UPDATE provisioner_devices SET previous_extension='9088', static_ip='192.168.188.88' WHERE mac_address='$mac'");
    $fetch = ['success' => true, 'ip' => '192.168.188.88'];
    $old = ['9088' => ['ip' => '192.168.188.88', 'status' => 'Avail', 'mac' => $mac]];
    assertTrue(!invokePrivate($module, 'verifyPhoneAfterFetch', $mac, $fetch['ip'], $fetch, $old, [$mac => $fetch['ip']])['verified']);
    $new = ['9188' => ['ip' => $fetch['ip'], 'status' => 'Avail', 'mac' => '000fd3000088']];
    assertTrue(!invokePrivate($module, 'verifyPhoneAfterFetch', $mac, $fetch['ip'], $fetch, $new, [])['verified']);
    $new['9188']['mac'] = $mac;
    $new['9188']['status'] = 'Unavail';
    assertTrue(!invokePrivate($module, 'verifyPhoneAfterFetch', $mac, $fetch['ip'], $fetch, $new, [])['verified']);
    $new['9188']['status'] = 'Avail';
    unset($new['9188']['mac']);
    assertTrue(!invokePrivate($module, 'verifyPhoneAfterFetch', $mac, $fetch['ip'], $fetch, $new, [])['verified']);
    assertTrue(invokePrivate($module, 'verifyPhoneAfterFetch', $mac, $fetch['ip'], $fetch, $new, [$mac => $fetch['ip']])['verified'], 'Fresh MAC/IP evidence can identify phones without a MAC in their user agent');
});

test('late swap verification only clears pending state when both phones match', function () {
    [$module, $db] = makeFixture();
    $ale = '3c28a6000089';
    $digium = '000fd3000090';
    insertDevice($db, $ale, '200', 'alcatel', 0, 1);
    insertDevice($db, $digium, '2000', 'digium', 0, 1);
    $db->exec("UPDATE provisioner_devices SET previous_extension='2000', static_ip='192.168.188.80', last_fetch_ip='192.168.188.84' WHERE mac_address='$ale'");
    $db->exec("UPDATE provisioner_devices SET previous_extension='200', static_ip='192.168.188.84', last_fetch_ip='192.168.188.80' WHERE mac_address='$digium'");
    $db->exec("UPDATE provisioner_devices SET last_push_status='downloaded', last_fetch_status='downloaded'");
    $contacts = ['200' => ['ip' => '192.168.188.80', 'status' => 'Avail', 'mac' => $ale]];
    $partial = $module->verifyPushStatus($ale, $contacts, []);
    assertTrue(!$partial['success'], 'Keep an incomplete swap pending');
    assertTrue($partial['verified'], 'Record the checked handset as verified');
    assertSame(2, (int)$db->query('SELECT COUNT(*) FROM provisioner_devices WHERE needs_changes=1')->fetchColumn());
    $contacts['2000'] = ['ip' => '192.168.188.84', 'status' => 'Avail', 'mac' => $digium];
    $complete = $module->verifyPushStatus($ale, $contacts, []);
    assertTrue($complete['success']);
    assertSame(0, (int)$db->query('SELECT COUNT(*) FROM provisioner_devices WHERE needs_changes=1')->fetchColumn());
    assertSame(0, (int)$db->query('SELECT COUNT(*) FROM provisioner_devices WHERE previous_extension IS NOT NULL')->fetchColumn());
});

test('delivery completes without performing registration verification or finalizing changes', function () {
    [$module, $db, $dir] = makeFixture();
    $mac = '3c28a6000120';
    insertDevice($db, $mac, '9120', 'alcatel', 0, 1);
    file_put_contents("$dir/config.$mac.xml", '<settings>' . str_repeat(' ', 200) . '</settings>');
    $observation = invokePrivate($module, 'beginFetchObservation', $mac);
    $observation['observed'] = ['success' => true, 'ip' => '192.168.188.120'];
    $observations = [$mac => $observation];
    $method = new ReflectionMethod($module, 'waitForPushDownloads');
    $method->setAccessible(true);
    $result = $method->invokeArgs($module, [[$mac => '192.168.188.120'], &$observations, microtime(true) - 1]);
    assertSame('downloaded', $result[$mac]['status']);
    assertTrue(!$result[$mac]['verified']);
    assertSame('pending', $db->query('SELECT last_verify_status FROM provisioner_devices')->fetchColumn());
    assertSame(1, (int)$db->query('SELECT needs_changes FROM provisioner_devices')->fetchColumn());
    assertTrue(!$observations[$mac]['armed']);
});

test('background verification requires a registered handset even when the download IP matches', function () {
    [$module, $db] = makeFixture();
    $mac = '000fd3000121';
    insertDevice($db, $mac, '9121', 'digium', 0, 1);
    $id = makeBackgroundJob($module, [$mac => '192.168.188.121']);
    $result = $module->processPushVerificationJob($id, [], [], microtime(true) - 1);
    assertTrue(!$result[$mac]['verified']);
    assertSame('downloaded', $db->query('SELECT last_push_status FROM provisioner_devices')->fetchColumn());
    assertSame('failed', $db->query('SELECT last_verify_status FROM provisioner_devices')->fetchColumn());
    assertSame(1, (int)$db->query('SELECT needs_changes FROM provisioner_devices')->fetchColumn());
    assertSame('incomplete', $db->query('SELECT status FROM provisioner_push_jobs')->fetchColumn());
    assertSame(false, $module->processPushVerificationJob($id, [], [], microtime(true) - 1), 'A finished job cannot be claimed twice');
});

test('background worker finalizes a complete swap atomically', function () {
    [$module, $db] = makeFixture();
    $ale = '3c28a6000122';
    $digium = '000fd3000123';
    insertDevice($db, $ale, '200', 'alcatel', 0, 1);
    insertDevice($db, $digium, '2000', 'digium', 0, 1);
    $db->exec("UPDATE provisioner_devices SET previous_extension='2000', static_ip='192.168.188.80' WHERE mac_address='$ale'");
    $db->exec("UPDATE provisioner_devices SET previous_extension='200', static_ip='192.168.188.84' WHERE mac_address='$digium'");
    $id = makeBackgroundJob($module, [$ale => '192.168.188.84', $digium => '192.168.188.80'], [[$ale, $digium]]);
    $contacts = [
        '200' => ['ip' => '192.168.188.80', 'status' => 'Avail', 'mac' => $ale],
        '2000' => ['ip' => '192.168.188.84', 'status' => 'Avail', 'mac' => $digium]
    ];
    $result = $module->processPushVerificationJob($id, $contacts, [], microtime(true) - 1);
    assertTrue($result[$ale]['verified'] && $result[$digium]['verified']);
    assertSame(0, (int)$db->query('SELECT COUNT(*) FROM provisioner_devices WHERE needs_changes=1 OR previous_extension IS NOT NULL')->fetchColumn());
    assertSame('complete', $db->query('SELECT status FROM provisioner_push_jobs')->fetchColumn());
});

test('background worker keeps both swap members pending after partial verification', function () {
    [$module, $db] = makeFixture();
    $ale = '3c28a6000124';
    $digium = '000fd3000125';
    insertDevice($db, $ale, '200', 'alcatel', 0, 1);
    insertDevice($db, $digium, '2000', 'digium', 0, 1);
    $db->exec("UPDATE provisioner_devices SET previous_extension='2000' WHERE mac_address='$ale'");
    $db->exec("UPDATE provisioner_devices SET previous_extension='200' WHERE mac_address='$digium'");
    $id = makeBackgroundJob($module, [$ale => '192.168.188.80', $digium => '192.168.188.84'], [[$ale, $digium]]);
    $contacts = ['200' => ['ip' => '192.168.188.80', 'status' => 'Avail', 'mac' => $ale]];
    $result = $module->processPushVerificationJob($id, $contacts, [], microtime(true) - 1);
    assertTrue($result[$ale]['verified'] && !$result[$digium]['verified']);
    assertSame(2, (int)$db->query('SELECT COUNT(*) FROM provisioner_devices WHERE needs_changes=1')->fetchColumn());
    assertSame(2, (int)$db->query('SELECT COUNT(*) FROM provisioner_devices WHERE previous_extension IS NOT NULL')->fetchColumn());
});

test('a superseded background job cannot overwrite a newer push', function () {
    [$module, $db] = makeFixture();
    $mac = '000fd3000126';
    insertDevice($db, $mac, '9126', 'digium', 0, 1);
    $old = makeBackgroundJob($module, [$mac => '192.168.188.126']);
    $new = makeBackgroundJob($module, [$mac => '192.168.188.126']);
    $contacts = ['9126' => ['ip' => '192.168.188.126', 'status' => 'Avail', 'mac' => $mac]];
    $result = $module->processPushVerificationJob($old, $contacts, [], microtime(true) - 1);
    assertSame('superseded', $result[$mac]['method']);
    assertSame('pending', $db->query('SELECT last_verify_status FROM provisioner_devices')->fetchColumn());
    assertSame($new, $db->query('SELECT last_push_job_id FROM provisioner_devices')->fetchColumn());
    assertSame(1, (int)$db->query('SELECT needs_changes FROM provisioner_devices')->fetchColumn());
});

test('editing a mapping cancels old background finalization', function () {
    [$module, $db] = makeFixture();
    $mac = '000fd3000127';
    insertDevice($db, $mac, '9127', 'digium', 0, 1);
    $id = makeBackgroundJob($module, [$mac => '192.168.188.127']);
    $deviceId = (int)$db->query('SELECT id FROM provisioner_devices')->fetchColumn();
    assertTrue($module->updateDevice($deviceId, $mac, '9227')['success']);
    $contacts = ['9127' => ['ip' => '192.168.188.127', 'status' => 'Avail', 'mac' => $mac]];
    $result = $module->processPushVerificationJob($id, $contacts, [], microtime(true) - 1);
    assertSame('superseded', $result[$mac]['method']);
    assertSame('9227', $db->query('SELECT extension FROM provisioner_devices')->fetchColumn());
    assertSame(1, (int)$db->query('SELECT needs_changes FROM provisioner_devices')->fetchColumn());
});

test('delayed delivery updates cannot reclaim a newer push', function () {
    [$module, $db] = makeFixture();
    $mac = '000fd3000130';
    insertDevice($db, $mac, '9130', 'digium', 0, 1);
    $old = makeBackgroundJob($module, [$mac => '192.168.188.130']);
    $new = makeBackgroundJob($module, [$mac => '192.168.188.131']);
    invokePrivate($module, 'setPushTriggered', $mac, '192.168.188.130', $old);
    invokePrivate($module, 'setPushDownloaded', $mac, '192.168.188.130', $old);
    invokePrivate($module, 'setPushTriggerFailed', $mac, $old);
    invokePrivate($module, 'setPushFetchFailed', $mac, $old);
    $row = $db->query('SELECT * FROM provisioner_devices')->fetch(PDO::FETCH_ASSOC);
    assertSame($new, $row['last_push_job_id']);
    assertSame('192.168.188.131', $row['last_fetch_ip']);
    assertSame('downloaded', $row['last_push_status']);
    assertSame('pending', $row['last_verify_status']);
});

test('background verification rejects the wrong handset and wrong target address', function () {
    foreach ([['mac' => '000fd3ffffff', 'ip' => '192.168.188.132'], ['mac' => '000fd3000132', 'ip' => '192.168.188.133']] as $contact) {
        [$module, $db] = makeFixture();
        $mac = '000fd3000132';
        insertDevice($db, $mac, '9132', 'digium', 0, 1);
        $db->exec("UPDATE provisioner_devices SET static_ip='192.168.188.132'");
        $id = makeBackgroundJob($module, [$mac => '192.168.188.132']);
        $contact['status'] = 'Avail';
        $result = $module->processPushVerificationJob($id, ['9132' => $contact], [], microtime(true) - 1);
        assertTrue(!$result[$mac]['verified']);
        assertSame(1, (int)$db->query('SELECT needs_changes FROM provisioner_devices')->fetchColumn());
    }
});

test('stalled verification workers expire without clearing pending changes', function () {
    [$module, $db] = makeFixture();
    $mac = '000fd3000128';
    insertDevice($db, $mac, '9128', 'digium', 0, 1);
    makeBackgroundJob($module, [$mac => '192.168.188.128']);
    $db->exec("UPDATE provisioner_push_jobs SET created_at='2000-01-01 00:00:00'");
    $module->getPushProgress([$mac]);
    assertSame('failed', $db->query('SELECT status FROM provisioner_push_jobs')->fetchColumn());
    assertSame('failed', $db->query('SELECT last_verify_status FROM provisioner_devices')->fetchColumn());
    assertSame(1, (int)$db->query('SELECT needs_changes FROM provisioner_devices')->fetchColumn());
});

test('ALE XML supplies documented IPv4 mode and address keys for static and DHCP', function () {
    [$module, $db, $dir] = makeFixture();
    $mac = '3c28a6000129';
    insertDevice($db, $mac, '9129', 'alcatel', 0, 1);
    $db->exec("INSERT INTO sip VALUES ('9129', 'secret', 'test-secret')");
    $db->exec("UPDATE provisioner_devices SET static_ip='192.168.188.84', static_mask='255.255.255.0', static_gw='192.168.188.254'");
    assertTrue($module->applyChanges(true, false, false)['success']);
    $values = [];
    foreach (simplexml_load_file("$dir/config.$mac.xml")->setting as $setting) $values[(string)$setting['id']] = (string)$setting['value'];
    assertSame('Static', $values['DeviceNetworkDhcpMode']);
    assertSame('192.168.188.84', $values['DeviceNetworkIpAddress']);
    assertSame('255.255.255.0', $values['DeviceNetworkSubnetMask']);
    assertSame('192.168.188.254', $values['DeviceNetworkGateway']);
    assertSame($values['DeviceNetworkDhcpMode'], $values['LocalEnetcfgDhcpMode']);
    $db->exec('UPDATE provisioner_devices SET static_ip=NULL, static_mask=NULL, static_gw=NULL');
    assertTrue($module->applyChanges(true, false, false)['success']);
    $xml = simplexml_load_file("$dir/config.$mac.xml");
    assertSame('Dynamic', (string)$xml->xpath('setting[@id="DeviceNetworkDhcpMode"]')[0]['value']);
});

test('revert restores the previous static IP and clears pending state', function () {
    [$module, $db] = makeFixture();
    $mac = '000fd3000012';
    $stmt = $db->prepare("INSERT INTO provisioner_devices (mac_address, extension, description, brand, static_ip, static_mask, static_gw, needs_changes) VALUES (?, ?, 'Test phone', 'alcatel', '192.168.0.99', '255.255.255.0', '192.168.0.1', 0)");
    $stmt->execute([$mac, '1000']);
    $id = (int)$db->lastInsertId();

    $res = $module->updateDevice($id, $mac, '1000', 'Test phone', 'alcatel', '', '192.168.0.100', '255.255.255.0', '192.168.0.1');
    assertTrue($res['success']);
    $row = $db->query("SELECT static_ip, needs_changes, previous_static_ip FROM provisioner_devices WHERE id=$id")->fetch(PDO::FETCH_ASSOC);
    assertSame('192.168.0.100', $row['static_ip']);
    assertSame('1', (string)$row['needs_changes']);
    assertSame('192.168.0.99', $row['previous_static_ip']);

    $res = $module->revertDevice($id);
    assertTrue($res['success']);
    $row = $db->query("SELECT static_ip, static_mask, static_gw, needs_changes, previous_static_ip, previous_extension FROM provisioner_devices WHERE id=$id")->fetch(PDO::FETCH_ASSOC);
    assertSame('192.168.0.99', $row['static_ip']);
    assertSame('255.255.255.0', $row['static_mask']);
    assertSame('192.168.0.1', $row['static_gw']);
    assertSame('0', (string)$row['needs_changes']);
    assertTrue($row['previous_static_ip'] === null);
});

test('discard all changes restores previous static values', function () {
    [$module, $db] = makeFixture();
    $mac = '000fd3000013';
    $stmt = $db->prepare("INSERT INTO provisioner_devices (mac_address, extension, description, brand, static_ip, static_mask, static_gw, needs_changes) VALUES (?, ?, 'Test phone', 'alcatel', '192.168.0.99', '255.255.255.0', '192.168.0.1', 0)");
    $stmt->execute([$mac, '1000']);
    $id = (int)$db->lastInsertId();

    $res = $module->updateDevice($id, $mac, '1000', 'Test phone', 'alcatel', '', '192.168.0.200', '255.255.255.0', '192.168.0.1');
    assertTrue($res['success']);
    assertSame('192.168.0.200', $db->query("SELECT static_ip FROM provisioner_devices WHERE id=$id")->fetchColumn());

    $res = $module->discardAllChanges();
    assertTrue($res['success']);
    assertSame(1, $res['affected']);
    $row = $db->query("SELECT static_ip, needs_changes, previous_static_ip FROM provisioner_devices WHERE id=$id")->fetch(PDO::FETCH_ASSOC);
    assertSame('192.168.0.99', $row['static_ip']);
    assertSame('0', (string)$row['needs_changes']);
    assertTrue($row['previous_static_ip'] === null);
});

test('plain description edit does not stage a static revert', function () {
    [$module, $db] = makeFixture();
    $mac = '000fd3000014';
    $stmt = $db->prepare("INSERT INTO provisioner_devices (mac_address, extension, description, brand, static_ip, static_mask, static_gw, needs_changes) VALUES (?, ?, 'Old name', 'alcatel', '192.168.0.99', '255.255.255.0', '192.168.0.1', 0)");
    $stmt->execute([$mac, '1000']);
    $id = (int)$db->lastInsertId();

    $res = $module->updateDevice($id, $mac, '1000', 'New name', 'alcatel', '', '192.168.0.99', '255.255.255.0', '192.168.0.1');
    assertTrue($res['success']);
    $row = $db->query("SELECT previous_static_ip, previous_static_mask, previous_static_gw, previous_description FROM provisioner_devices WHERE id=$id")->fetch(PDO::FETCH_ASSOC);
    assertTrue($row['previous_static_ip'] === null);
    assertTrue($row['previous_static_mask'] === null);
    assertTrue($row['previous_static_gw'] === null);
    assertSame('Old name', $row['previous_description']);
});

test('module initialization upgrades an old device schema', function () {
    $db = new PDO('sqlite::memory:');
    $db->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    $db->exec("CREATE TABLE provisioner_devices (id INTEGER PRIMARY KEY, mac_address TEXT, extension TEXT)");
    new Provisioner(new TestFreePBX($db));
    $columns = array_column($db->query("PRAGMA table_info(provisioner_devices)")->fetchAll(PDO::FETCH_ASSOC), 'name');
    foreach (['previous_static_ip', 'previous_static_mask', 'previous_static_gw', 'last_fetch_at', 'last_fetch_ip', 'last_fetch_status', 'last_verify_at', 'last_verify_ip', 'last_verify_status'] as $column) {
        assertTrue(in_array($column, $columns, true), "$column was not migrated");
    }
});

test('module and installer versions agree', function () {
    $root = dirname(__DIR__);
    $xml = simplexml_load_file($root . '/module.xml');
    $version = (string)$xml->version;
    foreach (['install.php', 'upgrade.php'] as $file) {
        $content = file_get_contents($root . '/' . $file);
        $pattern = '/\$version\s*=\s*[\'\"]' . preg_quote($version, '/') . '[\'\"]/';
        assertTrue((bool)preg_match($pattern, $content), "$file version differs from module.xml");
    }
});

require __DIR__ . '/live_status.php';

require __DIR__ . '/test_discovery.php';
require __DIR__ . '/test_bulk_workflow.php';

$passed = 0;
$failed = 0;
foreach ($tests as $name => $callback) {
    try {
        $callback();
        echo "PASS $name\n";
        $passed++;
    } catch (Throwable $error) {
        echo "FAIL $name: " . $error->getMessage() . "\n";
        $failed++;
    }
}
foreach ($tempDirs as $dir) removeTree($dir);
echo "\n$passed passed, $failed failed\n";
exit($failed === 0 ? 0 : 1);
