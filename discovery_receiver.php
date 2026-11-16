<?php

// Handsets get only a missing-file response. This endpoint never loads or serves a config.
ini_set('display_errors', '0');
http_response_code(404);
header('Content-Type: text/plain; charset=utf-8');
header('Cache-Control: no-store');
try {
    // Random challenge proves PHP handled this exact request, not a generic
    // Apache 404. No job is created and no identity evidence is recorded.
    if (($_SERVER['REQUEST_METHOD'] ?? '') === 'GET'
        && preg_match('#^/self-test/([a-f0-9]{32})$#D', $_SERVER['PATH_INFO'] ?? '', $test)) {
        header('X-Provisioner-Receiver: ' . $test[1]);
        echo "Not Found\n";
        return;
    }
    require_once __DIR__ . '/lib/SipDiscovery.php';
    if (($_SERVER['REQUEST_METHOD'] ?? '') === 'GET'
        && preg_match('#^/([a-f0-9]{32})/([a-f0-9]{32})/([a-z0-9._-]{1,100})$#i', $_SERVER['PATH_INFO'] ?? '', $parts)) {
        $store = new \FreePBX\modules\Provisioner\SipDiscovery();
        $store->receive(strtolower($parts[1]), strtolower($parts[2]), $_SERVER['REMOTE_ADDR'] ?? '', $_SERVER['HTTP_USER_AGENT'] ?? '', $parts[3]);
    }
} catch (\Throwable $error) {
    // A receiver failure still cannot turn into a provisioning response.
}
echo "Not Found\n";
