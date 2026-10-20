<?php

// Background verification is a CLI worker, independent of the browser.
if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    exit;
}
$jobId = $argv[1] ?? '';
if (!preg_match('/^[a-f0-9]{32}$/', $jobId)) exit(1);
require '/etc/freepbx.conf';
set_time_limit(150);
$module = FreePBX::Provisioner();
exit($module->processPushVerificationJob($jobId) === false ? 1 : 0);
