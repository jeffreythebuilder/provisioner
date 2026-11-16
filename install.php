<?php
global $db;
require_once __DIR__ . '/lib/ActiveArp.php';
\FreePBX\modules\Provisioner\ActiveArp::install();
require_once __DIR__ . '/lib/PushJobSchema.php';
\FreePBX\modules\Provisioner\PushJobSchema::install(\FreePBX::Database());
require_once __DIR__ . '/lib/RolloutSchema.php';
\FreePBX\modules\Provisioner\RolloutSchema::install(\FreePBX::Database());
\FreePBX\modules\Provisioner\RolloutSchema::schedule(\FreePBX::Cron(), __DIR__);
require_once __DIR__ . '/lib/ReceiverAccess.php';
\FreePBX\modules\Provisioner\ReceiverAccess::install(__DIR__);
if (\FreePBX::Database()->exec("DELETE FROM provisioner_settings WHERE key_name='force_push_without_mac'") === false) {
    throw new \RuntimeException('Could not remove the retired force-push setting.');
}
// FreePBX Module Admin owns registration/version/enabled state. In particular,
// FreePBX 16 inserts its row AFTER a fresh-install hook. Never pre-insert one.
// Repair duplicates from older hooks only, retaining a row for core to update.
$connection=\FreePBX::Database();
$count=(int)$connection->query("SELECT COUNT(*) FROM modules WHERE modulename='provisioner'")->fetchColumn();
if ($count>1 && $connection->exec("DELETE FROM modules WHERE modulename='provisioner' LIMIT ".($count-1))===false) {
    throw new \RuntimeException('Could not consolidate duplicate Provisioner module registration.');
}
