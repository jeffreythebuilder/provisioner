<?php

class BulkWorkflowFixture extends \FreePBX\modules\Provisioner {
    public function getExtensions() { return [['extension'=>'200'],['extension'=>'201'],['extension'=>'2000']]; }
}

function bulkFixture() {
    [, $db, $directory] = makeFixture();
    return [new BulkWorkflowFixture(new TestFreePBX($db)), $db, $directory];
}

function bulkContext($extra = []) {
    return $extra + ['extensions'=>['200','201','2000'], 'contacts'=>[], 'arp'=>[], 'local_subnets'=>['172.16.138.0/24'], 'observations'=>[]];
}

test('import preview parses exported headers, BOM and quoted newlines without writing mappings', function () {
    [$module,$db] = bulkFixture();
    $csv = "\xEF\xBB\xBF\"Extension\",\"MAC\",\"Brand\",\"Description\",\"Static IP\",\"Subnet Mask\",\"Gateway\"\n200,00:0f:d3:00:07:00,digium,\"Office,\nSecond line\",,,\n";
    $result = $module->previewBulkImport($csv, bulkContext());
    assertTrue($result['can_import']); assertSame(1,count($result['rows']));
    assertSame("Office,\nSecond line",$result['rows'][0]['description']);
    assertSame('new',$result['rows'][0]['action']); assertTrue(!$result['rows'][0]['push_ready']);
    assertSame(0,(int)$db->query('SELECT COUNT(*) FROM provisioner_devices')->fetchColumn());
    assertSame(0,(int)$db->query('SELECT COUNT(*) FROM provisioner_batches')->fetchColumn());
});

test('import preview rejects duplicates, malformed MACs, missing extensions and invalid static networks', function () {
    [$module] = bulkFixture();
    foreach ([
        "200,000fd3000700\n201,00:0f:d3:00:07:00",
        "200,000fd3000700\n200,000fd3000701",
        '200,xx000fd3000700', '999,000fd3000700', '200,000fd3000700,alcatel',
        '200,000fd3000700,digium,,192.168.1.50,255.0.255.0,192.168.1.1',
        '200,000fd3000700,digium,,192.168.1.50,255.255.255.0,192.168.2.1',
        '200,000fd3000700,digium,,,255.255.255.0,192.168.1.1'
    ] as $csv) {
        $result = $module->previewBulkImport($csv, bulkContext());
        assertTrue(!$result['can_import'], $csv); assertTrue($result['summary']['invalid'] > 0);
    }
});

test('import preview allows complete swaps and blocks a collision with an omitted partner', function () {
    [$module,$db] = bulkFixture();
    insertDevice($db,'000fd3000700','200'); insertDevice($db,'3c28a6000701','2000','alcatel');
    $single = $module->previewBulkImport('2000,000fd3000700',bulkContext());
    assertTrue(!$single['can_import']);
    $swap = $module->previewBulkImport("2000,000fd3000700\n200,3c28a6000701",bulkContext());
    assertTrue($swap['can_import']); assertSame(2,$swap['summary']['update']);
    assertTrue(count($swap['rows'][0]['changes']) > 0); assertTrue(count($swap['rows'][0]['warnings']) > 0);
});

test('import readiness requires fresh routed identity and does not prevent saving valid offline mappings', function () {
    [$module] = bulkFixture();
    $csv = '200,000fd3000700,digium,,192.168.188.80,255.255.255.0,192.168.188.254';
    $context = bulkContext();
    $result = $module->previewBulkImport($csv,$context);
    assertTrue($result['can_import']); assertTrue(!$result['rows'][0]['push_ready']);
    assertTrue(strpos($result['rows'][0]['readiness'],'MAC/IP') !== false);
    $context['observations']['000fd3000700'] = ['ip_address'=>'192.168.188.80','sip_port'=>5060,'sip_transport'=>'udp','source'=>'SIP OPTIONS','last_seen'=>date('Y-m-d H:i:s')];
    assertTrue($module->previewBulkImport($csv,$context)['rows'][0]['push_ready']);
    $context['observations']['000fd3000700']['last_seen'] = date('Y-m-d H:i:s',time()-901);
    assertTrue(!$module->previewBulkImport($csv,$context)['rows'][0]['push_ready']);
});

test('reviewed imports save atomically, preserve old IP and static history, and reject stale previews', function () {
    [$module,$db,$directory] = bulkFixture();
    insertDevice($db,'000fd3000700','200');
    $db->exec("UPDATE provisioner_devices SET ip_address='192.168.188.80',static_ip='192.168.188.80',static_mask='255.255.255.0',static_gw='192.168.188.254'");
    file_put_contents($directory.'/000fd3000700.cfg','original');
    $csv = "201,000fd3000700,digium,Updated,192.168.188.81,255.255.255.0,192.168.188.254\n2000,3c28a6000701,alcatel,New,,,";
    assertSame(0,$module->bulkImport($csv)['success']);
    $preview = $module->previewBulkImport($csv);
    assertTrue($preview['can_import']);
    $db->exec("UPDATE provisioner_devices SET description='Concurrent edit'");
    assertSame(0,$module->bulkImport($csv,$preview['revision'])['success']);
    assertSame(1,(int)$db->query('SELECT COUNT(*) FROM provisioner_devices')->fetchColumn());
    $preview = $module->previewBulkImport($csv);
    $db->exec("CREATE TRIGGER reject_second BEFORE INSERT ON provisioner_devices WHEN NEW.mac_address='3c28a6000701' BEGIN SELECT RAISE(ABORT,'injected failure'); END");
    assertSame(0,$module->bulkImport($csv,$preview['revision'])['success']);
    assertSame('200',$db->query('SELECT extension FROM provisioner_devices')->fetchColumn());
    assertSame(0,(int)$db->query('SELECT COUNT(*) FROM provisioner_batches')->fetchColumn());
    $db->exec('DROP TRIGGER reject_second');
    $saved = $module->bulkImport($csv,$preview['revision']);
    assertSame(2,$saved['success']);
    $device = $db->query("SELECT * FROM provisioner_devices WHERE mac_address='000fd3000700'")->fetch(PDO::FETCH_ASSOC);
    assertSame('192.168.188.80',$device['ip_address']); assertSame('200',$device['previous_extension']);
    assertSame('192.168.188.80',$device['previous_static_ip']); assertSame('192.168.188.81',$device['static_ip']);
    assertSame('original',file_get_contents($directory.'/000fd3000700.cfg'));
    $report = $module->getBatchReport($saved['batch_id']);
    assertSame('import',$report['batch']['kind']); assertSame(2,$report['counts']['imported']);
    assertTrue(!$report['items'][0]['mapping_changed']);
});

test('persistent push report retains all stages and receives detached verification by job identity', function () {
    [$module,$db,$directory] = bulkFixture();
    $mac = '000fd3000700'; insertDevice($db,$mac,'200');
    $job = bin2hex(random_bytes(16));
    $result = invokePrivate($module,'runPushBatch','push',[$mac],function () use ($module,$mac,$job) {
        $snapshots = invokePrivate($module,'capturePushJobSnapshots',[$mac],$job);
        invokePrivate($module,'recordBatchStage',$mac,['generation'=>'generated'],$job);
        invokePrivate($module,'setPushTriggered',$mac,'192.168.188.80',$job);
        invokePrivate($module,'setPushDownloaded',$mac,'192.168.188.80',$job);
        invokePrivate($module,'createPushVerificationJob',$job,[$mac=>'192.168.188.80'],$snapshots,[],false);
        return ['success'=>true,'verification_job'=>$job,'verification_queued'=>true];
    });
    $id = $result['batch_id'];
    assertTrue($module->getBatchReport($id)['active']);
    assertTrue(!$module->retryBatch($id)['success']);
    $otherWorker = new BulkWorkflowFixture(new TestFreePBX($db));
    $otherWorker->processPushVerificationJob($job,['200'=>['ip'=>'192.168.188.80','status'=>'Avail','mac'=>$mac]],[],microtime(true));
    $report = $module->getBatchReport($id);
    assertSame('complete',$report['batch']['status']); assertSame('generated',$report['items'][0]['generation']);
    assertSame('accepted',$report['items'][0]['trigger']); assertSame('downloaded',$report['items'][0]['delivery']);
    assertSame('verified',$report['items'][0]['verification']); assertSame([], $report['retry_macs']);
    $db->exec("UPDATE provisioner_devices SET last_push_status='failed',extension='201'");
    $again = $module->getBatchReport($id);
    assertSame('verified',$again['items'][0]['outcome']); assertTrue($again['items'][0]['mapping_changed']);
    assertSame(1,$module->getBatchHistory()['total']);
});

test('batch reports retain failed generation and blocked targets, and retry only eligible unsuccessful mappings', function () {
    [$module,$db,$directory] = bulkFixture();
    $mac = '000fd3000700'; insertDevice($db,$mac,'200');
    file_put_contents($directory.'/'.$mac.'.cfg',digiumConfig('200'));
    $failure = invokePrivate($module,'runPushBatch','apply',[$mac],function () { return ['success'=>false,'error'=>'Generation failed']; });
    $report = $module->getBatchReport($failure['batch_id']);
    assertSame('failed',$report['items'][0]['generation']); assertSame('Generation failed',$report['items'][0]['error']);
    assertSame([$mac],$report['retry_macs']);
    $db->exec("UPDATE provisioner_devices SET ip_address='10.254.253.252'");
    $result = $module->retryBatch($failure['batch_id']);
    assertTrue(!empty($result['batch_id']));
    $retried = $module->getBatchReport($result['batch_id']);
    assertSame($failure['batch_id'],$retried['batch']['parent_id']);
    assertTrue(strpos($retried['items'][0]['error'],'MAC/IP') !== false);
    assertSame('not_started',$retried['items'][0]['trigger']);
    assertSame('Generation failed',$module->getBatchReport($failure['batch_id'])['items'][0]['error']);
    $db->exec("UPDATE provisioner_devices SET extension='201'");
    assertSame([],$module->getBatchReport($result['batch_id'])['retry_macs']);
    assertTrue(!$module->retryBatch($result['batch_id'])['success']);
});

test('batch stage updates do not regress completed verification and expose failure reasons', function () {
    [$module,$db] = bulkFixture(); $mac = '000fd3000700'; insertDevice($db,$mac,'200');
    $job = bin2hex(random_bytes(16));
    $result = invokePrivate($module,'runPushBatch','push',[$mac],function () use ($module,$mac,$job) {
        invokePrivate($module,'recordBatchStage',$mac,['trigger'=>'accepted','delivery'=>'downloaded','verification'=>'verified','outcome'=>'verified'],$job);
        return ['success'=>true];
    });
    assertSame('verified',$module->getBatchReport($result['batch_id'])['items'][0]['outcome']);
    $stalledJob = bin2hex(random_bytes(16));
    $stalled = invokePrivate($module,'runPushBatch','push',[$mac],function () use ($module,$mac,$stalledJob) {
        invokePrivate($module,'recordBatchStage',$mac,['delivery'=>'downloaded','verification'=>'pending','outcome'=>'verifying'],$stalledJob);
        return ['success'=>true];
    });
    $db->exec("UPDATE provisioner_batches SET created_at='2000-01-01 00:00:00' WHERE id='".$stalled['batch_id']."'");
    assertSame('incomplete',$module->getBatchReport($stalled['batch_id'])['items'][0]['outcome']);
    assertTrue(!$module->getBatchReport('../bad')['success']);
    $setting=null;
    foreach (['previewBulkImport','getBatchHistory','getBatchReport','retryBatch'] as $command) assertTrue($module->ajaxRequest($command,$setting));
});

test('report history searches beyond the first page and sorts and paginates the complete result set', function () {
    [$module,$db] = bulkFixture();
    $batch = $db->prepare("INSERT INTO provisioner_batches (id,kind,status,created_at) VALUES (?,'import','complete',?)");
    $item = $db->prepare('INSERT INTO provisioner_batch_items (batch_id,mac,data) VALUES (?,?,?)');
    $ids = [];
    for ($i = 1; $i <= 36; $i++) {
        $id = str_pad(dechex($i),32,'0',STR_PAD_LEFT); $ids[] = $id;
        $batch->execute([$id,sprintf('2026-10-15 10:%02d:00',$i)]);
        for ($phone = 0; $phone < ($i === 30 ? 3 : 1); $phone++) {
            $mac = sprintf('000fd3%06x',$i*10+$phone);
            $item->execute([$id,$mac,json_encode(['mac'=>$mac,'extension'=>$i === 30 ? 'Needle_30' : ($i === 31 ? 'Needlex30' : (string)$i),
                'snapshot'=>[],'outcome'=>$i === 36 ? 'incomplete' : 'imported','target_ip'=>$i === 30 ? '192.0.2.80' : '', 'error'=>$i === 30 ? '100% saved!' : ''])]);
        }
    }
    $db->exec("UPDATE provisioner_batches SET kind='push',status='incomplete' WHERE id='".$ids[35]."'");
    $default = $module->getBatchHistory();
    assertSame(36,$default['total']); assertSame(25,count($default['batches']));
    $page = $module->getBatchHistory(20,10,'','created_at','asc');
    assertSame(array_slice($ids,20,10),array_column($page['batches'],'id'));
    assertSame(3,$module->getBatchHistory(0,10,'','device_count','desc')['batches'][0]['device_count']);
    foreach (['Needle_30','192.0.2.80','100% saved!'] as $search) {
        $found = $module->getBatchHistory(0,10,$search);
        assertSame(1,$found['total'],$search); assertSame(36,$found['total_all']);
        assertSame($ids[29],$found['batches'][0]['id']);
    }
    $mac = sprintf('000fd3%06x',320);
    $formatted = implode(':',str_split($mac,2));
    assertSame($ids[31],$module->getBatchHistory(0,10,$formatted)['batches'][0]['id']);
    assertSame($ids[35],$module->getBatchHistory(0,10,'Needs attention')['batches'][0]['id']);
    assertSame(0,$module->getBatchHistory(0,10,"missing' OR 1=1 --")['total']);
    assertSame($ids[35],$module->getBatchHistory(0,10,'','id; DROP TABLE provisioner_batches','invalid')['batches'][0]['id']);
    $clamped = $module->getBatchHistory(9990,10);
    assertSame(30,$clamped['offset']); assertSame(6,count($clamped['batches']));
    assertSame(36,count($module->getBatchHistory(-10,1000)['batches']));
    assertSame(0,(int)$db->query('SELECT COUNT(*) FROM provisioner_devices')->fetchColumn());
});
