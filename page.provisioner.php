<?php
$cssVersion = filemtime(__DIR__ . '/assets/css/provisioner.css');
$jsVersion = filemtime(__DIR__ . '/assets/js/provisioner.js');
$liveVersion = filemtime(__DIR__ . '/assets/js/live-status.js');
$discoveryVersion = filemtime(__DIR__ . '/assets/js/discovery.js');
echo '<link rel="stylesheet" href="/admin/modules/provisioner/assets/css/provisioner.css?v=' . $cssVersion . '">';
echo '<script src="/admin/modules/provisioner/assets/js/live-status.js?v=' . $liveVersion . '"></script>';
echo '<script src="/admin/modules/provisioner/assets/js/discovery.js?v=' . $discoveryVersion . '"></script>';
echo '<script src="/admin/modules/provisioner/assets/js/provisioner.js?v=' . $jsVersion . '"></script>';
echo FreePBX::Provisioner()->showPage();
