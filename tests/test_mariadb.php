<?php
// Opt-in only. Never load /etc/freepbx.conf or a default database connection.
$directory = getenv('PROVISIONER_MARIADB_TEST_DIRECTORY');
if (!$directory || !preg_match('#^/tmp/opencode/provisioner-mariadb-[a-zA-Z0-9_]+$#D',$directory)
    || !is_dir($directory.'/data') || !file_exists($directory.'/db.sock')) {
    throw new RuntimeException('Run only through tests/run_mariadb.py with a private database.');
}
define('PROVISIONER_TEST_DEFINITIONS_ONLY',true);
require __DIR__.'/run.php';
$tests = [];

// Standalone configuration for the real Database class. No service container
// or installed PBX bootstrap is loaded; hooks run in a separate staged process.
class FreePBX {
    public static $conf = [];
}
$libraries = '/var/www/html/admin/libraries';
require $libraries.'/Composer/vendor/autoload.php';
spl_autoload_register(function ($class) use ($libraries) {
    if (strpos($class,'FreePBX\\Database\\') !== 0) return;
    $relative = str_replace('\\','/',substr($class,strlen('FreePBX\\')));
    foreach (['.class.php','.php'] as $suffix) {
        $file = $libraries.'/BMO/'.$relative.$suffix;
        if (is_file($file)) { require_once $file; return; }
    }
});
require $libraries.'/BMO/Database.class.php';
FreePBX::$conf = ['AMPDBSOCK'=>$directory.'/db.sock','AMPDBUSER'=>'root','AMPDBPASS'=>'',
    'AMPDBNAME'=>'provisioner_test','AMPDBENGINE'=>'mysql'];
$admin = new PDO('mysql:unix_socket='.$directory.'/db.sock','root','',[PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION]);
assertSame(realpath($directory.'/data'),realpath($admin->query('SELECT @@datadir')->fetchColumn()));
assertSame(1,(int)$admin->query('SELECT @@skip_networking')->fetchColumn());
echo 'Private MariaDB: '.$admin->query('SELECT VERSION()')->fetchColumn()."; network disabled\n";

function mariaFixture($count = 0, $strict = true) {
    global $admin,$directory,$tempDirs;
    $database = 'provisioner_test_'.bin2hex(random_bytes(4));
    $admin->exec("CREATE DATABASE `$database` CHARACTER SET utf8mb4");
    $db = new \FreePBX\Database('mysql:unix_socket='.$directory.'/db.sock;dbname='.$database,'root','');
    $db->exec('SET SESSION sql_mode='. $db->quote($strict ? 'STRICT_ALL_TABLES,NO_ENGINE_SUBSTITUTION' : ''));
    // Exercise the installed FreePBX XML loader itself, not a hand-built schema.
    $xml = simplexml_load_file(dirname(__DIR__).'/module.xml');
    $db->migrateMultipleXML($xml->database->table);
    // The real loader opens a second Doctrine connection. Release that idle
    // connection between fixtures on the deliberately small private server.
    $doctrine=$db->getDoctrineConnection();
    // FreePBX 16 wraps the existing PDO in Doctrine. Closing that wrapper
    // loses its selected database on reconnect; it is not a second connection.
    // Older DBAL removes 'pdo' from its public params; a reconnectable
    // standalone connection must at least retain its database name.
    if (!empty($doctrine->getParams()['dbname'])) $doctrine->close();
    $db->exec('CREATE TABLE sip (id VARCHAR(20),keyword VARCHAR(50),data TEXT)');
    $db->exec('CREATE TABLE pjsip (id VARCHAR(20),keyword VARCHAR(50),data TEXT)');
    $db->exec('CREATE TABLE featurecodes (modulename VARCHAR(100),featurename VARCHAR(100),defaultcode VARCHAR(100),customcode VARCHAR(100),enabled INTEGER)');
    $db->exec('CREATE TABLE modules (modulename VARCHAR(100),version VARCHAR(100),enabled INTEGER)');
    \FreePBX\modules\Provisioner\PushJobSchema::install($db);
    \FreePBX\modules\Provisioner\RolloutSchema::install($db);
    $files = $directory.'/fixture-'.bin2hex(random_bytes(4));
    mkdir($files,0700); $tempDirs[] = $files;
    foreach (['prov_dir'=>$files,'pbx_ip'=>'192.0.2.10','sip_port'=>'5060','transport'=>'UDP','deploy_mode'=>'dhcp',
        'push_download_timeout'=>'1','push_verify_timeout'=>'2'] as $key=>$value) {
        $db->prepare('INSERT INTO provisioner_settings (key_name,value) VALUES (?,?)')->execute([$key,$value]);
    }
    $macs=[];
    for ($i=0;$i<$count;$i++) {
        $mac=sprintf('000fd3%06x',$i); $macs[]=$mac;
        $db->prepare("INSERT INTO provisioner_devices (mac_address,extension,description,brand,custom_config,needs_changes,created_at,updated_at) VALUES (?,?,'Test phone','digium',0,0,NOW(),NOW())")
            ->execute([$mac,(string)(1000+$i)]);
        file_put_contents($files.'/'.$mac.'.cfg',digiumConfig((string)(1000+$i)));
    }
    return [new RolloutEngineFixture($db,$files),$db,$files,$macs];
}

test('rollout control seed is repeatable without replacing saved ownership on old or new MariaDB',function () {
    [, $db]=mariaFixture();
    $db->exec("UPDATE provisioner_rollout_control SET token='compatibility-fixture',expires=12345 WHERE id=1");
    foreach([1,2] as $repeat)\FreePBX\modules\Provisioner\RolloutSchema::install($db);
    assertSame(1,(int)$db->query('SELECT COUNT(*) FROM provisioner_rollout_control')->fetchColumn());
    $row=$db->query('SELECT token,expires,schema_revision FROM provisioner_rollout_control WHERE id=1')->fetch(PDO::FETCH_ASSOC);
    assertSame('compatibility-fixture',$row['token']);assertSame(12345,(int)$row['expires']);
    assertSame(\FreePBX\modules\Provisioner\RolloutSchema::REVISION,(int)$row['schema_revision']);
});

test('FreePBX XML schema and official hooks work repeatedly against isolated MariaDB',function () use ($directory) {
    [$module,$db,$files,$macs]=mariaFixture(1);
    // Copy only hook inputs into a disposable directory. ReceiverAccess must
    // never chmod/write the real module's .htaccess during integration tests.
    $staging=$directory.'/hook-fixture'; mkdir($staging,0700); mkdir($staging.'/lib',0700);
    foreach (['install.php','upgrade.php','lib/ActiveArp.php','lib/PushJobSchema.php','lib/RolloutSchema.php','lib/ReceiverAccess.php','lib/discovery-receiver.htaccess.dist'] as $file) {
        copy(dirname(__DIR__).'/'.$file,$staging.'/'.$file);
    }
    // Hook libraries must load in a fresh process, not redeclare classes from
    // the source engine already loaded by this test runner.
    $pipes=[];
    $process=proc_open([PHP_BINARY,__DIR__.'/mariadb_hooks.php',$db->query('SELECT DATABASE()')->fetchColumn()],
        [0=>['pipe','r'],1=>['pipe','w'],2=>['pipe','w']],$pipes);
    assertTrue(is_resource($process)); fclose($pipes[0]);
    $output=stream_get_contents($pipes[1]); $error=stream_get_contents($pipes[2]);
    fclose($pipes[1]); fclose($pipes[2]);
    assertSame(0,proc_close($process),$output.$error);
    assertSame(1,json_decode($output,true)['scheduled_lines']);
    assertTrue(is_file($staging.'/.htaccess'));
    assertSame(1,(int)$db->query('SELECT COUNT(*) FROM modules')->fetchColumn());
    assertSame(1,(int)$db->query('SELECT COUNT(*) FROM provisioner_devices')->fetchColumn());
    assertSame('mediumtext',\FreePBX\modules\Provisioner\PushJobSchema::payloadType($db));
    assertSame(\FreePBX\modules\Provisioner\RolloutSchema::REVISION,(int)$db->query('SELECT schema_revision FROM provisioner_rollout_control')->fetchColumn());
    foreach (['provisioner_devices','provisioner_push_jobs','provisioner_rollouts','provisioner_rollout_owners','provisioner_rollout_control','provisioner_batches','provisioner_batch_items'] as $table) {
        assertSame('InnoDB',$db->query("SHOW TABLE STATUS WHERE Name='$table'")->fetch(PDO::FETCH_ASSOC)['Engine']);
    }
});

foreach ([true,false] as $strict) {
    test('300-phone rollout on MariaDB '.($strict?'strict':'non-strict').' SQL mode',function () use ($strict) {
        [$module,$db,,$macs]=mariaFixture(300,$strict);
        $result=$module->pushToPhones($macs);
        assertTrue($result['success'], $result['error'] ?? 'Submission failed');
        assertSame(300,$result['selected_count']); assertSame([],$module->dispatches);
        $same=$module->pushToPhones(array_reverse($macs)); assertSame($result['batch_id'],$same['batch_id']);
        assertTrue(!$module->pushToPhones(array_slice($macs,0,2))['success']);
        for($i=0;$i<12;$i++) assertTrue($module->processRolloutQueue());
        assertSame(false,$module->processRolloutQueue());
        assertSame(array_fill(0,12,25),array_map('count',$module->dispatches));
        $report=$module->getBatchReport($result['batch_id']);
        assertSame('complete',$report['rollout']['status']); assertSame(300,$report['counts']['verified']);
        assertSame(0,(int)$db->query('SELECT COUNT(*) FROM provisioner_rollout_owners')->fetchColumn());
        assertSame(300,$module->getBatchHistory()['batches'][0]['counts']['verified']);
    });
}

test('legacy TEXT migration and 300-phone payload survive real MariaDB without truncation',function () {
    [$module,$db,,$macs]=mariaFixture(300);
    $db->exec('ALTER TABLE provisioner_push_jobs MODIFY payload TEXT NOT NULL');
    \FreePBX\modules\Provisioner\PushJobSchema::install($db);
    \FreePBX\modules\Provisioner\PushJobSchema::install($db);
    $db->exec("UPDATE provisioner_devices SET description=REPEAT('x',255)");
    $phones=[]; foreach($macs as $mac) $phones[$mac]=$module->ip($mac);
    $id=str_repeat('a',32);
    $snapshots=invokePrivate($module,'prepareBulkPushJob',$id,$phones,[]);
    $payload=$db->query('SELECT payload FROM provisioner_push_jobs')->fetchColumn();
    assertTrue(strlen($payload)>65535);
    assertSame($snapshots,json_decode($payload,true)['snapshots']);
    assertSame(300,count(json_decode($payload,true)['phones']));
});

test('old wave positions survive both MariaDB migration orderings',function () {
    foreach([false,true] as $xmlAddedField) {
        [$module,$db,,$macs]=mariaFixture(26);
        $result=$module->pushToPhones($macs); $module->processRolloutQueue();
        $db->exec('ALTER TABLE provisioner_rollouts CHANGE COLUMN wave_cursor `cursor` INTEGER NOT NULL DEFAULT 0');
        if($xmlAddedField) $db->migrateMultipleXML(simplexml_load_file(dirname(__DIR__).'/module.xml')->database->table);
        \FreePBX\modules\Provisioner\RolloutSchema::install($db);
        \FreePBX\modules\Provisioner\RolloutSchema::install($db);
        assertSame(1,(int)$db->query('SELECT wave_cursor FROM provisioner_rollouts')->fetchColumn());
        $module->processRolloutQueue(); assertSame([25,1],array_map('count',$module->dispatches));
        assertSame('complete',$module->getBatchReport($result['batch_id'])['rollout']['status']);
    }
});

test('MariaDB finalization race stops the next wave without false verification',function () {
    [$module,$db,,$macs]=mariaFixture(26);
    $db->exec("CREATE TRIGGER change_mapping BEFORE UPDATE ON provisioner_devices FOR EACH ROW BEGIN IF NEW.last_push_status='verified' AND OLD.description='Test phone' THEN SET NEW.description='Changed during verification',NEW.needs_changes=1; END IF; END");
    $result=$module->pushToPhones($macs); $module->processRolloutQueue();
    $report=$module->getBatchReport($result['batch_id']);
    assertSame('review',$report['rollout']['status']); assertSame(25,$report['counts']['superseded']);
    assertSame(0,$report['counts']['verified'] ?? 0); assertSame(false,$module->processRolloutQueue());
    assertSame([25],array_map('count',$module->dispatches));
});

test('MariaDB submission rollback leaves no report or ownership after persistence failure',function () {
    [$module,$db,,$macs]=mariaFixture(2);
    $db->exec("CREATE TRIGGER deny_submission BEFORE INSERT ON provisioner_rollouts FOR EACH ROW SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT='Fixture queue write denied'");
    $result=$module->pushToPhones($macs);
    assertTrue(!$result['success']); assertSame([],$module->dispatches);
    foreach(['provisioner_batches','provisioner_batch_items','provisioner_rollout_owners','provisioner_rollouts'] as $table) {
        assertSame(0,(int)$db->query("SELECT COUNT(*) FROM $table")->fetchColumn());
    }
});

test('MariaDB control-row contention cannot dispatch a competing worker',function () use ($directory) {
    [$module,$db,,$macs]=mariaFixture(1);
    $result=$module->pushToPhones($macs);
    $other=new PDO('mysql:unix_socket='.$directory.'/db.sock;dbname='.$db->query('SELECT DATABASE()')->fetchColumn(),'root','',[PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION]);
    $other->exec('SET SESSION innodb_lock_wait_timeout=1');
    $db->beginTransaction(); $db->query('SELECT * FROM provisioner_rollout_control WHERE id=1 FOR UPDATE');
    $called=false;
    try {
        (new \FreePBX\modules\Provisioner\RolloutQueue($other))->workOne(function()use(&$called){$called=true;return true;});
        throw new RuntimeException('Expected control-row lock timeout');
    } catch(PDOException $error) {
        assertSame(1205,(int)$error->errorInfo[1]);
    } finally { $db->rollBack(); }
    assertTrue(!$called); assertSame([],$module->dispatches);
    assertTrue(!$other->inTransaction());
    $module->processRolloutQueue();
    assertSame('complete',$module->getBatchReport($result['batch_id'])['rollout']['status']);
});

test('denied MariaDB migrations fail closed and restore database error mode',function () use ($admin,$directory) {
    [$module,$db,,$macs]=mariaFixture(1);
    $database=$db->query('SELECT DATABASE()')->fetchColumn();
    $user='fixture_'.bin2hex(random_bytes(4));
    $password=bin2hex(random_bytes(16));
    $admin->exec('CREATE USER '.$admin->quote($user)."@'localhost' IDENTIFIED BY ".$admin->quote($password));
    $admin->exec('GRANT SELECT,INSERT,UPDATE,DELETE,CREATE ON `'.$database.'`.* TO '.$admin->quote($user)."@'localhost'");
    $limited=new PDO('mysql:unix_socket='.$directory.'/db.sock;dbname='.$database,$user,$password,[PDO::ATTR_ERRMODE=>PDO::ERRMODE_SILENT]);
    $db->exec('ALTER TABLE provisioner_push_jobs MODIFY payload TEXT NOT NULL');
    try {
        \FreePBX\modules\Provisioner\PushJobSchema::install($limited);
        throw new RuntimeException('Expected denied ALTER privilege');
    } catch(PDOException $error) { assertSame(1142,(int)$error->errorInfo[1]); }
    assertSame(PDO::ERRMODE_SILENT,$limited->getAttribute(PDO::ATTR_ERRMODE));
    assertSame('text',\FreePBX\modules\Provisioner\PushJobSchema::payloadType($db));
    assertTrue(!$module->pushToPhones($macs)['success']); assertSame([],$module->dispatches);
    \FreePBX\modules\Provisioner\PushJobSchema::install($db);
    $db->exec('DROP INDEX rollout_queue ON provisioner_rollouts');
    try {
        \FreePBX\modules\Provisioner\RolloutSchema::install($limited);
        throw new RuntimeException('Expected denied INDEX privilege');
    } catch(PDOException $error) { assertSame(1142,(int)$error->errorInfo[1]); }
    assertSame(PDO::ERRMODE_SILENT,$limited->getAttribute(PDO::ATTR_ERRMODE));
    assertSame(0,(int)$db->query('SELECT schema_revision FROM provisioner_rollout_control')->fetchColumn());
    assertTrue(!$module->pushToPhones($macs)['success']); assertSame([],$module->dispatches);
    \FreePBX\modules\Provisioner\RolloutSchema::install($db);
    assertTrue($module->pushToPhones($macs)['success']);
});

test('MariaDB recovery after actual worker termination never replays dispatch',function () use ($directory) {
    [$module,$db,,$macs]=mariaFixture(2);
    $result=$module->pushToPhones($macs);
    $dsn='mysql:unix_socket='.$directory.'/db.sock;dbname='.$db->query('SELECT DATABASE()')->fetchColumn();
    $script='require '.var_export(dirname(__DIR__).'/lib/RolloutQueue.php',true).';'
        .'$db=new PDO('.var_export($dsn,true).',"root","",[PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION]);'
        .'$queue=new \\FreePBX\\modules\\Provisioner\\RolloutQueue($db);'
        .'$queue->workOne(function($id,$wave,$queue){$queue->checkpoint($wave);echo "checkpoint\\n";flush();while(true)usleep(100000);});';
    $pipes=[];
    $process=proc_open([PHP_BINARY,'-r',$script],[0=>['pipe','r'],1=>['pipe','w'],2=>['pipe','w']],$pipes);
    assertTrue(is_resource($process)); fclose($pipes[0]); stream_set_timeout($pipes[1],5);
    try {
        assertSame("checkpoint\n",fgets($pipes[1]));
        assertSame(false,$module->processRolloutQueue());
    } finally { proc_terminate($process,9); fclose($pipes[1]); fclose($pipes[2]); proc_close($process); }
    $db->exec('UPDATE provisioner_rollout_control SET expires=1');
    $db->exec('UPDATE provisioner_rollouts SET expires=1');
    $module->processRolloutQueue();
    assertSame([],$module->dispatches);
    $report=$module->getBatchReport($result['batch_id']);
    assertSame('review',$report['rollout']['status']); assertSame(2,$report['counts']['incomplete']);
    assertSame(0,(int)$db->query('SELECT COUNT(*) FROM provisioner_rollout_owners')->fetchColumn());
});

test('official MariaDB hooks convert legacy MyISAM tables without losing data',function () {
    [$module,$db,,$macs]=mariaFixture(1);
    $result=$module->pushToPhones($macs);
    foreach(['provisioner_devices','provisioner_batches','provisioner_batch_items'] as $table) $db->exec("ALTER TABLE $table ENGINE=MyISAM");
    \FreePBX\modules\Provisioner\RolloutSchema::install($db);
    foreach(['provisioner_devices','provisioner_batches','provisioner_batch_items'] as $table) assertSame('InnoDB',$db->query("SHOW TABLE STATUS WHERE Name='$table'")->fetch(PDO::FETCH_ASSOC)['Engine']);
    $module->processRolloutQueue();
    assertSame('complete',$module->getBatchReport($result['batch_id'])['rollout']['status']);
    assertSame(1,(int)$db->query('SELECT COUNT(*) FROM provisioner_devices')->fetchColumn());
});

test('MariaDB manual verification is blocked during settling without superseding the wave',function () {
    [$module,$db,,$macs]=mariaFixture(2);
    $db->exec("UPDATE provisioner_devices SET previous_extension=''");
    $checked=false;
    $module->onSettle=function()use($module,$db,$macs,&$checked){
        $before=$db->query('SELECT * FROM provisioner_devices ORDER BY mac_address')->fetchAll(PDO::FETCH_ASSOC);
        $result=$module->verifyPushStatus($macs[0],['1000'=>['ip'=>$module->ip($macs[0]),'status'=>'Avail','mac'=>$macs[0]]],[]);
        assertSame('rollout_active',$result['code']); assertTrue(!$result['success']);
        assertSame($before,$db->query('SELECT * FROM provisioner_devices ORDER BY mac_address')->fetchAll(PDO::FETCH_ASSOC));
        $checked=true;
    };
    $queued=$module->pushToPhones($macs);
    $diagnostics=$module->getDeviceDiagnostics($macs[0]);
    assertSame(false,$diagnostics['manual_verification']['allowed']);
    assertSame($queued['batch_id'],$diagnostics['manual_verification']['batch_id']);
    $module->processRolloutQueue();
    assertTrue($checked);
    $report=$module->getBatchReport($queued['batch_id']);
    assertSame('complete',$report['rollout']['status']); assertSame(2,$report['counts']['verified']);
    $result=$module->verifyPushStatus($macs[0],['1000'=>['ip'=>$module->ip($macs[0]),'status'=>'Avail','mac'=>$macs[0]]],[]);
    assertTrue($result['success']);
});

test('MariaDB manual verification serializes with rollout submission and worker claims',function () use ($directory) {
    [$module,$db,$files,$macs]=mariaFixture(1);
    $queued=$module->pushToPhones($macs);
    $other=new PDO('mysql:unix_socket='.$directory.'/db.sock;dbname='.$db->query('SELECT DATABASE()')->fetchColumn(),'root','',[PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION]);
    $other->exec('SET SESSION innodb_lock_wait_timeout=1');
    $second=new RolloutEngineFixture($other,$files);
    $before=$db->query('SELECT * FROM provisioner_devices')->fetchAll(PDO::FETCH_ASSOC);
    $db->beginTransaction();$db->query('SELECT * FROM provisioner_rollout_control WHERE id=1 FOR UPDATE');
    try {
        $result=$second->verifyPushStatus($macs[0],[],[]);
        assertTrue(!$result['success']); assertSame('verification_unavailable',$result['code']);
        assertTrue(!$other->inTransaction());
    } finally {$db->rollBack();}
    assertSame($before,$db->query('SELECT * FROM provisioner_devices')->fetchAll(PDO::FETCH_ASSOC));
    $module->processRolloutQueue();
    assertSame('complete',$module->getBatchReport($queued['batch_id'])['rollout']['status']);
});

test('MariaDB automatic routed identification saves only matching evidence before delivery',function () {
    [, $db,$files,$macs]=mariaFixture(1);
    $module=new PreflightFixture($db,$files);$module->setSetting('pbx_ip','172.16.138.13');
    $queued=$module->pushToPhones($macs);$module->processRolloutQueue();
    assertSame(['probe','callback','identify'],array_column($module->operations,'type'));
    assertSame('complete',$module->getBatchReport($queued['batch_id'])['rollout']['status']);
    assertSame($macs[0],$db->query('SELECT mac_address FROM provisioner_discovery_observations')->fetchColumn());
    assertSame(1,count($module->dispatches));
});

test('MariaDB range-required recovery creates an explicit linked retry without guessing a subnet',function () {
    [, $db,$files,$macs]=mariaFixture(1);
    $module=new PreflightFixture($db,$files);$module->setSetting('pbx_ip','172.16.138.13');$module->knownIp=false;
    $queued=$module->pushToPhones($macs);$module->processRolloutQueue();
    assertSame([],$module->operations);assertSame([],$module->getBatchReport($queued['batch_id'])['retry_macs']);
    assertTrue($module->saveSettings(['push_search_subnets'=>'10.10.0.0/24'])['success']);
    $retry=$module->retryBatch($queued['batch_id']);$module->processRolloutQueue();
    $report=$module->getBatchReport($retry['batch_id']);
    assertSame('complete',$report['rollout']['status']);assertSame($queued['batch_id'],$report['batch']['parent_id']);
    assertSame('10.10.0.0/24',$module->operations[0]['payload']['subnet']);
});

test('MariaDB local pushes retain their existing rules when active ARP is unavailable',function () {
    [, $db,$files,$macs]=mariaFixture(1);
    $module=new PreflightFixture($db,$files);$module->local=true;$module->arpStatus='unavailable';
    $queued=$module->pushToPhones($macs);$module->processRolloutQueue();
    $report=$module->getBatchReport($queued['batch_id']);
    assertSame('complete',$report['rollout']['status']);assertSame('unavailable',$report['items'][0]['local_arp_refresh']);
    assertSame(['arp'],array_column($module->operations,'type'));
});

$passed=0; $failed=0;
try {
    foreach($tests as $name=>$callback) {
        try { $callback(); echo "PASS $name\n"; $passed++; }
        catch(Throwable $error) { echo "FAIL $name: ".$error->getMessage()."\n".$error->getTraceAsString()."\n"; $failed++; }
        finally { gc_collect_cycles(); }
    }
} finally {
    foreach($tempDirs as $dir) removeTree($dir);
}
echo "$passed MariaDB integration tests passed, $failed failed\n";
exit($failed?1:0);
