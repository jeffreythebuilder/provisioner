<?php

namespace FreePBX\modules\Provisioner;

trait BulkWorkflow {
    private $activeBatchId;

    private function ensureBatchTables() {
        $this->db->exec("CREATE TABLE IF NOT EXISTS provisioner_batches (id VARCHAR(32) PRIMARY KEY, kind VARCHAR(20) NOT NULL, parent_id VARCHAR(32), status VARCHAR(20) NOT NULL, created_at DATETIME NOT NULL, finished_at DATETIME)");
        $this->db->exec("CREATE TABLE IF NOT EXISTS provisioner_batch_items (batch_id VARCHAR(32) NOT NULL, mac VARCHAR(12) NOT NULL, job_id VARCHAR(32), data TEXT NOT NULL, PRIMARY KEY (batch_id,mac))");
    }

    private function mappingRevision($devices, $csv) {
        $fields = ['id','mac_address','extension','description','brand','static_ip','static_mask','static_gw','previous_extension','previous_description','previous_static_ip','previous_static_mask','previous_static_gw','custom_config','needs_changes','updated_at'];
        $snapshot = [];
        foreach ($devices as $device) $snapshot[] = array_intersect_key($device, array_flip($fields));
        return hash('sha256', $csv . json_encode($snapshot));
    }

    public function previewBulkImport($csv, $context = null) {
        if (!is_string($csv) || strlen($csv) > 1048576 || !preg_match('//u',$csv)) return ['success'=>false, 'error'=>'CSV must be UTF-8 text of at most 1 MB.'];
        $csv = preg_replace('/^\xEF\xBB\xBF/', '', $csv);
        $devices = $context['devices'] ?? $this->db->query('SELECT * FROM provisioner_devices ORDER BY mac_address')->fetchAll(\PDO::FETCH_ASSOC);
        $existing = array_column($devices, null, 'mac_address');
        $extensions = $context['extensions'] ?? array_column($this->getExtensions(), 'extension');
        $extensions = array_map('strval', $extensions);
        $contacts = $context['contacts'] ?? $this->getPjsipContacts(null, true);
        $arp = $context['arp'] ?? $this->getCurrentArpMap();
        $local = $context['local_subnets'] ?? $this->getArpCapableSubnets();
        $observations = $context['observations'] ?? $this->getDiscoveryObservations();
        $stream = fopen('php://temp', 'r+'); fwrite($stream, $csv); rewind($stream);
        $rows = []; $record = 0; $macRows = []; $extRows = [];
        while (($parts = fgetcsv($stream)) !== false) {
            $record++;
            if (count($parts) === 1 && trim((string)$parts[0]) === '') continue;
            $parts = array_map(function ($value) { return trim((string)$value); }, $parts);
            if (!$rows && strtolower($parts[0]) === 'extension') continue;
            if (count($rows) >= 2000) { fclose($stream); return ['success'=>false, 'error'=>'Import is limited to 2,000 rows.']; }
            $macText = $parts[1] ?? '';
            $mac = strtolower(str_replace([':', '-'], '', $macText));
            $old = $existing[$mac] ?? null;
            $brand = strtolower($parts[2] ?? '');
            if ($brand === '') $brand = $old['brand'] ?? $this->getBrandByMac($mac) ?? '';
            $row = ['row'=>$record, 'mac'=>$mac, 'extension'=>$parts[0], 'brand'=>$brand, 'description'=>$parts[3] ?? '',
                'static_ip'=>$parts[4] ?? '', 'static_mask'=>$parts[5] ?? '', 'static_gw'=>$parts[6] ?? '',
                'action'=>$old ? 'update' : 'new', 'old_extension'=>$old['extension'] ?? '', 'changes'=>[], 'errors'=>[], 'warnings'=>[]];
            if (count($parts) < 2 || count($parts) > 7) $row['errors'][] = 'Expected 2 to 7 CSV columns.';
            if (!preg_match('/^(?:[a-f0-9]{12}|(?:[a-f0-9]{2}:){5}[a-f0-9]{2}|(?:[a-f0-9]{2}-){5}[a-f0-9]{2})$/i', $macText)
                || $mac === '000000000000' || $mac === 'ffffffffffff' || (strlen($mac) === 12 && (hexdec(substr($mac, 0, 2)) & 1))) $row['errors'][] = 'Invalid handset MAC.';
            if (!preg_match('/^[a-zA-Z0-9_-]{1,20}$/', $row['extension'])) $row['errors'][] = 'Invalid extension.';
            elseif (!in_array($row['extension'], $extensions, true)) $row['errors'][] = 'Extension does not exist on this PBX.';
            if (!in_array($brand, ['digium','alcatel'], true)) $row['errors'][] = 'Supported brands are digium and alcatel.';
            $detected = $this->getSupportedDiscoveryBrand($mac);
            if ($detected && $detected !== $brand) $row['errors'][] = 'Brand conflicts with the MAC vendor.';
            if (strlen($row['description']) > 255) $row['errors'][] = 'Description exceeds 255 bytes.';
            foreach (['static_ip','static_gw'] as $field) if ($row[$field] !== '' && !$this->isPrivateIp($row[$field])) $row['errors'][] = "$field must be a private IPv4 address.";
            if ($row['static_ip'] !== '') {
                $mask = $row['static_mask'];
                $bits = filter_var($mask, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4) ? sprintf('%032b', ip2long($mask)) : '';
                if (!preg_match('/^1+0+$/', $bits)) $row['errors'][] = 'Static IP requires a contiguous subnet mask.';
                elseif ($row['static_gw'] !== '' && $this->isPrivateIp($row['static_gw'])
                    && (ip2long($row['static_gw']) & ip2long($mask)) !== (ip2long($row['static_ip']) & ip2long($mask))) $row['errors'][] = 'Gateway is outside the static subnet.';
                if ($row['static_gw'] === '') $row['warnings'][] = 'No static gateway specified.';
            } elseif ($row['static_mask'] !== '' || $row['static_gw'] !== '') $row['errors'][] = 'Subnet mask/gateway require a static IP.';
            foreach (['extension','brand','description','static_ip','static_mask','static_gw'] as $field) {
                if ($old && (string)($old[$field] ?? '') !== $row[$field]) $row['changes'][] = $field . ': ' . (($old[$field] ?? '') ?: '(empty)') . ' -> ' . ($row[$field] ?: '(empty)');
            }
            if ($old && !$row['changes']) $row['action'] = 'unchanged';
            if (!empty($old['custom_config'])) $row['warnings'][] = 'Custom config: extension changes regenerate it; otherwise it is preserved.';
            $index = count($rows); $macRows[$mac][] = $index; $extRows[$row['extension']][] = $index;
            $rows[] = $row;
        }
        fclose($stream);
        foreach ($macRows as $indices) if (count($indices) > 1) foreach ($indices as $index) $rows[$index]['errors'][] = 'Duplicate MAC in CSV.';
        foreach ($extRows as $indices) if (count($indices) > 1) foreach ($indices as $index) $rows[$index]['errors'][] = 'Duplicate extension in CSV.';
        $desired = array_column($devices, 'extension', 'mac_address');
        foreach ($rows as $row) $desired[$row['mac']] = $row['extension'];
        $owners = [];
        foreach ($desired as $mac => $ext) if ($ext !== '') $owners[(string)$ext][] = $mac;
        $summary = ['new'=>0, 'update'=>0, 'unchanged'=>0, 'invalid'=>0, 'ready'=>0, 'blocked'=>0];
        foreach ($rows as &$row) {
            if (count($owners[$row['extension']] ?? []) > 1) $row['errors'][] = 'Extension remains assigned to another MAC. Include all swap partners.';
            foreach ($devices as $other) {
                if ($other['mac_address'] !== $row['mac'] && $other['extension'] === $row['extension'] && isset($macRows[$other['mac_address']])) {
                    $row['warnings'][] = 'Reassignment/swap with ' . strtoupper($other['mac_address']) . '; push partners together.';
                }
            }
            $old = $existing[$row['mac']] ?? [];
            $candidate = array_replace($old, array_intersect_key($row, array_flip(['extension','brand','description','static_ip','static_mask','static_gw'])), ['mac_address'=>$row['mac'], 'needs_changes'=>1]);
            if ($old && $row['old_extension'] !== $row['extension']) $candidate['previous_extension'] = $old['previous_extension'] ?? $row['old_extension'];
            $target = $this->resolveDeviceTarget($candidate, $contacts, $arp, [], false, $local, $observations);
            $row['target_ip'] = $target['selected_ip'];
            $row['readiness'] = $row['errors'] ? 'Invalid row' : ($target['selected_ip'] ? 'Ready (rechecked at push)' : ($target['conflict_message'] ?? $target['blocked_reason'] ?? 'No current target. Scan/identify this phone.'));
            $row['push_ready'] = !$row['errors'] && !empty($target['selected_ip']);
            $row['valid'] = !$row['errors'];
            $summary[$row['action']]++;
            if (!$row['valid']) $summary['invalid']++;
            $summary[$row['push_ready'] ? 'ready' : 'blocked']++;
        }
        unset($row);
        return ['success'=>true, 'can_import'=>count($rows) > 0 && !$summary['invalid'], 'rows'=>$rows, 'summary'=>$summary,
            'revision'=>$this->mappingRevision($devices, $csv)];
    }

    public function commitBulkImport($csv, $revision) {
        if (!is_string($revision) || !preg_match('/^[a-f0-9]{64}$/', $revision)) return ['success'=>0, 'error'=>'Preview this CSV before importing.'];
        $this->db->beginTransaction();
        $oldMode = $this->db->getAttribute(\PDO::ATTR_ERRMODE);
        $this->db->setAttribute(\PDO::ATTR_ERRMODE, \PDO::ERRMODE_EXCEPTION);
        try {
            $lock = $this->db->getAttribute(\PDO::ATTR_DRIVER_NAME) === 'sqlite' ? '' : ' FOR UPDATE';
            $devices = $this->db->query('SELECT * FROM provisioner_devices ORDER BY mac_address' . $lock)->fetchAll(\PDO::FETCH_ASSOC);
            $preview = $this->previewBulkImport($csv, ['devices'=>$devices]);
            if (empty($preview['can_import'])) throw new \RuntimeException('CSV validation failed. Preview again and correct the invalid rows.');
            if (!hash_equals($preview['revision'], $revision)) throw new \RuntimeException('CSV or mappings changed since preview. Preview again before importing.');
            $existing = array_column($devices, null, 'mac_address');
            $batch = $this->createWorkflowBatch('import', $preview['rows']);
            foreach ($preview['rows'] as $row) {
                if ($row['action'] === 'unchanged') continue;
                $args = [$row['mac'], $row['extension'], $row['description'], $row['brand'], '', $row['static_ip'] ?: null, $row['static_mask'] ?: null, $row['static_gw'] ?: null];
                if (isset($existing[$row['mac']])) $result = $this->updateDevice($existing[$row['mac']]['id'], ...$args);
                else {
                    // Avoid addDevice's legacy schema-repair fallback: DDL would
                    // implicitly commit a partially imported batch on MySQL.
                    $insert = $this->db->prepare('INSERT INTO provisioner_devices (mac_address,extension,description,brand,static_ip,static_mask,static_gw,needs_changes,created_at,updated_at) VALUES (?,?,?,?,?,?,?,1,NOW(),NOW())');
                    $ok = $insert->execute([$row['mac'],$row['extension'],$row['description'],$row['brand'],$row['static_ip'] ?: null,$row['static_mask'] ?: null,$row['static_gw'] ?: null]);
                    $result = ['success'=>$ok];
                    if ($ok) $this->addLog('device', 'Added device MAC:' . $row['mac'] . ' EXT:' . $row['extension'] . ' BRAND:' . $row['brand'], 'success');
                }
                if (empty($result['success'])) throw new \RuntimeException('Row ' . $row['row'] . ': ' . ($result['error'] ?? 'Save failed'));
            }
            foreach ($preview['rows'] as $row) {
                $stmt = $this->db->prepare('SELECT * FROM provisioner_devices WHERE mac_address=?'); $stmt->execute([$row['mac']]);
                $saved = $stmt->fetch(\PDO::FETCH_ASSOC);
                $this->patchBatchItem($batch, $row['mac'], ['import'=>'saved', 'snapshot'=>$this->batchMappingSnapshot($saved), 'outcome'=>'imported']);
            }
            $this->db->prepare("UPDATE provisioner_batches SET status='complete',finished_at=NOW() WHERE id=?")->execute([$batch]);
            $this->addLog('device', 'Bulk imported ' . count($preview['rows']) . ' devices; batch:' . $batch, 'success');
            $this->db->commit();
            return ['success'=>count($preview['rows']), 'errors'=>[], 'batch_id'=>$batch, 'macs'=>array_column($preview['rows'], 'mac')];
        } catch (\Throwable $error) {
            if ($this->db->inTransaction()) $this->db->rollBack();
            return ['success'=>0, 'error'=>$error->getMessage()];
        } finally { $this->db->setAttribute(\PDO::ATTR_ERRMODE, $oldMode); }
    }

    private function batchMappingSnapshot($device) {
        return array_intersect_key($device, array_flip(['id','mac_address','extension','description','brand','static_ip','static_mask','static_gw','custom_config','updated_at']));
    }

    private function createWorkflowBatch($kind, $rows, $parent = null) {
        $id = bin2hex(random_bytes(16));
        if (!$this->db->prepare("INSERT INTO provisioner_batches (id,kind,parent_id,status,created_at) VALUES (?,?,?,'running',NOW())")->execute([$id,$kind,$parent])) throw new \RuntimeException('Could not save batch report.');
        foreach ($rows as $row) {
            $mac = strtolower($row['mac'] ?? $row['mac_address']);
            $data = ['mac'=>$mac, 'extension'=>$row['extension'], 'brand'=>$row['brand'], 'snapshot'=>$this->batchMappingSnapshot($row),
                'import'=>$kind === 'import' ? 'pending' : 'mapped', 'generation'=>'not_started', 'trigger'=>'not_started', 'delivery'=>'not_started',
                'verification'=>'not_started', 'outcome'=>'running', 'error'=>'', 'target_ip'=>null];
            if ($kind === 'import') $data += ['row'=>$row['row'], 'action'=>$row['action'], 'changes'=>$row['changes']];
            if ($kind === 'push') {
                $prior = $this->db->prepare("SELECT i.batch_id,i.data FROM provisioner_batch_items i JOIN provisioner_batches b ON b.id=i.batch_id WHERE b.kind='import' AND i.mac=? ORDER BY b.created_at DESC LIMIT 1");
                $prior->execute([$mac]); $import = $prior->fetch(\PDO::FETCH_ASSOC);
                if ($import && (json_decode($import['data'],true)['snapshot'] ?? []) == $data['snapshot']) {
                    $data['import'] = 'imported'; $data['import_batch_id'] = $import['batch_id'];
                }
            }
            if (!$this->db->prepare('INSERT INTO provisioner_batch_items (batch_id,mac,data) VALUES (?,?,?)')->execute([$id,$mac,json_encode($data)])) throw new \RuntimeException('Could not save batch item.');
        }
        return $id;
    }

    private function patchBatchItem($id, $mac, $patch, $jobId = null) {
        if (!$id) return;
        $owns = !$this->db->inTransaction();
        if ($owns) $this->db->beginTransaction();
        try {
            $lock = $this->db->getAttribute(\PDO::ATTR_DRIVER_NAME) === 'sqlite' ? '' : ' FOR UPDATE';
            $stmt = $this->db->prepare('SELECT data FROM provisioner_batch_items WHERE batch_id=? AND mac=?' . $lock);
            $stmt->execute([$id,strtolower($mac)]); $json = $stmt->fetchColumn();
            if ($json !== false) {
                $data = array_replace(json_decode($json,true), $patch);
                $data['updated_at'] = date('Y-m-d H:i:s');
                $sql = 'UPDATE provisioner_batch_items SET data=?' . ($jobId ? ',job_id=?' : '') . ' WHERE batch_id=? AND mac=?';
                $params = [json_encode($data, JSON_INVALID_UTF8_SUBSTITUTE)]; if ($jobId) $params[] = $jobId;
                $params[] = $id; $params[] = strtolower($mac);
                if (!$this->db->prepare($sql)->execute($params)) throw new \RuntimeException('Could not update batch report.');
            }
            if ($owns) $this->db->commit();
        } catch (\Throwable $error) { if ($owns && $this->db->inTransaction()) $this->db->rollBack(); throw $error; }
    }

    private function recordBatchStage($mac, $patch, $jobId = null) {
        if ($this->activeBatchId) { $this->patchBatchItem($this->activeBatchId, $mac, $patch, $jobId); return; }
        if (!$jobId) return;
        $stmt = $this->db->prepare('SELECT batch_id FROM provisioner_batch_items WHERE job_id=? AND mac=?');
        $stmt->execute([$jobId,strtolower($mac)]);
        foreach ($stmt->fetchAll(\PDO::FETCH_COLUMN) as $id) {
            $this->patchBatchItem($id,$mac,$patch);
            if (isset($patch['outcome']) && !in_array($patch['outcome'],['running','verifying'],true)) $this->getBatchReport($id);
        }
    }

    private function runPushBatch($command, $macs, $callback, $parent = null) {
        if ($this->activeBatchId) return $callback();
        $selected = $this->expandPushSelection($macs);
        if ($macs && !$selected) return ['success'=>false,'error'=>'No valid handset MACs selected.'];
        $devices = $this->db->query('SELECT * FROM provisioner_devices ORDER BY mac_address')->fetchAll(\PDO::FETCH_ASSOC);
        if (!$selected && $command === 'apply') {
            $pending = array_filter($devices, function ($row) { return !empty($row['needs_changes']); });
            if ($pending) $selected = $this->expandPushSelection(array_column($pending,'mac_address'));
        }
        if ($selected) $devices = array_values(array_filter($devices, function ($row) use ($selected) { return in_array($row['mac_address'],$selected,true); }));
        if (!$devices) return ['success'=>false,'error'=>'No mapped phones selected.'];
        $this->db->beginTransaction();
        try {
            $id = $this->createWorkflowBatch('push',$devices,$parent);
            $this->db->commit();
        } catch (\Throwable $error) {
            if ($this->db->inTransaction()) $this->db->rollBack();
            return ['success'=>false,'error'=>'Could not start saved batch: ' . $error->getMessage()];
        }
        $this->activeBatchId = $id;
        try {
            $result = $callback();
            $failed = $result['notify_failed'] ?? $result['failed'] ?? [];
            foreach ($failed as $phone) $this->patchBatchItem($id,$phone['mac'],['outcome'=>'failed','error'=>$phone['error'] ?? 'Push failed']);
            $stmt = $this->db->prepare('SELECT mac,data FROM provisioner_batch_items WHERE batch_id=?'); $stmt->execute([$id]);
            foreach ($stmt->fetchAll(\PDO::FETCH_ASSOC) as $item) {
                $data = json_decode($item['data'],true);
                if ($data['outcome'] === 'running') $this->patchBatchItem($id,$item['mac'],['outcome'=>'failed','error'=>$result['error'] ?? 'Push did not reach delivery.',
                    'generation'=>$data['generation'] === 'not_started' && empty($result['success']) ? 'failed' : $data['generation']]);
            }
            $this->db->prepare("UPDATE provisioner_batches SET status='submitted' WHERE id=?")->execute([$id]);
            $this->getBatchReport($id);
            $result['batch_id'] = $id;
            return $result;
        } catch (\Throwable $error) {
            foreach ($devices as $device) $this->patchBatchItem($id,$device['mac_address'],['outcome'=>'failed','error'=>$error->getMessage()]);
            $this->db->prepare("UPDATE provisioner_batches SET status='failed',finished_at=NOW() WHERE id=?")->execute([$id]);
            return ['success'=>false,'error'=>$error->getMessage(),'batch_id'=>$id];
        } finally { $this->activeBatchId = null; }
    }

    public function getBatchReport($id) {
        if (!is_string($id) || !preg_match('/^[a-f0-9]{32}$/',$id)) return ['success'=>false,'error'=>'Invalid batch ID.'];
        $stmt = $this->db->prepare('SELECT * FROM provisioner_batches WHERE id=?'); $stmt->execute([$id]); $batch = $stmt->fetch(\PDO::FETCH_ASSOC);
        if (!$batch) return ['success'=>false,'error'=>'Batch not found.'];
        $stmt = $this->db->prepare('SELECT mac,job_id,data FROM provisioner_batch_items WHERE batch_id=? ORDER BY mac'); $stmt->execute([$id]);
        $batchItems = $stmt->fetchAll(\PDO::FETCH_ASSOC);
        $stmt = $this->db->prepare('SELECT d.* FROM provisioner_devices d INNER JOIN provisioner_batch_items i ON i.mac=d.mac_address WHERE i.batch_id=?');
        $stmt->execute([$id]); $currentDevices = array_column($stmt->fetchAll(\PDO::FETCH_ASSOC),null,'mac_address');
        $items = []; $counts = []; $retry = [];
        foreach ($batchItems as $item) {
            $data = json_decode($item['data'],true);
            if (in_array($data['outcome'],['running','verifying'],true) && strtotime($batch['created_at']) < time()-600) {
                $data = array_replace($data,['outcome'=>'incomplete','error'=>'Batch stopped before completion. Review handset state before retrying.']);
                $this->patchBatchItem($id,$item['mac'],['outcome'=>$data['outcome'],'error'=>$data['error']]);
            }
            $current = $currentDevices[$item['mac']] ?? null;
            $data['mapping_changed'] = !$current || $this->batchMappingSnapshot($current) != $data['snapshot'];
            $data['superseded_by_push'] = !empty($item['job_id']) && !empty($current['last_push_job_id']) && $item['job_id'] !== $current['last_push_job_id'];
            $data['retryable'] = $batch['kind'] === 'push' && in_array($data['outcome'],['failed','incomplete','blocked'],true) && !$data['mapping_changed'] && !$data['superseded_by_push'];
            if ($data['retryable']) $retry[] = $item['mac'];
            unset($data['snapshot']);
            $items[] = $data; $counts[$data['outcome']] = ($counts[$data['outcome']] ?? 0)+1;
        }
        $active = !empty($counts['running']) || !empty($counts['verifying']);
        if (!$active && $batch['kind'] === 'push') {
            $status = count($items) === ($counts['verified'] ?? 0) ? 'complete' : 'incomplete';
            $this->db->prepare('UPDATE provisioner_batches SET status=?,finished_at=COALESCE(finished_at,NOW()) WHERE id=?')->execute([$status,$id]);
            $batch['status'] = $status;
            if (!$batch['finished_at']) $batch['finished_at'] = date('Y-m-d H:i:s');
        }
        $childrenStmt = $this->db->prepare('SELECT id,kind,status,created_at FROM provisioner_batches WHERE parent_id=? ORDER BY created_at,id');
        $childrenStmt->execute([$id]);
        return ['success'=>true,'batch'=>$batch,'items'=>$items,'counts'=>$counts,'active'=>$active,'retry_macs'=>$retry,
            'children'=>$childrenStmt->fetchAll(\PDO::FETCH_ASSOC)];
    }

    public function getBatchHistory($offset = 0, $limit = 25, $search = '', $sort = 'created_at', $order = 'desc') {
        $offset = max(0,(int)$offset);
        $limit = max(1,min(100,(int)$limit));
        $search = is_string($search) ? substr(trim($search),0,200) : '';
        $sort = in_array($sort,['created_at','kind','status','device_count'],true) ? $sort : 'created_at';
        $order = is_string($order) && strtolower($order) === 'asc' ? 'ASC' : 'DESC';
        $where = ''; $params = [];
        if ($search !== '') {
            $like = '%' . str_replace(['!','%','_'],['!!','!%','!_'],strtolower($search)) . '%';
            $macSearch = preg_match('/^[a-f0-9]{2}(?:[:-][a-f0-9]{0,2})+$/i',$search) ? str_replace([':', '-'],'',strtolower($search)) : strtolower($search);
            $macLike = '%' . str_replace(['!','%','_'],['!!','!%','!_'],$macSearch) . '%';
            $conditions = ["LOWER(b.id) LIKE ? ESCAPE '!'", "LOWER(b.kind) LIKE ? ESCAPE '!'", "LOWER(b.status) LIKE ? ESCAPE '!'", "b.created_at LIKE ? ESCAPE '!'",
                "EXISTS (SELECT 1 FROM provisioner_batch_items i WHERE i.batch_id=b.id AND (LOWER(i.mac) LIKE ? ESCAPE '!' OR LOWER(i.data) LIKE ? ESCAPE '!'))"];
            $params = [$like,$like,$like,$like,$macLike,$like];
            $statuses = [];
            foreach (['submitted'=>'In progress','running'=>'In progress','complete'=>'Complete','incomplete'=>'Needs attention','failed'=>'Failed'] as $status=>$label) {
                if (stripos($label,$search) !== false) $statuses[] = $status;
            }
            if ($statuses) {
                $conditions[] = 'b.status IN (' . implode(',',array_fill(0,count($statuses),'?')) . ')';
                $params = array_merge($params,$statuses);
            }
            $where = ' WHERE (' . implode(' OR ',$conditions) . ')';
        }
        $count = $this->db->prepare('SELECT COUNT(*) FROM provisioner_batches b' . $where);
        $count->execute($params); $total = (int)$count->fetchColumn();
        // Clamp an old page after search/deletion instead of leaving a non-empty history on a blank page.
        if ($total && $offset >= $total) $offset = (int)(floor(($total-1)/$limit)*$limit);
        $stmt = $this->db->prepare('SELECT b.*, (SELECT COUNT(*) FROM provisioner_batch_items i WHERE i.batch_id=b.id) AS device_count FROM provisioner_batches b'
            . $where . ' ORDER BY ' . $sort . ' ' . $order . ',b.id ' . $order . ' LIMIT ' . $limit . ' OFFSET ' . $offset);
        $stmt->execute($params); $rows = $stmt->fetchAll(\PDO::FETCH_ASSOC);
        foreach ($rows as &$row) {
            $report = $this->getBatchReport($row['id']); $row = $report['batch']; $row['counts'] = $report['counts']; $row['device_count'] = count($report['items']);
        }
        unset($row);
        return ['success'=>true,'batches'=>$rows,'offset'=>$offset,'total'=>$total,'total_all'=>(int)$this->db->query('SELECT COUNT(*) FROM provisioner_batches')->fetchColumn()];
    }

    public function retryBatch($id) {
        $report = $this->getBatchReport($id);
        if (empty($report['success'])) return $report;
        if ($report['active']) return ['success'=>false,'error'=>'Wait for this batch to finish before retrying.'];
        $macs = $report['retry_macs'];
        if (!$macs) return ['success'=>false,'error'=>'No retryable phones. Changed mappings require a new push.'];
        return $this->runPushBatch('retry',$macs,function () use ($macs) { return $this->pushToPhones($macs); },$id);
    }
}
