<?php

if (PHP_SAPI !== 'cli') { http_response_code(403); exit; }
$id = $argv[1] ?? '';
if (!preg_match('/^[a-f0-9]{32}$/', $id)) exit(1);
set_time_limit(200);
require_once __DIR__ . '/lib/SipDiscovery.php';
try {
    require '/etc/freepbx.conf';
    exit(FreePBX::Provisioner()->processDiscoveryJob($id) ? 0 : 1);
} catch (Throwable $error) {
    $store = new \FreePBX\modules\Provisioner\SipDiscovery();
    $job = $store->load($id);
    if ($job) {
        $job['status'] = 'failed';
        $job['error'] = 'Discovery worker could not initialize the PBX.';
        $store->save($job);
    }
    exit(1);
}
