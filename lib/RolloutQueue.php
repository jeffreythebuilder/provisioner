<?php
namespace FreePBX\modules\Provisioner;
require_once __DIR__ . '/RolloutSchema.php';

// SQL state machine only. All handset/config actions belong to the supplied
// executor. There is never an automatic replay across a dispatch checkpoint.
final class RolloutQueue {
    const LEASE_SECONDS = 300;
    private $db;
    private $token;
    private $id;
    private $currentWave = [];
    private $lastRenewal = 0;
    private $submissionStatus = 'queued';
    public function submissionStatus() { return $this->submissionStatus; }

    public function __construct($db) { $this->db = $db; }
    private function sql($sql, $args = []) {
        $stmt = $this->db->prepare($sql);
        if (!$stmt || !$stmt->execute($args)) throw new \RuntimeException('Could not persist rollout state.');
        return $stmt;
    }
    private function lockedControl() {
        $suffix = $this->db->getAttribute(\PDO::ATTR_DRIVER_NAME) === 'sqlite' ? '' : ' FOR UPDATE';
        $row = $this->sql('SELECT * FROM provisioner_rollout_control WHERE id=1' . $suffix)->fetch(\PDO::FETCH_ASSOC);
        if (!$row || (int)($row['schema_revision'] ?? 0) !== RolloutSchema::REVISION) throw new \RuntimeException('Install/upgrade Provisioner through Module Admin before pushing.');
        return $row;
    }
    public static function waves($macs, $groups, $limit) {
        $limit = max(1, min(30, (int)$limit));
        $remaining = array_fill_keys($macs, true); $units = [];
        foreach ($groups as $group) {
            if (!array_intersect($group, $macs)) continue;
            if (array_diff($group, $macs)) throw new \RuntimeException('Select every extension swap partner.');
            if (count($group) > $limit) throw new \RuntimeException('An extension swap group exceeds the wave limit. Review the group before pushing.');
            $units[] = $group;
            foreach ($group as $mac) unset($remaining[$mac]);
        }
        foreach (array_keys($remaining) as $mac) $units[] = [$mac];
        $waves = []; $wave = [];
        foreach ($units as $unit) {
            if (count($wave) + count($unit) > $limit) { $waves[] = $wave; $wave = []; }
            $wave = array_merge($wave, $unit);
        }
        if ($wave) $waves[] = $wave;
        return $waves;
    }
    // Must be called in the submission transaction, also containing the report.
    public function submit($id, $devices, $groups, $command, $key, $limit, $createReport) {
        $this->lockedControl(); // serialize overlapping submissions across browsers
        $macs = array_column($devices, 'mac_address'); sort($macs);
        $fields = array_flip(['id','mac_address','extension','description','brand','static_ip','static_mask','static_gw','previous_extension','custom_config','needs_changes','updated_at']);
        $fingerprint = hash('sha256', $command . json_encode(array_map(function ($device) use ($fields) { return array_intersect_key($device,$fields); }, $devices), JSON_THROW_ON_ERROR));
        $requestKey = $key === '' ? null : hash('sha256', $key);
        if ($requestKey) {
            $prior = $this->sql('SELECT id,fingerprint,status FROM provisioner_rollouts WHERE request_key=?', [$requestKey])->fetch(\PDO::FETCH_ASSOC);
            if ($prior) {
                if ($prior['fingerprint'] !== $fingerprint) throw new \RuntimeException('This request key was already used for different mappings. Submit a new request.');
                $this->submissionStatus = $prior['status'];
                return $prior['id'];
            }
        }
        $prior = $this->sql("SELECT id FROM provisioner_rollouts WHERE fingerprint=? AND status IN ('queued','running') ORDER BY created_at,id", [$fingerprint])->fetchColumn();
        if ($prior) return $prior;
        foreach ($macs as $mac) {
            if ($this->sql('SELECT rollout_id FROM provisioner_rollout_owners WHERE mac=?', [$mac])->fetchColumn()) throw new \RuntimeException('A selected handset already belongs to an active push. Open its saved report before retrying.');
        }
        $waves = self::waves($macs, $groups, $limit);
        $createReport($id);
        $this->sql("INSERT INTO provisioner_rollouts (id,status,fingerprint,request_key,waves,created_at,summary) VALUES (?,'queued',?,?,?,NOW(),?)", [$id,$fingerprint,$requestKey,json_encode($waves, JSON_THROW_ON_ERROR),json_encode(['queued'=>count($macs)])]);
        foreach ($macs as $mac) $this->sql('INSERT INTO provisioner_rollout_owners (mac,rollout_id) VALUES (?,?)', [$mac,$id]);
        return $id;
    }
    public function heartbeat($force = false) {
        if (!$this->id) return;
        if (!$force && microtime(true)-$this->lastRenewal < 2) return;
        $now = time();
        $stmt = $this->sql('UPDATE provisioner_rollout_control SET expires=? WHERE id=1 AND token=? AND expires>=? AND schema_revision=?', [$now+self::LEASE_SECONDS,$this->token,$now,RolloutSchema::REVISION]);
        // MySQL may report zero changed rows for a same-second renewal.
        if (!$stmt->rowCount() && !$this->sql('SELECT id FROM provisioner_rollout_control WHERE id=1 AND token=? AND expires>=? AND schema_revision=?', [$this->token,$now,RolloutSchema::REVISION])->fetchColumn()) throw new \RuntimeException('Rollout worker lease lost; no further notifications permitted.');
        $this->sql("UPDATE provisioner_rollouts SET heartbeat=?,expires=? WHERE id=? AND token=? AND status='running'", [$now,$now+self::LEASE_SECONDS,$this->id,$this->token]);
        $this->lastRenewal = microtime(true);
    }
    public function checkpoint($macs) {
        $this->heartbeat(true);
        if (array_diff($macs, $this->currentWave)) throw new \RuntimeException('A changed swap selection would exceed the owned wave. No notifications were sent.');
        $this->db->beginTransaction();
        try {
            $control = $this->lockedControl();
            if ($control['token'] !== $this->token || (int)$control['expires'] < time()) throw new \RuntimeException('Worker was superseded before dispatch.');
            foreach ($macs as $mac) {
                if ($this->sql('SELECT rollout_id FROM provisioner_rollout_owners WHERE mac=?',[$mac])->fetchColumn() !== $this->id) throw new \RuntimeException('Handset ownership changed before notification.');
                $row = $this->sql('SELECT data FROM provisioner_batch_items WHERE batch_id=? AND mac=?', [$this->id,$mac])->fetchColumn();
                if (!$row) throw new \RuntimeException('Missing saved rollout item.');
                $data = json_decode($row, true, 512, JSON_THROW_ON_ERROR);
                if (!empty($data['dispatch_checkpoint'])) throw new \RuntimeException('This attempt may already have notified the handset. Review before retrying.');
                $data['dispatch_checkpoint'] = date('c');
                $this->sql('UPDATE provisioner_batch_items SET data=? WHERE batch_id=? AND mac=?', [json_encode($data, JSON_THROW_ON_ERROR),$this->id,$mac]);
            }
            $this->db->commit();
        } catch (\Throwable $error) { if ($this->db->inTransaction()) $this->db->rollBack(); throw $error; }
    }
    private function finish($status, $error = '') {
        $this->heartbeat(true);
        $this->db->beginTransaction();
        try {
            $control = $this->lockedControl();
            if ($control['token'] !== $this->token) throw new \RuntimeException('Worker lease changed.');
            $this->sql('UPDATE provisioner_rollouts SET status=?,error=?,expires=0 WHERE id=? AND token=?', [$status,$error,$this->id,$this->token]);
            $rows = $this->sql('SELECT mac,data FROM provisioner_batch_items WHERE batch_id=?', [$this->id])->fetchAll(\PDO::FETCH_ASSOC);
            $counts = [];
            foreach ($rows as $row) {
                $data = json_decode($row['data'], true, 512, JSON_THROW_ON_ERROR);
                $outcome = $data['outcome'];
                if ($status !== 'queued' && in_array($outcome,['queued','running','verifying'],true)) $outcome = 'incomplete';
                $counts[$outcome] = ($counts[$outcome] ?? 0)+1;
            }
            $this->sql('UPDATE provisioner_rollouts SET summary=? WHERE id=? AND token=?', [json_encode($counts, JSON_THROW_ON_ERROR),$this->id,$this->token]);
            if ($status !== 'queued') {
                foreach ($rows as $row) {
                    $data = json_decode($row['data'], true, 512, JSON_THROW_ON_ERROR);
                    if (in_array($data['outcome'], ['queued','running','verifying'], true)) {
                        $uncertain = !empty($data['dispatch_checkpoint']);
                        $data = array_replace($data, ['outcome'=>'incomplete','error'=>($uncertain ? '' : 'Not sent: ') . ($error ?: 'Rollout stopped. Review handset state before explicitly retrying.'),
                            'next_action'=>$uncertain ? 'diagnostics' : 'push','retry_requires_review'=>$uncertain]);
                        $this->sql('UPDATE provisioner_batch_items SET data=? WHERE batch_id=? AND mac=?', [json_encode($data, JSON_THROW_ON_ERROR),$this->id,$row['mac']]);
                    }
                }
                $this->sql('DELETE FROM provisioner_rollout_owners WHERE rollout_id=?', [$this->id]);
                $this->sql('UPDATE provisioner_batches SET status=?,finished_at=NOW() WHERE id=?', [$status === 'complete' ? 'complete' : 'incomplete',$this->id]);
            }
            $this->sql('UPDATE provisioner_rollout_control SET token=NULL,expires=0 WHERE id=1 AND token=?', [$this->token]);
            $this->db->commit();
        } catch (\Throwable $error) { if ($this->db->inTransaction()) $this->db->rollBack(); throw $error; }
    }
    // One wave per call. CLI may call again; cron guarantees eventual wake-up.
    public function workOne($executor) {
        $this->db->beginTransaction();
        try {
            $control = $this->lockedControl();
            if ((int)$control['expires'] >= time() && $control['token']) { $this->db->commit(); return false; }
            $job = $this->sql("SELECT * FROM provisioner_rollouts WHERE status IN ('running','queued') ORDER BY CASE WHEN status='running' THEN 0 ELSE 1 END,created_at,id LIMIT 1")->fetch(\PDO::FETCH_ASSOC);
            if (!$job) { $this->db->commit(); return false; }
            $this->id = $job['id']; $this->token = bin2hex(random_bytes(16));
            $this->sql('UPDATE provisioner_rollout_control SET token=?,expires=? WHERE id=1', [$this->token,time()+self::LEASE_SECONDS]);
            $this->sql("UPDATE provisioner_rollouts SET status='running',token=?,heartbeat=?,expires=? WHERE id=?", [$this->token,time(),time()+self::LEASE_SECONDS,$this->id]);
            $this->sql("UPDATE provisioner_batches SET status='running' WHERE id=?", [$this->id]);
            $this->db->commit();
        } catch (\Throwable $error) { if ($this->db->inTransaction()) $this->db->rollBack(); throw $error; }
        try {
            $waves = json_decode($job['waves'], true, 512, JSON_THROW_ON_ERROR);
            $wave = $waves[(int)$job['wave_cursor']] ?? [];
            $this->currentWave = $wave;
            if (!$wave) throw new \RuntimeException('Invalid saved rollout wave.');
            // A process died after durable dispatch intent. Do NOT replay it,
            // even if no SIP result or download evidence reached the database.
            foreach ($wave as $mac) {
                $data = json_decode($this->sql('SELECT data FROM provisioner_batch_items WHERE batch_id=? AND mac=?', [$this->id,$mac])->fetchColumn(), true, 512, JSON_THROW_ON_ERROR);
                if (!empty($data['dispatch_checkpoint'])) throw new \RuntimeException('Worker stopped after dispatch began. Delivery is uncertain; inspect Diagnostics before an explicit retry.');
            }
            $healthy = $executor($this->id, $wave, $this);
            $this->heartbeat();
            if (!$healthy) {
                $this->finish('review', 'A wave was not fully verified. Remaining phones were not notified; review the saved report before explicitly retrying.');
            } else {
                $this->sql('UPDATE provisioner_rollouts SET wave_cursor=wave_cursor+1 WHERE id=? AND token=?', [$this->id,$this->token]);
                $this->finish((int)$job['wave_cursor']+1 >= count($waves) ? 'complete' : 'queued');
            }
        } catch (\Throwable $error) {
            $this->finish('review', $error->getMessage());
        } finally { $this->id = null; $this->token = null; $this->lastRenewal = 0; $this->currentWave = []; }
        return true;
    }
}
