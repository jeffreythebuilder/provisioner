<?php

use FreePBX\modules\Provisioner\LiveStatus;

final class LiveStatusFixture {
    private $state;
    public function __construct($state) { $this->state = $state; }
    public function getPhoneStatusGrid() { return json_decode(file_get_contents($this->state), true); }
    public function getPendingCount() { return ['count' => 0, 'needs_reboot' => false, 'deploy_mode' => 'sip_notify']; }
}

function liveMessage($socket, $predicate) {
    stream_set_timeout($socket, 1);
    $deadline = microtime(true) + 4;
    do {
        $line = fgets($socket);
        $message = $line === false ? null : json_decode($line, true);
        if ($message && $predicate($message)) return $message;
    } while (!feof($socket) && microtime(true) < $deadline);
    throw new RuntimeException('Expected live status message was not delivered');
}

test('live status revisions change only for visible state and connection changes', function () {
    $rows = [['mac' => '000fd3000001', 'status' => 'Registered']];
    $pending = ['count' => 0];
    $first = LiveStatus::snapshot($rows, $pending, true);
    assertSame($first['revision'], LiveStatus::snapshot($rows, $pending, true)['revision']);
    assertTrue($first['revision'] !== LiveStatus::snapshot($rows, $pending, false)['revision']);
    assertTrue($first['revision'] !== LiveStatus::snapshot($rows, ['count' => 1], true)['revision']);
    assertSame(['rows', 'pending', 'ami_connected', 'revision'], array_keys($first));
});

test('shared live broker delivers AMI and local changes, recovers connection loss, and exits idle', function () {
    if (!function_exists('pcntl_fork')) throw new RuntimeException('pcntl is required for the live broker integration test');
    global $tempDirs;
    $directory = sys_get_temp_dir() . '/provisioner-live-test-' . bin2hex(random_bytes(6));
    mkdir($directory, 0700);
    $tempDirs[] = $directory;
    $state = $directory . '/state.json';
    $rows = [['mac' => '000fd3000001', 'ip' => '192.168.188.80', 'status' => 'Registered']];
    file_put_contents($state, json_encode($rows));
    $amiServer = stream_socket_server('tcp://127.0.0.1:0', $errno, $error);
    assertTrue((bool)$amiServer, $error);
    $port = (int)substr(strrchr(stream_socket_get_name($amiServer, false), ':'), 1);
    $pid = pcntl_fork();
    if ($pid === 0) {
        fclose($amiServer);
        $broker = new LiveStatus(new LiveStatusFixture($state), $directory);
        exit($broker->run(['host' => '127.0.0.1', 'port' => $port, 'username' => 'test', 'secret' => 'test'], 1) ? 0 : 1);
    }
    assertTrue($pid > 0);
    $subscriber = $second = $ami = false;
    try {
        $ami = stream_socket_accept($amiServer, 4);
        assertTrue((bool)$ami, 'The broker must connect to AMI');
        fwrite($ami, "Asterisk Call Manager/6.0.0\r\nResponse: Success\r\nActionID: provisioner-login\r\n\r\n");
        $subscriber = stream_socket_client('unix://' . $directory . '/events.sock', $errno, $error, 2);
        assertTrue((bool)$subscriber, $error);
        fwrite($subscriber, "subscribe\n");
        $initial = liveMessage($subscriber, function ($message) {
            return $message['type'] === 'status' && $message['snapshot']['ami_connected'] === true;
        });
        assertSame($rows, $initial['snapshot']['rows']);

        $second = stream_socket_client('unix://' . $directory . '/events.sock', $errno, $error, 2);
        fwrite($second, "subscribe\n");
        $shared = liveMessage($second, function ($message) { return $message['type'] === 'status'; });
        assertSame($initial['snapshot']['revision'], $shared['snapshot']['revision']);

        $rows[0]['ip'] = '192.168.188.84';
        file_put_contents($state, json_encode($rows));
        $started = microtime(true);
        fwrite($ami, "Event: ContactStatus\r\nContactStatus: Reachable\r\n\r\n");
        foreach ([$subscriber, $second] as $client) {
            $changed = liveMessage($client, function ($message) {
                return $message['type'] === 'status' && $message['snapshot']['rows'][0]['ip'] === '192.168.188.84';
            });
            assertTrue($changed['snapshot']['revision'] !== $initial['snapshot']['revision']);
        }
        assertTrue(microtime(true) - $started < 2, 'AMI changes should be delivered without waiting for the recovery timer');

        $rows[0]['status'] = 'Apply Pending';
        file_put_contents($state, json_encode($rows));
        LiveStatus::notify($directory);
        $local = liveMessage($subscriber, function ($message) {
            return $message['type'] === 'status' && $message['snapshot']['rows'][0]['status'] === 'Apply Pending';
        });
        assertSame(true, $local['snapshot']['ami_connected']);

        fclose($ami);
        $ami = false;
        $disconnected = liveMessage($subscriber, function ($message) {
            return $message['type'] === 'status' && $message['snapshot']['ami_connected'] === false;
        });
        assertSame($rows, $disconnected['snapshot']['rows'], 'AMI loss must retain the current table snapshot');

        fclose($subscriber); $subscriber = false;
        fclose($second); $second = false;
        $deadline = microtime(true) + 4;
        do {
            $finished = pcntl_waitpid($pid, $status, WNOHANG);
            if ($finished === $pid) break;
            usleep(50000);
        } while (microtime(true) < $deadline);
        assertSame($pid, $finished, 'The on-demand broker must stop after subscribers leave');
        assertSame(0, pcntl_wexitstatus($status));
        $pid = null;
        assertTrue(!file_exists($directory . '/events.sock'));
    } finally {
        foreach ([$subscriber, $second, $ami, $amiServer] as $socket) if (is_resource($socket)) fclose($socket);
        if ($pid) { posix_kill($pid, SIGTERM); pcntl_waitpid($pid, $status); }
    }
});
