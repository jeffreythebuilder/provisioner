<?php
namespace FreePBX\modules\Provisioner;

final class DiagnosticSummary {
    private static function text($value) {
        if (!is_scalar($value) && $value !== null) return '—';
        $value = preg_replace('/[\r\n\t]+/', ' ', (string)$value);
        $value = preg_replace('/\b(password|secret|authorization|cookie|token|nonce)\s*[:=]\s*\S+/i', '$1=[redacted]', $value);
        $value = preg_replace('/\b[a-f0-9]{32,}\b/i', '[identifier redacted]', $value);
        $value = preg_replace_callback('#https?://[^\s<>]+#i', function ($match) {
            $url = parse_url($match[0]);
            if (!$url || isset($url['user']) || isset($url['pass']) || isset($url['query']) || isset($url['fragment'])) return '[URL redacted]';
            return $match[0];
        }, $value);
        return $value === '' ? '—' : substr($value, 0, 600);
    }

    public static function build($data) {
        // Deliberate allowlist: never dump the complete AJAX payload, config
        // contents, raw handset headers, descriptions, or worker error output.
        $device = $data['device'] ?? []; $target = $data['target'] ?? [];
        $push = $data['last_push'] ?? []; $config = $data['config'] ?? [];
        $service = $data['service'] ?? []; $job = $data['verification_job'] ?? [];
        $lines = ['Provisioner Diagnostic Summary',
            'Collected: ' . self::text($data['collected_at'] ?? null),
            'Module: ' . self::text($data['module_version'] ?? null) . ' | PHP: ' . self::text($data['php_version'] ?? null),
            'FreePBX/PBXact framework: ' . self::text($data['framework_version'] ?? null) . ' | Asterisk: ' . self::text($data['asterisk_version'] ?? null),
            'Extension: ' . self::text($device['extension'] ?? null) . ' | Previous extension: ' . self::text($device['previous_extension'] ?? null),
            'Brand: ' . self::text($device['brand'] ?? null) . ' | MAC: ' . self::text($device['mac'] ?? null),
            'Pending changes: ' . (!empty($device['needs_changes']) ? 'yes' : 'no'),
            'Target IP: ' . self::text($target['selected_ip'] ?? null) . ' | SIP port/transport: ' . self::text($target['selected_port'] ?? null) . '/' . self::text($target['selected_transport'] ?? null),
            'Target source: ' . self::text($target['selected_source'] ?? null) . ' | Confidence: ' . self::text($target['confidence'] ?? null),
            'Resolution mode: ' . self::text($target['resolution_mode'] ?? null) . ' | Registration: ' . self::text($target['pjsip_status'] ?? null),
            'Next action: ' . self::text($target['next_action'] ?? 'diagnostics'),
            'Blocked reason: ' . self::text($target['blocked_reason'] ?? $target['conflict_message'] ?? null),
            'Stored IP: ' . self::text($device['stored_ip'] ?? null) . ' | Configured static IP: ' . self::text($device['static_ip'] ?? null),
            'ARP check: ' . (!empty($target['scan_performed']) ? 'performed' : 'not run') . ' | Ranges: ' . self::text(implode(', ', $target['scan_subnets'] ?? [])),
            'Phone web interface: TCP ' . self::text($service['port'] ?? null) . ' ' . (!empty($service['reachable']) ? 'reachable' : 'not reachable') . ' (not a SIP health check)',
            'Config: ' . self::text($config['filename'] ?? null) . ' | Exists: ' . (!empty($config['exists']) ? 'yes' : 'no') . ' | Valid: ' . (!empty($config['valid']) ? 'yes' : 'no') . ' | Custom: ' . (!empty($config['custom']) ? 'yes' : 'no'),
            'Config bytes: ' . self::text($config['size'] ?? null) . ' | Modified: ' . self::text($config['modified'] ?? null),
            'Provisioning base URL: ' . self::text($data['provisioning_url'] ?? null),
            'Push status: ' . self::text($push['status'] ?? null) . ' | Attempt: ' . self::text($push['attempt'] ?? null),
            'Trigger accepted at: ' . self::text($push['ack'] ?? null),
            'Download: ' . self::text($push['fetch_status'] ?? null) . ' | Time: ' . self::text($push['fetch_at'] ?? null) . ' | Source IP: ' . self::text($push['fetch_ip'] ?? null),
            'Verification: ' . self::text($push['verify_status'] ?? null) . ' | Time: ' . self::text($push['verify_at'] ?? null) . ' | Verified IP: ' . self::text($push['verify_ip'] ?? null),
            'Background verification: ' . self::text($job['status'] ?? null) . ' | Worker error present: ' . (!empty($job['error']) ? 'yes (review locally)' : 'no'),
            'Target candidates:'];
        foreach (array_slice($target['candidates'] ?? [], 0, 20) as $candidate) {
            $lines[] = '- ' . self::text($candidate['source'] ?? null) . ': ' . self::text($candidate['ip'] ?? null)
                . ' | ' . (!empty($candidate['selected']) ? 'selected' : 'not selected') . ' | ' . (!empty($candidate['stale']) ? 'last-known' : 'current evidence')
                . ' | eligible=' . (($candidate['eligible'] ?? true) ? 'yes' : 'no') . ' | valid=' . (!empty($candidate['valid']) ? 'yes' : 'no')
                . ' | MAC evidence: ' . self::text($candidate['mac_evidence'] ?? null)
                . ' | Model/firmware: ' . self::text($candidate['model'] ?? null) . '/' . self::text($candidate['firmware'] ?? null)
                . ' | Identity source: ' . self::text($candidate['identity_source'] ?? null)
                . ' | Reason: ' . self::text($candidate['ignored_reason'] ?? null);
        }
        if (empty($target['candidates'])) $lines[] = '- None';
        $lines[] = 'Summary excludes credentials, config contents, raw headers, and callback tokens. Contains network/device identifiers; review before sharing.';
        return implode("\n", $lines);
    }
}
