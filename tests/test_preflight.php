<?php

class PreflightFixture extends RolloutEngineFixture {
    public $identityReady=false;
    public $knownIp=true;
    public $local=false;
    public $probeMac=false;
    public $mismatch=false;
    public $callbackMismatch=false;
    public $callbackDate;
    public $noReply=false;
    public $callbackOk=true;
    public $arpStatus='unavailable';
    public $operations=[];
    public $onLookup;
    protected function getPjsipContacts($output=null,$withIdentity=false,$registrarOutput=null) {
        $contacts=parent::getPjsipContacts($output,$withIdentity,$registrarOutput);
        if (!$this->identityReady) foreach ($contacts as &$contact) unset($contact['mac']);
        return $contacts;
    }
    protected function getArpCapableSubnets() { return $this->local?['10.10.0.0/16']:[]; }
    protected function resolveDeviceTargets($performScan=false,$onlyMac=null) {
        $targets=parent::resolveDeviceTargets($performScan,$onlyMac);
        foreach ($targets as $mac=>&$target) {
            $stmt=$this->database->prepare('SELECT ip_address,last_seen,sip_port,sip_transport FROM provisioner_discovery_observations WHERE mac_address=?');$stmt->execute([$mac]);$observed=$stmt->fetch(PDO::FETCH_ASSOC);
            if ($observed && strtotime($observed['last_seen'])>=time()-900) {
                $target['selected_ip']=$observed['ip_address'];$target['selected_port']=(int)$observed['sip_port'];$target['selected_transport']=$observed['sip_transport'];
            } elseif (!$this->identityReady && !$this->local) {
                $target['selected_ip']=null;$target['next_action']='discovery';
                $target['candidates']=$this->knownIp?[['ip'=>$this->ip($mac),'port'=>5070,'transport'=>'tcp','source'=>'stored','valid'=>true]]:[];
            }
        }
        return $targets;
    }
    protected function refreshLocalArpForWave($targets) {
        $this->operations[]=['type'=>'arp','targets'=>$targets];$results=[];
        foreach ($targets as $target)$results[]=['ip'=>$target['ip'],'status'=>$this->arpStatus,'macs'=>[]];
        return $results;
    }
    protected function preflightCallbackAccess() { $this->operations[]=['type'=>'callback'];return ['success'=>$this->callbackOk,'message'=>'Fixture callback access failed.']; }
    protected function runPreflightDiscovery($type,$payload) {
        $this->operations[]=['type'=>$type,'payload'=>$payload];
        if ($this->onLookup) ($this->onLookup)();
        if ($this->noReply) return [$type==='identify'?'identities':'responses'=>[]];
        $mac=$this->database->query('SELECT mac_address FROM provisioner_devices ORDER BY mac_address LIMIT 1')->fetchColumn();
        $ip=$this->ip($mac);
        if ($type==='identify') {
            if ($this->mismatch || $this->callbackMismatch) return ['identities'=>[['ip'=>$ip,'identity'=>null,'code'=>'identity_mismatch']]];
            return ['identities'=>[['ip'=>$ip,'identity'=>['ip'=>$ip,'mac'=>$mac,'http_status'=>404,'brand'=>'digium',
                'sip_port'=>5070,'sip_transport'=>'tcp','source'=>'SIP discovery fetch (HTTP 404)','seen_at'=>microtime(true),'last_seen'=>$this->callbackDate??date('Y-m-d H:i:s')]]]];
        }
        $agent='Asterisk A20 2.10.0.6746';
        if ($this->probeMac || $this->mismatch) $agent.=' '.($this->mismatch?'000fd3ffffff':$mac);
        return ['responses'=>[['ip'=>$ip,'port'=>5070,'transport'=>'tcp','status'=>'SIP/2.0 200 OK',
            'headers'=>['user-agent'=>$agent,'allow'=>'OPTIONS, NOTIFY'],'seen_at'=>time()]]];
    }
    protected function dispatchRolloutPhones($phones,$command) { $this->identityReady=true;return parent::dispatchRolloutPhones($phones,$command); }
}

function preflightFixture() {
    [, $db,$directory,$macs]=rolloutFixture(1);
    $module=new PreflightFixture($db,$directory);$module->setSetting('pbx_ip','172.16.138.13');
    return [$module,$db,$directory,$macs];
}

test('routed push automatically probes identifies and verifies the expected mapped phone',function () {
    [$module,$db,,$macs]=preflightFixture();
    $module->callbackDate='2000-01-01 00:00:00'; // Receiver's formatted date is not authoritative.
    $queued=$module->pushToPhones($macs);assertSame([],$module->operations);
    $module->processRolloutQueue();
    assertSame(['probe','callback','identify'],array_column($module->operations,'type'));
    assertSame(5070,$module->operations[0]['payload']['targets'][0]['port']);
    assertSame('tcp',$module->operations[0]['payload']['targets'][0]['transport']);
    assertSame([$macs[0]],$module->operations[2]['payload']['targets'][0]['expected_macs']);
    assertSame('complete',$module->getBatchReport($queued['batch_id'])['rollout']['status']);
    assertSame(1,count($module->dispatches));
    assertSame('tcp',$db->query('SELECT sip_transport FROM provisioner_discovery_observations')->fetchColumn());
});

test('matching existing registration or fresh discovery avoids additional identification',function () {
    foreach (['registration','discovery','options'] as $kind) {
        [$module,$db,,$macs]=preflightFixture();
        if($kind==='registration')$module->identityReady=true;
        if($kind==='discovery')$db->prepare('INSERT INTO provisioner_discovery_observations (mac_address,ip_address,brand,sip_port,sip_transport,last_seen) VALUES (?,?,?,5070,?,NOW())')->execute([$macs[0],$module->ip($macs[0]),'digium','tcp']);
        if($kind==='options')$module->probeMac=true;
        $queued=$module->pushToPhones($macs);$module->processRolloutQueue();
        assertSame($kind==='options'?['probe']:[],array_column($module->operations,'type'));
        assertSame('complete',$module->getBatchReport($queued['batch_id'])['rollout']['status']);
    }
});

test('unavailable local active ARP never becomes a new push requirement or routed identification',function () {
    foreach(['unavailable','no_response','observed'] as $status) {
        [$module,$db,,$macs]=preflightFixture();$module->local=true;$module->arpStatus=$status;
        $queued=$module->pushToPhones($macs);$module->processRolloutQueue();
        assertSame(['arp'],array_column($module->operations,'type'));
        $report=$module->getBatchReport($queued['batch_id']);
        assertSame('complete',$report['rollout']['status']);assertSame($status,$report['items'][0]['local_arp_refresh']);
        assertSame(1,count($module->dispatches));
    }
});

test('a different routed MAC stops before configuration generation or provisioning',function () {
    foreach (['options','callback'] as $where) {
    [$module,$db,$directory,$macs]=preflightFixture();
    if($where==='options')$module->mismatch=true;else $module->callbackMismatch=true;
    $before=file_get_contents($directory.'/'.$macs[0].'.cfg');
    $queued=$module->pushToPhones($macs);$module->processRolloutQueue();
    $report=$module->getBatchReport($queued['batch_id']);
    assertSame('review',$report['rollout']['status']);assertSame('diagnostics',$report['items'][0]['next_action']);
    assertSame([],$module->dispatches);assertSame($before,file_get_contents($directory.'/'.$macs[0].'.cfg'));
    assertSame(0,(int)$db->query('SELECT COUNT(*) FROM provisioner_discovery_observations')->fetchColumn());
    }
});

test('unknown IP never guesses a range and approved search enables an explicit retry',function () {
    [$module,$db,,$macs]=preflightFixture();$module->knownIp=false;
    $queued=$module->pushToPhones($macs);$module->processRolloutQueue();
    $report=$module->getBatchReport($queued['batch_id']);
    assertSame('search_range',$report['items'][0]['next_action']);assertSame([],$report['retry_macs']);
    assertSame([],$module->operations);assertSame([],$module->dispatches);
    assertTrue($module->saveSettings(['push_search_subnets'=>'10.10.0.0/24'])['success']);
    assertSame($macs,$module->getBatchReport($queued['batch_id'])['retry_macs']);
    $retry=$module->retryBatch($queued['batch_id']);$module->processRolloutQueue();
    assertSame(['scan','callback','identify'],array_column($module->operations,'type'));
    assertSame('10.10.0.0/24',$module->operations[0]['payload']['subnet']);
    assertSame('complete',$module->getBatchReport($retry['batch_id'])['rollout']['status']);
});

test('preflight failures and mapping edits cannot fall through into delivery',function () {
    foreach(['noReply','callback','mapping'] as $kind) {
        [$module,$db,,$macs]=preflightFixture();
        if($kind==='noReply')$module->noReply=true;
        if($kind==='callback')$module->callbackOk=false;
        if($kind==='mapping')$module->onLookup=function()use($db){$db->exec("UPDATE provisioner_devices SET description='Changed during lookup'");};
        $queued=$module->pushToPhones($macs);$module->processRolloutQueue();
        assertSame([],$module->dispatches);assertSame('review',$module->getBatchReport($queued['batch_id'])['rollout']['status']);
    }
    [$module]=preflightFixture();
    foreach(['0.0.0.0/24','8.8.8.0/24','10.0.0.0/16','10.0.1.0/24,10.0.2.0/24,10.0.3.0/24'] as $range)assertTrue(!$module->saveSettings(['push_search_subnets'=>$range])['success']);
});

test('optional ARP installation hook checks availability without changing OS packages or privileges',function () {
    require_once dirname(__DIR__).'/lib/ActiveArp.php';
    ob_start();$available=\FreePBX\modules\Provisioner\ActiveArp::install();$message=ob_get_clean();
    assertSame(\FreePBX\modules\Provisioner\ActiveArp::binary()!==null,$available);
    assertSame('',$message,'Optional tooling checks must not show non-actionable installation notices');
    $source=file_get_contents(dirname(__DIR__).'/lib/ActiveArp.php');
    assertTrue(!preg_match('/\b(?:exec|shell_exec|proc_open|system|passthru)\s*\(/',$source));
});

test('automatic identification receiver rejects a valid but unexpected handset MAC',function () {
    [,,$directory]=makeFixture();
    $store=new \FreePBX\modules\Provisioner\SipDiscovery($directory.'/expected-mac');$nonce=str_repeat('c',32);
    $job=$store->create('identify',['scan_id'=>'automatic-fixture','targets'=>[['ip'=>'192.168.100.118','port'=>5070,'transport'=>'tcp','nonce'=>$nonce,'expected_macs'=>['000fd3000701']]]]);
    $job['status']='running';$store->save($job);
    assertSame(false,$store->receive($job['id'],$nonce,'192.168.100.118','Asterisk A20 2.10.0.6746 000fd3000700','000fd3000700.cfg'));
    assertTrue(!is_file($store->path($job['id'],'receipt-'.$nonce.'.json')));
    $rejected=json_decode(file_get_contents($store->path($job['id'],'callback-'.$nonce.'.json')),true);
    assertSame('identity_mismatch',$rejected['code']);
    assertTrue($store->receive($job['id'],$nonce,'192.168.100.118','Asterisk A20 2.10.0.6746 000fd3000701','000fd3000701.cfg'));
    assertSame('000fd3000701',json_decode(file_get_contents($store->path($job['id'],'receipt-'.$nonce.'.json')),true)['mac']);
});

test('historical missing-identity reports offer explicit automatic lookup without rewriting history',function () {
    [$module,$db,,$macs]=preflightFixture();$module->noReply=true;
    $queued=$module->pushToPhones($macs);$module->processRolloutQueue();
    $statement=$db->prepare('SELECT data FROM provisioner_batch_items WHERE batch_id=?');$statement->execute([$queued['batch_id']]);$data=json_decode($statement->fetchColumn(),true);
    $data['next_action']='discovery';$data['error']='Original missing identity failure.';
    $saved=json_encode($data);$db->prepare('UPDATE provisioner_batch_items SET data=? WHERE batch_id=?')->execute([$saved,$queued['batch_id']]);
    $report=$module->getBatchReport($queued['batch_id']);
    assertTrue($report['items'][0]['can_auto_find']);assertSame($macs,$report['retry_macs']);
    $statement->execute([$queued['batch_id']]);assertSame($saved,$statement->fetchColumn());
    $module->noReply=false;
    $retry=$module->retryBatch($queued['batch_id']);$module->processRolloutQueue();
    assertSame('complete',$module->getBatchReport($retry['batch_id'])['rollout']['status']);
});

test('registered SIP port and transport are retained for target resolution and automatic lookup',function () {
    [$module]=makeFixture();
    $hash=str_pad('abcdef1234',32,'0');
    $summary='Contact: 201/sip:201@192.168.188.80:5091;transport=TLS abcdef1234 Avail 1.0';
    $registrar='/registrar/contact/201;@'.$hash.': '.json_encode(['endpoint'=>'201','uri'=>'sip:201@192.168.188.80:5091;transport=TLS','user_agent'=>'Asterisk A20 2.10.0.6746 000fd3cf406b']);
    $contacts=invokePrivate($module,'getPjsipContacts',$summary,true,$registrar);
    assertSame(5091,$contacts['201']['port']);assertSame('tls',$contacts['201']['transport']);
    $target=invokePrivate($module,'resolveDeviceTarget',['mac_address'=>'000fd3cf406b','extension'=>'201'],$contacts,[],[],false,[],[]);
    assertSame(5091,$target['selected_port']);assertSame('tls',$target['selected_transport']);
});

test('settings explain wave size pause and search scope without non-actionable ARP notices',function () {
    [$module]=makeFixture();
    $view=file_get_contents(dirname(__DIR__).'/views/main.php');
    $js=file_get_contents(dirname(__DIR__).'/assets/js/provisioner.js');
    foreach(['Phones per wave','300 phones with 25 per wave creates 12 groups','Wait after config downloads (seconds)',
        'not the overall verification timeout','Networks allowed for automatic phone search','Leave blank to try known IPs only',
        'This does not change phone IP settings','aria-describedby="settingsRolloutWaveHelp"','Search format, limits and safety'] as $text)assertTrue(strpos($view,$text)!==false,$text);
    assertTrue(strpos($view,'activeArpAvailability')===false);assertTrue(strpos($js,'activeArpAvailability')===false);
    assertTrue(!array_key_exists('active_arp_tool_present',$module->getSettings()));
    assertTrue(strpos($view,'<details id="settingsPushAdvanced" class="bulk-report-recovery-help">')!==false);
    $advanced=substr($view,strpos($view,'<details id="settingsPushAdvanced"'),strpos($view,'id="settingsDhcpLeasesRow"')-strpos($view,'<details id="settingsPushAdvanced"'));
    foreach(['settingsRolloutWaveSize','settingsRolloutSettleSeconds','settingsPushSearchSubnets'] as $field)assertTrue(strpos($advanced,'id="'.$field.'"')!==false);
    assertTrue(strpos($advanced,'id="settingsScanSubnets"')===false);
    assertTrue(strpos($view,'name="rollout_wave_size" min="1" max="30" value="25"')!==false);
    assertTrue(strpos($view,'name="rollout_settle_seconds" min="5" max="120" value="20"')!==false);
});
