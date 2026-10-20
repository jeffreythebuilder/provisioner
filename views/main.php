<?php
$moduleName = 'provisioner';
?>
<style>
#provisioner-app .provisioner-summary-group { display:flex; flex-wrap:wrap; align-items:center; gap:0 12px; margin:0 0 6px; }
#provisioner-app .discovery-intro { margin:0 0 8px; padding:4px 0; }
#provisioner-app .provisioner-summary-card { border:0; border-radius:0; background:transparent; padding:2px 0; margin:0 8px 4px 0; min-width:0; display:inline-block; vertical-align:baseline; }
#provisioner-app .provisioner-summary-card strong { display:inline; font-size:15px; line-height:1.2; margin-right:4px; }
#provisioner-app .provisioner-summary-card small { color:#5b6570; }
#provisioner-app .provisioner-filter-select { display:inline-block; width:auto; min-width:150px; margin:0 10px 6px 4px; vertical-align:middle; }
#provisioner-app .phone-status-toolbar-row,
#provisioner-app .phone-status-toolbar-controls,
#provisioner-app .phone-status-toolbar-actions { display:flex; align-items:center; flex-wrap:wrap; gap:6px; }
#provisioner-app .phone-status-toolbar-row { justify-content:space-between; margin:0 0 8px; }
#provisioner-app .phone-status-toolbar-actions { justify-content:flex-end; }
#provisioner-app .phone-status-toolbar .btn { float:none; }
#provisioner-app .phone-status-toolbar .provisioner-filter-select { min-height:32px; margin:0 4px; }
#provisioner-app .phone-status-toolbar #phoneStatusLastUpdated { white-space:nowrap; margin:0 6px; }
#provisioner-app .phone-status-toolbar .action-button { min-width:124px; }
#provisioner-app .phone-status-toolbar .action-button .badge { margin-left:2px; }
#provisioner-app .device-toolbar { display:flex; align-items:center; flex-wrap:wrap; gap:6px; }
#provisioner-app .device-toolbar .btn { margin:0; min-height:32px; }
#provisioner-app .device-toolbar .action-button { margin-left:auto; min-width:124px; }
#provisioner-app .bulk-handler-nav { margin:0 0 14px; }
#provisioner-app .bulk-handler-nav > li > a { padding:8px 14px; }
#provisioner-app .bulk-handler-section { padding:0 2px 12px; }
#provisioner-app .bulk-section-header { margin:0 0 14px; }
#provisioner-app .bulk-section-header h3 { margin:0 0 4px; font-size:18px; }
#provisioner-app .bulk-section-header p { margin:0; color:#5b6570; }
#provisioner-app .bulk-steps { display:flex; flex-wrap:wrap; gap:6px; margin:0 0 16px; }
#provisioner-app .bulk-steps .provisioner-step { margin:0; padding:6px 10px; border:1px solid #d9e0e6; border-radius:3px; }
#provisioner-app .bulk-steps .provisioner-step.active { border-color:#337ab7; background:#f3f9fc; }
#provisioner-app .bulk-steps .provisioner-step.complete { border-color:#5cb85c; background:#eff8ef; }
#provisioner-app .bulk-input-grid { display:grid; grid-template-columns:minmax(0, 1fr) minmax(0, 1fr); gap:14px; }
#provisioner-app .bulk-input-card { min-width:0; padding:14px; border:1px solid #d9e0e6; border-radius:4px; background:#fff; }
#provisioner-app .bulk-input-card label { display:block; margin:0 0 7px; font-weight:600; }
#provisioner-app .bulk-input-card .help-block { margin:7px 0 0; }
#provisioner-app .bulk-file-row { display:flex; align-items:center; flex-wrap:wrap; gap:8px; }
#provisioner-app .bulk-file-row .filename { min-width:0; overflow-wrap:anywhere; }
#provisioner-app .bulk-input-card textarea { min-height:150px; resize:vertical; font-family:monospace; font-size:12px; }
#provisioner-app .bulk-help { margin-top:10px; font-size:12px; }
#provisioner-app .bulk-help summary { cursor:pointer; color:#337ab7; }
#provisioner-app .bulk-action-row { display:flex; align-items:center; flex-wrap:wrap; gap:6px; margin:14px 0; }
#provisioner-app .bulk-action-row .btn { margin:0; }
#provisioner-app .bulk-result-panel { margin-top:12px; }
#provisioner-app .bulk-saved-panel { padding:12px 14px; border:1px solid #b7d9b8; border-left:4px solid #5cb85c; border-radius:4px; background:#f4fbf4; }
#provisioner-app .bulk-saved-heading { margin:0; color:#2e6b30; font-weight:600; }
#provisioner-app .bulk-saved-heading i { margin-right:4px; }
#provisioner-app .bulk-saved-details { display:block; margin-top:3px; color:#5b6570; }
#provisioner-app .bulk-report-controls { display:flex; align-items:center; justify-content:space-between; flex-wrap:wrap; gap:6px 12px; margin:0 0 8px; }
#provisioner-app .bulk-report-control-group { display:flex; align-items:center; flex-wrap:wrap; gap:6px; }
#provisioner-app .bulk-report-toolbar { width:100%; }
#provisioner-app .bulk-report-toolbar label { margin:0; font-weight:normal; color:#5b6570; }
#provisioner-app .bulk-report-toolbar .provisioner-filter-select { min-height:32px; margin:0; }
#provisioner-app #bulkReportsSection .fixed-table-toolbar .bs-bars { width:100%; float:none !important; }
#provisioner-app #bulkReportsSection .fixed-table-toolbar .search { max-width:100%; margin:0 0 10px; }
#provisioner-app #bulkReportsSection .fixed-table-toolbar .search input { min-height:32px; width:260px; max-width:100%; }
#provisioner-app .bulk-report-history-table,
#provisioner-app .bulk-report-table { margin:0; }
#provisioner-app .bulk-report-history-table th,
#provisioner-app .bulk-report-history-table td,
#provisioner-app .bulk-report-table th,
#provisioner-app .bulk-report-table td { vertical-align:top; overflow-wrap:anywhere; }
#provisioner-app .bulk-report-history-table .bulk-report-open { white-space:nowrap; }
#provisioner-app .bulk-report-meta { display:block; margin-top:4px; color:#5b6570; }
#provisioner-app .bulk-status { display:inline-block; padding:3px 6px; border-radius:3px; font-size:12px; font-weight:600; }
#provisioner-app .bulk-status.complete { color:#2e6b30; background:#eff8ef; }
#provisioner-app .bulk-status.in-progress { color:#287da8; background:#eef8fc; }
#provisioner-app .bulk-status.attention { color:#8a6d3b; background:#fcf8e3; }
#provisioner-app .bulk-status.neutral { color:#5b6570; background:#f1f3f5; }
#provisioner-app .bulk-report-panel { margin-top:12px; }
#provisioner-app .bulk-report-header { display:flex; align-items:center; justify-content:space-between; flex-wrap:wrap; gap:8px; margin:12px 0 8px; }
#provisioner-app .bulk-report-title { min-width:0; }
#provisioner-app .bulk-report-title h4 { margin:0 0 5px; font-size:18px; }
#provisioner-app .bulk-report-title small { color:#5b6570; }
#provisioner-app .bulk-report-kind { margin-right:5px; }
#provisioner-app .bulk-report-links { display:flex; align-items:center; flex-wrap:wrap; gap:6px; margin:0 0 10px; color:#5b6570; }
#provisioner-app .bulk-report-summary { margin-bottom:8px; }
#provisioner-app .bulk-report-table { width:100%; min-width:0; }
#provisioner-app .bulk-report-table .bulk-report-phone code,
#provisioner-app .bulk-report-table .bulk-report-target code { overflow-wrap:anywhere; word-break:break-word; }
#provisioner-app .bulk-report-detail { padding:10px; background:#f8fafb; overflow-wrap:anywhere; }
#provisioner-app .bulk-report-detail h5 { margin:0 0 8px; font-weight:600; }
#provisioner-app .bulk-report-detail p { margin:8px 0 0; }
#provisioner-app .bulk-report-detail-grid { display:grid; grid-template-columns:repeat(2, minmax(0, 1fr)); gap:16px; margin-top:12px; }
#provisioner-app .bulk-report-detail dl { display:grid; grid-template-columns:minmax(90px, 130px) minmax(0, 1fr); gap:5px 12px; margin:0; }
#provisioner-app .bulk-report-detail dt,
#provisioner-app .bulk-report-detail dd { margin:0; overflow-wrap:anywhere; }
#provisioner-app #bulkReportsSection .card-view { display:flex; align-items:flex-start; gap:8px; margin-bottom:6px; }
#provisioner-app #bulkReportsSection .card-view > .title { flex:0 0 95px; min-width:0; padding:0; white-space:normal; }
#provisioner-app #bulkReportsSection .card-view > .value { flex:1 1 auto; min-width:0; overflow-wrap:anywhere; }
#provisioner-app .bulk-report-related .bulk-report-control-group { margin-top:8px; }
#provisioner-app .bulk-report-message { margin:10px 0; padding:10px 12px; border:1px solid #d9e0e6; border-radius:4px; background:#f8fafb; color:#5b6570; }
#provisioner-app .bulk-report-message.is-error { color:#a94442; border-color:#ebccd1; background:#fdf4f3; }
#provisioner-app .bulk-report-message .btn { margin-left:8px; }
#provisioner-app .diagnostic-push-timeline { min-width:0; width:100%; padding:10px; border:1px solid #d9e0e6; border-radius:4px; background:#f8fafb; box-sizing:border-box; overflow:hidden; }
#provisioner-app .diagnostic-push-stages { display:grid; grid-template-columns:repeat(3, minmax(0, 1fr)); gap:8px; min-width:0; }
#provisioner-app .diagnostic-push-stage { display:grid; grid-template-columns:minmax(72px, .8fr) minmax(90px, 1fr) minmax(0, 1.6fr); align-items:baseline; gap:8px; min-width:0; min-height:0; margin:0; padding:8px; border:1px solid #d9e0e6; border-radius:3px; background:#fff; box-sizing:border-box; }
#provisioner-app .diagnostic-push-stage-label,
#provisioner-app .diagnostic-push-stage-status,
#provisioner-app .diagnostic-push-stage-time { display:block; min-width:0; line-height:1.3; overflow-wrap:anywhere; }
#provisioner-app .diagnostic-push-stage-label { color:#5b6570; font-size:11px; }
#provisioner-app .diagnostic-push-stage-status { font-size:13px; }
#provisioner-app .diagnostic-push-stage-time { color:#5b6570; font-size:11px; word-break:break-word; }
#provisioner-app .diagnostic-push-details { display:flex; flex-wrap:wrap; gap:4px 16px; min-width:0; padding-top:8px; margin-top:8px; border-top:1px solid #e3e8ec; color:#5b6570; font-size:11px; line-height:1.35; overflow-wrap:anywhere; }
#provisioner-app .diagnostic-push-details span { display:block; min-width:0; max-width:100%; overflow-wrap:anywhere; }
#provisioner-app #deviceDiagnosticsModal .diagnostic-overview > [class*="col-"] { min-width:0; }
#provisioner-app #deviceDiagnosticsModal .diagnostic-overview dd,
#provisioner-app #deviceDiagnosticsModal .diagnostic-overview p { overflow-wrap:anywhere; }
#provisioner-app #deviceDiagnosticsModal code { overflow-wrap:anywhere; word-break:break-word; }
#provisioner-app #deviceDiagnosticsModal .modal-body { overflow-x:hidden; }
#provisioner-app #deviceDiagnosticsModal .diagnostic-latest-push { margin-top:16px; }
@media (max-width: 767px) {
  #provisioner-app .phone-status-toolbar-row { align-items:flex-start; }
  #provisioner-app .phone-status-toolbar-controls,
  #provisioner-app .phone-status-toolbar-actions { width:100%; justify-content:flex-start; }
  #provisioner-app .phone-status-toolbar .action-button { flex:1 1 auto; }
  #provisioner-app .bulk-input-grid { grid-template-columns:1fr; }
  #provisioner-app .bulk-report-detail-grid { grid-template-columns:1fr; }
  #provisioner-app .bulk-report-control-group { justify-content:flex-start; }
  #provisioner-app #bulkReportsSection .fixed-table-toolbar .search { float:none !important; width:100%; }
  #provisioner-app #bulkReportsSection .fixed-table-toolbar .search input { width:100%; }
  #provisioner-app .bulk-saved-panel .bulk-action-row .btn { white-space:normal; }
  #provisioner-app .diagnostic-push-stages { grid-template-columns:1fr; }
  #provisioner-app .diagnostic-push-stage { grid-template-columns:minmax(80px, .7fr) minmax(100px, 1fr) minmax(0, 2fr); }
}
@media (max-width: 480px) {
  #provisioner-app .diagnostic-push-stage { grid-template-columns:1fr; gap:2px; }
  #provisioner-app .diagnostic-push-stage-status { font-weight:600; }
}
#provisioner-app .import-swap-details { margin:4px 0 8px; }
#provisioner-app .batch-stage-list { display:flex; flex-wrap:wrap; align-items:center; gap:2px 5px; }
#provisioner-app .provisioner-filter.active { font-weight:600; box-shadow:inset 0 -3px #286090; }
#provisioner-app .provisioner-step { display:inline-block; padding:4px 8px; margin:0 6px 8px 0; border-bottom:2px solid #d9e0e6; color:#5b6570; }
#provisioner-app .provisioner-step.active { border-color:#337ab7; color:#1d4e78; font-weight:600; }
#provisioner-app .provisioner-step.complete { border-color:#3c763d; color:#2e6b30; }
#provisioner-app .import-swap-row { background:#f1f8fb !important; }
#provisioner-app .batch-stage { white-space:nowrap; display:inline-block; font-size:12px; }
#provisioner-app .batch-stage.done { color:#2e6b30; }
#provisioner-app .batch-stage.failed { color:#a94442; font-weight:600; }
#provisioner-app .batch-stage.pending { color:#6c757d; }
#provisioner-app .batch-stage.active { color:#287da8; }
#provisioner-app .batch-result { font-weight:600; }
#provisioner-app .batch-result.complete { color:#2e6b30; }
#provisioner-app .batch-result.attention { color:#a94442; }
#provisioner-app .batch-result.progress { color:#287da8; }
#provisioner-app .diagnostic-conclusion { border-left:4px solid #5bc0de; padding:10px 14px; margin-bottom:16px; background:#f3f9fc; }
#provisioner-app .diagnostic-conclusion.warning { border-color:#f0ad4e; background:#fcf8e3; }
#provisioner-app .diagnostic-conclusion.danger { border-color:#d9534f; background:#f9eeee; }
#provisioner-app .diagnostic-conclusion.success { border-color:#5cb85c; background:#eff8ef; }
#provisioner-app .diagnostic-push-stage { min-height:70px; max-width:100%; margin-bottom:6px; padding:8px; border:1px solid #d9e0e6; border-radius:3px; background:#fff; box-sizing:border-box; }
#provisioner-app .diagnostic-push-stage-label,
#provisioner-app .diagnostic-push-stage-status,
#provisioner-app .diagnostic-push-stage-time { display:block; line-height:1.25; }
#provisioner-app .diagnostic-push-stage-label { color:#5b6570; font-size:11px; margin-bottom:3px; }
#provisioner-app .diagnostic-push-stage-status { font-size:13px; margin-bottom:3px; }
#provisioner-app .diagnostic-push-stage-time { color:#5b6570; font-size:11px; overflow-wrap:anywhere; word-break:normal; }
#provisioner-app #importReviewModal .modal-dialog { width:calc(100% - 30px); max-width:1200px; margin:15px auto; }
#provisioner-app #importReviewModal .modal-content { display:flex; flex-direction:column; max-height:calc(100vh - 30px); }
#provisioner-app #importReviewModal .modal-header,
#provisioner-app #importReviewModal .modal-footer { flex:0 0 auto; }
#provisioner-app #importReviewModal .modal-title { font-size:18px; }
#provisioner-app #importReviewModal .modal-body { display:flex; flex-direction:column; min-height:0; overflow:hidden; padding:14px 16px; }
#provisioner-app #importReviewModal .import-review-content { display:flex; flex-direction:column; min-height:0; flex:1 1 auto; }
#provisioner-app #importReviewModal .import-review-summary { flex:0 0 auto; margin-bottom:8px; }
#provisioner-app #importReviewModal .import-review-summary .provisioner-summary-group { margin-bottom:0; }
#provisioner-app #importReviewModal .import-review-controls { display:flex; align-items:center; flex-wrap:wrap; gap:6px 10px; flex:0 0 auto; margin:0 0 8px; }
#provisioner-app #importReviewModal .import-review-controls label { margin:0; font-weight:normal; color:#5b6570; }
#provisioner-app #importReviewModal .import-review-controls select { width:auto; min-width:160px; margin:0; }
#provisioner-app #importReviewModal #importReviewStatus { margin-left:auto; color:#5b6570; }
#provisioner-app #importReviewModal #importReviewStatus.warning { color:#8a6d3b; }
#provisioner-app #importReviewModal #importReviewStatus.success { color:#3c763d; }
#provisioner-app #importReviewModal #importResults { flex:1 1 auto; min-height:140px; margin:0; overflow:auto; border:1px solid #d9e0e6; border-radius:3px; }
#provisioner-app #importReviewModal #importResults > .table-responsive { margin:0 !important; }
#provisioner-app #importReviewModal .import-preview-table { min-width:820px; margin:0; table-layout:auto; }
#provisioner-app #importReviewModal .import-preview-table th,
#provisioner-app #importReviewModal .import-preview-table td { vertical-align:top; overflow-wrap:anywhere; word-break:normal; }
#provisioner-app #importReviewModal .import-preview-table code { overflow-wrap:anywhere; word-break:break-word; }
#provisioner-app #importReviewModal .import-review-loading { padding:36px 10px; text-align:center; color:#5b6570; }
#provisioner-app #importReviewModal .import-review-error { margin:0 0 10px; }
#provisioner-app #importReviewModal .modal-footer { display:flex; align-items:center; justify-content:flex-end; gap:6px; }
#provisioner-app #importReviewModal .modal-footer .btn { margin:0; }
@media (max-width: 767px) {
  #provisioner-app #importReviewModal .modal-dialog { width:calc(100% - 16px); margin:8px auto; }
  #provisioner-app #importReviewModal .modal-content { max-height:calc(100vh - 16px); }
  #provisioner-app #importReviewModal #importReviewStatus { width:100%; margin-left:0; }
}
#deployModeCard .mode-active {
  background: #e8f5e9;
  border-color: #4caf50 !important;
}
#deployModeCard .mode-active strong::after {
  content: " (Active)";
  font-size: 11px;
  color: #2e7d32;
  font-weight: bold;
  margin-left: 6px;
}
</style>
<div class="container-fluid" id="provisioner-app">
  <div class="row">
    <div class="col-sm-12">
      <div class="fpbx-container">
        <div class="display no-border">
          <h1>Provisioner</h1>
          <?php
          $provisionerHelp = '<p>Provisioner manages phone discovery, extension-to-MAC assignments, brand templates, generated configuration files, and configuration delivery.</p>';
          $provisionerHelp .= '<p>Use Phone Status to monitor devices, MAC Mapping to assign phones, and Config Files to inspect or pull active configurations.</p>';
          echo show_help($provisionerHelp, 'What is Provisioner?', false, true, 'info');
          ?>

  <!-- Tabs -->
  <ul class="nav nav-tabs" id="provisionerTabs" role="tablist">
    <li class="nav-item">
      <a class="nav-link active" id="phones-tab" data-toggle="tab" href="#phones" role="tab"><i class="fa fa-phone"></i> Phone Status</a>
    </li>
    <li class="nav-item">
      <a class="nav-link" id="devices-tab" data-toggle="tab" href="#devices" role="tab"><i class="fa fa-list"></i> MAC Mapping</a>
    </li>
    <li class="nav-item">
      <a class="nav-link" id="import-tab" data-toggle="tab" href="#import" role="tab"><i class="fa fa-upload"></i><i class="fa fa-download"></i> Bulk Handler</a>
    </li>
    <li class="nav-item">
      <a class="nav-link" id="provisioning-tab" data-toggle="tab" href="#provisioning" role="tab"><i class="fa fa-cogs"></i> Provisioning</a>
    </li>
    <li class="nav-item">
      <a class="nav-link" id="discovered-tab" data-toggle="tab" href="#discovered" role="tab"><i class="fa fa-search"></i> Device Discovery</a>
    </li>
    <li class="nav-item">
      <a class="nav-link" id="configfiles-tab" data-toggle="tab" href="#configfiles" role="tab"><i class="fa fa-file-text-o"></i> Config Files</a>
    </li>
    <li class="nav-item">
      <a class="nav-link" id="logs-tab" data-toggle="tab" href="#logs" role="tab"><i class="fa fa-history"></i> Logs</a>
    </li>
    <li class="nav-item">
      <a class="nav-link" id="settings-tab" data-toggle="tab" href="#settings" role="tab"><i class="fa fa-wrench"></i> Settings</a>
    </li>
  </ul>

  <div class="tab-content" id="provisionerTabsContent">

    <!-- ===================== PHONE STATUS TAB ===================== -->
    <div class="tab-pane display active" id="phones" role="tabpanel">
      <p class="text-muted">Registration and provisioning delivery are separate. The network mode under each IP is the saved mapping setting.</p>
      <div class="row" style="margin-top:20px;">
        <div class="col-md-12">
          <div class="table-responsive">
              <div id="phoneStatusToolbar" class="phone-status-toolbar">
                <div id="phoneStatusSummary" aria-live="polite" style="margin:0 0 4px;"></div>
                <div id="phoneStatusEmpty" class="alert alert-info" style="display:none;margin:0 0 8px;" aria-live="polite"></div>
                <div class="phone-status-toolbar-row">
                  <div class="phone-status-toolbar-controls">
                    <label for="phoneStatusFilter" class="text-muted" style="font-weight:normal;margin:0;">Show</label>
                    <select id="phoneStatusFilter" class="form-control input-sm provisioner-filter-select" aria-label="Filter phone status">
                      <option value="all">All phones</option>
                      <option value="pending">Pending changes</option>
                      <option value="issues">Push issues</option>
                      <option value="unregistered">Not registered / unavailable</option>
                    </select>
                    <span id="phoneStatusLastUpdated" class="text-muted" style="font-size:12px;" aria-live="polite">Waiting for first status update</span>
                  </div>
                  <div class="phone-status-toolbar-actions">
                    <button class="btn btn-default btn-sm action-button" id="applyChangesPhoneBtn" disabled title="Select phone(s) to push, or wait for a push issue to retry">
                      <i class="fa fa-paper-plane"></i> <span class="push-action-label">Push Config</span> <span id="pendingBadgePhone" class="badge badge-light" style="display:none;">0</span>
                    </button>
                    <button class="btn btn-default btn-sm action-button" id="rebootActionBtn" disabled title="No eligible phones to reboot">
                      <i class="fa fa-power-off"></i> <span class="reboot-action-label">Reboot All</span> <span id="rebootActionCount" class="badge badge-light" style="display:none;">0</span>
                    </button>
                    <button class="btn btn-default btn-sm" id="liveToggleBtn" title="Live Asterisk and Provisioner updates" aria-pressed="false">
                      <i class="fa fa-pause"></i> Live Updates · Paused
                    </button>
                  </div>
                </div>
              </div>
              <table id="phoneStatusTable"
                     data-url="ajax.php?module=provisioner&amp;command=getPhoneStatusGrid"
                     data-toolbar="#phoneStatusToolbar"
                     data-toggle="table"
                     data-pagination="true"
                     data-search="true"
                     data-unique-id="mac"
                     data-maintain-meta-data="true"
                     data-cookie="true"
                     data-cookie-id-table="provisioner-phones"
                     class="table table-striped">
                <thead>
                  <tr>
                    <th data-checkbox="true"></th>
                    <th data-sortable="true" data-field="extension">Extension</th>
                    <th data-sortable="true" data-field="mac" data-formatter="phoneStatusPhoneFormatter">Phone</th>
                    <th data-sortable="true" data-field="ip" data-sorter="ipSorter" data-formatter="phoneStatusIpFormatter">IP / Network</th>
                    <th data-sortable="true" data-field="registration" data-formatter="phoneStatusRegistrationFormatter">Registration</th>
                    <th data-sortable="true" data-field="status">Phone state</th>
                    <th data-sortable="true" data-field="push_status" data-formatter="phoneStatusPushFormatter">Last push</th>
                    <th data-field="needs_changes" data-visible="false">Pending</th>
                    <th data-field="push_issue" data-visible="false">Push issue</th>
                    <th data-field="registered" data-visible="false">Registered</th>
                    <th data-field="actions">Actions</th>
                  </tr>
                </thead>
              </table>
          </div>
        </div>
      </div>
    </div>

    <!-- ===================== MAC MAPPING TAB ===================== -->
    <div class="tab-pane display" id="devices" role="tabpanel">
      <div class="alert alert-info">Assign extensions, brands, and network settings to phone MAC addresses.</div>
      <div class="row" style="margin-top:20px;">
        <div class="col-md-12">
          <div class="table-responsive">
              <div id="deviceToolbar" class="device-toolbar">
                <button class="btn btn-add btn-sm" id="addDeviceBtn" title="Manually add a device to MAC Mapping"><i class="fa fa-plus"></i> Add Device</button>
                <button class="btn btn-success btn-sm" id="autoAssignBtn" title="Auto-assign next available extension to devices without one"><i class="fa fa-magic"></i> Auto-Assign</button>
                <button class="btn btn-danger btn-sm" id="deleteSelectedBtn" disabled title="Delete selected devices from MAC Mapping"><i class="fa fa-trash"></i> Delete Mappings</button>
                <button class="btn btn-secondary btn-sm" id="discardAllBtn" style="display:none;" title="Discard all pending changes on all devices">
                  <i class="fa fa-undo"></i> Discard All
                </button>
                <button class="btn btn-default btn-sm action-button" id="applyChangesBtn" disabled title="Select device(s) to push, or wait for a push issue to retry">
                  <i class="fa fa-paper-plane"></i> <span class="push-action-label">Push Config</span> <span id="pendingBadge" class="badge badge-light" style="display:none;">0</span>
                </button>
              </div>
              <table id="devicesTable"
                     data-url="ajax.php?module=provisioner&amp;command=getDeviceGrid"
                     data-toolbar="#deviceToolbar"
                     data-toggle="table"
                     data-pagination="true"
                     data-search="true"
                     data-unique-id="id"
                     data-maintain-meta-data="true"
                     data-cookie="true"
                     data-cookie-id-table="provisioner-devices"
                     class="table table-striped">
                <thead>
                  <tr>
                    <th data-checkbox="true"></th>
                    <th data-sortable="true" data-field="extension_display">Extension</th>
                    <th data-sortable="true" data-field="mac_address">MAC Address</th>
                    <th data-sortable="true" data-field="brand">Brand</th>
                    <th data-sortable="true" data-field="description">Description</th>
                    <th data-sortable="true" data-field="static_ip" data-sorter="ipSorter">Static IP</th>
                    <th data-field="config_sync">Config Sync</th>
                    <th data-field="actions">Actions</th>
                  </tr>
                </thead>
              </table>
          </div>
        </div>
      </div>
    </div>

    <!-- ===================== BULK HANDLER TAB ===================== -->
    <div class="tab-pane fade" id="import" role="tabpanel">
      <div class="row" style="margin-top:20px;">
        <div class="col-sm-12">
          <ul class="nav nav-tabs bulk-handler-nav" role="tablist">
            <li role="presentation" class="active"><a href="#bulkImportSection" class="bulk-pill active">Import</a></li>
            <li role="presentation"><a href="#bulkExportSection" class="bulk-pill">Export</a></li>
            <li role="presentation"><a href="#bulkReportsSection" class="bulk-pill" id="batchHistoryTab">Batch Reports</a></li>
          </ul>

          <div id="bulkImportSection" class="bulk-handler-section">
            <div class="bulk-section-header">
              <h3>Import mappings</h3>
              <p>Review every change before saving. Saving mappings never contacts a phone.</p>
            </div>
            <div id="importSteps" class="bulk-steps" aria-label="Import progress">
              <span class="provisioner-step active" data-step="1">1. Choose CSV</span>
              <span class="provisioner-step" data-step="2">2. Review</span>
              <span class="provisioner-step" data-step="3">3. Save</span>
            </div>
            <form name="bulkhandler" role="form">
              <div class="bulk-input-grid">
                <div class="bulk-input-card">
                  <label for="importCsvFile">CSV file</label>
                  <div class="bulk-file-row">
                    <span class="btn btn-default btn-file">Browse <input type="file" class="form-control importer" id="importCsvFile" accept=".csv,.txt"></span>
                    <span class="filename text-muted"></span>
                  </div>
                  <small id="import-file-help" class="help-block">Choose a CSV file, or paste its contents in the panel beside it.</small>
                </div>
                <div class="bulk-input-card">
                  <label for="importCsv">Paste CSV</label>
                  <textarea class="form-control" id="importCsv" rows="8" placeholder="201,00:04:f3:ab:cd:ef,,Office Phone,,,&#10;202,00:04:f3:12:34:56,alcatel,Conference Room,192.168.0.11,255.255.255.0,192.168.0.1"></textarea>
                  <small id="import-data-help" class="help-block">Format: <code>extension,mac,brand,description,static_ip,subnet_mask,gateway</code>. Brand and static IP columns are optional.</small>
                  <details class="bulk-help"><summary>CSV format example</summary><p class="text-muted" style="margin:6px 0 0;"><code>extension,mac,brand,description,static_ip,subnet_mask,gateway</code></p></details>
                </div>
              </div>
              <div class="bulk-action-row">
                <input type="button" class="btn btn-primary" id="importBtn" value="Review Import">
              </div>
              <div id="importSavedPanel" class="bulk-saved-panel bulk-result-panel" style="display:none;">
                <div class="bulk-saved-heading"><i class="fa fa-check-circle" aria-hidden="true"></i> <span id="importSavedSummary">Mappings saved</span></div>
                <small id="importSavedDetails" class="bulk-saved-details">Configurations have not been pushed</small>
                <div class="bulk-action-row" style="margin-bottom:0;">
                  <button type="button" class="btn btn-primary" id="pushImportedPhones" disabled><i class="fa fa-paper-plane"></i> Push Imported Phones <span id="pushImportedPhonesCount" class="badge">0</span></button>
                  <button type="button" class="btn btn-default" id="viewImportedMappings"><i class="fa fa-list"></i> View MAC Mappings</button>
                  <button type="button" class="btn btn-default" id="viewImportReport"><i class="fa fa-file-text-o"></i> View Batch Report</button>
                </div>
              </div>
            </form>
          </div>

          <div id="bulkExportSection" class="bulk-handler-section" style="display:none;">
            <div class="bulk-section-header">
              <h3>Export mappings</h3>
              <p>Download the current MAC mappings as a CSV file.</p>
            </div>
            <div class="bulk-input-card">
              <details class="bulk-help" open>
                <summary>Export options</summary>
                <div class="checkbox" style="margin:10px 0 6px;">
                  <label><input type="checkbox" id="exportUsePjsipIp"> Use available PJSIP contact IP as Static IP</label>
                </div>
                <div class="alert alert-warning" id="exportPjsipWarning" style="display:none;padding:8px 12px;margin:8px 0;">
                  PJSIP provides only the contact IP. Verify DHCP reservations, subnet masks, and gateways before importing or pushing this CSV.
                </div>
              </details>
              <div class="bulk-action-row" style="margin-bottom:0;">
                <button type="button" class="btn btn-default" id="exportCsvBtn"><i class="fa fa-download"></i> Download CSV</button>
              </div>
              <small id="export-help-help" class="help-block">The export includes extension, MAC, brand, description, and saved network settings.</small>
            </div>
          </div>

          <div id="bulkReportsSection" class="bulk-handler-section" style="display:none;">
            <div id="batchHistoryView">
              <div class="bulk-section-header">
                <h3>Batch Reports</h3>
                <p>Review saved imports and pushes. Search by date, type, status, or phone.</p>
              </div>
              <div id="batchHistoryMessage" class="bulk-report-message" role="status" aria-live="polite" style="display:none;">
                <span id="batchHistoryMessageText"></span>
                <button type="button" class="btn btn-default" id="reloadBatchHistoryBtn" style="display:none;"><i class="fa fa-refresh"></i> Try Again</button>
              </div>
              <div id="batchHistoryToolbar" class="bulk-report-toolbar">
                <div class="bulk-report-controls">
                  <div id="batchHistorySummary" class="provisioner-summary-group" aria-live="polite"></div>
                  <button type="button" class="btn btn-default" id="refreshBatchHistoryBtn"><i class="fa fa-refresh"></i> Refresh</button>
                </div>
              </div>
              <div id="batchHistoryResults" class="table-responsive">
                <table id="batchHistoryTable" class="table table-striped bulk-report-history-table" aria-label="Saved batch reports"></table>
              </div>
            </div>
            <div id="batchReportResults" class="bulk-report-panel" style="display:none;" aria-busy="false">
              <button type="button" class="btn btn-default" id="backToBatchHistoryBtn"><i class="fa fa-arrow-left"></i> Back to Reports</button>
              <div id="batchReportMessage" class="bulk-report-message" role="status" aria-live="polite" style="display:none;">
                <span id="batchReportMessageText"></span>
                <button type="button" class="btn btn-default" id="reloadBatchReportBtn" style="display:none;"><i class="fa fa-refresh"></i> Try Again</button>
              </div>
              <div id="batchReportContent" style="display:none;">
                <div class="bulk-report-header">
                  <div class="bulk-report-title">
                    <h4 id="batchReportTitle"></h4>
                    <small id="batchReportMeta"></small>
                  </div>
                  <span id="batchReportStatus" class="bulk-status neutral"></span>
                </div>
                <div id="batchReportSummary" class="provisioner-summary-group bulk-report-summary" aria-live="polite"></div>
                <div id="batchReportLinks" class="bulk-report-links" style="display:none;"></div>
                <div id="batchReportToolbar" class="bulk-report-toolbar">
                  <div class="bulk-report-controls">
                    <div class="bulk-report-control-group">
                      <label for="batchReportFilter">Show</label>
                      <select id="batchReportFilter" class="form-control input-sm provisioner-filter-select" aria-label="Filter report phones">
                        <option value="all">All Phones</option>
                        <option value="attention">Needs Attention</option>
                        <option value="progress">In Progress</option>
                        <option value="completed">Completed</option>
                      </select>
                    </div>
                    <div class="bulk-report-control-group">
                      <button type="button" class="btn btn-default" id="refreshBatchReportBtn"><i class="fa fa-refresh"></i> Refresh</button>
                      <button type="button" class="btn btn-default" id="downloadBatchCsvBtn"><i class="fa fa-download"></i> Download CSV</button>
                      <button type="button" class="btn btn-warning" id="retryBatchBtn" disabled title="Retry eligible phones in this batch, including required swap partners"><i class="fa fa-repeat"></i> Retry Eligible Phones <span id="retryBatchCount" class="badge">0</span></button>
                    </div>
                  </div>
                </div>
                <div class="table-responsive">
                  <table id="batchReportTable" class="table table-striped bulk-report-table" aria-label="Phones in this batch"></table>
                </div>
              </div>
            </div>
          </div>
        </div>
      </div>
    </div>

    <!-- ===================== PROVISIONING TAB ===================== -->
    <div class="tab-pane display" id="provisioning" role="tabpanel">
      <div class="alert alert-info">Review the provisioning server address, directory, generated files, and delivery settings.</div>
      <div class="row" style="margin-top:20px;">
        <div class="col-md-12">
          <div class="section-title" data-for="provisioner_server_info"><h3><i class="fa fa-minus"></i> Server Info</h3></div>
          <div class="section" data-id="provisioner_server_info" id="provInfoBody">
              <p>Loading...</p>
          </div>
        </div>
      </div>
    </div>

    <!-- ===================== DEVICE DISCOVERY TAB ===================== -->
    <div class="tab-pane display" id="discovered" role="tabpanel">
      <div class="row" style="margin-top:20px;">
        <div class="col-md-12">
          <div class="table-responsive">
              <div class="discovery-intro">
                <strong>Digium / Alcatel-ALE discovery</strong> · <span id="discoveryCardTitle">Scan for Phones</span>
                <span id="discoveryMethodSummary">Local: ping/ARP + SIP OPTIONS · Routed: SIP OPTIONS + registrations</span>
                <details style="margin:4px 0 8px;"><summary>Scan and identity details</summary>
                  <p style="margin:6px 0 0;">Normal scans are read-only. Identify MAC sends a URL-only SIP NOTIFY to an isolated receiver; the endpoint intentionally returns HTTP 404 and serves no config or firmware. Cross-subnet pushes still require a mapping and a matching available registration or MAC/IP identity seen within 15 minutes.</p>
                </details>
              </div>
              <div id="discoveryCoverage" class="provisioner-summary-group" style="display:none;" aria-live="polite"></div>
              <div id="discoveryEmpty" class="alert alert-info" style="display:none;margin:0 0 10px;" aria-live="polite"></div>
              <div id="discoveryExcluded" style="display:none;margin:0 0 10px;">
                <details>
                  <summary id="discoveryExcludedSummary">Other SIP responders not shown</summary>
                  <div class="table-responsive" style="margin-top:6px;"><table class="table table-condensed table-striped"><thead><tr><th>IP</th><th>SIP response</th><th>Why it was excluded</th></tr></thead><tbody id="discoveryExcludedRows"></tbody></table></div>
                </details>
              </div>
              <div id="discoveryFilters" style="display:none;margin-bottom:4px;">
                <label for="discoveryFilter" class="text-muted" style="font-weight:normal;">Show</label>
                <select id="discoveryFilter" class="form-control input-sm provisioner-filter-select" aria-label="Filter discovery results">
                  <option value="all">All results</option>
                  <option value="identified">Identified phones</option>
                  <option value="needs_mac">Needs MAC identification</option>
                  <option value="mapped">Already mapped</option>
                  <option value="stale">Not seen this scan</option>
                  <option value="other">Other / unsupported</option>
                </select>
              </div>
              <div id="discoveryScanBox">
                <div id="scanStatus" style="display:none;margin-bottom:8px;"></div>
                <div id="discoveredToolbar">
                  <button class="btn btn-add" id="addSelectedDiscoveryBtn" disabled title="Add selected devices to MAC Mapping for provisioning">
                    <i class="fa fa-plus"></i> Add
                  </button>
                  <button class="btn btn-success" id="autoAddSupportedBtn" disabled style="display:none;" title="Add every supported phone found by this scan and refresh stored IPs for existing mappings">
                    <i class="fa fa-magic"></i> Auto Add Supported
                  </button>
                  <button class="btn btn-info" id="identifyMacBtn" disabled style="display:none;" title="Identify selected unknown SIP IPs through a URL-only NOTIFY. The receiver returns 404 and serves no configuration.">
                    <i class="fa fa-search-plus"></i> Identify MAC
                  </button>
                <span id="scanSubnetRow" style="display:none;margin-left:10px;font-size:14px;font-weight:normal;">
                  Interface: <select id="scan-interface-select" class="form-control" style="width:auto;display:inline-block;margin-right:10px;">
                    <option value="">— Select interface —</option>
                  </select>
                  Subnet: <input type="text" id="scan-subnet-input" class="form-control" style="width:160px;font-family:monospace;display:inline-block;" placeholder="e.g. 192.168.0.0/24">
                  <span id="spinner-scan" class="hidden" style="margin-left:6px"><i class="fa fa-spinner fa-spin"></i></span>
                </span>
                <button class="btn  btn-primary float-right" id="scanDiscoveryBtn" style="margin-left:5px;" title="Read-only local ping/ARP plus SIP OPTIONS; routed subnets use UDP/TCP/TLS SIP OPTIONS; /22 to /32 ranges">
                  <i class="fa fa-search"></i> <span id="scanBtnLabel">Scan for Phones</span>
                </button>
                <button class="btn  btn-info float-right" id="discoverPjsipBtn" title="Discover available registered phones using SIP User-Agent MACs across routed subnets, with ARP matching for local phones.">
                  <i class="fa fa-phone"></i> Discover from PJSIP
                </button>
                </div>
                <table id="discoveredTable"
                       data-toolbar="#discoveredToolbar"
                       data-toggle="table"
                       data-pagination="true"
                       data-search="true"
                       data-unique-id="id"
                       data-maintain-meta-data="true"
                       class="table table-striped"
                       style="display:none;">
                  <thead>
                    <tr>
                      <th data-checkbox="true"></th>
                       <th data-sortable="true" data-field="mac" data-formatter="discoveredMacFormatter">MAC</th>
                       <th data-sortable="true" data-field="ip" data-sorter="ipSorter">IP</th>
                      <th data-sortable="true" data-field="extension">Ext</th>
                        <th data-sortable="true" data-field="vendor" data-escape="true" id="vendorCol" style="display:none;">Vendor</th>
                        <th data-field="model" data-formatter="discoveredModelFormatter">Model / Firmware</th>
                       <th data-sortable="true" data-field="source" data-formatter="discoveredSourceFormatter">Identity / freshness</th>
                       <th data-sortable="true" data-field="status" data-formatter="discoveredStatusFormatter">Status</th>
                       <th data-field="discovery_filter" data-visible="false">Filter</th>
                      <th data-field="actions" data-formatter="discoveredActionsFormatter">Actions</th>
                    </tr>
                  </thead>
                </table>
              </div>
          </div>
        </div>
      </div>
    </div>

    <!-- ===================== CONFIG FILES TAB ===================== -->
    <div class="tab-pane display" id="configfiles" role="tabpanel">
      <div class="row" style="margin-top:20px;">
        <div class="col-md-12">
          <div class="table-responsive">
              <div class="alert alert-info">Review, pull, restore, edit, revert, or delete phone configuration files.</div>
              <div id="configFileToolbar">
                <button class="btn btn-info" id="pullSelectedConfigBtn" disabled title="Download the active config file from the phone to inspect or edit">
                  <i class="fa fa-download"></i> Pull Config</button>
                <button class="btn btn-warning" id="revertSelectedConfigBtn" disabled title="Revert phone config to the last provisioner-generated version">
                  <i class="fa fa-undo"></i> Revert Config</button>
                <button class="btn btn-danger" id="deleteSelectedConfigBtn" disabled title="Delete selected config files from the server">
                  <i class="fa fa-trash"></i> Delete</button>
                <button class="btn btn-danger float-right" id="deleteAllConfigBtn" title="Delete all generated config files on the server">
                  <i class="fa fa-trash"></i> Delete All
                </button>
              </div>
              <table id="configFilesTable"
                     data-url="ajax.php?module=provisioner&amp;command=getConfigFileGrid"
                     data-toolbar="#configFileToolbar"
                     data-toggle="table"
                     data-pagination="true"
                     data-search="true"
                     data-unique-id="mac"
                     data-maintain-meta-data="true"
                     data-cookie="true"
                     data-cookie-id-table="provisioner-config"
                     class="table table-striped">
                <thead>
                  <tr>
                    <th data-checkbox="true"></th>
                    <th data-sortable="true" data-field="assigned_extension">Assigned Extension</th>
                    <th data-sortable="true" data-field="config_extension">Config Extension</th>
                    <th data-sortable="true" data-field="mac">MAC</th>
                    <th data-sortable="true" data-field="description">Description</th>
                    <th data-field="state">Config State</th>
                    <th data-sortable="true" data-field="modified">Modified</th>
                    <th data-field="actions">Actions</th>
                  </tr>
                </thead>
              </table>
          </div>
        </div>
      </div>
    </div>

    <!-- ===================== LOGS TAB ===================== -->
    <div class="tab-pane display" id="logs" role="tabpanel">
      <div class="row" style="margin-top:20px;">
        <div class="col-md-12">
          <div class="table-responsive">
              <div class="alert alert-info">Review provisioning, discovery, configuration pull, and push activity.</div>
              <div id="logsToolbar">
                <button class="btn btn-danger" id="clearLogsBtn">
                  <i class="fa fa-trash"></i> Clear Logs
                </button>
              </div>
              <table id="logsTable"
                     data-url="ajax.php?module=provisioner&amp;command=getLogGrid"
                     data-toolbar="#logsToolbar"
                     data-toggle="table"
                     data-pagination="true"
                     data-search="true"
                     data-cookie="true"
                     data-cookie-id-table="provisioner-logs"
                     class="table table-striped">
                <thead>
                  <tr>
                    <th data-sortable="true" data-field="timestamp">Timestamp</th>
                    <th data-sortable="true" data-field="action">Action</th>
                    <th data-sortable="true" data-field="details">Details</th>
                    <th data-sortable="true" data-field="status">Status</th>
                  </tr>
                </thead>
              </table>
          </div>
        </div>
      </div>
    </div>

    <!-- ===================== SETTINGS TAB ===================== -->
    <div class="tab-pane display" id="settings" role="tabpanel">
      <div class="alert alert-info">Configure deployment mode, PBX networking, VLANs, credentials, and brand templates.</div>
      <div class="row" style="margin-top:20px;">
        <div class="col-md-12">
            <div class="section-title" data-for="provisioner_deployment_mode"><h3><i class="fa fa-minus"></i> Deployment Mode</h3></div>
            <div class="section" data-id="provisioner_deployment_mode" id="deployModeCard">
                <div class="radio">
                  <label id="modeDhcpLabel" style="display:block;padding:10px 14px;border:1px solid #ddd;border-radius:4px;margin-bottom:8px;cursor:pointer;">
                    <input type="radio" name="deploy_mode" value="dhcp" id="modeDhcp" style="margin-right:6px;">
                    <strong>DHCP Option 66</strong> &mdash; Phone gets config URL from DHCP server. Push triggers a reboot (SIP NOTIFY via Asterisk) to force the phone to re-DHCP and fetch config.
                  </label>
                  <label id="modeSipNotifyLabel" style="display:block;padding:10px 14px;border:1px solid #ddd;border-radius:4px;margin-bottom:8px;cursor:pointer;">
                    <input type="radio" name="deploy_mode" value="sip_notify" id="modeSipNotify" style="margin-right:6px;">
                    <strong>SIP NOTIFY Push</strong> &mdash; Phone gets its config URL through SIP. Discover uses local ping/ARP with SIP OPTIONS enrichment on directly connected subnets, and SIP OPTIONS across routed subnets; Identify MAC performs an isolated missing-file lookup for selected unknown SIP IPs. Push resolves each mapped MAC through registration, local ARP, or saved MAC/IP discovery evidence across routed subnets.
                  </label>
                </div>
                <button type="button" class="btn btn-primary" id="saveDeployModeBtn"><i class="fa fa-save"></i> Save Mode</button>
                <span id="deployModeSaveStatus" style="margin-left:8px;"></span>
            </div>
        </div>
      </div>
      <div class="row">
        <div class="col-md-6">
          <div class="section-title" data-for="provisioner_pbx_settings"><h3><i class="fa fa-minus"></i> PBX Settings</h3></div>
          <div class="section" data-id="provisioner_pbx_settings">
              <form id="pbxSettingsForm">
                <div class="form-group">
                  <label for="settingsPbxIp">PBX IP Address</label>
                  <div class="input-group">
                    <select id="settingsInterfaceSelect" class="form-control" style="max-width:280px;">
                      <option value="">— Select interface —</option>
                    </select>
                    <input type="text" class="form-control" id="settingsPbxIp" name="pbx_ip" placeholder="e.g. 192.168.1.100" style="margin-left:6px;">
                  </div>
                </div>
                <div class="form-group">
                  <label for="settingsTransport">Transport</label>
                  <select class="form-control" id="settingsTransport" name="transport">
                    <option value="UDP">UDP</option>
                    <option value="TCP">TCP</option>
                  </select>
                </div>
                <div class="form-group">
                  <label for="settingsSipPort">SIP Port</label>
                  <input type="text" class="form-control" id="settingsSipPort" name="sip_port" readonly>
                  <small class="form-text text-muted" id="settingsSipDetection">Detecting active Asterisk transport...</small>
                </div>
                <div class="form-group">
                  <label for="settingsScanSubnets">Scan Subnets (CIDR)</label>
                  <div class="input-group">
                    <input type="text" class="form-control" id="settingsScanSubnets" name="scan_subnets" placeholder="192.168.0.0/24, 10.10.0.0/16">
                    <span class="input-group-btn"><button type="button" class="btn btn-default" id="detectScanSubnetBtn"><i class="fa fa-crosshairs"></i> Auto-Detect</button></span>
                  </div>
                  <small class="form-text text-muted" id="settingsScanDetection">Detecting subnet from the selected PBX interface...</small>
                </div>
                <div class="form-group" id="settingsDhcpLeasesRow">
                  <label for="settingsDhcpLeasesFile">DHCP Leases File</label>
                  <input type="text" class="form-control" id="settingsDhcpLeasesFile" name="dhcp_leases_file" placeholder="/var/lib/misc/dnsmasq.leases">
                  <small class="form-text text-muted">Path to the DHCP server's lease file for device discovery in DHCP Option 66 mode.</small>
                </div>
                <button type="button" class="btn  btn-primary" id="savePbxSettingsBtn"><i class="fa fa-save"></i> Save Settings</button>
              </form>
          </div>
          <div class="section-title" data-for="provisioner_vlan_settings"><h3><i class="fa fa-minus"></i> VLAN Settings</h3></div>
          <div class="section" data-id="provisioner_vlan_settings">
              <form id="vlanForm">
                <div class="form-group">
                  <label for="settingsVlanId">Voice VLAN ID</label>
                  <input type="number" class="form-control" id="settingsVlanId" name="vlan_id" placeholder="Empty = disabled" min="1" max="4094">
                  <small class="form-text text-muted">802.1Q VLAN ID for voice traffic. Leave empty to disable.</small>
                </div>
                <div class="form-group">
                  <label for="settingsVlanDataId">Data VLAN ID</label>
                  <input type="number" class="form-control" id="settingsVlanDataId" name="vlan_data_id" placeholder="Empty = disabled" min="1" max="4094">
                  <small class="form-text text-muted">802.1Q VLAN ID for data/PC port traffic. Leave empty to disable.</small>
                </div>
                 <button type="button" class="btn  btn-primary" id="saveVlanBtn"><i class="fa fa-save"></i> Save Settings</button>
               </form>
           </div>
            <div class="section-title" data-for="provisioner_phonebook"><h3><i class="fa fa-minus"></i> Cloud Phonebook</h3></div>
            <div class="section" data-id="provisioner_phonebook">
              <div class="alert alert-info">Select a phone brand, then configure its remote phonebook URL and authentication.</div>
              <form id="phonebookForm">
                <div class="form-group">
                  <label for="settingsPhonebookBrand">Phone Brand</label>
                  <select class="form-control" id="settingsPhonebookBrand">
                    <option value="digium">Digium</option>
                    <option value="alcatel">Alcatel M3s</option>
                  </select>
                </div>
                <div class="form-group">
                  <label for="settingsPhonebookName">Phonebook Name</label>
                  <input type="text" class="form-control" id="settingsPhonebookName" placeholder="Internal Contact">
                </div>
                <div class="form-group">
                  <label for="settingsPhonebookUrl">Phonebook URL</label>
                  <input type="text" class="form-control" id="settingsPhonebookUrl" placeholder="https://example.com/phonebook.xml">
                </div>
                <div class="form-group">
                  <label for="settingsPhonebookUsername">Username</label>
                  <input type="text" class="form-control" id="settingsPhonebookUsername" autocomplete="off">
                </div>
                <div class="form-group">
                  <label for="settingsPhonebookPassword">Password</label>
                  <input type="password" class="form-control" id="settingsPhonebookPassword" autocomplete="new-password">
                </div>
                <button type="button" class="btn btn-primary" id="savePhonebookBtn"><i class="fa fa-save"></i> Save Phonebook</button>
              </form>
            </div>
          </div>
        <div class="col-md-6">
          <div class="section-title" data-for="provisioner_template"><h3><i class="fa fa-minus"></i> Template</h3></div>
          <div class="section" data-id="provisioner_template">
              <div class="form-group">
                <label for="templateBrand">Brand</label>
                <select class="form-control" id="templateBrand">
                  <?php foreach ($brands as $b): ?>
                  <option value="<?= $b ?>"><?= ucfirst($b) ?></option>
                  <?php endforeach; ?>
                </select>
              </div>
              <div class="form-group">
                <label for="templatePass">Admin Password</label>
                <input type="password" class="form-control" id="templatePass" placeholder="Default for brand">
              </div>
              <textarea class="form-control" id="templateContent" rows="15" style="font-family:monospace;font-size:12px;"></textarea>
              <div style="margin-top:8px;">
                <button class="btn  btn-primary" id="saveTemplateBtn"><i class="fa fa-save"></i> Save Template</button>
                <button class="btn  btn-secondary" id="resetTemplateBtn"><i class="fa fa-undo"></i> Reset to Default</button>
              </div>
          </div>
        </div>
      </div>
    </div>

  </div>

        </div>
      </div>
    </div>
  </div>

  <!-- ===================== MODALS ===================== -->

  <!-- Import Review Modal -->
  <div class="modal fade" id="importReviewModal" tabindex="-1" role="dialog" aria-labelledby="importReviewTitle">
    <div class="modal-dialog" role="document">
      <div class="modal-content">
        <div class="modal-header">
          <button type="button" class="close" data-dismiss="modal" aria-label="Close"><span aria-hidden="true">&times;</span></button>
          <h4 class="modal-title" id="importReviewTitle">Review Import</h4>
        </div>
        <div class="modal-body">
          <div id="importReviewError" class="alert alert-danger import-review-error" style="display:none;" role="alert"></div>
          <div id="importReviewLoading" class="import-review-loading">
            <i class="fa fa-spinner fa-spin fa-2x"></i>
            <p style="margin:10px 0 0;">Checking mappings and handset readiness…</p>
          </div>
          <div id="importReviewContent" class="import-review-content" style="display:none;">
            <div id="importReviewSummary" class="import-review-summary" aria-live="polite"></div>
            <div class="import-review-controls">
              <label for="importPreviewFilter">Show</label>
              <select id="importPreviewFilter" class="form-control input-sm" aria-label="Filter import preview">
                <option value="all">All rows</option>
                <option value="invalid">Invalid mappings</option>
                <option value="updated">Changed mappings</option>
                <option value="needs_id">Needs identification</option>
                <option value="new">New mappings</option>
                <option value="unchanged">Unchanged</option>
              </select>
              <span id="importReviewStatus" aria-live="polite"></span>
            </div>
            <div id="importResults"></div>
          </div>
        </div>
        <div class="modal-footer">
          <button type="button" class="btn btn-default" id="cancelImportReviewBtn" data-dismiss="modal">Back to CSV</button>
          <button type="button" class="btn btn-primary" id="commitImportBtn" disabled>Save Mappings</button>
        </div>
      </div>
    </div>
  </div>

  <!-- Add/Edit Device Modal -->
  <div class="modal fade" id="deviceModal" tabindex="-1" role="dialog">
    <div class="modal-dialog" role="document">
      <div class="modal-content">
        <div class="modal-header">
          <h4 class="modal-title" id="deviceModalTitle">Add Device</h4>
          <button type="button" class="close" data-dismiss="modal">&times;</button>
        </div>
        <div class="modal-body">
          <div class="alert alert-danger" id="deviceModalError" style="display:none;"></div>
          <input type="hidden" id="deviceId">
          <div class="form-group">
            <label for="deviceMac">MAC Address</label>
            <input type="text" class="form-control" id="deviceMac" placeholder="00:04:f3:ab:cd:ef">
          </div>
          <div class="form-group">
            <label for="deviceExtension">Extension</label>
            <select class="form-control" id="deviceExtension">
              <option value="">-- Select Extension --</option>
            </select>
          </div>
          <div class="form-group">
            <label for="deviceBrand">Brand</label>
            <select class="form-control" id="deviceBrand">
              <?php foreach ($brands as $b): ?>
              <option value="<?= $b ?>"><?= ucfirst($b) ?></option>
              <?php endforeach; ?>
            </select>
          </div>
          <div class="form-group">
            <label for="deviceDescription">Description</label>
            <input type="text" class="form-control" id="deviceDescription" placeholder="Office Phone">
          </div>
          <div class="form-group">
            <label for="deviceIpMode">IP Assignment</label>
            <select class="form-control" id="deviceIpMode">
              <option value="dhcp" selected>DHCP</option>
              <option value="static">Static</option>
            </select>
          </div>
          <div id="staticFields" style="display:none;">
            <div class="form-group">
              <label for="deviceStaticIp">Static IP</label>
              <input type="text" class="form-control" id="deviceStaticIp" placeholder="192.168.0.50">
            </div>
            <div class="form-group">
              <label for="deviceStaticMask">Subnet Mask</label>
              <div class="input-group">
                <input type="text" class="form-control" id="deviceStaticMask" placeholder="24 or 255.255.255.0">
                <div class="input-group-append">
                  <select class="form-control" id="maskPresets" style="width:auto;">
                    <option value="">Presets</option>
                    <option value="8">/8 = 255.0.0.0</option>
                    <option value="16">/16 = 255.255.0.0</option>
                    <option value="23">/23 = 255.255.254.0</option>
                    <option value="24">/24 = 255.255.255.0</option>
                    <option value="28">/28 = 255.255.255.240</option>
                    <option value="30">/30 = 255.255.252</option>
                    <option value="32">/32 = 255.255.255.255</option>
                  </select>
                </div>
              </div>
              <small class="text-muted">Type CIDR (e.g. <code>24</code>) or dotted, or select from presets</small>
            </div>
            <div class="form-group">
              <label for="deviceStaticGw">Gateway</label>
              <input type="text" class="form-control" id="deviceStaticGw" placeholder="192.168.0.1">
            </div>
          </div>
        </div>
        <div class="modal-footer">
          <button type="button" class="btn btn-secondary" data-dismiss="modal">Cancel</button>
          <button type="button" class="btn btn-primary" id="saveDeviceBtn"><i class="fa fa-save"></i> Save Mapping</button>
        </div>
      </div>
    </div>
  </div>

  <!-- Device Diagnostics Modal -->
  <div class="modal fade" id="deviceDiagnosticsModal" tabindex="-1" role="dialog">
    <div class="modal-dialog modal-lg" role="document">
      <div class="modal-content">
        <div class="modal-header">
          <h4 class="modal-title" id="deviceDiagnosticsTitle">Device Diagnostics</h4>
          <button type="button" class="close" data-dismiss="modal">&times;</button>
        </div>
        <div class="modal-body">
          <div id="deviceDiagnosticsManualIp" style="display:none;"></div>
          <div id="deviceDiagnosticsBody">
            <p class="text-muted">Loading diagnostics...</p>
          </div>
        </div>
        <div class="modal-footer">
          <button type="button" class="btn btn-warning" id="verifyPushStatusBtn" style="display:none;"><i class="fa fa-search"></i> Verify Last Push</button>
          <button type="button" class="btn btn-info" id="runFreshDiagnosticsBtn"><i class="fa fa-refresh"></i> Run Fresh ARP Check</button>
          <button type="button" class="btn btn-secondary" data-dismiss="modal">Close</button>
        </div>
      </div>
    </div>
  </div>

  <!-- Push Config Modal -->
  <div class="modal fade" id="applyModal" tabindex="-1" role="dialog">
    <div class="modal-dialog" role="document">
      <div class="modal-content">
        <div class="modal-header">
          <h4 class="modal-title">Push Config</h4>
          <button type="button" class="btn btn-default" id="openLatestBatchBtn" style="display:none;"><i class="fa fa-file-text-o"></i> View Batch Report</button>
          <button type="button" class="close" data-dismiss="modal">&times;</button>
        </div>
        <div class="modal-body">
          <p id="applyCountDesc">Push config for <strong id="applyCount">0</strong> device(s) with pending changes.</p>
          <p id="applySelectedDesc" style="display:none;"><span id="applySelectedActionVerb">Push config for</span> <strong id="applySelectedCount">0</strong> selected <span id="applySelectedUnit">phones</span>. Pending or missing configs are generated first; extension-swap partners are included automatically.</p>
          <p id="applyNoChangesDesc" style="display:none;">Push config to all active phones (no pending changes).</p>
          <div class="checkbox">
            <label><input type="checkbox" id="applyGenerateCfg" checked> Generate config files</label>
          </div>
          <div class="checkbox" id="applyRebootRow">
            <label><input type="checkbox" id="applyRebootPhones" checked> Reboot phones</label>
          </div>
          <div id="applyNotifyRow" style="display:none;">
            <div class="checkbox">
              <label><input type="checkbox" id="applyNotifyPush" checked> Send SIP NOTIFY push</label>
            </div>
          </div>
          <div id="applyProgress" style="display:none;">
            <div class="progress" style="margin-bottom:8px;">
              <div class="progress-bar progress-bar-striped active" id="applyProgressBar" style="width:0%"></div>
            </div>
            <p class="text-muted" id="applyProgressText">Initializing...</p>
          </div>
          <div id="applyResults" style="display:none;">
            <hr>
            <div id="applyResultsContent"></div>
          </div>
        </div>
        <div class="modal-footer">
          <button type="button" class="btn btn-primary" id="applyOkBtn"><i class="fa fa-paper-plane"></i> Push Config</button>
        </div>
      </div>
    </div>
  </div>

  <!-- Edit Config File Modal -->
  <div class="modal fade" id="configEditModal" tabindex="-1" role="dialog">
    <div class="modal-dialog modal-lg" role="document">
      <div class="modal-content">
        <div class="modal-header">
          <h4 class="modal-title" id="configEditTitle">Edit Config File</h4>
          <button type="button" class="close" data-dismiss="modal">&times;</button>
        </div>
        <div class="modal-body">
          <input type="hidden" id="configEditMac">
          <textarea class="form-control" id="configEditContent" rows="20" style="font-family:monospace;font-size:12px;"></textarea>
        </div>
        <div class="modal-footer">
          <button type="button" class="btn btn-secondary" data-dismiss="modal">Cancel</button>
          <button type="button" class="btn btn-primary" id="saveActiveConfigBtn" style="display:none"><i class="fa fa-save"></i> Override Active Config</button>
          <button type="button" class="btn btn-primary" id="saveConfigBtn"><i class="fa fa-save"></i> Save</button>
        </div>
      </div>
    </div>
  </div>

  <!-- Pull Config Progress Modal -->
  <div class="modal fade" id="pullProgressModal" tabindex="-1" role="dialog" data-backdrop="static" data-keyboard="false">
    <div class="modal-dialog" role="document">
      <div class="modal-content">
        <div class="modal-header">
          <h4 class="modal-title" id="pullProgressTitle">Pulling Config from Phones</h4>
        </div>
        <div class="modal-body">
          <div class="progress" style="height:24px;margin-bottom:12px;">
            <div id="pullProgressBar" class="progress-bar progress-bar-striped active" role="progressbar" style="width:0%;">0 / 0</div>
          </div>
          <div id="pullProgressSummary" class="text-muted" style="margin-bottom:8px;"></div>
          <div style="max-height:300px;overflow-y:auto;">
          <table class="table table-sm table-condensed" id="pullProgressTable" style="margin-bottom:0;">
            <thead><tr><th>MAC</th><th>IP</th><th>Status</th></tr></thead>
            <tbody id="pullProgressBody"></tbody>
          </table>
          </div>
        </div>
        <div class="modal-footer">
          <button type="button" class="btn btn-warning" id="pullRetryFailedBtn" style="display:none;"><i class="fa fa-repeat"></i> Retry Failed</button>
          <button type="button" class="btn btn-secondary" data-dismiss="modal" id="pullCloseBtn">Close</button>
        </div>
      </div>
    </div>
  </div>

</div>
