<?php

namespace FreePBX\modules\Provisioner;

// Private, short-lived jobs are shared by the CLI worker and the HTTP receiver.
class SipDiscovery {
    const IDENTITY_MAX_AGE = 900;
    private $directory;

    public function __construct($directory = null) {
        $uid = function_exists('posix_geteuid') ? posix_geteuid() : getmyuid();
        // Apache PrivateTmp and externally launched CLI workers need the same filesystem.
        $base = is_dir('/var/spool/asterisk') ? '/var/spool/asterisk' : sys_get_temp_dir();
        $this->directory = $directory ?: (getenv('PROVISIONER_DISCOVERY_DIRECTORY') ?: $base . '/provisioner-discovery-' . $uid);
    }

    public function directory() { return $this->directory; }

    public function path($id, $name = 'job.json') {
        if (!preg_match('/^[a-f0-9]{32}$/', (string)$id)) throw new \RuntimeException('Invalid discovery job.');
        return $this->directory . '/' . $id . '/' . $name;
    }

    public static function writeJson($path, $data) {
        $temporary = $path . '.' . bin2hex(random_bytes(6));
        if (file_put_contents($temporary, json_encode($data, JSON_INVALID_UTF8_SUBSTITUTE), LOCK_EX) === false) {
            throw new \RuntimeException('Could not save discovery progress.');
        }
        chmod($temporary, 0600);
        if (!rename($temporary, $path)) { @unlink($temporary); throw new \RuntimeException('Could not activate discovery progress.'); }
    }

    public function create($type, $payload) {
        if (!in_array($type, ['scan', 'identify'], true)) throw new \RuntimeException('Invalid discovery action.');
        if (!is_dir($this->directory) && !@mkdir($this->directory, 0700, true) && !is_dir($this->directory)) {
            throw new \RuntimeException('Discovery storage is unavailable.');
        }
        $uid = function_exists('posix_geteuid') ? posix_geteuid() : getmyuid();
        if (is_link($this->directory) || fileowner($this->directory) !== $uid) throw new \RuntimeException('Invalid discovery storage owner.');
        chmod($this->directory, 0700);
        $lock = fopen($this->directory . '/create.lock', 'c');
        if (!$lock || !flock($lock, LOCK_EX)) throw new \RuntimeException('Could not lock discovery storage.');
        chmod($this->directory . '/create.lock', 0600);
        try {
            $active = 0;
            // Only this class's expired jobs are cleaned up.
            foreach (glob($this->directory . '/*/job.json') ?: [] as $old) {
                if (is_link(dirname($old))) continue;
                $existing = $this->load(basename(dirname($old)));
                if ($existing && in_array($existing['status'], ['queued', 'running'], true)) {
                    $active++;
                    if ($type === 'identify' && $existing['type'] === 'identify'
                        && ($existing['payload']['scan_id'] ?? '') === ($payload['scan_id'] ?? '')) {
                        throw new \RuntimeException('MAC identification for this scan is already running.');
                    }
                }
                if (filemtime($old) >= time() - 3600) continue;
                foreach (glob(dirname($old) . '/*') ?: [] as $file) if (is_file($file) && !is_link($file)) @unlink($file);
                @rmdir(dirname($old));
            }
            if ($active >= 2) throw new \RuntimeException('Two discovery jobs are already running. Wait for one to finish.');
            $id = bin2hex(random_bytes(16));
            if (!mkdir(dirname($this->path($id)), 0700)) throw new \RuntimeException('Could not create discovery storage.');
            $job = ['id' => $id, 'type' => $type, 'status' => 'queued', 'created' => time(), 'expires' => time() + 300, 'payload' => $payload];
            $this->save($job);
            return $job;
        } finally { fclose($lock); }
    }

    public function save($job) { self::writeJson($this->path($job['id']), $job); }

    public function load($id) {
        if (!preg_match('/^[a-f0-9]{32}$/', (string)$id)) return null;
        $path = $this->path($id);
        if (!is_file($path) || is_link($path)) return null;
        $job = json_decode(file_get_contents($path), true);
        return is_array($job) && ($job['id'] ?? '') === $id && ($job['expires'] ?? 0) >= time() ? $job : null;
    }

    public static function macBrand($mac) {
        $prefix = substr(strtolower(preg_replace('/[^a-f0-9]/i', '', (string)$mac)), 0, 6);
        if (in_array($prefix, ['0004f3', '000fd3'], true)) return 'digium';
        if (in_array($prefix, ['00809f', '08005a', '001c0e', '00236a', '3c28a6'], true)) return 'alcatel';
        return null;
    }

    public static function fingerprint($userAgent) {
        $userAgent = substr((string)$userAgent, 0, 1024);
        if (preg_match('/^(?:Asterisk|Digium|Sangoma)\s+([ADP][0-9]+[a-z0-9]*)(?:\s+Phone)?\s+([0-9]+(?:\.[0-9]+){2,6})\b/i', $userAgent, $match)) {
            $brand = 'digium';
        } elseif (preg_match('/^(?:ALE|Alcatel(?:-Lucent)?)\s+([a-z0-9_-]+)\s+([0-9]+(?:\.[0-9]+){2,6})\b/i', $userAgent, $match)) {
            $brand = 'alcatel';
        } else { return null; }
        return ['brand' => $brand, 'model' => $match[1], 'firmware' => $match[2], 'user_agent' => $userAgent];
    }

    public static function identity($userAgent, $filename = '') {
        $fingerprint = self::fingerprint($userAgent);
        if (!$fingerprint) return null;
        $userAgent = $fingerprint['user_agent'];
        $mac = null;
        if (preg_match('/(?<![a-f0-9])([a-f0-9]{12}|(?:[a-f0-9]{2}[:-]){5}[a-f0-9]{2})(?![a-f0-9])/i', $userAgent, $match)) {
            $mac = strtolower(preg_replace('/[^a-f0-9]/i', '', $match[1]));
        }
        if (preg_match('/^(?:config\.)?([a-f0-9]{12})\.(?:cfg|xml)$/i', $filename, $match)) {
            $fileMac = strtolower($match[1]);
            if ($mac !== null && $mac !== $fileMac) return null;
            $mac = $fileMac;
        }
        if ($mac === null || self::macBrand($mac) !== $fingerprint['brand']) return null;
        return ['mac' => $mac] + $fingerprint;
    }

    public function receive($id, $nonce, $ip, $userAgent, $filename) {
        if (!preg_match('/^[a-f0-9]{32}$/', (string)$nonce) || !preg_match('/^[a-z0-9._-]{1,100}$/i', $filename)) return false;
        $job = $this->load($id);
        if (!$job || $job['type'] !== 'identify' || $job['status'] !== 'running') return false;
        $target = null;
        foreach ($job['payload']['targets'] ?? [] as $candidate) {
            if (hash_equals($candidate['nonce'], $nonce) && $candidate['ip'] === $ip) { $target = $candidate; break; }
        }
        if (!$target) return false;
        $identity = self::identity($userAgent, $filename);
        if (!$identity) return false;
        $identity += ['ip' => $ip, 'sip_port' => $target['port'], 'sip_transport' => $target['transport'],
            'source' => 'SIP discovery fetch (HTTP 404)', 'filename' => $filename, 'http_status' => 404,
            'seen_at' => microtime(true), 'last_seen' => date('Y-m-d H:i:s')];
        $path = $this->path($id, 'receipt-' . $nonce . '.json');
        $lock = fopen($this->path($id, 'receipt-' . $nonce . '.lock'), 'c');
        if (!$lock || !flock($lock, LOCK_EX)) return false;
        try {
            $previous = is_file($path) ? json_decode(file_get_contents($path), true) : null;
            if ($previous && $previous['mac'] !== $identity['mac']) return false;
            $requests = $previous['requests'] ?? [];
            $requests[$filename] = ['filename' => $filename, 'http_status' => 404, 'received_at' => $identity['last_seen']];
            $identity['requests'] = array_slice($requests, -8, null, true);
            self::writeJson($path, $identity);
        } finally { fclose($lock); }
        return true;
    }
}
