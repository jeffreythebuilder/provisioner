<?php

use FreePBX\modules\Provisioner\RolloutQueue;
use FreePBX\modules\Provisioner\RolloutSchema;
use FreePBX\modules\Provisioner\FetchLogObserver;

// Real queue + generation/delivery/verification engine, with all external
// discovery, Asterisk, transport and access logs replaced by local fixtures.
class RolloutEngineFixture extends \FreePBX\modules\Provisioner {
    public $database;
    public $directory;
    public $dispatches = [];
    public $offline = [];
    public $wrongIdentity = [];
    public $badRegistration = [];
    public $skipDownloads = [];
    public $throwDuringDispatch = false;
    public $onDispatch;
    public $onSettle;
    public $settles = 0;
    public function __construct($db, $directory) {
        $this->database = $db; $this->directory = $directory;
        file_put_contents($directory.'/access.log','');
        parent::__construct(new TestFreePBX($db));
    }
    protected function getPjsipContacts($output = null, $withIdentity = false, $registrarOutput = null) {
        $contacts = [];
        foreach ($this->database->query('SELECT * FROM provisioner_devices')->fetchAll(PDO::FETCH_ASSOC) as $row) {
            $mac = $row['mac_address'];
            $contacts[$row['extension']] = ['ip'=>$this->ip($mac), 'status'=>$this->dispatches && in_array($mac,$this->badRegistration,true) ? 'Unavail' : 'Avail',
                'mac'=>in_array($mac,$this->wrongIdentity,true) ? '000fd3ffffff' : $mac];
        }
        return $contacts;
    }
    public function ip($mac) { $n = hexdec(substr($mac,-6)); return '10.10.' . intdiv($n,254) . '.' . ($n%254+1); }
    protected function getCurrentArpMap() { return []; }
    protected function getArpCapableSubnets() { return []; }
    protected function resolveDeviceTargets($performScan = false, $onlyMac = null) {
        $targets = [];
        foreach ($this->database->query('SELECT mac_address FROM provisioner_devices')->fetchAll(PDO::FETCH_COLUMN) as $mac) {
            $targets[$mac] = ['selected_ip'=>$this->ip($mac),'selected_source'=>'fixture','selected_port'=>5060,'selected_transport'=>'udp'];
        }
        return $targets;
    }
    protected function getProvisioningAccessLogPaths() { return [$this->directory.'/access.log']; }
    protected function settleRolloutWave() { $this->settles++; if ($this->onSettle) ($this->onSettle)(); }
    protected function dispatchRolloutPhones($phones, $command) {
        $this->dispatches[] = array_column($phones,'mac');
        foreach ($phones as $phone) {
            $stmt = $this->database->prepare('SELECT data FROM provisioner_batch_items WHERE mac=? ORDER BY batch_id DESC');
            $stmt->execute([$phone['mac']]);
            $checkpointed = false;
            foreach ($stmt->fetchAll(PDO::FETCH_COLUMN) as $data) if (!empty(json_decode($data,true)['dispatch_checkpoint'])) $checkpointed = true;
            assertTrue($checkpointed,'Dispatch must be checkpointed before transport is called');
        }
        if ($this->onDispatch) ($this->onDispatch)($phones);
        if ($this->throwDuringDispatch) throw new RuntimeException('Fixture worker stopped during dispatch');
        $results = [];
        foreach ($phones as $phone) {
            $mac = $phone['mac'];
            $success = !in_array($mac,$this->offline,true);
            $results[$mac] = ['success'=>$success,'ip'=>$phone['phone_ip']];
            if ($success && !in_array($mac,$this->skipDownloads,true)) {
                $name = is_file($this->directory.'/config.'.$mac.'.xml') ? 'config.'.$mac.'.xml' : $mac.'.cfg';
                file_put_contents($this->directory.'/access.log', $phone['phone_ip'].' - - [fixture] "GET /provisioning/'.$name.' HTTP/1.1" 200 100 "-" "fixture"' . "\n", FILE_APPEND);
            }
        }
        return json_encode(['results'=>$results]);
    }
}

function rolloutFixture($count = 1, $persistent = false) {
    [, $db, $directory] = makeFixture($persistent);
    $macs = [];
    for ($i=0; $i<$count; $i++) {
        $mac = sprintf('000fd3%06x',$i); $macs[] = $mac;
        insertDevice($db,$mac,(string)(1000+$i));
        file_put_contents($directory.'/'.$mac.'.cfg',digiumConfig((string)(1000+$i)));
    }
    $module = new RolloutEngineFixture($db,$directory);
    $module->setSetting('push_download_timeout','1');
    $module->setSetting('push_verify_timeout','2');
    return [$module,$db,$directory,$macs];
}

test('async submission of 300 phones is side-effect-free and completes in twelve verified waves', function () {
    [$module,$db,,$macs] = rolloutFixture(300);
    $result = $module->pushToPhones($macs);
    assertTrue($result['success'] && $result['queued']);
    assertSame(300,$result['selected_count']); assertSame([],$module->dispatches);
    assertSame(300,(int)$db->query('SELECT COUNT(*) FROM provisioner_rollout_owners')->fetchColumn());
    $duplicate = $module->pushToPhones(array_reverse($macs));
    assertSame($result['batch_id'],$duplicate['batch_id']);
    $db->exec("UPDATE provisioner_batches SET created_at='2000-01-01 00:00:00'");
    assertTrue($module->getBatchReport($result['batch_id'])['active'],'Old queued work must not expire on a report read');
    for ($i=0;$i<12;$i++) assertTrue($module->processRolloutQueue());
    assertSame(false,$module->processRolloutQueue());
    assertSame(array_fill(0,12,25),array_map('count',$module->dispatches));
    assertSame(12,$module->settles,'Every delivered wave must settle before final verification');
    assertSame(300,count(array_unique(array_merge(...$module->dispatches))));
    $report = $module->getBatchReport($result['batch_id']);
    assertSame(300,$report['counts']['verified']); assertTrue(!$report['active']);
    assertSame('complete',$report['batch']['status']);
    assertSame(0,(int)$db->query('SELECT COUNT(*) FROM provisioner_rollout_owners')->fetchColumn());
    $history = $module->getBatchHistory();
    assertSame(300,$history['batches'][0]['counts']['verified']);
    assertSame(300,(int)$history['batches'][0]['device_count']);
});

test('overlapping selections are rejected and explicit request keys remain idempotent', function () {
    [$module,$db,,$macs] = rolloutFixture(3);
    $_REQUEST['request_id'] = 'fixture_request_123456';
    try {
        $first = $module->pushToPhones([$macs[0],$macs[1]]);
        $same = $module->pushToPhones([$macs[0],$macs[1]]);
        assertSame($first['batch_id'],$same['batch_id']);
        unset($_REQUEST['request_id']);
        assertTrue(!$module->pushToPhones([$macs[1],$macs[2]])['success']);
        assertSame(1,(int)$db->query('SELECT COUNT(*) FROM provisioner_rollouts')->fetchColumn());
        $module->processRolloutQueue();
        $_REQUEST['request_id'] = 'fixture_request_123456';
        $replay = $module->pushToPhones([$macs[0],$macs[1]]);
        assertSame($first['batch_id'],$replay['batch_id']);
        assertSame('complete',$replay['queue_status']);
        $db->exec("UPDATE provisioner_devices SET description='Changed' WHERE mac_address='".$macs[0]."'");
        assertTrue(!$module->pushToPhones([$macs[0],$macs[1]])['success']);
    } finally { unset($_REQUEST['request_id']); }
});

test('offline wave stops subsequent waves and explicit report retry preserves scope', function () {
    [$module,$db,,$macs] = rolloutFixture(55);
    $module->offline = [$macs[0]];
    $result = $module->pushToPhones($macs);
    $module->processRolloutQueue();
    assertSame(1,count($module->dispatches));
    assertSame('review',$db->query('SELECT status FROM provisioner_rollouts')->fetchColumn());
    $report = $module->getBatchReport($result['batch_id']);
    assertSame(24,$report['counts']['verified']);
    assertSame(31,count($report['retry_macs']));
    $retry = $module->retryBatch($result['batch_id']);
    assertTrue($retry['queued']); assertSame(31,$retry['selected_count']);
    assertSame($result['batch_id'],$module->getBatchReport($retry['batch_id'])['batch']['parent_id']);
});

test('slow or identity-conflicting endpoints cannot be reported verified', function () {
    foreach (['badRegistration','wrongIdentity'] as $field) {
        [$module,$db,,$macs] = rolloutFixture(2);
        $module->$field = [$macs[0]];
        $result = $module->pushToPhones($macs);
        $module->processRolloutQueue();
        $report = $module->getBatchReport($result['batch_id']);
        $items = array_column($report['items'],null,'mac');
        assertTrue($items[$macs[0]]['outcome'] !== 'verified');
        if ($field === 'wrongIdentity') assertTrue(!in_array($macs[0],array_merge(...$module->dispatches),true));
        assertSame('review',$report['rollout']['status']);
    }
});

test('submission persistence failure rolls back report and ownership before transport', function () {
    [$module,$db,,$macs] = rolloutFixture(2);
    $db->exec("CREATE TRIGGER deny_queue BEFORE INSERT ON provisioner_rollouts BEGIN SELECT RAISE(FAIL,'fixture queue storage failure'); END");
    assertTrue(!$module->pushToPhones($macs)['success']);
    assertSame(0,(int)$db->query('SELECT COUNT(*) FROM provisioner_batches')->fetchColumn());
    assertSame(0,(int)$db->query('SELECT COUNT(*) FROM provisioner_rollout_owners')->fetchColumn());
    assertSame([],$module->dispatches);
    $db->exec('DROP TRIGGER deny_queue');
    $db->prepare('UPDATE provisioner_devices SET description=?')->execute(["\xff"]);
    assertTrue(!$module->pushToPhones($macs)['success'],'Invalid JSON must not produce a colliding queue fingerprint');
    assertSame(0,(int)$db->query('SELECT COUNT(*) FROM provisioner_rollouts')->fetchColumn());
});

test('stale queued mappings stop before transport and single notify cannot bypass ownership', function () {
    [$module,$db,,$macs] = rolloutFixture(2);
    $result = $module->pushToPhones([$macs[0]]);
    assertTrue(!$module->pushToPhones($macs)['success']);
    assertSame($result['batch_id'],$module->sendSipNotify('10.0.0.1',$macs[0])['batch_id']);
    $db->exec("UPDATE provisioner_devices SET extension='9999' WHERE mac_address='".$macs[0]."'");
    $module->processRolloutQueue();
    assertSame([],$module->dispatches);
    $report = $module->getBatchReport($result['batch_id']);
    assertSame('review',$report['rollout']['status']);
    assertSame([],$report['retry_macs']);
});

test('fresh worker lease excludes competitors and stale pre-dispatch work can resume', function () {
    [$module,$db,,$macs] = rolloutFixture(1);
    $result = $module->pushToPhones($macs);
    $db->exec("UPDATE provisioner_rollout_control SET token='old-worker',expires=".(time()+300));
    assertSame(false,$module->processRolloutQueue());
    $db->exec("UPDATE provisioner_rollout_control SET expires=1");
    $db->exec("UPDATE provisioner_rollouts SET status='running',token='old-worker',expires=1");
    assertTrue($module->processRolloutQueue());
    assertSame(1,count($module->dispatches));
    assertSame('complete',$module->getBatchReport($result['batch_id'])['rollout']['status']);
});

test('killed worker after dispatch is never blindly replayed', function () {
    [$module,$db,,$macs] = rolloutFixture(40);
    $result = $module->pushToPhones($macs);
    $stmt = $db->prepare('SELECT data FROM provisioner_batch_items WHERE batch_id=? AND mac=?');
    $stmt->execute([$result['batch_id'],$macs[0]]); $data = json_decode($stmt->fetchColumn(),true);
    $data['dispatch_checkpoint'] = date('c');
    $db->prepare('UPDATE provisioner_batch_items SET data=? WHERE batch_id=? AND mac=?')->execute([json_encode($data),$result['batch_id'],$macs[0]]);
    $db->exec("UPDATE provisioner_rollouts SET status='running',expires=1,token='dead-worker'");
    $db->exec("UPDATE provisioner_rollout_control SET token='dead-worker',expires=1");
    $module->processRolloutQueue();
    assertSame([],$module->dispatches);
    $report = $module->getBatchReport($result['batch_id']);
    assertSame('review',$report['rollout']['status']);
    assertSame(40,$report['counts']['incomplete']);
    assertTrue(strpos($report['rollout']['error'],'uncertain') !== false);
});

test('transport exception leaves durable uncertainty and releases ownership for explicit review', function () {
    [$module,$db,,$macs] = rolloutFixture(2);
    $module->throwDuringDispatch = true;
    $result = $module->pushToPhones($macs);
    $module->processRolloutQueue();
    assertSame('review',$module->getBatchReport($result['batch_id'])['rollout']['status']);
    assertSame(1,count($module->dispatches));
    assertSame(0,(int)$db->query('SELECT COUNT(*) FROM provisioner_rollout_owners')->fetchColumn());
    assertSame(false,$module->processRolloutQueue());
});

test('swap groups are indivisible and oversized groups are rejected before queue insertion', function () {
    $macs = array_map(function ($i) { return sprintf('000fd3%06x',$i); }, range(0,59));
    $group = [$macs[23],$macs[24],$macs[25]];
    $waves = RolloutQueue::waves($macs,[$group],25);
    foreach ($waves as $wave) {
        assertTrue(count($wave)<=25);
        assertTrue(!array_intersect($group,$wave) || count(array_intersect($group,$wave)) === 3);
    }
    try { RolloutQueue::waves($macs,[array_slice($macs,0,26)],25); throw new RuntimeException('Expected oversized group rejection'); }
    catch (RuntimeException $error) { assertTrue(strpos($error->getMessage(),'exceeds') !== false); }
});

test('incremental shared observer ignores stale/unrelated lines and handles partial lines and rotation', function () {
    [$module,,$directory] = makeFixture();
    $path = $directory.'/shared.log';
    $line = '10.0.0.1 - - [fixture] "GET /provisioning/000fd3000001.cfg HTTP/1.1" 200 10';
    file_put_contents($path,$line."\n");
    $observations = ['000fd3000001'=>['filename'=>'000fd3000001.cfg','logs'=>[$path=>filesize($path)]]];
    $observer = new FetchLogObserver($observations,function ($line,$name) use ($module) { return invokePrivate($module,'parseProvisioningFetchLog',$line,$name); });
    assertSame([],$observer->poll());
    file_put_contents($path,$line,FILE_APPEND); assertSame([],$observer->poll());
    rename($path,$path.'.1'); file_put_contents($path,'unrelated' . "\n");
    file_put_contents($path.'.1',"\n",FILE_APPEND);
    assertTrue($observer->poll()['000fd3000001']['success']);
    unset($observer);
    $observations['000fd3000001']['logs'][$path] = filesize($path);
    $observer = new FetchLogObserver($observations,function ($line,$name) use ($module) { return invokePrivate($module,'parseProvisioningFetchLog',$line,$name); });
    file_put_contents($path,$line."\n");
    // Exercise copytruncate after a cursor has advanced beyond the new size.
    $observer->poll();
    file_put_contents($path,''); $observer->poll();
    file_put_contents($path,$line."\n");
    assertTrue($observer->poll()['000fd3000001']['success']);
});

test('official rollout schema and scheduler setup are idempotent in isolated fixtures', function () {
    [, $db] = makeFixture();
    RolloutSchema::install($db); RolloutSchema::install($db);
    assertSame(1,(int)$db->query('SELECT COUNT(*) FROM provisioner_rollout_control')->fetchColumn());
    $cron = new class {
        public $lines = [];
        public function getAll() { return $this->lines; }
        public function removeLine($line) {
            $index = array_search($line,$this->lines,true);
            if ($index !== false) { unset($this->lines[$index]); $this->lines = array_values($this->lines); }
        }
        public function addLine($line) { $this->lines[] = $line; }
    };
    RolloutSchema::schedule($cron,'/fixture module'); RolloutSchema::schedule($cron,'/fixture module');
    assertSame(1,count($cron->lines)); assertTrue(strpos($cron->lines[0],"'/fixture module/rollout_worker.php'") !== false);
    $cron->lines[] = '0 * * * * /usr/bin/true # unrelated-task';
    RolloutSchema::schedule($cron,'/changed fixture module');
    assertSame(2,count($cron->lines));
    assertTrue(strpos(implode("\n",$cron->lines),"'/fixture module/rollout_worker.php'") === false);
    RolloutSchema::unschedule($cron);
    assertSame(['0 * * * * /usr/bin/true # unrelated-task'],array_values($cron->lines));
    RolloutSchema::unschedule($cron);
    assertSame(1,count($cron->lines));
});

test('actual killed worker is excluded while alive and recovered without notification replay', function () {
    [$module,$db,$directory,$macs] = rolloutFixture(2,true);
    $result = $module->pushToPhones($macs);
    $script = $directory.'/crash-worker.php';
    file_put_contents($script, '<?php require '.var_export(dirname(__DIR__).'/lib/RolloutQueue.php',true).';'
        .'$db=new PDO("sqlite:".$argv[1]);$db->setAttribute(PDO::ATTR_ERRMODE,PDO::ERRMODE_EXCEPTION);'
        .'$db->sqliteCreateFunction("NOW",function(){return date("Y-m-d H:i:s");});'
        .'$queue=new \\FreePBX\\modules\\Provisioner\\RolloutQueue($db);'
        .'$queue->workOne(function($id,$wave,$queue){$queue->checkpoint($wave);echo "checkpoint\\n";flush();while(true)usleep(100000);});');
    $pipes = [];
    $process = proc_open([PHP_BINARY,$script,$directory.'/fixture.sqlite'],[0=>['pipe','r'],1=>['pipe','w'],2=>['pipe','w']],$pipes);
    assertTrue(is_resource($process)); fclose($pipes[0]); stream_set_timeout($pipes[1],5);
    try {
        assertSame("checkpoint\n",fgets($pipes[1]));
        assertSame(false,$module->processRolloutQueue(),'A second worker cannot dispatch while the first lease is fresh');
    } finally {
        proc_terminate($process,9); fclose($pipes[1]); fclose($pipes[2]); proc_close($process);
    }
    // Advance lease expiry without waiting five real minutes.
    $db->exec('UPDATE provisioner_rollout_control SET expires=1');
    $db->exec('UPDATE provisioner_rollouts SET expires=1');
    $module->processRolloutQueue();
    assertSame([],$module->dispatches);
    assertSame('review',$module->getBatchReport($result['batch_id'])['rollout']['status']);
});

test('dispatch checkpoint storage failure prevents transport and old workers are fenced', function () {
    [$module,$db,,$macs] = rolloutFixture(2);
    $result = $module->pushToPhones($macs);
    $db->exec("CREATE TRIGGER deny_dispatch BEFORE UPDATE OF data ON provisioner_batch_items WHEN NEW.data LIKE '%dispatch_checkpoint%' BEGIN SELECT RAISE(FAIL,'fixture checkpoint failure'); END");
    $module->processRolloutQueue();
    assertSame([],$module->dispatches);
    assertSame('review',$module->getBatchReport($result['batch_id'])['rollout']['status']);
    $db->exec('DROP TRIGGER deny_dispatch');
    $retry = $module->retryBatch($result['batch_id']);
    assertTrue($retry['queued']);
    $queue = new RolloutQueue($db);
    try {
        $queue->workOne(function ($id,$wave,$queue) use ($db) {
            $db->exec("UPDATE provisioner_rollout_control SET token='replacement-worker'");
            $queue->checkpoint($wave);
            throw new RuntimeException('Must not reach transport');
        });
        throw new RuntimeException('Expected fencing failure');
    } catch (RuntimeException $error) {
        assertTrue(strpos($error->getMessage(),'lease lost') !== false);
    }
    assertSame([],$module->dispatches);
});

test('async generation preserves custom config and swap finalization stays atomic', function () {
    [$module,$db,$directory,$macs] = rolloutFixture(2);
    $db->exec("UPDATE provisioner_devices SET custom_config=1,needs_changes=1 WHERE mac_address='".$macs[0]."'");
    $before = file_get_contents($directory.'/'.$macs[0].'.cfg');
    $result = $module->pushToPhones([$macs[0]]); $module->processRolloutQueue();
    assertSame($before,file_get_contents($directory.'/'.$macs[0].'.cfg'));
    assertSame('custom_preserved',$module->getBatchReport($result['batch_id'])['items'][0]['generation']);
    assertSame(0,(int)$db->query("SELECT needs_changes FROM provisioner_devices WHERE mac_address='".$macs[0]."'")->fetchColumn());

    foreach ([false,true] as $partial) {
        [$module,$db,,$macs] = rolloutFixture(2);
        $db->exec("UPDATE provisioner_devices SET needs_changes=1,previous_extension=CASE extension WHEN '1000' THEN '1001' ELSE '1000' END");
        $db->exec("INSERT INTO sip VALUES ('1000','secret','fixture-secret'),('1001','secret','fixture-secret')");
        if ($partial) $module->badRegistration = [$macs[0]];
        $result = $module->pushToPhones([$macs[1]]); // expansion must own both
        assertSame(2,$result['selected_count']); $module->processRolloutQueue();
        assertSame(1,count($module->dispatches)); assertSame(2,count($module->dispatches[0]));
        assertSame($partial ? 2 : 0,(int)$db->query('SELECT COUNT(*) FROM provisioner_devices WHERE needs_changes=1')->fetchColumn());
        assertSame($partial ? 2 : 0,(int)$db->query('SELECT COUNT(*) FROM provisioner_devices WHERE previous_extension IS NOT NULL')->fetchColumn());
    }
});

test('mapped reboot and legacy apply cannot bypass an active queued rollout', function () {
    [$module,,$directory,$macs] = rolloutFixture(2);
    $result = $module->pushToPhones([$macs[0]]);
    $reboot = $module->rebootPhone('1000');
    assertTrue(!$reboot['success']); assertTrue(strpos($reboot['error'],'active push') !== false);
    assertSame([],$module->dispatches);
    $apply = $module->applyChanges(true,true,true,[$macs[1]]);
    assertTrue($apply['queued']); assertSame([],$module->dispatches);
});

test('late download evidence is observed incrementally without retriggering the handset', function () {
    [$module,$db,$directory,$macs] = rolloutFixture(1);
    $module->skipDownloads = $macs;
    $process = null; $pipes = [];
    $module->onDispatch = function ($phones) use ($directory,&$process,&$pipes) {
        $phone = $phones[0];
        $line = $phone['phone_ip'].' - - [fixture] "GET /provisioning/'.$phone['mac'].'.cfg HTTP/1.1" 200 100' . "\n";
        $process = proc_open([PHP_BINARY,'-r','usleep(200000); file_put_contents($argv[1],$argv[2],FILE_APPEND);',$directory.'/access.log',$line],
            [0=>['pipe','r'],1=>['pipe','w'],2=>['pipe','w']],$pipes);
        fclose($pipes[0]);
    };
    try {
        $result = $module->pushToPhones($macs); $module->processRolloutQueue();
        assertSame(1,count($module->dispatches));
        assertSame('complete',$module->getBatchReport($result['batch_id'])['rollout']['status']);
    } finally {
        if (is_resource($process)) { fclose($pipes[1]); fclose($pipes[2]); proc_close($process); }
    }
});

test('custom file edits while queued are detected even without a mapping timestamp change', function () {
    [$module,$db,$directory,$macs] = rolloutFixture(1);
    $result = $module->pushToPhones($macs);
    file_put_contents($directory.'/'.$macs[0].'.cfg',digiumConfig('1000')."Changed : fixture\n");
    $module->processRolloutQueue();
    assertSame([],$module->dispatches);
    assertSame('review',$module->getBatchReport($result['batch_id'])['rollout']['status']);
});

test('final identity conflicts block every swap partner and rollout settings are bounded', function () {
    [$module,$db,,$macs] = rolloutFixture(2);
    $db->exec("UPDATE provisioner_devices SET needs_changes=1,previous_extension=CASE extension WHEN '1000' THEN '1001' ELSE '1000' END");
    $db->exec("INSERT INTO sip VALUES ('1000','secret','fixture-secret'),('1001','secret','fixture-secret')");
    $module->wrongIdentity = [$macs[0]];
    $result = $module->pushToPhones([$macs[1]]); $module->processRolloutQueue();
    assertSame([],$module->dispatches);
    assertSame('review',$module->getBatchReport($result['batch_id'])['rollout']['status']);
    foreach (['0','31','-1','1.5','abc'] as $size) assertTrue(!$module->saveSettings(['rollout_wave_size'=>$size])['success']);
    foreach (['0','121','1.5'] as $seconds) assertTrue(!$module->saveSettings(['rollout_settle_seconds'=>$seconds])['success']);
    assertTrue($module->saveSettings(['rollout_wave_size'=>'20','rollout_settle_seconds'=>'30'])['success']);
    assertSame('20',$module->getSetting('rollout_wave_size'));
});

test('managed verification is not expired by batch age or claimed by a detached legacy verifier', function () {
    [$module,$db,,$macs] = rolloutFixture(1);
    $result = $module->pushToPhones($macs);
    $job = makeBackgroundJob($module,[$macs[0]=>$module->ip($macs[0])]);
    $db->prepare('UPDATE provisioner_batch_items SET job_id=? WHERE batch_id=?')->execute([$job,$result['batch_id']]);
    $db->exec("UPDATE provisioner_push_jobs SET created_at='2000-01-01 00:00:00'");
    $db->exec("UPDATE provisioner_batches SET created_at='2000-01-01 00:00:00'");
    $module->getPushProgress($macs);
    assertSame('queued',$db->query('SELECT status FROM provisioner_push_jobs')->fetchColumn());
    assertTrue($module->getBatchReport($result['batch_id'])['active']);
    assertSame(false,$module->processPushVerificationJob($job,[],[],microtime(true)-1));
    assertSame('queued',$db->query('SELECT status FROM provisioner_push_jobs')->fetchColumn());
});

test('an incomplete official schema migration blocks submission without runtime repairs', function () {
    [$module,$db,,$macs] = rolloutFixture(1);
    $db->exec('UPDATE provisioner_rollout_control SET schema_revision=0');
    $result = $module->pushToPhones($macs);
    assertTrue(!$result['success']); assertTrue(strpos($result['error'],'Module Admin') !== false);
    assertSame(0,(int)$db->query('SELECT schema_revision FROM provisioner_rollout_control')->fetchColumn());
    assertSame(0,(int)$db->query('SELECT COUNT(*) FROM provisioner_batches')->fetchColumn());
    assertSame([],$module->dispatches);
});

test('rollout wave-position migration preserves progress and never replays a completed wave', function () {
    foreach ([false,true] as $xmlAddedField) {
        [$module,$db,,$macs] = rolloutFixture(26);
        $result = $module->pushToPhones($macs);
        $module->processRolloutQueue();
        assertSame([25],array_map('count',$module->dispatches));
        // Reconstruct the prior unreleased schema. The second variant models
        // Module Admin adding the new XML field before invoking upgrade.php.
        $db->exec('ALTER TABLE provisioner_rollouts RENAME COLUMN wave_cursor TO `cursor`');
        if ($xmlAddedField) $db->exec('ALTER TABLE provisioner_rollouts ADD COLUMN wave_cursor INTEGER NOT NULL DEFAULT 0');
        RolloutSchema::install($db);
        RolloutSchema::install($db);
        assertSame(1,(int)$db->query('SELECT wave_cursor FROM provisioner_rollouts')->fetchColumn());
        $columns = array_column($db->query('PRAGMA table_info(provisioner_rollouts)')->fetchAll(PDO::FETCH_ASSOC),'name');
        assertTrue(!in_array('cursor',$columns,true));
        assertSame(RolloutSchema::REVISION,(int)$db->query('SELECT schema_revision FROM provisioner_rollout_control')->fetchColumn());
        assertSame(1,(int)$module->getBatchReport($result['batch_id'])['rollout']['cursor'],'Keep the report API field compatible');
        $module->processRolloutQueue();
        assertSame([25,1],array_map('count',$module->dispatches));
        assertSame('complete',$module->getBatchReport($result['batch_id'])['rollout']['status']);
    }
    $xml = simplexml_load_file(dirname(__DIR__).'/module.xml');
    assertSame(1,count($xml->xpath('//table[@name="provisioner_rollouts"]/field[@name="wave_cursor"]')));
    assertSame(0,count($xml->xpath('//table[@name="provisioner_rollouts"]/field[@name="cursor"]')));
    foreach (['lib/RolloutSchema.php','lib/RolloutQueue.php','lib/BulkWorkflow.php'] as $file) {
        $tokens = token_get_all(file_get_contents(dirname(__DIR__).'/'.$file));
        foreach ($tokens as $token) {
            if (!is_array($token) || $token[0] !== T_CONSTANT_ENCAPSED_STRING) continue;
            $literal = substr($token[1],1,-1);
            if (!preg_match('/^(?:CREATE|ALTER|UPDATE|SELECT)\b/i',$literal)) continue;
            assertTrue(!preg_match('/(?<![a-zA-Z0-9_`])cursor(?![a-zA-Z0-9_`])/i',$literal),$file.' contains an unquoted reserved CURSOR in SQL');
        }
    }
});

test('mapping changes at finalization revoke verified outcomes and stop the next wave', function () {
    [$module,$db,,$macs] = rolloutFixture(26);
    $db->exec("CREATE TRIGGER change_mapping_at_finalization AFTER UPDATE OF last_push_status ON provisioner_devices WHEN NEW.last_push_status='verified' AND NEW.description='Test phone' BEGIN UPDATE provisioner_devices SET description='Changed during verification',needs_changes=1 WHERE id=NEW.id; END");
    $result = $module->pushToPhones($macs);
    $module->processRolloutQueue();
    $report = $module->getBatchReport($result['batch_id']);
    assertSame('review',$report['rollout']['status']);
    assertSame(25,$report['counts']['superseded']);
    assertSame(0,$report['counts']['verified'] ?? 0);
    assertSame(25,(int)$db->query("SELECT COUNT(*) FROM provisioner_devices WHERE needs_changes=1 AND last_verify_status='failed'")->fetchColumn());
    assertSame(0,(int)$db->query("SELECT COUNT(*) FROM provisioner_devices WHERE last_push_status='verified'")->fetchColumn());
    assertSame(false,$module->processRolloutQueue());
    assertSame([25],array_map('count',$module->dispatches));
});

test('a config revision change before swap finalization supersedes the entire group', function () {
    [$module,$db,$directory,$macs] = rolloutFixture(3);
    $module->setSetting('rollout_wave_size','2');
    $db->exec("UPDATE provisioner_devices SET needs_changes=1,previous_extension=CASE extension WHEN '1000' THEN '1001' ELSE '1000' END WHERE extension IN ('1000','1001')");
    $db->exec("INSERT INTO sip VALUES ('1000','secret','fixture-secret'),('1001','secret','fixture-secret')");
    $db->sqliteCreateFunction('fixture_edit_config',function () use ($directory,$macs) {
        file_put_contents($directory.'/'.$macs[0].'.cfg',"Changed : during finalization\n",FILE_APPEND);
        return 1;
    });
    $db->exec("CREATE TRIGGER edit_config_at_finalization AFTER UPDATE OF last_push_status ON provisioner_devices WHEN NEW.last_push_status='verified' AND NEW.mac_address='".$macs[0]."' BEGIN SELECT fixture_edit_config(); END");
    $result = $module->pushToPhones($macs);
    $module->processRolloutQueue();
    $report = $module->getBatchReport($result['batch_id']);
    assertSame('review',$report['rollout']['status']);
    assertSame(2,$report['counts']['superseded']);
    assertSame(0,$report['counts']['verified'] ?? 0);
    assertSame(2,(int)$db->query('SELECT COUNT(*) FROM provisioner_devices WHERE needs_changes=1 AND previous_extension IS NOT NULL')->fetchColumn());
    assertSame(2,(int)$db->query("SELECT COUNT(*) FROM provisioner_devices WHERE last_verify_status='failed'")->fetchColumn());
    assertSame(false,$module->processRolloutQueue());
    assertSame([2],array_map('count',$module->dispatches));
});

test('rejected finalization never overwrites a newer push attempt', function () {
    [$module,$db,,$macs] = rolloutFixture(1);
    $newJob = str_repeat('b',32);
    $db->exec("CREATE TRIGGER replace_attempt_at_finalization AFTER UPDATE OF last_push_status ON provisioner_devices WHEN NEW.last_push_status='verified' BEGIN UPDATE provisioner_devices SET last_push_job_id='$newJob',needs_changes=1 WHERE id=NEW.id; END");
    $result = $module->pushToPhones($macs);
    $module->processRolloutQueue();
    $report = $module->getBatchReport($result['batch_id']);
    assertSame('review',$report['rollout']['status']);
    assertSame('superseded',$report['items'][0]['outcome']);
    $row = $db->query('SELECT * FROM provisioner_devices')->fetch(PDO::FETCH_ASSOC);
    assertSame($newJob,$row['last_push_job_id']);
    assertSame('verified',$row['last_push_status'],'Do not overwrite newer attempt status');
    assertSame('verified',$row['last_verify_status']);
    assertSame(1,(int)$row['needs_changes']);
});

test('manual verification of a queued phone is rejected without changing phone or report state',function () {
    [$module,$db,,$macs] = rolloutFixture(1);
    $db->exec("UPDATE provisioner_devices SET previous_extension='',last_push_status='downloaded',last_fetch_status='downloaded',last_fetch_ip='10.10.0.1'");
    $queued = $module->pushToPhones($macs);
    $before = $db->query('SELECT * FROM provisioner_devices')->fetchAll(PDO::FETCH_ASSOC);
    $items = $db->query('SELECT * FROM provisioner_batch_items')->fetchAll(PDO::FETCH_ASSOC);
    $blocked = $module->verifyPushStatus($macs[0],['1000'=>['ip'=>$module->ip($macs[0]),'status'=>'Avail','mac'=>$macs[0]]],[]);
    assertSame(false,$blocked['success']); assertSame('rollout_active',$blocked['code']);
    assertSame($queued['batch_id'],$blocked['batch_id']);
    assertSame($before,$db->query('SELECT * FROM provisioner_devices')->fetchAll(PDO::FETCH_ASSOC));
    assertSame($items,$db->query('SELECT * FROM provisioner_batch_items')->fetchAll(PDO::FETCH_ASSOC));
    assertTrue(!$db->inTransaction()); assertSame([],$module->dispatches);
});

test('manual verification during settling cannot supersede automatic rollout finalization',function () {
    [$module,$db,,$macs] = rolloutFixture(2);
    $db->exec("UPDATE provisioner_devices SET previous_extension=''");
    $attempts = 0;
    $module->onSettle = function () use ($module,$db,$macs,&$attempts) {
        $before = $db->query('SELECT * FROM provisioner_devices ORDER BY mac_address')->fetchAll(PDO::FETCH_ASSOC);
        $blocked = $module->verifyPushStatus($macs[0],['1000'=>['ip'=>$module->ip($macs[0]),'status'=>'Avail','mac'=>$macs[0]]],[]);
        assertSame('rollout_active',$blocked['code']); assertTrue(!$blocked['success']);
        assertSame($before,$db->query('SELECT * FROM provisioner_devices ORDER BY mac_address')->fetchAll(PDO::FETCH_ASSOC));
        $attempts++;
    };
    $queued = $module->pushToPhones($macs); $module->processRolloutQueue();
    assertSame(1,$attempts);
    $report = $module->getBatchReport($queued['batch_id']);
    assertSame('complete',$report['rollout']['status']); assertSame(2,$report['counts']['verified']);
    assertSame(0,(int)$db->query("SELECT COUNT(*) FROM provisioner_devices WHERE last_verify_status<>'verified'")->fetchColumn());
    $after = $module->verifyPushStatus($macs[0],['1000'=>['ip'=>$module->ip($macs[0]),'status'=>'Avail','mac'=>$macs[0]]],[]);
    assertTrue($after['success'],'Manual checks remain available after rollout ownership is released');
});

test('manual verification checks swap-partner ownership and standalone active verifiers',function () {
    [$module,$db,,$macs] = rolloutFixture(2);
    $module->pushToPhones([$macs[0]]);
    $db->exec("UPDATE provisioner_devices SET needs_changes=1,previous_extension=CASE extension WHEN '1000' THEN '1001' ELSE '1000' END");
    $blocked = $module->verifyPushStatus($macs[1],[],[]);
    assertSame('rollout_active',$blocked['code']); assertTrue(!$blocked['success']);

    [$module,$db,,$macs] = rolloutFixture(1);
    $job = makeBackgroundJob($module,[$macs[0]=>$module->ip($macs[0])]);
    $blocked = $module->verifyPushStatus($macs[0],[],[]);
    assertSame('verification_active',$blocked['code']); assertTrue(!$blocked['success']);
    assertSame('queued',$db->query('SELECT status FROM provisioner_push_jobs')->fetchColumn());
});

test('manual verification fails closed when rollout readiness cannot be checked',function () {
    [$module,$db,,$macs] = rolloutFixture(1);
    $before = $db->query('SELECT * FROM provisioner_devices')->fetchAll(PDO::FETCH_ASSOC);
    $db->exec('UPDATE provisioner_rollout_control SET schema_revision=0');
    $result = $module->verifyPushStatus($macs[0],[],[]);
    assertTrue(!$result['success']); assertSame('verification_unavailable',$result['code']);
    assertSame($before,$db->query('SELECT * FROM provisioner_devices')->fetchAll(PDO::FETCH_ASSOC));
    assertTrue(!$db->inTransaction());
});
