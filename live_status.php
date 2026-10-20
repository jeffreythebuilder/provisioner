<?php

if (PHP_SAPI !== 'cli') { http_response_code(403); exit; }
$bootstrap_settings['skip_astman'] = true;
require '/etc/freepbx.conf';
require_once __DIR__ . '/Provisioner.class.php';
set_time_limit(0);
$module = new FreePBX\modules\Provisioner(FreePBX::create());
$config = FreePBX::Config();
$listener = new FreePBX\modules\Provisioner\LiveStatus($module);
exit($listener->run([
    'host' => $config->get('ASTMANAGERHOST'),
    'port' => $config->get('ASTMANAGERPORT'),
    'username' => $config->get('AMPMGRUSER'),
    'secret' => $config->get('AMPMGRPASS')
]) ? 0 : 1);
