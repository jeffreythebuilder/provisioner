<?php
// Only the FreePBX-managed CLI scheduler may run the durable queue.
if (PHP_SAPI !== 'cli') { http_response_code(403); exit; }
require '/etc/freepbx.conf';
set_time_limit(0);
$module = FreePBX::Provisioner();
$started = microtime(true);
try {
    // Bound one invocation; another cron tick picks up durable remaining work.
    for ($wave = 0; $wave < 20 && microtime(true)-$started < 240; $wave++) {
        if (!$module->processRolloutQueue()) break;
    }
} catch (Throwable $error) {
    error_log('Provisioner rollout worker: ' . $error->getMessage());
    exit(1);
}
