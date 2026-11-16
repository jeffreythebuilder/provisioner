<?php
namespace FreePBX\modules\Provisioner;

trait PushPreflight {
    private $preflightArpMap = [];
    private $preflightIdentityErrors = [];
    private $preflightCallbackFailure = false;
    private $preflightSearchLimited = false;

    private function isAutomaticDiscoveryIp($ip) {
        return $this->isPrivateIp($ip) && strncmp($ip,'127.',4)!==0;
    }

    private function pushSearchRanges() {
        $ranges = $this->normalizeScanSubnets($this->getSetting('push_search_subnets') ?: '',32);
        if ($ranges === null || count($ranges) > 2) throw new \RuntimeException('Configure up to two approved automatic search ranges.');
        foreach ($ranges as $range) if ((int)explode('/',$range)[1] < 22 || !$this->isAutomaticDiscoveryIp(explode('/',$range)[0])) {
            throw new \RuntimeException('Automatic search ranges must be private IPv4 /22 or smaller ranges.');
        }
        return $ranges;
    }

    private function preflightStage($macs,$phase,$message,$extra=[]) {
        if ($this->rolloutQueueContext) $this->rolloutQueueContext->heartbeat(true);
        foreach ($macs as $mac) $this->recordBatchStage($mac,array_merge([
            'identity_phase'=>$phase,'identity_message'=>$message,'identity_at'=>date('Y-m-d H:i:s')],$extra));
    }

    protected function runPreflightCommand($command,$seconds) {
        $pipes=[];
        $process=proc_open('exec '.$command,[0=>['pipe','r'],1=>['pipe','w'],2=>['pipe','w']],$pipes);
        if (!is_resource($process)) throw new \RuntimeException('Could not start the identity preflight helper.');
        fclose($pipes[0]); stream_set_blocking($pipes[1],false); stream_set_blocking($pipes[2],false);
        $output=''; $started=microtime(true);
        try {
            do {
                if ($this->rolloutQueueContext) $this->rolloutQueueContext->heartbeat();
                $output.=stream_get_contents($pipes[1]); stream_get_contents($pipes[2]);
                if (strlen($output)>2097152 || microtime(true)-$started>$seconds) throw new \RuntimeException('Identity preflight exceeded its bounded execution window. No config was sent.');
                $status=proc_get_status($process);
                if (!$status['running']) {
                    $output.=stream_get_contents($pipes[1]);
                    if ($status['exitcode']!==0) throw new \RuntimeException('Identity preflight helper failed. Check runtime dependencies and connectivity.');
                    break;
                }
                usleep(100000);
            } while (true);
        } finally {
            if (proc_get_status($process)['running']) proc_terminate($process,9);
            fclose($pipes[1]); fclose($pipes[2]); proc_close($process);
        }
        return $output;
    }

    protected function runPreflightDiscovery($type,$payload) {
        $python=$this->findPython3Binary();
        if (!$python) throw new \RuntimeException('Python 3 is required for automatic handset identification.');
        $store=new SipDiscovery();
        $job=$store->create($type,$payload);
        $job['status']='running'; $store->save($job);
        try {
            $this->runPreflightCommand(escapeshellarg($python).' '.escapeshellarg(__DIR__.'/../discover_sip.py').' '.escapeshellarg($store->path($job['id'])),$type==='scan'?180:45);
            $progress=json_decode(file_get_contents($store->path($job['id'],'progress.json')),true,512,JSON_THROW_ON_ERROR);
            if (!is_array($progress) || ($progress['done']??-1)!==($progress['total']??null)) throw new \RuntimeException('Identity preflight returned incomplete progress.');
            $job['status']='complete'; $job['result']=$progress; $store->save($job);
            return $progress;
        } catch (\Throwable $error) {
            $job['status']='failed'; $job['error']=$error->getMessage(); $store->save($job); throw $error;
        }
    }

    protected function refreshLocalArpForWave($targets) {
        if (!$targets) return [];
        $python=$this->findPython3Binary();
        if (!$python) return [];
        $directory=(new SipDiscovery())->directory();
        if (!is_dir($directory) && !@mkdir($directory,0700,true)) return [];
        $uid=function_exists('posix_geteuid')?posix_geteuid():getmyuid();
        if (is_link($directory) || fileowner($directory)!==$uid) return [];
        @chmod($directory,0700);
        $file=tempnam($directory,'arp-');
        if ($file===false) return [];
        try {
            chmod($file,0600); file_put_contents($file,json_encode(array_values($targets),JSON_THROW_ON_ERROR));
            $output=$this->runPreflightCommand(escapeshellarg($python).' '.escapeshellarg(__DIR__.'/../active_arp.py').' '.escapeshellarg($file),25);
            return json_decode($output,true,512,JSON_THROW_ON_ERROR)['results']??[];
        } finally { @unlink($file); }
    }

    private function preflightEndpoints($candidates) {
        $endpoints=[];
        foreach ($candidates as $candidate) {
            $ip=$candidate['ip'];
            $variants=[[(int)($candidate['port']??5060),$candidate['transport']??'udp'],[5060,'udp'],[5160,'udp'],[5060,'tcp'],[5160,'tcp'],[5061,'tls']];
            foreach ($variants as [$port,$transport]) if ($port>=1 && $port<=65535 && in_array($transport,['udp','tcp','tls'],true)) {
                $endpoints[$ip.':'.$port.'/'.$transport]=['ip'=>$ip,'port'=>$port,'transport'=>$transport];
            }
        }
        return array_values($endpoints);
    }

    protected function preflightCallbackAccess() { return $this->testDiscoveryCallback(); }

    private function collectPreflightIdentities($devices,&$pending,&$conflicts,$owners,$source,&$callbackChecked) {
        $unknown=[];
        foreach ($devices as $row) {
            $mac=strtolower($row['mac']??''); $ip=$row['ip']??'';
            if (!$this->isAutomaticDiscoveryIp($ip)) continue;
            if ($mac!=='') {
                if (isset($pending[$mac]) && empty($row['identity_conflict']) && !empty($row['seen_now']) && SipDiscovery::macBrand($mac)===($row['brand']??'')) {
                    $this->preflightStage([$mac],'identity_confirmed','Handset MAC/IP matched; ready to send configuration.');
                    $this->saveDiscoveryObservations([$row]); unset($pending[$mac]);
                }
                foreach ($owners[$ip]??[] as $expected) if ($expected!==$mac && isset($pending[$expected])) {
                    $conflicts[$expected]='Expected MAC '.$expected.' but '.$ip.' identified as '.$mac.'. Review the mapping; no config was sent.';
                }
            } elseif (!empty($row['can_identify'])) $unknown[]=$row;
        }
        if (!$pending || !$unknown) return;
        if (!$callbackChecked) {
            $test=$this->preflightCallbackAccess();
            if (empty($test['success'])) {
                $this->preflightCallbackFailure=true;
                throw new \RuntimeException(($test['message']??'PBX callback access failed.').' '.($test['next_step']??''));
            }
            $callbackChecked=true;
        }
        // Bounded search enrichment: very broad anonymous inventories require a
        // narrower approved range, rather than an unbounded NOTIFY fan-out.
        if (count($unknown)>128) $this->preflightSearchLimited=true;
        foreach (array_chunk(array_slice($unknown,0,128),32) as $chunk) {
            $targets=[];
            foreach ($chunk as $row) {
                $allowed=$owners[$row['ip']]??array_keys($pending);
                $targets[]=['ip'=>$row['ip'],'port'=>(int)$row['sip_port'],'transport'=>$row['sip_transport'],
                    'nonce'=>bin2hex(random_bytes(16)),'expected_macs'=>array_values($allowed)];
            }
            $this->preflightStage(array_keys($pending),'identifying','Identifying SIP responders; configuration delivery has not started.');
            $port=(int)($this->getSetting('prov_port')?:2001);
            if ($port<1 || $port>65535) throw new \RuntimeException('Configure a valid provisioning HTTP port.');
            $progress=$this->runPreflightDiscovery('identify',['source_ip'=>$source,'targets'=>$targets,
                'scan_id'=>'push-'.bin2hex(random_bytes(8)),
                'receiver_url'=>'http://'.$source.':'.$port.'/admin/modules/provisioner/discovery_receiver.php']);
            foreach ($progress['identities']??[] as $received) {
                $identity=$received['identity']??null; $ip=$received['ip']??'';
                if (!$identity) {
                    if (($received['code']??'')==='identity_mismatch') foreach ($owners[$ip]??[] as $expected) {
                        if (isset($pending[$expected])) $conflicts[$expected]='The handset at '.$ip.' reported a different MAC. Review the mapped identity; no config was sent.';
                    }
                    foreach ($owners[$ip]??[] as $expected) if (isset($pending[$expected])) {
                        $this->preflightIdentityErrors[$expected]=['code'=>$received['code']??'identity_failed',
                            'message'=>($received['error']??'Handset identification did not complete.').' '.($received['next_step']??'Review the SIP endpoint and callback access.')];
                    }
                    continue;
                }
                $target=null; foreach ($targets as $entry) if ($entry['ip']===($identity['ip']??'') && $entry['ip']===$ip) { $target=$entry; break; }
                $mac=strtolower($identity['mac']??'');
                if (!$target || ($identity['http_status']??null)!==404 || !in_array($mac,$target['expected_macs'],true) || !isset($pending[$mac])) continue;
                $identity['mac']=$mac; $identity['seen_now']=true;
                // The receiver does not bootstrap FreePBX timezone settings.
                // Normalize its epoch timestamp in the worker, as manual
                // discovery does, rather than trusting a formatted PHP date.
                $identity['last_seen']=date('Y-m-d H:i:s',(int)($identity['seen_at']??time()));
                $this->preflightStage([$mac],'identity_confirmed','Handset MAC/IP matched; ready to send configuration.');
                $this->saveDiscoveryObservations([$identity]); unset($pending[$mac]);
            }
            if (!$pending) break;
        }
    }

    protected function preflightRolloutWave($id,$wave,$queue) {
        $this->preflightIdentityErrors=[];$this->preflightCallbackFailure=false;$this->preflightSearchLimited=false;
        $this->preflightStage($wave,'checking_identity','Checking existing MAC/IP evidence before delivery.');
        $targets=$this->resolveDeviceTargets(false);
        $local=$this->getArpCapableSubnets(); $contacts=$this->getPjsipContacts(null,true); $observations=$this->getDiscoveryObservations();
        $source=$this->getSetting('pbx_ip')?:$this->getLocalIP();
        $pending=[]; $conflicts=[]; $localTargets=[]; $owners=[]; $candidateEndpoints=[]; $callbackChecked=false;
        foreach ($wave as $mac) {
            $target=$targets[$mac]??[];
            if (($target['next_action']??'')==='mapping' || !empty($target['conflict']) || ($target['next_action']??'')==='diagnostics') {
                $message=$target['blocked_reason']??$target['conflict_message']??'Review the MAC mapping and identity conflict before pushing.';
                $this->preflightStage([$mac],'identity_blocked',$message,['outcome'=>'incomplete','error'=>$message,'next_action'=>($target['next_action']??'diagnostics')]);
                $conflicts[$mac]=$message; continue;
            }
            $ip=$target['selected_ip']??null;
            if ($ip && $this->ipInSubnets($ip,$local)) { $localTargets[$mac]=['ip'=>$ip]; continue; }
            $error=$ip?$this->getPushIdentityError($ip,$mac,$contacts,$observations,$local):null;
            if ($ip && !$error) { $this->preflightStage([$mac],'identity_confirmed','Existing matching MAC/IP evidence is sufficient; identification skipped.'); continue; }
            if ($error && strpos($error,'different handset MAC')!==false) { $conflicts[$mac]=$error; continue; }
            $candidates=[];
            foreach ($target['candidates']??[] as $candidate) {
                $candidateIp=$candidate['ip']??'';
                if (!$this->isAutomaticDiscoveryIp($candidateIp) || $candidateIp===$source) continue;
                if ($this->ipInSubnets($candidateIp,$local)) { $localTargets[$mac]=['ip'=>$candidateIp]; break; }
                $candidates[$candidateIp]=['ip'=>$candidateIp,'port'=>$candidate['port']??$target['selected_port']??5060,'transport'=>$candidate['transport']??$target['selected_transport']??'udp'];
            }
            if (isset($localTargets[$mac])) continue;
            if ($ip && $this->isAutomaticDiscoveryIp($ip) && $ip!==$source) $candidates=[$ip=>['ip'=>$ip,'port'=>$target['selected_port']??5060,'transport'=>$target['selected_transport']??'udp']]+$candidates;
            $pending[$mac]=array_slice(array_values($candidates),0,2);
            foreach ($pending[$mac] as $candidate) { $owners[$candidate['ip']][]=$mac; $candidateEndpoints[]=$candidate; }
        }
        if ($localTargets) {
            try { $arp=$this->refreshLocalArpForWave($localTargets); } catch (\Throwable $error) { $arp=[]; }
            $byIp=array_column($arp,null,'ip');
            foreach ($localTargets as $mac=>$target) {
                $result=$byIp[$target['ip']]??['status'=>'unavailable','macs'=>[]];
                foreach ($result['macs'] as $observed) if (preg_match('/^[a-f0-9]{12}$/D',$observed) && count($result['macs'])===1) $this->preflightArpMap[$observed]=$target['ip'];
                $this->preflightStage([$mac],'local_lookup','Best-effort active ARP refresh: '.$result['status'].'. Existing local push rules are unchanged.',
                    ['local_arp_refresh'=>$result['status'],'local_arp_macs'=>$result['macs']]);
            }
        }
        foreach ($conflicts as $mac=>$message) $this->preflightStage([$mac],'identity_blocked',$message,['outcome'=>'incomplete','error'=>$message,'next_action'=>($targets[$mac]['next_action']??'')==='mapping'?'mapping':'diagnostics']);
        if ($conflicts) return false;
        if (!$pending) return true;
        if (!$this->isAutomaticDiscoveryIp($source)) throw new \RuntimeException('Configure the private PBX source address before automatic identification.');
        $this->preflightStage(array_keys($pending),'finding','Finding the selected handset(s) using known IPs and SIP endpoints. No config has been sent.');
        $ranges=[];
        try {
            foreach (array_chunk($this->preflightEndpoints($candidateEndpoints),256) as $chunk) {
                $progress=$this->runPreflightDiscovery('probe',['source_ip'=>$source,'targets'=>$chunk]);
                $devices=array_values(array_filter($this->formatSipDiscoveryResponses($progress['responses']??[]),function($row)use($owners){return isset($owners[$row['ip']??'']);}));
                $this->collectPreflightIdentities($devices,$pending,$conflicts,$owners,$source,$callbackChecked);
                if (!$pending || $conflicts) break;
            }
            if ($pending && !$conflicts) $ranges=$this->pushSearchRanges();
            if ($pending && !$conflicts) foreach ($ranges as $range) {
                $this->preflightStage(array_keys($pending),'finding','Searching approved range '.$range.' for matching mapped handsets.');
                $port=(int)($this->getSetting('sip_port')?:5060);
                $progress=$this->runPreflightDiscovery('scan',['source_ip'=>$source,'subnet'=>$range,
                    'ports'=>array_values(array_unique([5060,5160,$port>=1&&$port<=65535?$port:5060])),'tls_ports'=>[5061]]);
                $devices=array_values(array_filter($this->formatSipDiscoveryResponses($progress['responses']??[]),function($row)use($range){return $this->ipInSubnet($row['ip']??'',$range);}));
                $this->collectPreflightIdentities($devices,$pending,$conflicts,[],$source,$callbackChecked);
                if (!$pending || $conflicts) break;
            }
        } catch (\Throwable $error) {
            foreach (array_keys($pending) as $mac) $this->preflightStage([$mac],'identity_blocked',$error->getMessage(),['outcome'=>'incomplete','error'=>$error->getMessage(),'next_action'=>$this->preflightCallbackFailure?'diagnostics':'find']);
            return false;
        }
        foreach ($pending as $mac=>$candidates) {
            $needsRange=!$candidates && !$ranges;
            $message=$conflicts[$mac]??$this->preflightIdentityErrors[$mac]['message']??(!$needsRange ? 'The handset was not found at the known IPs or approved search ranges. Check connectivity or narrow the search range, then try finding again.'
                : 'No known phone IP was available. Set an approved automatic search range in PBX Settings, then try finding again.');
            if ($this->preflightSearchLimited) $message.=' Identification is bounded to 128 anonymous responders per range; narrow the approved range.';
            $code=$this->preflightIdentityErrors[$mac]['code']??null;
            $review=isset($conflicts[$mac]) || in_array($code,['identity_invalid','identity_unsupported','callback_timeout','sip_rejected'],true);
            $this->preflightStage([$mac],$review?'identity_blocked':($needsRange?'search_range_needed':'phone_not_found'),$message,
                ['outcome'=>'incomplete','error'=>$message,'identity_error_code'=>$code,'next_action'=>$review?'diagnostics':($needsRange?'search_range':'find')]);
        }
        return !$pending && !$conflicts;
    }
}
