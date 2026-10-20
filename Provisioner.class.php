<?php

namespace FreePBX\modules;

require_once __DIR__ . '/lib/LiveStatus.php';
require_once __DIR__ . '/lib/SipDiscovery.php';
require_once __DIR__ . '/lib/BulkWorkflow.php';

class Provisioner implements \BMO {
    use \FreePBX\modules\Provisioner\BulkWorkflow;
    private $db;
    private $sipTransportCache;
    private $python3BinaryCache = false;

    private static $ouiMap = [
        '0004f3' => 'digium',
        '000fd3' => 'digium',
        '00809f' => 'alcatel',
        '08005a' => 'alcatel',
        '001c0e' => 'alcatel',
        '00236a' => 'alcatel',
        '3c28a6' => 'alcatel',
        '00037f' => 'yealink',
        '001565' => 'yealink',
        '346671' => 'yealink',
        '5c116f' => 'yealink',
        '805ec0' => 'yealink',
        'c098e5' => 'yealink',
        'f40e22' => 'yealink',
        'fc0fe6' => 'yealink',
        '000c41' => 'polycom',
        '00904f' => 'polycom',
        '000e53' => 'snom',
        '000413' => 'cisco',
        '001681' => 'grandstream',
    ];

    public function getBrandByMac($mac) {
        $prefix = substr(strtolower(preg_replace('/[^a-f0-9]/i', '', $mac)), 0, 6);
        return self::$ouiMap[$prefix] ?? null;
    }

    public function getBrands() {
        $dirs = glob(__DIR__ . '/templates/*', GLOB_ONLYDIR);
        $brands = [];
        foreach ($dirs as $dir) {
            $name = basename($dir);
            if (file_exists("$dir/default.cfg")) $brands[] = $name;
        }
        sort($brands);
        return $brands;
    }

    private function getSupportedDiscoveryBrand($mac) {
        $brand = $this->getBrandByMac($mac);
        if (!in_array($brand, ['digium', 'alcatel'], true) || !in_array($brand, $this->getBrands(), true)) return null;
        return method_exists($this, 'generate' . ucfirst($brand) . 'Config') ? $brand : null;
    }

    public function getExtensions() {
        if (!class_exists('FreePBX') || !\FreePBX::Core()) return [];
        $users = \FreePBX::Core()->listUsers();
        $exts = [];
        foreach ($users as $u) {
            $exts[] = ['extension' => $u[0], 'name' => $u[1]];
        }
        return $exts;
    }

    public function autoAssignExtensions() {
        $stmt = $this->db->query("SELECT id, mac_address, extension FROM provisioner_devices ORDER BY mac_address ASC");
        $devices = $stmt->fetchAll(\PDO::FETCH_ASSOC);
        $assigned = array_column($devices, 'extension');
        $assigned = array_filter($assigned, fn($v) => $v !== '');

        if (!class_exists('FreePBX') || !\FreePBX::Core()) {
            return ['success' => false, 'error' => 'Cannot access FreePBX extensions'];
        }
        $users = \FreePBX::Core()->listUsers();
        $allExts = array_map(fn($u) => $u[0], $users);
        sort($allExts, SORT_NUMERIC);
        $freeExts = array_values(array_diff($allExts, $assigned));

        $assignments = [];
        foreach ($devices as $d) {
            if (empty($d['extension'])) {
                $ext = array_shift($freeExts);
                if ($ext === null) break;
                $assignments[] = ['id' => $d['id'], 'mac' => $d['mac_address'], 'extension' => $ext];
            }
        }

        if (!empty($_REQUEST['save'])) {
            $now = date('Y-m-d H:i:s');
            foreach ($assignments as $a) {
                $stmt = $this->db->prepare("UPDATE provisioner_devices SET extension=?, needs_changes=1, updated_at=? WHERE id=?");
                $stmt->execute([$a['extension'], $now, $a['id']]);
            }
            $this->addLog('device', "Auto-assigned " . count($assignments) . " extension(s)", 'success');
            $deployMode = $this->getSetting('deploy_mode') ?: 'dhcp';
            return ['success' => true, 'assignments' => $assignments, 'deploy_mode' => $deployMode];
        }

        return ['success' => true, 'assignments' => $assignments];
    }

    public function exportCsv($usePjsipIp = false, $contacts = null) {
        $usePjsipIp = (bool)$usePjsipIp;
        if ($usePjsipIp && $contacts === null) $contacts = $this->getPjsipContacts();
        $contacts = is_array($contacts) ? $contacts : [];
        $rows = $this->db->query("SELECT extension, previous_extension, needs_changes, mac_address, brand, description, static_ip, static_mask, static_gw FROM provisioner_devices ORDER BY extension ASC");
        $csv = "Extension,MAC,Brand,Description,Static IP,Subnet Mask,Gateway\n";
        $pjsipUsed = 0;
        while ($row = $rows->fetch(\PDO::FETCH_ASSOC)) {
            $exportIp = $row['static_ip'] ?? '';
            if ($usePjsipIp) {
                $current = (string)($row['extension'] ?? '');
                $previous = (string)($row['previous_extension'] ?? '');
                $extensionOrder = !empty($row['needs_changes']) && $previous !== '' && $previous !== $current
                    ? [$previous, $current] : [$current, $previous];
                foreach (array_values(array_unique(array_filter($extensionOrder, 'strlen'))) as $extension) {
                    $contact = $contacts[$extension] ?? null;
                    if (!$contact || strcasecmp((string)($contact['status'] ?? ''), 'Avail') !== 0) continue;
                    $contactIp = (string)($contact['ip'] ?? '');
                    if (!filter_var($contactIp, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4) || !$this->isPrivateIp($contactIp)) continue;
                    $exportIp = $contactIp;
                    $pjsipUsed++;
                    break;
                }
            }
            $csv .= '"' . $row['extension'] . '","' . $row['mac_address'] . '","' . ($row['brand'] ?: 'digium') . '","' . str_replace('"', '""', $row['description'] ?? '') . '","' . $exportIp . '","' . ($row['static_mask'] ?? '') . '","' . ($row['static_gw'] ?? '') . '"' . "\n";
        }
        return [
            'success' => true,
            'csv' => $csv,
            'pjsip_used' => $pjsipUsed,
            'filename' => $usePjsipIp ? 'provisioner_devices-pjsip-static.csv' : 'provisioner_devices.csv'
        ];
    }

    public function __construct($freepbx = null) {
        if ($freepbx) {
            $this->db = $freepbx->Database();
            $this->ensureBatchTables();
            // Existing installs may predate the static-value revert columns.
            $this->ensurePreviousStaticColumns();
            $this->db->exec("CREATE TABLE IF NOT EXISTS provisioner_push_jobs (id VARCHAR(32) PRIMARY KEY, status VARCHAR(20) NOT NULL, payload TEXT NOT NULL, created_at DATETIME NOT NULL, finished_at DATETIME DEFAULT NULL, error TEXT DEFAULT NULL)");
            $this->db->exec("CREATE TABLE IF NOT EXISTS provisioner_discovery_observations (mac_address VARCHAR(12) PRIMARY KEY, ip_address VARCHAR(45) NOT NULL UNIQUE, brand VARCHAR(50) NOT NULL, model VARCHAR(100), firmware VARCHAR(100), sip_port INTEGER DEFAULT 5060, sip_transport VARCHAR(10) DEFAULT 'udp', source VARCHAR(100), last_seen DATETIME NOT NULL)");
            if (!in_array('sip_transport', $this->getTableColumns('provisioner_discovery_observations'), true)) {
                $this->db->exec("ALTER TABLE provisioner_discovery_observations ADD COLUMN sip_transport VARCHAR(10) DEFAULT 'udp'");
            }
        }
    }

    public function install() {}
    public function uninstall() {}
    public function backup() {}
    public function restore($backup) {}
    public function doConfigPageInit($page) {}

    public function getSetting($key) {
        $stmt = $this->db->prepare("SELECT value FROM provisioner_settings WHERE key_name=?");
        $stmt->execute([$key]);
        $row = $stmt->fetch(\PDO::FETCH_ASSOC);
        return $row ? $row['value'] : null;
    }

    public function setSetting($key, $value) {
        $stmt = $this->db->prepare("INSERT INTO provisioner_settings (key_name, value) VALUES (?, ?) ON DUPLICATE KEY UPDATE value=?");
        $stmt->execute([$key, $value, $value]);
    }

    public function getSettings() {
        $stmt = $this->db->query("SELECT key_name, value FROM provisioner_settings");
        $settings = [];
        while ($row = $stmt->fetch(\PDO::FETCH_ASSOC)) {
            $settings[$row['key_name']] = $row['value'];
        }
        return $settings;
    }

    public function saveSettings($data) {
        if (array_key_exists('scan_subnets', $data)) {
            $scanSubnets = $this->normalizeScanSubnets($data['scan_subnets']);
            if ($scanSubnets === null) {
                return ['success' => false, 'error' => 'Scan Subnets must contain valid IPv4 CIDR ranges separated by commas.'];
            }
            $data['scan_subnets'] = implode(', ', $scanSubnets);
        }
        $keys = ['pbx_ip', 'sip_port', 'transport', 'prov_dir', 'scan_subnets', 'phone_admin_pass', 'phone_admin_pass_alcatel', 'deploy_mode', 'vlan_id', 'vlan_data_id', 'dhcp_leases_file', 'digium_phonebook_name', 'digium_phonebook_url', 'digium_phonebook_username', 'digium_phonebook_password', 'alcatel_phonebook_name', 'alcatel_phonebook_url', 'alcatel_phonebook_username', 'alcatel_phonebook_password'];
        foreach ($keys as $key) {
            if (isset($data[$key])) {
                $this->setSetting($key, $data[$key]);
            }
        }
        $this->addLog('settings', 'Settings updated', 'success');
        return ['success' => true];
    }

    private function normalizeScanSubnets($value, $maxPrefix = 30) {
        $value = trim((string)$value);
        if ($value === '') return [];
        $subnets = [];
        foreach (preg_split('/\s*,\s*/', $value) as $subnet) {
            if (!preg_match('/^(\d+\.\d+\.\d+\.\d+)\/(\d{1,2})$/', trim($subnet), $m)) return null;
            $prefix = (int)$m[2];
            $ipLong = ip2long($m[1]);
            if ($ipLong === false || $prefix < 8 || $prefix > $maxPrefix) return null;
            $mask = -1 << (32 - $prefix);
            $subnets[] = long2ip($ipLong & $mask) . '/' . $prefix;
        }
        return array_values(array_unique($subnets));
    }

    public function getSipTransportInfo() {
        if ($this->sipTransportCache !== null) return $this->sipTransportCache;
        $output = shell_exec('asterisk -rx "pjsip show transports" 2>/dev/null');
        $all = [];
        if ($output) {
            foreach (explode("\n", $output) as $line) {
                if (!preg_match('/^\s*Transport:\s+(\S+)\s+(udp|tcp|tls)\s+\S+\s+\S+\s+(\S+)\s*$/i', $line, $m)) continue;
                $bind = $m[3];
                if (!preg_match('/^(?:\[([^\]]+)\]|(.+)):(\d+)$/', $bind, $address)) continue;
                $protocol = strtoupper($m[2]);
                $all[$protocol][] = [
                    'id' => $m[1],
                    'protocol' => $protocol,
                    'bind_address' => $address[1] !== '' ? $address[1] : $address[2],
                    'port' => (int)$address[3]
                ];
            }
        }

        $pbxIp = $this->getSetting('pbx_ip') ?: '';
        $selected = [];
        foreach ($all as $protocol => $transports) {
            usort($transports, function ($a, $b) use ($pbxIp) {
                $rank = function ($transport) use ($pbxIp) {
                    if ($transport['bind_address'] === '0.0.0.0' || $transport['bind_address'] === '::') return 0;
                    if ($pbxIp && $transport['bind_address'] === $pbxIp) return 1;
                    return 2;
                };
                return $rank($a) <=> $rank($b);
            });
            $selected[$protocol] = $transports[0];
            $all[$protocol] = $transports;
        }

        $this->sipTransportCache = [
            'success' => !empty($selected),
            'source' => !empty($selected) ? 'Asterisk runtime' : 'Provisioner fallback',
            'transports' => $selected,
            'all' => $all,
            'fallback_port' => (int)($this->getSetting('sip_port') ?: 5060)
        ];
        return $this->sipTransportCache;
    }

    private function getDetectedSipPort($transport) {
        $protocol = strtoupper((string)$transport);
        $info = $this->getSipTransportInfo();
        if (!empty($info['transports'][$protocol]['port'])) return (string)$info['transports'][$protocol]['port'];
        return (string)($this->getSetting('sip_port') ?: '5060');
    }

    private function findPython3Binary($candidates = null) {
        $useCache = $candidates === null;
        if ($useCache && $this->python3BinaryCache !== false) return $this->python3BinaryCache;

        if ($candidates === null) {
            $candidates = [
                '/usr/bin/python3',
                '/usr/local/bin/python3',
                '/usr/bin/python3.6',
                '/usr/local/bin/python3.6'
            ];
            foreach (['python3', 'python3.6'] as $command) {
                $path = trim((string)shell_exec('command -v ' . $command . ' 2>/dev/null'));
                if ($path !== '') $candidates[] = $path;
            }
        }

        foreach (array_unique($candidates) as $candidate) {
            if (!is_string($candidate) || !is_executable($candidate)) continue;
            $check = escapeshellarg($candidate) . ' -c ' . escapeshellarg('import sys; print(sys.version_info[0])') . ' 2>/dev/null';
            if (trim((string)shell_exec($check)) === '3') {
                if ($useCache) $this->python3BinaryCache = $candidate;
                return $candidate;
            }
        }

        if ($useCache) $this->python3BinaryCache = null;
        return null;
    }

    // =====================
    // PHONE STATUS
    // =====================
    private function isPhoneReachable($ip) {
        $arp = @file_get_contents('/proc/net/arp');
        if (!$arp) return false;
        foreach (explode("\n", $arp) as $line) {
            $parts = preg_split('/\s+/', trim($line));
            if (isset($parts[0]) && $parts[0] === $ip && isset($parts[3]) && $parts[3] === '0x2') {
                return true;
            }
        }
        return false;
    }

    private function isPrivateIp($ip) {
        if (!filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4)) return false;
        $parts = explode('.', $ip);
        $first = (int)$parts[0];
        $second = (int)$parts[1];
        if ($first === 10) return true;
        if ($first === 192 && $second === 168) return true;
        if ($first === 172 && $second >= 16 && $second <= 31) return true;
        if ($ip === '127.0.0.1') return true;
        return false;
    }

    private function getPjsipContacts($output = null, $withIdentity = false, $registrarOutput = null) {
        if ($output === null) {
            $output = shell_exec('asterisk -rx "pjsip show contacts" 2>/dev/null');
        }
        $contacts = [];
        $hashes = [];
        if (!$output) return $contacts;
        foreach (explode("\n", $output) as $line) {
            if (!preg_match('/^\s*Contact:\s+([^\/\s]+)\/sip:[^@\s]+@(\d+\.\d+\.\d+\.\d+)/i', $line, $m)) continue;
            $status = preg_match('/\b(Avail|Unavail)\b/i', $line, $state) ? ucfirst(strtolower($state[1])) : 'Unknown';
            $contacts[$m[1]] = [
                'ip' => $m[2],
                'status' => $status
            ];
            if ($withIdentity && preg_match('/^\s*Contact:\s+\S+\s+([a-f0-9]{10,32})\s+/i', $line, $hash)) {
                $hashes[$m[1]] = strtolower($hash[1]);
            }
        }
        if ($withIdentity) {
            if ($registrarOutput === null) {
                $registrarOutput = shell_exec('asterisk -rx "database show registrar/contact" 2>/dev/null');
            }
            foreach (explode("\n", (string)$registrarOutput) as $line) {
                if (!preg_match('/^\s*\/registrar\/contact\/(.+);@([a-f0-9]{32}):\s*(\{.*\})\s*$/i', $line, $record)) continue;
                $data = json_decode($record[3], true);
                if (!is_array($data)) continue;
                $extension = (string)($data['endpoint'] ?? $record[1]);
                if (empty($contacts[$extension]) || empty($hashes[$extension])
                    || strpos(strtolower($record[2]), $hashes[$extension]) !== 0) continue;
                if (!preg_match('/^sip:[^@\s]+@(\d+\.\d+\.\d+\.\d+)/i', $data['uri'] ?? '', $uri)
                    || $uri[1] !== $contacts[$extension]['ip']) continue;
                $userAgent = (string)($data['user_agent'] ?? '');
                $contacts[$extension]['user_agent'] = $userAgent;
                if (preg_match('/(?<![a-f0-9])([a-f0-9]{12}|(?:[a-f0-9]{2}[:-]){5}[a-f0-9]{2})(?![a-f0-9])/i', $userAgent, $identity)) {
                    $contacts[$extension]['mac'] = strtolower(preg_replace('/[^a-f0-9]/i', '', $identity[1]));
                }
            }
        }
        return $contacts;
    }

    private function getCurrentArpMap() {
        $result = [];
        $arp = @file('/proc/net/arp');
        if (!$arp) return $result;
        foreach ($arp as $line) {
            $parts = preg_split('/\s+/', trim($line));
            if (count($parts) < 4 || $parts[2] !== '0x2') continue;
            $mac = strtolower(str_replace(':', '', $parts[3]));
            if (strlen($mac) === 12) $result[$mac] = $parts[0];
        }
        return $result;
    }

    private function getDhcpLeaseMap() {
        $result = [];
        if (($this->getSetting('deploy_mode') ?: 'dhcp') !== 'dhcp') return $result;
        $path = $this->getSetting('dhcp_leases_file') ?: '/var/lib/misc/dnsmasq.leases';
        $lines = @file($path, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
        if (!$lines) return $result;
        foreach ($lines as $line) {
            $parts = preg_split('/\s+/', trim($line));
            if (count($parts) < 3) continue;
            $mac = strtolower(preg_replace('/[^a-f0-9]/i', '', $parts[1]));
            if (strlen($mac) === 12 && filter_var($parts[2], FILTER_VALIDATE_IP, FILTER_FLAG_IPV4)) {
                $result[$mac] = $parts[2];
            }
        }
        return $result;
    }

    private function resolveDeviceTarget($device, $contacts = null, $arpMap = null, $dhcpMap = null, $arpFresh = false, $localSubnets = null, $discoveryMap = null) {
        $contacts = is_array($contacts) ? $contacts : $this->getPjsipContacts(null, true);
        $arpMap = is_array($arpMap) ? $arpMap : $this->getCurrentArpMap();
        $dhcpMap = is_array($dhcpMap) ? $dhcpMap : $this->getDhcpLeaseMap();
        $localSubnets = is_array($localSubnets) ? $localSubnets : $this->getArpCapableSubnets();
        $discoveryMap = is_array($discoveryMap) ? $discoveryMap : $this->getDiscoveryObservations();
        $mac = strtolower(preg_replace('/[^a-f0-9]/i', '', $device['mac_address'] ?? ''));
        $discovered = $discoveryMap[$mac] ?? null;
        $discoveryCandidate = $discovered ? [
            'source' => 'discovery', 'label' => 'MAC identity discovery (' . $discovered['last_seen'] . ')',
            'ip' => $discovered['ip_address'], 'mac' => $mac, 'port' => (int)$discovered['sip_port'],
            'identity_source' => $discovered['source'], 'model' => $discovered['model'] ?? '', 'firmware' => $discovered['firmware'] ?? '',
            'stale' => strtotime($discovered['last_seen']) < time() - \FreePBX\modules\Provisioner\SipDiscovery::IDENTITY_MAX_AGE
        ] : null;
        $currentExtension = (string)($device['extension'] ?? '');
        $previousExtension = (string)($device['previous_extension'] ?? '');
        $extensionOrder = !empty($device['needs_changes']) && $previousExtension !== '' && $previousExtension !== $currentExtension
            ? [$previousExtension, $currentExtension]
            : [$currentExtension, $previousExtension];
        $extensions = array_values(array_unique(array_filter($extensionOrder, 'strlen')));
        $pjsipCandidate = null;
        foreach ($extensions as $extension) {
            if (!empty($contacts[$extension]['ip'])) {
                if (!empty($contacts[$extension]['mac']) && $contacts[$extension]['mac'] !== $mac) continue;
                $pjsipCandidate = [
                    'source' => 'pjsip',
                    'label' => 'PJSIP contact (' . $extension . ')',
                    'ip' => $contacts[$extension]['ip'],
                    'mac' => $contacts[$extension]['mac'] ?? null,
                    'status' => $contacts[$extension]['status'] ?? 'Unknown',
                    'stale' => false
                ];
                break;
            }
        }
        $arpCandidate = !empty($arpMap[$mac]) ? [
            'source' => 'arp',
            'label' => $arpFresh ? 'Fresh ARP (active sweep)' : 'ARP cache',
            'ip' => $arpMap[$mac],
            'stale' => !$arpFresh
        ] : null;
        $conflict = $arpFresh && $arpCandidate && $pjsipCandidate && $arpCandidate['ip'] !== $pjsipCandidate['ip'];

        $raw = [];
        if ($arpFresh && $arpCandidate) $raw[] = $arpCandidate;
        if ($discoveryCandidate && !$discoveryCandidate['stale'] && empty($pjsipCandidate['mac'])) $raw[] = $discoveryCandidate;
        if ($pjsipCandidate) $raw[] = $pjsipCandidate;
        if ($discoveryCandidate && !$discoveryCandidate['stale'] && !empty($pjsipCandidate['mac'])) $raw[] = $discoveryCandidate;
        if (!empty($device['needs_changes'])) {
            if (!empty($device['ip_address'])) $raw[] = ['source' => 'stored', 'label' => 'Stored last-known IP', 'ip' => $device['ip_address'], 'stale' => true];
            if (!empty($device['previous_static_ip'])) $raw[] = ['source' => 'previous_static_ip', 'label' => 'Previous static IP', 'ip' => $device['previous_static_ip'], 'stale' => true];
        }
        if (!empty($device['static_ip'])) $raw[] = ['source' => 'static_ip', 'label' => 'Static IP', 'ip' => $device['static_ip'], 'stale' => false];
        if (!empty($dhcpMap[$mac])) {
            $dhcpLocal = $this->ipInSubnets($dhcpMap[$mac], $localSubnets);
            $raw[] = [
                'source' => 'dhcp',
                'label' => 'DHCP lease',
                'ip' => $dhcpMap[$mac],
                'stale' => false,
                'eligible' => $dhcpLocal,
                'ignored_reason' => $dhcpLocal ? null : 'Lease is not on a directly connected subnet'
            ];
        }
        if (!$arpFresh && $arpCandidate) $raw[] = $arpCandidate;
        if ($discoveryCandidate && $discoveryCandidate['stale']) $raw[] = $discoveryCandidate;
        if (!empty($device['ip_address'])) $raw[] = ['source' => 'stored', 'label' => 'Stored last-known IP', 'ip' => $device['ip_address'], 'stale' => true];

        $candidates = [];
        $selectedIp = null;
        $selectedSource = null;
        $macConfirmationRequired = false;
        $blockedReason = null;
        foreach ($raw as $candidate) {
            $candidate['valid'] = $this->isPrivateIp($candidate['ip']);
            if (!isset($candidate['eligible'])) $candidate['eligible'] = true;
            foreach ($contacts as $contact) {
                if (($contact['ip'] ?? null) === $candidate['ip'] && !empty($contact['mac']) && $contact['mac'] !== $mac) {
                    $candidate['eligible'] = false;
                    $candidate['ignored_reason'] = 'IP is registered by a different handset MAC';
                }
            }
            if (empty($candidate['mac']) && !($candidate['source'] === 'arp' && $arpFresh)) {
                foreach ($discoveryMap as $ownerMac => $observation) {
                    if ($ownerMac !== $mac && $observation['ip_address'] === $candidate['ip']
                        && strtotime($observation['last_seen']) >= time() - \FreePBX\modules\Provisioner\SipDiscovery::IDENTITY_MAX_AGE) {
                        $candidate['eligible'] = false;
                        $candidate['ignored_reason'] = 'IP was recently identified as a different handset MAC';
                    }
                }
            }
            if ($candidate['valid'] && !$this->ipInSubnets($candidate['ip'], $localSubnets)) {
                $candidate['mac_evidence'] = $this->getCrossSubnetMacEvidence($mac, $candidate['ip'], $contacts, $discoveryMap);
                if (!$candidate['mac_evidence']) {
                    $candidate['eligible'] = false;
                    $candidate['ignored_reason'] = $candidate['ignored_reason'] ?? 'Cross-subnet push needs a matching handset MAC/IP identity. Run Scan Network and, if needed, Identify MAC (identity is valid for 15 minutes).';
                    $macConfirmationRequired = true;
                    $blockedReason = $candidate['ignored_reason'];
                }
            }
            if ($candidate['valid'] && !$candidate['eligible'] && !empty($candidate['ignored_reason']) && $blockedReason === null) {
                $blockedReason = $candidate['ignored_reason'];
            }
            $candidate['selected'] = false;
            if (!$conflict && $selectedIp === null && $candidate['valid'] && $candidate['eligible']) {
                $selectedIp = $candidate['ip'];
                $selectedSource = $candidate['source'];
                $candidate['selected'] = true;
            }
            $candidates[] = $candidate;
        }

        $confidence = 'unresolved';
        if ($conflict) {
            $confidence = 'conflict';
        } elseif ($selectedSource === 'arp' && $pjsipCandidate && $selectedIp === $pjsipCandidate['ip']) {
            $selectedSource = 'arp_pjsip';
            $confidence = 'verified';
            foreach ($candidates as &$candidate) {
                if ($candidate['ip'] === $selectedIp && in_array($candidate['source'], ['arp', 'pjsip'], true)) $candidate['selected'] = true;
            }
            unset($candidate);
        } elseif ($selectedSource === 'arp') {
            $confidence = $arpFresh ? 'mac_verified' : 'arp_cached';
        } elseif ($selectedSource === 'pjsip') {
            $confidence = 'cross_subnet';
        } elseif ($selectedSource === 'discovery') {
            $confidence = $discoveryCandidate['stale'] ? 'stale' : 'mac_identified';
        } elseif (in_array($selectedSource, ['static_ip', 'dhcp'], true)) {
            $confidence = 'configured';
        } elseif (in_array($selectedSource, ['stored', 'previous_static_ip'], true)) {
            $confidence = 'stale';
        }

        return [
            'selected_ip' => $selectedIp,
            'selected_source' => $selectedSource,
            'selected_port' => $discovered && $discovered['ip_address'] === $selectedIp ? (int)$discovered['sip_port'] : 5060,
            'selected_transport' => $discovered && $discovered['ip_address'] === $selectedIp ? ($discovered['sip_transport'] ?? 'udp') : 'udp',
            'mac_confirmation_required' => $selectedIp === null && $macConfirmationRequired,
            'blocked_reason' => $selectedIp === null ? $blockedReason : null,
            'is_stale_fallback' => in_array($selectedSource, ['stored', 'previous_static_ip'], true) || ($selectedSource === 'discovery' && $discoveryCandidate['stale']),
            'confidence' => $confidence,
            'conflict' => $conflict,
            'conflict_message' => $conflict ? 'Fresh ARP maps the configured MAC to ' . $arpCandidate['ip'] . ' but PJSIP maps extension ' . ($extensions[0] ?? '') . ' to ' . $pjsipCandidate['ip'] . '.' : null,
            'candidates' => $candidates,
            'pjsip_status' => $this->getDevicePjsipStatus($extensions, $contacts)
        ];
    }

    private function getCrossSubnetMacEvidence($mac, $ip, $contacts, $observations) {
        foreach ($contacts as $contact) {
            if (($contact['ip'] ?? '') === $ip && ($contact['status'] ?? '') === 'Avail'
                && strtolower($contact['mac'] ?? '') === $mac) return 'Available SIP registration MAC';
        }
        $observed = $observations[$mac] ?? null;
        if ($observed && $observed['ip_address'] === $ip
            && strtotime($observed['last_seen']) >= time() - \FreePBX\modules\Provisioner\SipDiscovery::IDENTITY_MAX_AGE) {
            return ($observed['source'] ?? 'Handset identity') . ' at ' . $observed['last_seen'];
        }
        return null;
    }

    private function getPushIdentityError($ip, $mac, $contacts = null, $observations = null, $localSubnets = null) {
        $mac = strtolower(preg_replace('/[^a-f0-9]/i', '', (string)$mac));
        $mapping = $this->db->prepare('SELECT extension FROM provisioner_devices WHERE mac_address=?');
        $mapping->execute([$mac]);
        $extension = $mapping->fetchColumn();
        if (strlen($mac) !== 12 || $extension === false || trim((string)$extension) === '') {
            return 'Assign this handset MAC to an extension in MAC Mapping or Bulk Handler before pushing.';
        }
        if (!$this->isPrivateIp($ip)) return 'No valid private IPv4 push target.';
        $localSubnets = $localSubnets ?? $this->getArpCapableSubnets();
        if ($this->ipInSubnets($ip, $localSubnets)) return null;
        $contacts = $contacts ?? $this->getPjsipContacts(null, true);
        $observations = $observations ?? $this->getDiscoveryObservations();
        foreach ($contacts as $contact) {
            if (($contact['ip'] ?? '') === $ip && !empty($contact['mac']) && strtolower($contact['mac']) !== $mac) {
                return 'Push target is registered by a different handset MAC.';
            }
        }
        return $this->getCrossSubnetMacEvidence($mac, $ip, $contacts, $observations) ? null
            : 'Cross-subnet push needs a matching handset MAC/IP identity. Run Scan Network and, if needed, Identify MAC (identity is valid for 15 minutes).';
    }

    private function getDevicePjsipStatus($extensions, $contacts) {
        foreach ($extensions as $extension) {
            if (isset($contacts[$extension])) return $contacts[$extension]['status'] ?? 'Unknown';
        }
        return 'Not registered';
    }

    private function getArpCapableSubnets() {
        $links = shell_exec('ip -o link show 2>/dev/null');
        $addresses = shell_exec('ip -o -f inet addr show up 2>/dev/null');
        if (!$links || !$addresses) return [];
        $interfaces = [];
        foreach (explode("\n", trim($links)) as $line) {
            if (!preg_match('/^\d+:\s+([^:]+):\s+<([^>]+)>.*\bstate\s+(\w+)/', $line, $m)) continue;
            $flags = explode(',', $m[2]);
            if ($m[3] === 'UP' && in_array('BROADCAST', $flags, true) && in_array('UP', $flags, true) && in_array('LOWER_UP', $flags, true) && !in_array('NOARP', $flags, true)) {
                $interfaces[explode('@', $m[1], 2)[0]] = true;
            }
        }
        $subnets = [];
        foreach (explode("\n", trim($addresses)) as $line) {
            if (!preg_match('/^\d+:\s+(\S+)\s+inet\s+(\d+\.\d+\.\d+\.\d+)\/(\d+)/', $line, $m)) continue;
            $interface = explode('@', $m[1], 2)[0];
            if (empty($interfaces[$interface])) continue;
            $prefix = (int)$m[3];
            if ($prefix < 8 || $prefix > 30) continue;
            $network = long2ip(ip2long($m[2]) & (-1 << (32 - $prefix)));
            $subnets[] = $network . '/' . $prefix;
        }
        return array_values(array_unique($subnets));
    }

    private function getLocalDiscoverySubnets($subnet, $localSubnets = null) {
        $normalized = $this->normalizeScanSubnets($subnet, 32);
        if (!$normalized || count($normalized) !== 1) return [];
        $subnet = $normalized[0];
        [$network, $prefix] = explode('/', $subnet);
        if ((int)$prefix < 22) return [];
        $ranges = [];
        foreach ($localSubnets ?? $this->getArpCapableSubnets() as $local) {
            $valid = $this->normalizeScanSubnets($local, 32);
            if (!$valid || count($valid) !== 1) continue;
            $local = $valid[0];
            [$localNetwork, $localPrefix] = explode('/', $local);
            if ((int)$prefix >= (int)$localPrefix && $this->ipInSubnet($network, $local)) return [$subnet];
            if ((int)$prefix < (int)$localPrefix && $this->ipInSubnet($localNetwork, $subnet)) $ranges[] = $local;
        }
        // A range spanning local and routed networks sweeps only the local portions.
        usort($ranges, function ($a, $b) { return (int)explode('/', $a)[1] <=> (int)explode('/', $b)[1]; });
        $result = [];
        foreach (array_unique($ranges) as $range) {
            if (!$this->ipInSubnets(explode('/', $range)[0], $result)) $result[] = $range;
        }
        return $result;
    }

    private function getConfiguredScanSubnets() {
        $configured = $this->normalizeScanSubnets($this->getSetting('scan_subnets') ?: '');
        if (!empty($configured)) return $configured;

        $pbxIp = $this->getSetting('pbx_ip') ?: '';
        foreach ($this->getNetworkInterfaces() as $interface) {
            if ($pbxIp !== '' && $interface['ip'] === $pbxIp) return [$interface['subnet']];
        }
        return $this->getArpCapableSubnets();
    }

    private function ipInSubnet($ip, $subnet) {
        if (!$this->isPrivateIp($ip) || strpos($subnet, '/') === false) return false;
        [$network, $prefix] = explode('/', $subnet, 2);
        $prefix = (int)$prefix;
        if (!filter_var($network, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4) || $prefix < 0 || $prefix > 32) return false;
        $mask = $prefix === 0 ? 0 : (-1 << (32 - $prefix));
        return (ip2long($ip) & $mask) === (ip2long($network) & $mask);
    }

    private function ipInSubnets($ip, $subnets) {
        foreach ($subnets as $subnet) {
            if ($this->ipInSubnet($ip, $subnet)) return true;
        }
        return false;
    }

    private function annotateResolutionMode($target, $localSubnets) {
        if (!empty($target['conflict'])) $target['resolution_mode'] = 'conflict';
        elseif (!empty($target['mac_confirmation_required'])) $target['resolution_mode'] = 'cross_subnet_unidentified';
        elseif ($target['confidence'] === 'verified') $target['resolution_mode'] = 'local_verified';
        elseif ($target['confidence'] === 'mac_verified') $target['resolution_mode'] = 'local_mac_verified';
        elseif ($target['selected_source'] === 'pjsip') $target['resolution_mode'] = $this->ipInSubnets($target['selected_ip'], $localSubnets) ? 'local_unverified' : 'cross_subnet';
        elseif ($target['selected_source'] === 'discovery') $target['resolution_mode'] = $target['is_stale_fallback'] ? 'stale' : ($this->ipInSubnets($target['selected_ip'], $localSubnets) ? 'local_mac_identified' : 'cross_subnet_mac_identified');
        elseif (in_array($target['selected_source'], ['static_ip', 'dhcp'], true)) $target['resolution_mode'] = $this->ipInSubnets($target['selected_ip'], $localSubnets) ? 'local_configured' : 'cross_subnet_configured';
        elseif (in_array($target['selected_source'], ['stored', 'previous_static_ip'], true)) $target['resolution_mode'] = 'stale';
        else $target['resolution_mode'] = 'unresolved';
        return $target;
    }

    private function applyPendingSwapTargetOverrides($targets, $groups = null) {
        $groups = $groups === null ? $this->getPendingExtensionSwapGroups() : $groups;
        foreach ($groups as $group) {
            $arpByMac = [];
            $arpIps = [];
            $valid = true;
            $hasConflict = false;
            foreach ($group as $mac) {
                if (empty($targets[$mac])) {
                    $valid = false;
                    break;
                }
                $arp = null;
                foreach ($targets[$mac]['candidates'] as $candidate) {
                    if ($candidate['source'] === 'arp' && empty($candidate['stale']) && !empty($candidate['valid'])) $arp = $candidate;
                }
                if (!$arp || isset($arpIps[$arp['ip']])) {
                    $valid = false;
                    break;
                }
                $arpByMac[$mac] = $arp['ip'];
                $arpIps[$arp['ip']] = true;
                $hasConflict = $hasConflict || !empty($targets[$mac]['conflict']);
            }
            if (!$valid || !$hasConflict) continue;

            foreach ($group as $mac) {
                foreach ($targets[$mac]['candidates'] as $candidate) {
                    if ($candidate['source'] === 'pjsip' && !isset($arpIps[$candidate['ip']])) {
                        $valid = false;
                        break 2;
                    }
                }
            }
            if (!$valid) continue;

            foreach ($group as $mac) {
                $targets[$mac]['selected_ip'] = $arpByMac[$mac];
                $targets[$mac]['selected_source'] = 'arp_swap_verified';
                $targets[$mac]['confidence'] = 'verified';
                $targets[$mac]['conflict'] = false;
                $targets[$mac]['conflict_message'] = null;
                $targets[$mac]['resolution_mode'] = 'local_verified';
                $targets[$mac]['extension_swap'] = true;
                foreach ($targets[$mac]['candidates'] as &$candidate) {
                    $candidate['selected'] = $candidate['source'] === 'arp' && $candidate['ip'] === $arpByMac[$mac];
                }
                unset($candidate);
            }
        }
        return $targets;
    }

    private function resolveDeviceTargets($performScan = false, $onlyMac = null) {
        if ($onlyMac) {
            $stmt = $this->db->prepare("SELECT * FROM provisioner_devices WHERE mac_address=?");
            $stmt->execute([strtolower($onlyMac)]);
            $devices = $stmt->fetchAll(\PDO::FETCH_ASSOC);
        } else {
            $devices = $this->db->query("SELECT * FROM provisioner_devices")->fetchAll(\PDO::FETCH_ASSOC);
        }
        $contacts = $this->getPjsipContacts(null, true);
        $arpMap = $this->getCurrentArpMap();
        $dhcpMap = $this->getDhcpLeaseMap();
        $localSubnets = $this->getArpCapableSubnets();
        $discoveryMap = $this->getDiscoveryObservations();
        $configuredScanSubnets = $this->getConfiguredScanSubnets();
        $targets = [];
        foreach ($devices as $device) {
            $mac = strtolower($device['mac_address']);
            $targets[$mac] = $this->annotateResolutionMode($this->resolveDeviceTarget($device, $contacts, $arpMap, $dhcpMap, false, $localSubnets, $discoveryMap), $localSubnets);
        }
        if ($performScan) {
            $scanSubnets = [];
            $hasCandidate = false;
            $scanAllLocal = false;
            foreach ($targets as $target) {
                if (empty($target['selected_ip']) || $target['selected_source'] === 'stored') $scanAllLocal = true;
                foreach ($target['candidates'] as $candidate) {
                    if (empty($candidate['valid'])) continue;
                    $hasCandidate = true;
                    foreach ($configuredScanSubnets as $subnet) {
                        if ($this->ipInSubnet($candidate['ip'], $subnet)) $scanSubnets[] = $subnet;
                    }
                }
            }
            if (!$hasCandidate || $scanAllLocal) $scanSubnets = $configuredScanSubnets;
            $scanSubnets = array_values(array_unique($scanSubnets));
            $arpMap = $scanSubnets ? $this->resolveDeviceIpsLocal($scanSubnets) : [];
            foreach ($devices as $device) {
                $mac = strtolower($device['mac_address']);
                $targets[$mac] = $this->annotateResolutionMode($this->resolveDeviceTarget($device, $contacts, $arpMap, $dhcpMap, true, $localSubnets, $discoveryMap), $localSubnets);
                $targets[$mac]['scan_performed'] = !empty($scanSubnets);
                $targets[$mac]['scan_subnets'] = $scanSubnets;
            }
        } else {
            foreach ($targets as &$target) {
                $target['scan_performed'] = false;
                $target['scan_subnets'] = [];
            }
            unset($target);
        }
        if ($performScan && !$onlyMac) $targets = $this->applyPendingSwapTargetOverrides($targets);
        return $targets;
    }

    private function testTcpPort($ip, $port, $timeout = 1) {
        if (!$ip) return false;
        $socket = @fsockopen($ip, $port, $errno, $error, $timeout);
        if (!$socket) return false;
        fclose($socket);
        return true;
    }

    public function getDeviceDiagnostics($mac, $active = false) {
        $this->expirePushVerificationJobs();
        $mac = strtolower(preg_replace('/[^a-f0-9]/i', '', $mac));
        $stmt = $this->db->prepare("SELECT * FROM provisioner_devices WHERE mac_address=?");
        $stmt->execute([$mac]);
        $device = $stmt->fetch(\PDO::FETCH_ASSOC);
        if (!$device) return ['success' => false, 'error' => 'Device not found'];

        $targets = $this->resolveDeviceTargets((bool)$active, $mac);
        $target = $targets[$mac] ?? $this->resolveDeviceTarget($device);
        $brand = $device['brand'] ?: 'digium';
        $port = $brand === 'alcatel' ? 443 : 80;
        $path = $this->getConfigFilePath($mac);
        $configExists = file_exists($path);
        $pbxIp = $this->getSetting('pbx_ip') ?: $this->getLocalIP();
        $provPort = $this->getSetting('prov_port') ?: '2001';

        $job = null;
        if (!empty($device['last_push_job_id'])) {
            $jobStmt = $this->db->prepare('SELECT status, created_at, finished_at, error FROM provisioner_push_jobs WHERE id=?');
            $jobStmt->execute([$device['last_push_job_id']]);
            $job = $jobStmt->fetch(\PDO::FETCH_ASSOC) ?: null;
        }
        return [
            'success' => true,
            'device' => [
                'mac' => $mac,
                'extension' => $device['extension'],
                'previous_extension' => $device['previous_extension'],
                'brand' => $brand,
                'description' => $device['description'],
                'static_ip' => $device['static_ip'],
                'stored_ip' => $device['ip_address'],
                'needs_changes' => !empty($device['needs_changes'])
            ],
            'target' => $target,
            'service' => [
                'port' => $port,
                'reachable' => $this->testTcpPort($target['selected_ip'], $port),
                'fetch_supported' => in_array($brand, ['digium', 'alcatel'], true)
            ],
            'config' => [
                'filename' => $this->getDeviceConfigFilename($mac),
                'path' => $path,
                'exists' => $configExists,
                'size' => $configExists ? filesize($path) : 0,
                'modified' => $configExists ? date('Y-m-d H:i:s', filemtime($path)) : null,
                'valid' => $configExists ? $this->isValidPhoneConfig($mac, file_get_contents($path)) : null,
                'custom' => !empty($device['custom_config'])
            ],
            'provisioning_url' => "http://{$pbxIp}:{$provPort}/provisioning/",
            'last_push' => [
                'status' => $device['last_push_status'],
                'attempt' => $device['last_push_attempt_at'],
                'ack' => $device['last_ack_at'],
                'fetch_status' => $device['last_fetch_status'] ?? null,
                'fetch_at' => $device['last_fetch_at'] ?? null,
                'fetch_ip' => $device['last_fetch_ip'] ?? null,
                'verify_status' => $device['last_verify_status'] ?? null,
                'verify_at' => $device['last_verify_at'] ?? null,
                'verify_ip' => $device['last_verify_ip'] ?? null
            ],
            'verification_job' => $job
        ];
    }

    public function getPhoneStatus() {
        $this->expirePushVerificationJobs();
        $devices = $this->getDevices();
        $provDir = $this->getSetting('prov_dir') ?: '/var/www/html/provisioning';
        $contacts = $this->getPjsipContacts(null, true);
        $arpMap = $this->getCurrentArpMap();
        $dhcpMap = $this->getDhcpLeaseMap();
        $localSubnets = $this->getArpCapableSubnets();
        $discoveryMap = $this->getDiscoveryObservations();
        $results = [];

        foreach ($devices as $device) {
            $needsChanges = !empty($device['needs_changes']);

            $ext  = $device['extension'];
            $desc = $device['description'];
            $mac  = $device['mac_address'];

            if ($needsChanges) {
                $ext  = $device['previous_extension'] !== null ? $device['previous_extension'] : $ext;
                $desc = $device['previous_description'] !== null ? $device['previous_description'] : $desc;
            }

            $configExists = file_exists($provDir . '/' . $mac . '.cfg') || file_exists($provDir . '/config.' . $mac . '.xml');

            $targetDevice = $device;
            $targetDevice['extension'] = $ext;
            $target = $this->resolveDeviceTarget($targetDevice, $contacts, $arpMap, $dhcpMap, false, $localSubnets, $discoveryMap);
            $status = $target['pjsip_status'] === 'Not registered' ? 'Unavailable' : $target['pjsip_status'];
            $ip = $target['selected_ip'] ?: '';
            $observation = $discoveryMap[strtolower($mac)] ?? [];

            $results[] = [
                'extension'     => $ext,
                'mac'           => $mac,
                'description'   => $desc,
                'brand'         => $device['brand'] ?? 'digium',
                'model'         => $observation['model'] ?? '',
                'ip'            => $ip,
                'status'        => $status,
                'registration_status' => $target['pjsip_status'],
                'static_ip'     => $device['static_ip'] ?? null,
                'needs_changes' => $needsChanges,
                'config_exists' => $configExists,
                'last_ack_at'   => $device['last_ack_at'] ?? null,
                'last_push_status' => $device['last_push_status'] ?? null,
                'last_push_attempt_at' => $device['last_push_attempt_at'] ?? null,
                'last_fetch_status' => $device['last_fetch_status'] ?? null,
                'last_fetch_at' => $device['last_fetch_at'] ?? null,
                'last_fetch_ip' => $device['last_fetch_ip'] ?? null,
                'last_verify_status' => $device['last_verify_status'] ?? null,
                'last_verify_at' => $device['last_verify_at'] ?? null,
                'last_verify_ip' => $device['last_verify_ip'] ?? null,
                'ip_source'     => $target['selected_source'],
                'stale_ip'      => $target['is_stale_fallback'],
                'mac_confirmation_required' => $target['mac_confirmation_required'] ?? false
            ];
        }

        return $results;
    }

    public function getPhoneStatusGrid() {
        $raw = $this->getPhoneStatus();
        $provDir = $this->getSetting('prov_dir') ?: '/var/www/html/provisioning';
        $sipNotifyMode = $this->getSetting('deploy_mode') === 'sip_notify';
        $rows = [];
        foreach ($raw as $r) {
            $configExists = file_exists($provDir . '/' . $r['mac'] . '.cfg') || file_exists($provDir . '/config.' . $r['mac'] . '.xml');
            $isRegistered = $r['status'] === 'Avail' || $r['status'] === 'Unavail';
            $isAvail = $r['status'] === 'Avail';
            $manualIp = $this->isAleManualIp($r);
            if ($r['needs_changes'] && $configExists) {
                $actions = '<a class="clickable reboot-btn" data-ext="' . $r['extension'] . '" title="Reboot"><i class="fa fa-power-off"></i></a>';
                $statusLabel = 'Apply Pending';
            } elseif ($r['needs_changes']) {
                $actions = '<span class="text-muted"><i class="fa fa-clock-o"></i> Push first</span>';
                $statusLabel = 'Config Pending';
            } elseif ($isAvail) {
                $actions = '<a class="clickable reboot-btn" data-ext="' . $r['extension'] . '" title="Reboot"><i class="fa fa-power-off"></i></a>';
                $statusLabel = 'Ready';
            } elseif (!$r['config_exists']) {
                $actions = '<span class="text-muted"><i class="fa fa-file-text-o"></i> Push config</span>';
                $statusLabel = 'No Config';
            } elseif ($isRegistered) {
                $actions = '<span class="text-muted"><i class="fa fa-power-off"></i></span>';
                $statusLabel = (!empty($r['ip']) && $this->isPhoneReachable($r['ip'])) ? 'Unreachable' : 'Offline';
            } elseif (!empty($r['ip'])) {
                $actions = '<a class="clickable reboot-btn" data-ext="' . $r['extension'] . '" title="Reboot"><i class="fa fa-power-off"></i></a>';
                $statusLabel = $this->isPhoneReachable($r['ip']) ? 'Online' : 'Offline';
            } else {
                $actions = '<span class="text-muted"><i class="fa fa-power-off"></i></span>';
                $statusLabel = 'Offline';
            }
            if (!empty($r['mac_confirmation_required'])) $statusLabel = '<span class="text-warning" title="Cross-subnet push needs a matching handset MAC/IP identity. Run Scan Network and Identify MAC, then review Diagnostics.">Identify MAC</span>';
            $actions .= ' <a class="clickable diagnostics-btn" data-mac="' . $r['mac'] . '" title="Device diagnostics"><i class="fa fa-stethoscope"></i></a>';
            if ($sipNotifyMode) $actions .= ' <a class="clickable push-phone-btn" data-mac="' . $r['mac'] . '" title="Push config to this phone; target identity is checked again before sending"><i class="fa fa-paper-plane"></i> Push</a>';
            if ($manualIp) {
                $statusLabel = '<span class="text-warning" title="' . htmlspecialchars($this->manualIpMessage($r['ip'], $r['static_ip']), ENT_QUOTES) . '"><i class="fa fa-exclamation-triangle"></i> IP Set Manually</span>';
            }
            $pushStatus = $r['last_push_status'] ?? null;
            $pushInProgress = ($pushStatus === 'triggered' && ($r['last_fetch_status'] ?? '') === 'pending')
                || ($pushStatus === 'downloaded' && in_array(($r['last_verify_status'] ?? ''), ['pending', 'verifying'], true));
            if ($pushStatus === 'verified') {
                $pushLabel = '<span class="text-success">Verified</span>';
            } elseif ($pushStatus === 'downloaded') {
                $pushLabel = in_array($r['last_verify_status'], ['pending', 'verifying'], true)
                    ? '<span class="text-info" title="Config delivered. Registration, handset MAC, and target IP are being checked in the background.">Downloaded · Verifying</span>'
                    : '<span class="text-warning" title="Config delivered, but final registration or IP verification is incomplete. Open Diagnostics to verify again.">Verification incomplete</span>';
            } elseif ($pushStatus === 'triggered') {
                $pushLabel = ($r['last_fetch_status'] ?? '') === 'pending'
                    ? '<span class="text-info">Waiting for download</span>'
                     : '<span class="text-warning" title="SIP accepted the trigger, but config delivery was not confirmed. Use Retry Push to try again.">Download not observed</span>';
            } elseif ($pushStatus === 'failed') {
                $pushLabel = '<span class="text-danger" title="Push failed. Use Retry Push to try again.">Failed</span>';
            } else {
                $pushLabel = '<span class="text-muted">—</span>';
            }
            $pushIssue = $pushStatus === 'failed'
                || ($pushStatus === 'triggered' && ($r['last_fetch_status'] ?? '') !== 'pending')
                || ($pushStatus === 'downloaded' && !in_array(($r['last_verify_status'] ?? ''), ['pending', 'verifying', 'verified'], true));
            $rebootEligible = ($r['needs_changes'] && $configExists)
                || $isAvail
                || (!$isRegistered && !empty($r['ip']));
            $registration = ($r['registration_status'] ?? '') === 'Avail' ? 'Registered'
                : (($r['registration_status'] ?? '') === 'Unavail' ? 'Unavailable' : 'Not registered');

            $rows[] = [
                'extension'   => $r['extension'],
                'mac'         => $r['mac'],
                'description' => $r['description'],
                'brand'       => ucfirst($r['brand']),
                'model'       => $r['model'] ?? '',
                'ip'          => $r['ip'],
                'configured_mode' => trim((string)($r['static_ip'] ?? '')) !== '' ? 'Static' : 'DHCP',
                'needs_changes' => $r['needs_changes'],
                'registration' => $registration,
                'registered' => $registration === 'Registered',
                'push_issue' => $pushIssue,
                'push_retryable' => $pushIssue && !$pushInProgress,
                'push_in_progress' => $pushInProgress,
                'reboot_eligible' => (bool)$rebootEligible,
                'last_push_time' => $r['last_push_attempt_at'] ?? null,
                'status'      => $statusLabel,
                'push_status' => $pushLabel,
                'actions'     => $actions
            ];
        }
        return $rows;
    }

    public function getPhoneStatusSnapshot() {
        return \FreePBX\modules\Provisioner\LiveStatus::snapshot($this->getPhoneStatusGrid(), $this->getPendingCount());
    }

    public function notifyLiveStatus() {
        \FreePBX\modules\Provisioner\LiveStatus::notify();
    }

    private function isAleManualIp($row) {
        // A static IP was configured, the config was successfully pushed, yet
        // the phone's registered IP still differs — the phone is pinned to an
        // address provisioning can't move. This is pure live-state detection,
        // and only applies once the current config has actually been pushed
        // (no pending change).
        return strtolower($row['brand'] ?? '') === 'alcatel'
            && !empty($row['static_ip'])
            && !empty($row['ip'])
            && $row['static_ip'] !== $row['ip']
            && in_array(($row['last_push_status'] ?? null), ['success', 'verified'], true)
            && empty($row['needs_changes']);
    }

    private function manualIpMessage($actualIp, $staticIp) {
        return "This ALE phone's IP was set manually on the device — provisioning can't change it."
            . " The phone is at {$actualIp} but the configured static IP is {$staticIp}."
            . ' Set a matching DHCP reservation or the same static IP on the phone,'
            . ' or factory-reset the phone to re-provision.';
    }

    // =====================
    // REBOOT PHONE
    // =====================
    private function getBrandByExtension($extension) {
        $stmt = $this->db->prepare("SELECT brand FROM provisioner_devices WHERE extension=? OR previous_extension=?");
        $stmt->execute([$extension, $extension]);
        $row = $stmt->fetch(\PDO::FETCH_ASSOC);
        return $row ? ($row['brand'] ?? 'digium') : 'digium';
    }

    public function rebootPhone($extension) {
        $ext = preg_replace('/[^a-zA-Z0-9_-]/', '', $extension);
        $stmt = $this->db->prepare("SELECT * FROM provisioner_devices WHERE extension=? OR previous_extension=?");
        $stmt->execute([$ext, $ext]);
        $device = $stmt->fetch(\PDO::FETCH_ASSOC);
        $target = $device ? $this->resolveDeviceTarget($device) : ['selected_ip' => null, 'selected_source' => null];
        $ip = $target['selected_ip'];
        $source = $target['selected_source'] ?: 'endpoint';
        $success = false;
        $output = null;
        if ($ip) {
            $mac = strtolower(preg_replace('/[^a-f0-9]/i', '', $device['mac_address'] ?? ''));
            $pbxIp = $this->getSetting('pbx_ip') ?: $this->getLocalIP();
            $provPort = $this->getSetting('prov_port') ?: '2001';
            $python = $this->findPython3Binary();
            $script = __DIR__ . '/send_sip_notify.py';
            if ($python && is_readable($script) && $mac) {
                // Raw body-less NOTIFY with Event: check-sync;reboot=true —
                // bypasses Asterisk notify-type config, which may be empty on
                // some hosts. This same event forces a reboot on Digium,
                // Sangoma and ALE phones alike.
                $cmd = escapeshellcmd($python) . ' ' . escapeshellarg($script)
                     . ' ' . escapeshellarg($ip) . ' ' . escapeshellarg($pbxIp)
                     . ' ' . escapeshellarg($mac) . ' - '
                     . '5060 ' . escapeshellarg('check-sync;reboot=true');
                $targetEvent = 'check-sync;reboot=true';
            } else {
                // Fallback: ask Asterisk to send the NOTIFY (requires the notify
                // type to be defined in pjsip_notify.conf).
                $cmd = 'asterisk -rx ' . escapeshellarg("pjsip send notify sync-reboot-sangoma uri sip:$ip");
                $targetEvent = 'sync-reboot-sangoma';
            }
            $output = shell_exec($cmd . ' 2>/dev/null');
            $success = strpos($output, 'OK') !== false;
        } else {
            $output = shell_exec('asterisk -rx ' . escapeshellarg("pjsip send notify sync-reboot-sangoma endpoint $ext") . ' 2>/dev/null');
            $targetEvent = 'sync-reboot-sangoma';
            $success = strpos($output, 'Sending NOTIFY') !== false;
        }
        if ($success) {
            $this->db->prepare("UPDATE provisioner_devices SET needs_changes = 0, previous_extension = NULL, previous_description = NULL, previous_static_ip = NULL, previous_static_mask = NULL, previous_static_gw = NULL WHERE previous_extension = ? OR extension = ?")
                ->execute([$extension, $extension]);
        }
        $this->addLog('reboot', "Reboot sent to extension $extension ($targetEvent)" . ($ip ? " (IP:$ip source:$source)" : " (endpoint fallback)"), $success ? 'success' : 'error');
        return ['success' => $success, 'output' => $output, 'ip' => $ip, 'ip_source' => $source];
    }

    public function rebootPhones($extensions) {
        $results = [];
        foreach ($extensions as $ext) {
            $results[] = $this->rebootPhone($ext);
        }
        return ['success' => true, 'results' => $results];
    }

    // =====================
    // GENERATE PROVISIONING
    // =====================
    private function cleanDeviceConfig($mac, $provDir) {
        foreach ([$mac . '.cfg', 'config.' . $mac . '.xml'] as $file) {
            $path = $provDir . '/' . $file;
            if (file_exists($path)) unlink($path);
        }
    }

    private function writeGeneratedConfig($path, $config) {
        $tmpPath = tempnam(dirname($path), '.generate-');
        if ($tmpPath === false) return false;

        $expectedBytes = strlen($config);
        $written = file_put_contents($tmpPath, $config, LOCK_EX);
        if ($written !== $expectedBytes) {
            @unlink($tmpPath);
            return false;
        }

        if (!@chmod($tmpPath, 0644)) {
            @unlink($tmpPath);
            return false;
        }
        @chown($tmpPath, 'asterisk');
        @chgrp($tmpPath, 'asterisk');
        if (!rename($tmpPath, $path)) {
            @unlink($tmpPath);
            return false;
        }
        return true;
    }

    private function cleanupConfigStagingDirectory($dir) {
        if (!is_dir($dir)) return;
        $handle = @opendir($dir);
        if ($handle) {
            while (($file = readdir($handle)) !== false) {
                if ($file !== '.' && $file !== '..') @unlink($dir . '/' . $file);
            }
            closedir($handle);
        }
        @rmdir($dir);
    }

    private function rollbackConfigActivations($activations) {
        $restored = true;
        foreach ($activations as $activation) {
            if ($activation['had_original']) {
                if (!file_exists($activation['backup_path']) || !@rename($activation['backup_path'], $activation['original_path'])) {
                    $restored = false;
                    continue;
                }
                if ($activation['target_path'] !== $activation['original_path'] && file_exists($activation['target_path'])) {
                    if (!@unlink($activation['target_path'])) $restored = false;
                }
            } elseif (file_exists($activation['target_path']) && !@unlink($activation['target_path'])) {
                $restored = false;
            }
            if ($activation['backup_path'] && file_exists($activation['backup_path'])) @unlink($activation['backup_path']);
        }
        return $restored;
    }

    private function generateDeviceConfig($device, $template, $pbxIp, $sipPort, $transportVal, $provDir, $cleanupAlternate = true) {
        $mac = strtolower($device['mac_address']);
        $existingPath = $cleanupAlternate ? $this->getConfigFilePath($mac) : null;
        $brand = $device['brand'] ?? 'digium';
        $method = 'generate' . ucfirst($brand) . 'Config';
        if (method_exists($this, $method)) {
            $generated = $this->$method($device, $template, $pbxIp, $sipPort, $transportVal, $provDir);
            if (!$generated) return false;

            if ($cleanupAlternate) {
                $targetPath = $provDir . '/' . ($brand === 'alcatel' ? 'config.' . $mac . '.xml' : $mac . '.cfg');
                foreach ([$existingPath, $provDir . '/' . $mac . '.cfg', $provDir . '/config.' . $mac . '.xml'] as $path) {
                    if ($path !== $targetPath && file_exists($path)) @unlink($path);
                }
            }
            return true;
        }
        return null;
    }

    private function generateConfigSet($devices, $customMode = 'skip') {
        $pbxIp = $this->getSetting('pbx_ip') ?: '192.168.0.10';
        $transport = $this->getSetting('transport') ?: 'UDP';
        $sipPort = $this->getDetectedSipPort($transport);
        $provDir = $this->getSetting('prov_dir') ?: '/var/www/html/provisioning';
        $transportMap = ['UDP' => '0', 'TCP' => '1', 'TLS' => '2', 'TCP/UDP' => '0'];
        $transportVal = isset($transportMap[$transport]) ? $transportMap[$transport] : '0';

        if (!is_dir($provDir) && !mkdir($provDir, 0755, true)) {
            return ['success' => false, 'error' => 'Failed to create provisioning directory.'];
        }
        @chown($provDir, 'asterisk');
        @chgrp($provDir, 'asterisk');
        if (!is_writable($provDir)) {
            return ['success' => false, 'error' => 'Provisioning directory is not writable.'];
        }

        $templates = [];
        $eligible = [];
        $customTransitions = [];
        $preservedCustom = [];
        foreach ($devices as $device) {
            $mac = strtolower($device['mac_address']);
            $extensionChanged = $device['previous_extension'] !== null
                && $device['previous_extension'] !== ''
                && (string)$device['previous_extension'] !== (string)$device['extension'];
            $isCustom = !empty($device['custom_config']);
            if ($isCustom && $customMode !== 'replace' && !($customMode === 'extension_changes' && $extensionChanged)) {
                $preservedCustom[] = $device['mac_address'];
                continue;
            }
            if ($isCustom) $customTransitions[$mac] = true;
            $eligible[$mac] = $device;
            $brand = $device['brand'] ?? 'digium';
            if (!array_key_exists($brand, $templates)) {
                $templateFile = __DIR__ . '/templates/' . $brand . '/default.cfg';
                $templates[$brand] = file_exists($templateFile) ? file_get_contents($templateFile) : null;
            }
        }
        if (empty($eligible)) {
            return ['success' => true, 'generated' => [], 'custom_replaced' => 0, 'preserved_custom' => $preservedCustom];
        }

        $configWriteLock = $this->acquireConfigWriteLock();
        if (!$configWriteLock) {
            return ['success' => false, 'error' => 'Another config activation is already in progress. Try again shortly.'];
        }

        $stagingDir = tempnam($provDir, '.apply-');
        if ($stagingDir === false || !@unlink($stagingDir) || !@mkdir($stagingDir, 0755)) {
            if ($stagingDir && file_exists($stagingDir)) @unlink($stagingDir);
            return ['success' => false, 'error' => 'Failed to create config generation staging directory.'];
        }

        $generated = [];
        try {
            foreach ($eligible as $mac => $device) {
                $brand = $device['brand'] ?? 'digium';
                $template = $templates[$brand] ?? null;
                if (!$template || !$this->generateDeviceConfig($device, $template, $pbxIp, $sipPort, $transportVal, $stagingDir, false)) {
                    $this->cleanupConfigStagingDirectory($stagingDir);
                    return ['success' => false, 'error' => "Failed to generate config for $mac. Active configs were not changed."];
                }
                $generated[] = $device['mac_address'];
            }
        } catch (\Throwable $e) {
            $this->cleanupConfigStagingDirectory($stagingDir);
            return ['success' => false, 'error' => 'Config generation failed: ' . $e->getMessage()];
        }

        $activations = [];
        foreach ($eligible as $mac => $device) {
            $brand = $device['brand'] ?? 'digium';
            $filename = $brand === 'alcatel' ? 'config.' . $mac . '.xml' : $mac . '.cfg';
            $originalPath = $this->getConfigFilePath($mac);
            $hadOriginal = file_exists($originalPath);
            $backupPath = null;
            if ($hadOriginal) {
                $backupPath = tempnam($provDir, '.active-');
                $content = file_get_contents($originalPath);
                $written = $backupPath === false || $content === false ? false : file_put_contents($backupPath, $content, LOCK_EX);
                if ($content === false || $written !== strlen($content)) {
                    if ($backupPath) @unlink($backupPath);
                    foreach ($activations as $activation) {
                        if ($activation['backup_path']) @unlink($activation['backup_path']);
                    }
                    $this->cleanupConfigStagingDirectory($stagingDir);
                    return ['success' => false, 'error' => "Failed to back up active config for $mac. Active configs were not changed."];
                }
                @chmod($backupPath, 0644);
            }
            $activations[$mac] = [
                'original_path' => $originalPath,
                'backup_path' => $backupPath,
                'had_original' => $hadOriginal,
                'staged_path' => $stagingDir . '/' . $filename,
                'target_path' => $provDir . '/' . $filename
            ];
        }

        foreach ($customTransitions as $mac => $_) {
            $activation = $activations[$mac];
            if (!$activation['had_original']) continue;
            $content = file_get_contents($activation['backup_path']);
            if ($content === false || !$this->writeGeneratedConfig($activation['original_path'] . '.custom.bak', $content)) {
                foreach ($activations as $item) {
                    if ($item['backup_path']) @unlink($item['backup_path']);
                }
                $this->cleanupConfigStagingDirectory($stagingDir);
                return ['success' => false, 'error' => "Failed to archive custom config for $mac. Active configs were not changed."];
            }
        }

        if (!empty($customTransitions)) {
            try {
                $macs = array_keys($customTransitions);
                $placeholders = implode(',', array_fill(0, count($macs), '?'));
                $updated = $this->db->prepare("UPDATE provisioner_devices SET custom_config=0 WHERE mac_address IN ($placeholders)")->execute($macs);
                if (!$updated) throw new \RuntimeException('Failed to clear custom config state');
            } catch (\Throwable $e) {
                foreach ($activations as $activation) {
                    if ($activation['backup_path']) @unlink($activation['backup_path']);
                }
                $this->cleanupConfigStagingDirectory($stagingDir);
                return ['success' => false, 'error' => 'Generated configs could not be activated in the database. Active configs were not changed.'];
            }
        }

        $activationError = null;
        foreach ($activations as $mac => $activation) {
            if (!@rename($activation['staged_path'], $activation['target_path'])) {
                $activationError = "Failed to activate generated config for $mac.";
                break;
            }
            foreach ([$activation['original_path'], $provDir . '/' . $mac . '.cfg', $provDir . '/config.' . $mac . '.xml'] as $path) {
                if ($path !== $activation['target_path'] && file_exists($path) && !@unlink($path)) {
                    $activationError = "Failed to remove the previous config for $mac.";
                    break 2;
                }
            }
        }

        if ($activationError !== null) {
            $restored = $this->rollbackConfigActivations($activations);
            if (!empty($customTransitions)) {
                try {
                    $macs = array_keys($customTransitions);
                    $placeholders = implode(',', array_fill(0, count($macs), '?'));
                    $reset = $this->db->prepare("UPDATE provisioner_devices SET custom_config=1 WHERE mac_address IN ($placeholders)")->execute($macs);
                    if (!$reset) $restored = false;
                } catch (\Throwable $e) {
                    $restored = false;
                }
            }
            $this->cleanupConfigStagingDirectory($stagingDir);
            $activationError .= $restored ? ' Original configs were restored.' : ' One or more original configs require manual recovery.';
            return ['success' => false, 'error' => $activationError];
        }

        foreach ($activations as $activation) {
            if ($activation['backup_path']) @unlink($activation['backup_path']);
        }
        $this->cleanupConfigStagingDirectory($stagingDir);
        if (!empty($customTransitions)) {
            $this->addLog('config', 'Archived and regenerated ' . count($customTransitions) . ' custom config(s)', 'success');
        }
        return [
            'success' => true,
            'generated' => $generated,
            'custom_replaced' => count($customTransitions),
            'preserved_custom' => $preservedCustom
        ];
    }

    private function renderFeatureCodePlaceholders($template) {
        $features = [
            'DND_ON_CODE' => ['donotdisturb', 'dnd_on'],
            'DND_OFF_CODE' => ['donotdisturb', 'dnd_off'],
            'CF_ALL_ON_CODE' => ['callforward', 'cfon'],
            'CF_ALL_OFF_CODE' => ['callforward', 'cfoff'],
            'CF_BUSY_ON_CODE' => ['callforward', 'cfbon'],
            'CF_BUSY_OFF_CODE' => ['callforward', 'cfboff'],
            'CF_NOANSWER_ON_CODE' => ['callforward', 'cfuon'],
            'CF_NOANSWER_OFF_CODE' => ['callforward', 'cfuoff']
        ];
        $requested = array_filter($features, function ($feature, $key) use ($template) {
            return strpos($template, '{{' . $key . '}}') !== false;
        }, ARRAY_FILTER_USE_BOTH);
        if (!$requested) return $template;
        // Match FreePBX getCodeActive(): enabled custom code, otherwise default;
        // missing or disabled features deliberately resolve to an empty code.
        $stmt = $this->db->prepare('SELECT modulename,featurename,defaultcode,customcode,enabled FROM featurecodes WHERE modulename IN (?,?)');
        if (!$stmt || !$stmt->execute(['donotdisturb', 'callforward'])) throw new \RuntimeException('Could not read PBX feature codes.');
        $codes = [];
        foreach ($stmt->fetchAll(\PDO::FETCH_ASSOC) as $row) {
            $code = (string)($row['customcode'] ?? '');
            if ($code === '') $code = (string)($row['defaultcode'] ?? '');
            $codes[$row['modulename'] . '/' . $row['featurename']] = (int)$row['enabled'] === 1 ? $code : '';
        }
        $replace = [];
        foreach ($requested as $key => $feature) {
            $code = $codes[implode('/', $feature)] ?? '';
            if ($code !== '' && !preg_match('/^[0-9*#]{1,32}$/D', $code)) {
                throw new \RuntimeException('Unsupported handset feature code for ' . implode('/', $feature) . '. Use up to 32 digits, * or #.');
            }
            $replace['{{' . $key . '}}'] = $code;
        }
        return strtr($template, $replace);
    }

    private function generateDigiumConfig($device, $template, $pbxIp, $sipPort, $transportVal, $provDir) {
        $ext = $device['extension'];
        $mac = $device['mac_address'];

        $stmt = $this->db->prepare("SELECT data FROM sip WHERE id = ? AND keyword = 'secret'");
        $stmt->execute([$ext]);
        $row = $stmt->fetch(\PDO::FETCH_ASSOC);

        if (!$row) {
            $stmt = $this->db->prepare("SELECT data FROM pjsip WHERE id = ? AND keyword = 'password'");
            $stmt->execute([$ext]);
            $row = $stmt->fetch(\PDO::FETCH_ASSOC);
        }

        if (!$row) return null;

        $secret = $row['data'];
        $tcpUdp = $transportVal === '0' ? '0' : '1';

        $wanMode = empty($device['static_ip']) ? 'DHCP' : 'STATIC';
        $dhcpEnabled = empty($device['static_ip']) ? '1' : '0';
        $staticIp = $device['static_ip'] ?? null ?: '0.0.0.0';
        $staticMask = $device['static_mask'] ?? null ?: '0.0.0.0';
        $staticGw = $device['static_gw'] ?? null ?: '0.0.0.0';
        $staticDns = empty($device['static_ip']) ? '8.8.8.8' : ($device['static_gw'] ?: '8.8.8.8');
        $ntpServer = $pbxIp;
        $provPort = $this->getSetting('prov_port') ?: '2001';
        $provUrl = "http://{$pbxIp}:{$provPort}/provisioning/";
        $phonebookName = trim((string)$this->getSetting('digium_phonebook_name'));
        if ($phonebookName === '') $phonebookName = 'Internal Contact';
        $phonebookUrl = trim((string)$this->getSetting('digium_phonebook_url'));
        if ($phonebookUrl === '') $phonebookUrl = "http://{$pbxIp}:2001/phonebook.php?format=digium";
        $phonebookUsername = (string)$this->getSetting('digium_phonebook_username');
        $phonebookPassword = (string)$this->getSetting('digium_phonebook_password');
        $phonebookAuth = ($phonebookUsername !== '' || $phonebookPassword !== '')
            ? $phonebookUsername . ':' . $phonebookPassword
            : '';
        $vlanId = $this->getSetting('vlan_id') ?: '';
        $vlanDataId = $this->getSetting('vlan_data_id') ?: '';
        $vlanEnabled = $vlanId ? '1' : '0';

        $template = $this->renderFeatureCodePlaceholders($template);
        $config = str_replace(
            ['{{MAC}}', '{{EXTENSION}}', '{{PBX_IP}}', '{{SIP_PORT}}', '{{SECRET}}', '{{TCP_UDP}}',
             '{{WAN_MODE}}', '{{STATIC_IP}}', '{{STATIC_MASK}}', '{{STATIC_GW}}', '{{STATIC_DNS}}', '{{DHCP_ENABLED}}',
             '{{NTP_SERVER}}', '{{PROV_URL}}', '{{PHONEBOOK_NAME}}', '{{PHONEBOOK_URL}}', '{{PHONEBOOK_AUTH}}', '{{VLAN_ENABLED}}', '{{VLAN_ID}}', '{{DATA_VLAN_ID}}'],
            [$mac, $ext, $pbxIp, $sipPort, $secret, $tcpUdp,
             $wanMode, $staticIp, $staticMask, $staticGw, $staticDns, $dhcpEnabled,
             $ntpServer, $provUrl, $phonebookName, $phonebookUrl, $phonebookAuth, $vlanEnabled, $vlanId, $vlanDataId],
            $template
        );

        return $this->writeGeneratedConfig($provDir . '/' . $mac . '.cfg', $config);
    }

    private function generateAlcatelConfig($device, $template, $pbxIp, $sipPort, $transportVal, $provDir) {
        $ext = $device['extension'];
        $mac = $device['mac_address'];

        $stmt = $this->db->prepare("SELECT data FROM sip WHERE id = ? AND keyword = 'secret'");
        $stmt->execute([$ext]);
        $row = $stmt->fetch(\PDO::FETCH_ASSOC);

        if (!$row) {
            $stmt = $this->db->prepare("SELECT data FROM pjsip WHERE id = ? AND keyword = 'password'");
            $stmt->execute([$ext]);
            $row = $stmt->fetch(\PDO::FETCH_ASSOC);
        }

        if (!$row) return null;

        $secret = $row['data'];
        $dhcpEnabled = empty($device['static_ip']) ? 'Dynamic' : 'Static';
        $staticIp = $device['static_ip'] ?? '';
        $staticMask = $device['static_mask'] ?? '';
        $staticGw = $device['static_gw'] ?? '';
        $staticDns = $staticIp ? ($staticGw ?: '8.8.8.8') : '';
        $adminPass = $this->getSetting('phone_admin_pass_alcatel') ?: '123456';
        $vlanId = $this->getSetting('vlan_id') ?: '';
        $vlanDataId = $this->getSetting('vlan_data_id') ?: '';
        $vlanEnabled = $vlanId ? 'true' : 'false';
        $dataVlanEnabled = $vlanDataId ? 'true' : 'false';
        $ntpServer = $pbxIp;
        $provPort = $this->getSetting('prov_port') ?: '2001';
        $provUrl = "http://{$pbxIp}:{$provPort}/provisioning/";
        $phonebookName = trim((string)$this->getSetting('alcatel_phonebook_name'));
        if ($phonebookName === '') $phonebookName = 'Internal Contact';
        $phonebookUrl = trim((string)$this->getSetting('alcatel_phonebook_url'));
        if ($phonebookUrl === '') $phonebookUrl = "http://{$pbxIp}:2001/phonebook.php?format=alcatel";
        $phonebookUsername = (string)$this->getSetting('alcatel_phonebook_username');
        $phonebookPassword = (string)$this->getSetting('alcatel_phonebook_password');

        $template = $this->renderFeatureCodePlaceholders($template);
        $config = str_replace(
            ['{{MAC}}', '{{EXTENSION}}', '{{PBX_IP}}', '{{SIP_PORT}}', '{{USERNAME}}', '{{PASSWORD}}', '{{TRANSPORT}}',
             '{{DHCP_ENABLED}}', '{{STATIC_IP}}', '{{STATIC_MASK}}', '{{STATIC_GW}}', '{{STATIC_DNS}}',
             '{{ADMIN_PASS}}', '{{VLAN_ENABLED}}', '{{VLAN_ID}}', '{{DATA_VLAN_ENABLED}}', '{{DATA_VLAN_ID}}',
             '{{NTP_SERVER}}', '{{PROV_URL}}', '{{PHONEBOOK_NAME}}', '{{PHONEBOOK_URL}}', '{{PHONEBOOK_USERNAME}}', '{{PHONEBOOK_PASSWORD}}'],
            [$mac, $ext, $pbxIp, $sipPort, $ext, $secret, $transportVal === '0' ? '0' : '1',
             $dhcpEnabled, $staticIp, $staticMask, $staticGw, $staticDns,
             $adminPass, $vlanEnabled, $vlanId, $dataVlanEnabled, $vlanDataId,
             $ntpServer, $provUrl, $phonebookName, $phonebookUrl, $phonebookUsername, $phonebookPassword],
            $template
        );

        $file = 'config.' . $mac . '.xml';
        return $this->writeGeneratedConfig($provDir . '/' . $file, $config);
    }

    private function generateYealinkConfig($device, $template, $pbxIp, $sipPort, $transportVal, $provDir) {
        $ext = $device['extension'];
        $mac = $device['mac_address'];

        $stmt = $this->db->prepare("SELECT data FROM sip WHERE id = ? AND keyword = 'secret'");
        $stmt->execute([$ext]);
        $row = $stmt->fetch(\PDO::FETCH_ASSOC);

        if (!$row) {
            $stmt = $this->db->prepare("SELECT data FROM pjsip WHERE id = ? AND keyword = 'password'");
            $stmt->execute([$ext]);
            $row = $stmt->fetch(\PDO::FETCH_ASSOC);
        }

        if (!$row) return null;

        $secret = $row['data'];
        $displayName = $device['description'] ?: $ext;
        $wanType = empty($device['static_ip']) ? '0' : '2';
        $staticIp = $device['static_ip'] ?? '';
        $staticMask = $device['static_mask'] ?? '';
        $staticGw = $device['static_gw'] ?? '';
        $staticDns = $staticIp ? ($staticGw ?: '8.8.8.8') : '';
        $dnsEnable = $staticIp ? '1' : '0';
        $ntpServer = $pbxIp;
        $vlanId = $this->getSetting('vlan_id') ?: '';
        $vlanDataId = $this->getSetting('vlan_data_id') ?: '';
        $vlanEnable = $vlanId ? '1' : '0';
        $dataVlanEnable = $vlanDataId ? '1' : '0';

        $config = str_replace(
            ['{ext}', '{display_name}', '{secret}', '{server_addr}', '{server_port}', '{transport}',
             '{wan_type}', '{static_ip}', '{static_mask}', '{static_gw}', '{static_dns}', '{dns_enable}',
             '{ntp_server}', '{vlan_enable}', '{vlan_id}', '{data_vlan_enable}', '{data_vlan_id}'],
            [$ext, $displayName, $secret, $pbxIp, $sipPort, $transportVal,
             $wanType, $staticIp, $staticMask, $staticGw, $staticDns, $dnsEnable,
             $ntpServer, $vlanEnable, $vlanId, $dataVlanEnable, $vlanDataId],
            $template
        );

        return $this->writeGeneratedConfig($provDir . '/' . $mac . '.cfg', $config);
    }

    public function generateProvisioning() {
        $dir = '/var/www/html/provisioning';
        $output = shell_exec("chown -R asterisk:asterisk $dir 2>&1");
        return ['success' => true, 'output' => $output];
    }

    public function generateAllConfigs($replaceCustom = false) {
        $devices = $this->getDevices();
        if (empty($devices)) {
            return ['success' => true, 'generated' => 0, 'message' => 'No devices in MAC Mapping.'];
        }

        $customDevices = [];
        foreach ($devices as $device) {
            if (!empty($device['custom_config'])) $customDevices[] = strtolower($device['mac_address']);
        }
        if (!empty($customDevices) && !$replaceCustom) {
            return [
                'success' => true,
                'requires_confirmation' => true,
                'custom_count' => count($customDevices),
                'custom_macs' => $customDevices
            ];
        }

        $result = $this->generateConfigSet($devices, $replaceCustom ? 'replace' : 'skip');
        if (!$result['success']) return $result;
        $generated = count($result['generated']);
        $this->addLog('apply', "Generated configs for $generated devices (all)", 'success');
        return [
            'success' => true,
            'generated' => $generated,
            'custom_replaced' => $result['custom_replaced']
        ];
    }

    // =====================
    // MAC MAPPING / DEVICES
    // =====================
    public function getDevices() {
        $stmt = $this->db->prepare("SELECT * FROM provisioner_devices ORDER BY extension ASC");
        $stmt->execute();
        return $stmt->fetchAll(\PDO::FETCH_ASSOC);
    }

    public function getDeviceGrid() {
        $devices = $this->getDevices();
        $rows = [];
        foreach ($devices as $d) {
            $isPending = !empty($d['needs_changes']);
            $actions = '';
            $brandBadge = $this->brandBadge($d['brand'] ?? 'digium');
            $staticIp = $d['static_ip'] ?? '';
            $staticLabel = $staticIp ? $staticIp : '';
            $assignedExtension = (string)($d['extension'] ?? '');
            $previousExtension = (string)($d['previous_extension'] ?? '');
            $extensionDisplay = htmlspecialchars($assignedExtension, ENT_QUOTES);
            if ($isPending && $previousExtension !== '' && $previousExtension !== $assignedExtension) {
                $extensionDisplay = htmlspecialchars($previousExtension, ENT_QUOTES)
                    . ' <span class="text-muted">&rarr;</span> <strong>' . htmlspecialchars($assignedExtension, ENT_QUOTES) . '</strong>';
            }
            $configState = $this->getDeviceConfigExtensionState($d);
            $configSync = $this->formatConfigSyncState($configState, false);
            if (strtolower($d['brand'] ?? '') === 'alcatel' && !empty($d['static_ip']) && ($d['last_push_status'] ?? null) === 'verified' && empty($d['needs_changes'])) {
                $held = $this->resolveDeviceTarget($d);
                $heldIp = $held['selected_ip'] ?? '';
                if (!empty($heldIp) && $heldIp !== $d['static_ip']) {
                    $note = $this->manualIpMessage($heldIp, $d['static_ip']);
                    $configSync .= ' <i class="fa fa-exclamation-triangle text-warning" title="' . htmlspecialchars($note, ENT_QUOTES) . '"></i>';
                }
            }            $actions .= '<a class="clickable edit-device-btn" data-id="' . $d['id'] . '" data-mac="' . $d['mac_address'] . '" data-ext="' . $d['extension'] . '" data-desc="' . htmlspecialchars($d['description'] ?? '', ENT_QUOTES) . '" data-brand="' . ($d['brand'] ?? 'digium') . '" data-static-ip="' . htmlspecialchars($staticIp, ENT_QUOTES) . '" data-static-mask="' . htmlspecialchars($d['static_mask'] ?? '', ENT_QUOTES) . '" data-static-gw="' . htmlspecialchars($d['static_gw'] ?? '', ENT_QUOTES) . '" title="Edit"><i class="fa fa-edit"></i></a> ';
            $actions .= '<a class="clickable diagnostics-btn" data-mac="' . $d['mac_address'] . '" title="Device diagnostics"><i class="fa fa-stethoscope"></i></a> ';
            if ($isPending) {
                $actions .= '<a class="clickable revert-device-btn" data-id="' . $d['id'] . '" title="Revert"><i class="fa fa-undo"></i></a> ';
            }
            $actions .= '<a class="clickable delete-device-btn" data-id="' . $d['id'] . '" data-ext="' . $d['extension'] . '" title="Delete"><i class="fa fa-trash"></i></a>';
            $rows[] = [
                'id'          => $d['id'],
                'extension'   => $d['extension'],
                'extension_display' => $extensionDisplay,
                'mac_address' => $d['mac_address'],
                'brand'       => $brandBadge,
                'description' => $d['description'] ?? '',
                'static_ip'   => $staticLabel,
                'config_sync' => $configSync,
                'is_pending'  => $isPending,
                'actions'     => $actions
            ];
        }
        return $rows;
    }

    private function brandBadge($brand) {
        $colors = ['digium' => 'primary', 'alcatel' => 'info', 'yealink' => 'success', 'polycom' => 'warning'];
        $color = $colors[$brand] ?? 'default';
        return '<span class="badge badge-' . $color . '">' . htmlspecialchars(ucfirst($brand)) . '</span>';
    }

    public function getPendingCount() {
        $stmt = $this->db->prepare("SELECT mac_address FROM provisioner_devices WHERE needs_changes = 1");
        $stmt->execute();
        $macs = $stmt->fetchAll(\PDO::FETCH_COLUMN);
        $count = count($macs);
        $deployMode = $this->getSetting('deploy_mode') ?: 'dhcp';
        if ($count === 0) {
            return ['count' => 0, 'needs_reboot' => false, 'deploy_mode' => $deployMode];
        }
        $provDir = $this->getSetting('prov_dir') ?: '/var/www/html/provisioning';
        $allHaveConfig = true;
        foreach ($macs as $mac) {
            if (!file_exists($provDir . '/' . $mac . '.cfg') && !file_exists($provDir . '/config.' . $mac . '.xml')) {
                $allHaveConfig = false;
                break;
            }
        }
        return ['count' => $count, 'needs_reboot' => $allHaveConfig, 'deploy_mode' => $deployMode];
    }

    private function getDuplicateDesiredExtensions() {
        $stmt = $this->db->query("SELECT extension FROM provisioner_devices WHERE extension IS NOT NULL AND extension <> '' GROUP BY extension HAVING COUNT(*) > 1 ORDER BY extension");
        return $stmt->fetchAll(\PDO::FETCH_COLUMN);
    }

    private function getPendingExtensionSwapGroups($rows = null) {
        if ($rows === null) {
            $rows = $this->db->query("SELECT mac_address, extension, previous_extension FROM provisioner_devices WHERE needs_changes = 1 AND previous_extension IS NOT NULL AND previous_extension <> '' AND previous_extension <> extension")->fetchAll(\PDO::FETCH_ASSOC);
        }
        $byPrevious = [];
        foreach ($rows as $row) {
            if (isset($byPrevious[$row['previous_extension']])) return [];
            $byPrevious[$row['previous_extension']] = $row;
        }

        $groups = [];
        $claimed = [];
        foreach ($byPrevious as $startKey => $row) {
            $start = (string)$startKey;
            if (isset($claimed[$start])) continue;
            $path = [];
            $position = [];
            $extension = $start;
            while (isset($byPrevious[$extension]) && !isset($position[$extension])) {
                $position[$extension] = count($path);
                $path[] = $byPrevious[$extension];
                $extension = $byPrevious[$extension]['extension'];
            }
            if ($extension !== $start || count($path) < 2) continue;
            $macs = [];
            foreach ($path as $member) {
                $claimed[$member['previous_extension']] = true;
                $macs[] = strtolower($member['mac_address']);
            }
            $groups[] = $macs;
        }
        return $groups;
    }

    private function expandPushSelection($macs) {
        if (!is_array($macs)) $macs = [$macs];
        $macs = array_values(array_unique(array_filter(array_map(function ($mac) {
            return strtolower(preg_replace('/[^a-f0-9]/i', '', (string)$mac));
        }, $macs), function ($mac) { return strlen($mac) === 12; })));
        foreach ($this->getPendingExtensionSwapGroups() as $group) {
            if (array_intersect($macs, $group)) $macs = array_values(array_unique(array_merge($macs, $group)));
        }
        return $macs;
    }

    public function applyChanges($generate = true, $reboot = false, $finalize = true, $macs = []) {
        $macs = $this->expandPushSelection($macs);
        $sql = "SELECT * FROM provisioner_devices WHERE needs_changes = 1";
        if ($macs) $sql = "SELECT * FROM provisioner_devices WHERE mac_address IN (" . implode(',', array_fill(0, count($macs), '?')) . ')';
        $stmt = $this->db->prepare($sql);
        $stmt->execute($macs);
        $pending = $stmt->fetchAll(\PDO::FETCH_ASSOC);

        if (empty($pending)) {
            return ['success' => true, 'affected' => 0, 'message' => 'No pending changes.'];
        }

        $duplicates = $this->getDuplicateDesiredExtensions();
        if (!empty($duplicates)) {
            return [
                'success' => false,
                'error' => 'Duplicate desired extension assignment(s): ' . implode(', ', $duplicates) . '. Complete the extension swap before applying changes.'
            ];
        }

        $generated = [];
        $preservedCustom = [];
        $rebootResults = [];

        if (!$generate && $finalize) {
            foreach ($pending as $device) {
                $extensionChanged = $device['previous_extension'] !== null
                    && $device['previous_extension'] !== ''
                    && (string)$device['previous_extension'] !== (string)$device['extension'];
                if (!empty($device['custom_config']) && $extensionChanged) {
                    return ['success' => false, 'error' => 'Custom configs with extension changes must be regenerated before pending changes can be finalized.'];
                }
            }
        }

        if ($generate) {
            $result = $this->generateConfigSet($pending, 'extension_changes');
            if (!$result['success']) return $result;
            $generated = $result['generated'];
            $preservedCustom = $result['preserved_custom'];
            $this->addLog('apply', 'Generated configs for ' . count($generated) . ' devices', 'success');
        }

        $skippedReboot = 0;
        if ($reboot) {
            foreach ($pending as $device) {
                $rebootExt = $device['previous_extension'] !== null ? $device['previous_extension'] : $device['extension'];
                if ($rebootExt === null) {
                    $skippedReboot++;
                    continue;
                }
                $result = $this->rebootPhone($rebootExt);
                $rebootResults[] = [
                    'extension' => $rebootExt,
                    'success'   => $result['success']
                ];
            }
        }

        if (($generate || $reboot) && $finalize) {
            $ids = array_column($pending, 'id');
            $placeholders = implode(',', array_fill(0, count($ids), '?'));
            $this->db->prepare("UPDATE provisioner_devices SET needs_changes = 0, previous_extension = NULL, previous_description = NULL, previous_static_ip = NULL, previous_static_mask = NULL, previous_static_gw = NULL WHERE id IN ($placeholders)")->execute($ids);
        }

        return [
            'success'        => true,
            'affected'       => count($pending),
            'generated'      => $generated,
            'preserved_custom' => $preservedCustom,
            'reboots'        => $rebootResults,
            'skipped_reboot' => $skippedReboot
        ];
    }

    public function addDevice($mac, $extension, $description = '', $brand = null, $ip = '', $staticIp = null, $staticMask = null, $staticGw = null) {
        $mac = strtolower(preg_replace('/[^a-fA-F0-9]/', '', $mac));
        if ($brand === null || !in_array($brand, $this->getBrands())) {
            $detected = $this->getBrandByMac($mac);
            $brand = $detected ?: 'digium';
        }
        if (strlen($mac) !== 12) {
            return ['success' => false, 'error' => 'Invalid MAC address — must be 12 hex characters'];
        }
        if ($ip && !filter_var($ip, FILTER_VALIDATE_IP)) {
            return ['success' => false, 'error' => 'Invalid IP address'];
        }
        $ip = $ip ?: null;
        if ($staticIp && !filter_var($staticIp, FILTER_VALIDATE_IP)) {
            return ['success' => false, 'error' => 'Invalid static IP address'];
        }
        if ($staticGw && !filter_var($staticGw, FILTER_VALIDATE_IP)) {
            return ['success' => false, 'error' => 'Invalid gateway IP address'];
        }

        $prevExt  = null;
        $prevDesc = null;
        $stmt = $this->db->prepare("SELECT id, extension, description, brand, ip_address, static_ip, needs_changes, previous_extension, previous_description FROM provisioner_devices WHERE mac_address=?");
        $stmt->execute([$mac]);
        $existing = $stmt->fetch(\PDO::FETCH_ASSOC);
        if ($existing) {
            $originalExt = !empty($existing['needs_changes']) && $existing['previous_extension'] !== null
                ? $existing['previous_extension'] : $existing['extension'];
            $originalDesc = !empty($existing['needs_changes']) && $existing['previous_description'] !== null
                ? $existing['previous_description'] : $existing['description'];
            if ($extension !== $originalExt) {
                $prevExt = $originalExt;
            }
            if ($description !== $originalDesc) {
                $prevDesc = $originalDesc;
            }
        }

        $now = date('Y-m-d H:i:s');
        try {
            if ($existing) {
                $stmt = $this->db->prepare("UPDATE provisioner_devices SET extension=?, description=?, brand=?, ip_address=NULLIF(?, ''), static_ip=NULLIF(?, ''), static_mask=NULLIF(?, ''), static_gw=NULLIF(?, ''), needs_changes=1, previous_extension=?, previous_description=?, updated_at=? WHERE mac_address=?");
                $stmt->execute([$extension, $description, $brand, $ip, $staticIp, $staticMask, $staticGw, $prevExt, $prevDesc, $now, $mac]);
            } else {
                $stmt = $this->db->prepare("INSERT INTO provisioner_devices (mac_address, extension, description, brand, needs_changes, previous_extension, previous_description, ip_address, static_ip, static_mask, static_gw, created_at, updated_at) VALUES (?, ?, ?, ?, 1, ?, ?, ?, ?, ?, ?, ?, ?)");
                $stmt->execute([$mac, $extension, $description, $brand, $prevExt, $prevDesc, $ip, $staticIp, $staticMask, $staticGw, $now, $now]);
            }
            $this->addLog('device', ($existing ? 'Updated' : 'Added') . " device MAC:$mac EXT:$extension BRAND:$brand" . ($ip ? " IP:$ip" : ''), 'success');
            return ['success' => true];
        } catch (\Exception $e) {
            try {
                $this->ensureTables();
                $now = date('Y-m-d H:i:s');
                if ($existing) {
                    $stmt = $this->db->prepare("UPDATE provisioner_devices SET extension=?, description=?, brand=?, ip_address=NULLIF(?, ''), static_ip=NULLIF(?, ''), static_mask=NULLIF(?, ''), static_gw=NULLIF(?, ''), needs_changes=1, previous_extension=?, previous_description=?, updated_at=? WHERE mac_address=?");
                    $stmt->execute([$extension, $description, $brand, $ip, $staticIp, $staticMask, $staticGw, $prevExt, $prevDesc, $now, $mac]);
                } else {
                    $stmt = $this->db->prepare("INSERT INTO provisioner_devices (mac_address, extension, description, brand, needs_changes, previous_extension, previous_description, ip_address, static_ip, static_mask, static_gw, created_at, updated_at) VALUES (?, ?, ?, ?, 1, ?, ?, ?, ?, ?, ?, ?, ?)");
                    $stmt->execute([$mac, $extension, $description, $brand, $prevExt, $prevDesc, $ip, $staticIp, $staticMask, $staticGw, $now, $now]);
                }
                $this->addLog('device', ($existing ? 'Updated' : 'Added') . " device MAC:$mac EXT:$extension BRAND:$brand" . ($ip ? " IP:$ip" : ''), 'success');
                return ['success' => true];
            } catch (\Exception $e2) {
                $msg = $e2->getMessage();
                $this->addLog('device', "Failed to add device MAC:$mac EXT:$extension - $msg", 'error');
                return ['success' => false, 'error' => $msg];
            }
        }
    }

    public function addDiscoveredDevices($devices) {
        $added = 0;
        $accepted = [];
        $errors = [];
        foreach ($devices as $d) {
            $mac  = is_array($d) ? ($d['mac'] ?? '') : $d;
            $ip   = is_array($d) ? ($d['ip'] ?? '') : '';
            $ext  = is_array($d) ? ($d['extension'] ?? '') : '';
            $clean = strtolower(preg_replace('/[^a-fA-F0-9]/', '', $mac));
            if (strlen($clean) !== 12) {
                $errors[] = "$mac: invalid MAC";
                continue;
            }
            $stmt = $this->db->prepare("SELECT id, extension, ip_address FROM provisioner_devices WHERE mac_address=?");
            $stmt->execute([$clean]);
            $existing = $stmt->fetch(\PDO::FETCH_ASSOC);
            if ($existing) {
                $accepted[] = [
                    'mac' => strtoupper($clean),
                    'ip' => $ip ?: ($existing['ip_address'] ?? ''),
                    'extension' => $existing['extension'] ?? '',
                    'state' => 'existing'
                ];
                continue;
            }
            $result = $this->addDevice($mac, $ext, '', null, $ip);
            if ($result['success']) {
                $added++;
                if ($ext) {
                    $this->db->prepare("UPDATE provisioner_devices SET needs_changes=0 WHERE mac_address=?")->execute([$clean]);
                }
                $accepted[] = ['mac' => strtoupper($clean), 'ip' => $ip, 'extension' => $ext, 'state' => 'added'];
            } else {
                $errors[] = $mac . ': ' . $result['error'];
            }
        }
        $status = $errors ? 'warning' : 'success';
        $this->addLog('device', "Added $added discovered device(s) to MAC Mapping", $status);
        return [
            'success' => !empty($accepted),
            'added' => $added,
            'devices' => $accepted,
            'errors' => $errors,
            'error' => empty($accepted) && $errors ? implode('; ', $errors) : null
        ];
    }

    public function autoAddSupportedDiscoveredDevices($devices, $pjsipMatches = null) {
        if (($this->getSetting('deploy_mode') ?: 'dhcp') !== 'sip_notify') {
            return ['success' => false, 'error' => 'Auto Add Supported is available only in SIP NOTIFY mode.'];
        }
        if (!is_array($devices) || empty($devices)) {
            return ['success' => false, 'error' => 'No discovered devices supplied.'];
        }
        if (count($devices) > 1024) {
            return ['success' => false, 'error' => 'Too many discovered devices in one request.'];
        }

        $pjsipWarning = null;
        if ($pjsipMatches === null) {
            $discovery = $this->autoDiscoverFromPjsip();
            $pjsipMatches = !empty($discovery['success']) ? $discovery['matches'] : [];
            if (empty($discovery['success'])) $pjsipWarning = $discovery['error'] ?? 'PJSIP matching was unavailable.';
        }
        $extensionsByMac = [];
        foreach ((array)$pjsipMatches as $match) {
            $matchMac = strtolower(preg_replace('/[^a-fA-F0-9]/', '', (string)($match['mac'] ?? '')));
            $extension = trim((string)($match['extension'] ?? ''));
            if (strlen($matchMac) === 12 && preg_match('/^[a-zA-Z0-9_-]+$/', $extension)) {
                $extensionsByMac[$matchMac] = $extension;
            }
        }

        $normalized = [];
        $unsupported = [];
        $errors = [];
        foreach ($devices as $device) {
            $rawMac = is_array($device) ? (string)($device['mac'] ?? '') : (string)$device;
            $mac = strtolower(preg_replace('/[^a-fA-F0-9]/', '', $rawMac));
            if (strlen($mac) !== 12) {
                $errors[] = ['mac' => $rawMac, 'error' => 'Invalid MAC address'];
                continue;
            }
            if (isset($normalized[$mac])) continue;
            $ip = is_array($device) ? trim((string)($device['ip'] ?? '')) : '';
            if ($ip !== '' && !filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4)) {
                $errors[] = ['mac' => strtoupper($mac), 'error' => 'Invalid IPv4 address'];
                continue;
            }
            $recognizedBrand = $this->getBrandByMac($mac);
            $brand = $this->getSupportedDiscoveryBrand($mac);
            if (!$brand) {
                $unsupported[] = [
                    'mac' => strtoupper($mac),
                    'ip' => $ip,
                    'brand' => $recognizedBrand,
                    'reason' => $recognizedBrand ? 'Recognized brand is not provisionable' : 'Unknown device'
                ];
                continue;
            }
            $normalized[$mac] = [
                'mac' => $mac,
                'ip' => $ip,
                'brand' => $brand,
                'extension' => $extensionsByMac[$mac] ?? ''
            ];
        }

        $mappedRows = $this->db->query("SELECT mac_address, extension, ip_address FROM provisioner_devices")->fetchAll(\PDO::FETCH_ASSOC);
        $mappedByMac = [];
        $macByExtension = [];
        foreach ($mappedRows as $row) {
            $mappedMac = strtolower($row['mac_address']);
            $mappedByMac[$mappedMac] = $row;
            if ((string)$row['extension'] !== '') $macByExtension[(string)$row['extension']] = $mappedMac;
        }

        $extensionCandidates = [];
        foreach ($normalized as $mac => $device) {
            if (isset($mappedByMac[$mac]) || $device['extension'] === '') continue;
            $extensionCandidates[$device['extension']][] = $mac;
        }
        $extensionConflicts = [];
        foreach ($normalized as $mac => &$device) {
            $extension = $device['extension'];
            if ($extension === '' || isset($mappedByMac[$mac])) continue;
            $mappedConflict = isset($macByExtension[$extension]) && $macByExtension[$extension] !== $mac;
            $discoveryConflict = count($extensionCandidates[$extension] ?? []) > 1;
            if ($mappedConflict || $discoveryConflict) {
                $extensionConflicts[] = [
                    'mac' => strtoupper($mac),
                    'extension' => $extension,
                    'reason' => $mappedConflict ? 'Extension is already mapped to another MAC' : 'Extension matched multiple discovered phones'
                ];
                $device['extension'] = '';
            }
        }
        unset($device);

        $added = [];
        $refreshed = [];
        $unchanged = [];
        foreach ($normalized as $mac => $device) {
            if (isset($mappedByMac[$mac])) {
                $existing = $mappedByMac[$mac];
                if ($device['ip'] !== '' && $device['ip'] !== (string)($existing['ip_address'] ?? '')) {
                    $stmt = $this->db->prepare("UPDATE provisioner_devices SET ip_address=? WHERE mac_address=?");
                    if ($stmt && $stmt->execute([$device['ip'], $mac])) {
                        $refreshed[] = ['mac' => strtoupper($mac), 'ip' => $device['ip'], 'extension' => $existing['extension'] ?? ''];
                    } else {
                        $errors[] = ['mac' => strtoupper($mac), 'error' => 'Failed to refresh stored IP'];
                    }
                } else {
                    $unchanged[] = ['mac' => strtoupper($mac), 'ip' => $existing['ip_address'] ?? '', 'extension' => $existing['extension'] ?? ''];
                }
                continue;
            }
            $result = $this->addDevice($mac, $device['extension'], '', $device['brand'], $device['ip']);
            if (!empty($result['success'])) {
                $added[] = [
                    'mac' => strtoupper($mac), 'ip' => $device['ip'],
                    'extension' => $device['extension'], 'brand' => $device['brand']
                ];
            } else {
                $errors[] = ['mac' => strtoupper($mac), 'error' => $result['error'] ?? 'Failed to add device'];
            }
        }

        $withExtension = count(array_filter($added, function ($device) { return $device['extension'] !== ''; }));
        $status = $errors ? 'warning' : 'success';
        $this->addLog('device', 'Auto Add Supported: added ' . count($added) . ', refreshed ' . count($refreshed)
            . ', unsupported ' . count($unsupported) . ', errors ' . count($errors), $status);
        return [
            'success' => true,
            'added' => $added,
            'added_count' => count($added),
            'added_with_extension' => $withExtension,
            'added_without_extension' => count($added) - $withExtension,
            'refreshed' => $refreshed,
            'refreshed_count' => count($refreshed),
            'unchanged' => $unchanged,
            'unchanged_count' => count($unchanged),
            'unsupported' => $unsupported,
            'unsupported_count' => count($unsupported),
            'extension_conflicts' => $extensionConflicts,
            'errors' => $errors,
            'pjsip_warning' => $pjsipWarning
        ];
    }

    public function updateDevice($id, $mac, $extension, $description = '', $brand = null, $ip = '', $staticIp = null, $staticMask = null, $staticGw = null) {
        if ($brand !== null && !in_array($brand, $this->getBrands())) {
            $brand = 'digium';
        }
        $mac = strtolower(preg_replace('/[^a-fA-F0-9]/', '', $mac));
        if (strlen($mac) !== 12) {
            return ['success' => false, 'error' => 'Invalid MAC address — must be 12 hex characters'];
        }
        if ($ip && !filter_var($ip, FILTER_VALIDATE_IP)) {
            return ['success' => false, 'error' => 'Invalid IP address'];
        }
        if ($staticIp && !filter_var($staticIp, FILTER_VALIDATE_IP)) {
            return ['success' => false, 'error' => 'Invalid static IP address'];
        }
        if ($staticGw && !filter_var($staticGw, FILTER_VALIDATE_IP)) {
            return ['success' => false, 'error' => 'Invalid gateway IP address'];
        }
        try {
            $stmt = $this->db->prepare("SELECT extension, description, ip_address, static_ip, static_mask, static_gw, needs_changes, previous_extension, previous_description, previous_static_ip, previous_static_mask, previous_static_gw FROM provisioner_devices WHERE id=?");
            $stmt->execute([$id]);
            $old = $stmt->fetch(\PDO::FETCH_ASSOC);
            if (!$old) {
                return ['success' => false, 'error' => 'Device not found'];
            }
            if ($ip === '') $ip = $old['ip_address'] ?: '';

            $prevExt  = null;
            $prevDesc = null;
            $originalExt = !empty($old['needs_changes']) && $old['previous_extension'] !== null
                ? $old['previous_extension'] : $old['extension'];
            $originalDesc = !empty($old['needs_changes']) && $old['previous_description'] !== null
                ? $old['previous_description'] : $old['description'];
            if ($extension !== $originalExt) {
                $prevExt = $originalExt;
            }
            if ($description !== $originalDesc) {
                $prevDesc = $originalDesc;
            }

            $prevStaticIp = null;
            $prevStaticMask = null;
            $prevStaticGw = null;
            $originalStaticIp = !empty($old['needs_changes']) && $old['previous_static_ip'] !== null
                ? $old['previous_static_ip'] : $old['static_ip'];
            $originalStaticMask = !empty($old['needs_changes']) && $old['previous_static_mask'] !== null
                ? $old['previous_static_mask'] : $old['static_mask'];
            $originalStaticGw = !empty($old['needs_changes']) && $old['previous_static_gw'] !== null
                ? $old['previous_static_gw'] : $old['static_gw'];
            if ($staticIp !== $originalStaticIp) {
                $prevStaticIp = $originalStaticIp;
            }
            if ($staticMask !== $originalStaticMask) {
                $prevStaticMask = $originalStaticMask;
            }
            if ($staticGw !== $originalStaticGw) {
                $prevStaticGw = $originalStaticGw;
            }

            $now = date('Y-m-d H:i:s');
            $sql = "UPDATE provisioner_devices SET last_push_job_id=NULL, last_verify_status=CASE WHEN last_verify_status IN ('pending','verifying') THEN 'not_attempted' ELSE last_verify_status END, mac_address=?, extension=?, description=?, ip_address=NULLIF(?, ''), static_ip=NULLIF(?, ''), static_mask=NULLIF(?, ''), static_gw=NULLIF(?, ''), needs_changes=1, previous_extension=?, previous_description=?, previous_static_ip=?, previous_static_mask=?, previous_static_gw=?, updated_at=? WHERE id=?";
            $params = [$mac, $extension, $description, $ip ?: null, $staticIp, $staticMask, $staticGw, $prevExt, $prevDesc, $prevStaticIp, $prevStaticMask, $prevStaticGw, $now, $id];

            if ($brand !== null) {
                $sql = "UPDATE provisioner_devices SET last_push_job_id=NULL, last_verify_status=CASE WHEN last_verify_status IN ('pending','verifying') THEN 'not_attempted' ELSE last_verify_status END, mac_address=?, extension=?, description=?, brand=?, ip_address=NULLIF(?, ''), static_ip=NULLIF(?, ''), static_mask=NULLIF(?, ''), static_gw=NULLIF(?, ''), needs_changes=1, previous_extension=?, previous_description=?, previous_static_ip=?, previous_static_mask=?, previous_static_gw=?, updated_at=? WHERE id=?";
                $params = [$mac, $extension, $description, $brand, $ip ?: null, $staticIp, $staticMask, $staticGw, $prevExt, $prevDesc, $prevStaticIp, $prevStaticMask, $prevStaticGw, $now, $id];
            }

            $stmt = $this->db->prepare($sql);
            $stmt->execute($params);
            $this->addLog('device', "Updated device ID:$id", 'success');
            return ['success' => true];
        } catch (\Exception $e) {
            $this->addLog('device', "Failed to update device ID:$id - " . $e->getMessage(), 'error');
            return ['success' => false, 'error' => $e->getMessage()];
        }
    }

    public function deleteDevice($id) {
        $stmt = $this->db->prepare("SELECT mac_address FROM provisioner_devices WHERE id=?");
        $stmt->execute([$id]);
        $row = $stmt->fetch(\PDO::FETCH_ASSOC);
        $stmt = $this->db->prepare("DELETE FROM provisioner_devices WHERE id=?");
        $stmt->execute([$id]);
        if ($row) {
            $provDir = $this->getSetting('prov_dir') ?: '/var/www/html/provisioning';
            $this->cleanDeviceConfig($row['mac_address'], $provDir);
        }
        $this->addLog('device', "Deleted device ID:$id", 'warning');
        return ['success' => true];
    }

    public function deleteDevices($ids) {
        if (!is_array($ids)) $ids = [$ids];
        $placeholders = implode(',', array_fill(0, count($ids), '?'));
        $stmt = $this->db->prepare("SELECT mac_address FROM provisioner_devices WHERE id IN ($placeholders)");
        $stmt->execute($ids);
        $macs = $stmt->fetchAll(\PDO::FETCH_COLUMN);
        $provDir = $this->getSetting('prov_dir') ?: '/var/www/html/provisioning';
        foreach ($macs as $mac) {
            $this->cleanDeviceConfig($mac, $provDir);
        }
        $stmt = $this->db->prepare("DELETE FROM provisioner_devices WHERE id IN ($placeholders)");
        $stmt->execute($ids);
        $this->addLog('device', "Deleted " . count($ids) . " device(s)", 'warning');
        return ['success' => true, 'deleted' => count($ids)];
    }

    public function bulkImport($csv, $revision = null) {
        return $this->commitBulkImport($csv, $revision);
    }

    public function revertDevice($id) {
        $stmt = $this->db->prepare("SELECT extension, description, static_ip, static_mask, static_gw, previous_extension, previous_description, previous_static_ip, previous_static_mask, previous_static_gw FROM provisioner_devices WHERE id=?");
        $stmt->execute([$id]);
        $device = $stmt->fetch(\PDO::FETCH_ASSOC);
        if (!$device) {
            return ['success' => false, 'error' => 'Device not found'];
        }
        $newExt  = $device['previous_extension'] !== null ? $device['previous_extension'] : $device['extension'];
        $newDesc = $device['previous_description'] !== null ? $device['previous_description'] : $device['description'];
        $newStaticIp = $device['previous_static_ip'] !== null ? $device['previous_static_ip'] : $device['static_ip'];
        $newStaticMask = $device['previous_static_mask'] !== null ? $device['previous_static_mask'] : $device['static_mask'];
        $newStaticGw = $device['previous_static_gw'] !== null ? $device['previous_static_gw'] : $device['static_gw'];
        $stmt = $this->db->prepare("UPDATE provisioner_devices SET last_push_job_id=NULL, last_verify_status=CASE WHEN last_verify_status IN ('pending','verifying') THEN 'not_attempted' ELSE last_verify_status END, extension=?, description=?, static_ip=?, static_mask=?, static_gw=?, needs_changes=0, previous_extension=NULL, previous_description=NULL, previous_static_ip=NULL, previous_static_mask=NULL, previous_static_gw=NULL WHERE id=?");
        $stmt->execute([$newExt, $newDesc, $newStaticIp, $newStaticMask, $newStaticGw, $id]);
        $this->addLog('device', "Reverted device ID:$id", 'info');
        return ['success' => true];
    }

    public function discardAllChanges() {
        $stmt = $this->db->query("SELECT id, previous_extension, previous_description, previous_static_ip, previous_static_mask, previous_static_gw FROM provisioner_devices WHERE needs_changes = 1");
        $affected = 0;
        while ($device = $stmt->fetch(\PDO::FETCH_ASSOC)) {
            $newExt  = $device['previous_extension'] !== null ? $device['previous_extension'] : '';
            $newDesc = $device['previous_description'] !== null ? $device['previous_description'] : '';
            $newStaticIp = $device['previous_static_ip'] !== null ? $device['previous_static_ip'] : null;
            $newStaticMask = $device['previous_static_mask'] !== null ? $device['previous_static_mask'] : null;
            $newStaticGw = $device['previous_static_gw'] !== null ? $device['previous_static_gw'] : null;
            $this->db->prepare("UPDATE provisioner_devices SET last_push_job_id=NULL, last_verify_status=CASE WHEN last_verify_status IN ('pending','verifying') THEN 'not_attempted' ELSE last_verify_status END, extension=?, description=?, static_ip=?, static_mask=?, static_gw=?, needs_changes=0, previous_extension=NULL, previous_description=NULL, previous_static_ip=NULL, previous_static_mask=NULL, previous_static_gw=NULL WHERE id=?")
                ->execute([$newExt, $newDesc, $newStaticIp, $newStaticMask, $newStaticGw, $device['id']]);
            $affected++;
        }
        $this->addLog('device', "Discarded all changes for $affected device(s)", 'info');
        return ['success' => true, 'affected' => $affected];
    }

    // =====================
    // CONFIG FILES
    // =====================
    public function getConfigFiles() {
        $provDir = $this->getSetting('prov_dir') ?: '/var/www/html/provisioning';
        $files = [];
        if (!is_dir($provDir)) return $files;
        $dh = opendir($provDir);
        while (($file = readdir($dh)) !== false) {
            if (preg_match('/^([a-f0-9]{12})\.cfg$/i', $file, $m)) $mac = strtolower($m[1]);
            elseif (preg_match('/^config\.([a-f0-9]{12})\.xml$/i', $file, $m)) $mac = strtolower($m[1]);
            else continue;
            $path = $provDir . '/' . $file;
            $entry = [
                'mac'      => $mac,
                'file'     => $file,
                'size'     => filesize($path),
                'modified' => date('Y-m-d H:i:s', filemtime($path))
            ];
            if (!isset($files[$mac]) || filemtime($path) > strtotime($files[$mac]['modified'])) $files[$mac] = $entry;
        }
        closedir($dh);
        $files = array_values($files);
        usort($files, function ($a, $b) { return strcmp($b['modified'], $a['modified']); });
        return $files;
    }

    public function getConfigFileGrid() {
        $files = $this->getConfigFiles();
        $devices = $this->getDevices();
        $deviceMap = [];
        foreach ($devices as $d) {
            $deviceMap[$d['mac_address']] = $d;
        }
        $rows = [];
        foreach ($files as $f) {
            $device = $deviceMap[$f['mac']] ?? null;
            $ext = $device ? $device['extension'] : '-';
            $desc = $device ? $device['description'] : '-';
            $custom = $device && !empty($device['custom_config']);
            $backup = $this->getCustomBackupMetadata($f['mac']);
            $pullBtn = $custom
                ? '<a class="clickable revert-config-btn" data-mac="' . $f['mac'] . '" title="Revert to template-generated config"><i class="fa fa-undo"></i></a>'
                : '<a class="clickable fetch-config-btn" data-mac="' . $f['mac'] . '" title="Fetch current config from phone"><i class="fa fa-download"></i></a>';
            $state = $custom
                ? '<span class="badge badge-warning">Custom</span>'
                : '<span class="badge badge-primary">Template</span>';
            if ($device) {
                $configState = $this->getDeviceConfigExtensionState($device);
            } else {
                $brand = stripos($f['file'], 'config.') === 0 ? 'alcatel' : ($this->getBrandByMac($f['mac']) ?: 'digium');
                $configState = $this->getDeviceConfigExtensionState([
                    'mac_address' => $f['mac'], 'extension' => '', 'brand' => $brand,
                    'needs_changes' => 0, 'previous_extension' => null, 'custom_config' => 0
                ], ($this->getSetting('prov_dir') ?: '/var/www/html/provisioning') . '/' . $f['file']);
            }
            $state .= ' ' . $this->formatConfigSyncState($configState, true);
            $restoreBtn = '';
            if (!$custom && !empty($backup['exists'])) {
                if (!empty($backup['valid'])) {
                    $state .= ' <span class="badge badge-info">Pulled backup</span>';
                    $restoreBtn = ' <a class="clickable restore-pulled-btn" data-mac="' . $f['mac'] . '" title="Restore archived pulled config"><i class="fa fa-history"></i></a>';
                } else {
                    $state .= ' <span class="badge badge-danger">Invalid backup</span>';
                    $restoreBtn = ' <span class="text-danger" title="Archived pulled config failed validation"><i class="fa fa-exclamation-triangle"></i></span>';
                }
            }
            $rows[] = [
                'mac'         => $f['mac'],
                'assigned_extension' => $ext,
                'config_extension' => $configState['config_extension'] !== null
                    ? htmlspecialchars($configState['config_extension'], ENT_QUOTES) : '<span class="text-muted">Unknown</span>',
                'description' => $desc,
                'state'       => $state,
                'modified'    => $f['modified'],
                'actions'     => '<a class="clickable edit-config-btn" data-mac="' . $f['mac'] . '" data-ext="' . $ext . '" title="Edit"><i class="fa fa-edit"></i></a> '
                    . $pullBtn
                    . $restoreBtn
                    . ' <a class="clickable delete-config-btn" data-mac="' . $f['mac'] . '" title="Delete"><i class="fa fa-trash"></i></a>'
            ];
        }
        return $rows;
    }

    private function getDeviceBrand($mac) {
        $stmt = $this->db->prepare("SELECT brand FROM provisioner_devices WHERE mac_address=?");
        $stmt->execute([$mac]);
        $row = $stmt->fetch(\PDO::FETCH_ASSOC);
        return $row ? $row['brand'] : 'digium';
    }

    private function isValidPhoneConfig($mac, $content, $brand = null) {
        if (empty($content) || strlen($content) < 100) {
            return false;
        }
        if (preg_match('/<html|<head|<body|<title>/i', $content)) {
            return false;
        }
        if (stripos($content, '403 Forbidden') !== false || stripos($content, 'nginx') !== false || stripos($content, 'server too busy') !== false) {
            return false;
        }
        if (stripos($content, '<title>Logon</title>') !== false) {
            return false;
        }
        $brand = $brand ?: $this->getDeviceBrand($mac);
        if ($brand === 'digium') {
            if (strpos($content, '<<VOIP CONFIG FILE>>') === false) {
                return false;
            }
            if (strpos($content, '<SIP CONFIG MODULE>') === false && strpos($content, '<GLOBAL CONFIG MODULE>') === false) {
                return false;
            }
            if (strpos($content, '<<END OF FILE>>') === false) {
                return false;
            }
        } elseif ($brand === 'alcatel') {
            if (substr(trim($content), 0, 5) !== '<?xml') {
                return false;
            }
            $oldLibXml = libxml_use_internal_errors(true);
            $xml = simplexml_load_string($content);
            libxml_use_internal_errors($oldLibXml);
            if (!$xml) {
                return false;
            }
        } elseif ($brand === 'yealink') {
            if (strpos($content, '#!version:') !== 0 || !preg_match('/^\s*[a-zA-Z0-9_.-]+\s*=\s*/m', $content)) {
                return false;
            }
        }
        return true;
    }

    private function getDeviceConfigFilename($mac, $brand = null) {
        $mac = strtolower($mac);
        $brand = $brand ?: $this->getDeviceBrand($mac);
        return $brand === 'alcatel' ? 'config.' . $mac . '.xml' : $mac . '.cfg';
    }

    private function getConfigFilePath($mac, $brand = null) {
        $provDir = $this->getSetting('prov_dir') ?: '/var/www/html/provisioning';
        $path = $provDir . '/' . $this->getDeviceConfigFilename($mac, $brand);
        if (file_exists($path)) {
            return $path;
        }
        $dh = @opendir($provDir);
        if (!$dh) {
            return $path;
        }
        while (($file = readdir($dh)) !== false) {
            $ext = substr($file, -4);
            $fileMac = null;
            if ($ext === '.cfg') {
                $fileMac = substr($file, 0, -4);
            } elseif (preg_match('/^config\.([a-f0-9]{12})\.xml$/i', $file, $m)) {
                $fileMac = $m[1];
            }
            if ($fileMac !== null && strtolower($fileMac) === strtolower($mac)) {
                closedir($dh);
                return $provDir . '/' . $file;
            }
        }
        closedir($dh);
        return $path;
    }

    private function getCustomBackupPath($mac) {
        $provDir = $this->getSetting('prov_dir') ?: '/var/www/html/provisioning';
        $filename = $this->getDeviceConfigFilename($mac) . '.custom.bak';
        $path = $provDir . '/' . $filename;
        if (file_exists($path)) return $path;
        $handle = @opendir($provDir);
        if (!$handle) return $path;
        while (($file = readdir($handle)) !== false) {
            if (strcasecmp($file, $filename) === 0) {
                closedir($handle);
                return $provDir . '/' . $file;
            }
        }
        closedir($handle);
        return $path;
    }

    private function extractConfigExtension($mac, $content, $brand = null) {
        $brand = $brand ?: $this->getDeviceBrand($mac);
        if ($brand === 'digium') {
            if (preg_match('/^SIP1 Register User\s*:\s*(.*?)\s*$/mi', $content, $match)) return trim($match[1]);
            if (preg_match('/^SIP1 Phone Number\s*:\s*(.*?)\s*$/mi', $content, $match)) return trim($match[1]);
        } elseif ($brand === 'alcatel') {
            $oldLibXml = libxml_use_internal_errors(true);
            $xml = simplexml_load_string($content);
            libxml_use_internal_errors($oldLibXml);
            if ($xml) {
                $settings = $xml->xpath("//setting[@id='SIPGroup1AuthenticationName']");
                if ($settings && isset($settings[0]['value'])) return trim((string)$settings[0]['value']);
            }
        } elseif ($brand === 'yealink') {
            if (preg_match('/^\s*account\.1\.(?:user_name|auth_name)\s*=\s*(.*?)\s*$/mi', $content, $match)) return trim($match[1]);
        }
        return null;
    }

    private function getDeviceConfigExtensionState($device, $path = null) {
        $mac = strtolower((string)($device['mac_address'] ?? ''));
        $brand = $device['brand'] ?? 'digium';
        $assigned = (string)($device['extension'] ?? '');
        $previous = (string)($device['previous_extension'] ?? '');
        $pending = !empty($device['needs_changes']);
        $custom = !empty($device['custom_config']);
        $extensionChanged = $pending && $previous !== '' && $previous !== $assigned;
        $willRegenerate = $pending && (!$custom || $extensionChanged);
        $path = $path ?: $this->getConfigFilePath($mac, $brand);

        if (!is_file($path)) {
            return [
                'status' => 'missing', 'assigned_extension' => $assigned, 'config_extension' => null,
                'push_extension' => $willRegenerate ? $assigned : null, 'will_regenerate' => $willRegenerate
            ];
        }
        $content = file_get_contents($path);
        if ($content === false || !$this->isValidPhoneConfig($mac, $content, $brand)) {
            return [
                'status' => 'invalid', 'assigned_extension' => $assigned, 'config_extension' => null,
                'push_extension' => $willRegenerate ? $assigned : null, 'will_regenerate' => $willRegenerate
            ];
        }
        $configExtension = $this->extractConfigExtension($mac, $content, $brand);
        if ($configExtension === null || $configExtension === '') {
            $status = 'unknown';
        } elseif ($willRegenerate && $configExtension !== $assigned) {
            $status = 'pending_generation';
        } elseif ($assigned === '') {
            $status = 'unmapped';
        } elseif ($configExtension === $assigned) {
            $status = 'in_sync';
        } elseif ($custom) {
            $status = 'custom_mismatch';
        } else {
            $status = 'mismatch';
        }
        return [
            'status' => $status,
            'assigned_extension' => $assigned,
            'config_extension' => $configExtension,
            'push_extension' => $willRegenerate ? $assigned : $configExtension,
            'will_regenerate' => $willRegenerate
        ];
    }

    private function formatConfigSyncState($state, $detailed) {
        $status = $state['status'];
        $assigned = htmlspecialchars((string)($state['assigned_extension'] ?? ''), ENT_QUOTES);
        $config = htmlspecialchars((string)($state['config_extension'] ?? ''), ENT_QUOTES);
        if ($status === 'in_sync') return '<span class="badge badge-success">' . ($detailed ? 'Extension match' : 'In Sync') . '</span>';
        if ($status === 'pending_generation') {
            return '<span class="badge badge-warning">Will generate ' . $assigned . ' on Push</span>';
        }
        if ($status === 'custom_mismatch') {
            return '<span class="badge badge-danger">' . ($detailed ? 'Push uses config ' . $config : 'Custom Mismatch: file uses ' . $config) . '</span>';
        }
        if ($status === 'mismatch') {
            return '<span class="badge badge-danger">' . ($detailed ? 'Push uses config ' . $config : 'Mismatch: file uses ' . $config) . '</span>';
        }
        if ($status === 'missing') return '<span class="badge badge-danger">Config Missing</span>';
        if ($status === 'invalid') return '<span class="badge badge-danger">Invalid Config</span>';
        if ($status === 'unmapped') return '<span class="badge badge-default">Unmapped</span>';
        return '<span class="badge badge-warning">Extension Unknown</span>';
    }

    private function getCustomBackupMetadata($mac) {
        $mac = strtolower(preg_replace('/[^a-fA-F0-9]/', '', $mac));
        $path = $this->getCustomBackupPath($mac);
        $currentExtension = $this->getExtensionByMac($mac);
        if (!file_exists($path)) {
            return ['exists' => false, 'valid' => false, 'current_extension' => $currentExtension];
        }
        $content = file_get_contents($path);
        $valid = $content !== false && $this->isValidPhoneConfig($mac, $content);
        $archivedExtension = $valid ? $this->extractConfigExtension($mac, $content) : null;
        return [
            'exists' => true,
            'valid' => $valid,
            'file' => basename($path),
            'size' => filesize($path),
            'modified' => date('Y-m-d H:i:s', filemtime($path)),
            'archived_extension' => $archivedExtension,
            'current_extension' => $currentExtension,
            'extension_matches' => $archivedExtension !== null && (string)$archivedExtension === (string)$currentExtension
        ];
    }

    public function getCustomBackupInfo($mac) {
        $mac = strtolower(preg_replace('/[^a-fA-F0-9]/', '', $mac));
        if (strlen($mac) !== 12) return ['success' => false, 'error' => 'Invalid MAC address.'];
        $metadata = $this->getCustomBackupMetadata($mac);
        if (empty($metadata['exists'])) return ['success' => false, 'error' => 'Archived pulled config not found.'];
        return array_merge(['success' => true], $metadata);
    }

    public function getConfigFileContent($mac) {
        $path = $this->getConfigFilePath($mac);
        if (!file_exists($path)) {
            return ['success' => false, 'error' => 'Config file not found'];
        }
        $ext = $this->getExtensionByMac($mac);
        return ['success' => true, 'content' => file_get_contents($path), 'extension' => $ext];
    }

    public function saveConfigFile($mac, $content) {
        $configWriteLock = $this->acquireConfigWriteLock();
        if (!$configWriteLock) return ['success' => false, 'error' => 'Another config activation is already in progress. Try again shortly.'];
        $path = $this->getConfigFilePath($mac);
        $result = file_put_contents($path, $content);
        if ($result === false) {
            $this->addLog('config', "Failed to save config for $mac", 'error');
            return ['success' => false, 'error' => 'Failed to write config file'];
        }
        $this->db->prepare("UPDATE provisioner_devices SET last_push_job_id=NULL, last_verify_status=CASE WHEN last_verify_status IN ('pending','verifying') THEN 'not_attempted' ELSE last_verify_status END WHERE mac_address=?")->execute([$mac]);
        $this->addLog('config', "Saved config for $mac", 'success');
        return ['success' => true];
    }

    public function deleteConfigFile($mac) {
        $configWriteLock = $this->acquireConfigWriteLock();
        if (!$configWriteLock) return ['success' => false, 'error' => 'Another config activation is already in progress. Try again shortly.'];
        $path = $this->getConfigFilePath($mac);
        if (file_exists($path)) {
            unlink($path);
        }
        $this->db->prepare("UPDATE provisioner_devices SET last_push_job_id=NULL, last_verify_status=CASE WHEN last_verify_status IN ('pending','verifying') THEN 'not_attempted' ELSE last_verify_status END WHERE mac_address=?")->execute([$mac]);
        $this->addLog('config', "Deleted config for $mac", 'warning');
        return ['success' => true];
    }

    public function deleteSelectedConfigFiles($macs) {
        $count = 0;
        $errors = [];
        foreach ($macs as $mac) {
            $r = $this->deleteConfigFile($mac);
            if ($r['success']) $count++;
            else $errors[] = $mac;
        }
        $this->addLog('config', "Deleted $count config files", 'warning');
        return ['success' => true, 'deleted' => $count, 'errors' => $errors];
    }

    public function deleteAllConfigFiles() {
        $files = $this->getConfigFiles();
        $count = 0;
        foreach ($files as $f) {
            $this->deleteConfigFile($f['mac']);
            $count++;
        }
        $this->addLog('config', "Deleted all $count config files", 'warning');
        return ['success' => true, 'deleted' => $count];
    }

    private function acquirePhoneFetchLock($mac) {
        $uid = function_exists('posix_geteuid') ? posix_geteuid() : getmyuid();
        $path = sys_get_temp_dir() . '/provisioner-fetch-' . $uid . '-' . $mac . '.lock';
        $handle = @fopen($path, 'c');
        if (!$handle || !@flock($handle, LOCK_EX | LOCK_NB)) {
            if ($handle) fclose($handle);
            return null;
        }
        @chmod($path, 0600);
        return $handle;
    }

    private function acquireConfigWriteLock() {
        $uid = function_exists('posix_geteuid') ? posix_geteuid() : getmyuid();
        $path = sys_get_temp_dir() . '/provisioner-config-write-' . $uid . '.lock';
        $handle = @fopen($path, 'c');
        if (!$handle || !@flock($handle, LOCK_EX)) {
            if ($handle) fclose($handle);
            return null;
        }
        @chmod($path, 0600);
        return $handle;
    }

    private function releasePhoneFetchLock($handle) {
        if (!$handle) return;
        @flock($handle, LOCK_UN);
        @fclose($handle);
    }

    private function runFetchScript($mac, $ext, $ip) {
        $python = $this->findPython3Binary();
        if (!$python) return ['success' => false, 'error' => 'Python 3 not found (checked python3 and python3.6)'];
        $brand = $this->getDeviceBrand($mac);
        if ($brand === 'alcatel') {
            $script = 'fetch_alcatel_config.py';
            $pass = $this->getSetting('phone_admin_pass_alcatel') ?: '123456';
        } else {
            $script = 'fetch_digium_config.py';
            $pass = $this->getSetting('phone_admin_pass') ?: '789';
        }
        $cmd = escapeshellarg($python) . ' ' . escapeshellarg(__DIR__ . '/' . $script)
             . ' ' . escapeshellarg($ip)
             . ' admin'
             . ' ' . escapeshellarg($pass)
             . ' ' . escapeshellarg($mac) . ' 2>&1';
        $output = shell_exec($cmd);
        return json_decode($output, true);
    }

    private function fetchValidatedPhoneConfig($mac) {
        $mac = strtolower(preg_replace('/[^a-fA-F0-9]/', '', $mac));
        $stmt = $this->db->prepare("SELECT * FROM provisioner_devices WHERE mac_address=?");
        $stmt->execute([$mac]);
        $device = $stmt->fetch(\PDO::FETCH_ASSOC);
        if (!$device) {
            return ['success' => false, 'error' => 'Device not found in MAC Mapping'];
        }
        $ext = $device['extension'];
        $target = $this->resolveDeviceTarget($device);
        $ip = $target['selected_ip'];
        $source = $target['selected_source'] ?: 'none';
        if (!$ip) {
            return ['success' => false, 'error' => "Could not determine IP for extension $ext — phone may not be registered or on DHCP"];
        }
        $result = $this->runFetchScript($mac, $ext, $ip);
        if (!$result || !$result['success']) {
            $error = $result['error'] ?? 'Script execution failed';
            $this->addLog('config', "Fetch config failed for $mac (IP $ip): $error", 'error');
            return ['success' => false, 'error' => $error];
        }
        if (!$this->isValidPhoneConfig($mac, $result['config'])) {
            $errorMsg = 'Invalid config format — response does not match ' . $this->getDeviceBrand($mac) . ' phone config';
            $this->addLog('config', "Fetch config failed for $mac (IP $ip): $errorMsg", 'error');
            return ['success' => false, 'error' => $errorMsg];
        }
        return [
            'success' => true,
            'config' => $result['config'],
            'extension' => $ext,
            'ip' => $ip,
            'ip_source' => $source,
            'session_reused' => !empty($result['session_reused'])
        ];
    }

    private function activatePhoneConfig($mac, $content, $markPending = false) {
        $mac = strtolower(preg_replace('/[^a-fA-F0-9]/', '', $mac));
        $stmt = $this->db->prepare("SELECT id FROM provisioner_devices WHERE mac_address=?");
        $stmt->execute([$mac]);
        if (!$stmt->fetch(\PDO::FETCH_ASSOC)) return ['success' => false, 'error' => 'Device not found in MAC Mapping'];
        if (!$this->isValidPhoneConfig($mac, $content)) {
            return ['success' => false, 'error' => 'Invalid config format — content does not match ' . $this->getDeviceBrand($mac) . ' phone config'];
        }
        $configWriteLock = $this->acquireConfigWriteLock();
        if (!$configWriteLock) return ['success' => false, 'error' => 'Another config activation is already in progress. Try again shortly.'];

        $provDir = $this->getSetting('prov_dir') ?: '/var/www/html/provisioning';
        if (!is_dir($provDir)) {
            if (!mkdir($provDir, 0755, true)) {
                return ['success' => false, 'error' => 'Failed to create provisioning directory'];
            }
            @chown($provDir, 'asterisk');
            @chgrp($provDir, 'asterisk');
        }
        if (!is_writable($provDir)) return ['success' => false, 'error' => 'Provisioning directory is not writable'];
        $filename = $this->getDeviceConfigFilename($mac);
        $path = $provDir . '/' . $filename;
        $bakPath = $provDir . '/' . $filename . '.bak';
        $tmpPath = tempnam($provDir, '.pull-');
        if ($tmpPath === false) return ['success' => false, 'error' => 'Failed to create temporary config file'];

        $expectedBytes = strlen($content);
        $written = file_put_contents($tmpPath, $content, LOCK_EX);
        if ($written !== $expectedBytes) {
            @unlink($tmpPath);
            return ['success' => false, 'error' => 'Failed to write complete config file'];
        }
        $verifyContent = file_get_contents($tmpPath);
        if ($verifyContent === false || !$this->isValidPhoneConfig($mac, $verifyContent)) {
            @unlink($tmpPath);
            return ['success' => false, 'error' => 'Written config failed validation'];
        }

        $hadExisting = file_exists($path);
        if ($hadExisting && !copy($path, $bakPath)) {
            @unlink($tmpPath);
            return ['success' => false, 'error' => 'Failed to back up existing config file'];
        }
        @chmod($tmpPath, 0644);
        @chown($tmpPath, 'asterisk');
        @chgrp($tmpPath, 'asterisk');
        if (!rename($tmpPath, $path)) {
            @unlink($tmpPath);
            return ['success' => false, 'error' => 'Failed to activate pulled config file'];
        }

        $sql = $markPending
            ? "UPDATE provisioner_devices SET last_push_job_id=NULL, last_verify_status='not_attempted', custom_config=1, needs_changes=1 WHERE mac_address=?"
            : "UPDATE provisioner_devices SET last_push_job_id=NULL, last_verify_status='not_attempted', custom_config=1 WHERE mac_address=?";
        $stmt = $this->db->prepare($sql);
        if (!$stmt || !$stmt->execute([$mac])) {
            if ($hadExisting && file_exists($bakPath)) copy($bakPath, $path);
            elseif (!$hadExisting) @unlink($path);
            return ['success' => false, 'error' => 'Config was fetched but its database state could not be saved'];
        }
        return [
            'success' => true,
            'file' => $filename,
            'bytes' => $expectedBytes,
            'modified' => date('Y-m-d H:i:s', filemtime($path))
        ];
    }

    public function pullPhoneConfig($mac) {
        $mac = strtolower(preg_replace('/[^a-fA-F0-9]/', '', $mac));
        $lock = $this->acquirePhoneFetchLock($mac);
        if (!$lock) return ['success' => false, 'error' => 'A config fetch is already in progress for this phone.'];
        try {
            $fetched = $this->fetchValidatedPhoneConfig($mac);
            if (!$fetched['success']) return $fetched;
            $activated = $this->activatePhoneConfig($mac, $fetched['config']);
            if (!$activated['success']) return $activated;
            $session = $fetched['session_reused'] ? 'reused' : 'new';
            $this->addLog('config', "Pulled config from phone for $mac (ext {$fetched['extension']}) via {$fetched['ip']} source:{$fetched['ip_source']} session:$session", 'success');
            return array_merge($activated, [
                'message' => "Config pulled from phone ({$fetched['ip']}, {$fetched['ip_source']}). The previous config was backed up.",
                'ip' => $fetched['ip'],
                'ip_source' => $fetched['ip_source'],
                'session_reused' => $fetched['session_reused']
            ]);
        } finally {
            $this->releasePhoneFetchLock($lock);
        }
    }

    public function fetchPhoneConfig($mac) {
        $mac = strtolower(preg_replace('/[^a-fA-F0-9]/', '', $mac));
        $lock = $this->acquirePhoneFetchLock($mac);
        if (!$lock) return ['success' => false, 'error' => 'A config fetch is already in progress for this phone.'];
        try {
            $result = $this->fetchValidatedPhoneConfig($mac);
            if ($result['success']) {
                $session = $result['session_reused'] ? 'reused' : 'new';
                $this->addLog('config', "Fetched config from phone for $mac (ext {$result['extension']}) via {$result['ip']} source:{$result['ip_source']} session:$session", 'info');
            }
            return $result;
        } finally {
            $this->releasePhoneFetchLock($lock);
        }
    }

    public function saveAsActiveConfig($mac, $content) {
        $mac = strtolower(preg_replace('/[^a-fA-F0-9]/', '', $mac));
        $lock = $this->acquirePhoneFetchLock($mac);
        if (!$lock) return ['success' => false, 'error' => 'A config fetch or activation is already in progress for this phone.'];
        try {
            $result = $this->activatePhoneConfig($mac, $content);
            if (!$result['success']) return $result;
            $this->addLog('config', "Saved fetched config for $mac (with backup)", 'success');
            $result['message'] = 'Config saved as active config. Previous file backed up.';
            return $result;
        } finally {
            $this->releasePhoneFetchLock($lock);
        }
    }

    public function restoreCustomBackup($mac, $force = false) {
        $mac = strtolower(preg_replace('/[^a-fA-F0-9]/', '', $mac));
        if (strlen($mac) !== 12) return ['success' => false, 'error' => 'Invalid MAC address.'];
        $lock = $this->acquirePhoneFetchLock($mac);
        if (!$lock) return ['success' => false, 'error' => 'A config fetch or activation is already in progress for this phone.'];
        try {
            $stmt = $this->db->prepare("SELECT custom_config FROM provisioner_devices WHERE mac_address=?");
            $stmt->execute([$mac]);
            $device = $stmt->fetch(\PDO::FETCH_ASSOC);
            if (!$device) return ['success' => false, 'error' => 'Device not found in MAC Mapping.'];
            if (!empty($device['custom_config'])) return ['success' => false, 'error' => 'The archived pulled config is already active.'];

            $metadata = $this->getCustomBackupMetadata($mac);
            if (empty($metadata['exists'])) return ['success' => false, 'error' => 'Archived pulled config not found.'];
            if (empty($metadata['valid'])) return ['success' => false, 'error' => 'Archived pulled config failed validation.'];
            if (empty($metadata['extension_matches']) && !$force) {
                return array_merge(['success' => false, 'requires_confirmation' => true], $metadata);
            }

            $path = $this->getCustomBackupPath($mac);
            $content = file_get_contents($path);
            if ($content === false) return ['success' => false, 'error' => 'Failed to read archived pulled config.'];
            $result = $this->activatePhoneConfig($mac, $content, true);
            if (!$result['success']) return $result;
            $mismatch = empty($metadata['extension_matches']);
            $this->addLog('config', "Restored archived pulled config for $mac" . ($mismatch ? ' with forced extension mismatch' : ''), $mismatch ? 'warning' : 'success');
            return array_merge($result, [
                'message' => 'Archived pulled config restored. The generated config was backed up and the device is pending push.',
                'archived_extension' => $metadata['archived_extension'],
                'current_extension' => $metadata['current_extension'],
                'extension_matches' => $metadata['extension_matches']
            ]);
        } finally {
            $this->releasePhoneFetchLock($lock);
        }
    }

    public function revertConfigTemplate($mac) {
        $mac = strtolower(preg_replace('/[^a-fA-F0-9]/', '', $mac));
        if (strlen($mac) !== 12) return ['success' => false, 'error' => 'Invalid MAC address.'];
        $phoneLock = $this->acquirePhoneFetchLock($mac);
        if (!$phoneLock) return ['success' => false, 'error' => 'A config fetch or activation is already in progress for this phone.'];
        try {
            $stmt = $this->db->prepare("SELECT custom_config FROM provisioner_devices WHERE mac_address=?");
            $stmt->execute([$mac]);
            $device = $stmt->fetch(\PDO::FETCH_ASSOC);
            if (!$device) return ['success' => false, 'error' => 'Device not found in MAC Mapping.'];
            if (empty($device['custom_config'])) return ['success' => false, 'error' => 'This device is already using a template-generated config.'];

            $configWriteLock = $this->acquireConfigWriteLock();
            if (!$configWriteLock) return ['success' => false, 'error' => 'Another config activation is already in progress. Try again shortly.'];
            $provDir = $this->getSetting('prov_dir') ?: '/var/www/html/provisioning';
            if (!is_dir($provDir) || !is_writable($provDir)) {
                return ['success' => false, 'error' => 'Provisioning directory is not writable.'];
            }
            $filename = $this->getDeviceConfigFilename($mac);
            $cfgPath = $this->getConfigFilePath($mac);
            $bakPath = $provDir . '/' . $filename . '.bak';
            if (!file_exists($bakPath)) {
                $handle = @opendir($provDir);
                if ($handle) {
                    while (($file = readdir($handle)) !== false) {
                        if (strcasecmp($file, $filename . '.bak') === 0) {
                            $bakPath = $provDir . '/' . $file;
                            break;
                        }
                    }
                    closedir($handle);
                }
            }

            $hadActive = is_file($cfgPath);
            $activeContent = $hadActive ? file_get_contents($cfgPath) : null;
            if ($hadActive && $activeContent === false) {
                return ['success' => false, 'error' => 'Failed to read the active custom config for rollback.'];
            }
            $rollbackPath = null;
            if ($hadActive) {
                $rollbackPath = tempnam($provDir, '.revert-current-');
                $written = $rollbackPath === false ? false : file_put_contents($rollbackPath, $activeContent, LOCK_EX);
                if ($written !== strlen($activeContent)) {
                    if ($rollbackPath) @unlink($rollbackPath);
                    return ['success' => false, 'error' => 'Failed to preserve the active custom config for rollback.'];
                }
                @chmod($rollbackPath, 0600);
            }

            $restored = file_exists($bakPath);
            if ($restored) {
                $backupContent = is_file($bakPath) && is_readable($bakPath) ? file_get_contents($bakPath) : false;
                if ($backupContent === false || !$this->isValidPhoneConfig($mac, $backupContent)) {
                    if ($rollbackPath) @unlink($rollbackPath);
                    return ['success' => false, 'error' => 'The previous generated config backup is unreadable or invalid.'];
                }
                $stagedPath = tempnam($provDir, '.revert-stage-');
                $written = $stagedPath === false ? false : file_put_contents($stagedPath, $backupContent, LOCK_EX);
                if ($written !== strlen($backupContent)) {
                    if ($stagedPath) @unlink($stagedPath);
                    if ($rollbackPath) @unlink($rollbackPath);
                    return ['success' => false, 'error' => 'Failed to stage the previous generated config.'];
                }
                if (!@chmod($stagedPath, 0644) || !@chown($stagedPath, 'asterisk') || !@chgrp($stagedPath, 'asterisk')) {
                    @unlink($stagedPath);
                    if ($rollbackPath) @unlink($rollbackPath);
                    return ['success' => false, 'error' => 'Failed to set ownership or permissions on the staged config.'];
                }
                if (!@rename($stagedPath, $cfgPath)) {
                    @unlink($stagedPath);
                    if ($rollbackPath) @unlink($rollbackPath);
                    return ['success' => false, 'error' => 'Failed to atomically restore the previous generated config.'];
                }
            } elseif ($hadActive && !@unlink($cfgPath)) {
                @unlink($rollbackPath);
                return ['success' => false, 'error' => 'Failed to remove the active custom config.'];
            }

            try {
                $stmt = $this->db->prepare("UPDATE provisioner_devices SET custom_config=0, needs_changes=1 WHERE mac_address=?");
                if (!$stmt || !$stmt->execute([$mac])) throw new \RuntimeException('Database update failed');
            } catch (\Throwable $e) {
                $rolledBack = true;
                if ($hadActive) {
                    @chmod($rollbackPath, 0644);
                    @chown($rollbackPath, 'asterisk');
                    @chgrp($rollbackPath, 'asterisk');
                    $rolledBack = @rename($rollbackPath, $cfgPath);
                } elseif (file_exists($cfgPath)) {
                    $rolledBack = @unlink($cfgPath);
                }
                if ($rolledBack && $rollbackPath && file_exists($rollbackPath)) @unlink($rollbackPath);
                $error = $rolledBack
                    ? 'Database state could not be updated. The active custom config was restored.'
                    : 'Database state could not be updated and automatic file rollback failed. Manual recovery is required.';
                $this->addLog('config', "Revert failed for $mac: $error", 'error');
                return ['success' => false, 'error' => $error];
            }

            if ($rollbackPath && file_exists($rollbackPath)) @unlink($rollbackPath);
            $msg = $restored
                ? 'Reverted to previous config by restoring from backup.'
                : 'Reverted to template. Config will be regenerated on next push.';
            $this->addLog('config', "Reverted $mac" . ($restored ? ' (restored from backup)' : ''), 'info');
            return ['success' => true, 'message' => $msg];
        } finally {
            $this->releasePhoneFetchLock($phoneLock);
        }
    }

    public function pullSelectedConfigFiles($macs) {
        $count = 0;
        $errors = [];
        foreach ($macs as $mac) {
            $r = $this->pullPhoneConfig($mac);
            if ($r['success']) $count++;
            else $errors[] = ['mac' => $mac, 'error' => $r['error']];
        }
        $this->addLog('config', "Bulk pull: $count succeeded, " . count($errors) . " failed", $errors ? 'warning' : 'success');
        return ['success' => true, 'pulled' => $count, 'errors' => $errors];
    }

    public function revertSelectedConfigFiles($macs) {
        $count = 0;
        $errors = [];
        foreach ($macs as $mac) {
            $r = $this->revertConfigTemplate($mac);
            if ($r['success']) $count++;
            else $errors[] = ['mac' => $mac, 'error' => $r['error']];
        }
        $this->addLog('config', "Bulk revert: $count succeeded, " . count($errors) . " failed", $errors ? 'warning' : 'success');
        return ['success' => true, 'reverted' => $count, 'errors' => $errors];
    }

    private function getExtensionByMac($mac) {
        $stmt = $this->db->prepare("SELECT extension FROM provisioner_devices WHERE mac_address=?");
        $stmt->execute([$mac]);
        $row = $stmt->fetch(\PDO::FETCH_ASSOC);
        return $row ? $row['extension'] : '';
    }

    // =====================
    // PROVISIONING INFO
    // =====================
    public function getProvisioningInfo() {
        $pbxIp = $this->getSetting('pbx_ip') ?: '192.168.0.10';
        $provDir = $this->getSetting('prov_dir') ?: '/var/www/html/provisioning';
        $isDir = is_dir($provDir);
        $writable = $isDir && is_writable($provDir) ? true : ($isDir ? false : 'missing');
        $deviceCount = 0;
        $modelFiles = [];
        if ($isDir) {
            $dh = opendir($provDir);
            while (($file = readdir($dh)) !== false) {
                $ext = substr($file, -4);
                $path = $provDir . '/' . $file;
                if ($ext === '.cfg') {
                    if (preg_match('/^f0A\d+hw1\.100\.cfg$/', $file)) {
                        $modelFiles[] = [
                            'name'     => $file,
                            'size'     => filesize($path),
                            'modified' => date('Y-m-d H:i:s', filemtime($path))
                        ];
                    } elseif ($file !== 'template.cfg' && preg_match('/^[a-f0-9]{12}\.cfg$/i', $file)) {
                        $deviceCount++;
                    }
                } elseif (preg_match('/^config\.[a-f0-9]{12}\.xml$/i', $file)) {
                    $deviceCount++;
                }
            }
            closedir($dh);
        }

        $modelCount = count($modelFiles);
        asort($modelFiles);

        return [
            'pbx_ip'       => $pbxIp,
            'prov_dir'     => $provDir,
            'dir_exists'   => $isDir,
            'dir_writable' => $writable,
            'device_count' => $deviceCount,
            'model_count'  => $modelCount,
            'model_files'  => array_values($modelFiles)
        ];
    }

    public function checkDhcpOption66() {
        $expected = 'http://' . ($this->getSetting('pbx_ip') ?: '192.168.0.10') . ':2001/provisioning/';
        $path = '/etc/asterisk/dnsmasq.conf';
        if (!file_exists($path) || !is_readable($path)) {
            return ['set' => false, 'correct' => false, 'value' => null, 'expected' => $expected];
        }
        $lines = file($path, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
        foreach ($lines as $line) {
            if (preg_match('/^dhcp-option\s*=\s*[^,]+,\s*66\s*,\s*(.*)$/i', trim($line), $m)) {
                $value = trim($m[1]);
                return ['set' => true, 'correct' => $value === $expected, 'value' => $value, 'expected' => $expected];
            }
        }
        return ['set' => false, 'correct' => false, 'value' => null, 'expected' => $expected];
    }

    public function getDhcpLeases() {
        if (($this->getSetting('deploy_mode') ?: 'dhcp') !== 'dhcp') {
            return ['success' => true, 'leases' => [], 'count' => 0, 'disabled' => true, 'message' => 'DHCP leases are disabled in SIP NOTIFY mode.'];
        }
        $path = $this->getSetting('dhcp_leases_file') ?: '/var/lib/misc/dnsmasq.leases';
        if (!is_readable($path)) {
            return ['success' => false, 'error' => "DHCP lease file not readable: $path"];
        }
        $lines = @file($path, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
        if (!$lines || !is_array($lines)) {
            return ['success' => false, 'error' => "Could not read DHCP lease file: $path"];
        }
        $leases = [];
        $seen = [];
        foreach ($lines as $line) {
            if (!preg_match('/^\d+\s+([a-f0-9:]{17})\s+(\d+\.\d+\.\d+\.\d+)\s/', trim($line), $m)) continue;
            $mac = str_replace(':', '', strtolower($m[1]));
            if (isset($seen[$mac])) continue;
            $seen[$mac] = true;
            $brand = $this->getBrandByMac($mac);
            $supportedBrand = $this->getSupportedDiscoveryBrand($mac);
            $inProvisioner = $this->checkProvisioner($mac);
            $leases[] = [
                'mac' => strtoupper($mac),
                'mac_formatted' => $this->formatMac($mac),
                'ip' => $m[2],
                'vendor' => $brand ? ucfirst($brand) : 'Unknown',
                'brand' => $brand,
                'model' => '-',
                'source' => 'DHCP:' . basename($path),
                'is_phone' => $brand !== null,
                'is_supported' => $supportedBrand !== null,
                'in_provisioner' => $inProvisioner,
                'status' => $inProvisioner ? 'added' : 'new',
            ];
        }
        return ['success' => true, 'leases' => $leases, 'count' => count($leases), 'file_path' => $path];
    }

    public function checkFirewallProvisioning() {
        $subnetsToCheck = [];

        $pbxIp = $this->getSetting('pbx_ip');
        if ($pbxIp) {
            $longIp = ip2long($pbxIp);
            if ($longIp !== false) {
                foreach ($this->getAllSubnets() as $cidr) {
                    $parts = explode('/', $cidr);
                    $net = $parts[0];
                    $prefix = (int)($parts[1] ?? 24);
                    $mask = -1 << (32 - $prefix);
                    if (($longIp & $mask) === (ip2long($net) & $mask)) {
                        $subnetsToCheck[] = $cidr;
                        break;
                    }
                }
            }
        }

        $trusted = [];
        $internal = [];
        exec('timeout 15s fwconsole firewall list trusted 2>/dev/null', $t, $trustedStatus);
        exec('timeout 15s fwconsole firewall list internal 2>/dev/null', $i, $internalStatus);
        if ($trustedStatus === 124 || $internalStatus === 124) {
            return ['success' => false, 'error' => 'Firewall zone check timed out.'];
        }

        foreach ($t as $l) {
            if (preg_match('/^\d+\.\d+\.\d+\.\d+\/\d+/', trim($l), $m)) {
                $trusted[] = $m[0];
            }
        }
        foreach ($i as $l) {
            if (preg_match('/^\d+\.\d+\.\d+\.\d+\/\d+/', trim($l), $m)) {
                $internal[] = $m[0];
            }
        }

        $blocked = [];
        foreach ($subnetsToCheck as $s) {
            if (in_array($s, $trusted) || in_array($s, $internal)) {
                continue;
            }
            $blocked[] = $s;
        }

        if (empty($blocked)) {
            return [
                'success' => true,
                'blocked' => false,
                'message' => 'Phone subnet(s) are registered in firewall zones and provisioning should work.'
            ];
        }

        return [
            'success' => true,
            'blocked' => true,
            'blocked_subnets' => $blocked,
            'message' => implode(', ', $blocked) . ' not found in firewall trusted or internal zones and may be blocked. '
                . '<a href="./config.php?display=firewall&tab=networks" target="_blank">Add to Firewall &rarr; Networks</a>.'
        ];
    }

    public function getNetworkSubnets() {
        $interfaces = $this->getNetworkInterfaces();
        $pbxIp = $this->getSetting('pbx_ip') ?: '';
        $selectedSubnet = null;
        foreach ($interfaces as $interface) {
            if ($interface['ip'] === $pbxIp) {
                $selectedSubnet = $interface['subnet'];
                break;
            }
        }
        return [
            'success' => true,
            'subnets' => $this->getAllSubnets(),
            'interfaces' => $interfaces,
            'selected_subnet' => $selectedSubnet
        ];
    }

    private function getNetworkInterfaces() {
        $output = shell_exec("ip -o -f inet addr show 2>/dev/null");
        $interfaces = [];
        if ($output) {
            foreach (explode("\n", trim($output)) as $line) {
                if (preg_match('/^(\d+):\s+(\S+)\s+inet\s+(\d+\.\d+\.\d+\.\d+)\/(\d+)/', $line, $m)) {
                    $ip = $m[3]; $prefix = (int)$m[4];
                    if ($prefix < 8 || $prefix > 30) continue;
                    if (strpos($ip, '127.') === 0 || strpos($ip, '169.254.') === 0) continue;
                    $network = long2ip(ip2long($ip) & (-1 << (32 - $prefix)));
                    $subnet = "$network/$prefix";
                    $interfaces[] = [
                        'name' => $m[2],
                        'ip' => $ip,
                        'subnet' => $subnet,
                        'label' => $m[2] . ' (' . $subnet . ')',
                    ];
                }
            }
        }
        return $interfaces;
    }

    // =====================
    // LOGS
    // =====================
    public function addLog($action, $details, $status = 'info') {
        try {
            $stmt = $this->db->prepare("INSERT INTO provisioner_logs (timestamp, action, details, status) VALUES (NOW(), ?, ?, ?)");
            $stmt->execute([$action, $details, $status]);
        } catch (\Exception $e) {}
        $this->notifyLiveStatus();
    }

    public function getLogs($limit = 100) {
        $stmt = $this->db->prepare("SELECT * FROM provisioner_logs ORDER BY id DESC LIMIT ?");
        $stmt->bindValue(1, (int)$limit, \PDO::PARAM_INT);
        $stmt->execute();
        return $stmt->fetchAll(\PDO::FETCH_ASSOC);
    }

    public function getLogGrid() {
        $rows = $this->getLogs(200);
        $result = [];
        foreach ($rows as $r) {
            $statusClass = $r['status'] === 'success' ? 'text-success' : ($r['status'] === 'error' ? 'text-danger' : 'text-muted');
            $result[] = [
                'timestamp' => $r['timestamp'],
                'action'    => $r['action'],
                'details'   => $r['details'],
                'status'    => '<span class="' . $statusClass . '">' . ucfirst($r['status']) . '</span>'
            ];
        }
        return $result;
    }

    public function clearLogs() {
        $this->db->query("DELETE FROM provisioner_logs");
        $this->addLog('logs', 'Logs cleared', 'warning');
        return ['success' => true];
    }

    // =====================
    // TEMPLATE
    // =====================
    public function getTemplateContent($brand = 'digium') {
        if (!in_array($brand, $this->getBrands())) $brand = 'digium';
        $path = __DIR__ . '/templates/' . $brand . '/default.cfg';
        if (!file_exists($path)) {
            // Fallback to digium
            $path = __DIR__ . '/templates/digium/default.cfg';
        }
        if (!file_exists($path)) {
            return ['success' => false, 'error' => 'Template file not found.'];
        }
        $raw = file_get_contents($path);
        if ($raw === false) {
            return ['success' => false, 'error' => 'Failed to read template.'];
        }
        $passKey = $this->getBrandPassKey($brand);
        $password = $passKey ? ($this->getSetting($passKey) ?: '') : '';
        return ['success' => true, 'content' => $raw, 'password' => $password];
    }

    public function saveTemplateContent($content, $brand = 'digium') {
        if (!in_array($brand, $this->getBrands())) $brand = 'digium';
        $path = __DIR__ . '/templates/' . $brand . '/default.cfg';
        $dir = dirname($path);
        if (!is_dir($dir)) {
            mkdir($dir, 0755, true);
        }
        $result = file_put_contents($path, $content);
        if ($result === false) {
            return ['success' => false, 'error' => 'Failed to write template.'];
        }
        $passKey = $this->getBrandPassKey($brand);
        if ($passKey && isset($_REQUEST['password'])) {
            $this->setSetting($passKey, $_REQUEST['password']);
        }
        $this->addLog('settings', "$brand template saved", 'success');
        return ['success' => true];
    }

    private function getBrandPassKey($brand) {
        $map = [
            'digium' => 'phone_admin_pass',
            'alcatel' => 'phone_admin_pass_alcatel',
        ];
        return $map[$brand] ?? null;
    }

    public function getDefaultTemplateContent($brand = 'digium') {
        if (!in_array($brand, $this->getBrands())) $brand = 'digium';
        $path = __DIR__ . '/templates/' . $brand . '/default.cfg.dist';
        if (!file_exists($path)) {
            $path = __DIR__ . '/templates/digium/default.cfg.dist';
        }
        if (!file_exists($path)) {
            return ['success' => false, 'error' => 'Default template not found.'];
        }
        $raw = file_get_contents($path);
        return ['success' => true, 'content' => $raw];
    }

    public function resetTemplate($brand = 'digium') {
        if (!in_array($brand, $this->getBrands())) $brand = 'digium';
        $dist = __DIR__ . '/templates/' . $brand . '/default.cfg.dist';
        if (!file_exists($dist)) {
            $dist = __DIR__ . '/templates/digium/default.cfg.dist';
        }
        $target = __DIR__ . '/templates/' . $brand . '/default.cfg';
        if (file_exists($dist)) {
            copy($dist, $target);
            $this->addLog('settings', "$brand template reset to default", 'success');
            return ['success' => true];
        }
        return ['success' => false, 'error' => 'Default template not found.'];
    }

    // =====================
    // DISCOVERY HELPERS
    // =====================

    private function getSubnet() {
        $output = shell_exec("ip -o -f inet addr show 2>/dev/null");
        if (!$output) return '172.16.138.0/24';
        foreach (explode("\n", trim($output)) as $line) {
            if (preg_match('/inet (\d+\.\d+\.\d+\.\d+)\/(\d+)/', $line, $m)) {
                $ip = $m[1]; $prefix = (int)$m[2];
                if ($prefix < 8 || $prefix > 30) continue;
                if (strpos($ip, '127.') === 0 || strpos($ip, '169.254.') === 0) continue;
                $network = long2ip(ip2long($ip) & (-1 << (32 - $prefix)));
                return "$network/$prefix";
            }
        }
        return '172.16.138.0/24';
    }

    private function getAllSubnets() {
        $output = shell_exec("ip -o -f inet addr show 2>/dev/null");
        if (!$output) return ['172.16.138.0/24'];
        $subnets = [];
        foreach (explode("\n", trim($output)) as $line) {
            if (preg_match('/inet (\d+\.\d+\.\d+\.\d+)\/(\d+)/', $line, $m)) {
                $ip = $m[1]; $prefix = (int)$m[2];
                if ($prefix < 8 || $prefix > 30) continue;
                if (strpos($ip, '127.') === 0 || strpos($ip, '169.254.') === 0) continue;
                $network = long2ip(ip2long($ip) & (-1 << (32 - $prefix)));
                $subnets[] = "$network/$prefix";
            }
        }
        return $subnets ?: ['172.16.138.0/24'];
    }

    private function formatMac($mac) {
        $mac = preg_replace('/[^a-f0-9]/i', '', $mac);
        if (strlen($mac) !== 12) return $mac;
        return strtoupper(substr($mac, 0, 2)) . ':' . strtoupper(substr($mac, 2, 2)) . ':'
             . strtoupper(substr($mac, 4, 2)) . ':' . strtoupper(substr($mac, 6, 2)) . ':'
             . strtoupper(substr($mac, 8, 2)) . ':' . strtoupper(substr($mac, 10, 2));
    }

    private function getLocalIP() {
        try {
            $db = \FreePBX::Database();
            $stmt = $db->query("SELECT `values` FROM `endpoint_global` WHERE `key`='internal'");
            $row = $stmt->fetch(\PDO::FETCH_ASSOC);
            if ($row && !empty($row['values'])) return $row['values'];
        } catch (\Exception $e) {}
        if (!empty($_SERVER['SERVER_ADDR'])) return $_SERVER['SERVER_ADDR'];
        $ip = gethostbyname(gethostname());
        return ($ip !== gethostname()) ? $ip : '192.168.1.1';
    }

    private function checkProvisioner($mac) {
        try {
            $stmt = $this->db->prepare("SELECT id FROM provisioner_devices WHERE mac_address = ?");
            $stmt->execute([strtolower(preg_replace('/[^a-f0-9]/i', '', $mac))]);
            return (bool)$stmt->fetch(\PDO::FETCH_ASSOC);
        } catch (\Exception $e) {
            return false;
        }
    }

    private function resolveDeviceIpsLocal($subnets = null) {
        if ($subnets === null) $subnets = $this->getArpCapableSubnets();
        if (empty($subnets)) return [];

        $ping = trim(shell_exec('which ping 2>/dev/null'));
        if (!$ping) return [];

        $arpMap = [];
        foreach ($subnets as $subnet) {
            $neighbors = $this->pingSweepSubnet($subnet, $ping, 3, 50);
            foreach ($neighbors as $mac => $entry) {
                if (!isset($arpMap[$mac]) || !empty($entry['seen_now'])) {
                    $arpMap[$mac] = $entry['ip'];
                }
            }
        }

        return $arpMap;
    }

    private function parseSubnetNeighborEntries($output, $subnet) {
        $parts = explode('/', $subnet);
        if (count($parts) !== 2 || ip2long($parts[0]) === false) return [];
        $prefix = (int)$parts[1];
        if ($prefix < 8 || $prefix > 32) return [];
        $maskLong = -1 << (32 - $prefix);
        $netLong = ip2long($parts[0]) & $maskLong;
        $entries = [];
        foreach (explode("\n", trim((string)$output)) as $line) {
            $line = trim($line);
            if (!preg_match('/^(\d+\.\d+\.\d+\.\d+)\s+.*\blladdr\s+([a-f0-9:]{17})\b/i', $line, $match)) continue;
            $ipLong = ip2long($match[1]);
            if ($ipLong === false || ($ipLong & $maskLong) !== $netLong) continue;
            $mac = strtolower(str_replace(':', '', $match[2]));
            $tokens = preg_split('/\s+/', $line);
            $state = strtoupper((string)end($tokens));
            if ($mac === '000000000000' || in_array($state, ['FAILED', 'INCOMPLETE'], true)) continue;
            $seenNow = in_array($state, ['REACHABLE', 'DELAY', 'PROBE'], true);
            if (!isset($entries[$mac]) || $seenNow) {
                $entries[$mac] = [
                    'ip' => $match[1],
                    'mac' => $mac,
                    'neighbor_state' => $state,
                    'seen_now' => $seenNow
                ];
            }
        }
        return $entries;
    }

    private function getSubnetNeighborEntries($subnet) {
        $output = shell_exec('ip neigh show to ' . escapeshellarg($subnet) . ' 2>/dev/null');
        return $this->parseSubnetNeighborEntries($output, $subnet);
    }

    private function pingSweepSubnet($subnet, $ping, $passes = 1, $parallel = 50, $progress = null) {
        $subnet = trim($subnet);
        if (empty($subnet)) return [];
        $parts = explode('/', $subnet);
        if (count($parts) !== 2) return [];
        $prefix = (int)$parts[1];
        if ($prefix < 8 || $prefix > 32 || ip2long($parts[0]) === false) return [];
        $hostBits = 32 - $prefix;
        if ($hostBits > 10) return [];

        $base = ip2long($parts[0]) & (-1 << (32 - $prefix));
        $count = 1 << $hostBits;
        $hostList = [];
        $first = $prefix >= 31 ? 0 : 1;
        $end = $prefix >= 31 ? $count : $count - 1;
        for ($i = $first; $i < $end; $i++) {
            $hostList[] = long2ip($base + $i);
        }

        $tmpDir = $this->getSetting('prov_dir') ?: '/var/www/html/provisioning';
        $pingDir = $tmpDir . '/tmp';
        if (!is_dir($pingDir)) @mkdir($pingDir, 0755, true);
        $tmpfile = tempnam($pingDir, 'ping_');
        if ($tmpfile === false || file_put_contents($tmpfile, implode("\n", $hostList)) === false) {
            if ($tmpfile) @unlink($tmpfile);
            return $this->getSubnetNeighborEntries($subnet);
        }
        $passes = max(1, min(3, (int)$passes));
        $parallel = max(1, min(50, (int)$parallel));
        $neighbors = $this->getSubnetNeighborEntries($subnet);
        for ($pass = 0; $pass < $passes; $pass++) {
            shell_exec("xargs -a " . escapeshellarg($tmpfile) . " -P" . $parallel . " -n1 " . escapeshellcmd($ping) . " -c1 -W1 >/dev/null 2>&1");
            foreach ($this->getSubnetNeighborEntries($subnet) as $mac => $entry) {
                if (!isset($neighbors[$mac]) || !empty($entry['seen_now'])) $neighbors[$mac] = $entry;
            }
            if ($progress) $progress($neighbors, $pass + 1, $passes);
            if ($pass + 1 < $passes) usleep(500000);
        }
        @unlink($tmpfile);
        return $neighbors;
    }

    /**
     * Return the access logs that may contain requests for the provisioning
     * directory.  FreePBX installations commonly use the vhost log, while
     * stock Apache installations commonly use access.log.  A setting is
     * accepted for installations with a custom log location, but it is not
     * required for normal operation.
     */
    private function getProvisioningAccessLogPaths() {
        $paths = [];
        $configured = trim((string)$this->getSetting('apache_access_log'));
        if ($configured !== '') $paths[] = $configured;
        foreach ([
            '/var/log/apache2/other_vhosts_access.log',
            '/var/log/apache2/access.log',
            '/var/log/httpd/access_log',
            '/var/log/httpd/access.log'
        ] as $path) {
            if (!in_array($path, $paths, true)) $paths[] = $path;
        }
        return $paths;
    }

    /**
     * Arm a config file for download observation before sending the SIP
     * trigger.  The access log is the primary signal.  On this PBX Apache's
     * log is normally root:adm and the asterisk PHP worker cannot read it, so
     * a temporary relatime/atime probe provides a useful unprivileged
     * fallback without changing the provisioning URL or using the handset
     * WebUI API.
     */
    private function beginFetchObservation($mac) {
        $mac = strtolower(preg_replace('/[^a-f0-9]/i', '', $mac));
        $path = $this->getConfigFilePath($mac);
        clearstatcache(true, $path);
        $observation = [
            'mac' => $mac,
            'filename' => basename($path),
            'path' => $path,
            'logs' => [],
            'armed' => false,
            'observed' => null,
            'original_mtime' => is_file($path) ? @filemtime($path) : null,
            'original_atime' => is_file($path) ? @fileatime($path) : null,
            'probe_atime' => null
        ];

        foreach ($this->getProvisioningAccessLogPaths() as $logPath) {
            clearstatcache(true, $logPath);
            if (!is_readable($logPath)) continue;
            $size = @filesize($logPath);
            $observation['logs'][$logPath] = $size === false ? 0 : (int)$size;
        }

        if (is_file($path) && is_writable($path)
            && $observation['original_mtime'] !== false && $observation['original_atime'] !== false) {
            // Make relatime update atime on the next HTTP read.  The original
            // timestamps are restored by finishFetchObservation().
            $now = time();
            $probeAtime = $now - 5;
            $probeMtime = $now + 5;
            if (@touch($path, $probeMtime, $probeAtime)) {
                $observation['armed'] = true;
                $observation['probe_atime'] = $probeAtime;
                clearstatcache(true, $path);
            }
        }

        return $observation;
    }

    private function parseProvisioningFetchLog($line, $filename) {
        $quotedFilename = preg_quote($filename, '/');
        if (!preg_match('/"(?:GET|HEAD)\s+[^"\s]*\/' . $quotedFilename . '(?:[?\s"])/i', $line)) {
            return null;
        }
        if (!preg_match('/"(?:GET|HEAD)\s+[^"\s]+\s+HTTP\/[^"\s]+"\s+(\d{3})\b/i', $line, $statusMatch)) {
            return null;
        }

        $clientIp = null;
        $requestStart = strpos($line, '"');
        $prefix = $requestStart === false ? $line : substr($line, 0, $requestStart);
        if (preg_match_all('/(?<![\d.])(?:\d{1,3}\.){3}\d{1,3}(?![\d.])/', $prefix, $ipMatches)
            && !empty($ipMatches[0])) {
            $clientIp = end($ipMatches[0]);
        }

        $statusCode = (int)$statusMatch[1];
        return [
            'observed' => true,
            'success' => $statusCode >= 200 && $statusCode < 400,
            'status_code' => $statusCode,
            'ip' => $clientIp,
            'source' => 'access_log'
        ];
    }

    private function checkFetchObservation(&$observation) {
        if (!empty($observation['observed'])) return $observation['observed'];

        foreach ($observation['logs'] as $logPath => $offset) {
            clearstatcache(true, $logPath);
            if (!is_readable($logPath)) continue;
            $size = @filesize($logPath);
            if ($size === false) continue;
            $size = (int)$size;
            $start = $size >= (int)$offset ? (int)$offset : 0;
            $handle = @fopen($logPath, 'rb');
            if (!$handle) continue;
            if (fseek($handle, $start) !== 0) {
                fclose($handle);
                continue;
            }
            $chunk = stream_get_contents($handle, 4 * 1024 * 1024);
            fclose($handle);
            if ($chunk === false || $chunk === '') continue;
            foreach (preg_split('/\r?\n/', $chunk) as $line) {
                $result = $this->parseProvisioningFetchLog($line, $observation['filename']);
                if ($result === null) continue;
                $observation['observed'] = $result;
                return $result;
            }
        }

        // Apache reads the config in a different worker. Refresh the stat
        // cache on every poll, including the first poll after arming atime.
        clearstatcache(true, $observation['path']);
        if (!empty($observation['armed']) && is_file($observation['path'])) {
            $atime = @fileatime($observation['path']);
            if ($atime !== false && $observation['probe_atime'] !== null && $atime > $observation['probe_atime']) {
                $observation['observed'] = [
                    'observed' => true,
                    'success' => true,
                    'status_code' => 200,
                    'ip' => null,
                    'source' => 'file_access'
                ];
                return $observation['observed'];
            }
        }

        return null;
    }

    private function finishFetchObservation(&$observation) {
        clearstatcache(true, $observation['path']);
        if (empty($observation['armed']) || !is_file($observation['path'])) return;
        $atime = $observation['original_atime'];
        if (!empty($observation['observed'])) {
            $currentAtime = @fileatime($observation['path']);
            if ($currentAtime !== false && ($atime === false || $currentAtime > $atime)) $atime = $currentAtime;
        }
        if ($observation['original_mtime'] !== false && $atime !== false) {
            @touch($observation['path'], $observation['original_mtime'], $atime);
            clearstatcache(true, $observation['path']);
        }
        $observation['armed'] = false;
    }

    private function setPushTriggered($mac, $phoneIp, $jobId = null) {
        $guard = $jobId === null ? '' : ' AND last_push_job_id=?';
        if ($jobId === null) $jobId = bin2hex(random_bytes(16));
        try {
            $params = [$phoneIp, $jobId, strtolower($mac)];
            if ($guard) $params[] = $jobId;
            $this->db->prepare("UPDATE provisioner_devices SET ip_address=?, last_push_job_id=?, last_ack_at=NOW(), last_push_status='triggered', last_push_attempt_at=NOW(), last_fetch_at=NULL, last_fetch_ip=NULL, last_fetch_status='pending', last_verify_at=NULL, last_verify_ip=NULL, last_verify_status='pending' WHERE mac_address=?$guard")
                ->execute($params);
        } catch (\Exception $e) {}
        $this->recordBatchStage($mac, ['trigger'=>'accepted','target_ip'=>$phoneIp,'trigger_at'=>date('Y-m-d H:i:s')], $jobId);
        $this->notifyLiveStatus();
    }

    private function setPushTriggerFailed($mac, $jobId = null) {
        try {
            $guard = $jobId === null ? '' : ' AND last_push_job_id=?';
            $params = [strtolower($mac)];
            if ($guard) $params[] = $jobId;
            $this->db->prepare("UPDATE provisioner_devices SET last_push_job_id=NULL, last_push_status='failed', last_push_attempt_at=NOW(), last_fetch_status='failed', last_verify_status='failed', needs_changes=1 WHERE mac_address=?$guard")
                ->execute($params);
        } catch (\Exception $e) {}
        $this->recordBatchStage($mac, ['trigger'=>'failed','outcome'=>'failed','error'=>'SIP trigger was not accepted.'], $jobId);
        $this->notifyLiveStatus();
    }

    private function setPushDownloaded($mac, $fetchIp, $jobId = null) {
        try {
            $guard = $jobId === null ? '' : ' AND last_push_job_id=?';
            $params = [$fetchIp, strtolower($mac)];
            if ($guard) $params[] = $jobId;
            $this->db->prepare("UPDATE provisioner_devices SET last_push_status='downloaded', last_fetch_at=NOW(), last_fetch_ip=?, last_fetch_status='downloaded', last_verify_status='pending' WHERE mac_address=?$guard")
                ->execute($params);
        } catch (\Exception $e) {}
        $this->recordBatchStage($mac, ['delivery'=>'downloaded','delivery_at'=>date('Y-m-d H:i:s'),'fetch_ip'=>$fetchIp,'verification'=>'pending','outcome'=>'verifying'], $jobId);
        $this->notifyLiveStatus();
    }

    private function setPushFetchFailed($mac, $jobId = null) {
        try {
            $guard = $jobId === null ? '' : ' AND last_push_job_id=?';
            $params = [strtolower($mac)];
            if ($guard) $params[] = $jobId;
            $this->db->prepare("UPDATE provisioner_devices SET last_fetch_at=NOW(), last_fetch_status='failed', last_verify_status='not_attempted' WHERE mac_address=?$guard")
                ->execute($params);
        } catch (\Exception $e) {}
        $this->recordBatchStage($mac, ['delivery'=>'failed','outcome'=>'failed','error'=>'Trigger accepted but no configuration download was confirmed.'], $jobId);
        $this->notifyLiveStatus();
    }

    private function setPushVerification($mac, $verified, $verifyIp = null) {
        try {
            if ($verified) {
                $this->db->prepare("UPDATE provisioner_devices SET last_push_status='verified', last_verify_at=NOW(), last_verify_ip=?, last_verify_status='verified', ip_address=? WHERE mac_address=?")
                    ->execute([$verifyIp, $verifyIp, strtolower($mac)]);
            } else {
                $this->db->prepare("UPDATE provisioner_devices SET last_push_status='downloaded', last_verify_at=NOW(), last_verify_ip=?, last_verify_status='failed' WHERE mac_address=?")
                    ->execute([$verifyIp, strtolower($mac)]);
            }
        } catch (\Exception $e) {}
        $this->notifyLiveStatus();
    }

    private function finalizeVerifiedPush($mac) {
        try {
            $this->db->prepare("UPDATE provisioner_devices SET needs_changes=0, previous_extension=NULL, previous_description=NULL, previous_static_ip=NULL, previous_static_mask=NULL, previous_static_gw=NULL WHERE mac_address=?")
                ->execute([strtolower($mac)]);
        } catch (\Exception $e) {}
        $this->notifyLiveStatus();
    }

    private function getPushVerificationTimeout() {
        $configured = (int)$this->getSetting('push_verify_timeout');
        if ($configured < 2) $configured = 75;
        return min(120, $configured);
    }

    public function getPushProgress($macs = []) {
        $this->expirePushVerificationJobs();
        $macs = $this->expandPushSelection($macs);

        $sql = "SELECT mac_address AS mac, extension, needs_changes, last_push_attempt_at AS attempt, last_push_status AS status, last_fetch_status AS fetch_status, last_verify_status AS verify_status FROM provisioner_devices";
        if ($macs) $sql .= ' WHERE mac_address IN (' . implode(',', array_fill(0, count($macs), '?')) . ')';
        $stmt = $this->db->prepare($sql);
        $stmt->execute($macs);
        return [
            'success' => true,
            'devices' => $stmt->fetchAll(\PDO::FETCH_ASSOC),
            'verification_timeout' => $this->getPushVerificationTimeout(),
            'download_timeout' => $this->getPushDownloadTimeout()
        ];
    }

    private function verifyPhoneAfterFetch($mac, $sourceIp, $fetch, $contacts = null, $arp = null, $requireRegistration = false) {
        $mac = strtolower(preg_replace('/[^a-f0-9]/i', '', $mac));
        $stmt = $this->db->prepare("SELECT * FROM provisioner_devices WHERE mac_address=?");
        $stmt->execute([$mac]);
        $device = $stmt->fetch(\PDO::FETCH_ASSOC);
        if (!$device) return ['verified' => false, 'ip' => null, 'method' => 'mapping_missing'];

        $contacts = $contacts === null ? $this->getPjsipContacts(null, true) : $contacts;
        $arp = $arp === null ? $this->getCurrentArpMap() : $arp;
        $extension = (string)$device['extension'];
        $extensionChanged = !empty($device['needs_changes']) && !empty($device['previous_extension'])
            && (string)$device['previous_extension'] !== $extension;
        $contact = $contacts[$extension] ?? [];
        $contactIp = $contact['ip'] ?? null;
        $contactMac = strtolower(preg_replace('/[^a-f0-9]/i', '', $contact['mac'] ?? ''));
        $contactReady = $contactIp && ($contact['status'] ?? '') === 'Avail';
        $contactMatches = $contactMac === $mac || ($contactMac === '' && ($arp[$mac] ?? null) === $contactIp);
        $expectedIp = trim((string)($device['static_ip'] ?? ''));
        $fetchIp = !empty($fetch['ip']) ? $fetch['ip'] : $sourceIp;

        // Swapping both extension and IP can leave the same extension/IP
        // pairs in PJSIP. Verify the handset identity, not just those pairs.
        if ($extensionChanged || $requireRegistration) {
            $method = 'registration_not_ready';
            $reason = "new extension $extension is not registered and available yet";
            if ($contactReady) {
                if (!$contactMatches) {
                    $method = $contactMac !== '' ? 'contact_mac_mismatch' : 'contact_identity_missing';
                    $reason = $contactMac !== '' ? "registration for extension $extension belongs to a different handset"
                        : "handset MAC could not be confirmed for extension $extension";
                } elseif ($expectedIp !== '' && $contactIp !== $expectedIp) {
                    $method = 'target_not_seen';
                    $reason = 'expected final phone IP was not observed';
                } else {
                    return ['verified' => true, 'ip' => $contactIp, 'method' => $contactMac === $mac ? 'pjsip_mac' : 'pjsip_arp_mac'];
                }
            }
            return ['verified' => false, 'ip' => $contactIp, 'method' => $method, 'reason' => $reason];
        }

        if (!$contactReady || ($contactMac !== '' && $contactMac !== $mac)) $contactIp = null;
        if ($expectedIp !== '') {
            if (($arp[$mac] ?? null) === $expectedIp) {
                return ['verified' => true, 'ip' => $expectedIp, 'method' => 'arp_mac'];
            }
            if ($contactIp === $expectedIp) {
                return ['verified' => true, 'ip' => $expectedIp, 'method' => 'pjsip'];
            }
            if ($fetchIp === $expectedIp) {
                return ['verified' => true, 'ip' => $expectedIp, 'method' => 'config_fetch'];
            }
            return ['verified' => false, 'ip' => $contactIp ?: ($arp[$mac] ?? null), 'method' => 'target_not_seen'];
        }

        if (!empty($arp[$mac])) {
            return ['verified' => true, 'ip' => $arp[$mac], 'method' => 'arp_mac'];
        }
        if ($contactIp) {
            return ['verified' => true, 'ip' => $contactIp, 'method' => 'pjsip'];
        }
        // For DHCP there is no predetermined address.  A successful request
        // for this MAC-specific file is the strongest available verification
        // when the phone is across a routed subnet and has no local ARP entry.
        if ($fetchIp && filter_var($fetchIp, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4)) {
            return ['verified' => true, 'ip' => $fetchIp, 'method' => 'config_fetch'];
        }
        return ['verified' => false, 'ip' => null, 'method' => 'phone_not_seen'];
    }

    private function getPushDownloadTimeout() {
        $configured = (int)$this->getSetting('push_download_timeout');
        return $configured > 0 ? min(30, $configured) : 10;
    }

    private function waitForPushDownloads($phones, &$observations, $deadline = null, $jobId = null) {
        if ($deadline === null) $deadline = microtime(true) + $this->getPushDownloadTimeout();
        $pending = $phones;
        $results = [];
        try {
            do {
                foreach ($pending as $mac => $sourceIp) {
                    $fetch = $this->checkFetchObservation($observations[$mac]);
                    if ($fetch === null) continue;
                    if (!empty($fetch['success'])) {
                        $this->setPushDownloaded($mac, $fetch['ip'] ?: $sourceIp, $jobId);
                        $results[$mac] = ['status' => 'downloaded', 'triggered' => true, 'downloaded' => true, 'verified' => false];
                    } else {
                        $this->setPushFetchFailed($mac, $jobId);
                        $results[$mac] = ['status' => 'triggered', 'triggered' => true, 'downloaded' => false, 'verified' => false,
                            'error' => 'Config fetch returned HTTP ' . ($fetch['status_code'] ?? 'error')];
                    }
                    unset($pending[$mac]);
                }
                if (!$pending || microtime(true) >= $deadline) break;
                usleep(100000);
            } while (true);
            foreach ($pending as $mac => $sourceIp) {
                $guard = $jobId === null ? '' : ' AND last_push_job_id=?';
                $params = [$mac];
                if ($guard) $params[] = $jobId;
                $this->db->prepare("UPDATE provisioner_devices SET last_fetch_status='not_observed', last_verify_status='not_attempted' WHERE mac_address=?$guard")->execute($params);
                $results[$mac] = ['status' => 'triggered', 'triggered' => true, 'downloaded' => false, 'verified' => false,
                    'error' => 'SIP trigger accepted, but config download was not observed'];
            }
        } finally {
            foreach ($phones as $mac => $sourceIp) $this->finishFetchObservation($observations[$mac]);
        }
        return $results;
    }

    private function capturePushJobSnapshots($macs, $jobId) {
        $snapshots = [];
        $stmt = $this->db->prepare('SELECT id, mac_address, extension, description, brand, custom_config, static_ip, static_mask, static_gw, previous_extension, updated_at FROM provisioner_devices WHERE mac_address=?');
        foreach ($macs as $mac) {
            $stmt->execute([$mac]);
            $row = $stmt->fetch(\PDO::FETCH_ASSOC);
            if (!$row) continue;
            $this->db->prepare('UPDATE provisioner_devices SET last_push_job_id=? WHERE id=?')->execute([$jobId, $row['id']]);
            $snapshots[$mac] = $row;
        }
        return $snapshots;
    }

    private function pushJobGuard($jobId, $snapshot, &$params) {
        $params = [$jobId];
        $conditions = ['last_push_job_id=?'];
        foreach (['id', 'mac_address', 'extension', 'description', 'brand', 'custom_config', 'static_ip', 'static_mask', 'static_gw', 'previous_extension', 'updated_at'] as $field) {
            if (($snapshot[$field] ?? null) === null) {
                $conditions[] = "$field IS NULL";
            } else {
                $conditions[] = "$field=?";
                $params[] = $snapshot[$field];
            }
        }
        return implode(' AND ', $conditions);
    }

    private function createPushVerificationJob($jobId, $phones, $snapshots, $groups, $launch = true) {
        if (!$phones) return false;
        $payload = ['phones' => $phones, 'snapshots' => $snapshots, 'groups' => $groups,
            'deadline' => microtime(true) + $this->getPushVerificationTimeout()];
        $ok = $this->db->prepare("INSERT INTO provisioner_push_jobs (id,status,payload,created_at) VALUES (?,'queued',?,NOW())")
            ->execute([$jobId, json_encode($payload)]);
        if (!$ok) {
            $this->failPushVerificationJob($jobId, 'Could not queue background verification');
            return false;
        }
        $this->db->prepare('DELETE FROM provisioner_push_jobs WHERE finished_at IS NOT NULL AND finished_at<?')
            ->execute([date('Y-m-d H:i:s', time() - 7 * 86400)]);
        if (!$launch) return true;
        $php = null;
        foreach (['/usr/bin/php', '/usr/local/bin/php'] as $candidate) {
            if (is_executable($candidate)) { $php = $candidate; break; }
        }
        if (!$php) {
            $this->failPushVerificationJob($jobId, 'PHP CLI is unavailable; use Diagnostics to verify the downloaded config');
            return false;
        }
        try {
            exec('nohup ' . escapeshellarg($php) . ' ' . escapeshellarg(__DIR__ . '/verify_push.php') . ' ' . escapeshellarg($jobId)
                . ' </dev/null >/dev/null 2>&1 &', $output, $status);
        } catch (\Throwable $e) {
            $this->failPushVerificationJob($jobId, 'Background worker could not start; use Diagnostics to verify the downloaded config');
            return false;
        }
        if ($status !== 0) {
            $this->failPushVerificationJob($jobId, 'Background worker could not start; use Diagnostics to verify the downloaded config');
            return false;
        }
        return true;
    }

    public function failPushVerificationJob($jobId, $error) {
        $this->db->prepare("UPDATE provisioner_push_jobs SET status='failed', finished_at=NOW(), error=? WHERE id=? AND status IN ('queued','running')")->execute([$error, $jobId]);
        $this->db->prepare("UPDATE provisioner_devices SET last_verify_status='failed' WHERE last_push_job_id=? AND last_push_status='downloaded' AND last_verify_status IN ('pending','verifying')")->execute([$jobId]);
        $this->addLog('verify', "Background verification $jobId: $error", 'warning');
        $stmt = $this->db->prepare('SELECT batch_id,mac,data FROM provisioner_batch_items WHERE job_id=?'); $stmt->execute([$jobId]);
        foreach ($stmt->fetchAll(\PDO::FETCH_ASSOC) as $item) {
            $data = json_decode($item['data'],true);
            if (in_array($data['outcome'], ['running','verifying'], true)) {
                $this->patchBatchItem($item['batch_id'],$item['mac'], ['verification'=>'failed','outcome'=>'incomplete','error'=>$error]);
                $this->getBatchReport($item['batch_id']);
            }
        }
    }

    private function expirePushVerificationJobs() {
        $stmt = $this->db->prepare("SELECT id FROM provisioner_push_jobs WHERE status IN ('queued','running') AND created_at<?");
        $stmt->execute([date('Y-m-d H:i:s', time() - 150)]);
        foreach ($stmt->fetchAll(\PDO::FETCH_COLUMN) as $jobId) {
            $this->failPushVerificationJob($jobId, 'Background worker did not finish; use Diagnostics → Verify Last Push');
        }
    }

    private function finalizePushJob($jobId, $payload, $results) {
        $units = [];
        $grouped = [];
        foreach ($payload['groups'] as $group) {
            if (!array_intersect($group, array_keys($payload['phones']))) continue;
            $units[] = $group;
            foreach ($group as $mac) $grouped[$mac] = true;
        }
        foreach ($payload['phones'] as $mac => $ip) if (!isset($grouped[$mac])) $units[] = [$mac];
        foreach ($units as $unit) {
            foreach ($unit as $mac) if (empty($results[$mac]['verified']) || empty($payload['snapshots'][$mac])) continue 2;
            $this->db->beginTransaction();
            try {
                $valid = true;
                foreach ($unit as $mac) {
                    $guard = $this->pushJobGuard($jobId, $payload['snapshots'][$mac], $params);
                    $lock = $this->db->getAttribute(\PDO::ATTR_DRIVER_NAME) === 'sqlite' ? '' : ' FOR UPDATE';
                    $stmt = $this->db->prepare("SELECT id FROM provisioner_devices WHERE $guard AND last_verify_status='verified'$lock");
                    $stmt->execute($params);
                    if (!$stmt->fetchColumn()) { $valid = false; break; }
                }
                if ($valid) {
                    foreach ($unit as $mac) {
                        $guard = $this->pushJobGuard($jobId, $payload['snapshots'][$mac], $params);
                        $ok = $this->db->prepare("UPDATE provisioner_devices SET needs_changes=0, previous_extension=NULL, previous_description=NULL, previous_static_ip=NULL, previous_static_mask=NULL, previous_static_gw=NULL WHERE $guard")->execute($params);
                        if (!$ok) throw new \RuntimeException('Could not finalize verified push');
                    }
                }
                $this->db->commit();
            } catch (\Throwable $e) {
                if ($this->db->inTransaction()) $this->db->rollBack();
                throw $e;
            }
        }
    }

    public function processPushVerificationJob($jobId, $contacts = null, $arp = null, $deadline = null) {
        if (!preg_match('/^[a-f0-9]{32}$/', $jobId)) return false;
        $claim = $this->db->prepare("UPDATE provisioner_push_jobs SET status='running' WHERE id=? AND status='queued'");
        $claim->execute([$jobId]);
        if ($claim->rowCount() !== 1) return false;
        try {
            $stmt = $this->db->prepare('SELECT payload FROM provisioner_push_jobs WHERE id=?');
            $stmt->execute([$jobId]);
            $payload = json_decode($stmt->fetchColumn(), true);
            if (!is_array($payload) || empty($payload['phones'])) throw new \RuntimeException('Invalid verification job');
            if ($deadline === null) $deadline = (float)$payload['deadline'];
            $pending = $payload['phones'];
            $results = [];
            do {
                $currentContacts = $contacts === null ? $this->getPjsipContacts(null, true) : $contacts;
                $currentArp = $arp === null ? $this->getCurrentArpMap() : $arp;
                foreach ($pending as $mac => $sourceIp) {
                    $guard = $this->pushJobGuard($jobId, $payload['snapshots'][$mac], $params);
                    $stmt = $this->db->prepare("SELECT last_verify_status, last_verify_ip FROM provisioner_devices WHERE $guard");
                    $stmt->execute($params);
                    $row = $stmt->fetch(\PDO::FETCH_ASSOC);
                    if (!$row) {
                        $results[$mac] = ['verified' => false, 'method' => 'superseded'];
                        unset($pending[$mac]);
                        continue;
                    }
                    if ($row['last_verify_status'] === 'verified') {
                        $results[$mac] = ['verified' => true, 'ip' => $row['last_verify_ip'], 'method' => 'stored_verification'];
                        unset($pending[$mac]);
                        continue;
                    }
                    $this->db->prepare("UPDATE provisioner_devices SET last_verify_status='verifying' WHERE $guard")->execute($params);
                    $verification = $this->verifyPhoneAfterFetch($mac, $sourceIp, ['success' => true, 'ip' => $sourceIp], $currentContacts, $currentArp, true);
                    $results[$mac] = $verification;
                    if (!empty($verification['verified'])) {
                        $values = array_merge([$verification['ip'], $verification['ip']], $params);
                        $update = $this->db->prepare("UPDATE provisioner_devices SET last_push_status='verified', last_verify_status='verified', last_verify_at=NOW(), last_verify_ip=?, ip_address=? WHERE $guard");
                        $update->execute($values);
                        if ($update->rowCount() === 1) {
                            $this->addLog('verify', "Background verification MAC:$mac IP:{$verification['ip']} method:{$verification['method']}", 'success');
                        } else {
                            $results[$mac] = ['verified' => false, 'method' => 'superseded'];
                        }
                        unset($pending[$mac]);
                    }
                }
                if (!$pending || microtime(true) >= $deadline) break;
                usleep(500000);
            } while (true);
            foreach ($pending as $mac => $sourceIp) {
                $guard = $this->pushJobGuard($jobId, $payload['snapshots'][$mac], $params);
                $this->db->prepare("UPDATE provisioner_devices SET last_verify_status='failed', last_verify_at=NOW() WHERE $guard AND last_verify_status<>'verified'")->execute($params);
                $this->addLog('verify', "Verification incomplete MAC:$mac — " . ($results[$mac]['reason'] ?? 'registration not observed'), 'warning');
            }
            $this->finalizePushJob($jobId, $payload, $results);
            foreach ($results as $mac => $verification) {
                $outcome = !empty($verification['verified']) ? 'verified' : (($verification['method'] ?? '') === 'superseded' ? 'superseded' : 'incomplete');
                $this->recordBatchStage($mac, ['verification'=>$outcome,'outcome'=>$outcome,'verify_ip'=>$verification['ip'] ?? null,
                    'verification_method'=>$verification['method'] ?? '', 'verification_at'=>date('Y-m-d H:i:s'),
                    'error'=>$outcome === 'verified' ? '' : ($verification['reason'] ?? ($outcome === 'superseded' ? 'Mapping or push superseded this attempt.' : 'Registration not verified.'))], $jobId);
            }
            $this->notifyLiveStatus();
            $complete = !array_filter($results, function ($result) { return empty($result['verified']); });
            $superseded = array_filter($results, function ($result) { return ($result['method'] ?? '') === 'superseded'; });
            $this->db->prepare('UPDATE provisioner_push_jobs SET status=?, finished_at=NOW() WHERE id=?')->execute([$superseded ? 'superseded' : ($complete ? 'complete' : 'incomplete'), $jobId]);
            return $results;
        } catch (\Throwable $e) {
            $this->failPushVerificationJob($jobId, $e->getMessage());
            return false;
        }
    }

    private function waitForPushVerification($mac, $sourceIp, &$observation, $deadline = null) {
        $observations = [strtolower($mac) => &$observation];
        $results = $this->waitForPushVerifications([strtolower($mac) => $sourceIp], $observations, $deadline);
        return $results[strtolower($mac)];
    }

    private function waitForPushVerifications($phones, &$observations, $deadline = null) {
        if ($deadline === null) $deadline = microtime(true) + $this->getPushVerificationTimeout();
        $pending = $phones;
        $fetches = [];
        $verifications = [];
        $results = [];

        try {
            do {
                $contacts = null;
                $arp = null;
                foreach ($pending as $mac => $sourceIp) {
                    if (!isset($fetches[$mac])) {
                        $fetch = $this->checkFetchObservation($observations[$mac]);
                        if ($fetch === null) continue;
                        $fetches[$mac] = $fetch;
                        if (empty($fetch['success'])) {
                            $this->setPushFetchFailed($mac);
                            $results[$mac] = [
                                'status' => 'triggered', 'triggered' => true,
                                'downloaded' => false, 'verified' => false,
                                'error' => 'Config fetch returned HTTP ' . ($fetch['status_code'] ?? 'error')
                            ];
                            unset($pending[$mac]);
                            continue;
                        }
                        $this->setPushDownloaded($mac, $fetch['ip'] ?: $sourceIp);
                    }
                    if ($contacts === null) {
                        $contacts = $this->getPjsipContacts(null, true);
                        $arp = $this->getCurrentArpMap();
                    }
                    $verification = $this->verifyPhoneAfterFetch($mac, $sourceIp, $fetches[$mac], $contacts, $arp);
                    $verifications[$mac] = $verification;
                    if (!empty($verification['verified'])) {
                        $this->setPushVerification($mac, true, $verification['ip']);
                        $results[$mac] = [
                            'status' => 'verified', 'triggered' => true,
                            'downloaded' => true, 'verified' => true,
                            'ip' => $verification['ip'], 'method' => $verification['method']
                        ];
                        unset($pending[$mac]);
                    }
                }
                if (!$pending || microtime(true) >= $deadline) break;
                usleep(250000);
            } while (true);

            foreach ($pending as $mac => $sourceIp) {
                if (!isset($fetches[$mac])) {
                    try {
                        $this->db->prepare("UPDATE provisioner_devices SET last_fetch_status='not_observed', last_verify_status='not_attempted' WHERE mac_address=?")
                            ->execute([$mac]);
                    } catch (\Exception $e) {}
                    $results[$mac] = [
                        'status' => 'triggered', 'triggered' => true,
                        'downloaded' => false, 'verified' => false,
                        'error' => 'SIP trigger accepted, but config download was not observed'
                    ];
                } else {
                    $this->setPushVerification($mac, false, $verifications[$mac]['ip'] ?? null);
                    $results[$mac] = [
                        'status' => 'downloaded', 'triggered' => true,
                        'downloaded' => true, 'verified' => false,
                        'error' => 'Config downloaded, but ' . ($verifications[$mac]['reason'] ?? 'expected final phone IP was not observed')
                    ];
                }
            }
        } finally {
            foreach ($phones as $mac => $sourceIp) $this->finishFetchObservation($observations[$mac]);
        }
        return $results;
    }

    public function verifyPushStatus($mac, $contacts = null, $arp = null) {
        $mac = strtolower(preg_replace('/[^a-f0-9]/i', '', $mac));
        $stmt = $this->db->prepare("SELECT * FROM provisioner_devices WHERE mac_address=?");
        $stmt->execute([$mac]);
        $device = $stmt->fetch(\PDO::FETCH_ASSOC);
        if (!$device) return ['success' => false, 'error' => 'Device not found'];
        if (($device['last_fetch_status'] ?? null) !== 'downloaded') {
            return ['success' => false, 'error' => 'A successful config download has not been recorded for this push.'];
        }

        $macs = [$mac];
        foreach ($this->getPendingExtensionSwapGroups() as $group) {
            if (in_array($mac, $group, true)) {
                $macs = $group;
                break;
            }
        }
        $contacts = $contacts === null ? $this->getPjsipContacts(null, true) : $contacts;
        $arp = $arp === null ? $this->getCurrentArpMap() : $arp;
        $results = [];
        $complete = true;
        foreach ($macs as $member) {
            $stmt->execute([$member]);
            $row = $stmt->fetch(\PDO::FETCH_ASSOC);
            if (!$row || ($row['last_fetch_status'] ?? '') !== 'downloaded') {
                $results[$member] = ['verified' => false, 'ip' => null, 'method' => 'download_not_recorded'];
                $complete = false;
                continue;
            }
            $fetchIp = $row['last_fetch_ip'] ?: $row['ip_address'];
            $verification = $this->verifyPhoneAfterFetch($member, $fetchIp, [
                'success' => true, 'ip' => $fetchIp, 'source' => 'stored_fetch'
            ], $contacts, $arp, true);
            $results[$member] = $verification;
            $this->setPushVerification($member, !empty($verification['verified']), $verification['ip'] ?? null);
            if (!empty($verification['verified']) && !empty($row['last_push_job_id'])) {
                $this->recordBatchStage($member, ['verification'=>'verified','outcome'=>'verified','verify_ip'=>$verification['ip'] ?? null,
                    'verification_method'=>$verification['method'] ?? '', 'verification_at'=>date('Y-m-d H:i:s'),'error'=>''], $row['last_push_job_id']);
            }
            if (empty($verification['verified'])) $complete = false;
        }
        $verification = $results[$mac];
        if ($complete) {
            $placeholders = implode(',', array_fill(0, count($macs), '?'));
            $this->db->prepare("UPDATE provisioner_devices SET needs_changes=0, previous_extension=NULL, previous_description=NULL, previous_static_ip=NULL, previous_static_mask=NULL, previous_static_gw=NULL WHERE mac_address IN ($placeholders)")->execute($macs);
            $this->addLog('notify', 'Late verification succeeded MAC:' . implode(',', $macs), 'success');
            return [
                'success' => true,
                'verified' => true,
                'ip' => $verification['ip'],
                'method' => $verification['method'],
                'group' => $results
            ];
        }

        return [
            'success' => false,
            'verified' => !empty($verification['verified']),
            'group_pending' => count($macs) > 1,
            'error' => !empty($verification['verified'])
                ? 'This phone is verified, but the other swap member(s) still need download or registration verification. The whole swap remains pending.'
                : 'Config downloaded, but ' . ($verification['reason'] ?? 'the expected final phone IP is not reachable yet') . '.',
            'ip' => $verification['ip'] ?? null,
            'method' => $verification['method'],
            'group' => $results
        ];
    }

    public function sendSipNotify($phoneIp, $mac, $phonePort = 5060) {
        $identityError = $this->getPushIdentityError($phoneIp, $mac);
        if ($identityError) return ['success' => false, 'triggered' => false, 'downloaded' => false, 'verified' => false, 'error' => $identityError];
        $pbxIp = $this->getSetting('pbx_ip') ?: $this->getLocalIP();
        $python = $this->findPython3Binary();
        if (!$python) return ['success' => false, 'error' => 'Python 3 not found (checked python3 and python3.6)'];
        $script = __DIR__ . '/send_sip_notify.py';
        if (!is_readable($script)) return ['success' => false, 'error' => 'send_sip_notify.py not found'];
        $macClean = strtolower(preg_replace('/[^a-f0-9]/i', '', $mac));
        $notify = $this->getNotifyDefinition($macClean, $pbxIp);
        $observation = $this->beginFetchObservation($macClean);
        $cmd = escapeshellcmd($python) . ' ' . escapeshellarg($script)
            . ' ' . escapeshellarg($phoneIp)
            . ' ' . escapeshellarg($pbxIp)
            . ' ' . escapeshellarg($mac)
            . ' ' . escapeshellarg($notify['uri'])
            . ' ' . escapeshellarg((string)$phonePort)
            . ' ' . escapeshellarg($notify['event'])
            . ' ' . escapeshellarg($notify['to_user'])
            . ' 2>/dev/null';
        $output = shell_exec($cmd);
        $triggered = ($output !== null && trim($output) === 'OK');
        if (!$triggered) {
            $this->finishFetchObservation($observation);
            $this->setPushTriggerFailed($macClean);
            $this->addLog('notify', $notify['label'] . " failed MAC:$macClean IP:$phoneIp — body:{$notify['uri']}", 'error');
            return ['success' => false, 'triggered' => false, 'downloaded' => false, 'verified' => false, 'output' => $output, 'uri' => $notify['uri'], 'event' => $notify['event'], 'method' => $notify['label']];
        }

        $this->setPushTriggered($macClean, $phoneIp);
        $verification = $this->waitForPushVerification($macClean, $phoneIp, $observation);
        if (!empty($verification['verified'])) $this->finalizeVerifiedPush($macClean);
        $success = !empty($verification['verified']);
        $logStatus = $success ? 'success' : ($verification['downloaded'] ? 'warning' : 'error');
        $detail = $success
            ? $notify['label'] . " verified MAC:$macClean IP:" . ($verification['ip'] ?? $phoneIp) . " method:" . ($verification['method'] ?? 'unknown')
            : $notify['label'] . " triggered MAC:$macClean IP:$phoneIp — " . ($verification['error'] ?? 'verification incomplete');
        $this->addLog('notify', $detail, $logStatus);
        return array_merge([
            'success' => $success,
            'output' => $output,
            'uri' => $notify['uri'],
            'event' => $notify['event'],
            'method' => $notify['label']
        ], $verification);
    }

    private function getNotifyDefinition($mac, $pbxIp = null) {
        $pbxIp = $pbxIp ?: ($this->getSetting('pbx_ip') ?: $this->getLocalIP());
        $brand = strtolower((string)$this->getDeviceBrand(strtolower($mac)));
        $provPort = $this->getSetting('prov_port') ?: '2001';
        return [
            'event' => 'ua-profile',
            'uri' => "http://{$pbxIp}:{$provPort}/provisioning/",
            'to_user' => '',
            'label' => $brand === 'alcatel' ? 'ALE config fetch' : 'NOTIFY'
        ];
    }

    private function getDiscoveryObservations() {
        try {
            $rows = $this->db->query('SELECT * FROM provisioner_discovery_observations')->fetchAll(\PDO::FETCH_ASSOC);
            return array_column($rows, null, 'mac_address');
        } catch (\Throwable $error) { return []; }
    }

    private function saveDiscoveryObservations($devices) {
        $ownsTransaction = !$this->db->inTransaction();
        if ($ownsTransaction) $this->db->beginTransaction();
        try {
            foreach ($devices as $device) {
                $mac = strtolower($device['mac'] ?? '');
                if (!preg_match('/^[a-f0-9]{12}$/', $mac) || !in_array($device['brand'] ?? '', ['digium', 'alcatel'], true)
                    || !$this->isPrivateIp($device['ip'] ?? '') || empty($device['seen_now']) || !empty($device['identity_conflict'])) continue;
                $seen = $device['last_seen'] ?? date('Y-m-d H:i:s');
                if (!strtotime($seen) || strtotime($seen) > time() + 5) continue;
                $existing = $this->db->prepare('SELECT last_seen FROM provisioner_discovery_observations WHERE mac_address=? OR ip_address=?');
                $existing->execute([$mac, $device['ip']]);
                $newer = array_filter($existing->fetchAll(\PDO::FETCH_COLUMN), function ($timestamp) use ($seen) { return $timestamp > $seen; });
                if ($newer) continue;
                $values = [$device['ip'], $device['brand'], $device['model'] ?? '', $device['firmware'] ?? '',
                    (int)($device['sip_port'] ?? 5060), $device['sip_transport'] ?? 'udp', $device['source'] ?? '', $seen, $mac];
                if (!$this->db->prepare('DELETE FROM provisioner_discovery_observations WHERE ip_address=? OR mac_address=?')->execute([$device['ip'], $mac])
                    || !$this->db->prepare('INSERT INTO provisioner_discovery_observations (ip_address,brand,model,firmware,sip_port,sip_transport,source,last_seen,mac_address) VALUES (?,?,?,?,?,?,?,?,?)')->execute($values)) {
                    throw new \RuntimeException('Could not save the MAC/IP discovery evidence.');
                }
            }
            if ($ownsTransaction) $this->db->commit();
        } catch (\Throwable $error) {
            if ($ownsTransaction && $this->db->inTransaction()) $this->db->rollBack();
            throw $error;
        }
        $this->notifyLiveStatus();
    }

    private function formatSipDiscoveryResponses($responses, $registrations = [], $localDevices = [], &$excludedResponders = null) {
        $excluded = [];
        $byIp = [];
        foreach ($responses as $reply) {
            $ip = $reply['ip'] ?? '';
            if (!$this->isPrivateIp($ip)) continue;
            $headers = $reply['headers'] ?? [];
            $agent = $headers['user-agent'] ?? $headers['server'] ?? '';
            $fingerprint = \FreePBX\modules\Provisioner\SipDiscovery::fingerprint($agent);
            $identity = \FreePBX\modules\Provisioner\SipDiscovery::identity($agent);
            if (!$identity && $agent !== '') {
                foreach (['mac', 'x-mac', 'x-mac-address'] as $header) {
                    if (!empty($headers[$header])) $identity = \FreePBX\modules\Provisioner\SipDiscovery::identity($agent . ' ' . $headers[$header]);
                    if ($identity) break;
                }
            }
            if (!$identity && ((!$fingerprint && $agent !== '') || strpos($reply['status'] ?? '', 'SIP/2.0 200') !== 0
                || stripos($headers['allow'] ?? '', 'NOTIFY') === false)) {
                $reason = (strpos($reply['status'] ?? '', 'SIP/2.0 200') !== 0) ? 'SIP response was not 200 OK.'
                    : ((!$fingerprint && $agent !== '') ? 'Unrecognized or unsupported SIP User-Agent.' : 'SIP responder did not advertise NOTIFY.');
                $excluded[$ip] = ['ip'=>$ip, 'status'=>$reply['status'] ?? '', 'reason'=>$reason];
                continue;
            }
            $row = [
                'id' => $identity ? strtoupper($identity['mac']) : 'IP:' . $ip,
                'mac' => $identity ? strtoupper($identity['mac']) : '', 'ip' => $ip,
                'brand' => $identity['brand'] ?? $fingerprint['brand'] ?? '', 'vendor' => $identity ? ucfirst($identity['brand']) : 'Unidentified SIP',
                'model' => $fingerprint['model'] ?? '', 'firmware' => $fingerprint['firmware'] ?? '',
                'source' => 'SIP OPTIONS (' . strtoupper($reply['transport']) . ' ' . $reply['port'] . ')',
                'sip_port' => (int)$reply['port'], 'sip_transport' => $reply['transport'],
                'sip_status' => $reply['status'], 'seen_now' => true, 'last_seen' => date('Y-m-d H:i:s', (int)($reply['seen_at'] ?? time())),
                'is_phone' => $identity !== null, 'is_supported' => $identity !== null,
                'can_identify' => $identity === null, 'identity_pending' => $identity === null,
                'in_provisioner' => $identity ? $this->checkProvisioner($identity['mac']) : false
            ];
            $previous = $byIp[$ip] ?? null;
            $rank = ['udp' => 3, 'tcp' => 2, 'tls' => 1];
            if (!$previous || ($row['mac'] && !$previous['mac'])
                || (!!$row['mac'] === !!$previous['mac'] && $rank[$row['sip_transport']] > $rank[$previous['sip_transport']])) $byIp[$ip] = $row;
        }
        foreach ($localDevices as $local) {
            $ip = $local['ip'] ?? '';
            if (!$this->isPrivateIp($ip) || !in_array($local['brand'] ?? '', ['digium', 'alcatel'], true)
                || !preg_match('/^[a-f0-9]{12}$/i', $local['mac'] ?? '')) continue;
            $local['mac'] = strtoupper($local['mac']); $local['id'] = $local['mac'];
            $local['can_identify'] = false; $local['identity_pending'] = false;
            $previous = $byIp[$ip] ?? null;
            if (!$previous) { $byIp[$ip] = $local; continue; }
            // An old neighbor entry cannot identify a currently anonymous SIP responder.
            if (empty($local['seen_now'])) continue;
            if ((!empty($previous['mac']) && strcasecmp($previous['mac'], $local['mac']) !== 0)
                || (!empty($previous['brand']) && $previous['brand'] !== $local['brand'])) {
                $local['identity_conflict'] = true; $local['is_supported'] = false;
                $local['source'] .= '; SIP identity conflict';
                $byIp[$ip] = $local;
                continue;
            }
            $row = array_replace($previous, $local);
            foreach (['model', 'firmware', 'sip_port', 'sip_transport'] as $field) {
                if (!empty($previous[$field])) $row[$field] = $previous[$field];
            }
            $row['source'] = $local['source'] . ' + ' . $previous['source'];
            $byIp[$ip] = $row;
        }
        foreach ($registrations as $registration) {
            if (!in_array($registration['brand'] ?? '', ['digium', 'alcatel'], true)) continue;
            $ip = $registration['ip'];
            $previous = $byIp[$ip] ?? [];
            if (!empty($previous['identity_conflict'])) continue;
            if (!empty($previous['seen_now']) && !empty($previous['mac']) && strcasecmp($previous['mac'], $registration['mac']) !== 0) continue;
            if (!empty($previous['mac']) && strcasecmp($previous['mac'], $registration['mac']) !== 0) $previous = [];
            $row = array_replace($previous, $registration);
            foreach (['model', 'firmware'] as $field) if (empty($row[$field])) $row[$field] = $previous[$field] ?? '';
            if (!empty($previous['neighbor_state']) && !empty($previous['seen_now'])) $row['source'] = $previous['source'] . ' + ' . $registration['source'];
            $row['id'] = strtoupper($row['mac']);
            $row['can_identify'] = false; $row['identity_pending'] = false;
            $row['sip_port'] = $row['sip_port'] ?? 5060;
            $row['sip_transport'] = $row['sip_transport'] ?? 'udp';
            $row['seen_now'] = true;
            $row['last_seen'] = date('Y-m-d H:i:s');
            $byIp[$ip] = $row;
        }
        $devices = [];
        foreach ($byIp as $row) {
            if (!isset($devices[$row['id']]) || !empty($row['seen_now']) || empty($devices[$row['id']]['seen_now'])) $devices[$row['id']] = $row;
        }
        $devices = array_values($devices);
        usort($devices, function ($a, $b) { return ip2long($a['ip']) <=> ip2long($b['ip']); });
        if ($excludedResponders !== null) $excludedResponders = array_values($excluded);
        return $devices;
    }

    private function launchDiscoveryJob($job) {
        $php = is_executable('/usr/bin/php') ? '/usr/bin/php' : PHP_BINARY;
        exec('nohup ' . escapeshellarg($php) . ' ' . escapeshellarg(__DIR__ . '/discovery_worker.php')
            . ' ' . escapeshellarg($job['id']) . ' </dev/null >/dev/null 2>&1 &', $output, $status);
        if ($status !== 0) {
            $job['status'] = 'failed'; $job['error'] = 'Could not start the discovery worker.';
            (new \FreePBX\modules\Provisioner\SipDiscovery())->save($job);
            throw new \RuntimeException($job['error']);
        }
        return ['success' => true, 'job_id' => $job['id'], 'type' => $job['type'], 'scan_mode' => $job['payload']['scan_mode'] ?? null];
    }

    public function startDiscoveryScan($subnet) {
        $normalized = $this->normalizeScanSubnets(trim((string)$subnet), 32);
        if (!$normalized || count($normalized) !== 1) return ['success' => false, 'error' => 'Enter one valid IPv4 subnet in CIDR notation.'];
        if ((int)explode('/', $normalized[0])[1] < 22) return ['success' => false, 'error' => 'Network scans are limited to /22 or smaller ranges.'];
        if (!$this->isPrivateIp(explode('/', $normalized[0])[0])) return ['success' => false, 'error' => 'Select a private IPv4 subnet.'];
        if (!$this->findPython3Binary()) return ['success' => false, 'error' => 'Python 3 is required for SIP discovery.'];
        $source = $this->getSetting('pbx_ip') ?: $this->getLocalIP();
        if (!filter_var($source, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4)) return ['success' => false, 'error' => 'Configure a valid PBX IPv4 address first.'];
        try {
            $store = new \FreePBX\modules\Provisioner\SipDiscovery();
            $registrations = $this->autoDiscoverFromPjsip(null, null, $normalized[0]);
            $port = (int)($this->getSetting('sip_port') ?: 5060);
            $localSubnets = $this->getLocalDiscoverySubnets($normalized[0]);
            $job = $store->create('scan', ['subnet' => $normalized[0], 'source_ip' => $source,
                'ports' => array_values(array_unique([5060, 5160, $port >= 1 && $port <= 65535 ? $port : 5060])),
                'tls_ports' => [5061],
                'local_subnets' => $localSubnets, 'local_devices' => [],
                'scan_mode' => !$localSubnets ? 'routed' : (in_array($normalized[0], $localSubnets, true) ? 'local' : 'mixed'),
                'registrations' => $registrations['matches'] ?? []]);
            return $this->launchDiscoveryJob($job);
        } catch (\Throwable $error) { return ['success' => false, 'error' => $error->getMessage()]; }
    }

    public function startIdentifyMac($scanId, $ids) {
        $store = new \FreePBX\modules\Provisioner\SipDiscovery();
        $scan = $store->load($scanId);
        if (!$scan || $scan['type'] !== 'scan' || $scan['status'] !== 'complete') return ['success' => false, 'error' => 'Run a fresh SIP scan before identifying a MAC.'];
        if (!is_array($ids) || !$ids || count($ids) > 32) return ['success' => false, 'error' => 'Select between 1 and 32 unidentified IPs.'];
        foreach ($ids as $candidateId) if (!is_string($candidateId) || !preg_match('/^IP:\d+\.\d+\.\d+\.\d+$/', $candidateId)) return ['success' => false, 'error' => 'Invalid unidentified device selection.'];
        $requested = array_fill_keys(array_values(array_unique($ids)), true);
        $targets = [];
        foreach ($scan['result']['devices'] ?? [] as $device) {
            if (!isset($requested[$device['id']]) || empty($device['can_identify']) || empty($device['seen_now']) || !empty($device['mac'])) continue;
            $targets[] = ['ip' => $device['ip'], 'port' => $device['sip_port'], 'transport' => $device['sip_transport'], 'nonce' => bin2hex(random_bytes(16))];
            unset($requested[$device['id']]);
        }
        if (!$targets || $requested) return ['success' => false, 'error' => 'Select only unidentified devices seen by this scan.'];
        try {
            $source = $scan['payload']['source_ip'];
            $port = (int)($this->getSetting('prov_port') ?: 2001);
            if ($port < 1 || $port > 65535) throw new \RuntimeException('Configure a valid provisioning HTTP port first.');
            $job = $store->create('identify', ['source_ip' => $source, 'targets' => $targets, 'scan_id' => $scanId,
                'receiver_url' => 'http://' . $source . ':' . $port . '/admin/modules/provisioner/discovery_receiver.php',
                'original_devices' => $scan['result']['devices']]);
            return $this->launchDiscoveryJob($job);
        } catch (\Throwable $error) { return ['success' => false, 'error' => $error->getMessage()]; }
    }

    public function getDiscoveryJobStatus($id) {
        $store = new \FreePBX\modules\Provisioner\SipDiscovery();
        $job = $store->load($id);
        if (!$job) return ['success' => false, 'error' => 'Discovery job expired or was not found. Run a new scan.'];
        if ($job['status'] === 'failed') return ['success' => false, 'error' => $job['error'] ?? 'Discovery failed.'];
        $progressPath = $store->path($id, 'progress.json');
        $progress = is_file($progressPath) ? json_decode(file_get_contents($progressPath), true) : [];
        $result = $job['result'] ?? [];
        $excluded = $result['excluded_responders'] ?? [];
        if ($job['type'] === 'scan' && empty($job['result'])) {
            $result['devices'] = $this->formatSipDiscoveryResponses($progress['responses'] ?? [], $job['payload']['registrations'], $job['payload']['local_devices'] ?? [], $excluded);
        }
        return ['success' => true, 'job_id' => $id, 'type' => $job['type'], 'status' => $job['status'],
            'complete' => $job['status'] === 'complete', 'done' => $progress['done'] ?? 0, 'total' => $progress['total'] ?? 0,
            'scan_mode' => $job['payload']['scan_mode'] ?? 'routed',
            'phase' => $progress['phase'] ?? ($job['type'] === 'identify' ? 'identify' : (!empty($job['payload']['local_subnets']) ? 'local_arp' : 'sip_options')),
            'devices' => $result['devices'] ?? [], 'errors' => $result['errors'] ?? [],
            'excluded_responders' => $excluded,
            'subnet' => $job['payload']['subnet'] ?? '', 'scan_id' => $job['payload']['scan_id'] ?? $id];
    }

    public function processDiscoveryJob($id) {
        $store = new \FreePBX\modules\Provisioner\SipDiscovery();
        $job = $store->load($id);
        if (!$job || $job['status'] !== 'queued') return false;
        $lock = fopen($store->path($id, 'worker.lock'), 'c');
        if (!$lock || !flock($lock, LOCK_EX | LOCK_NB)) return false;
        try {
            $job = $store->load($id);
            if (!$job || $job['status'] !== 'queued') return false;
            $job['status'] = 'running'; $store->save($job);
            if ($job['type'] === 'scan') {
                $localSubnets = $this->getLocalDiscoverySubnets($job['payload']['subnet']);
                $job['payload']['local_subnets'] = $localSubnets;
                $job['payload']['scan_mode'] = !$localSubnets ? 'routed' : (in_array($job['payload']['subnet'], $localSubnets, true) ? 'local' : 'mixed');
                $localDevices = [];
                $localTotal = count($localSubnets) * 3;
                if ($localTotal) \FreePBX\modules\Provisioner\SipDiscovery::writeJson($store->path($id, 'progress.json'), ['phase' => 'local_arp', 'done' => 0, 'total' => $localTotal]);
                foreach ($localSubnets as $index => $localSubnet) {
                    $local = $this->doNetworkScan($localSubnet, function ($devices, $pass) use (&$job, $store, $id, $index, $localTotal, &$localDevices) {
                        $job['payload']['local_devices'] = array_merge($localDevices, $devices);
                        $store->save($job);
                        \FreePBX\modules\Provisioner\SipDiscovery::writeJson($store->path($id, 'progress.json'), ['phase' => 'local_arp', 'done' => $index * 3 + $pass, 'total' => $localTotal]);
                    });
                    if (!empty($local['error'])) throw new \RuntimeException($local['error']);
                    $localDevices = array_merge($localDevices, $local['devices']);
                }
                $job['payload']['local_devices'] = $localDevices;
                $store->save($job);
            }
            $python = $this->findPython3Binary();
            exec('timeout 190s ' . escapeshellarg($python) . ' ' . escapeshellarg(__DIR__ . '/discover_sip.py') . ' '
                . escapeshellarg($store->path($id)) . ' 2>&1', $output, $status);
            if ($status !== 0) throw new \RuntimeException('Discovery worker failed: ' . substr(implode(' ', $output), 0, 300));
            $progress = json_decode(file_get_contents($store->path($id, 'progress.json')), true);
            if (!is_array($progress)) throw new \RuntimeException('Discovery worker returned invalid progress.');
            $errors = [];
            $excludedResponders = [];
            if ($job['type'] === 'scan') {
                $registrations = $this->autoDiscoverFromPjsip(null, null, $job['payload']['subnet']);
                $devices = $this->formatSipDiscoveryResponses($progress['responses'] ?? [], $registrations['matches'] ?? [], $job['payload']['local_devices'] ?? [], $excludedResponders);
            } else {
                $byIp = array_column($job['payload']['original_devices'], null, 'ip');
                foreach ($progress['identities'] ?? [] as $received) {
                    $identity = $received['identity'] ?? null;
                    if (!$identity) { $errors[] = ['ip' => $received['ip'], 'error' => $received['error']]; continue; }
                    $row = $byIp[$identity['ip']];
                    $row = array_replace($row, $identity);
                    $row['last_seen'] = date('Y-m-d H:i:s', (int)($identity['seen_at'] ?? time()));
                    $row['mac'] = strtoupper($identity['mac']); $row['id'] = $row['mac'];
                    $row['vendor'] = ucfirst($row['brand']); $row['is_phone'] = true; $row['is_supported'] = true;
                    $row['identity_pending'] = false; $row['can_identify'] = false;
                    $row['in_provisioner'] = $this->checkProvisioner($identity['mac']);
                    $byIp[$identity['ip']] = $row;
                }
                $devices = array_values($byIp);
                // Keep the original scan's candidates current for subsequent selections.
                $scan = $store->load($job['payload']['scan_id']);
                if ($scan) { $scan['result']['devices'] = $devices; $store->save($scan); }
            }
            $this->saveDiscoveryObservations($devices);
            $job['status'] = 'complete'; $job['result'] = ['devices' => $devices, 'errors' => $errors, 'excluded_responders' => $excludedResponders];
            $store->save($job);
            $this->addLog('discovery', ($job['type'] === 'scan' ? (empty($job['payload']['local_subnets']) ? 'Read-only routed SIP scan' : 'Read-only local ping/ARP + SIP scan') : 'Selected IP MAC identification')
                . ': ' . count(array_filter($devices, function ($device) { return !empty($device['mac']); })) . ' Digium/ALE identities', 'success');
            return true;
        } catch (\Throwable $error) {
            $job['status'] = 'failed'; $job['error'] = $error->getMessage(); $store->save($job);
            return false;
        } finally { fclose($lock); }
    }

    private function doNetworkScan($subnet, $progress = null) {
        $ping = trim(shell_exec('which ping 2>/dev/null'));
        if (!$ping) return ['error' => 'ping not found'];

        $onPass = $progress ? function ($neighbors, $pass) use ($progress) {
            $progress($this->formatLocalDiscoveryNeighbors($neighbors), $pass);
        } : null;
        $neighbors = $this->pingSweepSubnet($subnet, $ping, 3, 50, $onPass);
        return ['devices' => $this->formatLocalDiscoveryNeighbors($neighbors)];
    }

    private function formatLocalDiscoveryNeighbors($neighbors) {
        $devices = [];
        $observations = $this->getDiscoveryObservations();
        foreach ($neighbors as $mac => $neighbor) {
            $supportedBrand = $this->getSupportedDiscoveryBrand($mac);
            if (!$supportedBrand || !preg_match('/^[a-f0-9]{12}$/i', $mac) || !$this->isPrivateIp($neighbor['ip'] ?? '')) continue;
            $seenNow = !empty($neighbor['seen_now']);
            $previous = $observations[strtolower($mac)] ?? [];
            if (($previous['ip_address'] ?? '') !== $neighbor['ip']) $previous = [];
            $devices[] = [
                'id' => strtoupper($mac),
                'ip' => $neighbor['ip'],
                'mac' => strtoupper($mac),
                'mac_formatted' => $this->formatMac($mac),
                'vendor' => ucfirst($supportedBrand),
                'brand' => $supportedBrand,
                'model' => $previous['model'] ?? '', 'firmware' => $previous['firmware'] ?? '',
                'source' => $seenNow ? 'Local ping/ARP' : 'Local ARP cache',
                'sip_port' => (int)($previous['sip_port'] ?? 5060), 'sip_transport' => $previous['sip_transport'] ?? 'udp',
                'neighbor_state' => $neighbor['neighbor_state'],
                'seen_now' => $seenNow, 'last_seen' => $seenNow ? date('Y-m-d H:i:s') : '',
                'is_phone' => true, 'is_supported' => true,
                'can_identify' => false, 'identity_pending' => false,
                'in_provisioner' => $this->checkProvisioner($mac),
            ];
        }
        return $devices;
    }

    public function scanNetwork($subnet = '') {
        return $this->startDiscoveryScan($subnet);
    }

    private function mergePjsipDiscoveryDevices($devices, $matches) {
        $byMac = [];
        foreach ($devices as $device) $byMac[strtolower($device['mac'])] = $device;
        foreach ($matches as $match) {
            $mac = strtolower($match['mac']);
            $byMac[$mac] = array_replace($byMac[$mac] ?? [], $match);
        }
        return array_values($byMac);
    }

    public function autoDiscoverFromPjsip($contacts = null, $arp = null, $subnet = '', $localSubnets = null) {
        $contacts = $contacts === null ? $this->getPjsipContacts(null, true) : $contacts;
        $arp = $arp === null ? $this->getCurrentArpMap() : $arp;
        $localSubnets = $localSubnets === null ? $this->getArpCapableSubnets() : $localSubnets;
        $mapped = array_fill_keys($this->db->query("SELECT mac_address FROM provisioner_devices")->fetchAll(\PDO::FETCH_COLUMN), true);
        $matches = [];
        foreach ($contacts as $extension => $contact) {
            $ip = $contact['ip'] ?? '';
            if (!$this->isPrivateIp($ip) || ($contact['status'] ?? '') !== 'Avail') continue;
            if ($subnet !== '' && !$this->ipInSubnet($ip, $subnet)) continue;
            $mac = strtolower(preg_replace('/[^a-f0-9]/i', '', $contact['mac'] ?? ''));
            $source = 'PJSIP User-Agent';
            if (strlen($mac) !== 12 && $this->ipInSubnets($ip, $localSubnets)) {
                $mac = (string)array_search($ip, $arp, true);
                $source = 'PJSIP + ARP';
            }
            if (strlen($mac) !== 12 || $mac === '000000000000' || isset($matches[$mac])) continue;
            $brand = $this->getBrandByMac($mac);
            if (!in_array($brand, ['digium', 'alcatel'], true)) continue;
            $inProvisioner = isset($mapped[$mac]);
            $identity = \FreePBX\modules\Provisioner\SipDiscovery::identity($contact['user_agent'] ?? '');
            $matches[$mac] = [
                'mac' => strtoupper($mac),
                'mac_formatted' => $this->formatMac($mac),
                'ip' => $ip,
                'extension' => (string)$extension,
                'vendor' => ucfirst($brand),
                'model' => $identity['model'] ?? '',
                'firmware' => $identity['firmware'] ?? '',
                'brand' => $brand,
                'is_phone' => true,
                'is_supported' => $this->getSupportedDiscoveryBrand($mac) !== null,
                'source' => $source,
                'pjsip_status' => $contact['status'],
                'seen_now' => true,
                'in_provisioner' => $inProvisioner,
                'status' => $inProvisioner ? 'added' : 'new'
            ];
        }
        return [
            'success' => true,
            'matches' => array_values($matches),
            'count' => count($matches),
        ];
    }

    private function pushConfigToPhonesBulk($macsWithIps, $finalize = true) {
        $pbxIp = $this->getSetting('pbx_ip') ?: $this->getLocalIP();
        $provPort = $this->getSetting('prov_port') ?: '2001';
        $python = $this->findPython3Binary();
        if (!$python) return ['success' => false, 'error' => 'Python 3 not found (checked python3 and python3.6)', 'sent' => [], 'failed' => $macsWithIps];

        $script = __DIR__ . '/send_sip_notify_bulk.py';
        if (!is_readable($script)) return ['success' => false, 'error' => 'send_sip_notify_bulk.py not found', 'sent' => [], 'failed' => $macsWithIps];

        $normal = [];
        $failed = [];
        $contacts = $this->getPjsipContacts(null, true);
        $observationsByMac = $this->getDiscoveryObservations();
        $localSubnets = $this->getArpCapableSubnets();
        foreach ($macsWithIps as $m) {
            $identityError = $this->getPushIdentityError($m['ip'], $m['mac'], $contacts, $observationsByMac, $localSubnets);
            if ($identityError) $failed[] = $m + ['error' => $identityError];
            else $normal[] = $m;
        }

        $sent = [];
        $downloaded = [];
        $verified = [];
        $sources = [];
        $methods = [];
        $observations = [];
        $jobId = bin2hex(random_bytes(16));
        $snapshots = $this->capturePushJobSnapshots(array_map(function ($phone) {
            return strtolower(preg_replace('/[^a-f0-9]/i', '', $phone['mac']));
        }, $normal), $jobId);
        foreach ($normal as $phone) {
            $mac = strtolower($phone['mac']);
            $this->recordBatchStage($mac, ['target_ip'=>$phone['ip'],'target_source'=>$phone['source'] ?? '', 'attempt_at'=>date('Y-m-d H:i:s'),
                'snapshot'=>$this->batchMappingSnapshot($snapshots[$mac] ?? [])], $jobId);
            if ($this->activeBatchId) {
                $item = $this->db->prepare('SELECT data FROM provisioner_batch_items WHERE batch_id=? AND mac=?'); $item->execute([$this->activeBatchId,$mac]);
                $data = json_decode($item->fetchColumn() ?: '{}',true);
                if (($data['generation'] ?? '') === 'not_started') $this->recordBatchStage($mac, ['generation'=>'existing_config']);
            }
        }
        $swapGroups = $this->getPendingExtensionSwapGroups();
        foreach ($normal as $m) {
            $macClean = strtolower(preg_replace('/[^a-f0-9]/i', '', $m['mac']));
            $sources[$macClean] = $m['source'] ?? 'unknown';
            $observations[$macClean] = $this->beginFetchObservation($macClean);
        }

        if (!empty($normal)) {
            $phones = [];
            foreach ($normal as $m) {
                $macClean = strtolower(preg_replace('/[^a-f0-9]/i', '', $m['mac']));
                $notify = $this->getNotifyDefinition($macClean, $pbxIp);
                $methods[$macClean] = $notify['label'];
                $phones[] = [
                    'phone_ip' => $m['ip'],
                    'port' => (int)($m['port'] ?? 5060),
                    'transport' => $m['transport'] ?? 'udp',
                    'pbx_ip' => $pbxIp,
                    'mac' => $macClean,
                    'uri' => $notify['uri'],
                    'event' => $notify['event'],
                    'to_user' => $notify['to_user']
                ];
            }

            $tmpDir = $this->getSetting('prov_dir') ?: '/var/www/html/provisioning';
            $tmpFileDir = $tmpDir . '/tmp';
            if (!is_dir($tmpFileDir)) @mkdir($tmpFileDir, 0755, true);
            $tmpFile = tempnam($tmpFileDir, 'prov_');
            file_put_contents($tmpFile, json_encode($phones));

            $cmd = escapeshellcmd($python) . ' ' . escapeshellarg($script) . ' ' . escapeshellarg($tmpFile) . ' 2>/dev/null';
            $output = shell_exec($cmd);
            @unlink($tmpFile);

            if (!$output) {
                foreach ($normal as $m) {
                    $macClean = strtolower(preg_replace('/[^a-f0-9]/i', '', $m['mac']));
                    $this->finishFetchObservation($observations[$macClean]);
                    $this->setPushTriggerFailed($macClean, $jobId);
                    $failed[] = ['mac' => $macClean, 'ip' => $m['ip'], 'source' => $m['source'] ?? 'unknown', 'error' => 'bulk script returned no output'];
                }
                return ['success' => false, 'error' => 'Bulk script returned no output', 'sent' => $sent, 'downloaded' => $downloaded, 'verified' => $verified, 'failed' => $failed];
            }

            $parsed = json_decode($output, true);
            if (!$parsed || !isset($parsed['results'])) {
                foreach ($normal as $m) {
                    $macClean = strtolower(preg_replace('/[^a-f0-9]/i', '', $m['mac']));
                    $this->finishFetchObservation($observations[$macClean]);
                    $this->setPushTriggerFailed($macClean, $jobId);
                    $failed[] = ['mac' => $macClean, 'ip' => $m['ip'], 'source' => $m['source'] ?? 'unknown', 'error' => 'bulk script returned invalid JSON'];
                }
                return ['success' => false, 'error' => 'Bulk script returned invalid JSON: ' . substr($output, 0, 200), 'sent' => $sent, 'downloaded' => $downloaded, 'verified' => $verified, 'failed' => $failed];
            }

            $seen = [];
            $accepted = [];
            foreach ($parsed['results'] as $mac => $r) {
                $macClean = strtolower(preg_replace('/[^a-f0-9]/i', '', $mac));
                if (!isset($observations[$macClean]) || empty($r['success'])) continue;
                $accepted[$macClean] = $r['ip'] ?? null;
                $this->setPushTriggered($macClean, $accepted[$macClean], $jobId);
            }
            // The HTTP request waits only for delivery. Registration and
            // final mapping changes are handled by the detached worker.
            $verifications = $this->waitForPushDownloads($accepted, $observations, null, $jobId);
            foreach ($parsed['results'] as $mac => $r) {
                $macClean = strtolower(preg_replace('/[^a-f0-9]/i', '', $mac));
                if (!isset($observations[$macClean])) continue;
                $seen[$macClean] = true;
                $source = $sources[$macClean] ?? 'unknown';
                $method = $methods[$macClean] ?? 'NOTIFY';
                $phoneIp = $r['ip'] ?? null;
                if (!empty($r['success'])) {
                    $verification = $verifications[$macClean];
                    $entry = [
                        'mac' => $mac,
                        'ip' => $phoneIp,
                        'source' => $source,
                        'method' => $method,
                        'status' => $verification['status'],
                        'verification_method' => $verification['method'] ?? null
                    ];
                    if (!empty($verification['error'])) $entry['error'] = $verification['error'];
                    $sent[] = $entry;
                    if (!empty($verification['downloaded'])) $downloaded[] = $entry;
                    if (!empty($verification['verified'])) {
                        $verified[] = $entry;
                        if ($finalize) $this->finalizeVerifiedPush($macClean);
                        $this->addLog('notify', "$method verified MAC:$macClean IP:" . ($verification['ip'] ?? $phoneIp) . " source:$source method:" . ($verification['method'] ?? 'unknown'), 'success');
                    } elseif (empty($verification['downloaded'])) {
                        $failed[] = $entry;
                        $this->addLog('notify', "$method triggered MAC:$macClean IP:$phoneIp source:$source — " . ($verification['error'] ?? 'verification incomplete'), !empty($verification['downloaded']) ? 'warning' : 'error');
                    } else {
                        $this->addLog('notify', "$method downloaded MAC:$macClean IP:$phoneIp — awaiting background verification", 'success');
                    }
                } else {
                    $this->finishFetchObservation($observations[$macClean]);
                    $this->setPushTriggerFailed($macClean, $jobId);
                    $failed[] = ['mac' => $mac, 'ip' => $phoneIp, 'source' => $source, 'method' => $method, 'error' => 'no 200 OK'];
                    $this->addLog('notify', "$method failed MAC:$macClean IP:$phoneIp source:$source — no 200 OK after 10 retries", 'error');
                }
            }

            foreach ($normal as $m) {
                $macClean = strtolower(preg_replace('/[^a-f0-9]/i', '', $m['mac']));
                if (isset($seen[$macClean])) continue;
                $this->finishFetchObservation($observations[$macClean]);
                $this->setPushTriggerFailed($macClean, $jobId);
                $failed[] = ['mac' => $macClean, 'ip' => $m['ip'], 'source' => $m['source'] ?? 'unknown', 'error' => 'bulk script returned no result'];
            }
        }

        $verificationPhones = [];
        foreach ($downloaded as $entry) $verificationPhones[strtolower($entry['mac'])] = $entry['ip'];
        $queued = $this->createPushVerificationJob($jobId, $verificationPhones, $snapshots, $swapGroups);
        return ['success' => true, 'sent' => $sent, 'downloaded' => $downloaded, 'verified' => $verified, 'failed' => $failed,
            'verification_queued' => $queued, 'verification_job' => $queued ? $jobId : null];
    }

    private function runNotifyScript($phones) {
        $pbxIp = $this->getSetting('pbx_ip') ?: $this->getLocalIP();
        $provPort = $this->getSetting('prov_port') ?: '2001';
        $python = $this->findPython3Binary();
        if (!$python) return null;
        $script = __DIR__ . '/send_sip_notify_bulk.py';
        if (!is_readable($script)) return null;
        $tmpDir = $this->getSetting('prov_dir') ?: '/var/www/html/provisioning';
        $tmpFileDir = $tmpDir . '/tmp';
        if (!is_dir($tmpFileDir)) @mkdir($tmpFileDir, 0755, true);
        $tmpFile = tempnam($tmpFileDir, 'prov_');
        if ($tmpFile === false) return null;
        file_put_contents($tmpFile, json_encode($phones));
        $cmd = escapeshellcmd($python) . ' ' . escapeshellarg($script) . ' ' . escapeshellarg($tmpFile) . ' 2>/dev/null';
        $output = shell_exec($cmd);
        @unlink($tmpFile);
        return $output;
    }

    private function notifySingleSync($mac, $ip, $source = 'arp', $finalize = true) {
        if ($this->getPushIdentityError($ip, $mac)) return false;
        $pbxIp = $this->getSetting('pbx_ip') ?: $this->getLocalIP();
        $notify = $this->getNotifyDefinition($mac, $pbxIp);
        $macClean = strtolower(preg_replace('/[^a-f0-9]/i', '', $mac));
        $phones = [['phone_ip' => $ip, 'pbx_ip' => $pbxIp, 'mac' => $macClean, 'uri' => $notify['uri'], 'event' => $notify['event'], 'to_user' => $notify['to_user']]];
        $observation = $this->beginFetchObservation($macClean);
        $output = $this->runNotifyScript($phones);
        $parsed = $output ? json_decode($output, true) : null;
        $success = !empty($parsed['results'][$macClean]['success']);
        if (!$success) {
            $this->finishFetchObservation($observation);
            $this->setPushTriggerFailed($macClean);
            $this->addLog('notify', "{$notify['label']} failed MAC:$macClean IP:$ip source:$source — no 200 OK", 'error');
            return false;
        }
        $this->setPushTriggered($macClean, $ip);
        $verification = $this->waitForPushVerification($macClean, $ip, $observation);
        if (!empty($verification['verified']) && $finalize) $this->finalizeVerifiedPush($macClean);
        $this->addLog('notify', "{$notify['label']} " . (!empty($verification['verified']) ? 'verified' : 'triggered') . " MAC:$macClean IP:$ip source:$source", !empty($verification['verified']) ? 'success' : 'warning');
        return !empty($verification['verified']);
    }

    private function sendRawRebootNotify($ip, $mac) {
        $pbxIp = $this->getSetting('pbx_ip') ?: $this->getLocalIP();
        $python = $this->findPython3Binary();
        $script = __DIR__ . '/send_sip_notify.py';
        if (!$python || !is_readable($script) || !$mac) return false;

        // Raw body-less NOTIFY with Event: check-sync;reboot=true — bypasses
        // Asterisk notify-type config and forces a reboot on ALE, Digium and
        // Sangoma phones alike. Proven in the lab.
        $cmd = escapeshellcmd($python) . ' ' . escapeshellarg($script)
             . ' ' . escapeshellarg($ip) . ' ' . escapeshellarg($pbxIp)
             . ' ' . escapeshellarg($mac) . ' - '
             . '5060 ' . escapeshellarg('check-sync;reboot=true');
        $output = shell_exec($cmd . ' 2>/dev/null');
        return strpos($output, 'OK') !== false;
    }

    public function applyChangesWithNotify($macs = []) {
        return $this->runPushBatch('apply', $macs, function () use ($macs) { return $this->applyChangesWithNotifyInternal($macs); });
    }

    private function applyChangesWithNotifyInternal($macs = []) {
        $macs = $this->expandPushSelection($macs);
        $targets = $this->resolveDeviceTargets(true);
        $result = $this->applyChanges(true, false, false, $macs);
        if (!$result['success']) return $result;
        foreach ($result['generated'] ?? [] as $mac) $this->recordBatchStage($mac, ['generation'=>'generated','generation_at'=>date('Y-m-d H:i:s')]);
        foreach ($result['preserved_custom'] ?? [] as $mac) $this->recordBatchStage($mac, ['generation'=>'custom_preserved']);

        if (empty($result['generated']) && empty($result['preserved_custom'])) {
            $result['generated'] = [];
            $result['affected'] = 0;
            $result['message'] = 'No pending changes — pushing current configs.';
        }

        $swapGroups = $this->getPendingExtensionSwapGroups();
        $swapByMac = [];
        foreach ($swapGroups as $index => $group) {
            foreach ($group as $mac) $swapByMac[$mac] = $index;
        }

        $generatedSet = array_fill_keys(array_map('strtolower', $result['generated']), true);
        $blockedGroups = [];
        foreach ($swapGroups as $index => $group) {
            foreach ($group as $mac) {
                if (empty($generatedSet[$mac]) || empty($targets[$mac]['selected_ip'])) {
                    $blockedGroups[$index] = true;
                    break;
                }
            }
        }

        $macsWithIps = [];
        $allMacs = array_values(array_unique(array_merge($result['generated'], $result['preserved_custom'] ?? [])));
        if (empty($allMacs)) {
            $stmt = $this->db->query("SELECT mac_address FROM provisioner_devices");
            $allMacs = $stmt->fetchAll(\PDO::FETCH_COLUMN);
        }
        foreach ($allMacs as $mac) {
            $macClean = strtolower($mac);
            if (isset($swapByMac[$macClean]) && !empty($blockedGroups[$swapByMac[$macClean]])) continue;
            $target = $targets[$macClean] ?? [];
            $ip = $target['selected_ip'] ?? null;
            if (!$ip) continue;
            $macsWithIps[] = ['mac' => $mac, 'ip' => $ip, 'port' => $target['selected_port'] ?? 5060, 'transport' => $target['selected_transport'] ?? 'udp', 'source' => $target['selected_source']];
        }

        $bulk = $this->pushConfigToPhonesBulk($macsWithIps, false);

        $successful = [];
        foreach (($bulk['verified'] ?? []) as $verified) $successful[strtolower($verified['mac'])] = true;
        $finalize = [];
        foreach ($successful as $mac => $_) {
            if (!isset($swapByMac[$mac])) $finalize[] = $mac;
        }
        foreach ($swapGroups as $group) {
            $complete = true;
            foreach ($group as $mac) {
                if (empty($successful[$mac])) {
                    $complete = false;
                    break;
                }
            }
            if ($complete) $finalize = array_merge($finalize, $group);
        }
        if (!empty($finalize)) {
            $placeholders = implode(',', array_fill(0, count($finalize), '?'));
            $this->db->prepare("UPDATE provisioner_devices SET needs_changes=0, previous_extension=NULL, previous_description=NULL, previous_static_ip=NULL, previous_static_mask=NULL, previous_static_gw=NULL WHERE mac_address IN ($placeholders)")->execute(array_values(array_unique($finalize)));
        }

        $notifySkipped = [];
        foreach ($allMacs as $mac) {
            $macClean = strtolower($mac);
            $ip = $targets[$macClean]['selected_ip'] ?? null;
            $groupBlocked = isset($swapByMac[$macClean]) && !empty($blockedGroups[$swapByMac[$macClean]]);
            if (!$ip || $groupBlocked) {
                $notifySkipped[] = $macClean;
                try {
                    $this->db->prepare("UPDATE provisioner_devices SET last_push_job_id=NULL, last_push_status='failed', last_push_attempt_at=NOW(), last_fetch_status='failed', last_verify_status='failed', needs_changes=1 WHERE mac_address=?")->execute([$macClean]);
                } catch (\Exception $e) {}
                if ($groupBlocked) $reason = 'extension swap group is incomplete or one member has no verified target';
                else $reason = !empty($targets[$macClean]['conflict']) ? $targets[$macClean]['conflict_message'] : ($targets[$macClean]['blocked_reason'] ?? 'no valid private IP candidate');
                $this->addLog('notify', "NOTIFY skipped MAC:$macClean — $reason", 'warning');
                $bulk['failed'][] = ['mac' => $macClean, 'error' => $reason];
            }
        }

        $result['notify_sent'] = $bulk['sent'];
        $result['notify_downloaded'] = $bulk['downloaded'] ?? [];
        $result['notify_verified'] = $bulk['verified'] ?? [];
        $result['notify_failed'] = $bulk['failed'];
        $result['notify_skipped'] = $notifySkipped;
        $result['push_macs'] = $allMacs;
        $result['verification_queued'] = $bulk['verification_queued'] ?? false;
        $result['verification_job'] = $bulk['verification_job'] ?? null;
        return $result;
    }

    public function pushConfigToPhones() {
        return $this->runPushBatch('push', [], function () { return $this->pushConfigToPhonesInternal(); });
    }

    private function pushConfigToPhonesInternal() {
        $targets = $this->resolveDeviceTargets(true);

        $stmt = $this->db->query("SELECT mac_address, extension FROM provisioner_devices ORDER BY id");
        $devices = $stmt->fetchAll(\PDO::FETCH_ASSOC);

        $macsWithIps = [];
        foreach ($devices as $d) {
            $mac = strtolower($d['mac_address']);
            $target = $targets[$mac] ?? [];
            $ip = $target['selected_ip'] ?? null;
            if (!$ip) continue;
            $macsWithIps[] = ['mac' => $mac, 'ip' => $ip, 'port' => $target['selected_port'] ?? 5060, 'transport' => $target['selected_transport'] ?? 'udp', 'source' => $target['selected_source']];
        }

        $bulk = $this->pushConfigToPhonesBulk($macsWithIps);

        $skipped = [];
        foreach ($devices as $d) {
            $mac = strtolower($d['mac_address']);
            if (empty($targets[$mac]['selected_ip'])) {
                $skipped[] = $mac;
                try {
                        $this->db->prepare("UPDATE provisioner_devices SET last_push_job_id=NULL, last_push_status='failed', last_push_attempt_at=NOW(), last_fetch_status='failed', last_verify_status='failed', needs_changes=1 WHERE mac_address=?")->execute([$mac]);
                } catch (\Exception $e) {}
                $reason = !empty($targets[$mac]['conflict']) ? $targets[$mac]['conflict_message'] : ($targets[$mac]['blocked_reason'] ?? 'no valid private IP candidate');
                $this->addLog('notify', "NOTIFY skipped MAC:$mac ext:{$d['extension']} — $reason", 'warning');
                $bulk['failed'][] = ['mac' => $mac, 'error' => $reason];
            }
        }

        $bulk['skipped'] = $skipped;
        return $bulk;
    }

    public function retryPushForPhones($macs) {
        return $this->pushToPhones($macs);
    }

    private function pushSelectionNeedsGeneration($macs) {
        if (!is_array($macs)) $macs = [$macs];
        foreach ($macs as $mac) {
            $stmt = $this->db->prepare('SELECT needs_changes FROM provisioner_devices WHERE mac_address=?');
            $stmt->execute([$mac]);
            $device = $stmt->fetch(\PDO::FETCH_ASSOC);
            if ($device && (!empty($device['needs_changes']) || !is_file($this->getConfigFilePath($mac)))) return true;
        }
        return false;
    }

    public function pushToPhones($macs = []) {
        return $this->runPushBatch('push', $macs, function () use ($macs) { return $this->pushToPhonesInternal($macs); });
    }

    private function pushToPhonesInternal($macs = []) {
        if (!is_array($macs)) $macs = [$macs];
        $macs = $this->expandPushSelection($macs);

        if (empty($macs)) {
            return $this->pushConfigToPhones();
        }

        if ($this->pushSelectionNeedsGeneration($macs)) {
            $result = $this->applyChangesWithNotify($macs);
            foreach (['sent', 'downloaded', 'verified', 'failed', 'skipped'] as $key) {
                $result[$key] = $result['notify_' . $key] ?? [];
            }
            return $result;
        }

        $targets = $this->resolveDeviceTargets(true);
        $macsWithIps = [];
        foreach ($macs as $m) {
            $target = $targets[$m] ?? [];
            $ip = $target['selected_ip'] ?? null;
            if ($ip) {
                $macsWithIps[] = ['mac' => $m, 'ip' => $ip, 'port' => $target['selected_port'] ?? 5060, 'transport' => $target['selected_transport'] ?? 'udp', 'source' => $target['selected_source']];
            }
        }

        $result = $this->pushConfigToPhonesBulk($macsWithIps);

        $notFound = [];
        foreach ($macs as $m) {
            if (empty($targets[$m]['selected_ip'])) {
                $reason = !empty($targets[$m]['conflict']) ? $targets[$m]['conflict_message'] : ($targets[$m]['blocked_reason'] ?? 'IP not found');
                $notFound[] = ['mac' => $m, 'error' => $reason];
                try {
                    $this->db->prepare("UPDATE provisioner_devices SET last_push_status='failed', last_push_attempt_at=NOW(), last_fetch_status='failed', last_verify_status='failed', needs_changes=1 WHERE mac_address=?")->execute([$m]);
                } catch (\Exception $e) {}
                $this->addLog('notify', "NOTIFY skipped MAC:$m — $reason", 'warning');
            }
        }

        $result['failed'] = array_merge($result['failed'], $notFound);
        return $result;
    }

    public function addDiscoveredDeviceWithNotify($mac, $extension, $description = '', $ip = '') {
        $brand = $this->getBrandByMac(strtolower(preg_replace('/[^a-f0-9]/i', '', $mac)));
        $result = $this->addDevice($mac, $extension, $description, $brand, $ip);
        $notifyResult = null;
        if ($result['success']) {
            $this->applyChanges(true, false);
            if ($ip) {
                $notifyResult = $this->sendSipNotify($ip, $mac);
            }
        }
        $result['notify'] = $notifyResult;
        return $result;
    }

    // =====================
    // AJAX
    // =====================
    public function ajaxRequest($req, &$setting) {
        $allowed = [
            'getPhoneStatus', 'getPhoneStatusGrid', 'getPhoneStatusSnapshot', 'streamPhoneStatus', 'rebootPhone', 'rebootPhones', 'generateProvisioning',
            'getDevices', 'getDeviceGrid', 'getDeviceDiagnostics', 'addDevice', 'updateDevice', 'deleteDevice', 'deleteDevices',
            'bulkImport', 'previewBulkImport', 'getBatchHistory', 'getBatchReport', 'retryBatch', 'getLogs', 'getLogGrid', 'clearLogs',
            'getSettings', 'saveSettings', 'getSipTransportInfo',
            'getPendingCount', 'applyChanges',
            'revertDevice', 'discardAllChanges',
            'getProvisioningInfo', 'generateAllConfigs', 'getConfigFiles', 'getConfigFileGrid', 'getConfigFileContent', 'saveConfigFile', 'deleteConfigFile', 'deleteSelectedConfigFiles', 'deleteAllConfigFiles',
            'getTemplateContent', 'saveTemplateContent',
            'getDefaultTemplateContent', 'resetTemplate',
            'addDiscoveredDevice', 'addDiscoveredDevices', 'autoAddSupportedDiscoveredDevices',
            'getBrands',
            'getExtensions', 'autoAssignExtensions', 'exportCsv', 'checkDhcpOption66', 'getDhcpLeases', 'checkFirewallProvisioning', 'getNetworkSubnets',
            'pullPhoneConfig', 'revertConfigTemplate', 'getCustomBackupInfo', 'restoreCustomBackup',
            'pullSelectedConfigFiles', 'revertSelectedConfigFiles',
            'fetchPhoneConfig', 'saveAsActiveConfig',
            'scanNetwork', 'autoDiscoverFromPjsip', 'startDiscoveryScan', 'startIdentifyMac', 'getDiscoveryJobStatus',
            'applyChangesWithNotify', 'pushConfigToPhones', 'verifyPushStatus', 'getPushProgress',
            'retryPushForPhones', 'pushToPhones'
        ];
        return in_array($req, $allowed);
    }

    public function ajaxCustomHandler() {
        if (($_REQUEST['command'] ?? '') !== 'streamPhoneStatus') return false;
        \FreePBX\modules\Provisioner\LiveStatus::stream();
        return true;
    }

    public function ajaxHandler() {
        $command = $_REQUEST['command'];
        switch ($command) {
            case 'getPhoneStatus':
                return $this->getPhoneStatus();

            case 'getPhoneStatusGrid':
                return $this->getPhoneStatusGrid();

            case 'getPhoneStatusSnapshot':
                $snapshot = $this->getPhoneStatusSnapshot();
                $this->notifyLiveStatus();
                return $snapshot;

            case 'rebootPhone':
                return $this->rebootPhone($_REQUEST['extension']);

            case 'rebootPhones':
                return $this->rebootPhones($_REQUEST['extensions']);

            case 'getProvisioningInfo':
                return $this->getProvisioningInfo();

            case 'checkDhcpOption66':
                return $this->checkDhcpOption66();

            case 'getDhcpLeases':
                return $this->getDhcpLeases();

            case 'checkFirewallProvisioning':
                return $this->checkFirewallProvisioning();

            case 'getNetworkSubnets':
                return $this->getNetworkSubnets();

            case 'generateProvisioning':
                return $this->generateProvisioning();

            case 'generateAllConfigs':
                $replaceCustom = isset($_REQUEST['replace_custom']) && in_array($_REQUEST['replace_custom'], [true, 'true', '1', 1], true);
                return $this->generateAllConfigs($replaceCustom);

            case 'getDevices':
                return $this->getDevices();

            case 'getDeviceGrid':
                return $this->getDeviceGrid();

            case 'getDeviceDiagnostics':
                $active = isset($_REQUEST['active']) && in_array($_REQUEST['active'], [true, 1, '1', 'true'], true);
                return $this->getDeviceDiagnostics($_REQUEST['mac'] ?? '', $active);

            case 'deleteDevices':
                return $this->deleteDevices($_REQUEST['ids']);

            case 'addDevice':
                return $this->addDevice(
                    $_REQUEST['mac'],
                    $_REQUEST['extension'],
                    $_REQUEST['description'] ?? '',
                    $_REQUEST['brand'] ?? null,
                    $_REQUEST['ip'] ?? '',
                    $_REQUEST['static_ip'] ?? null,
                    $_REQUEST['static_mask'] ?? null,
                    $_REQUEST['static_gw'] ?? null
                );

            case 'updateDevice':
                return $this->updateDevice(
                    $_REQUEST['id'],
                    $_REQUEST['mac'],
                    $_REQUEST['extension'],
                    $_REQUEST['description'] ?? '',
                    $_REQUEST['brand'] ?? null,
                    $_REQUEST['ip'] ?? '',
                    $_REQUEST['static_ip'] ?? null,
                    $_REQUEST['static_mask'] ?? null,
                    $_REQUEST['static_gw'] ?? null
                );

            case 'deleteDevice':
                return $this->deleteDevice($_REQUEST['id']);

            case 'bulkImport':
                return $this->bulkImport($_REQUEST['csv_content'] ?? '', $_REQUEST['revision'] ?? null);

            case 'previewBulkImport':
                return $this->previewBulkImport($_REQUEST['csv_content'] ?? '');

            case 'getBatchHistory':
                return $this->getBatchHistory($_REQUEST['offset'] ?? 0, $_REQUEST['limit'] ?? 25, $_REQUEST['search'] ?? '', $_REQUEST['sort'] ?? 'created_at', $_REQUEST['order'] ?? 'desc');

            case 'getBatchReport':
                return $this->getBatchReport($_REQUEST['batch_id'] ?? '');

            case 'retryBatch':
                return $this->retryBatch($_REQUEST['batch_id'] ?? '');

            case 'getLogs':
                return $this->getLogs(isset($_REQUEST['limit']) ? $_REQUEST['limit'] : 100);

            case 'getLogGrid':
                return $this->getLogGrid();

            case 'clearLogs':
                return $this->clearLogs();

            case 'getSettings':
                return $this->getSettings();

            case 'saveSettings':
                return $this->saveSettings($_REQUEST);

            case 'getSipTransportInfo':
                return $this->getSipTransportInfo();

            case 'getPendingCount':
                return $this->getPendingCount();

            case 'applyChanges':
                $generate = isset($_REQUEST['generate']) && ($_REQUEST['generate'] === true || $_REQUEST['generate'] === 'true' || $_REQUEST['generate'] === '1');
                $reboot   = isset($_REQUEST['reboot']) && ($_REQUEST['reboot'] === true || $_REQUEST['reboot'] === 'true' || $_REQUEST['reboot'] === '1');
                return $this->applyChanges($generate, $reboot);

            case 'revertDevice':
                return $this->revertDevice($_REQUEST['id']);

            case 'discardAllChanges':
                return $this->discardAllChanges();

            case 'getConfigFiles':
                return $this->getConfigFiles();

            case 'getConfigFileGrid':
                return $this->getConfigFileGrid();

            case 'getConfigFileContent':
                return $this->getConfigFileContent($_REQUEST['mac']);

            case 'getCustomBackupInfo':
                return $this->getCustomBackupInfo($_REQUEST['mac']);

            case 'restoreCustomBackup':
                $force = isset($_REQUEST['force']) && in_array($_REQUEST['force'], [true, 'true', '1', 1], true);
                return $this->restoreCustomBackup($_REQUEST['mac'], $force);

            case 'saveConfigFile':
                return $this->saveConfigFile($_REQUEST['mac'], $_REQUEST['content']);

            case 'deleteConfigFile':
                return $this->deleteConfigFile($_REQUEST['mac']);

            case 'deleteSelectedConfigFiles':
                return $this->deleteSelectedConfigFiles($_REQUEST['macs']);

            case 'deleteAllConfigFiles':
                return $this->deleteAllConfigFiles();

            case 'getTemplateContent':
                return $this->getTemplateContent($_REQUEST['brand'] ?? 'digium');

            case 'saveTemplateContent':
                return $this->saveTemplateContent($_REQUEST['content'], $_REQUEST['brand'] ?? 'digium');

            case 'getDefaultTemplateContent':
                return $this->getDefaultTemplateContent($_REQUEST['brand'] ?? 'digium');

            case 'resetTemplate':
                return $this->resetTemplate($_REQUEST['brand'] ?? 'digium');

            case 'getBrands':
                return $this->getBrands();

            case 'getExtensions':
                return $this->getExtensions();

            case 'autoAssignExtensions':
                return $this->autoAssignExtensions();

            case 'exportCsv':
                $usePjsipIp = isset($_REQUEST['use_pjsip_ip']) && in_array($_REQUEST['use_pjsip_ip'], [true, 'true', '1', 1], true);
                return $this->exportCsv($usePjsipIp);

            case 'addDiscoveredDevice':
                $mac = $_REQUEST['mac'] ?? '';
                $ext = $_REQUEST['extension'] ?? '';
                $desc = $_REQUEST['description'] ?? '';
                $ip = $_REQUEST['ip'] ?? '';
                if ($ext) {
                    return $this->addDiscoveredDeviceWithNotify($mac, $ext, $desc, $ip);
                }
                return $this->addDevice($mac, '', '', null, $ip);

            case 'addDiscoveredDevices':
                return $this->addDiscoveredDevices($_REQUEST['devices'] ?? []);

            case 'autoAddSupportedDiscoveredDevices':
                return $this->autoAddSupportedDiscoveredDevices($_REQUEST['devices'] ?? []);

            case 'pullPhoneConfig':
                return $this->pullPhoneConfig($_REQUEST['mac']);

            case 'revertConfigTemplate':
                return $this->revertConfigTemplate($_REQUEST['mac']);

            case 'pullSelectedConfigFiles':
                return $this->pullSelectedConfigFiles($_REQUEST['macs']);

            case 'revertSelectedConfigFiles':
                return $this->revertSelectedConfigFiles($_REQUEST['macs']);

            case 'fetchPhoneConfig':
                return $this->fetchPhoneConfig($_REQUEST['mac']);

            case 'saveAsActiveConfig':
                return $this->saveAsActiveConfig($_REQUEST['mac'], $_REQUEST['content']);

            case 'scanNetwork':
                $subnet = $_REQUEST['subnet'] ?? '';
                return $this->scanNetwork($subnet);

            case 'startDiscoveryScan':
                return $this->startDiscoveryScan($_REQUEST['subnet'] ?? '');

            case 'startIdentifyMac':
                return $this->startIdentifyMac($_REQUEST['scan_id'] ?? '', $_REQUEST['ids'] ?? []);

            case 'getDiscoveryJobStatus':
                return $this->getDiscoveryJobStatus($_REQUEST['job_id'] ?? '');

            case 'autoDiscoverFromPjsip':
                return $this->autoDiscoverFromPjsip();

            case 'applyChangesWithNotify':
                return $this->applyChangesWithNotify($_REQUEST['macs'] ?? []);

            case 'pushConfigToPhones':
                return $this->pushConfigToPhones();

            case 'retryPushForPhones':
                return $this->retryPushForPhones($_REQUEST['macs'] ?? []);

            case 'pushToPhones':
                $macs = $_REQUEST['macs'] ?? [];
                if (is_string($macs)) $macs = [$macs];
                return $this->pushToPhones($macs);

            case 'verifyPushStatus':
                return $this->verifyPushStatus($_REQUEST['mac'] ?? '');

            case 'getPushProgress':
                return $this->getPushProgress($_REQUEST['macs'] ?? []);

            default:
                return false;
        }
    }

    // =====================
    // PAGE
    // =====================
    public function showPage() {
        $vars = ['brands' => $this->getBrands()];
        return load_view(__DIR__ . '/views/main.php', $vars);
    }

    public function getActionBar($request) {
        return [];
    }

    public function getRightNav($request) {
        return '';
    }

    private function ensureTables() {
        $this->db->query("CREATE TABLE IF NOT EXISTS provisioner_devices (id INT AUTO_INCREMENT PRIMARY KEY, mac_address VARCHAR(12) NOT NULL UNIQUE, extension VARCHAR(20) NOT NULL, description VARCHAR(255) DEFAULT '', brand VARCHAR(50) DEFAULT 'digium', created_at DATETIME DEFAULT NULL, updated_at DATETIME DEFAULT NULL, needs_changes TINYINT(1) NOT NULL DEFAULT 1, previous_extension VARCHAR(20) DEFAULT NULL, previous_description VARCHAR(255) DEFAULT NULL, ip_address VARCHAR(45) DEFAULT NULL, last_ack_at DATETIME DEFAULT NULL, last_push_status VARCHAR(20) DEFAULT NULL, last_push_attempt_at DATETIME DEFAULT NULL, last_fetch_at DATETIME DEFAULT NULL, last_fetch_ip VARCHAR(45) DEFAULT NULL, last_fetch_status VARCHAR(20) DEFAULT NULL, last_verify_at DATETIME DEFAULT NULL, last_verify_ip VARCHAR(45) DEFAULT NULL, last_verify_status VARCHAR(20) DEFAULT NULL, static_ip VARCHAR(45) DEFAULT NULL, static_mask VARCHAR(15) DEFAULT NULL, static_gw VARCHAR(45) DEFAULT NULL, previous_static_ip VARCHAR(45) DEFAULT NULL, previous_static_mask VARCHAR(15) DEFAULT NULL, previous_static_gw VARCHAR(45) DEFAULT NULL) ENGINE=InnoDB DEFAULT CHARSET=utf8");
        $this->db->query("CREATE TABLE IF NOT EXISTS provisioner_logs (id INT AUTO_INCREMENT PRIMARY KEY, timestamp DATETIME NOT NULL, action VARCHAR(255) NOT NULL, details TEXT, status VARCHAR(50)) ENGINE=InnoDB DEFAULT CHARSET=utf8");
        $this->db->query("CREATE TABLE IF NOT EXISTS provisioner_settings (key_name VARCHAR(100) NOT NULL PRIMARY KEY, value TEXT) ENGINE=InnoDB DEFAULT CHARSET=utf8");
        $this->ensurePreviousStaticColumns('provisioner_devices');
    }

    private function ensurePreviousStaticColumns($table = 'provisioner_devices') {
        $columns = $this->getTableColumns($table);
        if (empty($columns)) return;
        $additions = [
            'previous_static_ip' => 'VARCHAR(45) DEFAULT NULL',
            'previous_static_mask' => 'VARCHAR(15) DEFAULT NULL',
            'previous_static_gw' => 'VARCHAR(45) DEFAULT NULL',
            'last_fetch_at' => 'DATETIME DEFAULT NULL',
            'last_fetch_ip' => 'VARCHAR(45) DEFAULT NULL',
            'last_fetch_status' => 'VARCHAR(20) DEFAULT NULL',
            'last_verify_at' => 'DATETIME DEFAULT NULL',
            'last_verify_ip' => 'VARCHAR(45) DEFAULT NULL',
            'last_verify_status' => 'VARCHAR(20) DEFAULT NULL',
            'last_push_job_id' => 'VARCHAR(32) DEFAULT NULL'
        ];
        foreach ($additions as $column => $definition) {
            if (!in_array($column, $columns, true)) {
                try {
                    $this->db->query("ALTER TABLE $table ADD COLUMN $column $definition");
                } catch (\Exception $e) {}
            }
        }
        // Prior releases called a SIP 200 OK a successful push.  Do not
        // present those historical acknowledgements as verified state after
        // the staged verification fields are available.
        try {
            $this->db->query("UPDATE $table SET last_push_status='triggered', last_fetch_status='not_observed', last_verify_status='not_attempted' WHERE last_push_status='success' AND (last_verify_status IS NULL OR last_verify_status <> 'verified')");
        } catch (\Exception $e) {}
    }

    private function getTableColumns($table) {
        $columns = [];
        $driver = $this->db->getAttribute(\PDO::ATTR_DRIVER_NAME);
        try {
            if ($driver === 'sqlite') {
                $rows = $this->db->query("PRAGMA table_info($table)")->fetchAll(\PDO::FETCH_ASSOC);
                foreach ($rows as $row) $columns[] = $row['name'];
            } else {
                $rows = $this->db->query("SHOW COLUMNS FROM $table")->fetchAll(\PDO::FETCH_ASSOC);
                foreach ($rows as $row) $columns[] = $row['Field'];
            }
        } catch (\Exception $e) {}
        return $columns;
    }
}
