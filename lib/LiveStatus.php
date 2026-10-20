<?php

namespace FreePBX\modules\Provisioner;

// One on-demand local broker shares AMI events and status snapshots between browsers.
class LiveStatus {
    private $module;
    private $directory;

    public function __construct($module, $directory = null) {
        $this->module = $module;
        $this->directory = $directory ?: self::directory();
    }

    public static function directory() {
        $uid = function_exists('posix_geteuid') ? posix_geteuid() : getmyuid();
        return sys_get_temp_dir() . '/provisioner-live-' . $uid;
    }

    public static function notify($directory = null) {
        $path = ($directory ?: self::directory()) . '/events.sock';
        if (!file_exists($path)) return;
        $socket = @stream_socket_client('unix://' . $path, $errno, $error, 0.1);
        if (!$socket) return;
        @fwrite($socket, "invalidate\n");
        fclose($socket);
    }

    public static function snapshot($rows, $pending, $amiConnected = null) {
        $data = ['rows' => $rows, 'pending' => $pending, 'ami_connected' => $amiConnected];
        $data['revision'] = hash('sha256', json_encode($data, JSON_INVALID_UTF8_SUBSTITUTE));
        return $data;
    }

    private static function ensureDirectory($directory) {
        if (!is_dir($directory) && !@mkdir($directory, 0700, true) && !is_dir($directory)) return false;
        $uid = function_exists('posix_geteuid') ? posix_geteuid() : getmyuid();
        return !is_link($directory) && fileowner($directory) === $uid && @chmod($directory, 0700);
    }

    private static function connect($directory) {
        return @stream_socket_client('unix://' . $directory . '/events.sock', $errno, $error, 0.2);
    }

    public static function subscribe() {
        $directory = self::directory();
        if (!self::ensureDirectory($directory)) return false;
        $socket = self::connect($directory);
        if (!$socket) {
            $lock = @fopen($directory . '/start.lock', 'c');
            if (!$lock || !flock($lock, LOCK_EX)) return false;
            try {
                $socket = self::connect($directory);
                if (!$socket) {
                    $php = is_executable('/usr/bin/php') ? '/usr/bin/php' : '/usr/local/bin/php';
                    if (!is_executable($php)) return false;
                    exec('nohup ' . escapeshellarg($php) . ' ' . escapeshellarg(dirname(__DIR__) . '/live_status.php')
                        . ' </dev/null >/dev/null 2>&1 &', $output, $status);
                    if ($status !== 0) return false;
                    $deadline = microtime(true) + 4;
                    do {
                        usleep(50000);
                        $socket = self::connect($directory);
                    } while (!$socket && microtime(true) < $deadline);
                }
            } finally {
                fclose($lock);
            }
        }
        if ($socket) {
            stream_set_timeout($socket, 12);
            fwrite($socket, "subscribe\n");
        }
        return $socket;
    }

    public static function stream() {
        $socket = false;
        try { $socket = self::subscribe(); } catch (\Throwable $e) {}
        if (session_status() === PHP_SESSION_ACTIVE) session_write_close();
        @ini_set('zlib.output_compression', '0');
        while (ob_get_level() > 0) {
            if (!@ob_end_clean()) break;
        }
        header('Content-Type: text/event-stream');
        header('Cache-Control: no-cache, no-store');
        header('X-Accel-Buffering: no');
        set_time_limit(100);
        echo "retry: 3000\n: " . str_repeat(' ', 2048) . "\n\n";
        flush();
        if (!$socket) {
            echo "event: unavailable\ndata: {}\n\n";
            flush();
            return;
        }
        try {
            // Periodic reconnects re-check FreePBX authentication and module access.
            $deadline = microtime(true) + 90;
            do {
                $line = fgets($socket, 2097152);
                if ($line === false) {
                    break;
                } else {
                    $message = json_decode($line, true);
                    if (($message['type'] ?? '') === 'status' && isset($message['snapshot']['revision'])) {
                        echo 'id: ' . $message['snapshot']['revision'] . "\nevent: status\ndata: "
                            . json_encode($message['snapshot'], JSON_INVALID_UTF8_SUBSTITUTE) . "\n\n";
                    } else {
                        echo "event: heartbeat\ndata: {}\n\n";
                    }
                }
                flush();
            } while (!connection_aborted() && microtime(true) < $deadline);
            if (!connection_aborted() && microtime(true) >= $deadline) {
                echo "event: reconnect\ndata: {}\n\n";
                flush();
            }
        } finally {
            fclose($socket);
        }
    }

    private function refresh($amiConnected) {
        return self::snapshot($this->module->getPhoneStatusGrid(), $this->module->getPendingCount(), $amiConnected);
    }

    public function run($amiSettings, $idleTimeout = 60) {
        if (!self::ensureDirectory($this->directory)) return false;
        $lock = fopen($this->directory . '/worker.lock', 'c');
        if (!$lock || !flock($lock, LOCK_EX | LOCK_NB)) return false;
        $path = $this->directory . '/events.sock';
        @unlink($path);
        $server = @stream_socket_server('unix://' . $path, $errno, $error);
        if (!$server) { fclose($lock); return false; }
        chmod($path, 0600);
        stream_set_blocking($server, false);
        $clients = [];
        $ami = false;
        $amiConnected = false;
        $amiBuffer = '';
        $amiLastReceive = 0;
        $retryAt = 0;
        $pingAt = 0;
        $snapshot = null;
        $dirtyAt = microtime(true);
        $reconcileAt = 0;
        $heartbeatAt = 0;
        $idleSince = microtime(true);
        $sourceVersion = filemtime(__FILE__);
        $disconnectAmi = function () use (&$ami, &$amiConnected, &$amiBuffer, &$retryAt, &$dirtyAt) {
            if ($ami) fclose($ami);
            $ami = false;
            $amiConnected = false;
            $amiBuffer = '';
            $retryAt = microtime(true) + 5;
            $dirtyAt = microtime(true);
        };
        $queue = function ($message, $only = null) use (&$clients) {
            $line = json_encode($message, JSON_INVALID_UTF8_SUBSTITUTE) . "\n";
            foreach ($clients as $id => &$client) {
                if ($client['subscribed'] && ($only === null || $only === $id)) {
                    $client['out'] .= $line;
                    if (strlen($client['out']) > 2097152) {
                        fclose($client['socket']);
                        unset($clients[$id]);
                    }
                }
            }
            unset($client);
        };
        try {
            while (true) {
                $now = microtime(true);
                $subscribed = array_filter($clients, function ($client) { return $client['subscribed']; });
                if ($subscribed) $idleSince = $now;
                if (!$subscribed && $now - $idleSince >= $idleTimeout) break;
                if (!$ami && $now >= $retryAt) {
                    $host = $amiSettings['host'] ?? '127.0.0.1';
                    $port = (int)($amiSettings['port'] ?? 5038);
                    $ami = @stream_socket_client("tcp://$host:$port", $errno, $error, 1);
                    if ($ami) {
                        stream_set_blocking($ami, false);
                        $login = "Action: Login\r\nActionID: provisioner-login\r\nUsername: " . $amiSettings['username']
                            . "\r\nSecret: " . $amiSettings['secret'] . "\r\nEvents: system\r\n\r\n";
                        @fwrite($ami, $login);
                        $amiLastReceive = $now;
                        $pingAt = $now + 15;
                    } else { $retryAt = $now + 5; }
                }
                if ($ami && $now - $amiLastReceive > ($amiConnected ? 40 : 5)) $disconnectAmi();
                if ($ami && $now >= $pingAt) {
                    @fwrite($ami, "Action: Ping\r\nActionID: provisioner-ping\r\n\r\n");
                    $pingAt = $now + 15;
                }
                if (($dirtyAt !== null && $now >= $dirtyAt) || $now >= $reconcileAt) {
                    clearstatcache(true, __FILE__);
                    if (filemtime(__FILE__) !== $sourceVersion) break;
                    $next = $this->refresh($amiConnected);
                    if (!$snapshot || $snapshot['revision'] !== $next['revision']) {
                        $snapshot = $next;
                        $queue(['type' => 'status', 'snapshot' => $snapshot]);
                    }
                    $dirtyAt = null;
                    $reconcileAt = $now + 30;
                }
                if ($now >= $heartbeatAt) {
                    $queue(['type' => 'heartbeat']);
                    $heartbeatAt = $now + 10;
                }
                foreach ($clients as $id => $client) {
                    if (!$client['subscribed'] && $now - $client['created'] > 3) {
                        fclose($client['socket']);
                        unset($clients[$id]);
                    }
                }
                $read = [$server];
                if ($ami) $read[] = $ami;
                $write = [];
                foreach ($clients as $client) {
                    $read[] = $client['socket'];
                    if ($client['out'] !== '') $write[] = $client['socket'];
                }
                $except = null;
                $wait = $dirtyAt === null ? 1000000 : 100000;
                if (@stream_select($read, $write, $except, 0, $wait) === false) continue;
                foreach ($write as $socket) {
                    $id = (int)$socket;
                    $written = @fwrite($socket, $clients[$id]['out']);
                    if ($written === false) { fclose($socket); unset($clients[$id]); }
                    else $clients[$id]['out'] = substr($clients[$id]['out'], $written);
                }
                foreach ($read as $socket) {
                    if (!is_resource($socket)) continue;
                    if ($socket === $server) {
                        while ($accepted = @stream_socket_accept($server, 0)) {
                            if (count($clients) >= 64) { fclose($accepted); continue; }
                            stream_set_blocking($accepted, false);
                            $clients[(int)$accepted] = ['socket' => $accepted, 'subscribed' => false,
                                'buffer' => '', 'out' => '', 'created' => microtime(true)];
                        }
                        continue;
                    }
                    $data = @fread($socket, 65536);
                    if ($socket === $ami) {
                        if ($data === false || ($data === '' && feof($socket))) { $disconnectAmi(); continue; }
                        $amiLastReceive = microtime(true);
                        $amiBuffer .= $data;
                        while (($end = strpos($amiBuffer, "\r\n\r\n")) !== false) {
                            $frame = substr($amiBuffer, 0, $end);
                            $amiBuffer = substr($amiBuffer, $end + 4);
                            if (strpos($frame, 'ActionID: provisioner-login') !== false) {
                                if (strpos($frame, 'Response: Success') === false) { $disconnectAmi(); break; }
                                $amiConnected = true;
                                $dirtyAt = microtime(true);
                            }
                            if (preg_match('/(?:^|\r\n)Event: (ContactStatus|PeerStatus|Registry|Reload|FullyBooted|Shutdown)\r?$/m', $frame)) {
                                if ($dirtyAt === null) $dirtyAt = microtime(true) + 0.1;
                            }
                        }
                        if (strlen($amiBuffer) > 1048576) $disconnectAmi();
                        continue;
                    }
                    $id = (int)$socket;
                    if (!isset($clients[$id])) continue;
                    if ($data === false || ($data === '' && feof($socket))) { fclose($socket); unset($clients[$id]); continue; }
                    $clients[$id]['buffer'] .= $data;
                    if (strlen($clients[$id]['buffer']) > 1024) { fclose($socket); unset($clients[$id]); continue; }
                    while (($end = strpos($clients[$id]['buffer'], "\n")) !== false) {
                        $command = substr($clients[$id]['buffer'], 0, $end);
                        $clients[$id]['buffer'] = substr($clients[$id]['buffer'], $end + 1);
                        if ($command === 'subscribe') {
                            $clients[$id]['subscribed'] = true;
                            if ($snapshot) $queue(['type' => 'status', 'snapshot' => $snapshot], $id);
                            if ($dirtyAt === null) $dirtyAt = microtime(true) + 0.1;
                        } elseif ($command === 'invalidate' && $dirtyAt === null) {
                            $dirtyAt = microtime(true) + 0.1;
                        }
                    }
                }
            }
            return true;
        } finally {
            foreach ($clients as $client) fclose($client['socket']);
            if ($ami) fclose($ami);
            fclose($server);
            @unlink($path);
            fclose($lock);
        }
    }
}
