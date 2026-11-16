<?php
namespace FreePBX\modules\Provisioner;

// One cursor per access log for a wave, not per handset. Keep the open inode
// across rotation, advance on every read, and parse only complete lines.
final class FetchLogObserver {
    private $logs = [];
    private $filenames = [];
    private $results = [];
    private $parse;
    public function __construct($observations, $parse) {
        $this->parse = $parse;
        foreach ($observations as $mac => $observation) {
            $this->filenames[$observation['filename']] = $mac;
            foreach ($observation['logs'] as $path => $offset) {
                if (isset($this->logs[$path])) continue;
                $handle = @fopen($path, 'rb');
                if (!$handle) continue;
                $stat = fstat($handle);
                $identity = $stat['dev'] . ':' . $stat['ino'];
                $original = $observation['log_inodes'][$path] ?? $identity;
                $start = $identity === $original && $stat['size'] >= $offset ? $offset : 0;
                fseek($handle, $start);
                $this->logs[$path] = ['handle'=>$handle,'identity'=>$identity,'offset'=>$start,'partial'=>''];
            }
        }
    }
    private function readChunk(&$log) {
        // Clear EOF on a growing regular file before reading appended bytes.
        if (fseek($log['handle'], $log['offset']) !== 0) return;
        $chunk = stream_get_contents($log['handle'], 4 * 1024 * 1024);
        if ($chunk === false || $chunk === '') return;
        $log['offset'] += strlen($chunk);
        $lines = explode("\n", $log['partial'] . $chunk);
        $log['partial'] = array_pop($lines);
        // Bound memory for corrupt/non-log input; never parse an incomplete line.
        if (strlen($log['partial']) > 65536) $log['partial'] = '';
        foreach ($lines as $line) {
            if (!preg_match('/"(?:GET|HEAD)\s+[^"\s]*\/([^\/?\s"]+)(?:[?\s"])/i', $line, $match)) continue;
            $filename = $match[1];
            if (!isset($this->filenames[$filename])) continue;
            $mac = $this->filenames[$filename];
            if (isset($this->results[$mac])) continue;
            $result = ($this->parse)($line, $filename);
            if ($result !== null) $this->results[$mac] = $result;
        }
    }
    public function poll() {
        foreach ($this->logs as $path => &$log) {
            clearstatcache(true, $path);
            $stat = @stat($path);
            $identity = $stat ? $stat['dev'] . ':' . $stat['ino'] : null;
            if ($identity === $log['identity'] && $stat['size'] < $log['offset']) {
                fseek($log['handle'], 0); $log['offset'] = 0; $log['partial'] = '';
            }
            $this->readChunk($log);
            if ($identity !== null && $identity !== $log['identity'] && feof($log['handle'])) {
                fclose($log['handle']);
                $handle = @fopen($path, 'rb');
                if (!$handle) { unset($this->logs[$path]); continue; }
                $opened = fstat($handle);
                $log = ['handle'=>$handle,'identity'=>$opened['dev'].':'.$opened['ino'],'offset'=>0,'partial'=>''];
                $this->readChunk($log);
            }
        }
        unset($log);
        return $this->results;
    }
    public function __destruct() {
        foreach ($this->logs as $log) if (is_resource($log['handle'])) fclose($log['handle']);
    }
}
