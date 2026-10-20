<?php
// page.provisioner_test_discovery.php
// SIP PnP Discovery Test — listens for phones announcing via SIP SUBSCRIBE
// Accessed via Settings → Provisioner → Discovery Test

$action = $_GET['action'] ?? '';
if ($action) {
    while (ob_get_level()) ob_end_clean();
    header('Content-Type: application/json');
    try {
        $result = handleAjax($action);
        echo json_encode($result);
    } catch (\Exception $e) {
        echo json_encode(['success' => false, 'error' => $e->getMessage()]);
    }
    exit;
}

function handleAjax($action) {
    switch ($action) {
        case 'pnp_listen':
            $duration = max(5, min(60, (int)($_GET['duration'] ?? 20)));
            return listenPnP($duration);
        case 'scan_network':
            $subnet = $_GET['subnet'] ?? '';
            return $subnet ? scanPhones($subnet) : scanNetwork();
        case 'get_available_extension':
            return getAvailableExtension();
        case 'add_discovered_device':
            $mac = $_GET['mac'] ?? '';
            $extension = $_GET['extension'] ?? '';
            $description = $_GET['description'] ?? '';
            $phoneIp = $_GET['ip'] ?? '';
            if (!$mac || !$extension) {
                return ['success' => false, 'error' => 'MAC and extension are required'];
            }
            $provisioner = \FreePBX::Provisioner();
            $brand = $provisioner->getBrandByMac(strtolower(preg_replace('/[^a-f0-9]/i', '', $mac)));
            $result = $provisioner->addDevice($mac, $extension, $description, $brand);
            $notifyResult = null;
            if ($result['success']) {
                $provisioner->applyChanges(true, false);
                if ($phoneIp) {
                    $notifyResult = sendPnPNotify($phoneIp, $mac);
                }
            }
            if ($notifyResult) {
                $result['notify'] = $notifyResult;
            }
            return $result;
        default:
            return ['success' => false, 'error' => 'Unknown action'];
    }
}

function getAvailableExtension() {
    try {
        $db = \FreePBX::Database();
        $stmt = $db->query("SELECT MAX(CAST(extension AS UNSIGNED))+1 AS next FROM provisioner_devices");
        $row = $stmt->fetch(\PDO::FETCH_ASSOC);
        $next = $row['next'] ?? 2001;
        if ($next < 2001) $next = 2001;
        return ['success' => true, 'extension' => (int)$next];
    } catch (\Exception $e) {
        return ['success' => true, 'extension' => 2001];
    }
}

function sendPnPNotify($phoneIp, $mac, $phonePort = 5060) {
    $pbxIp = getLocalIP();
    $python = trim(shell_exec('which python3 2>/dev/null'));
    if (!$python) {
        return ['success' => false, 'error' => 'python3 not found'];
    }
    $script = __DIR__ . '/send_pnp_notify.py';
    if (!is_readable($script)) {
        return ['success' => false, 'error' => 'send_pnp_notify.py not found'];
    }
    $provPort = '2001';
    $uri = "http://{$pbxIp}:{$provPort}/provisioning/" . strtolower(preg_replace('/[^a-f0-9]/i', '', $mac)) . '.cfg';
    $cmd = escapeshellcmd($python) . ' ' . escapeshellarg($script)
        . ' ' . escapeshellarg($phoneIp)
        . ' ' . escapeshellarg($pbxIp)
        . ' ' . escapeshellarg($mac)
        . ' ' . escapeshellarg($uri)
        . ' ' . escapeshellarg((string)$phonePort)
        . ' 2>/dev/null';
    $output = shell_exec($cmd);
    return ['success' => ($output !== null && trim($output) === 'OK'), 'output' => $output, 'uri' => $uri];
}

function listenPnP($duration) {
    set_time_limit($duration + 10);

    $provisioner = \FreePBX::Provisioner();
    $localIP = getLocalIP();
    $script = __DIR__ . '/pnp_listener.py';

    if (!is_readable($script)) {
        return ['success' => false, 'error' => 'pnp_listener.py not found in module directory.'];
    }

    $python = trim(shell_exec('which python3 2>/dev/null'));
    if (!$python) {
        return ['success' => false, 'error' => 'python3 not found on this system.'];
    }

    $cmd = escapeshellcmd($python) . ' ' . escapeshellarg($script) . ' ' . escapeshellarg((string)$duration) . ' ' . escapeshellarg($localIP) . ' 2>/dev/null';
    $output = shell_exec($cmd);

    if ($output === null || $output === false) {
        return ['success' => false, 'error' => 'Failed to execute Python listener (timeout or permission denied).'];
    }

    $data = json_decode($output, true);
    if (!$data || !isset($data['success'])) {
        return ['success' => false, 'error' => 'Failed to parse listener output. Raw: ' . substr($output, 0, 200)];
    }

    $provisioner = \FreePBX::Provisioner();
    foreach ($data['devices'] as &$d) {
        $normalizedMac = strtolower(preg_replace('/[^a-f0-9]/i', '', $d['mac']));
        $brand = $provisioner->getBrandByMac($normalizedMac);
        $d['mac'] = strtoupper($normalizedMac);
        $d['mac_formatted'] = formatMac(strtoupper($normalizedMac));
        $d['is_phone'] = $brand !== null;
        $d['in_provisioner'] = checkProvisioner($normalizedMac);
    }

    return $data;
}

function parseSipPacket($buf) {
    $info = ['mac' => null, 'vendor' => null, 'model' => null];

    if (preg_match('/Contact:\s*<sip:([a-f0-9]{12})@/i', $buf, $m)) {
        $info['mac'] = strtoupper($m[1]);
    } elseif (preg_match('/Contact:\s*<sip:sngmac_([a-f0-9]{12})@/i', $buf, $m)) {
        $info['mac'] = strtoupper($m[1]);
    } elseif (preg_match('/From:\s*<sip:MAC([a-f0-9]{12})@/i', $buf, $m)) {
        $info['mac'] = strtoupper($m[1]);
    } elseif (preg_match('/User-Agent:\s*.*\s+([a-f0-9]{12})$/i', $buf, $m)) {
        $info['mac'] = strtoupper($m[1]);
    }

    if (preg_match('/Event:\s*.+?vendor\s*=\s*"([^"]+)"/i', $buf, $m)) {
        $info['vendor'] = $m[1];
    } elseif (preg_match('/User-Agent:\s*(\S+)/i', $buf, $m)) {
        $info['vendor'] = $m[1];
    }

    if (strcasecmp($info['vendor'], 'Asterisk') === 0) {
        $info['vendor'] = 'Digium';
    }

    if (preg_match('/Event:\s*.+?model\s*=\s*"([^"]+)"/i', $buf, $m)) {
        $info['model'] = $m[1];
    } elseif (preg_match('/User-Agent:\s*\S+\s+(\S+)/i', $buf, $m)) {
        $info['model'] = $m[1];
    }

    return $info;
}

function formatMac($mac) {
    $mac = preg_replace('/[^a-f0-9]/i', '', $mac);
    if (strlen($mac) !== 12) return $mac;
    return strtoupper(substr($mac, 0, 2)) . ':' . strtoupper(substr($mac, 2, 2)) . ':'
         . strtoupper(substr($mac, 4, 2)) . ':' . strtoupper(substr($mac, 6, 2)) . ':'
         . strtoupper(substr($mac, 8, 2)) . ':' . strtoupper(substr($mac, 10, 2));
}

function getLocalIP() {
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

function checkProvisioner($mac) {
    try {
        $db = \FreePBX::Database();
        $stmt = $db->prepare("SELECT id FROM provisioner_devices WHERE mac_address = ?");
        $stmt->execute([strtoupper(preg_replace('/[^a-f0-9]/', '', $mac))]);
        return (bool)$stmt->fetch(\PDO::FETCH_ASSOC);
    } catch (\Exception $e) {
        return false;
    }
}

function getSubnet() {
    $output = shell_exec("ip -o -f inet addr show 2>/dev/null");
    if (!$output) return '172.16.138.0/24';
    foreach (explode("\n", trim($output)) as $line) {
        if (preg_match('/inet (\d+\.\d+\.\d+\.\d+)\/(\d+)/', $line, $m)) {
            $ip = $m[1];
            $prefix = (int)$m[2];
            if ($prefix < 8 || $prefix > 30) continue;
            if (strpos($ip, '127.') === 0) continue;
            if (strpos($ip, '169.254.') === 0) continue;
            $mask = -1 << (32 - $prefix);
            $network = long2ip(ip2long($ip) & $mask);
            return "$network/$prefix";
        }
    }
    return '172.16.138.0/24';
}

function scanPhones($subnet) {
    set_time_limit(120);
    $provisioner = \FreePBX::Provisioner();

    $ping = trim(shell_exec('which ping 2>/dev/null'));
    if (!$ping) return ['success' => false, 'error' => 'ping not found'];

    $parts = explode('/', $subnet);
    if (count($parts) === 2) {
        $prefix = (int)$parts[1];
        if ($prefix >= 8 && $prefix <= 30) {
            $hostBits = 32 - $prefix;
            if ($hostBits <= 10) {
                shell_exec("ip neigh flush to " . escapeshellarg($subnet) . " 2>/dev/null");
                $base = ip2long($parts[0]) & (-1 << (32 - $prefix));
                $count = 1 << $hostBits;
                $hostList = [];
                for ($i = 1; $i < $count - 1; $i++) {
                    $hostList[] = long2ip($base + $i);
                }
                $tmpfile = tempnam(sys_get_temp_dir(), 'ping_');
                file_put_contents($tmpfile, implode("\n", $hostList));
                shell_exec("cat " . escapeshellarg($tmpfile) . " | xargs -P50 -n1 " . escapeshellcmd($ping) . " -c1 -W1 >/dev/null 2>&1");
                @unlink($tmpfile);
            }
        }
    }

    $devices = [];
    $seen = [];
    $arpRaw = @file('/proc/net/arp');
    if ($arpRaw) {
        foreach ($arpRaw as $line) {
            if (!preg_match('/^(\d+\.\d+\.\d+\.\d+)\s+.*\s+([a-f0-9:]{17})\s/', $line, $m)) continue;
            $mac = str_replace(':', '', strtolower($m[2]));
            if (isset($seen[$mac])) continue;
            $seen[$mac] = true;
            $brand = $provisioner->getBrandByMac($mac);
            $devices[] = [
                'ip' => $m[1],
                'mac' => strtoupper($mac),
                'mac_formatted' => formatMac($mac),
                'vendor' => $brand ? ucfirst($brand) : 'Unknown',
                'model' => '-',
                'source' => 'ARP:' . $subnet,
                'is_phone' => $brand !== null,
                'in_provisioner' => checkProvisioner($mac),
            ];
        }
    }

    return [
        'success' => true,
        'devices' => $devices,
        'count' => count($devices),
        'subnet' => $subnet,
    ];
}

function scanNetwork() {
    $subnet = getSubnet();
    $result = scanPhones($subnet);
    $result['detected_subnet'] = $subnet;
    return $result;
}
?>
<div class="fpbx-container">
    <div class="row">
        <div class="col-sm-12">
            <h1><i class="fa fa-flask"></i> SIP PnP Discovery Test</h1>
            <p class="text-muted" style="margin-bottom:20px">
                Listens for phones announcing themselves via SIP SUBSCRIBE on <code>224.0.1.75:5060</code> and <code>224.0.1.75:60000</code>.
                Works on any network — PBX does not need to be the DHCP server.
                <span class="label label-warning">DEV</span>
            </p>

            <div id="alert-area"></div>

            <div class="panel panel-default">
                <div class="panel-heading"><strong>Network Scan</strong></div>
                <div class="panel-body">
                    <p>Ping-sweeps the PBX subnet to discover phones by MAC OUI. Identifies known phone brands (Digium, Alcatel, Sangoma) without any DHCP or PnP dependency.</p>
                    <div class="row" style="margin-top:10px">
                        <div class="col-sm-8">
                            <form onsubmit="startScan(); return false;">
                                <div class="form-inline">
                                    <label>Subnet:</label>
                                    <input type="text" id="scan-subnet-input" class="form-control" style="width:180px;display:inline-block;font-family:monospace" placeholder="auto-detect..." value="">
                                    <button id="btn-scan" class="btn btn-default" type="submit">
                                        <i class="fa fa-search"></i> Start Scan
                                    </button>
                                    <span id="spinner-scan" class="hidden" style="margin-left:8px">
                                        <i class="fa fa-spinner fa-spin"></i> Scanning...
                                    </span>
                                </div>
                            </form>
                        </div>
                        <div class="col-sm-4 text-right">
                            <span class="label label-info" id="scan-status">Ready</span>
                        </div>
                    </div>
                    <div id="scan-details" class="hidden" style="margin-top:10px">
                        <p class="text-muted" id="scan-info"></p>
                    </div>
                </div>
            </div>

            <div class="panel panel-default">
                <div class="panel-heading"><strong>SIP PnP Listener</strong></div>
                <div class="panel-body">
                    <p>Reboot a phone while listening to capture its SIP PnP announcement. The listener passively monitors multicast traffic — no network probes are sent.</p>
                    <div class="row" style="margin-top:10px">
                        <div class="col-sm-8">
                            <form onsubmit="startListening(); return false;">
                                <div class="form-inline">
                                    <label>Duration:</label>
                                    <select id="duration-select" class="form-control" style="width:auto">
                                        <option value="10">10 seconds</option>
                                        <option value="20" selected>20 seconds</option>
                                        <option value="30">30 seconds</option>
                                        <option value="60">60 seconds</option>
                                    </select>
                                    <button id="btn-listen" class="btn btn-default" type="submit">
                                        <i class="fa fa-play"></i> Start Listening
                                    </button>
                                    <span id="spinner-listen" class="hidden" style="margin-left:8px">
                                        <i class="fa fa-spinner fa-spin"></i> Listening...
                                    </span>
                                </div>
                            </form>
                        </div>
                        <div class="col-sm-4 text-right">
                            <span class="label label-info" id="listener-status">Ready</span>
                        </div>
                    </div>
                    <div id="listener-details" class="hidden" style="margin-top:10px">
                        <p class="text-muted" id="listener-ports"></p>
                    </div>
                </div>
            </div>

            <div class="panel panel-default">
                <div class="panel-heading">
                    <strong>Detected Phones</strong>
                    <span id="result-count" class="badge pull-right" style="display:none">0</span>
                </div>
                <div class="panel-body" id="results-body">
                    <p class="text-muted" id="results-placeholder">Click "Start Listening" then reboot a phone to detect it via SIP PnP.</p>
                    <div id="results-table-wrapper" class="hidden">
                        <table class="table table-striped table-condensed" id="results-table">
                            <thead>
                                <tr>
                                    <th>IP</th>
                                    <th>MAC</th>
                                    <th>Vendor</th>
                                    <th>Model</th>
                                    <th>Ext</th>
                                    <th>Source</th>
                                    <th>Phone?</th>
                                    <th>In Provisioner?</th>
                                    <th>Action</th>
                                </tr>
                            </thead>
                            <tbody id="results-tbody"></tbody>
                        </table>
                    </div>
                </div>
            </div>

            <hr>
            <h4>Known Phone OUI Prefixes</h4>
            <p class="text-muted">Loaded from <code>Provisioner::$ouiMap</code> — phones whose MAC matches these OUIs are highlighted green.</p>
            <table class="table table-condensed" style="max-width:400px">
                <thead><tr><th>OUI</th><th>Brand</th></tr></thead>
                <tbody id="oui-table-body"></tbody>
            </table>
        </div>
    </div>
</div>

<script>
(function() {
    var listening = false;

    window.startListening = function() {
        if (listening) return;
        listening = true;

        var duration = document.getElementById('duration-select').value;
        var btn = document.getElementById('btn-listen');
        var spinner = document.getElementById('spinner-listen');
        var status = document.getElementById('listener-status');
        var details = document.getElementById('listener-details');
        var ports = document.getElementById('listener-ports');

        btn.disabled = true;
        spinner.classList.remove('hidden');
        status.textContent = 'Listening for ' + duration + 's...';
        status.className = 'label label-warning';
        details.classList.remove('hidden');
        ports.textContent = 'Listening on ports 5060 and 60000 (multicast 224.0.1.75)...';

        fetch('config.php?display=provisioner_test_discovery&action=pnp_listen&duration=' + duration)
            .then(function(r) { return r.json(); })
            .then(function(data) {
                listening = false;
                btn.disabled = false;
                spinner.classList.add('hidden');

                if (!data.success) {
                    status.textContent = 'Error';
                    status.className = 'label label-danger';
                    showAlert(data.error || 'Listener failed', 'danger');
                    return;
                }

                status.textContent = 'Done — ' + data.count + ' phone(s)';
                status.className = 'label label-success';
                ports.textContent = 'Listened on: ' + data.ports;

                if (data.count > 0) {
                    renderResults(data.devices);
                } else {
                    renderResults([]);
                    showAlert('No SIP PnP packets received in ' + duration + ' seconds. Try rebooting a phone.', 'warning');
                }
            })
            .catch(function(err) {
                listening = false;
                btn.disabled = false;
                spinner.classList.add('hidden');
                status.textContent = 'Error';
                status.className = 'label label-danger';
                showAlert('Request failed: ' + err.message, 'danger');
            });
    };

    function showAlert(msg, type) {
        type = type || 'info';
        var alertArea = document.getElementById('alert-area');
        alertArea.innerHTML = '<div class="alert alert-' + type + ' alert-dismissible"><button type="button" class="close" data-dismiss="alert">&times;</button>' + msg + '</div>';
    }

    function renderResults(devices) {
        var tbody = document.getElementById('results-tbody');
        var placeholder = document.getElementById('results-placeholder');
        var wrapper = document.getElementById('results-table-wrapper');
        var count = document.getElementById('result-count');

        tbody.innerHTML = '';
        if (devices.length === 0) {
            placeholder.classList.remove('hidden');
            wrapper.classList.add('hidden');
            count.style.display = 'none';
            return;
        }

        placeholder.classList.add('hidden');
        wrapper.classList.remove('hidden');
        count.style.display = 'inline';
        count.textContent = devices.length;

        // Pre-fetch suggested extension for the first device
        var suggestedExt = 2001;
        fetch('config.php?display=provisioner_test_discovery&action=get_available_extension')
            .then(function(r) { return r.json(); })
            .then(function(data) {
                if (data.success && data.extension) {
                    suggestedExt = data.extension;
                    // Update any existing ext inputs
                    tbody.querySelectorAll('.ext-input').forEach(function(inp) {
                        if (!inp.value) inp.value = suggestedExt;
                    });
                }
            })
            .catch(function() {});

        devices.forEach(function(d) {
            var tr = document.createElement('tr');
            if (d.is_phone) tr.className = 'success';

            var phoneIcon = d.is_phone ? '<i class="fa fa-check-circle text-success" title="' + d.vendor + '"></i>' :
                '<i class="fa fa-times-circle text-muted"></i>';
            var provIcon = d.in_provisioner ? '<i class="fa fa-check-circle text-success"></i>' :
                '<i class="fa fa-minus-circle text-muted"></i>';

            var extInput = d.in_provisioner
                ? '<span class="label label-success">Assigned</span>'
                : '<input type="number" class="form-control input-sm ext-input" style="width:80px;display:inline-block" placeholder="Ext" min="1000" max="9999" data-mac="' + d.mac + '">';

            var actionCell = d.in_provisioner
                ? '<span class="label label-success"><i class="fa fa-check"></i> In Provisioner</span>'
                : '<button class="btn btn-success btn-xs add-pnp-btn" data-mac="' + d.mac + '" data-ip="' + d.ip + '"><i class="fa fa-plus"></i> Add</button>';

            tr.innerHTML = '<td>' + d.ip + '</td>' +
                '<td><code>' + d.mac_formatted + '</code></td>' +
                '<td>' + d.vendor + '</td>' +
                '<td>' + d.model + '</td>' +
                '<td>' + extInput + '</td>' +
                '<td><span class="label label-default">' + d.source + '</span></td>' +
                '<td style="text-align:center">' + phoneIcon + '</td>' +
                '<td style="text-align:center">' + provIcon + '</td>' +
                '<td>' + actionCell + '</td>';

            tbody.appendChild(tr);
        });

        // Set first ext input to suggested
        var firstExt = tbody.querySelector('.ext-input');
        if (firstExt) firstExt.value = suggestedExt;

        // Add button handlers
        tbody.querySelectorAll('.add-pnp-btn').forEach(function(btn) {
            btn.addEventListener('click', function() {
                addDiscoveredDevice(this);
            });
        });
    }

    function addDiscoveredDevice(btn) {
        var mac = btn.getAttribute('data-mac');
        var ip = btn.getAttribute('data-ip');
        var tr = btn.closest('tr');
        var extInput = tr.querySelector('.ext-input');
        var ext = extInput ? extInput.value.trim() : '';

        if (!ext) {
            showAlert('Please enter an extension number for this device.', 'warning');
            return;
        }

        btn.disabled = true;
        btn.innerHTML = '<i class="fa fa-spinner fa-spin"></i> Adding...';

        var url = 'config.php?display=provisioner_test_discovery&action=add_discovered_device&mac=' + encodeURIComponent(mac) + '&extension=' + encodeURIComponent(ext);
        if (ip) url += '&ip=' + encodeURIComponent(ip);
        fetch(url)
            .then(function(r) { return r.json(); })
            .then(function(data) {
                if (data.success) {
                    var msg = 'Phone ' + mac + ' added as extension ' + ext + '. Config generated.';
                    if (data.notify && data.notify.success) {
                        msg += ' PnP URL sent to phone.';
                    } else if (data.notify && !data.notify.success) {
                        msg += ' PnP notify failed: ' + (data.notify.error || 'unknown');
                    } else {
                        msg += ' Phone IP not available — send PnP NOTIFY manually.';
                    }
                    showAlert(msg, 'success');
                    // Update row to show "in provisioner" state
                    var phoneIcon = '<i class="fa fa-check-circle text-success"></i>';
                    var provIcon = '<i class="fa fa-check-circle text-success"></i>';
                    var extBadge = '<span class="label label-success">' + ext + '</span>';
                    var actionBadge = '<span class="label label-success"><i class="fa fa-check"></i> In Provisioner</span>';
                    tr.cells[4].innerHTML = extBadge;
                    tr.cells[7].innerHTML = provIcon;
                    tr.cells[8].innerHTML = actionBadge;
                } else {
                    showAlert('Failed to add: ' + (data.error || 'Unknown error'), 'danger');
                    btn.disabled = false;
                    btn.innerHTML = '<i class="fa fa-plus"></i> Add';
                }
            })
            .catch(function(err) {
                showAlert('Request failed: ' + err.message, 'danger');
                btn.disabled = false;
                btn.innerHTML = '<i class="fa fa-plus"></i> Add';
            });
    }

    // Auto-detect subnet on page load
    fetch('config.php?display=provisioner_test_discovery&action=scan_network')
        .then(function(r) { return r.json(); })
        .then(function(data) {
            if (data.detected_subnet) {
                document.getElementById('scan-subnet-input').value = data.detected_subnet;
                document.getElementById('scan-subnet-input').placeholder = data.detected_subnet;
            }
        })
        .catch(function() {
            document.getElementById('scan-subnet-input').placeholder = '172.16.138.0/24';
        });

    var scanning = false;
    window.startScan = function() {
        if (scanning) return;
        scanning = true;

        var btn = document.getElementById('btn-scan');
        var spinner = document.getElementById('spinner-scan');
        var status = document.getElementById('scan-status');
        var details = document.getElementById('scan-details');
        var info = document.getElementById('scan-info');

        btn.disabled = true;
        spinner.classList.remove('hidden');
        status.textContent = 'Scanning...';
        status.className = 'label label-warning';
        details.classList.remove('hidden');
        var subnet = document.getElementById('scan-subnet-input').value.trim();
        info.textContent = 'Scanning ' + (subnet || 'auto-detected subnet') + '...';

        var url = 'config.php?display=provisioner_test_discovery&action=scan_network';
        if (subnet) url += '&subnet=' + encodeURIComponent(subnet);
        fetch(url)
            .then(function(r) { return r.json(); })
            .then(function(data) {
                scanning = false;
                btn.disabled = false;
                spinner.classList.add('hidden');

                if (!data.success) {
                    status.textContent = 'Error';
                    status.className = 'label label-danger';
                    showAlert(data.error || 'Scan failed', 'danger');
                    return;
                }

                status.textContent = 'Done — ' + data.count + ' phone(s)';
                status.className = data.count > 0 ? 'label label-success' : 'label label-default';
                info.textContent = 'Scanned ' + data.subnet + ': ' + data.count + ' known phones found.';

                if (data.count > 0) {
                    renderResults(data.devices);
                } else {
                    renderResults([]);
                    showAlert('No known phones found in ' + data.subnet + '. Only devices with MAC OUIs matching Digium, Alcatel, or Sangoma are shown.', 'info');
                }
            })
            .catch(function(err) {
                scanning = false;
                btn.disabled = false;
                spinner.classList.add('hidden');
                status.textContent = 'Error';
                status.className = 'label label-danger';
                showAlert('Scan request failed: ' + err.message, 'danger');
            });
    };

    // Load OUI table
    var ouiRows = <?php
        $provisioner = \FreePBX::Provisioner();
        $ref = new \ReflectionClass($provisioner);
        $prop = $ref->getProperty('ouiMap');
        $prop->setAccessible(true);
        echo json_encode($prop->getValue($provisioner));
    ?>;
    var ouiTbody = document.getElementById('oui-table-body');
    Object.keys(ouiRows).sort().forEach(function(oui) {
        var tr = document.createElement('tr');
        tr.innerHTML = '<td><code>' + oui.toUpperCase() + '</code></td><td>' + ouiRows[oui] + '</td>';
        ouiTbody.appendChild(tr);
    });
})();
</script>
