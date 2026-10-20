$(document).ready(function () {

  const ajax = function (command, data, callback, timeout) {
    data = data || {};
    data.module = 'provisioner';
    var isPush = ['applyChangesWithNotify', 'pushToPhones', 'pushConfigToPhones', 'retryPushForPhones', 'retryBatch', 'applyChanges'].indexOf(command) !== -1;
    return $.ajax({
      url: 'ajax.php?module=provisioner&command=' + command,
      type: 'POST',
      data: data,
      dataType: 'json',
      timeout: timeout == null ? (isPush ? 180000 : 0) : timeout
    }).done(function (res) {
      callback(res);
    }).fail(function (xhr, status) {
      if (status === 'abort' && !isPush) return;
      console.error('AJAX error:', xhr.responseText);
      callback({ success: false, error: status === 'timeout' && isPush
        ? 'Push request timed out. Check Phone Status or Diagnostics for the recorded result.'
        : 'Request failed' });
    });
  };

  function getUiPreference(key, fallback) {
    try { return window.localStorage.getItem(key) || fallback; } catch (e) { return fallback; }
  }
  function setUiPreference(key, value) {
    try { window.localStorage.setItem(key, value); } catch (e) {}
  }

  // =====================
  // IP SORTER
  // =====================
  window.ipSorter = function (a, b) {
    if (!a) return -1;
    if (!b) return 1;
    var aa = a.split('.').map(function (n) { return ('000' + n).slice(-3); }).join('');
    var bb = b.split('.').map(function (n) { return ('000' + n).slice(-3); }).join('');
    return aa.localeCompare(bb);
  };

  window.phoneStatusIpFormatter = function (value, row) {
    var ip = diagnosticsEsc(value);
    if (['Static', 'DHCP'].indexOf(row.configured_mode) === -1) return ip;
    var pending = !!row.needs_changes;
    var tooltip = 'Configured mode from the saved MAC mapping. Actual handset mode is not independently checked.';
    if (pending) tooltip += ' Pending changes are awaiting a completed push.';
    return ip + '<br><span class="badge provisioner-configured-mode' + (pending ? ' is-pending' : '')
      + '" title="' + tooltip + '">' + row.configured_mode + ' (' + (pending ? 'pending' : 'configured') + ')</span>';
  };

  function friendlyAge(value) {
    if (!value) return '';
    var date = new Date(String(value).replace(' ', 'T'));
    if (isNaN(date.getTime())) return String(value);
    var seconds = Math.max(0, Math.floor((Date.now() - date.getTime()) / 1000));
    if (seconds < 60) return 'just now';
    if (seconds < 3600) return Math.floor(seconds / 60) + ' min ago';
    if (seconds < 86400) return Math.floor(seconds / 3600) + ' hr ago';
    return Math.floor(seconds / 86400) + ' days ago';
  }

  window.phoneStatusPhoneFormatter = function (value, row) {
    var model = row.model || row.brand || 'Phone';
    var brand = row.brand || '';
    var secondary = [brand, row.description].filter(Boolean).join(' · ');
    return '<strong>' + diagnosticsEsc(model) + '</strong>'
      + (secondary ? '<br><small>' + diagnosticsEsc(secondary) + '</small>' : '')
      + '<br><code style="font-size:11px;">' + diagnosticsEsc(row.mac) + '</code>';
  };

  window.phoneStatusRegistrationFormatter = function (value) {
    if (value === 'Registered') return '<span class="text-success"><i class="fa fa-check-circle"></i> Registered</span>';
    if (value === 'Unavailable') return '<span class="text-warning"><i class="fa fa-exclamation-circle"></i> Unavailable</span>';
    return '<span class="text-muted"><i class="fa fa-circle-o"></i> Not registered</span>';
  };

  window.phoneStatusPushFormatter = function (value, row) {
    return (value || '<span class="text-muted">Not pushed</span>')
      + (row.last_push_time ? '<br><small class="text-muted">' + diagnosticsEsc(friendlyAge(row.last_push_time)) + '</small>' : '');
  };

  // =====================
  // PENDING CHANGES COUNT
  // =====================
  var pendingNeedsReboot = false;
  var deploymentMode = 'dhcp';
  var pendingPushCount = 0;

  function loadPendingCount(callback) {
    ajax('getPendingCount', {}, function (res) {
      renderPendingCount(res);
      if (callback) callback(res && res.count ? res.count : 0);
    });
  }

  function renderPendingCount(res) {
      var count = res && res.count ? res.count : 0;
      var discard = $('#discardAllBtn');
      pendingNeedsReboot = res && res.needs_reboot ? true : false;
      if (res && res.deploy_mode) deploymentMode = res.deploy_mode;
      pendingPushCount = count;
      if (count > 0) discard.show();
      else discard.hide();
      updateActionButtons();
      updateImportPushButton();
  }

  function updateImportPushButton() {
    var available = deploymentMode === 'sip_notify' && Array.isArray(importedMacs) && importedMacs.length > 0;
    $('#pushImportedPhones').prop('disabled', !available)
      .attr('title', available ? 'Open the push flow for the saved imported phones.' : 'Requires saved mappings and SIP NOTIFY deployment mode.');
  }

  // =====================
  // TABLE SELECTION TRACKING
  // =====================
  var selectedExtensions = [];
  var selectedDeviceIds = [];
  var selectedConfigMacs = [];
  var selectedDhcpMacs = [];
  var phoneStatusFilterActive = getUiPreference('provisioner-phone-filter', 'all');
  var discoveredRows = [];
  var discoveryMode = 'dhcp';
  var scanCompleted = false;
  var discoveryBusy = false;
  var discoveryScanId = '';
  var discoveryScanMode = '';
  var discoverySubnet = '';
  var discoveryExcludedResponders = [];
  var discoveryFilterActive = getUiPreference('provisioner-discovery-filter', 'all');
  var liveUpdates;

  function rowMac(row) {
    return String((row && (row.mac || row.mac_address)) || '').replace(/[^a-f0-9]/gi, '').toLowerCase();
  }

  function phoneStatusRows() {
    return $('#phoneStatusTable').bootstrapTable('getData', { unfiltered: true }) || [];
  }

  function selectedPhoneRows() {
    var rows = phoneStatusRows();
    var selected = $('#phoneStatusTable').bootstrapTable('getSelections') || [];
    if (selected.length) return selected.filter(function (row) { return !!rowMac(row); });
    if (!selectedExtensions.length) return [];
    return rows.filter(function (row) { return selectedExtensions.map(String).indexOf(String(row.extension)) !== -1 && !!rowMac(row); });
  }

  function selectedDeviceRows() {
    var rows = $('#devicesTable').bootstrapTable('getData', { unfiltered: true }) || [];
    var selected = $('#devicesTable').bootstrapTable('getSelections') || [];
    if (selected.length) return selected.filter(function (row) { return !!rowMac(row); });
    if (!selectedDeviceIds.length) return [];
    return rows.filter(function (row) { return selectedDeviceIds.map(String).indexOf(String(row.id)) !== -1 && !!rowMac(row); });
  }

  function flagIsTrue(value) {
    return value === true || value === 1 || value === '1' || value === 'true';
  }

  function pluralWord(count, singular, plural) {
    return Number(count) === 1 ? singular : (plural || singular + 's');
  }

  function pushRetryable(row) {
    if (!row || !rowMac(row)) return false;
    if (Object.prototype.hasOwnProperty.call(row, 'push_retryable')) return flagIsTrue(row.push_retryable);
    if (flagIsTrue(row.push_in_progress)) return false;
    if (flagIsTrue(row.push_issue)) return true;
    var status = $('<div>').html(row.push_status || '').text();
    return status.indexOf('Failed') !== -1 || status.indexOf('Download not observed') !== -1 || status.indexOf('Verification incomplete') !== -1;
  }

  function pushRetryableRows() {
    return phoneStatusRows().filter(pushRetryable);
  }

  function rebootEligible(row) {
    if (!row) return false;
    if (Object.prototype.hasOwnProperty.call(row, 'reboot_eligible')) return flagIsTrue(row.reboot_eligible);
    var status = $('<div>').html(row.status || '').text();
    return ['Ready', 'Online', 'Reboot Required', 'Apply Pending'].indexOf(status) !== -1;
  }

  function setPushActionButton(buttonSelector, badgeSelector, selected, source) {
    var button = $(buttonSelector);
    var badge = $(badgeSelector);
    var retryRows = pushRetryableRows();
    var action;
    if (selected.length) {
      action = {
        enabled: true,
        mode: 'push',
        label: 'Push Selected',
        count: selected.length,
        icon: 'fa-paper-plane',
        tone: 'btn-primary',
        title: 'Push config to ' + selected.length + ' selected ' + pluralWord(selected.length, 'phone') + '.'
      };
    } else if (retryRows.length) {
      action = {
        enabled: true,
        mode: 'retry',
        label: 'Retry Push',
        count: retryRows.length,
        icon: 'fa-repeat',
        tone: 'btn-warning',
        title: 'Retry push for ' + retryRows.length + ' ' + pluralWord(retryRows.length, 'phone') + ' with delivery issues.'
      };
    } else {
      action = {
        enabled: false,
        mode: 'none',
        label: 'Push Config',
        count: pendingPushCount,
        icon: 'fa-paper-plane',
        tone: 'btn-primary',
        title: pendingPushCount > 0
          ? 'Select ' + pluralWord(pendingPushCount, 'phone') + ' to push ' + pendingPushCount + ' pending ' + pluralWord(pendingPushCount, 'change') + '.'
          : 'Select phones to push config.'
      };
    }
    button.prop('disabled', !action.enabled)
      .attr('aria-disabled', action.enabled ? 'false' : 'true')
      .attr('title', action.title)
      .removeClass('btn-primary btn-warning btn-default btn-info btn-success')
      .addClass(action.tone)
      .find('i').attr('class', 'fa ' + action.icon);
    button.find('.push-action-label').text(action.label);
    if (action.count > 0) badge.text(action.count).show();
    else badge.text('0').hide();
    button.data('push-action', action.mode).data('push-source', source);
    return action;
  }

  function updatePushActionButtons() {
    setPushActionButton('#applyChangesBtn', '#pendingBadge', selectedDeviceRows(), 'devices');
    setPushActionButton('#applyChangesPhoneBtn', '#pendingBadgePhone', selectedPhoneRows(), 'phones');
  }

  function updateRebootActionButton() {
    var button = $('#rebootActionBtn');
    var selected = selectedPhoneRows();
    var allRows = phoneStatusRows();
    var hasSelection = selected.length > 0;
    var scope = hasSelection ? selected : allRows;
    var eligible = scope.filter(rebootEligible);
    var label = hasSelection ? 'Reboot Selected' : 'Reboot All';
    var enabled = eligible.length > 0;
    var count = hasSelection ? selected.length : eligible.length;
    button.prop('disabled', !enabled)
      .attr('aria-disabled', enabled ? 'false' : 'true')
      .attr('title', enabled
        ? (hasSelection ? 'Reboot ' + eligible.length + ' selected ' + pluralWord(eligible.length, 'phone') + '.' : 'Reboot all ' + eligible.length + ' eligible ' + pluralWord(eligible.length, 'phone') + '.')
        : (hasSelection ? 'No selected phones are eligible for reboot.' : 'No eligible phones to reboot.'))
      .removeClass('btn-warning btn-default')
      .addClass(enabled ? 'btn-warning' : 'btn-default');
    button.find('.reboot-action-label').text(label);
    if (count > 0) $('#rebootActionCount').text(count).show();
    else $('#rebootActionCount').text('0').hide();
    button.data('reboot-scope', hasSelection ? 'selected' : 'all');
  }

  function updateActionButtons() {
    updatePushActionButtons();
    updateRebootActionButton();
  }

  // Kept as a small compatibility wrapper for the live-status refresh paths.
  function updatePushButtonBadges() {
    updateActionButtons();
  }

  function onTableSelect(tableId, field, store, preserveSelection) {
    function syncSelection() {
      store.length = 0;
      var selections = $('#' + tableId).bootstrapTable('getSelections');
      $.each(selections, function (i, row) {
        store.push(row[field]);
      });
      updateButtonStates();
    }
    $('#' + tableId).on('check.bs.table uncheck.bs.table check-all.bs.table uncheck-all.bs.table',
      syncSelection
    );
    $('#' + tableId).on('page-change.bs.table', function () {
      if (preserveSelection) {
        setTimeout(syncSelection, 0);
        return;
      }
      store.length = 0;
      updateButtonStates();
    });
    if (preserveSelection) $('#' + tableId).on('search.bs.table', function () { setTimeout(syncSelection, 0); });
  }

  function updateButtonStates() {
    $('#deleteSelectedBtn').prop('disabled', selectedDeviceIds.length === 0);
    $('#deleteSelectedConfigBtn').prop('disabled', selectedConfigMacs.length === 0);
    $('#pullSelectedConfigBtn').prop('disabled', selectedConfigMacs.length === 0);
    $('#revertSelectedConfigBtn').prop('disabled', selectedConfigMacs.length === 0);
    var hasNewDiscoverySelection = selectedDhcpMacs.some(function (mac) {
      var row = discoveredRows.find(function (candidate) { return candidate.id === mac; });
      return row && row.mac && row.is_supported && row.seen_now !== false && row.status === 'new';
    });
    $('#addSelectedDiscoveryBtn').prop('disabled', discoveryBusy || !hasNewDiscoverySelection);
    $('#identifyMacBtn').prop('disabled', discoveryBusy || !discoveryScanId || !selectedDhcpMacs.some(function (id) {
      var row = discoveredRows.find(function (candidate) { return candidate.id === id; });
      return row && row.can_identify && row.seen_now !== false;
    }));
    updateActionButtons();
  }

  // =====================
  // TABLE EVENT INIT
  // =====================
  onTableSelect('phoneStatusTable', 'extension', selectedExtensions, true);

  $('#phoneStatusTable').on('post-body.bs.table', function () {
    $('.reboot-btn').off('click').on('click', function () {
      var ext = $(this).data('ext');
      showConfirm('Reboot Phone', 'Reboot extension ' + ext + '?', function () {
        ajax('rebootPhone', { extension: ext }, function (res) {
          if (res && res.success) {
            showAlert('success', 'Reboot sent to extension ' + ext);
            $('#phoneStatusTable').bootstrapTable('refresh');
          } else {
            showAlert('danger', 'Reboot failed for extension ' + ext);
          }
        });
      });
    });
    $('.retry-push-btn').off('click').on('click', function () {
      var btn = $(this);
      btn.html('<i class="fa fa-spinner fa-spin"></i>');
      var mac = btn.data('mac');
      ajax('retryPushForPhones', { macs: [mac] }, function (res) {
        if (res && res.success) {
          var sent = (res.sent || []).length;
          var failed = (res.failed || []).length;
          var msg = 'Retry done. ' + sent + ' sent, ' + failed + ' failed.';
          showAlert(sent > 0 ? 'success' : 'warning', msg);
        } else {
          showAlert('danger', 'Retry failed: ' + (res ? res.error : 'Unknown error'));
        }
        $('#phoneStatusTable').bootstrapTable('refresh');
      });
    });
    $('.push-phone-btn').off('click').on('click', function () {
      var mac = String($(this).data('mac') || '').replace(/[^a-f0-9]/gi, '').toLowerCase();
      if (!mac) return;
      selectedPushMacs = [mac];
      selectedPushAction = 'push';
      openApplyModal('push');
    });
    updateActionButtons();
    renderPhoneStatusSummary();
  });

  function renderPhoneStatusSummary() {
    var table = $('#phoneStatusTable');
    var rows = table.bootstrapTable('getData', { unfiltered: true }) || [];
    var visibleRows = table.bootstrapTable('getData') || [];
    var registered = rows.filter(function (row) { return row.registered === true || row.registered === 1; }).length;
    var pending = rows.filter(function (row) { return row.needs_changes === true || row.needs_changes === 1 || row.needs_changes === '1'; }).length;
    var issues = rows.filter(function (row) { return row.push_issue === true || row.push_issue === 1; }).length;
    var html = '<div class="provisioner-summary-card"><strong>' + registered + ' / ' + rows.length + '</strong><small>Registered</small></div>'
      + '<div class="provisioner-summary-card"><strong>' + pending + '</strong><small>Pending changes</small></div>'
      + '<div class="provisioner-summary-card"><strong>' + issues + '</strong><small>Push issues</small></div>'
      + '<div class="provisioner-summary-card"><strong>' + Math.max(0, rows.length - registered) + '</strong><small>Not available</small></div>';
    $('#phoneStatusSummary').html(html);
    var emptyMessage = rows.length === 0
      ? 'No phones are mapped yet. Add a phone in MAC Mapping or identify one from Device Discovery.'
      : (visibleRows.length === 0 ? 'No phones match this filter. Choose All to show the complete list.' : '');
    $('#phoneStatusEmpty').text(emptyMessage).toggle(!!emptyMessage);
  }

  function applyPhoneStatusFilter(filter) {
    phoneStatusFilterActive = filter || 'all';
    setUiPreference('provisioner-phone-filter', phoneStatusFilterActive);
    $('#phoneStatusFilter').val(phoneStatusFilterActive);
    var criteria = phoneStatusFilterActive === 'pending' ? {needs_changes: true}
      : (phoneStatusFilterActive === 'issues' ? {push_issue: true} : (phoneStatusFilterActive === 'unregistered' ? {registered: false} : {}));
    $('#phoneStatusTable').bootstrapTable('filterBy', criteria);
  }
  $('#phoneStatusFilter').on('change', function () { applyPhoneStatusFilter($(this).val()); });
  $('#phoneStatusTable').on('load-success.bs.table', function () { applyPhoneStatusFilter(phoneStatusFilterActive); });

  function diagnosticsEsc(value) {
    return $('<div>').text(value == null || value === '' ? '—' : value).html();
  }

  function loadDeviceDiagnostics(mac, active) {
    var loadingText = active ? 'Running fresh ARP verification...' : 'Loading diagnostics...';
    $('#deviceDiagnosticsBody').html('<p class="text-muted"><i class="fa fa-spinner fa-spin"></i> ' + loadingText + '</p>');
    $('#runFreshDiagnosticsBtn').prop('disabled', true);
    $('#verifyPushStatusBtn').hide().prop('disabled', true).data('mac', mac);
    $.ajax({
      url: 'ajax.php?module=provisioner&command=getDeviceDiagnostics',
      type: 'POST',
      data: { module: 'provisioner', mac: mac, active: active ? 1 : 0 },
      dataType: 'json',
      timeout: active ? 30000 : 8000
    }).done(function (res) {
      $('#runFreshDiagnosticsBtn').prop('disabled', false);
      if (!res || !res.success) {
        $('#deviceDiagnosticsBody').html('<div class="alert alert-danger">' + diagnosticsEsc(res ? res.error : 'Diagnostics request failed') + '</div>');
        return;
      }
      var device = res.device || {};
      var target = res.target || {};
      var service = res.service || {};
      var config = res.config || {};
      var lastPush = res.last_push || {};
      var verificationJob = res.verification_job || {};
      var canVerifyPush = lastPush.fetch_status === 'downloaded' && lastPush.status !== 'verified';
      $('#verifyPushStatusBtn').toggle(canVerifyPush).prop('disabled', false).data('mac', device.mac || mac);
      var manualIpMismatch = String(device.brand || '').toLowerCase() === 'alcatel'
        && device.static_ip && target.selected_ip && device.static_ip !== target.selected_ip
        && (lastPush.status === 'verified' || lastPush.status === 'success') && !device.needs_changes;
      if (manualIpMismatch) {
        var manualStory = "This ALE phone's IP was set manually on the device — provisioning can't change it."
          + ' The phone is at ' + target.selected_ip + ' but the configured static IP is ' + device.static_ip + '.'
          + ' Set a matching DHCP reservation or the same static IP on the phone, or factory-reset the phone to re-provision.';
        $('#deviceDiagnosticsManualIp')
          .html('<div class="alert alert-warning"><strong><i class="fa fa-exclamation-triangle"></i> IP Set Manually</strong><br>' + diagnosticsEsc(manualStory) + '</div>')
          .show();
      } else {
        $('#deviceDiagnosticsManualIp').hide().empty();
      }
      var arpUnavailable = String(target.resolution_mode || '').indexOf('cross_subnet') === 0;
      $('#runFreshDiagnosticsBtn')
        .prop('disabled', arpUnavailable)
       .attr('title', arpUnavailable ? 'ARP verification is only available for devices on a directly connected subnet.' : 'Run an active local subnet sweep and verify the phone MAC in ARP.')
       .html(arpUnavailable ? '<i class="fa fa-ban"></i> ARP Unavailable (Cross-Subnet)' : '<i class="fa fa-refresh"></i> Run Fresh ARP Check');
      var candidates = target.candidates || [];
      var selectedCandidate = candidates.find(function (candidate) { return candidate.selected || candidate.ip === target.selected_ip; }) || {};
      var model = selectedCandidate.model || '';
      var identitySource = selectedCandidate.identity_source || '';
      var conclusion, conclusionClass = 'diagnostic-conclusion';
      if (target.conflict) {
        conclusion = '<strong>Push blocked: handset identity conflicts with this mapping.</strong> ' + diagnosticsEsc(target.conflict_message || 'Resolve the extension/MAC conflict before pushing.');
        conclusionClass += ' danger';
      } else if (target.mac_confirmation_required) {
        conclusion = '<strong>Push blocked: handset MAC/IP identity is required.</strong> ' + diagnosticsEsc(target.blocked_reason || 'Run discovery and identify this phone again.');
        conclusionClass += ' warning';
      } else if (device.needs_changes) {
        conclusion = '<strong>Mapping changes are waiting to be pushed.</strong> Check the target and use Push Config when ready.';
        conclusionClass += ' warning';
      } else if (lastPush.verify_status === 'verified' || lastPush.status === 'verified') {
        conclusion = '<strong>Latest push verified.</strong> Registration, handset MAC, and target IP matched.';
        conclusionClass += ' success';
      } else if (lastPush.status === 'failed' || lastPush.fetch_status === 'failed' || lastPush.verify_status === 'failed') {
        conclusion = '<strong>Latest push needs attention.</strong> Open the saved batch report or review the delivery steps below.';
        conclusionClass += ' danger';
      } else if (lastPush.fetch_status === 'downloaded') {
        conclusion = '<strong>Config downloaded; final handset verification is incomplete.</strong> Use Verify Last Push after the phone reconnects.';
        conclusionClass += ' warning';
      } else if (!target.selected_ip) {
        conclusion = '<strong>No current push target.</strong> ' + diagnosticsEsc(target.blocked_reason || 'Run discovery and check the network evidence.');
        conclusionClass += ' warning';
      } else if (!config.exists) {
        conclusion = '<strong>Target found; no config file is generated yet.</strong> Generate a config before provisioning.';
      } else if (!service.reachable) {
        conclusion = '<strong>Target selected, but the phone service is not reachable.</strong> Confirm that the phone is online and that the selected IP is current.';
        conclusionClass += ' warning';
      } else {
        conclusion = '<strong>Target and config are available.</strong> No recent push has been verified.';
      }
      var pushStage = function (title, status, when, tone) {
        return '<div class="diagnostic-push-stage">'
          + '<span class="diagnostic-push-stage-label">' + diagnosticsEsc(title) + '</span>'
          + '<strong class="diagnostic-push-stage-status ' + tone + '">' + diagnosticsEsc(status || 'Not started') + '</strong>'
          + '<small class="diagnostic-push-stage-time">' + diagnosticsEsc(when || '—') + '</small></div>';
      };
      var triggerStatus = lastPush.ack ? 'Accepted' : (lastPush.status === 'failed' ? 'Failed' : 'Not confirmed');
      var deliveryStatus = lastPush.fetch_status === 'downloaded' ? 'Downloaded' : (lastPush.fetch_status === 'failed' ? 'Failed' : 'Not confirmed');
      var verifyStatus = lastPush.verify_status === 'verified' ? 'Verified' : (lastPush.verify_status || 'Not checked');
      var html = '<div class="' + conclusionClass + '">' + conclusion + '</div>';
       html += '<div class="row diagnostic-overview"><div class="col-md-6"><h5>1. Phone identity</h5><dl class="dl-horizontal">'
        + '<dt>Extension</dt><dd>' + diagnosticsEsc(device.extension) + '</dd>'
        + '<dt>Phone</dt><dd>' + diagnosticsEsc(model || device.brand) + (model ? '<br><small>' + diagnosticsEsc(device.brand) + '</small>' : '') + '</dd>'
        + '<dt>MAC</dt><dd><code>' + diagnosticsEsc(device.mac) + '</code></dd>'
        + (identitySource ? '<dt>Identity source</dt><dd>' + diagnosticsEsc(identitySource) + '</dd>' : '')
         + '</dl></div><div class="col-md-6"><h5>2. Push target</h5>'
         + '<p><strong>' + (target.selected_ip ? '<code>' + diagnosticsEsc(target.selected_ip) + '</code>' : '<span class="text-muted">No selected IP</span>') + '</strong>'
         + (target.is_stale_fallback ? ' <span class="label label-warning">Stale evidence</span>' : '') + '</p>'
         + '<p>' + diagnosticsEsc(target.selected_source || target.blocked_reason || 'No target evidence available.') + '</p>'
         + (selectedCandidate.mac_evidence ? '<p><small>MAC/IP evidence: ' + diagnosticsEsc(selectedCandidate.mac_evidence) + '</small></p>' : '')
         + (target.conflict ? '<div class="text-danger">' + diagnosticsEsc(target.conflict_message) + '</div>' : '')
         + (target.mac_confirmation_required ? '<div class="text-warning">' + diagnosticsEsc(target.blocked_reason) + '</div>' : '')
         + '</div><div class="col-md-12 diagnostic-latest-push"><h5>3. Latest push</h5>'
         + '<div class="diagnostic-push-timeline"><div class="diagnostic-push-stages">' + pushStage('Trigger', triggerStatus, lastPush.ack || lastPush.attempt, lastPush.ack ? 'text-success' : '')
         + pushStage('Download', deliveryStatus, lastPush.fetch_at, lastPush.fetch_status === 'downloaded' ? 'text-success' : '')
         + pushStage('Verification', verifyStatus, lastPush.verify_at, lastPush.verify_status === 'verified' ? 'text-success' : '') + '</div>'
         + ((lastPush.fetch_ip || lastPush.verify_ip) ? '<div class="diagnostic-push-details">'
           + (lastPush.fetch_ip ? '<span>Downloaded from ' + diagnosticsEsc(lastPush.fetch_ip) + '.</span>' : '')
           + (lastPush.verify_ip ? '<span>Verified at ' + diagnosticsEsc(lastPush.verify_ip) + '.</span>' : '')
           + '</div>' : '')
         + '</div></div></div>';
      if (verificationJob.error) html += '<div class="alert alert-warning" style="margin-top:10px;">' + diagnosticsEsc(verificationJob.error) + '</div>';
      html += '<details style="margin-top:12px;"><summary>Detailed evidence and config</summary>'
        + '<div class="row" style="margin-top:8px;"><div class="col-md-6"><dl class="dl-horizontal">'
        + '<dt>Selected source</dt><dd>' + diagnosticsEsc(target.selected_source) + '</dd>'
        + '<dt>Confidence</dt><dd>' + diagnosticsEsc(target.confidence) + '</dd>'
        + '<dt>Resolution mode</dt><dd>' + diagnosticsEsc(target.resolution_mode) + '</dd>'
        + '<dt>ARP verification</dt><dd>' + (target.scan_performed ? 'Fresh scan: ' + diagnosticsEsc((target.scan_subnets || []).join(', ')) : '<span class="text-muted">Not run</span>') + '</dd>'
        + '<dt>PJSIP registration</dt><dd>' + diagnosticsEsc(target.pjsip_status) + '</dd>'
        + '<dt>Phone service</dt><dd>' + (service.reachable ? '<span class="text-success">TCP ' + diagnosticsEsc(service.port) + ' reachable</span>' : '<span class="text-danger">TCP ' + diagnosticsEsc(service.port) + ' unavailable</span>') + '</dd>'
        + '</dl></div><div class="col-md-6"><dl class="dl-horizontal">'
        + '<dt>Config file</dt><dd>' + diagnosticsEsc(config.filename) + '</dd>'
        + '<dt>Config state</dt><dd>' + (config.exists ? diagnosticsEsc(config.size) + ' bytes, ' + diagnosticsEsc(config.modified) + (config.valid ? ' <span class="label label-success">Valid</span>' : ' <span class="label label-danger">Invalid</span>') : '<span class="text-muted">Not generated</span>') + (config.custom ? ' <span class="label label-info">Custom</span>' : '') + '</dd>'
        + '<dt>Provisioning URL</dt><dd><code>' + diagnosticsEsc(res.provisioning_url) + '</code></dd>'
        + '<dt>Push attempt</dt><dd>' + diagnosticsEsc(lastPush.attempt) + '</dd>'
        + '<dt>Background job</dt><dd>' + diagnosticsEsc(verificationJob.status || 'None') + '</dd>'
        + '</dl></div></div>'
        + '<p class="text-muted">Final verification checks the assigned extension, handset MAC, and target IP through registration. SIP does not report DHCP/static mode.</p>'
        + '<h5>Target IP candidates</h5><div class="table-responsive"><table class="table table-condensed table-striped"><thead><tr><th>Source / identity</th><th>IP</th><th>Evidence</th></tr></thead><tbody>';
      (target.candidates || []).forEach(function (candidate) {
        var state;
        if (candidate.eligible === false) state = '<span class="text-warning">Ignored: ' + diagnosticsEsc(candidate.ignored_reason) + '</span>';
        else state = candidate.valid ? '<span class="text-success">Valid private IP</span>' : '<span class="text-danger">Rejected</span>';
        if (candidate.selected) state += ' <span class="label label-primary">selected</span>';
        if (candidate.stale) state += ' <span class="label label-warning">last-known</span>';
        if (candidate.mac_evidence) state += '<br><small>MAC ' + diagnosticsEsc(candidate.mac || device.mac) + ': ' + diagnosticsEsc(candidate.mac_evidence) + '</small>';
        var identity = candidate.identity_source ? '<br><small>' + diagnosticsEsc(candidate.identity_source)
          + (candidate.model ? ' · ' + diagnosticsEsc(candidate.model) : '')
          + (candidate.firmware ? ' · ' + diagnosticsEsc(candidate.firmware) : '') + '</small>' : '';
        html += '<tr><td>' + diagnosticsEsc(candidate.label) + identity + '</td><td><code>' + diagnosticsEsc(candidate.ip) + '</code></td><td>' + state + '</td></tr>';
      });
      if (!target.candidates || target.candidates.length === 0) html += '<tr><td colspan="3" class="text-muted">No IP candidates found.</td></tr>';
      html += '</tbody></table></div></details>';
      if (!service.fetch_supported) html += '<div class="alert alert-warning">Config fetch is not supported for this brand.</div>';
      $('#deviceDiagnosticsBody').html(html);
    }).fail(function (xhr, status) {
      $('#runFreshDiagnosticsBtn')
        .prop('disabled', false)
        .attr('title', 'Retry fresh ARP verification.')
        .html('<i class="fa fa-refresh"></i> Run Fresh ARP Check');
      $('#verifyPushStatusBtn').hide().prop('disabled', true);
      var message = status === 'timeout' ? 'Diagnostics timed out. Try the quick view again or verify the configured scan subnet.' : 'Diagnostics request failed.';
      $('#deviceDiagnosticsBody').html('<div class="alert alert-danger">' + diagnosticsEsc(message) + '</div>');
    });
  }

  $(document).off('click.provisionerDiagnostics', '.diagnostics-btn').on('click.provisionerDiagnostics', '.diagnostics-btn', function () {
    var mac = $(this).data('mac');
    $('#deviceDiagnosticsManualIp').hide().empty();
    $('#deviceDiagnosticsTitle').text('Device Diagnostics — ' + mac);
    $('#runFreshDiagnosticsBtn')
      .data('mac', mac)
      .prop('disabled', true)
      .attr('title', 'Loading device resolution mode...')
      .html('<i class="fa fa-refresh"></i> Run Fresh ARP Check');
    $('#deviceDiagnosticsModal').modal('show');
    loadDeviceDiagnostics(mac, false);
  });

  $(document).off('click.provisionerManualIp', '.manual-ip-btn').on('click.provisionerManualIp', '.manual-ip-btn', function () {
    var mac = $(this).data('mac');
    var actualIp = $(this).data('ip');
    var staticIp = $(this).data('static-ip');
    var story = "This ALE phone's IP was set manually on the device \u2014 provisioning can't change it."
      + ' The phone is at ' + (actualIp || '?') + ' but the configured static IP is ' + (staticIp || '?') + '.'
      + ' Set a matching DHCP reservation or the same static IP on the phone,'
      + ' or factory-reset the phone to re-provision.';
    $('#deviceDiagnosticsManualIp')
      .html('<div class="alert alert-warning"><strong><i class="fa fa-exclamation-triangle"></i> IP Set Manually</strong><br>' + diagnosticsEsc(story) + '</div>')
      .show();
    $('#deviceDiagnosticsTitle').text('Device Diagnostics — ' + mac);
    $('#runFreshDiagnosticsBtn')
      .data('mac', mac)
      .prop('disabled', true)
      .attr('title', 'Loading device resolution mode...')
      .html('<i class="fa fa-refresh"></i> Run Fresh ARP Check');
    $('#deviceDiagnosticsModal').modal('show');
    loadDeviceDiagnostics(mac, false);
  });

  $('#runFreshDiagnosticsBtn').off('click').on('click', function () {
    var mac = $(this).data('mac');
    if (mac) loadDeviceDiagnostics(mac, true);
  });

  $('#verifyPushStatusBtn').off('click').on('click', function () {
    var button = $(this);
    var mac = button.data('mac');
    if (!mac) return;
    button.prop('disabled', true).html('<i class="fa fa-spinner fa-spin"></i> Verifying...');
    ajax('verifyPushStatus', { mac: mac }, function (res) {
      if (res && res.success) {
        showAlert('success', 'Push verified at ' + (res.ip || 'the expected address') + '.');
      } else {
        showAlert('warning', res && res.error ? res.error : 'Final phone state is not verified yet.');
      }
      $('#phoneStatusTable').bootstrapTable('refresh');
      $('#devicesTable').bootstrapTable('refresh');
      loadPendingCount();
      loadDeviceDiagnostics(mac, false);
    });
  });

  $('#rebootActionBtn').off('click').on('click', function () {
    var selected = selectedPhoneRows();
    var hasSelection = selected.length > 0;
    var scope = hasSelection ? selected : phoneStatusRows();
    var eligible = scope.filter(rebootEligible);
    if (eligible.length === 0) return;
    var extensions = eligible.map(function (row) { return row.extension; }).filter(Boolean);
    if (extensions.length === 0) return;
    var skipped = hasSelection ? scope.length - eligible.length : 0;
    var title = hasSelection ? 'Reboot Selected Phones' : 'Reboot All Phones';
    var message = hasSelection ? 'Reboot ' + extensions.length + ' selected ' + pluralWord(extensions.length, 'phone') + '?' : 'Reboot all ' + extensions.length + ' eligible ' + pluralWord(extensions.length, 'phone') + '?';
    if (skipped > 0) message += ' (' + skipped + ' selected ' + pluralWord(skipped, 'phone') + ' will be skipped.)';
    showConfirm(title, message, function () {
      var button = $('#rebootActionBtn');
      button.prop('disabled', true).find('.reboot-action-label').text('Rebooting...');
      ajax('rebootPhones', { extensions: extensions }, function (res) {
        if (res && res.success) {
          showAlert('success', 'Reboot sent to ' + extensions.length + ' phone(s).');
          $('#phoneStatusTable').bootstrapTable('refresh');
        } else {
          showAlert('danger', 'Reboot failed.');
          updateActionButtons();
        }
      });
    });
  });

  // MAC Mapping — bulk delete
  onTableSelect('devicesTable', 'id', selectedDeviceIds, true);

  $('#devicesTable').on('post-body.bs.table', function () {
    $('.edit-device-btn').off('click').on('click', function () {
      $('#deviceModalTitle').text('Edit Device');
      $('#deviceId').val($(this).data('id'));
      var curExt = $(this).attr('data-ext');
      $('#deviceMac').val($(this).data('mac'));
      $('#deviceBrand').val($(this).data('brand'));
      $('#deviceDescription').val($(this).data('desc'));
      var staticIp = $(this).data('static-ip') || '';
      if (staticIp) {
        $('#deviceIpMode').val('static');
        $('#staticFields').show();
      } else {
        $('#deviceIpMode').val('dhcp');
        $('#staticFields').hide();
      }
      $('#deviceStaticIp').val(staticIp);
      $('#deviceStaticMask').val($(this).data('static-mask') || '').trigger('input');
      $('#deviceStaticGw').val($(this).data('static-gw') || '');
      $('#deviceModalError').hide();
      loadExtensions(curExt, function () { $('#deviceModal').modal('show'); });
    });
    $('.revert-device-btn').off('click').on('click', function () {
      if ($(this).data('digium-busy')) return;
      var id = $(this).data('id');
      showConfirm('Revert Device', 'Restore this device to its previous values?', function () {
        ajax('revertDevice', { id: id }, function (res) {
          if (res && res.success) {
            showAlert('success', 'Device reverted.');
            $('#devicesTable').bootstrapTable('refresh');
            loadPendingCount();
          } else {
            showAlert('danger', 'Error: ' + (res ? res.error : 'Unknown error'));
          }
        });
      });
    });
    $('.delete-device-btn').off('click').on('click', function () {
      var btn = $(this);
      if (btn.data('digium-busy')) return;
      var id = $(this).data('id');
      var ext = $(this).data('ext');
      showConfirm('Delete Mapping', 'Delete the mapping for extension ' + ext + '? This cannot be undone.', function () {
        btn.data('digium-busy', true);
        ajax('deleteDevice', { id: id }, function (res) {
          btn.data('digium-busy', false);
          if (res && res.success) {
            showAlert('success', 'Device deleted.');
            $('#devicesTable').bootstrapTable('refresh');
            $('#phoneStatusTable').bootstrapTable('refresh');
            loadPendingCount();
          } else {
            showAlert('danger', 'Error: ' + (res ? res.error : 'Unknown error'));
          }
        });
      });
    });
  });

  // Config Files — bulk delete
  onTableSelect('configFilesTable', 'mac', selectedConfigMacs, true);
  var individualConfigFetches = {};
  var configModalRequestSequence = 0;
  var savingActiveConfig = false;

  $('#configFilesTable').on('post-body.bs.table', function () {
    $('.edit-config-btn').off('click').on('click', function () {
      var mac = $(this).data('mac');
      var ext = $(this).data('ext');
      var token = ++configModalRequestSequence;
      $('#configEditModal').data('configExt', ext);
      $('#configEditModal').data('requestToken', token);
      $('#configEditMac').val(mac);
      $('#configEditTitle').text('Edit Config File — ' + mac);
      $('#configEditContent').val('Loading...');
      $('#configEditModal').modal('show');
      ajax('getConfigFileContent', { mac: mac }, function (res) {
        if ($('#configEditModal').data('requestToken') !== token || $('#configEditMac').val() !== mac) return;
        if (res && res.success) {
          $('#configEditContent').val(res.content);
          $('#configEditModal').data('configExt', res.extension);
        } else {
          $('#configEditContent').val('Error loading config.');
        }
      });
    });
    $('.delete-config-btn').off('click').on('click', function () {
      var btn = $(this);
      if (btn.data('digium-busy')) return;
      var mac = $(this).data('mac');
      showConfirm('Delete Config File', 'Delete config for ' + mac + '? (MAC Mapping entries will not be deleted.)', function () {
        btn.data('digium-busy', true);
        ajax('deleteConfigFile', { mac: mac }, function (res) {
          btn.data('digium-busy', false);
          if (res && res.success) {
            showAlert('success', 'Deleted ' + mac + '.cfg');
            $('#configFilesTable').bootstrapTable('refresh');
          } else {
            showAlert('danger', 'Error: ' + (res ? res.error : 'Unknown error'));
          }
        });
      });
    });
    $('.fetch-config-btn').off('click').on('click', function () {
      var btn = $(this);
      var mac = $(this).data('mac');
      if (individualConfigFetches[mac]) {
        showAlert('info', 'A config fetch is already in progress for ' + mac + '.');
        return;
      }
      var token = ++configModalRequestSequence;
      individualConfigFetches[mac] = token;
      btn.data('original-html', btn.html()).addClass('disabled').html('<i class="fa fa-spinner fa-spin"></i>');
      $('#configEditMac').val(mac);
      $('#configEditTitle').text('Config from Phone — ' + mac + ' (Loading...)');
      $('#configEditContent').val('Fetching config from phone...').prop('readonly', true);
      $('#saveConfigBtn').hide();
      $('#saveActiveConfigBtn').hide();
      $('#configEditAlert').remove();
      $('#configEditModal').data('requestToken', token).modal('show');
      $.ajax({
        url: 'ajax.php?module=provisioner&command=fetchPhoneConfig',
        type: 'POST',
        data: { mac: mac },
        dataType: 'json',
        timeout: 120000
      }).done(function (res) {
        if ($('#configEditModal').data('requestToken') !== token || $('#configEditMac').val() !== mac) return;
        if (res && res.success) {
          var fetchState = res.session_reused ? 'session reused' : 'new session';
          $('#configEditTitle').text('Config from Phone — ' + mac + ' (' + fetchState + ')');
          $('#configEditContent').val(res.config).prop('readonly', true);
          $('#saveActiveConfigBtn').show().data('mac', mac);
        } else {
          var error = res ? res.error : 'Unknown error';
          var hint = /auth|credential|password/i.test(error) ? '\n\nCheck Phone Admin credentials in Settings.' : '\n\nWait briefly before retrying if the phone reports that its web interface is busy.';
          $('#configEditTitle').text('Config from Phone — ' + mac + ' (Failed)');
          $('#configEditContent').val('Failed to fetch config:\n' + error + hint).prop('readonly', true);
        }
      }).fail(function (xhr) {
        if ($('#configEditModal').data('requestToken') !== token || $('#configEditMac').val() !== mac) return;
        var error = xhr.statusText === 'timeout' ? 'Fetch timed out. The phone may be busy; wait before retrying.' : 'HTTP request failed.';
        $('#configEditTitle').text('Config from Phone — ' + mac + ' (Failed)');
        $('#configEditContent').val(error).prop('readonly', true);
      }).always(function () {
        if (individualConfigFetches[mac] === token) delete individualConfigFetches[mac];
        btn.removeClass('disabled').html(btn.data('original-html') || '<i class="fa fa-download"></i>');
      });
    });
    $('.revert-config-btn').off('click').on('click', function () {
      var btn = $(this);
      if (btn.data('digium-busy')) return;
      var mac = $(this).data('mac');
      showConfirm('Revert to Template', 'Restore the backed-up config (if available) and revert to template-generated config for next push?', function () {
        btn.data('digium-busy', true);
        ajax('revertConfigTemplate', { mac: mac }, function (res) {
          btn.data('digium-busy', false);
          if (res && res.success) {
            showAlert('success', res.message);
            $('#configFilesTable').bootstrapTable('refresh');
          } else {
            showAlert('danger', 'Revert failed: ' + (res ? res.error : 'Unknown error'));
          }
        });
      });
    });
    $('.restore-pulled-btn').off('click').on('click', function () {
      var btn = $(this);
      if (btn.data('digium-busy')) return;
      var mac = btn.data('mac');
      btn.data('digium-busy', true);
      ajax('getCustomBackupInfo', { mac: mac }, function (info) {
        if (!info || !info.success) {
          btn.data('digium-busy', false);
          showAlert('danger', 'Restore failed: ' + (info ? info.error : 'Unknown error'));
          return;
        }
        btn.data('digium-busy', false);
        var esc = function (value) { return $('<div>').text(value === null || value === undefined ? 'Unknown' : String(value)).html(); };
        var mismatch = !info.extension_matches;
        var message = '<p>Restore the archived pulled config for <strong>' + esc(mac) + '</strong>?</p>'
          + '<dl class="dl-horizontal">'
          + '<dt>Archive</dt><dd>' + esc(info.file) + '</dd>'
          + '<dt>Archived extension</dt><dd>' + esc(info.archived_extension) + '</dd>'
          + '<dt>Current extension</dt><dd>' + esc(info.current_extension) + '</dd>'
          + '<dt>Modified</dt><dd>' + esc(info.modified) + '</dd>'
          + '</dl>'
          + '<p>The active generated config will be saved as <code>.bak</code>. The pulled archive will be preserved.</p>';
        if (mismatch) message += '<div class="alert alert-warning">The archived and current extensions do not match. Continuing will require a second confirmation.</div>';
        showConfirm('Restore Pulled Config', message, function () {
          btn.data('digium-busy', true);
          restorePulledConfig(btn, mac, false);
        }, null, 'Continue');
      });
    });
  });

  function restorePulledConfig(btn, mac, force) {
    ajax('restoreCustomBackup', { mac: mac, force: force ? 1 : 0 }, function (res) {
      if (res && res.requires_confirmation && !force) {
        btn.data('digium-busy', false);
        var archived = $('<div>').text(res.archived_extension === null ? 'Unknown' : String(res.archived_extension)).html();
        var current = $('<div>').text(res.current_extension === null ? 'Unknown' : String(res.current_extension)).html();
        var warning = '<div class="alert alert-danger"><strong>High-risk restore:</strong> The archive is for extension <strong>'
          + archived + '</strong>, but this phone is currently mapped to <strong>' + current + '</strong>.</div>'
          + '<p>Force restoration anyway? The phone may register with the archived account until the config is corrected.</p>';
        showConfirm('Confirm Extension Mismatch', warning, function () {
          btn.data('digium-busy', true);
          restorePulledConfig(btn, mac, true);
        }, null, 'Force Restore');
        return;
      }
      btn.data('digium-busy', false);
      if (res && res.success) {
        showAlert('success', res.message);
        $('#configFilesTable').bootstrapTable('refresh');
        $('#devicesTable').bootstrapTable('refresh');
        loadPendingCount();
      } else {
        showAlert('danger', 'Restore failed: ' + (res ? res.error : 'Unknown error'));
      }
    });
  }

  $('#deleteSelectedConfigBtn').off('click').on('click', function () {
    if (selectedConfigMacs.length === 0) return;
    showConfirm('Delete Configs', 'Delete ' + selectedConfigMacs.length + ' config file(s)? (MAC Mapping entries will not be deleted.)', function () {
      ajax('deleteSelectedConfigFiles', { macs: selectedConfigMacs }, function (res) {
        if (res && res.success) {
          showAlert('success', 'Deleted ' + res.deleted + ' file(s).');
          $('#configFilesTable').bootstrapTable('refresh');
        } else {
          showAlert('danger', 'Error: ' + (res ? res.error : 'Unknown error'));
        }
      });
    });
  });

  $('#pullSelectedConfigBtn').off('click').on('click', function () {
    if (selectedConfigMacs.length === 0) return;
    showConfirm('Pull Configs', 'Download config from ' + selectedConfigMacs.length + ' phone(s) and override their template configs? Current configs will be backed up. Only one backup kept per phone.', function () {
      ajax('pullSelectedConfigFiles', { macs: selectedConfigMacs }, function (res) {
        if (res && res.success) {
          var msg = 'Config pulled from ' + res.pulled + ' phone(s).';
          if (res.errors.length) msg += ' ' + res.errors.length + ' failed.';
          showAlert('success', msg);
          $('#configFilesTable').bootstrapTable('refresh');
        } else {
          showAlert('danger', 'Pull failed: ' + (res ? res.error : 'Unknown error'));
        }
      });
    });
  });

  $('#revertSelectedConfigBtn').off('click').on('click', function () {
    if (selectedConfigMacs.length === 0) return;
    showConfirm('Revert Configs', 'Restore backed-up config for ' + selectedConfigMacs.length + ' device(s) and revert to template-generated config?', function () {
      ajax('revertSelectedConfigFiles', { macs: selectedConfigMacs }, function (res) {
        if (res && res.success) {
          var msg = 'Reverted ' + res.reverted + ' device(s).';
          if (res.errors.length) msg += ' ' + res.errors.length + ' failed.';
          showAlert('success', msg);
          $('#configFilesTable').bootstrapTable('refresh');
        } else {
          showAlert('danger', 'Revert failed: ' + (res ? res.error : 'Unknown error'));
        }
      });
    });
  });

  $('#saveActiveConfigBtn').off('click').on('click', function () {
    if (savingActiveConfig) return;
    var btn = $(this);
    var mac = $(this).data('mac');
    var content = $('#configEditContent').val();
    showConfirm('Override Active Config', 'Override the current ' + mac + '.cfg with the config from phone? Current file will be backed up.', function () {
      if (savingActiveConfig) return;
      savingActiveConfig = true;
      btn.prop('disabled', true).html('<i class="fa fa-spinner fa-spin"></i> Saving...');
      ajax('saveAsActiveConfig', { mac: mac, content: content }, function (res) {
        savingActiveConfig = false;
        btn.prop('disabled', false).html('<i class="fa fa-save"></i> Override Active Config');
        if (res && res.success) {
          showAlert('success', res.message);
          $('#configFilesTable').bootstrapTable('refresh');
          $('#configEditModal').modal('hide');
        } else {
          showAlert('danger', 'Save failed: ' + (res ? res.error : 'Unknown error'));
        }
      });
    });
  });

  $('#configEditModal').on('hidden.bs.modal', function () {
    $(this).removeData('requestToken');
    $('#saveActiveConfigBtn').hide();
    $('#saveConfigBtn').show();
    $('#configEditContent').prop('readonly', false);
  });

  $('#saveConfigBtn').off('click').on('click', function () {
    var mac = $('#configEditMac').val();
    var content = $('#configEditContent').val();
    ajax('saveConfigFile', {
      mac: mac,
      content: content
    }, function (res) {
      if (res && res.success) {
        showAlert('success', 'Config file saved.');
        $('#configFilesTable').bootstrapTable('refresh');
        var cfgExt = $('#configEditModal').data('configExt');
        if (cfgExt) {
            showConfirm('Push & Reboot', 'Reboot extension ' + cfgExt + ' now to load the new config?', function () {
            ajax('rebootPhone', { extension: cfgExt }, function (r) {
              if (r && r.success) {
                showAlert('success', 'Reboot sent to extension ' + cfgExt);
              } else {
                showAlert('danger', 'Reboot failed for extension ' + cfgExt);
              }
            });
          });
        }
      } else {
        showAlert('danger', 'Save failed: ' + (res ? res.error : 'Unknown error'));
      }
    });
  });

  function startLiveRefresh() {
    liveUpdates.setEnabled(true);
  }

  function stopLiveRefresh() {
    liveUpdates.setEnabled(false);
  }

  function refreshPhoneTable() {
    $('#phoneStatusTable').bootstrapTable('refresh');
    loadPendingCount();
  }

  $('#liveToggleBtn').off('click').on('click', function () {
    if ($(this).data('active')) {
      stopLiveRefresh();
    } else {
      startLiveRefresh();
    }
  });

  var liveStorage;
  try { liveStorage = window.localStorage; } catch (e) { liveStorage = {}; }
  function renderPhoneStatusUpdated(timestamp) {
    if (!timestamp || !isFinite(timestamp)) {
      $('#phoneStatusLastUpdated').text('Waiting for first status update');
      return;
    }
    var date = new Date(timestamp);
    $('#phoneStatusLastUpdated').text('Last successful update ' + date.toLocaleTimeString())
      .attr('title', date.toLocaleString());
  }
  var lastPhoneStatusUpdate = 0;
  try { lastPhoneStatusUpdate = Number(liveStorage.getItem('provisioner-live-last-success')) || 0; } catch (e) {}
  renderPhoneStatusUpdated(lastPhoneStatusUpdate);
  liveUpdates = new window.ProvisionerLiveStatus({
    storage: liveStorage,
    now: function () { return Date.now(); },
    setTimeout: window.setTimeout.bind(window),
    clearTimeout: window.clearTimeout.bind(window),
    isVisible: function () { return $('#phones').hasClass('active') && !document.hidden; },
    isBlocked: function () { return $('.modal.in, .modal.show, .bootbox.modal').length > 0; },
    createSource: function () { return new window.EventSource('ajax.php?module=provisioner&command=streamPhoneStatus'); },
    fetchSnapshot: function (callback) { ajax('getPhoneStatusSnapshot', {}, callback, 8000); },
    getRows: function () { return $('#phoneStatusTable').bootstrapTable('getData', { unfiltered: true }); },
    applyRows: function (updates, added, removed) {
      var table = $('#phoneStatusTable');
      if (removed.length) table.bootstrapTable('remove', { field: 'mac', values: removed });
      if (added.length) table.bootstrapTable('append', added);
      if (updates.length) table.bootstrapTable('updateByUniqueId', updates);
      selectedExtensions.length = 0;
      table.bootstrapTable('getSelections').forEach(function (row) { selectedExtensions.push(row.extension); });
      updateButtonStates();
      renderPhoneStatusSummary();
    },
    onSuccessfulUpdate: function () {
      lastPhoneStatusUpdate = Date.now();
      try { liveStorage.setItem('provisioner-live-last-success', String(lastPhoneStatusUpdate)); } catch (e) {}
      renderPhoneStatusUpdated(lastPhoneStatusUpdate);
    },
    applyPending: renderPendingCount,
    setState: function (state, enabled) {
      var live = state === 'Live';
      var text = enabled ? 'Live Updates · ' + state : 'Live Updates · Paused';
      $('#liveToggleBtn').show().removeClass('btn-success btn-warning btn-secondary')
        .addClass(live ? 'btn-success' : (enabled && state === 'Reconnecting' ? 'btn-warning' : 'btn-secondary'))
        .html('<i class="fa ' + (live ? 'fa-circle' : 'fa-pause') + '"></i> ' + text)
        .data('active', enabled).attr('aria-pressed', enabled ? 'true' : 'false')
        .attr('title', enabled ? 'Live Asterisk and Provisioner updates. Click to pause.' : 'Click to enable live updates.');
    }
  });
  $(document).on('visibilitychange.provisionerLive', function () { liveUpdates.sync(); });
  $(document).on('hidden.bs.modal.provisionerLive', function () { liveUpdates.flush(); });
  $(window).on('pagehide.provisionerLive', function () { liveUpdates.pause(); });
  $(window).on('pageshow.provisionerLive', function () { liveUpdates.sync(); });

  // =====================
  // ADD / EDIT DEVICE
  // =====================
  function loadExtensions(selectedExt, callback) {
    var sel = $('#deviceExtension');
    sel.empty().append('<option value="">Loading extensions...</option>');
    ajax('getExtensions', {}, function (exts) {
      sel.empty().append('<option value="">-- Select Extension --</option>');
      if (exts && exts.length) {
        $.each(exts, function (i, e) {
          var opt = $('<option>').val(e.extension).text(e.extension + ' (' + e.name + ')');
          if (selectedExt && e.extension === selectedExt) opt.prop('selected', true);
          sel.append(opt);
        });
      }
      if (callback) callback();
    });
  }

  $('#addDeviceBtn').off('click').on('click', function () {
    $('#deviceModalTitle').text('Add Device');
    $('#deviceId').val('');
    $('#deviceMac').val('');
    $('#deviceBrand').val($('#deviceBrand option:first').val());
    $('#deviceDescription').val('');
    $('#deviceIpMode').val('dhcp');
    $('#staticFields').hide();
    $('#deviceStaticIp').val('');
    $('#deviceStaticMask').val('');
    $('#deviceStaticGw').val('');
    $('#deviceModalError').hide();
    loadExtensions('', function () { $('#deviceModal').modal('show'); });
  });

  function cidrToMask(val) {
    if (!val) return null;
    if (/^\d{1,2}$/.test(val)) {
      var n = parseInt(val);
      if (n >= 0 && n <= 32) {
        var mask = ~(Math.pow(2, 32 - n) - 1) >>> 0;
        return [(mask >>> 24), (mask >> 16 & 255), (mask >> 8 & 255), (mask & 255)].join('.');
      }
    }
    return val;
  }

  function maskToCidr(val) {
    if (!val) return '';
    if (/^\d{1,2}$/.test(val)) return val;
    var parts = val.split('.').map(Number);
    if (parts.length !== 4 || parts.some(function(p) { return isNaN(p) || p < 0 || p > 255; })) return '';
    var bin = parts.map(function(p) { return (p >>> 0).toString(2).padStart(8, '0'); }).join('');
    if (!/^1+0*$/.test(bin)) return '';
    return bin.indexOf('0') === -1 ? '32' : String(bin.indexOf('0'));
  }

  $('#deviceIpMode').on('change', function () {
    $('#staticFields').toggle($(this).val() === 'static');
  });

  $('#maskPresets').on('change', function () {
    if ($(this).val()) {
      $('#deviceStaticMask').val($(this).val());
    }
  });

  $('#deviceStaticMask').on('input', function () {
    var cidr = maskToCidr($(this).val());
    $('#maskPresets').val(cidr || '');
  });

  var saving = false;
  $('#saveDeviceBtn').off('click').on('click', function () {
    if (saving) return;
    var id   = $('#deviceId').val();
    var mac  = $('#deviceMac').val().trim();
    var ext  = $('#deviceExtension').val().trim();
    var brand = $('#deviceBrand').val();
    var desc = $('#deviceDescription').val().trim();
    if (!mac || !ext) {
      $('#deviceModalError').text('MAC address and extension are required.').show();
      return;
    }
    saving = true;
    var command = id ? 'updateDevice' : 'addDevice';
    var isStatic = $('#deviceIpMode').val() === 'static';
    var staticIp  = isStatic ? $('#deviceStaticIp').val().trim() || null : null;
    var staticMask = isStatic ? cidrToMask($('#deviceStaticMask').val().trim()) : null;
    var staticGw  = isStatic ? $('#deviceStaticGw').val().trim() || null : null;
    var data    = { mac: mac, extension: ext, description: desc, brand: brand, static_ip: staticIp, static_mask: staticMask, static_gw: staticGw };
    if (id) data.id = id;
    ajax(command, data, function (res) {
      saving = false;
      if (res && res.success) {
        $('#deviceModal').modal('hide');
        showAlert('success', 'Device saved. Use Push Config to generate configs and reboot.');
        $('#devicesTable').bootstrapTable('refresh');
        loadPendingCount();
      } else {
        $('#deviceModalError').text('Error: ' + (res ? res.error : 'Unknown error')).show();
      }
    });
  });

  // =====================
  // DISCARD ALL
  // =====================
  var discarding = false;
  $('#discardAllBtn').off('click').on('click', function () {
    if (discarding) return;
    showConfirm('Discard All Changes', 'Revert ALL pending devices to their previous values? This cannot be undone.', function () {
      discarding = true;
      ajax('discardAllChanges', {}, function (res) {
        discarding = false;
        if (res && res.success) {
          showAlert('success', 'Discarded changes for ' + res.affected + ' device(s).');
          $('#devicesTable').bootstrapTable('refresh');
          loadPendingCount();
        } else {
          showAlert('danger', 'Error: ' + (res ? res.error : 'Unknown error'));
        }
      });
    });
  });

  // =====================
  // DELETE SELECTED
  // =====================
  $('#deleteSelectedBtn').off('click').on('click', function () {
    if (selectedDeviceIds.length === 0) return;
    showConfirm('Delete Mappings', 'Delete ' + selectedDeviceIds.length + ' selected ' + pluralWord(selectedDeviceIds.length, 'mapping') + '? This cannot be undone.', function () {
      ajax('deleteDevices', { ids: selectedDeviceIds }, function (res) {
        if (res && res.success) {
          showAlert('success', 'Deleted ' + res.deleted + ' ' + pluralWord(res.deleted, 'mapping') + '.');
          $('#devicesTable').bootstrapTable('refresh');
          $('#phoneStatusTable').bootstrapTable('refresh');
          loadPendingCount();
        } else {
          showAlert('danger', 'Error: ' + (res ? res.error : 'Unknown error'));
        }
      });
    });
  });

  // =====================
  // AUTO-ASSIGN EXTENSIONS
  // =====================
  $('#autoAssignBtn').off('click').on('click', function () {
    var btn = $(this);
    btn.prop('disabled', true).html('<i class="fa fa-spinner fa-spin"></i> Loading...');
    ajax('autoAssignExtensions', {}, function (res) {
      btn.prop('disabled', false).html('<i class="fa fa-magic"></i> Auto-Assign');
      if (!res || res.error) {
        showAlert('danger', res && res.error ? res.error : 'Auto-assign request failed.');
        return;
      }
      if (!res.success || !res.assignments || res.assignments.length === 0) {
        showAlert('info', 'No devices without extensions to assign.');
        return;
      }
      var html = '<p>Assign the following extensions?</p><table class="table table-condensed"><thead><tr><th>MAC</th><th>Extension</th></tr></thead><tbody>';
      $.each(res.assignments, function (i, a) {
        html += '<tr><td>' + a.mac + '</td><td><strong>' + a.extension + '</strong></td></tr>';
      });
      html += '</tbody></table>';
      showConfirm('Auto-Assign Extensions (' + res.assignments.length + ')', html, function () {
        btn.prop('disabled', true).html('<i class="fa fa-spinner fa-spin"></i> Assigning...');
        ajax('autoAssignExtensions', { save: true }, function (res2) {
          btn.prop('disabled', false).html('<i class="fa fa-magic"></i> Auto-Assign');
          if (res2 && res2.success) {
            showAlert('success', 'Assigned ' + res2.assignments.length + ' extension(s). Review and start the pending config push.');
            $('#devicesTable').bootstrapTable('refresh');
            $('#phoneStatusTable').bootstrapTable('refresh');
            loadPendingCount(function () {
              selectedPushMacs = [];
              openApplyModal('pending');
            });
          } else {
            showAlert('danger', 'Assignment failed: ' + (res2 ? res2.error : 'Unknown error'));
          }
        });
      });
    });
  });

  $('#discoverPjsipBtn').off('click').on('click', function () {
    if (discoveryBusy) return;
    var btn = $(this);
    btn.prop('disabled', true).html('<i class="fa fa-spinner fa-spin"></i> Discovering...');
    ajax('autoDiscoverFromPjsip', {}, function (res) {
      btn.prop('disabled', false).html('<i class="fa fa-phone"></i> Discover from PJSIP');
      if (!res || !res.success) {
        showAlert('warning', res ? res.error : 'PJSIP discovery failed.');
        return;
      }
      var matches = (res.matches || []).filter(function (row) { return ['digium', 'alcatel'].indexOf(row.brand) !== -1; });
      scanCompleted = true;
      discoveryScanMode = 'routed';
      discoverySubnet = 'PJSIP registrations';
      discoveryExcludedResponders = [];
      $('#discoveryMethodSummary').text('Available PJSIP registrations · User-Agent identity with local ARP matching where available');
      loadDiscoveredRows(matches, true, true);
      if (matches.length === 0) {
        showAlert('info', 'No available registered phones could be identified by their SIP User-Agent or local ARP.');
        return;
      }
      $('#scanStatus').html('<p class="text-muted"><strong>' + matches.length
        + '</strong> registered phone(s) identified, including routed subnets. MAC identity comes from SIP User-Agent or local ARP.</p>').show();
    });
  });

  // =====================
  // BULK HANDLER TAB PILLS
  // =====================
  function activateBulkSection(target) {
    $('.bulk-pill').removeClass('active');
    $('.bulk-pill').closest('li').removeClass('active');
    $('.bulk-pill[href="' + target + '"]').addClass('active').closest('li').addClass('active');
    $('#import .bulk-handler-section').hide();
    $(target).show();
    if (target !== '#bulkReportsSection') stopBatchReportPolling();
  }
  $(document).on('click', '.bulk-pill', function (e) {
    e.preventDefault();
    activateBulkSection($(this).attr('href'));
  });

  // =====================
  // BULK IMPORT
  // =====================
  // File upload handler — read CSV into textarea
  var importPreview = null;
  var importPreviewRequest = 0;
  var importedMacs = [];
  var savedImportBatchId = '';
  function setImportStep(step) {
    $('#importSteps .provisioner-step').each(function () {
      var number = Number($(this).data('step'));
      $(this).toggleClass('active', number === step).toggleClass('complete', number < step);
    });
  }
  function invalidateImportPreview(closeReview) {
    importPreviewRequest++;
    importPreview = null;
    $('#commitImportBtn').prop('disabled', true).html('Save Mappings');
    $('#importSavedPanel').hide();
    $('#importReviewError').hide().empty();
    $('#importReviewContent').hide();
    $('#importReviewLoading').show();
    $('#importReviewSummary').empty();
    $('#importReviewStatus').text('').removeClass('warning success');
    $('#importResults').empty().hide();
    $('#importPreviewFilter').val('all');
    setImportStep(1);
    if (closeReview !== false) $('#importReviewModal').modal('hide');
  }

  function applyImportPreviewFilter(filter) {
    $('.import-preview-row').each(function () {
      var rowState = $(this).data('import-state');
      var needs = String($(this).data('import-needs-id')) === '1';
      var visible = filter === 'all' || (filter === 'needs_id' ? needs : filter === 'invalid' ? rowState === 'invalid'
        : filter === 'updated' ? rowState === 'updated' : rowState === filter);
      $(this).toggle(visible);
    });
  }
  $('#importCsv').on('input', invalidateImportPreview);
  $('#importCsvFile').off('change').on('change', function () {
    var file = this.files[0];
    if (!file) return;
    var reader = new FileReader();
    reader.onload = function (e) {
      $('#importCsv').val(e.target.result);
      invalidateImportPreview();
    };
    reader.readAsText(file);
  });

  $('#importBtn').off('click').on('click', function () {
    var csv = $('#importCsv').val().trim();
    if (!csv) { showAlert('danger', 'Please provide CSV content.'); return; }
    invalidateImportPreview();
    $('#importBtn').prop('disabled', true);
    $('#importReviewModal').modal('show');
    var requestToken = importPreviewRequest;
    ajax('previewBulkImport', { csv_content: csv }, function (res) {
      $('#importBtn').prop('disabled', false);
      if (requestToken !== importPreviewRequest) return;
      if (!res || !res.success) {
        $('#importReviewLoading').hide();
        $('#importReviewError').text(res ? res.error : 'Preview failed.').show();
        showAlert('danger', res ? res.error : 'Preview failed.');
        return;
      }
      if ($('#importCsv').val().trim() !== csv) return;
      importPreview = {csv: csv, revision: res.revision, summary: res.summary};
      var summary = res.summary;
      var needsId = res.rows.filter(function (row) { return row.valid && !row.push_ready; }).length;
      var rowsByMac = {};
      res.rows.forEach(function (row) { rowsByMac[String(row.mac).toLowerCase()] = row; });
      var swapPairs = {};
      res.rows.forEach(function (row) {
        (row.warnings || []).forEach(function (warning) {
          var match = String(warning).match(/Reassignment\/swap with ([a-f0-9]{12})/i);
          if (!match) return;
          var partner = rowsByMac[match[1].toLowerCase()];
          if (!partner) return;
          var pair = [row, partner].sort(function (a, b) { return a.mac.localeCompare(b.mac); });
          swapPairs[pair[0].mac.toLowerCase() + ':' + pair[1].mac.toLowerCase()] = pair;
        });
      });
      var swapSummary = Object.keys(swapPairs).length ? '<details class="import-swap-details"><summary>Extension swap partners (' + Object.keys(swapPairs).length + ')</summary><ul style="margin:6px 0 0;padding-left:20px;">'
        + Object.keys(swapPairs).map(function (key) {
          var pair = swapPairs[key];
           return '<li><code>' + diagnosticsEsc(pair[0].mac) + '</code> ' + diagnosticsEsc(pair[0].old_extension || '(new)') + ' <i class="fa fa-long-arrow-right"></i> ' + diagnosticsEsc(pair[0].extension)
             + ' <strong>↔</strong> <code>' + diagnosticsEsc(pair[1].mac) + '</code> ' + diagnosticsEsc(pair[1].old_extension || '(new)') + ' <i class="fa fa-long-arrow-right"></i> ' + diagnosticsEsc(pair[1].extension) + '</li>';
         }).join('') + '</ul><small>Keep partners together when pushing the completed swap.</small></details>' : '';
      var validRows = res.rows.filter(function (row) { return row.valid; }).length;
      var invalidRows = res.rows.length - validRows;
      var summaryHtml = '<div class="provisioner-summary-group" aria-label="Import preview totals">'
        + '<span class="provisioner-summary-card"><strong>' + summary.new + '</strong><small>New</small></span>'
        + '<span class="provisioner-summary-card"><strong>' + summary.update + '</strong><small>Changed</small></span>'
        + '<span class="provisioner-summary-card"><strong>' + summary.unchanged + '</strong><small>Unchanged</small></span>'
        + '<span class="provisioner-summary-card"><strong>' + summary.invalid + '</strong><small>Invalid</small></span>'
        + '<span class="provisioner-summary-card"><strong>' + needsId + '</strong><small>Need identification</small></span>'
        + '<span class="provisioner-summary-card"><strong>' + validRows + '</strong><small>Ready to save</small></span></div>';
      $('#importReviewSummary').html(summaryHtml);
      $('#importReviewStatus').text(res.can_import
        ? validRows + ' ' + pluralWord(validRows, 'row') + ' ready to save.' + (needsId ? ' ' + needsId + ' need identification before a push.' : '')
        : 'Fix ' + invalidRows + ' invalid ' + pluralWord(invalidRows, 'row') + ' before saving.')
        .removeClass('warning success').addClass(res.can_import ? 'success' : 'warning');
      var initialFilter = Number(summary.invalid) > 0 ? 'invalid' : 'all';
      $('#importPreviewFilter').val(initialFilter);
      var html = swapSummary + '<p class="text-muted">Offline phones can still be mapped; handset identity is checked again before a push.</p>'
        + '<div class="table-responsive"><table class="table table-striped import-preview-table"><thead><tr><th>Row</th><th>Phone / extension</th><th>Mapping change</th><th>Push readiness</th><th>Validation / notes</th></tr></thead><tbody>';
      res.rows.forEach(function (row) {
        var state = !row.valid ? 'invalid' : (row.action === 'update' ? 'updated' : (row.action === 'unchanged' ? 'unchanged' : 'new'));
        var changes = (row.changes || []).map(function (change) {
          var match = String(change).match(/^([^:]+):\s*(.*?)\s+->\s+(.*)$/);
          if (!match) return '<li>' + diagnosticsEsc(change) + '</li>';
          return '<li><strong>' + diagnosticsEsc(match[1]) + '</strong> '
            + diagnosticsEsc(match[2]) + ' <i class="fa fa-long-arrow-right" aria-hidden="true"></i> ' + diagnosticsEsc(match[3]) + '</li>';
        }).join('');
        var mapping = row.action === 'new' ? '<span class="text-info">New mapping</span>'
          : (changes ? '<ul style="padding-left:18px;margin:0;">' + changes + '</ul>' : '<span class="text-muted">No mapping changes</span>');
        var errors = (row.errors || []).map(function (error) { return '<div class="text-danger"><i class="fa fa-times-circle"></i> ' + diagnosticsEsc(error) + '</div>'; }).join('');
        var warnings = (row.warnings || []).map(function (warning) { return '<div class="text-warning"><i class="fa fa-exclamation-triangle"></i> ' + diagnosticsEsc(warning) + '</div>'; }).join('');
        var validation = errors + warnings + (!errors && !warnings ? '<span class="text-success"><i class="fa fa-check-circle"></i> Valid</span>' : '');
        var readiness = row.push_ready ? '<span class="text-success"><i class="fa fa-check-circle"></i> Ready</span>' : '<span class="text-warning"><i class="fa fa-exclamation-circle"></i> Needs attention</span>';
        readiness += (row.target_ip ? '<br><code>' + diagnosticsEsc(row.target_ip) + '</code>' : '') + '<br><small>' + diagnosticsEsc(row.readiness) + '</small>';
        var isSwap = (row.warnings || []).some(function (warning) { return /Reassignment\/swap with /i.test(warning); });
        html += '<tr class="import-preview-row' + (isSwap ? ' import-swap-row' : '') + '" data-import-state="' + state + '" data-import-needs-id="' + (row.valid && !row.push_ready ? '1' : '0') + '"><td>' + row.row
          + '</td><td><code>' + diagnosticsEsc(row.mac) + '</code><br>Ext ' + diagnosticsEsc(row.extension)
          + '</td><td><div><span class="label label-default">' + diagnosticsEsc(row.action) + '</span></div>' + mapping
          + '</td><td>' + readiness + '</td><td>' + validation + '</td></tr>';
      });
      $('#importReviewLoading').hide();
      $('#importReviewError').hide().empty();
      $('#importReviewContent').show();
      $('#importResults').html(html + '</tbody></table></div>').show();
      setImportStep(2);
      $('#importPreviewFilter').off('change').on('change', function () {
        applyImportPreviewFilter($(this).val());
      });
      applyImportPreviewFilter(initialFilter);
      $('#commitImportBtn').prop('disabled', !res.can_import)
        .html('Save Mappings' + (validRows ? ' <span class="badge">' + validRows + '</span>' : ''));
    });
  });

  $('#commitImportBtn').off('click').on('click', function () {
    var csv = $('#importCsv').val().trim();
    if (!importPreview || importPreview.csv !== csv) { invalidateImportPreview(); return; }
    var revision = importPreview.revision;
    var savedSummary = importPreview.summary;
    invalidateImportPreview(false);
    $('#importReviewLoading').hide();
    $('#importReviewContent').hide();
    $('#importReviewError').hide().empty();
    $('#commitImportBtn').prop('disabled', true).html('<i class="fa fa-spinner fa-spin"></i> Saving…');
    $('#cancelImportReviewBtn').prop('disabled', true);
    ajax('bulkImport', { csv_content: csv, revision: revision }, function (res) {
      $('#cancelImportReviewBtn').prop('disabled', false);
      if (!res || res.error) {
        var error = res && res.error ? res.error : 'Import failed.';
        $('#importReviewError').text(error).show();
        $('#commitImportBtn').prop('disabled', true).html('Save Mappings');
        showAlert('danger', error);
        return;
      }
      if (res.success > 0) {
        importedMacs = res.macs || [];
        savedImportBatchId = res.batch_id || '';
        updateImportPushButton();
        $('#pushImportedPhonesCount').text(importedMacs.length);
        var summaryText = res.success + ' ' + pluralWord(res.success, 'mapping') + ' saved';
        var detailParts = [['new', 'new'], ['update', 'changed'], ['unchanged', 'unchanged']].filter(function (part) {
          return Number(savedSummary[part[0]]) > 0;
        }).map(function (part) { return savedSummary[part[0]] + ' ' + part[1]; });
        detailParts.push('Configurations have not been pushed');
        var detailText = detailParts.join(' · ');
        $('#importSavedSummary').text(summaryText);
        $('#importSavedDetails').text(detailText);
        $('#importReviewModal').modal('hide');
        $('#importSavedPanel').show();
        $('#importResults').hide();
        setImportStep(3);
        $('#devicesTable').bootstrapTable('refresh');
        latestBatchId = savedImportBatchId;
        if (savedImportBatchId) $('#openLatestBatchBtn').show();
        loadPendingCount();
      } else {
        var noSaveError = res.error || 'No mappings were saved.';
        $('#importReviewError').text(noSaveError).show();
        $('#commitImportBtn').prop('disabled', true).html('Save Mappings');
        showAlert('danger', noSaveError);
      }
    });
  });

  $('#pushImportedPhones').on('click', function () {
    if (!importedMacs.length) return;
    if (deploymentMode !== 'sip_notify') {
      showAlert('warning', 'Set Deployment Mode to SIP NOTIFY before using Push Imported Phones. The mappings are saved and can be reviewed in MAC Mapping.');
      return;
    }
    selectedPushMacs = importedMacs.slice();
    selectedPushAction = 'push';
    openApplyModal('push');
  });
  $('#viewImportedMappings').on('click', function () {
    $('a[href="#devices"]').tab('show');
    $('#devicesTable').bootstrapTable('refresh');
  });
  $('#viewImportReport').on('click', function () {
    if (savedImportBatchId) showBatchReport(savedImportBatchId, true);
  });

  // =====================
  // SAVED BATCH REPORTS
  // =====================
  var currentReportId = '', latestBatchId = '', reportPollGeneration = 0, reportPollTimer = null;
  var batchRetryBusy = false, currentBatchReport = null, reportHasError = false, reportRequestPending = false, historyHasError = false;
  var historyTableReady = false, reportTableReady = false, historyRequestGeneration = 0, reportDisplayedId = '';
  var reportFilterActive = 'all', expandedReportMacs = {};
  function readableBatchStatus(status) {
    return ({submitted:'In progress', running:'In progress', complete:'Complete', incomplete:'Needs attention', failed:'Failed'})[status] || status || 'Unknown';
  }
  function batchStatusClass(status) {
    return ({complete:'complete', submitted:'in-progress', running:'in-progress', incomplete:'attention', failed:'attention'})[status] || 'neutral';
  }
  function readableOutcomeCount(status) {
    return ({verified:'Verified', imported:'Mappings saved', running:'In progress', verifying:'Verifying', failed:'Failed',
      incomplete:'Needs attention', blocked:'Blocked', superseded:'Superseded'})[status] || status;
  }
  function readableBatchDate(value) {
    if (!value) return 'Unknown time';
    var date = new Date(String(value).replace(' ', 'T'));
    return isNaN(date.getTime()) ? String(value) : date.toLocaleString();
  }
  function stageView(stage, value) {
    var labels = {
      generation: {generated:'Config ready', existing_config:'Config reused', custom_preserved:'Custom config kept', failed:'Config failed', not_started:'Config pending'},
      trigger: {accepted:'Triggered', failed:'Trigger failed', not_started:'Not sent'},
      delivery: {downloaded:'Downloaded', pending:'Waiting for download', failed:'Download failed', not_started:'No download'},
      verification: {verified:'Verified', pending:'Verify pending', verifying:'Verifying', failed:'Verify failed', incomplete:'Not verified', not_started:'Not checked'}
    };
    var label = (labels[stage] || {})[value] || value || 'Not started';
    var done = (stage === 'generation' && ['generated','existing_config','custom_preserved'].indexOf(value) !== -1)
      || (stage === 'trigger' && value === 'accepted') || (stage === 'delivery' && value === 'downloaded')
      || (stage === 'verification' && value === 'verified');
    var failed = value === 'failed' || value === 'incomplete';
    var active = value === 'pending' || value === 'verifying';
    var icon = done ? 'fa-check-circle' : (failed ? 'fa-times-circle' : (active ? 'fa-spinner' : 'fa-circle-o'));
    return '<span class="batch-stage ' + (done ? 'done' : (failed ? 'failed' : (active ? 'active' : 'pending'))) + '"><i class="fa ' + icon + '"></i> ' + diagnosticsEsc(label) + '</span>';
  }
  function batchOutcomeView(row) {
    var tone = reportOutcomeGroup(row.outcome) === 'completed' ? 'complete' : (reportOutcomeGroup(row.outcome) === 'progress' ? 'in-progress' : 'attention');
    return '<span class="bulk-status ' + tone + '">' + diagnosticsEsc(readableOutcomeCount(row.outcome) || 'Unknown') + '</span>';
  }

  function reportOutcomeGroup(outcome) {
    if (outcome === 'verified' || outcome === 'imported') return 'completed';
    if (outcome === 'running' || outcome === 'verifying') return 'progress';
    return 'attention';
  }

  function compactReportView() {
    return !!window.matchMedia && window.matchMedia('(max-width: 767px)').matches;
  }

  function reportSummaryItem(count, label) {
    return '<span class="provisioner-summary-card"><strong>' + (Number(count) || 0) + '</strong><small>' + diagnosticsEsc(label) + '</small></span>';
  }

  function setReportMessage(prefix, message, error) {
    $('#' + prefix + 'MessageText').text(message);
    $('#' + prefix + 'Message').toggleClass('is-error', !!error).toggle(!!message);
    $('#' + (prefix === 'batchHistory' ? 'reloadBatchHistoryBtn' : 'reloadBatchReportBtn')).toggle(!!error);
  }

  function updateReportRetryButton() {
    var report = currentBatchReport;
    var count = report && report.batch.id === currentReportId ? (report.retry_macs || []).length : 0;
    var enabled = count > 0 && !batchRetryBusy && !reportHasError && !report.active;
    $('#retryBatchBtn').prop('disabled', !enabled).attr('aria-disabled', enabled ? 'false' : 'true')
      .attr('title', batchRetryBusy ? 'Starting a retry batch…' : report && report.active ? 'Wait for this batch to finish before retrying.'
        : reportHasError ? 'Refresh the report to check its current state before retrying.'
          : count ? 'Retry all ' + count + ' eligible ' + pluralWord(count, 'phone') + ' in this batch, including required swap partners. Search and filters do not change this scope.'
            : 'No eligible phones in this batch. Changed mappings require a new push.');
    $('#retryBatchCount').text(count);
  }

  function reportPhoneDetails(index, row) {
    var progress = row.report_kind === 'import' ? '<span class="batch-stage done"><i class="fa fa-check-circle"></i> Mapping saved</span>'
      : '<div class="batch-stage-list">' + stageView('generation', row.generation) + '<i class="fa fa-angle-right text-muted"></i>'
        + stageView('trigger', row.trigger) + '<i class="fa fa-angle-right text-muted"></i>'
        + stageView('delivery', row.delivery) + '<i class="fa fa-angle-right text-muted"></i>'
        + stageView('verification', row.verification) + '</div>';
    var nextStep = row.error || (row.outcome === 'verified' ? 'No action needed.'
      : row.outcome === 'imported' ? 'Review this phone in MAC Mapping. Use Push Imported Phones from the saved import to deliver its configuration.'
        : reportOutcomeGroup(row.outcome) === 'progress' ? 'Waiting for the phone and background verification.'
          : row.retryable ? 'This phone is eligible for a retry using its current mapping and fresh target checks.'
            : 'Review the reason and current MAC mapping before starting a new push.');
    var fields = [['Target source', row.target_source || 'Not recorded'], ['Target IP', row.target_ip || '—'],
      ['Download IP', row.fetch_ip || '—'], ['Verification IP', row.verify_ip || '—']];
    var times = [['Attempt', row.attempt_at], ['Config generated', row.generation_at], ['Trigger', row.trigger_at],
      ['Download', row.delivery_at], ['Verification', row.verification_at]];
    var detailList = function (values) {
      return '<dl>' + values.map(function (field) { return '<dt>' + field[0] + '</dt><dd>' + diagnosticsEsc(field[1] || '—') + '</dd>'; }).join('') + '</dl>';
    };
    return '<div class="bulk-report-detail"><h5>Phone Details · ' + diagnosticsEsc(row.extension || 'Unassigned') + ' · <code>' + diagnosticsEsc(row.mac) + '</code></h5>'
      + progress + '<p><strong>Result / next step:</strong> ' + diagnosticsEsc(nextStep) + '</p>'
      + (row.mapping_changed ? '<p class="text-warning">Mapping changed since this batch; retry is blocked.</p>' : '')
      + (row.superseded_by_push ? '<p class="text-warning">A newer push has superseded this attempt.</p>' : '')
      + (row.action ? '<p><strong>Mapping ' + diagnosticsEsc(row.action) + ':</strong> ' + diagnosticsEsc((row.changes || []).join('; ') || 'No mapping changes') + '</p>' : '')
      + '<div class="bulk-report-detail-grid"><div><h5>Target Evidence</h5>' + detailList(fields) + '</div><div><h5>Attempt Times</h5>' + detailList(times) + '</div></div></div>';
  }

  function reportPhoneSearch(data, text) {
    var query = String(text || '').trim().toLowerCase();
    var macQuery = /^[a-f0-9:-]+$/i.test(query) ? query.replace(/[:-]/g, '') : '';
    return data.filter(function (row) {
      return (reportFilterActive === 'all' || reportOutcomeGroup(row.outcome) === reportFilterActive)
        && (!query || row.report_search.indexOf(query) !== -1 || (macQuery && rowMac(row).indexOf(macQuery) !== -1));
    });
  }

  function setReportDetailsButton(mac, open) {
    $('#batchReportTable .batch-phone-details[data-mac="' + rowMac({mac: mac}) + '"]')
      .attr('aria-expanded', open ? 'true' : 'false').html('<i class="fa fa-' + (open ? 'angle-up' : 'angle-down') + '"></i> ' + (open ? 'Hide Details' : 'Details'));
  }

  function initReportPhoneTable(rows) {
    if (reportTableReady) {
      // Loading data into the existing widget preserves search, sort, page size and page number.
      $('#batchReportTable').bootstrapTable('load', {rows: rows, fixedScroll: true});
      return;
    }
    reportTableReady = true;
    $('#batchReportTable').bootstrapTable({
      toolbar: '#batchReportToolbar', pagination: true, search: true, pageSize: 10, pageList: [10, 25, 50, 100],
      cardView: compactReportView(),
      uniqueId: 'mac', sortName: 'extension', sortOrder: 'asc', data: rows,
      detailView: true, detailViewIcon: false, detailFormatter: reportPhoneDetails, customSearch: reportPhoneSearch,
      formatSearch: function () { return 'Search extension, MAC, IP, or result'; },
      formatLoadingMessage: function () { return 'Loading report phones…'; },
      formatNoMatches: function () { return currentBatchReport && !currentBatchReport.items.length ? 'No phones in this report.' : 'No phones match your search or filter.'; },
      columns: [
        {field: 'extension', title: 'Extension', sortable: true, formatter: function (value) { return diagnosticsEsc(value || 'Unassigned'); }},
        {field: 'mac', title: 'Phone / MAC', sortable: true, class: 'bulk-report-phone', formatter: function (value, row) {
          return (row.brand ? diagnosticsEsc(row.brand) + '<br>' : '') + '<code>' + diagnosticsEsc(value) + '</code>';
        }},
        {field: 'report_ip', title: 'Target IP', sortable: true, sorter: window.ipSorter, class: 'bulk-report-target', formatter: function (value) {
          return value ? '<code>' + diagnosticsEsc(value) + '</code>' : '<span class="text-muted">Not recorded</span>';
        }},
        {field: 'outcome_label', title: 'Result', sortable: true, formatter: function (value, row) { return batchOutcomeView(row); }},
        {field: 'details', title: 'Details', searchable: false, formatter: function (value, row) {
          var open = !!expandedReportMacs[row.mac];
          return '<button type="button" class="btn btn-default batch-phone-details" data-mac="' + rowMac(row) + '" aria-expanded="' + open
            + '" aria-label="Details for extension ' + diagnosticsEsc(row.extension || 'unassigned') + ', MAC ' + diagnosticsEsc(row.mac) + '"><i class="fa fa-' + (open ? 'angle-up' : 'angle-down') + '"></i> ' + (open ? 'Hide Details' : 'Details') + '</button>';
        }, events: {'click .batch-phone-details': function (event, value, row, index) {
          event.preventDefault();
          $('#batchReportTable').bootstrapTable('toggleDetailView', index);
        }}}
      ],
      onExpandRow: function (index, row) { expandedReportMacs[row.mac] = true; setReportDetailsButton(row.mac, true); },
      onCollapseRow: function (index, row) { delete expandedReportMacs[row.mac]; setReportDetailsButton(row.mac, false); },
      onPostBody: function () {
        if (!Object.keys(expandedReportMacs).length) return;
        ($('#batchReportTable').bootstrapTable('getData', {useCurrentPage: true}) || []).forEach(function (row) {
          if (expandedReportMacs[row.mac]) $('#batchReportTable').bootstrapTable('expandRowByUniqueId', row.mac);
        });
      }
    });
    $('#batchReportResults .search input').attr('aria-label', 'Search phones in this report');
  }
  function csvCell(value) { return '"' + String(value == null ? '' : value).replace(/"/g, '""') + '"'; }
  function downloadCurrentBatchCsv() {
    if (!currentBatchReport || !currentBatchReport.items) return;
    var columns = [['MAC','mac'],['Extension','extension'],['Brand','brand'],['Target IP','target_ip'],['Target source','target_source'],
      ['Mapping action','action'],['Mapping changes','changes'],['Config','generation'],['Config time','generation_at'],['Trigger','trigger'],['Download','delivery'],
      ['Verification','verification'],['Outcome','outcome'],['Error / next step','error'],['Attempt time','attempt_at'],
      ['Trigger time','trigger_at'],['Download time','delivery_at'],['Verification time','verification_at']];
    var lines = [columns.map(function (column) { return csvCell(column[0]); }).join(',')];
    currentBatchReport.items.forEach(function (row) {
      lines.push(columns.map(function (column) {
        var value = row[column[1]];
        if (Array.isArray(value)) value = value.join('; ');
        return csvCell(value);
      }).join(','));
    });
    var blob = new Blob(['\uFEFF' + lines.join('\r\n')], {type:'text/csv;charset=utf-8;'});
    var link = document.createElement('a');
    link.href = URL.createObjectURL(blob);
    link.download = 'provisioner-batch-' + currentBatchReport.batch.id + '.csv';
    link.click(); setTimeout(function () { URL.revokeObjectURL(link.href); }, 200);
  }
  function loadBatchHistory() {
    if (historyTableReady) { $('#batchHistoryTable').bootstrapTable('refresh', {silent: true}); return; }
    historyTableReady = true;
    $('#batchHistoryTable').bootstrapTable({
      toolbar: '#batchHistoryToolbar', pagination: true, search: true, sidePagination: 'server',
      cardView: compactReportView(),
      pageSize: 10, pageList: [10, 25, 50, 100], sortName: 'created_at', sortOrder: 'desc', uniqueId: 'id',
      formatSearch: function () { return 'Search saved reports'; },
      formatLoadingMessage: function () { return 'Loading reports…'; },
      formatNoMatches: function () {
        if (historyHasError) return 'Reports could not be loaded. Use Try Again to refresh.';
        var options = $('#batchHistoryTable').bootstrapTable('getOptions') || {};
        return options.searchText ? 'No reports match your search.' : 'No reports yet. Your next import or push will appear here.';
      },
      ajax: function (params) {
        var request = ++historyRequestGeneration;
        setReportMessage('batchHistory', 'Loading reports…', false);
        ajax('getBatchHistory', params.data, function (res) {
          if (request !== historyRequestGeneration) return;
          if (!res || !res.success) {
            historyHasError = true;
            setReportMessage('batchHistory', res && res.error ? res.error : 'Could not load reports.', true);
            params.error({status: 0});
            return;
          }
          historyHasError = false;
          setReportMessage('batchHistory', '', false);
          var total = res.total_all == null ? res.total : res.total_all;
          $('#batchHistorySummary').html(reportSummaryItem(total, 'Saved ' + pluralWord(total, 'report')));
          params.success({total: res.total, rows: res.batches});
          if (res.total && Number(res.offset) !== Number(params.data.offset)) {
            $('#batchHistoryTable').bootstrapTable('selectPage', Math.floor(res.offset / params.data.limit) + 1);
          }
        });
      },
      columns: [
        {field: 'created_at', title: 'Date', sortable: true, formatter: function (value, row) {
          return '<span title="Batch ' + diagnosticsEsc(row.id) + '">' + diagnosticsEsc(readableBatchDate(value)) + '</span>';
        }},
        {field: 'kind', title: 'Type', sortable: true, formatter: function (value) { return value === 'import' ? 'Import' : 'Push'; }},
        {field: 'device_count', title: 'Phones', sortable: true, formatter: function (value, row) {
          return value == null ? Object.keys(row.counts || {}).reduce(function (sum, key) { return sum + (Number(row.counts[key]) || 0); }, 0) : Number(value);
        }},
        {field: 'status', title: 'Result', sortable: true, formatter: function (value, row) {
          var counts = Object.keys(row.counts || {}).map(function (key) { return readableOutcomeCount(key) + ': ' + row.counts[key]; }).join(' · ');
          return '<span class="bulk-status ' + batchStatusClass(value) + '">' + diagnosticsEsc(readableBatchStatus(value))
            + '</span>' + (counts ? '<small class="bulk-report-meta">' + diagnosticsEsc(counts) + '</small>' : '');
        }},
        {field: 'actions', title: 'Actions', searchable: false, class: 'bulk-report-open', formatter: function () {
          return '<button type="button" class="btn btn-default batch-report-link"><i class="fa fa-file-text-o"></i> View Report</button>';
        }, events: {'click .batch-report-link': function (event, value, row) { event.preventDefault(); showBatchReport(row.id, false); }}}
      ]
    });
    $('#batchHistoryView .search input').attr('aria-label', 'Search saved batch reports');
  }

  function stopBatchReportPolling() {
    reportPollGeneration++;
    reportRequestPending = false;
    if (reportPollTimer !== null) window.clearTimeout(reportPollTimer);
    reportPollTimer = null;
  }

  function showBatchHistory() {
    stopBatchReportPolling();
    currentReportId = '';
    currentBatchReport = null;
    updateReportRetryButton();
    $('#batchReportResults').hide();
    $('#batchHistoryView').show();
    loadBatchHistory();
  }

  function showBatchReport(id, navigate) {
    stopBatchReportPolling();
    currentReportId = id;
    var generation = reportPollGeneration;
    var differentReport = reportDisplayedId !== id;
    if (differentReport) {
      currentBatchReport = null;
      reportFilterActive = 'all';
      expandedReportMacs = {};
      $('#batchReportFilter').val('all');
      $('#batchReportContent').hide();
    }
    $('#batchHistoryView').hide();
    $('#batchReportResults').show().attr('aria-busy', 'true');
    $('#downloadBatchCsvBtn').prop('disabled', true);
    updateReportRetryButton();
    setReportMessage('batchReport', 'Loading report…', false);
    if (navigate) {
      $('#applyModal').modal('hide');
      $('a[href="#import"]').tab('show');
      activateBulkSection('#bulkReportsSection');
    }
    function refresh() {
      reportRequestPending = true;
      ajax('getBatchReport', {batch_id: id}, function (res) {
        if (generation !== reportPollGeneration || currentReportId !== id) return;
        reportRequestPending = false;
        $('#batchReportResults').attr('aria-busy', 'false');
        if (!res || !res.success) {
          reportHasError = true;
          setReportMessage('batchReport', res && res.error ? res.error : 'Could not load report. Refresh to try again.', true);
          updateReportRetryButton();
          return;
        }
        reportHasError = false;
        setReportMessage('batchReport', '', false);
        currentBatchReport = res;
        var counts = {completed:0, progress:0, attention:0};
        var rows = res.items.map(function (row) {
          counts[reportOutcomeGroup(row.outcome)]++;
          return Object.assign({}, row, {
            report_kind: res.batch.kind,
            report_ip: row.target_ip || row.fetch_ip || row.verify_ip || '',
            outcome_label: readableOutcomeCount(row.outcome),
            report_search: [row.extension, row.mac, row.brand, row.target_ip, row.fetch_ip, row.verify_ip,
              row.outcome, readableOutcomeCount(row.outcome), row.error, (row.changes || []).join(' ')].filter(Boolean).join(' ').toLowerCase()
          });
        });
        var reportKind = res.batch.kind === 'import' ? 'Import' : 'Push';
        $('#batchReportTitle').text(reportKind + ' Report');
        $('#batchReportMeta').html(diagnosticsEsc(readableBatchDate(res.batch.created_at)) + ' · <code>' + diagnosticsEsc(id) + '</code>');
        $('#batchReportStatus').text(readableBatchStatus(res.batch.status)).attr('class', 'bulk-status ' + batchStatusClass(res.batch.status));
        $('#batchReportSummary').html(reportSummaryItem(rows.length, pluralWord(rows.length, 'Phone'))
          + reportSummaryItem(counts.completed, res.batch.kind === 'import' ? 'Mappings saved' : 'Verified')
          + reportSummaryItem(counts.progress, 'In progress') + reportSummaryItem(counts.attention, 'Needs attention'));
        var links = '';
        if (res.batch.parent_id) links += '<div class="bulk-report-control-group">Retry of <button type="button" class="btn btn-default batch-parent-link" data-id="' + diagnosticsEsc(res.batch.parent_id) + '"><i class="fa fa-file-text-o"></i> View Original Report</button></div>';
        if (res.children && res.children.length) links += '<div class="bulk-report-control-group">' + res.children.map(function (child) {
          return '<button type="button" class="btn btn-default batch-child-link" data-id="' + diagnosticsEsc(child.id) + '"><i class="fa fa-file-text-o"></i> View Retry · ' + diagnosticsEsc(readableBatchDate(child.created_at)) + ' · ' + diagnosticsEsc(readableBatchStatus(child.status)) + '</button>';
        }).join('') + '</div>';
        var linksHtml = links ? '<details class="bulk-report-related"><summary>Related Reports</summary>' + links + '</details>' : '';
        if ($('#batchReportLinks').data('content') !== linksHtml) $('#batchReportLinks').html(linksHtml).data('content', linksHtml);
        $('#batchReportLinks').toggle(!!links);
        $('#batchReportContent').show();
        if (differentReport && reportTableReady) {
          $('#batchReportTable').bootstrapTable('resetSearch', '');
          $('#batchReportTable').bootstrapTable('selectPage', 1);
        }
        initReportPhoneTable(rows);
        reportDisplayedId = id;
        differentReport = false;
        $('#downloadBatchCsvBtn').prop('disabled', false);
        $('.batch-parent-link').off('click').on('click', function () { showBatchReport(String($(this).data('id')), false); });
        $('.batch-child-link').off('click').on('click', function () { showBatchReport(String($(this).data('id')), false); });
        updateReportRetryButton();
        if (res.active) reportPollTimer = setTimeout(function () {
          reportPollTimer = null;
          if (generation === reportPollGeneration && $('#bulkReportsSection').is(':visible')) refresh();
        }, 2000);
      });
    }
    refresh();
  }
  $('#batchHistoryTab').on('click', showBatchHistory);
  $('#refreshBatchHistoryBtn, #reloadBatchHistoryBtn').on('click', loadBatchHistory);
  $('#backToBatchHistoryBtn').on('click', showBatchHistory);
  $('#refreshBatchReportBtn, #reloadBatchReportBtn').on('click', function () { if (currentReportId) showBatchReport(currentReportId, false); });
  $('#downloadBatchCsvBtn').on('click', downloadCurrentBatchCsv);
  $('#batchReportFilter').on('change', function () {
    reportFilterActive = $(this).val();
    if (reportTableReady) {
      $('#batchReportTable').bootstrapTable('filterBy', {});
      $('#batchReportTable').bootstrapTable('selectPage', 1);
    }
  });
  $('#retryBatchBtn').on('click', function () {
    if (batchRetryBusy || reportHasError || !currentBatchReport || currentBatchReport.active || !(currentBatchReport.retry_macs || []).length || currentBatchReport.batch.id !== currentReportId) return;
    var id = currentReportId;
    showConfirm('Retry Eligible Phones', 'Retry all eligible phones in this batch using their current mappings and fresh target checks? Search and filters do not limit this action. Required extension-swap partners will be included.', function () {
      if (batchRetryBusy || currentReportId !== id || reportHasError || !currentBatchReport || currentBatchReport.active || !(currentBatchReport.retry_macs || []).length) return;
      batchRetryBusy = true;
      updateReportRetryButton();
      ajax('retryBatch', {batch_id: id}, function (res) {
        batchRetryBusy = false;
        if (res && res.batch_id) {
          latestBatchId = res.batch_id;
          if (currentReportId === id) showBatchReport(res.batch_id, false);
        } else if (currentReportId === id) {
          reportHasError = true;
          setReportMessage('batchReport', (res && res.error ? res.error + ' ' : 'Retry request failed. ') + 'Refresh the report to check its result before retrying.', true);
        }
        updateReportRetryButton();
        loadPendingCount();
        $('#phoneStatusTable, #devicesTable').bootstrapTable('refresh');
      });
    });
  });
  $('#openLatestBatchBtn').on('click', function () { if (latestBatchId) showBatchReport(latestBatchId, true); });
  $(window).on('resize.provisionerReports', function () {
    var compact = compactReportView();
    [['#batchHistoryTable', historyTableReady], ['#batchReportTable', reportTableReady]].forEach(function (entry) {
      if (entry[1] && $(entry[0]).bootstrapTable('getOptions').cardView !== compact) $(entry[0]).bootstrapTable('toggleView');
    });
  });

  // =====================
  // EXPORT CSV
  // =====================
  $('#exportUsePjsipIp').off('change').on('change', function () {
    $('#exportPjsipWarning').toggle($(this).is(':checked'));
  });

  $('#exportCsvBtn').off('click').on('click', function () {
    var usePjsipIp = $('#exportUsePjsipIp').is(':checked');
    ajax('exportCsv', { use_pjsip_ip: usePjsipIp ? 1 : 0 }, function (res) {
      if (res && res.success && res.csv) {
        var blob = new Blob([res.csv], { type: 'text/csv;charset=utf-8;' });
        var link = document.createElement('a');
        link.href = URL.createObjectURL(blob);
        link.download = res.filename || 'provisioner_devices.csv';
        link.click();
        setTimeout(function () { URL.revokeObjectURL(link.href); }, 200);
        if (usePjsipIp) showAlert('success', 'CSV exported using ' + (res.pjsip_used || 0) + ' available PJSIP contact IP(s).');
      } else {
        showAlert('danger', 'Export failed.');
      }
    });
  });

  // =====================
  // PUSH CONFIG MODAL
  // =====================
  var verificationWatchTimer = null;
  var verificationWatchMacs = {};
  var verificationWatchUntil = 0;
  var verificationWatchSnapshot = '';

  function watchBackgroundVerification(macs) {
    if (liveUpdates.connected()) return;
    macs.forEach(function (mac) { verificationWatchMacs[mac] = true; });
    verificationWatchUntil = Date.now() + 150000;
    if (verificationWatchTimer !== null) return;
    function poll() {
      var requestedMacs = Object.keys(verificationWatchMacs);
      ajax('getPushProgress', { macs: requestedMacs }, function (res) {
        var pending = true;
        if (res && res.success) {
          var snapshot = JSON.stringify(res.devices || []);
          if (snapshot !== verificationWatchSnapshot) {
            verificationWatchSnapshot = snapshot;
            if (!liveUpdates.connected()) $('#phoneStatusTable').bootstrapTable('refresh');
            $('#devicesTable').bootstrapTable('refresh');
            loadPendingCount();
          }
          pending = (res.devices || []).some(function (device) {
            return device.fetch_status === 'pending' || device.verify_status === 'pending' || device.verify_status === 'verifying';
          }) || Object.keys(verificationWatchMacs).some(function (mac) { return requestedMacs.indexOf(mac) === -1; });
        }
        if (pending && Date.now() < verificationWatchUntil) {
          verificationWatchTimer = setTimeout(poll, 3000);
        } else {
          verificationWatchTimer = null;
          verificationWatchMacs = {};
          verificationWatchSnapshot = '';
        }
      }, 8000);
    }
    verificationWatchTimer = setTimeout(poll, 1500);
  }

  var selectedPushMacs = [];
  var selectedPushAction = 'push';

  $('#applyModal').on('hidden.bs.modal.provisionerSelection', function () {
    if (!applying) selectedPushMacs = [];
  });

  function openApplyModal(action) {
    if (action) selectedPushAction = action;
    var actionMode = selectedPushAction || 'push';
    var selCount = selectedPushMacs.length;
    var count = parseInt($('#pendingBadge').text()) || 0;
    $('#applyResults').hide();
    $('#applyResultsContent').empty();
    $('#applyProgress').hide();
    $('#applyProgressBar').css('width', '0%').text('').addClass('progress-bar-striped active');
    $('#applyProgressText').text('Initializing...');
    $('#applyOkBtn').removeClass('btn-primary btn-warning').addClass(actionMode === 'retry' ? 'btn-warning' : 'btn-primary')
      .html('<i class="fa ' + (actionMode === 'retry' ? 'fa-repeat' : 'fa-paper-plane') + '"></i> ' + (actionMode === 'retry' ? 'Retry Push' : 'Push Config')).prop('disabled', false).data('retry', false);

    if (selCount > 0) {
      $('#applyCountDesc').hide();
      $('#applySelectedDesc').show();
      $('#applyNoChangesDesc').hide();
      $('#applySelectedCount').text(selCount);
      $('#applySelectedUnit').text(pluralWord(selCount, 'phone'));
      $('#applySelectedActionVerb').text(actionMode === 'retry' ? 'Retry push for' : 'Push config for');
      $('#applyGenerateCfg').prop('checked', false).closest('.checkbox').hide();
      $('#applyProgress').hide();
      $('#applyOkBtn').prop('disabled', false);
      $('#applyRebootRow').hide();
      $('#applyNotifyRow').show();
      $('#applyNotifyPush').prop('checked', true);
      updatePushBtnState();
      $('#applyModal').modal('show');
      return;
    }

    if (count === 0) {
      selectedPushAction = 'pending';
      ajax('getSettings', {}, function (data) {
        var mode = (data && data.deploy_mode) || 'dhcp';
        if (mode !== 'sip_notify') return;
        $('#applyCountDesc').hide();
        $('#applySelectedDesc').hide();
        $('#applyNoChangesDesc').show();
        $('#applyCount').text(0);
        $('#applyGenerateCfg').prop('checked', false).closest('.checkbox').hide();
        $('#applyProgress').hide();
        $('#applyOkBtn').prop('disabled', false);
        $('#applyRebootRow').hide();
        $('#applyNotifyRow').show();
        $('#applyNotifyPush').prop('checked', true);
        updatePushBtnState();
        $('#applyModal').modal('show');
      });
      return;
    }

    $('#applyCountDesc').show();
    $('#applySelectedDesc').hide();
    $('#applyNoChangesDesc').hide();
    $('#applyCount').text(count);
    selectedPushAction = 'pending';
    $('#applyGenerateCfg').prop('checked', true).closest('.checkbox').show();
    $('#applyProgress').hide();
    $('#applyOkBtn').prop('disabled', false);
    ajax('getSettings', {}, function (data) {
      var mode = (data && data.deploy_mode) || 'dhcp';
      if (mode === 'sip_notify') {
        $('#applyRebootRow').hide();
        $('#applyNotifyRow').show();
        $('#applyNotifyPush').prop('checked', true);
      } else {
        $('#applyRebootRow').show();
        $('#applyRebootPhones').prop('checked', pendingNeedsReboot).prop('disabled', false);
        $('#applyNotifyRow').hide();
      }
      updatePushBtnState();
      $('#applyModal').modal('show');
    });
  }

  $('#applyChangesBtn, #applyChangesPhoneBtn').off('click').on('click', function () {
    var source = $(this).data('push-source') || ($(this).is('#applyChangesPhoneBtn') ? 'phones' : 'devices');
    var selected = source === 'phones' ? selectedPhoneRows() : selectedDeviceRows();
    var retryRows = pushRetryableRows();
    var targets = selected.length ? selected : retryRows;
    if (!targets.length) return;
    var seen = {};
    selectedPushMacs = targets.map(function (row) { return rowMac(row); }).filter(function (mac) {
      if (!mac || seen[mac]) return false;
      seen[mac] = true;
      return true;
    });
    selectedPushAction = selected.length ? 'push' : 'retry';
    if (selectedPushMacs.length) openApplyModal(selectedPushAction);
  });

  $('#applyModal').on('hidden.bs.modal', function () {
    selectedPushMacs = [];
    selectedPushAction = 'push';
    applying = false;
    $('#applyGenerateCfg').closest('.checkbox').show();
    $('#applyCountDesc').show();
    $('#applySelectedDesc').hide();
    $('#applyNoChangesDesc').hide();
    $('#applyOkBtn').removeClass('btn-warning').addClass('btn-primary').html('<i class="fa fa-paper-plane"></i> Push Config');
    $('#applyResults').hide();
    $('#applyResultsContent').empty();
    $('#applyProgress').hide();
  });

  $('#applyModal').on('hide.bs.modal', function (event) {
    if (applying) event.preventDefault();
  });

  function updatePushBtnState() {
    var gen  = $('#applyGenerateCfg').is(':checked');
    var reboot = $('#applyRebootPhones').is(':checked');
    var notify = $('#applyNotifyPush').is(':checked');
    var anyAction = gen || reboot || notify;
    if ($('#applyNotifyRow').is(':visible')) {
      anyAction = gen || notify;
    }
    $('#applyOkBtn').prop('disabled', !anyAction);
  }

  $('#applyGenerateCfg, #applyRebootPhones, #applyNotifyPush').off('change').on('change', function () {
    updatePushBtnState();
  });

  function updateProgress(pct, text) {
    if (pct === null) {
      $('#applyProgressBar').css('width', '100%').text('Working...').addClass('progress-bar-striped active');
    } else {
      $('#applyProgressBar').css('width', pct + '%').text(pct + '%');
      if (pct >= 100) $('#applyProgressBar').removeClass('active');
    }
    $('#applyProgressText').text(text);
  }

  function runPushRequest(command, data, callback) {
    data = data || {};
    var requestedMacs = data.macs || [];
    var initialText = $('#applyProgressText').text();
    ajax('getPushProgress', { macs: requestedMacs }, function (baseline) {
      var before = {};
      var devices = baseline && baseline.success ? baseline.devices || [] : [];
      if (command === 'applyChangesWithNotify') {
        var pending = devices.filter(function (device) { return Number(device.needs_changes) === 1; });
        if (pending.length) devices = pending;
      }
      devices.forEach(function (device) { before[device.mac] = device.attempt; });
      var macs = devices.map(function (device) { return device.mac; });
      var total = macs.length;
      var started = Date.now();
      var finished = false;
      var timer;

      function pollProgress() {
        if (finished) return;
        ajax('getPushProgress', { macs: macs }, function (progress) {
          if (finished) return;
          var elapsed = Math.floor((Date.now() - started) / 1000);
          var text = initialText;
          if (progress && progress.success) {
            // Only show states belonging to this push, never a previous
            // verified result from before the request was sent.
            var current = (progress.devices || []).filter(function (device) {
              return Object.prototype.hasOwnProperty.call(before, device.mac) && device.attempt !== before[device.mac];
            });
            var accepted = current.filter(function (device) {
              return ['triggered', 'downloaded', 'verified'].indexOf(device.status) !== -1;
            }).length;
            var downloaded = current.filter(function (device) { return device.fetch_status === 'downloaded'; }).length;
            if (downloaded) {
              text = 'Config downloads confirmed: ' + downloaded + '/' + total + '. Waiting for remaining downloads...';
            } else if (accepted) {
              text = 'SIP triggers accepted: ' + accepted + '/' + total + '. Waiting for config downloads...';
            }
            text += ' Download window: up to ' + progress.download_timeout + ' seconds.';
          }
          updateProgress(null, text);
          $('#applyProgressBar').text(elapsed + 's');
          timer = setTimeout(pollProgress, 1500);
        }, 8000);
      }

      ajax(command, data, function (res) {
        finished = true;
        clearTimeout(timer);
        if (res && res.batch_id) {
          latestBatchId = res.batch_id;
          $('#openLatestBatchBtn').show();
        }
        callback(res);
      });
      timer = setTimeout(pollProgress, 1500);
    }, 8000);
  }

  function showInlineResults(res) {
    var sent = res.notify_sent || res.sent || [];
    var downloaded = res.notify_downloaded || res.downloaded || [];
    var verified = res.notify_verified || res.verified || [];
    var failed = res.notify_failed || res.failed || [];
    var incomplete = failed.filter(function (phone) { return phone.status === 'triggered' || phone.status === 'downloaded'; });
    var notifyFailed = failed.filter(function (phone) { return phone.status !== 'triggered' && phone.status !== 'downloaded'; });
    var skipped = (res.notify_skipped || res.skipped || []).filter(function (mac) {
      return !failed.some(function (phone) { return phone.mac === mac; });
    });
    var generated = res.generated || [];
    if (res.verification_queued) watchBackgroundVerification(downloaded.map(function (phone) { return phone.mac; }));
    var html = '';
    if (verified.length > 0) {
      html += '<div class="alert alert-success" style="margin-bottom:8px;padding:8px 12px"><strong>Push verified</strong> — ' + verified.length + ' phone(s)</div>';
    }
    if (downloaded.length > verified.length) {
      html += '<div class="alert alert-success" style="margin-bottom:8px;padding:8px 12px"><strong>Config downloaded</strong> — '
        + (downloaded.length - verified.length) + ' phone(s). '
        + (res.verification_queued ? 'Verifying in background. Follow progress in Phone Status.' : 'Check final registration in Phone Status or use Diagnostics → Verify Last Push.') + '</div>';
    }
    if (sent.length > downloaded.length && incomplete.length === 0) {
      html += '<div class="alert alert-warning" style="margin-bottom:8px;padding:8px 12px"><strong>Trigger accepted</strong> — ' + (sent.length - downloaded.length) + ' phone(s) still need a confirmed config download</div>';
    }
    if (incomplete.length > 0) {
      var incompleteReasons = Array.from(new Set(incomplete.map(function (phone) { return phone.error || 'Final phone state is not verified yet'; })));
      html += '<div class="alert alert-warning" style="margin-bottom:8px;padding:8px 12px"><strong>Delivery incomplete</strong> — '
        + incomplete.length + ' phone(s): ' + diagnosticsEsc(incompleteReasons.join('; '));
      if (downloaded.length > verified.length) html += '<br>Use Diagnostics → Verify Last Push to check again after the phone reconnects.';
      html += '</div>';
    }
    if (notifyFailed.length > 0) {
      var errMap = {};
      notifyFailed.forEach(function(f){ var e = f.error || 'unknown'; errMap[e] = (errMap[e] || 0) + 1; });
      var parts = Object.keys(errMap).map(function(e){ return errMap[e] > 1 ? e + ' (' + errMap[e] + ')' : e; });
      html += '<div class="alert alert-danger" style="margin-bottom:8px;padding:8px 12px"><strong>Push failed</strong> — ' + notifyFailed.length + ' phone(s): ' + diagnosticsEsc(parts.join(', ')) + '</div>';
    }
    if (skipped.length > 0) {
      html += '<div class="alert alert-warning" style="margin-bottom:8px;padding:8px 12px"><strong>NOTIFY skipped</strong> — ' + skipped.length + ' phone(s): no valid private IP candidate</div>';
    }
    if (generated.length > 0) {
      html += '<p class="text-muted" style="margin-bottom:4px">Configs generated for ' + generated.length + ' device(s).</p>';
    }
    if (!html) html = '<p class="text-muted">No phones to notify.</p>';
    $('#applyResultsContent').html(html);
    $('#applyResults').show();
    if (failed.length > 0 || skipped.length > 0) {
      lastFailedMacs = Array.from(new Set(failed.filter(function (phone) { return phone.status !== 'downloaded'; })
        .map(function(f){ return f.mac; }).filter(function(m){ return !!m; }).concat(skipped)));
      if (lastFailedMacs.length > 0) {
        $('#applyOkBtn').prop('disabled', false).text('Retry (' + lastFailedMacs.length + ')').data('retry', true);
      } else {
        $('#applyOkBtn').prop('disabled', false).text('Close').data('retry', false);
      }
    } else {
      $('#applyOkBtn').prop('disabled', false).text('Close').data('retry', false);
    }
  }

  var applying = false;
  var lastFailedMacs = [];
  $('#applyOkBtn').off('click').on('click', function () {
    if ($(this).text() === 'Close') {
      $('#applyModal').modal('hide');
      return;
    }
    if ($('#applyOkBtn').data('retry')) {
      if (applying) return;
      applying = true;
      $('#applyOkBtn').prop('disabled', true);
      $('#applyProgress').show();
      updateProgress(null, 'Retrying ' + lastFailedMacs.length + ' failed phone(s)...');
      runPushRequest('pushToPhones', { macs: lastFailedMacs }, function (res) {
        applying = false;
        $('#applyProgress').hide();
        if (res && res.success) {
          if (!$('#applyModal').is(':visible')) return;
          showInlineResults(res);
        } else {
          if (!$('#applyModal').is(':visible')) return;
          showInlineResults({ failed: lastFailedMacs.map(function(m){ return {mac: m, error: res ? res.error : 'Request failed'}; }) });
        }
        $('#devicesTable').bootstrapTable('refresh');
        $('#phoneStatusTable').bootstrapTable('refresh');
        loadPendingCount();
      });
      return;
    }
    if (applying) return;
    applying = true;
    $('#applyOkBtn').prop('disabled', true);
    $('#applyResults').hide();
    $('#applyProgress').show();
    var isSipNotify = $('#applyNotifyPush').length > 0 && $('#applyNotifyRow').is(':visible') && $('#applyNotifyPush').is(':checked');
    if (isSipNotify) {
      if (selectedPushMacs.length > 0) {
        var selectedCommand = selectedPushAction === 'retry' ? 'retryPushForPhones' : 'pushToPhones';
        updateProgress(null, selectedPushAction === 'retry'
          ? 'Retrying push for phones with delivery issues...'
          : 'Preparing configs and pushing selected phones (swap partners are included)...');
        runPushRequest(selectedCommand, { macs: selectedPushMacs }, function (res) {
          applying = false;
          selectedPushMacs = [];
          $('#applyProgress').hide();
          if (res && res.success) {
            if (!$('#applyModal').is(':visible')) return;
            showInlineResults(res);
          } else {
            if (!$('#applyModal').is(':visible')) return;
            showInlineResults({ failed: [{ error: res ? res.error : 'Request failed' }] });
          }
          $('#devicesTable').bootstrapTable('refresh');
          $('#phoneStatusTable').bootstrapTable('refresh');
          loadPendingCount();
        });
      } else {
        updateProgress(null, 'Generating configs and notifying pending phones...');
        runPushRequest('applyChangesWithNotify', {}, function (res) {
          applying = false;
          selectedPushMacs = [];
          $('#applyProgress').hide();
          if (!res || !res.success) {
            if (!$('#applyModal').is(':visible')) return;
            showInlineResults({ failed: [{ error: res ? res.error : 'Request failed' }] });
            $('#devicesTable').bootstrapTable('refresh');
            $('#phoneStatusTable').bootstrapTable('refresh');
            loadPendingCount();
            return;
          }
          if (!$('#applyModal').is(':visible')) return;
          showInlineResults(res);
          $('#devicesTable').bootstrapTable('refresh');
          $('#phoneStatusTable').bootstrapTable('refresh');
          loadPendingCount();
        });
      }
    } else {
      var generate = $('#applyGenerateCfg').is(':checked');
      var reboot = $('#applyRebootPhones').is(':checked');
      updateProgress(null, generate ? 'Generating configs and applying pending changes...' : 'Applying pending changes...');
      ajax('applyChanges', { generate: generate, reboot: reboot }, function (res) {
        applying = false;
        $('#applyProgress').hide();
        $('#applyOkBtn').prop('disabled', false);
        if (res && res.success) {
          var msg = 'Push completed.';
          if (res.generated && res.generated.length > 0) msg += ' Generated ' + res.generated.length + ' config(s).';
          if (res.reboots && res.reboots.length > 0) msg += ' Reboot sent to ' + res.reboots.length + ' phone(s).';
          if (res.skipped_reboot > 0) msg += ' Skipped reboot for ' + res.skipped_reboot + ' new device(s).';
          showAlert('success', msg);
          $('#applyModal').modal('hide');
          $('#devicesTable').bootstrapTable('refresh');
          $('#phoneStatusTable').bootstrapTable('refresh');
          loadPendingCount();
        } else {
          showAlert('danger', 'Push failed: ' + (res ? res.error : 'Unknown error'));
        }
      });
    }
  });

  // =====================
  // PROVISIONING INFO
  // =====================
  function provisioningCheckLoading(label) {
    return '<div class="alert alert-info" style="margin-top:10px;padding:8px 12px;min-height:38px;">' +
      '<i class="fa fa-spinner fa-spin"></i> <strong>' + label + '</strong></div>';
  }

  var provisioningLoadSequence = 0;
  function loadProvisioningInfo() {
    var loadToken = ++provisioningLoadSequence;
    $('#provInfoBody').html(
      '<div class="text-center text-muted" style="padding:30px 10px;">' +
      '<i class="fa fa-spinner fa-spin fa-2x"></i><p style="margin-top:10px;">Loading provisioning status...</p></div>'
    );
    ajax('getProvisioningInfo', {}, function (data) {
      if (loadToken !== provisioningLoadSequence) return;
      var html = '';
      if (!data || data.error) {
        html = '<div class="alert alert-danger"><strong>Unable to load provisioning status.</strong> ' + diagnosticsEsc((data && data.error) || 'Request failed') +
          ' <button type="button" class="btn btn-xs btn-default" id="retryProvisioningInfoBtn"><i class="fa fa-repeat"></i> Retry</button></div>';
        $('#provInfoBody').html(html);
        $('#retryProvisioningInfoBtn').off('click').on('click', loadProvisioningInfo);
        return;
      }
      ajax('getSettings', {}, function (settings) {
        if (loadToken !== provisioningLoadSequence) return;
        if (!settings || settings.error) {
          $('#provInfoBody').html(
            '<div class="alert alert-danger"><strong>Unable to load provisioning settings.</strong> ' + diagnosticsEsc((settings && settings.error) || 'Request failed') +
            ' <button type="button" class="btn btn-xs btn-default" id="retryProvisioningSettingsBtn"><i class="fa fa-repeat"></i> Retry</button></div>'
          );
          $('#retryProvisioningSettingsBtn').off('click').on('click', loadProvisioningInfo);
          return;
        }
        var mode = (settings && settings.deploy_mode) || 'dhcp';
        var showOption66 = (mode === 'dhcp');
        html += '<dl class="dl-horizontal">';
        html += '  <dt>PBX IP</dt><dd>' + data.pbx_ip + '</dd>';
        html += '  <dt>Provisioning URL</dt><dd><code>http://' + data.pbx_ip + ':2001/provisioning/</code>';
        if (showOption66) {
          html += ' <button class="btn btn-xs btn-default" onclick="copyProvUrl(\'http://' + data.pbx_ip + ':2001/provisioning/\')"><i class="fa fa-copy"></i> Copy</button>';
        }
        html += '</dd>';
        html += '  <div id="dhcpOption66Status">' + (showOption66 ? provisioningCheckLoading('Checking DHCP Option 66...') : '<div class="alert alert-info" style="margin-top:10px;padding:8px 12px;"><i class="fa fa-info-circle"></i> <strong>DHCP Option 66:</strong> Not applicable in SIP NOTIFY mode.</div>') + '</div>';
        html += '  <div id="firewallProvisioningStatus">' + provisioningCheckLoading('Checking FreePBX firewall zones...') + '</div>';
        html += '  <dt>Directory</dt><dd><code>' + data.prov_dir + '</code></dd>';
        var writableIcon = data.dir_writable === true ? '<span class="label label-success">Writable</span>' : (data.dir_writable === false ? '<span class="label label-danger">Not writable</span>' : '<span class="label label-warning">Missing</span>');
        html += '  <dt>Status</dt><dd>' + writableIcon + '</dd>';
        html += '  <dt>Device Configs</dt>';
        html += '  <dd><span id="provDeviceCount">' + data.device_count + '</span> file(s) &mdash; <a href="#" onclick="$(\'a[href=\\\'#configfiles\\\']\').tab(\'show\');return false;">View in Config Files</a></dd>';
        html += '</dl>';
        html += '<button class="btn btn-primary" id="generateAllConfigsBtn" style="margin-right:5px;"><i class="fa fa-cogs"></i> Generate All Configs</button>';
        html += '<button class="btn btn-danger" id="fixPermsBtn"><i class="fa fa-wrench"></i> Fix Permissions</button>';
        if (data.model_files && data.model_files.length > 0) {
          html += '<h5 style="margin-top:16px;">Model Files</h5>';
          html += '<table class="table table-condensed"><thead><tr><th>File</th><th>Size</th><th>Modified</th></tr></thead><tbody>';
          $.each(data.model_files, function (i, f) {
            html += '<tr><td><code>' + f.name + '</code></td><td>' + f.size + ' B</td><td>' + f.modified + '</td></tr>';
          });
          html += '</tbody></table>';
        }
        $('#provInfoBody').html(html);
        if (showOption66) {
         ajax('checkDhcpOption66', {}, function (res) {
        if (loadToken !== provisioningLoadSequence) return;
        var statusDiv = $('#dhcpOption66Status');
        if (!res || res.error) {
          statusDiv.html(
            '<div class="alert alert-danger" style="margin-top:10px;padding:8px 12px;">' +
            '<i class="fa fa-times-circle"></i> <strong>DHCP Option 66 check failed:</strong> ' + diagnosticsEsc((res && res.error) || 'Request failed') +
            ' <button type="button" class="btn btn-xs btn-default" id="retryDhcpCheckBtn"><i class="fa fa-repeat"></i> Retry</button></div>'
          );
          $('#retryDhcpCheckBtn').off('click').on('click', loadProvisioningInfo);
        } else if (res.set && res.correct) {
          statusDiv.html(
            '<div class="alert alert-success" style="margin-top:10px;padding:8px 12px;">' +
            '<i class="fa fa-check-circle"></i> ' +
            '<strong>DHCP Option 66 configured correctly:</strong> <code>' + res.value + '</code>' +
            '</div>'
          );
        } else if (res && res.set && !res.correct) {
          statusDiv.html(
            '<div class="alert alert-warning" style="margin-top:10px;padding:8px 12px;">' +
            '<i class="fa fa-exclamation-triangle"></i> ' +
            '<strong>Option 66 misconfigured:</strong> set to <code>' + res.value + '</code>, ' +
            'expected <code>' + res.expected + '</code>. Update in ' +
            '<a href="config.php?display=sysadmin&view=dhcpd" target="_blank">System Admin &rarr; DHCP Server</a>' +
            '</div>'
          );
        } else {
          statusDiv.html(
            '<div class="alert alert-warning" style="margin-top:10px;padding:8px 12px;">' +
            '<i class="fa fa-exclamation-triangle"></i> ' +
            '<strong>Requirement:</strong> DHCP Option 66 (next-server) must point to the provisioning URL above. Configure in ' +
            '<a href="config.php?display=sysadmin&view=dhcpd" target="_blank">System Admin &rarr; DHCP Server</a> ' +
            'for phones to auto-discover and download their config.' +
            '</div>'
          );
        }
      });
      }
      ajax('checkFirewallProvisioning', {}, function (res) {
          if (loadToken !== provisioningLoadSequence) return;
          var fwDiv = $('#firewallProvisioningStatus');
          if (!res || res.error || res.success === false) {
            fwDiv.html(
              '<div class="alert alert-danger" style="margin-top:10px;padding:8px 12px;">' +
              '<i class="fa fa-times-circle"></i> <strong>Firewall check failed:</strong> ' + diagnosticsEsc((res && res.error) || 'Request failed') +
              ' <button type="button" class="btn btn-xs btn-default" id="retryFirewallCheckBtn"><i class="fa fa-repeat"></i> Retry</button></div>'
            );
            $('#retryFirewallCheckBtn').off('click').on('click', loadProvisioningInfo);
          } else if (!res.blocked) {
            fwDiv.html(
              '<div class="alert alert-success" style="margin-top:10px;padding:8px 12px;">' +
              '<i class="fa fa-check-circle"></i> ' +
              '<strong>Firewall:</strong> ' + res.message +
              '</div>'
            );
          } else if (res && res.blocked) {
            fwDiv.html(
              '<div class="alert alert-warning" style="margin-top:10px;padding:8px 12px;">' +
              '<i class="fa fa-exclamation-triangle"></i> ' +
              '<strong>Firewall blocks provisioning:</strong> ' + res.message +
              '</div>'
            );
          } else {
            fwDiv.html(
              '<div class="alert alert-info" style="margin-top:10px;padding:8px 12px;">' +
              '<i class="fa fa-info-circle"></i> ' +
              '<strong>Firewall:</strong> Unable to determine provisioning status.' +
              '</div>'
            );
          }
        });
      function generateAllConfigs(replaceCustom) {
        var btn = $('#generateAllConfigsBtn');
        btn.prop('disabled', true).html('<i class="fa fa-spinner fa-spin"></i> Generating...');
        ajax('generateAllConfigs', { replace_custom: replaceCustom ? 1 : 0 }, function (res) {
          btn.prop('disabled', false).html('<i class="fa fa-cogs"></i> Generate All Configs');
          if (res && res.requires_confirmation) {
            showConfirm(
              'Replace Pulled Configs',
              'Generate All will archive and replace <strong>' + res.custom_count + '</strong> pulled custom config(s) with Provisioner templates. Continue?',
              function () { generateAllConfigs(true); }
            );
            return;
          }
          if (res && res.success) {
            var message = 'Generated ' + res.generated + ' config file(s).';
            if (res.custom_replaced > 0) message += ' Archived and replaced ' + res.custom_replaced + ' custom config(s).';
            showAlert('success', message);
            loadProvisioningInfo();
            $('#configFilesTable').bootstrapTable('refresh');
          } else {
            showAlert('danger', 'Generation failed: ' + (res ? res.error : 'Unknown error'));
          }
        });
      }
      $('#generateAllConfigsBtn').off('click').on('click', function () {
        generateAllConfigs(false);
      });
      $('#fixPermsBtn').off('click').on('click', function () {
        ajax('generateProvisioning', {}, function (res) {
          if (res && res.success) showAlert('success', 'Permissions fixed.');
          else showAlert('danger', 'Failed: ' + (res ? res.output : 'Unknown error'));
        });
      });
    });
  });
  }

  // =====================
  // CONFIG FILES — DELETE ALL
  // =====================
  $('#deleteAllConfigBtn').off('click').on('click', function () {
    showConfirm('Delete All Config Files', 'Delete ALL generated config files? This cannot be undone. (MAC Mapping entries will not be deleted.)', function () {
      ajax('deleteAllConfigFiles', {}, function (res) {
        if (res && res.success) {
          showAlert('success', 'Deleted ' + res.deleted + ' config file(s).');
          $('#configFilesTable').bootstrapTable('refresh');
        } else {
          showAlert('danger', 'Error: ' + (res ? res.error : 'Unknown error'));
          }
        });
      });
  });

  // =====================
  // LOGS
  // =====================
  $('#clearLogsBtn').off('click').on('click', function () {
    showConfirm('Clear Logs', 'Are you sure you want to clear all logs?', function () {
      ajax('clearLogs', {}, function (res) {
        if (res && res.success) {
          showAlert('success', 'Logs cleared.');
          $('#logsTable').bootstrapTable('refresh');
        } else {
          showAlert('danger', 'Error clearing logs.');
        }
      });
    });
  });

  // =====================
  // SETTINGS
  // =====================
  var detectedSipTransports = {};
  var settingsInterfaces = [];
  var scanSubnetsManual = false;
  var phonebookSettings = { digium: {}, alcatel: {} };
  var phonebookActiveBrand = 'digium';

  function phonebookDefaultUrl(brand) {
    var ip = $('#settingsPbxIp').val() || '';
    return ip ? 'http://' + ip + ':2001/phonebook.php?format=' + brand : '';
  }

  function loadPhonebookFields(brand) {
    phonebookActiveBrand = brand;
    var saved = phonebookSettings[brand] || {};
    $('#settingsPhonebookName').val(saved.name || 'Internal Contact');
    $('#settingsPhonebookUrl').val(saved.url || phonebookDefaultUrl(brand));
    $('#settingsPhonebookUsername').val(saved.username || '');
    $('#settingsPhonebookPassword').val(saved.password || '');
  }

  function cachePhonebookFields(brand) {
    brand = brand || phonebookActiveBrand;
    if (!brand) return;
    phonebookSettings[brand] = {
      name: $('#settingsPhonebookName').val(),
      url: $('#settingsPhonebookUrl').val(),
      username: $('#settingsPhonebookUsername').val(),
      password: $('#settingsPhonebookPassword').val()
    };
  }

  function updateAutoDetectedScanSubnet() {
    var option = $('#settingsInterfaceSelect option:selected');
    var subnet = option.data('subnet') || '';
    $('#settingsScanSubnets').val(subnet);
    $('#settingsScanDetection')
      .removeClass('text-warning').addClass('text-muted')
      .text(subnet ? 'Auto-detected from ' + (option.data('name') || 'the selected PBX interface') + ': ' + subnet : 'Select a PBX interface to detect its scan subnet.');
  }

  function loadSettingsInterfaces(pbxIp, savedScanSubnets) {
    ajax('getNetworkSubnets', {}, function (res) {
      var select = $('#settingsInterfaceSelect');
      select.empty().append('<option value="">— Select interface —</option>');
      settingsInterfaces = (res && res.interfaces) || [];
      $.each(settingsInterfaces, function (i, iface) {
        select.append($('<option>', { value: iface.ip, text: iface.label }).attr('data-subnet', iface.subnet).attr('data-name', iface.name));
      });
      if (pbxIp && select.find('option[value="' + pbxIp + '"]').length) select.val(pbxIp);
      var detectedSubnet = select.find('option:selected').data('subnet') || '';
      scanSubnetsManual = !!savedScanSubnets && $.trim(savedScanSubnets) !== detectedSubnet;
      if (scanSubnetsManual) {
        $('#settingsScanSubnets').val(savedScanSubnets);
        $('#settingsScanDetection').removeClass('text-warning').addClass('text-muted').text('Manual scan subnet override. Click Auto-Detect to use the selected interface.');
      } else {
        updateAutoDetectedScanSubnet();
      }
    });
  }

  function updateDetectedSipPort() {
    var transport = $('#settingsTransport').val();
    var detected = detectedSipTransports[transport];
    if (detected) {
      $('#settingsSipPort').val(detected.port);
      $('#settingsSipDetection').removeClass('text-warning').addClass('text-muted').text('Detected from Asterisk runtime: ' + detected.bind_address + ':' + detected.port);
    }
  }

  function loadSipTransportInfo(preferredTransport, fallbackPort) {
    ajax('getSipTransportInfo', {}, function (res) {
      var select = $('#settingsTransport');
      if (!res || !res.success) {
        if (select.find('option[value="' + preferredTransport + '"]').length) select.val(preferredTransport);
        $('#settingsSipPort').val(fallbackPort || '5060');
        $('#settingsSipDetection').removeClass('text-muted').addClass('text-warning').text('Asterisk transport detection failed; using the saved fallback port.');
        return;
      }
      detectedSipTransports = {};
      select.empty();
      ['UDP', 'TCP'].forEach(function (protocol) {
        if (!res.transports || !res.transports[protocol]) return;
        detectedSipTransports[protocol] = res.transports[protocol];
        select.append('<option value="' + protocol + '">' + protocol + '</option>');
      });
      if (select.children().length === 0) {
        select.append('<option value="UDP">UDP</option><option value="TCP">TCP</option>');
        if (select.find('option[value="' + preferredTransport + '"]').length) select.val(preferredTransport);
        $('#settingsSipPort').val(res.fallback_port || fallbackPort || '5060');
        $('#settingsSipDetection').removeClass('text-muted').addClass('text-warning').text('No active UDP/TCP PJSIP transport detected; using the saved fallback port.');
        return;
      }
      if (detectedSipTransports[preferredTransport]) select.val(preferredTransport);
      else select.val(select.children().first().val());
      updateDetectedSipPort();
    });
  }

  function updateDhcpSettingsVisibility(mode) {
    $('#settingsDhcpLeasesRow').toggle(mode === 'dhcp');
  }

  function loadSettings() {
    ajax('getSettings', {}, function (data) {
      if (data) {
        $('#settingsPbxIp').val(data.pbx_ip || '');
        $('#settingsSipPort').val(data.sip_port || '5060');
        loadSipTransportInfo(data.transport || 'UDP', data.sip_port || '5060');
        loadSettingsInterfaces(data.pbx_ip || '', data.scan_subnets || '');
         $('#settingsDhcpLeasesFile').val(data.dhcp_leases_file || '/var/lib/misc/dnsmasq.leases');
         $('#settingsVlanId').val(data.vlan_id || '');
         $('#settingsVlanDataId').val(data.vlan_data_id || '');
         phonebookSettings.digium = {
           name: data.digium_phonebook_name || '',
           url: data.digium_phonebook_url || '',
           username: data.digium_phonebook_username || '',
           password: data.digium_phonebook_password || ''
         };
         phonebookSettings.alcatel = {
           name: data.alcatel_phonebook_name || '',
           url: data.alcatel_phonebook_url || '',
           username: data.alcatel_phonebook_username || '',
           password: data.alcatel_phonebook_password || ''
         };
         loadPhonebookFields($('#settingsPhonebookBrand').val() || 'digium');
         var mode = data.deploy_mode || 'dhcp';
        if (mode === 'sip_notify') {
          $('#modeSipNotify').prop('checked', true);
          $('#modeSipNotifyLabel').addClass('mode-active');
        } else {
          $('#modeDhcp').prop('checked', true);
          $('#modeDhcpLabel').addClass('mode-active');
        }
        updateDhcpSettingsVisibility(mode);
      }
    });
  }

  $('#settingsInterfaceSelect').off('change').on('change', function () {
    var ip = $(this).val();
    if (ip) $('#settingsPbxIp').val(ip);
    if (!scanSubnetsManual) updateAutoDetectedScanSubnet();
  });

  $('#settingsScanSubnets').off('input').on('input', function () {
    scanSubnetsManual = true;
    $('#settingsScanDetection').removeClass('text-warning').addClass('text-muted').text('Manual scan subnet override. Click Auto-Detect to use the selected interface.');
  });

  $('#detectScanSubnetBtn').off('click').on('click', function () {
    scanSubnetsManual = false;
    updateAutoDetectedScanSubnet();
  });

  $('#settingsTransport').off('change').on('change', updateDetectedSipPort);

  $('#settingsPhonebookBrand').off('change').on('change', function () {
    cachePhonebookFields(phonebookActiveBrand);
    loadPhonebookFields($(this).val());
  });

  $('#savePbxSettingsBtn').off('click').on('click', function (e) {
    e.preventDefault();
    var data = {
      pbx_ip:   $('#settingsPbxIp').val(),
      transport: $('#settingsTransport').val(),
      scan_subnets: scanSubnetsManual ? $('#settingsScanSubnets').val() : ''
    };
    if ($('input[name="deploy_mode"]:checked').val() === 'dhcp') data.dhcp_leases_file = $('#settingsDhcpLeasesFile').val();
    ajax('saveSettings', data, function (res) {
      if (res && res.success) showAlert('success', 'PBX settings saved.');
      else showAlert('danger', 'Failed to save PBX settings: ' + (res ? res.error : 'Unknown error'));
    });
  });

  $('input[name="deploy_mode"]').off('change').on('change', function () {
    var val = $(this).val();
    $('#modeDhcpLabel, #modeSipNotifyLabel').removeClass('mode-active');
    if (val === 'sip_notify') {
      $('#modeSipNotifyLabel').addClass('mode-active');
    } else {
      $('#modeDhcpLabel').addClass('mode-active');
    }
    updateDhcpSettingsVisibility(val);
    applyDiscoveryMode(val);
  });

  $('#saveVlanBtn').off('click').on('click', function (e) {
    e.preventDefault();
    var data = {
      vlan_id: $('#settingsVlanId').val(),
      vlan_data_id: $('#settingsVlanDataId').val()
    };
    ajax('saveSettings', data, function (res) {
      if (res && res.success) showAlert('success', 'VLAN settings saved.');
      else showAlert('danger', 'Failed to save VLAN settings.');
    });
  });

  $('#savePhonebookBtn').off('click').on('click', function (e) {
    e.preventDefault();
    cachePhonebookFields();
    var brand = $('#settingsPhonebookBrand').val();
    var prefix = brand === 'alcatel' ? 'alcatel_phonebook_' : 'digium_phonebook_';
    var data = {};
    data[prefix + 'name'] = phonebookSettings[brand].name;
    data[prefix + 'url'] = phonebookSettings[brand].url;
    data[prefix + 'username'] = phonebookSettings[brand].username;
    data[prefix + 'password'] = phonebookSettings[brand].password;
    ajax('saveSettings', data, function (res) {
      var label = brand === 'alcatel' ? 'Alcatel' : 'Digium';
      if (res && res.success) showAlert('success', label + ' cloud phonebook settings saved.');
      else showAlert('danger', 'Failed to save ' + label + ' phonebook settings: ' + (res ? res.error : 'Unknown error'));
    });
  });


  $('#saveDeployModeBtn').off('click').on('click', function () {
    var mode = $('input[name="deploy_mode"]:checked').val();
    if (!mode) { showAlert('warning', 'Please select a deployment mode.'); return; }
    var btn = $(this);
    btn.prop('disabled', true).html('<i class="fa fa-spinner fa-spin"></i> Saving...');
    ajax('saveSettings', { deploy_mode: mode }, function (res) {
      btn.prop('disabled', false).html('<i class="fa fa-save"></i> Save Mode');
      if (res && res.success) {
        deploymentMode = mode;
        updateImportPushButton();
        showAlert('success', 'Deployment mode set to ' + mode.replace('_', ' ') + '.');
        applyDiscoveryMode(mode);
        updateDhcpSettingsVisibility(mode);
        if (mode === 'sip_notify') {
          $('#modeSipNotifyLabel').addClass('mode-active');
        } else {
          $('#modeDhcpLabel').addClass('mode-active');
        }
        $('#deployModeSaveStatus').text('Saved!').fadeOut(2000);
      } else {
        showAlert('danger', 'Failed to save mode.');
      }
    });
  });

  // =====================
  // TEMPLATE
  // =====================
  var currentTemplateBrand = 'digium';

  function loadTemplate() {
    var brand = $('#templateBrand').val();
    currentTemplateBrand = brand;
    $('#templateContent').val('Loading...');
    ajax('getTemplateContent', { brand: brand }, function (res) {
      if (res && res.success) {
        $('#templateContent').val(res.content);
        $('#templatePass').val(res.password || '');
      } else {
        $('#templateContent').val('/* Error loading template */');
      }
    });
  }

  $('#templateBrand').off('change').on('change', function () {
    loadTemplate();
  });

  $('#saveTemplateBtn').off('click').on('click', function () {
    ajax('saveTemplateContent', {
      content: $('#templateContent').val(),
      brand: currentTemplateBrand,
      password: $('#templatePass').val()
    }, function (res) {
      if (res && res.success) showAlert('success', 'Template saved.');
      else showAlert('danger', 'Save failed: ' + (res ? res.error : 'Unknown error'));
    });
  });

  $('#resetTemplateBtn').off('click').on('click', function () {
    showConfirm('Reset Template', 'Reset the template to its default content? Any edits will be lost.', function () {
      ajax('resetTemplate', { brand: currentTemplateBrand }, function (res) {
        if (res && res.success) { showAlert('success', 'Template reset to default.'); loadTemplate(); }
        else showAlert('danger', 'Reset failed: ' + (res ? res.error : 'Unknown error'));
      });
    });
  });

  // =====================
  // ALERTS / CONFIRM
  // =====================
  window.copyProvUrl = function(url) {
    if (navigator.clipboard && navigator.clipboard.writeText) {
      navigator.clipboard.writeText(url).then(function () {
        showAlert('success', 'Provisioning URL copied to clipboard.');
      }).catch(function () {
        fallbackCopy(url);
      });
    } else {
      fallbackCopy(url);
    }
  };
  function fallbackCopy(text) {
    var ta = document.createElement('textarea');
    ta.value = text;
    ta.style.position = 'fixed';
    ta.style.opacity = '0';
    document.body.appendChild(ta);
    ta.select();
    try { document.execCommand('copy'); } catch (e) {}
    document.body.removeChild(ta);
    showAlert('success', 'Provisioning URL copied to clipboard.');
  }

  function showAlert(type, message) {
    var keys = { success: 'success', danger: 'danger', info: 'info', warning: 'warning' };
    var cls = keys[type] || 'info';
    var html = '<div class="alert alert-' + cls + ' alert-dismissible" role="alert" style="position:fixed;top:60px;right:20px;z-index:99999;max-width:500px;">'
            + '<button type="button" class="close" data-dismiss="alert">&times;</button>'
            + message + '</div>';
    var $alert = $(html).appendTo('body');
    setTimeout(function () { $alert.alert('close'); }, 7000);
  }

  function showConfirm(title, message, callback, cancelCallback, okLabel, cancelLabel) {
    var actionTone = /^(delete|clear|remove)\b/i.test(title) ? 'btn-danger' : (/^retry\b/i.test(title) ? 'btn-warning' : 'btn-primary');
    var $modal = $(
      '<div class="modal fade" tabindex="-1" role="dialog">' +
        '<div class="modal-dialog" role="document">' +
          '<div class="modal-content">' +
            '<div class="modal-header"><h4 class="modal-title">' + title + '</h4><button type="button" class="close" data-dismiss="modal">&times;</button></div>' +
            '<div class="modal-body">' + message + '</div>' +
            '<div class="modal-footer">' +
              '<button type="button" class="btn btn-default" data-dismiss="modal" id="confirmCancel">' + (cancelLabel || 'Cancel') + '</button>' +
              '<button type="button" class="btn ' + actionTone + '" id="confirmOk">' + (okLabel || 'OK') + '</button>' +
            '</div>' +
          '</div>' +
        '</div>' +
      '</div>'
    ).appendTo('#provisioner-app');
    $modal.find('#confirmOk').on('click', function () {
      $modal.modal('hide');
      if (callback) callback();
    });
    $modal.find('#confirmCancel').on('click', function () {
      if (cancelCallback) cancelCallback();
    });
    $modal.on('hidden.bs.modal', function () { $modal.remove(); });
    $modal.modal('show');
  }

  // =====================
  // TAB TRIGGERS
  // =====================
  $('a[href="#phones"]').off('shown.bs.tab').on('shown.bs.tab', function () {
    if (!liveUpdates.enabled) refreshPhoneTable();
  });
  $('a[href="#devices"]').off('shown.bs.tab').on('shown.bs.tab', function () {
    $('#devicesTable').bootstrapTable('refresh');
    loadPendingCount();
  });
  $('a[href="#import"]').on('hide.bs.tab.provisionerReports', stopBatchReportPolling);
  $('a[href="#import"]').on('shown.bs.tab.provisionerReports', function () {
    if (currentReportId && $('#bulkReportsSection').is(':visible') && !reportRequestPending && reportPollTimer === null) showBatchReport(currentReportId, false);
  });
  $('a[href="#provisioning"]').off('shown.bs.tab').on('shown.bs.tab', function () {
    loadProvisioningInfo();
  });
  $('a[href="#discovered"]').off('shown.bs.tab').on('shown.bs.tab', function () {
    ajax('getSettings', {}, function (data) {
      var mode = (data && data.deploy_mode) || 'dhcp';
      applyDiscoveryMode(mode);
    });
    if ($('#discoveredTable').is(':visible') && $('#discoveredTable').bootstrapTable('getData').length > 0) {
      refreshDiscoveredTable();
    }
    loadInterfaces(true);
  });
  $('a[href="#configfiles"]').off('shown.bs.tab').on('shown.bs.tab', function () {
    $('#configFilesTable').bootstrapTable('refresh');
  });
  $('a[href="#logs"]').off('shown.bs.tab').on('shown.bs.tab', function () {
    $('#logsTable').bootstrapTable('refresh');
  });
  $('a[href="#settings"]').off('shown.bs.tab').on('shown.bs.tab', function () {
    loadSettings();
    loadTemplate();
  });
  $('#provisionerTabs a[data-toggle="tab"]').on('shown.bs.tab.provisionerLive', function () { liveUpdates.sync(); });

  // =====================
  // DEVICE DISCOVERY
  // =====================
  onTableSelect('discoveredTable', 'id', selectedDhcpMacs, true);

  window.discoveredMacFormatter = function (value) {
    return value ? diagnosticsEsc(value) : '<span class="text-muted">Identify required</span>';
  };

  window.discoveredModelFormatter = function (value, row) {
    return value ? diagnosticsEsc(value) + (row.firmware ? '<br><small>' + diagnosticsEsc(row.firmware) + '</small>' : '') : '—';
  };

  window.discoveredSourceFormatter = function (value, row) {
    var source = String(value || '');
    var label = source.indexOf('SIP discovery fetch') !== -1 ? 'MAC identified via handset request' : source;
    var seen = row.seen_now !== false ? 'Seen in this scan' : '';
    var age = friendlyAge(row.last_seen);
    if (row.last_seen && row.seen_now === false) {
      var seenAt = new Date(String(row.last_seen).replace(' ', 'T')).getTime();
      if (isFinite(seenAt) && Date.now() - seenAt > 900000) seen = 'Identity expired';
      else if (age) seen = 'Confirmed ' + age;
    }
    var explanation = source.indexOf('SIP discovery fetch') !== -1
      ? 'A URL-only handset request identified the MAC; the isolated receiver returned HTTP 404 and served no configuration.' : '';
    return '<span' + (explanation ? ' title="' + diagnosticsEsc(explanation) + '"' : '') + '>' + diagnosticsEsc(label || 'Discovery observation') + '</span>'
      + (seen ? '<br><small class="text-muted">' + diagnosticsEsc(seen) + '</small>' : '');
  };

  window.discoveredStatusFormatter = function (value, row) {
    if (row.seen_now === false) {
      return row.status === 'added'
        ? '<span class="text-muted">Already in Mapping; Not Seen This Scan</span>'
        : '<span class="text-warning">Not Seen This Scan</span>';
    }
    if (row.identity_conflict) return '<span class="text-warning">MAC identity conflict</span>';
    if (row.status === 'added') {
      return '<span class="text-muted">Already in Mapping</span>';
    }
    if (row.identity_pending) return '<span class="text-info">Online; MAC unknown</span>';
    if (row.is_supported) return '<span class="text-success">' + (row.extension ? 'New Supported' : 'New / Unassigned') + '</span>';
    if (row.is_phone) return '<span class="text-warning">Recognized, Unsupported</span>';
    return '<span class="text-muted">Not Supported</span>';
  };

  window.discoveredActionsFormatter = function (value, row) {
    if (row.can_identify && row.seen_now !== false) {
      return '<a class="clickable identify-mac-row-btn" data-id="' + row.id + '"><i class="fa fa-search-plus"></i> Identify MAC</a>';
    }
    if (row.mac && row.is_supported && row.status === 'new' && row.seen_now !== false) {
      return '<a class="clickable add-discovered-btn" data-mac="' + row.mac + '" data-ip="' + (row.ip || '') + '" data-ext="' + (row.extension || '') + '"><i class="fa fa-plus"></i> Add</a>';
    }
    return '-';
  };

   function applyDiscoveryMode(mode) {
     discoveryMode = mode;
     if (mode === 'sip_notify') {
       $('#scanBtnLabel').text('Scan Network');
       $('#discoveryCardTitle').text('Network Scan');
       $('#scanSubnetRow').show();
       $('#vendorCol').show();
       $('#discoveryScanBox').show();
         $('#discoverPjsipBtn').prop('disabled', discoveryBusy).show();
        $('#autoAddSupportedBtn').show();
         $('#identifyMacBtn').show();
       } else {
       $('#scanBtnLabel').text('Scan DHCP Leases');
       $('#discoveryCardTitle').text('DHCP Leases');
       $('#scanSubnetRow').hide();
       $('#vendorCol').show();
       $('#discoveryScanBox').show();
        $('#discoverPjsipBtn').hide();
         $('#autoAddSupportedBtn').hide();
           $('#identifyMacBtn').hide();
        }
        $('#discoveryFilters').toggle(mode === 'sip_notify' && discoveredRows.length > 0);
        renderDiscoveryCoverage();
        updateAutoAddSupportedButton();
       updateButtonStates();
   }

  function refreshDiscoveredTable() {
  }

  function normalizeDiscoveredRows(rows) {
    return window.ProvisionerDiscovery.normalizeRows(rows);
  }

  function updateAutoAddSupportedButton() {
    var available = !discoveryBusy && discoveryMode === 'sip_notify' && scanCompleted && discoveredRows.some(function (row) {
      return row.is_supported && row.seen_now !== false;
    });
    $('#autoAddSupportedBtn').prop('disabled', !available);
  }

  function clearDiscoverySelection() {
    selectedDhcpMacs.length = 0;
    $('#discoveredTable').bootstrapTable('uncheckAll');
    updateButtonStates();
  }

  function loadDiscoveredRows(rows, preserveExisting, preserveOtherSources) {
    var incoming = normalizeDiscoveredRows(rows);
    if (preserveExisting) {
      discoveredRows = window.ProvisionerDiscovery.mergeRows(discoveredRows, incoming, preserveOtherSources);
    } else {
      discoveredRows = incoming.map(function (row) {
        if (row.seen_now !== false) row.last_seen = new Date().toISOString();
        return row;
      });
    }
    discoveredRows.forEach(function (row) {
      row.discovery_filter = row.seen_now === false ? 'stale'
        : (row.can_identify ? 'needs_mac' : (row.status === 'added' || row.in_provisioner ? 'mapped'
          : (row.mac && row.is_supported ? 'identified' : 'other')));
    });
    clearDiscoverySelection();
    $('#discoveredTable').bootstrapTable('load', discoveredRows).show();
    $('#discoveryFilters').toggle(discoveryMode === 'sip_notify');
    applyDiscoveryFilter(discoveryFilterActive);
    renderDiscoveryCoverage();
    updateButtonStates();
    updateAutoAddSupportedButton();
  }

  function applyDiscoveryFilter(filter) {
    discoveryFilterActive = filter || 'all';
    setUiPreference('provisioner-discovery-filter', discoveryFilterActive);
    $('#discoveryFilter').val(discoveryFilterActive);
    $('#discoveredTable').bootstrapTable('filterBy', discoveryFilterActive === 'all' ? {} : {discovery_filter: discoveryFilterActive});
  }

  $('#discoveryFilter').on('change', function () { applyDiscoveryFilter($(this).val()); });

  function renderDiscoveryCoverage() {
    if (discoveryMode !== 'sip_notify' || !discoveryScanMode) {
      $('#discoveryCoverage, #discoveryExcluded, #discoveryEmpty').hide();
      return;
    }
    var seen = discoveredRows.filter(function (row) { return row.seen_now !== false; });
    var identified = seen.filter(function (row) { return row.mac && row.is_supported && !row.identity_conflict; }).length;
    var needsMac = seen.filter(function (row) { return row.can_identify; }).length;
    var mapped = seen.filter(function (row) { return row.status === 'added' || row.in_provisioner; }).length;
    var other = seen.filter(function (row) { return row.discovery_filter === 'other'; }).length;
    var stale = discoveredRows.length - seen.length;
    var html = '<div class="provisioner-summary-card"><strong>' + seen.length + '</strong><small>Current results</small></div>'
      + '<div class="provisioner-summary-card"><strong>' + identified + '</strong><small>Identified Digium / ALE</small></div>'
      + '<div class="provisioner-summary-card"><strong>' + needsMac + '</strong><small>Unidentified SIP IPs</small></div>'
      + '<div class="provisioner-summary-card"><strong>' + mapped + '</strong><small>Already mapped</small></div>'
      + '<div class="provisioner-summary-card"><strong>' + other + '</strong><small>Other / unsupported</small></div>'
      + (stale ? '<div class="provisioner-summary-card"><strong>' + stale + '</strong><small>Not seen this scan</small></div>' : '');
    $('#discoveryCoverage').html(html).show();
    if (scanCompleted && seen.length === 0) {
      $('#discoveryEmpty').text('No supported Digium/ALE identities or unidentified SIP IPs responded in this scan. Confirm the private subnet and SIP reachability on the scanned ports.').show();
    } else {
      $('#discoveryEmpty').hide();
    }
    if (discoveryExcludedResponders.length) {
      var excludedHtml = '';
      discoveryExcludedResponders.forEach(function (row) {
        excludedHtml += '<tr><td><code>' + diagnosticsEsc(row.ip) + '</code></td><td>' + diagnosticsEsc(row.status || '—')
          + '</td><td>' + diagnosticsEsc(row.reason || 'Unsupported or incomplete SIP response') + '</td></tr>';
      });
      $('#discoveryExcludedSummary').text('Other SIP responders not shown (' + discoveryExcludedResponders.length + ')');
      $('#discoveryExcludedRows').html(excludedHtml);
      $('#discoveryExcluded').show();
    } else {
      $('#discoveryExcluded').hide();
    }
  }

  function updateDiscoveredRowStatus(mac, status) {
    mac = String(mac || '').replace(/[^a-f0-9]/gi, '').toUpperCase();
    var idx = discoveredRows.findIndex(function (r) { return r.mac === mac; });
    if (idx !== -1) {
      discoveredRows[idx].status = status;
      discoveredRows[idx].discovery_filter = status === 'added' ? 'mapped' : discoveredRows[idx].discovery_filter;
      $('#discoveredTable').bootstrapTable('updateByUniqueId', { id: mac, row: { status: status } });
      $('#discoveredTable').bootstrapTable('uncheckBy', { field: 'mac', values: [mac] });
      for (var i = selectedDhcpMacs.length - 1; i >= 0; i--) {
        if (selectedDhcpMacs[i] === mac) selectedDhcpMacs.splice(i, 1);
      }
      updateButtonStates();
      updateAutoAddSupportedButton();
      renderDiscoveryCoverage();
      applyDiscoveryFilter(discoveryFilterActive);
    }
  }

  $('#discoveredTable').on('post-body.bs.table', function () {
    $('.identify-mac-row-btn').off('click').on('click', function () {
      if (!discoveryBusy) identifySelectedIps([String($(this).data('id'))]);
    });
    $('.add-discovered-btn').off('click').on('click', function () {
      if (discoveryBusy) return;
      var mac = $(this).data('mac');
      var ip  = $(this).data('ip') || '';
      var ext = $(this).data('ext') || '';
      ajax('addDiscoveredDevices', { devices: [{ mac: mac, extension: ext, ip: ip }] }, function (res) {
        if (res && res.success) {
          var msg = mac + ' added to MAC Mapping.';
          if (ext) msg += ' Extension ' + ext + ' assigned.';
          else msg += ' Set its extension in the MAC Mapping tab.';
          showAlert('success', msg);
          updateDiscoveredRowStatus(mac, 'added');
          $('#devicesTable').bootstrapTable('refresh');
          loadPendingCount();
        } else {
          showAlert('danger', 'Failed to add ' + mac + ': ' + (res ? res.error : 'Unknown error'));
        }
      });
    });
  });

  function loadInterfaces(selectFirst) {
    ajax('getNetworkSubnets', {}, function (res) {
      var select = $('#scan-interface-select');
      select.empty().append('<option value="">— Select interface —</option>');
      var interfaces = (res && res.interfaces) || [];
      $.each(interfaces, function (i, iface) {
        select.append('<option value="' + iface.subnet + '" data-ip="' + iface.ip + '">' + iface.label + '</option>');
      });
      if (selectFirst && interfaces.length > 0 && !$('#scan-subnet-input').val().trim()) {
        select.val((res && res.selected_subnet) || interfaces[0].subnet).trigger('change');
      }
      if (interfaces.length === 0 && res && res.subnets && res.subnets.length > 0) {
        select.append('<option value="' + res.subnets[0] + '">' + res.subnets[0] + '</option>');
        if (selectFirst && !$('#scan-subnet-input').val().trim()) select.val(res.subnets[0]).trigger('change');
      }
    });
  }

  $('#scan-interface-select').off('change').on('change', function () {
    var subnet = $(this).val();
    $('#scan-subnet-input').val(subnet);
  });

  $('#scanDiscoveryBtn').off('click').on('click', function () {
    if (discoveryBusy) return;
    var btn = $(this);
    var isDhcpMode = discoveryMode === 'dhcp';

    scanCompleted = false;
    discoveryScanMode = '';
    discoveryExcludedResponders = [];
    $('#discoveryEmpty').hide();
    $('#discoverPjsipBtn').prop('disabled', true);
    updateAutoAddSupportedButton();
    $('#discoveredTable').bootstrapTable('resetSearch', '');
    clearDiscoverySelection();
    if (isDhcpMode) {
      discoveredRows = [];
      $('#discoveredTable').bootstrapTable('load', []).hide();
    } else if (discoveredRows.length > 0) {
      discoveredRows.forEach(function (row) { row.seen_now = false; });
      $('#discoveredTable').bootstrapTable('load', discoveredRows).show();
    }

    btn.prop('disabled', true).find('#scanBtnLabel').html('<i class="fa fa-spinner fa-spin"></i> Scanning...');

    if (isDhcpMode) {
      ajax('getDhcpLeases', {}, function (res) {
        btn.prop('disabled', false).find('#scanBtnLabel').text('Scan DHCP Leases');
        if (!res || !res.success) {
          $('#scanStatus').html('<div class="alert alert-warning">' + diagnosticsEsc(res ? res.error : 'Scan failed.') + '</div>').show();
          if (discoveredRows.length === 0) $('#discoveredTable').hide();
          return;
        }
        if (res.leases.length === 0) {
          $('#scanStatus').html('<div class="alert alert-info">No DHCP leases found.</div>').show();
          $('#discoveredTable').hide();
          return;
        }
        scanCompleted = true;
        $('#discoverPjsipBtn').prop('disabled', false);
        $('#scanStatus').html('<p class="text-muted"><strong>' + res.leases.length + '</strong> device(s) found in <code>' + res.file_path + '</code></p>').show();
        loadDiscoveredRows(res.leases);
      });
    } else {
      var subnet = $('#scan-subnet-input').val().trim();
      if (!subnet) {
        $('#discoverPjsipBtn').prop('disabled', false);
        btn.prop('disabled', false).find('#scanBtnLabel').text('Scan Network');
        $('#scanStatus').html('<div class="alert alert-warning">Select an interface or enter a subnet manually.</div>').show();
        if (discoveredRows.length === 0) $('#discoveredTable').hide();
        return;
      }
      $('#spinner-scan').removeClass('hidden');
      discoverySubnet = subnet;
      discoveryBusy = true;
      discoveryScanId = '';
      updateButtonStates();
      ajax('startDiscoveryScan', { subnet: subnet }, function (res) {
        if (!res || !res.success) {
          finishDiscoveryJob();
          $('#scanStatus').html('<div class="alert alert-warning">' + diagnosticsEsc(res ? res.error : 'Scan failed.') + '</div>').show();
          if (discoveredRows.length === 0) $('#discoveredTable').hide();
          return;
        }
        discoveryScanId = res.job_id;
        discoveryScanMode = res.scan_mode || 'routed';
        pollDiscoveryJob(res.job_id);
      });
    }
  });

  function finishDiscoveryJob() {
    discoveryBusy = false;
    $('#scanDiscoveryBtn').prop('disabled', false).find('#scanBtnLabel').text('Scan Network');
    $('#discoverPjsipBtn').prop('disabled', false);
    $('#spinner-scan').addClass('hidden');
    $('#identifyMacBtn').html('<i class="fa fa-search-plus"></i> Identify MAC');
    updateButtonStates();
    updateAutoAddSupportedButton();
  }

  function pollDiscoveryJob(jobId) {
    ajax('getDiscoveryJobStatus', { job_id: jobId }, function (res) {
      if (!res || !res.success) {
        finishDiscoveryJob();
        $('#scanStatus').html('<div class="alert alert-warning">' + diagnosticsEsc(res ? res.error : 'Discovery failed.') + '</div>').show();
        return;
      }
      var rows = res.devices || [];
      if (res.type === 'scan') {
        discoveryScanMode = res.scan_mode || discoveryScanMode || 'routed';
        discoverySubnet = res.subnet || discoverySubnet;
        if (res.complete) discoveryExcludedResponders = res.excluded_responders || [];
        var method = discoveryScanMode === 'local'
          ? 'Local subnet ' + discoverySubnet + ' · ping/ARP (3 passes) + SIP OPTIONS'
          : (discoveryScanMode === 'mixed'
            ? 'Mixed range ' + discoverySubnet + ' · local ping/ARP + routed SIP OPTIONS'
            : 'Routed subnet ' + discoverySubnet + ' · SIP OPTIONS + available registrations');
        $('#discoveryMethodSummary').text(method);
      }
      if (rows.length || res.complete) loadDiscoveredRows(rows, true);
      var identified = rows.filter(function (row) { return !!row.mac && row.seen_now !== false && !row.identity_conflict; }).length;
      var unknown = rows.filter(function (row) { return !!row.can_identify; }).length;
      var label = res.type === 'identify' ? 'Identifying selected MACs'
        : res.phase === 'local_arp' ? 'Local ping/ARP sweep'
        : res.scan_mode === 'local' ? 'Local ping/ARP + SIP OPTIONS scan'
        : res.scan_mode === 'mixed' ? 'Local ping/ARP + routed SIP OPTIONS scan' : 'Routed SIP OPTIONS scan';
       var progress = res.total ? label + ': ' + res.done + '/' + res.total + (res.phase === 'local_arp' ? ' passes' : ' probes') : label + ': starting';
       var methodText = res.type === 'scan' ? $('#discoveryMethodSummary').text() : label;
       $('#scanStatus').html('<p class="text-muted">' + (res.complete ? '<i class="fa fa-check-circle text-success"></i> ' : '<i class="fa fa-spinner fa-spin"></i> ')
         + diagnosticsEsc(methodText) + '<br>' + diagnosticsEsc(progress) + ' · <strong>' + identified
         + '</strong> identified Digium/ALE · <strong>' + unknown + '</strong> unidentified SIP IPs'
         + (discoveryExcludedResponders.length ? ' · <strong>' + discoveryExcludedResponders.length + '</strong> other SIP responders excluded' : '')
         + '</p>').show();
       if (res.complete) {
         scanCompleted = true;
         finishDiscoveryJob();
         renderDiscoveryCoverage();
        (res.errors || []).forEach(function (error) {
          $('#scanStatus').append('<p class="text-warning">' + diagnosticsEsc(error.ip) + ': ' + diagnosticsEsc(error.error) + '</p>');
        });
      } else {
        setTimeout(function () { pollDiscoveryJob(jobId); }, 1000);
      }
    });
  }

  function identifySelectedIps(ids) {
    if (discoveryBusy || !discoveryScanId || !ids.length) return;
    discoveryBusy = true;
    $('#scanDiscoveryBtn, #discoverPjsipBtn').prop('disabled', true);
    $('#identifyMacBtn').html('<i class="fa fa-spinner fa-spin"></i> Identifying...');
    updateButtonStates();
    updateAutoAddSupportedButton();
    ajax('startIdentifyMac', { scan_id: discoveryScanId, ids: ids }, function (res) {
      if (!res || !res.success) {
        finishDiscoveryJob();
        showAlert('warning', diagnosticsEsc(res ? res.error : 'MAC identification failed.'));
      } else {
        pollDiscoveryJob(res.job_id);
      }
    });
  }

  $('#identifyMacBtn').off('click').on('click', function () {
    var ids = selectedDhcpMacs.filter(function (id) {
      var row = discoveredRows.find(function (candidate) { return candidate.id === id; });
      return row && row.can_identify && row.seen_now !== false;
    });
    identifySelectedIps(ids);
  });

  $('#autoAddSupportedBtn').off('click').on('click', function () {
    var btn = $(this);
    if (discoveryBusy || btn.data('digium-busy') || discoveryMode !== 'sip_notify' || !scanCompleted) return;
    var seenRows = discoveredRows.filter(function (row) { return row.seen_now !== false; });
    var supported = seenRows.filter(function (row) { return row.is_supported; });
    if (supported.length === 0) {
      showAlert('info', 'No supported phones were found by this scan.');
      return;
    }

    btn.data('digium-busy', true).prop('disabled', true).html('<i class="fa fa-spinner fa-spin"></i> Matching...');
    ajax('autoDiscoverFromPjsip', {}, function (matchResult) {
      var pjsipWarning = null;
      if (matchResult && matchResult.success) {
        $.each(matchResult.matches || [], function (i, match) {
          var mac = String(match.mac || '').replace(/[^a-f0-9]/gi, '').toUpperCase();
          var row = discoveredRows.find(function (candidate) { return candidate.mac === mac; });
          if (row) {
            row.extension = match.extension || '';
            $('#discoveredTable').bootstrapTable('updateByUniqueId', { id: mac, row: { extension: row.extension } });
          }
        });
      } else {
        pjsipWarning = matchResult ? matchResult.error : 'PJSIP matching was unavailable.';
      }

      btn.data('digium-busy', false).html('<i class="fa fa-magic"></i> Auto Add Supported');
      updateAutoAddSupportedButton();
      var newSupported = supported.filter(function (row) { return row.status === 'new'; });
      var existingSupported = supported.length - newSupported.length;
      var withExtension = newSupported.filter(function (row) { return !!row.extension; }).length;
      var unsupportedCount = seenRows.length - supported.length;
      var message = '<p>Process all supported phones found by this scan?</p>'
        + '<dl class="dl-horizontal">'
        + '<dt>New with extension</dt><dd>' + withExtension + '</dd>'
        + '<dt>New unassigned</dt><dd>' + (newSupported.length - withExtension) + '</dd>'
        + '<dt>Existing IP refresh</dt><dd>' + existingSupported + '</dd>'
        + '<dt>Unsupported skipped</dt><dd>' + unsupportedCount + '</dd>'
        + '</dl>'
        + '<p>Configs will not be generated or pushed. Review MAC Mapping before using Push Config.</p>';
      if (pjsipWarning) {
        message += '<div class="alert alert-warning">PJSIP matching warning: '
          + $('<div>').text(pjsipWarning).html() + '. Phones without a match will be added unassigned.</div>';
      }
      showConfirm('Auto Add Supported Phones', message, function () {
        runAutoAddSupported(btn);
      }, null, 'Add Supported');
    });
  });

  function runAutoAddSupported(btn) {
    var devices = discoveredRows.filter(function (row) { return row.mac && row.is_supported && row.seen_now !== false; }).map(function (row) {
      return { mac: row.mac, ip: row.ip || '' };
    });
    btn.data('digium-busy', true).prop('disabled', true).html('<i class="fa fa-spinner fa-spin"></i> Adding...');
    ajax('autoAddSupportedDiscoveredDevices', { devices: devices }, function (res) {
      btn.data('digium-busy', false).html('<i class="fa fa-magic"></i> Auto Add Supported');
      updateAutoAddSupportedButton();
      if (!res || !res.success) {
        showAlert('danger', 'Auto Add failed: ' + (res ? res.error : 'Unknown error'));
        return;
      }
      $.each(res.added || [], function (i, device) {
        var mac = String(device.mac || '').replace(/[^a-f0-9]/gi, '').toUpperCase();
        var row = discoveredRows.find(function (candidate) { return candidate.mac === mac; });
        if (row) {
          row.status = 'added';
          row.extension = device.extension || '';
          $('#discoveredTable').bootstrapTable('updateByUniqueId', { id: mac, row: { status: 'added', extension: row.extension } });
        }
      });
      $('#devicesTable').bootstrapTable('refresh');
      loadPendingCount();
      clearDiscoverySelection();
      var result = '<p>Supported phone processing is complete.</p><dl class="dl-horizontal">'
        + '<dt>Added</dt><dd>' + res.added_count + '</dd>'
        + '<dt>With extension</dt><dd>' + res.added_with_extension + '</dd>'
        + '<dt>Unassigned</dt><dd>' + res.added_without_extension + '</dd>'
        + '<dt>IPs refreshed</dt><dd>' + res.refreshed_count + '</dd>'
        + '<dt>Already current</dt><dd>' + res.unchanged_count + '</dd>'
        + '<dt>Unsupported skipped</dt><dd>' + res.unsupported_count + '</dd>'
        + '<dt>Extension conflicts</dt><dd>' + (res.extension_conflicts || []).length + '</dd>'
        + '<dt>Errors</dt><dd>' + (res.errors || []).length + '</dd></dl>';
      if (res.pjsip_warning) result += '<div class="alert alert-warning">PJSIP matching was unavailable; unmatched phones were added without extensions.</div>';
      showConfirm('Auto Add Complete', result, function () {
        $('a[href="#devices"]').tab('show');
      }, null, 'Review MAC Mapping', 'Stay Here');
    });
  }

  var pullProgressSequence = 0;
  function startPullProgress(devices) {
    var progressToken = ++pullProgressSequence;
    var MAX_CONCURRENT = 5;
    var pullQueue = devices.filter(function (d) { return d.extension; });
    if (pullQueue.length === 0) return;
    var results = {};
    pullQueue.forEach(function (d) { results[d.mac] = null; });
    var total = pullQueue.length;
    var done = 0;
    var ok = 0;
    var failed = 0;
    var index = 0;
    var pending = 0;

    function isCurrentPull() {
      return progressToken === pullProgressSequence;
    }

    function renderTable() {
      if (!isCurrentPull()) return;
      var html = '';
      pullQueue.forEach(function (d) {
        var r = results[d.mac];
        var statusIcon = r && r.success ? '<span class="text-success">\u2705 Pulled</span>'
          : r && r.success === false ? '<span class="text-danger">\u274c ' + diagnosticsEsc(r.error || 'Unknown error') + '</span>'
          : '<span class="text-muted"><i class="fa fa-spinner fa-spin"></i> fetching...</span>';
        html += '<tr><td>' + d.mac + '</td><td>' + (d.ip || '—') + '</td><td>' + statusIcon + '</td></tr>';
      });
      $('#pullProgressBody').html(html);
    }

    function updateProgress() {
      if (!isCurrentPull()) return;
      var pct = total > 0 ? Math.round(done / total * 100) : 0;
      $('#pullProgressBar').css('width', pct + '%').text(done + ' / ' + total);
      $('#pullProgressSummary').text(ok + ' succeeded, ' + failed + ' failed');
      renderTable();
    }

    function finishPull() {
      if (!isCurrentPull()) return;
      if (failed > 0) {
        $('#pullRetryFailedBtn').show();
      }
      $('#pullProgressBar').removeClass('active');
      $('#pullCloseBtn').prop('disabled', false);
      if (ok > 0) {
        var table = $('#configFilesTable');
        table.bootstrapTable('resetSearch', '');
        table.one('load-success.bs.table', function () { table.bootstrapTable('selectPage', 1); });
        table.bootstrapTable('refresh');
        $('#pullProgressSummary').text(ok + ' succeeded, ' + failed + ' failed. Config Files refreshed.');
      }
    }

    function kick() {
      while (pending < MAX_CONCURRENT && index < total) {
        (function (mac) {
          index++;
          pending++;
          if (!mac) { pending--; done++; updateProgress(); return; }
          renderTable();
          $.ajax({
            url: 'ajax.php?module=provisioner&command=pullPhoneConfig',
            type: 'POST',
            data: { mac: mac },
            dataType: 'json',
            timeout: 120000
          }).done(function (res) {
            if (res && res.success) {
              results[mac] = { success: true, file: res.file || '' };
              ok++;
            } else {
              results[mac] = { success: false, error: (res && res.error) || 'Unknown error' };
              failed++;
            }
          }).fail(function () {
            results[mac] = { success: false, error: 'HTTP error' };
            failed++;
          }).always(function () {
            done++;
            pending--;
            updateProgress();
            if (done >= total && pending === 0) {
              finishPull();
            } else {
              kick();
            }
          });
        })(pullQueue[index].mac);
      }
    }

    $('#pullProgressTitle').text('Pulling Config from Phones');
    $('#pullProgressSummary').text('');
    $('#pullRetryFailedBtn').hide().data('devices', pullQueue);
    $('#pullCloseBtn').prop('disabled', true);
    updateProgress();
    $('#pullProgressModal').modal('show');
    kick();

    $('#pullRetryFailedBtn').off('click').on('click', function () {
      var failedDevices = pullQueue.filter(function (d) { return !results[d.mac] || !results[d.mac].success; });
      if (failedDevices.length === 0) { $(this).hide(); return; }
      failedDevices.forEach(function (d) { results[d.mac] = null; });
      ok = 0;
      failed = 0;
      done = 0;
      index = 0;
      pending = 0;
      pullQueue = failedDevices;
      total = pullQueue.length;
      $('#pullProgressBar').addClass('active');
      $('#pullRetryFailedBtn').hide();
      updateProgress();
      kick();
    });
  }

  $('#addSelectedDiscoveryBtn').off('click').on('click', function () {
    if (discoveryBusy) return;
    var macs = selectedDhcpMacs.slice();
    if (macs.length === 0) return;
    var newDevices = [];
    $.each(macs, function (i, mac) {
      var row = discoveredRows.find(function (r) { return r.id === mac; });
      if (row && row.mac && row.is_supported && row.status === 'new' && row.seen_now !== false) {
        newDevices.push({ mac: row.mac, ip: row.ip || '', extension: row.extension || '' });
      }
    });
    if (newDevices.length === 0) {
      showAlert('info', 'All selected devices are already in MAC Mapping.');
      return;
    }
    var skipped = macs.length - newDevices.length;
    var withExt = newDevices.filter(function (d) { return d.extension; }).length;
    var msg = 'Add ' + newDevices.length + ' device(s) to MAC Mapping?';
    if (withExt > 0) msg += ' ' + withExt + ' have PJSIP extensions assigned.';
    else msg += ' Extensions will be empty — edit them in the MAC Mapping tab.';
    if (skipped > 0) msg += ' (' + skipped + ' already in mapping, skipped.)';

    function doAdd() {
        ajax('addDiscoveredDevices', { devices: newDevices }, function (res) {
          if (res && res.success) {
            var accepted = res.devices || [];
            $.each(accepted, function (i, d) { updateDiscoveredRowStatus(d.mac, 'added'); });
            $('#devicesTable').bootstrapTable('refresh');
            loadPendingCount();
            var extCount = accepted.filter(function(d){ return d.extension; }).length;
            var addMsg = 'Added ' + res.added + ' of ' + newDevices.length + ' device(s) to MAC Mapping.';
            if (extCount > 0) addMsg += ' ' + extCount + ' with extensions.';
            if (res.errors && res.errors.length > 0) addMsg += ' Errors: ' + res.errors.join('; ');
            showAlert(res.errors && res.errors.length > 0 ? 'warning' : 'success', addMsg);
        } else {
          showAlert('danger', 'Failed: ' + (res ? res.error : 'Unknown error'));
        }
      });
    }

    showConfirm('Add Devices', msg + '<br><br>Review the mapping, then use Push Config when ready.', doAdd);
  });

  // Init Bootstrap tooltips
  $('[data-toggle="tooltip"]').tooltip();

  // Load first tab on start
  loadPendingCount();
  liveUpdates.sync();
});
