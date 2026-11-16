<?php
namespace FreePBX\modules\Provisioner;

final class CallbackDiagnostics {
    public static function assess($status, $body, $marker, $challenge, $networkError = false) {
        if ($status === 404 && $body === "Not Found\n" && hash_equals($challenge, $marker)) {
            return ['success' => true, 'code' => 'callback_accessible', 'http_status' => 404,
                'message' => 'PBX callback self-test passed (expected HTTP 404).',
                'next_step' => 'This tests PBX-to-PBX HTTP access only. Handsets must also reach this address and port.'];
        }
        if ($status === 403) {
            $code = 'callback_forbidden';
            $message = 'The PBX callback is blocked by HTTP 403 before identity can be accepted.';
            $next = 'Reinstall the current Provisioner package through FreePBX Module Admin. Check the module access rule and Apache access/error logs; do not broaden core FreePBX access rules.';
        } elseif ($status === 401) {
            $code = 'callback_authentication'; $message = 'The PBX callback requires HTTP authentication (401).';
            $next = 'Check web-server authentication for the identity receiver; phones cannot use your FreePBX browser session.';
        } elseif ($status >= 300 && $status < 400) {
            $code = 'callback_redirect'; $message = 'The PBX callback redirects HTTP requests (' . $status . ').';
            $next = 'Check the configured PBX IP, provisioning HTTP port, virtual host, and redirects. The callback must reach the receiver directly.';
        } elseif ($status === 0 || $networkError) {
            $code = 'callback_unreachable'; $message = 'The PBX could not complete its callback self-test.';
            $next = 'Check PBX IP, provisioning HTTP port, web service, firewall, and local routing. A PBX self-test does not test the handset network path.';
        } else {
            $code = 'callback_unexpected'; $message = 'The PBX callback did not return the expected receiver response (HTTP ' . $status . ').';
            $next = 'Check the installed receiver, PHP execution, virtual host, and Apache/PHP logs. A generic 404 page is not a successful receiver test.';
        }
        return ['success' => false, 'code' => $code, 'http_status' => $status, 'message' => $message, 'next_step' => $next];
    }

    public static function check($ip, $port) {
        if (!filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4) || !preg_match('/^[0-9]{1,5}$/D', (string)$port) || (int)$port < 1 || (int)$port > 65535) {
            return ['success' => false, 'code' => 'callback_configuration', 'http_status' => 0,
                'message' => 'Configure a valid PBX IPv4 address and provisioning HTTP port.', 'next_step' => 'Review Provisioner Settings before identifying phones.'];
        }
        if (!function_exists('curl_init')) return ['success' => false, 'code' => 'callback_test_unavailable', 'http_status' => 0,
            'message' => 'PHP cURL is required for the callback self-test.', 'next_step' => 'Enable PHP cURL for the FreePBX web/CLI PHP runtime.'];
        $base = 'http://' . $ip . ':' . (int)$port . '/admin/modules/provisioner/discovery_receiver.php';
        $challenge = bin2hex(random_bytes(16));
        $curl = curl_init($base . '/self-test/' . $challenge);
        $body = ''; $marker = '';
        curl_setopt_array($curl, [CURLOPT_CONNECTTIMEOUT => 2, CURLOPT_TIMEOUT => 4,
            CURLOPT_FOLLOWLOCATION => false, CURLOPT_PROTOCOLS => CURLPROTO_HTTP, CURLOPT_PROXY => '',
            CURLOPT_USERAGENT => 'Provisioner-Callback-Self-Test',
            CURLOPT_WRITEFUNCTION => function ($handle, $chunk) use (&$body) {
                if (strlen($body) + strlen($chunk) > 4096) return 0;
                $body .= $chunk; return strlen($chunk);
            },
            CURLOPT_HEADERFUNCTION => function ($handle, $line) use (&$marker) {
                if (stripos($line, 'X-Provisioner-Receiver:') === 0) $marker = trim(substr($line, strlen('X-Provisioner-Receiver:')));
                return strlen($line);
            }]);
        $ok = curl_exec($curl);
        $status = (int)curl_getinfo($curl, CURLINFO_HTTP_CODE);
        curl_close($curl);
        return self::assess($status, $body, $marker, $challenge, $ok === false) + ['callback_url' => $base];
    }
}
