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
#provisioner-app .bulk-report-controls { display:flex; align-items:center; justify-content:space-between; flex-wrap:wrap; gap:6px 12px; margin:0; }
#provisioner-app .bulk-report-control-group { display:flex; align-items:center; flex-wrap:wrap; gap:6px; }
#provisioner-app .bulk-report-toolbar { width:100%; }
#provisioner-app .bulk-report-toolbar label { margin:0; font-weight:600; font-size:12px; color:#5b6570; }
#provisioner-app .bulk-report-toolbar .provisioner-filter-select { min-height:32px; margin:0; }
#provisioner-app #bulkReportsSection code { padding:2px 6px; border:1px solid #d9e0e6; border-radius:3px; background:#f1f5f9; color:#334155; font-size:12px; }
#provisioner-app #bulkReportsSection .fixed-table-toolbar { display:flex; flex-wrap:wrap; align-items:center; justify-content:space-between; gap:8px 12px; margin:0 0 10px; }
#provisioner-app #bulkReportsSection .fixed-table-toolbar::after { display:none; }
#provisioner-app #bulkReportsSection .fixed-table-toolbar .bs-bars { flex:1 1 auto; width:auto; float:none !important; margin:0; }
#provisioner-app #bulkReportsSection .fixed-table-toolbar .search { flex:0 0 auto; max-width:100%; margin:0; float:none !important; }
#provisioner-app #bulkReportsSection .fixed-table-toolbar .search input { min-height:32px; width:260px; max-width:100%; }
#provisioner-app .bulk-report-history-table,
#provisioner-app .bulk-report-table { margin:0; }
#provisioner-app .bulk-report-history-table th,
#provisioner-app .bulk-report-history-table td,
#provisioner-app .bulk-report-table th,
#provisioner-app .bulk-report-table td { vertical-align:middle; overflow-wrap:anywhere; }
#provisioner-app .bulk-report-table > tbody > tr.detail-view > td { padding:10px 12px; background:#f1f5f8; border-top:0; }
#provisioner-app .bulk-report-history-table .bulk-report-open { white-space:nowrap; }
#provisioner-app .bulk-report-meta { display:block; margin-top:4px; color:#5b6570; }
#provisioner-app .bulk-status { display:inline-block; padding:3px 8px; border-radius:3px; font-size:12px; font-weight:600; border:1px solid transparent; }
#provisioner-app .bulk-status.complete { color:#2e6b30; background:#eff8ef; border-color:#c3e6cb; }
#provisioner-app .bulk-status.in-progress { color:#1d6fa5; background:#eef8fc; border-color:#bee5eb; }
#provisioner-app .bulk-status.attention { color:#8a6d3b; background:#fcf8e3; border-color:#faebcc; }
#provisioner-app .bulk-status.neutral { color:#5b6570; background:#f1f3f5; border-color:#d9e0e6; }
#provisioner-app .bulk-report-panel { margin-top:12px; }
#provisioner-app .bulk-report-header { display:flex; align-items:center; justify-content:space-between; flex-wrap:wrap; gap:10px; margin:10px 0 12px; padding:12px 16px; border:1px solid #d9e0e6; border-left:4px solid #4c9471; border-radius:4px; background:#f8fafb; }
#provisioner-app .bulk-report-header.is-attention { border-left-color:#f0ad4e; }
#provisioner-app .bulk-report-header.is-progress { border-left-color:#287da8; }
#provisioner-app .bulk-report-title { min-width:0; }
#provisioner-app .bulk-report-title h4 { margin:0 0 4px; font-size:17px; font-weight:600; color:#253545; }
#provisioner-app .bulk-report-title small { color:#5b6570; font-size:12px; }
#provisioner-app .bulk-report-header-status { display:flex; align-items:center; flex-wrap:wrap; gap:10px; }
#provisioner-app .bulk-report-poll-status { color:#5b6570; font-size:12px; display:inline-flex; align-items:center; gap:5px; }
#provisioner-app .bulk-report-poll-status.is-error { color:#a94442; }
#provisioner-app .bulk-report-kind { margin-right:5px; }
#provisioner-app .bulk-report-links { display:flex; align-items:center; flex-wrap:wrap; gap:6px; margin:0 0 10px; color:#5b6570; }
#provisioner-app .bulk-report-summary { display:grid; grid-template-columns:repeat(4, minmax(130px, 1fr)); gap:10px; margin:0 0 12px; }
#provisioner-app .bulk-report-summary .provisioner-summary-card { display:flex; flex-direction:column; align-items:flex-start; justify-content:center; padding:10px 14px; margin:0; border:1px solid #d9e0e6; border-left:4px solid #6c757d; border-radius:4px; background:#fff; cursor:pointer; text-align:left; transition:background-color .12s ease, border-color .12s ease, box-shadow .12s ease; }
#provisioner-app .bulk-report-summary .provisioner-summary-card:hover,
#provisioner-app .bulk-report-summary .provisioner-summary-card:focus { background:#f8fafb; box-shadow:0 1px 3px rgba(0,0,0,.06); outline:none; }
#provisioner-app .bulk-report-summary .provisioner-summary-card.is-active { background:#f3f9fc; border-color:#337ab7; box-shadow:0 1px 4px rgba(51,122,183,.15); }
#provisioner-app .bulk-report-summary .provisioner-summary-card.summary-completed { border-left-color:#5cb85c; }
#provisioner-app .bulk-report-summary .provisioner-summary-card.summary-progress { border-left-color:#287da8; }
#provisioner-app .bulk-report-summary .provisioner-summary-card.summary-attention { border-left-color:#f0ad4e; }
#provisioner-app .bulk-report-summary .provisioner-summary-card strong { display:block; font-size:18px; font-weight:700; color:#253545; line-height:1.15; margin:0 0 2px; }
#provisioner-app .bulk-report-summary .provisioner-summary-card small { font-size:12px; color:#5b6570; }
#provisioner-app .bulk-report-subnav-bar { display:flex; align-items:center; justify-content:space-between; flex-wrap:wrap; gap:8px 12px; margin:0 0 12px; padding:8px 12px; border:1px solid #d9e0e6; border-radius:4px; background:#f8fafb; }
#provisioner-app .bulk-report-view-switcher .btn { font-weight:600; color:#4b5563; background:#fff; border-color:#cbd5e1; }
#provisioner-app .bulk-report-view-switcher .btn.active { color:#1d4e78; background:#eaf4fb; border-color:#337ab7; box-shadow:inset 0 -2px 0 #337ab7; }
#provisioner-app .bulk-report-view-switcher .btn .badge { margin-left:4px; background:#e2e8f0; color:#253545; }
#provisioner-app .bulk-report-view-switcher .btn.active .badge { background:#337ab7; color:#fff; }
#provisioner-app .bulk-overview-section-title { margin:0 0 8px; font-size:13px; font-weight:700; text-transform:uppercase; letter-spacing:.03em; color:#4b5563; display:flex; align-items:center; justify-content:space-between; gap:8px; }
#provisioner-app .bulk-overview-section-title small { text-transform:none; letter-spacing:normal; font-weight:normal; color:#5b6570; font-size:12px; }
#provisioner-app .bulk-overview-funnel { display:grid; grid-template-columns:repeat(auto-fit, minmax(175px, 1fr)); gap:10px; margin:0 0 14px; }
#provisioner-app .bulk-funnel-card { padding:12px 14px; border:1px solid #d9e0e6; border-top:3px solid #cbd5e1; border-radius:4px; background:#fff; display:flex; flex-direction:column; gap:6px; min-width:0; text-align:left; cursor:pointer; transition:border-color .12s ease, box-shadow .12s ease, background-color .12s ease; }
#provisioner-app .bulk-funnel-card:hover,
#provisioner-app .bulk-funnel-card:focus { background:#f8fafb; border-color:#94a3b8; box-shadow:0 1px 4px rgba(0,0,0,.06); outline:none; }
#provisioner-app .bulk-funnel-card.is-done { border-top-color:#5cb85c; }
#provisioner-app .bulk-funnel-card.is-active { border-top-color:#287da8; }
#provisioner-app .bulk-funnel-card.is-failed { border-top-color:#f0ad4e; }
#provisioner-app .bulk-funnel-head { display:flex; align-items:baseline; justify-content:space-between; gap:6px; }
#provisioner-app .bulk-funnel-step { font-size:11px; font-weight:700; text-transform:uppercase; letter-spacing:.03em; color:#5b6570; }
#provisioner-app .bulk-funnel-pct { font-size:12px; font-weight:700; color:#253545; }
#provisioner-app .bulk-funnel-count { font-size:18px; font-weight:700; color:#253545; line-height:1.15; }
#provisioner-app .bulk-funnel-count small { font-size:12px; font-weight:normal; color:#5b6570; margin-left:3px; }
#provisioner-app .bulk-funnel-bar { display:flex; width:100%; height:7px; border-radius:4px; background:#e9ecef; overflow:hidden; margin:2px 0; }
#provisioner-app .bulk-funnel-bar > span { display:block; height:100%; }
#provisioner-app .bulk-funnel-bar .bar-done { background:#5cb85c; }
#provisioner-app .bulk-funnel-bar .bar-active { background:#287da8; }
#provisioner-app .bulk-funnel-bar .bar-failed { background:#d9534f; }
#provisioner-app .bulk-funnel-meta { display:flex; flex-wrap:wrap; gap:4px 10px; font-size:11px; color:#5b6570; }
#provisioner-app .bulk-funnel-meta .is-done { color:#2e6b30; font-weight:600; }
#provisioner-app .bulk-funnel-meta .is-active { color:#1d6fa5; font-weight:600; }
#provisioner-app .bulk-funnel-meta .is-failed { color:#a94442; font-weight:600; }
#provisioner-app .bulk-funnel-meta .is-muted { color:#6c757d; }
#provisioner-app .bulk-overview-grid { display:grid; grid-template-columns:minmax(0, 1.35fr) minmax(0, 1fr); gap:14px; margin:0 0 12px; }
#provisioner-app .bulk-overview-card { padding:14px 16px; border:1px solid #d9e0e6; border-radius:4px; background:#fff; min-width:0; }
#provisioner-app .bulk-overview-card h5 { margin:0 0 10px; padding-bottom:8px; border-bottom:1px solid #eef2f5; font-size:14px; font-weight:600; color:#253545; display:flex; align-items:center; justify-content:space-between; gap:8px; }
#provisioner-app .bulk-overview-list { display:flex; flex-direction:column; gap:8px; margin:0; padding:0; list-style:none; }
#provisioner-app .bulk-overview-item { display:flex; align-items:flex-start; justify-content:space-between; gap:10px; padding:9px 11px; border:1px solid #e2e8f0; border-left:3px solid #94a3b8; border-radius:4px; background:#f8fafb; }
#provisioner-app .bulk-overview-item.is-attention { border-left-color:#f0ad4e; background:#fffdf8; }
#provisioner-app .bulk-overview-item.is-progress { border-left-color:#287da8; background:#f6fbfe; }
#provisioner-app .bulk-overview-item.is-completed { border-left-color:#5cb85c; background:#f9fdf9; }
#provisioner-app .bulk-overview-item-body { min-width:0; flex:1 1 auto; }
#provisioner-app .bulk-overview-item-title { display:flex; flex-wrap:wrap; align-items:center; gap:6px 8px; font-weight:600; color:#253545; font-size:13px; }
#provisioner-app .bulk-overview-item-desc { display:block; margin-top:3px; font-size:12px; color:#5b6570; overflow-wrap:anywhere; }
#provisioner-app .bulk-overview-item-samples { display:block; margin-top:3px; font-size:11px; color:#64748b; }
#provisioner-app .bulk-overview-item-actions { flex:0 0 auto; display:flex; align-items:center; gap:6px; white-space:nowrap; }
#provisioner-app .bulk-overview-kv { display:grid; grid-template-columns:repeat(2, minmax(0, 1fr)); gap:8px 12px; margin:0 0 12px; padding-bottom:12px; border-bottom:1px solid #eef2f5; }
#provisioner-app .bulk-overview-kv-item { padding:7px 10px; border:1px solid #e2e8f0; border-radius:3px; background:#f8fafb; min-width:0; }
#provisioner-app .bulk-overview-kv-item small { display:block; font-size:11px; color:#5b6570; }
#provisioner-app .bulk-overview-kv-item strong { display:block; font-size:13px; color:#253545; overflow-wrap:anywhere; margin-top:1px; }
#provisioner-app .bulk-overview-subtable { width:100%; margin:0 0 10px; font-size:12px; }
#provisioner-app .bulk-overview-subtable th,
#provisioner-app .bulk-overview-subtable td { padding:5px 6px; border-bottom:1px solid #eef2f5; vertical-align:middle; }
#provisioner-app .bulk-overview-subtable th { color:#5b6570; font-weight:600; font-size:11px; text-transform:uppercase; }
#provisioner-app .bulk-report-stage-chip { display:inline-flex; align-items:center; gap:6px; padding:4px 9px; border:1px solid #bee5eb; border-radius:3px; background:#eef8fc; color:#1d6fa5; font-size:12px; font-weight:600; }
#provisioner-app .bulk-report-table { width:100%; min-width:0; }
#provisioner-app .bulk-report-result-top { display:flex; flex-wrap:wrap; align-items:center; gap:6px 10px; }
#provisioner-app .bulk-report-inline-stages { display:inline-flex; flex-wrap:wrap; align-items:center; gap:3px 6px; padding:2px 7px; border:1px solid #e2e8f0; border-radius:3px; background:#f8fafb; }
#provisioner-app .bulk-report-result-reason,
#provisioner-app .bulk-report-result-next { display:block; margin-top:4px; max-width:520px; white-space:normal; overflow-wrap:anywhere; color:#5b6570; line-height:1.35; }
#provisioner-app .bulk-report-result-next { color:#374151; }
#provisioner-app .bulk-report-row-actions { display:flex; flex-wrap:wrap; gap:6px; margin-top:7px; }
#provisioner-app .bulk-report-recovery-help { margin-top:10px; }
#provisioner-app .bulk-report-recovery-help summary { cursor:pointer; font-weight:600; }
#provisioner-app .bulk-report-recovery-help ol { margin-top:8px; padding-left:22px; }
#provisioner-app .bulk-report-table .bulk-report-phone code,
#provisioner-app .bulk-report-table .bulk-report-target code { overflow-wrap:anywhere; word-break:break-word; }
#provisioner-app .bulk-report-detail { padding:12px 16px; border:1px solid #d9e0e6; border-left:4px solid #4c9471; border-radius:4px; background:#fff; overflow-wrap:anywhere; box-shadow:0 1px 2px rgba(0,0,0,.03); }
#provisioner-app .bulk-report-detail.is-attention { border-left-color:#f0ad4e; }
#provisioner-app .bulk-report-detail.is-progress { border-left-color:#287da8; }
#provisioner-app .bulk-report-detail-header { display:flex; align-items:center; justify-content:space-between; flex-wrap:wrap; gap:8px; margin:0 0 10px; padding-bottom:8px; border-bottom:1px solid #eef2f5; }
#provisioner-app .bulk-report-detail h5 { margin:0; font-size:14px; font-weight:600; color:#253545; }
#provisioner-app .bulk-report-detail p { margin:8px 0 0; }
#provisioner-app .bulk-report-stage-cards { display:grid; grid-template-columns:repeat(5, minmax(0, 1fr)); gap:10px; margin:10px 0; }
#provisioner-app .bulk-report-stage-card { padding:9px 11px; border:1px solid #d9e0e6; border-top:3px solid #cbd5e1; border-radius:3px; background:#f8fafb; display:flex; flex-direction:column; gap:3px; min-width:0; }
#provisioner-app .bulk-report-stage-card.is-done { border-top-color:#5cb85c; background:#fff; }
#provisioner-app .bulk-report-stage-card.is-failed { border-top-color:#d9534f; background:#fffafa; }
#provisioner-app .bulk-report-stage-card.is-active { border-top-color:#287da8; background:#f4fafe; }
#provisioner-app .bulk-report-stage-step { font-size:11px; font-weight:600; text-transform:uppercase; letter-spacing:.02em; color:#5b6570; }
#provisioner-app .bulk-report-stage-meta { font-size:11px; color:#5b6570; line-height:1.3; }
#provisioner-app .bulk-report-meta-strip { display:flex; flex-wrap:wrap; align-items:center; gap:6px 18px; margin-top:10px; padding-top:8px; border-top:1px solid #eef2f5; font-size:12px; color:#5b6570; }
#provisioner-app .bulk-report-meta-strip strong { color:#354555; }
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
  #provisioner-app .bulk-report-summary { grid-template-columns:repeat(2, minmax(0, 1fr)); }
  #provisioner-app .bulk-overview-funnel { grid-template-columns:repeat(2, minmax(0, 1fr)); }
  #provisioner-app .bulk-overview-grid { grid-template-columns:1fr; }
  #provisioner-app .bulk-overview-kv { grid-template-columns:1fr; }
  #provisioner-app .bulk-report-stage-cards { grid-template-columns:repeat(2, minmax(0, 1fr)); }
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
      <div id="mappingReportReturn" class="bulk-report-controls" style="display:none; margin-bottom:12px; padding:10px 12px; border:1px solid #bee5eb; border-radius:4px; background:#eef8fc;">
        <span id="mappingReportHint" style="color:#1d6fa5; font-size:13px;"></span>
        <button type="button" class="btn btn-default btn-sm" id="returnToMappingReportBtn"><i class="fa fa-file-text-o"></i> Return to Batch Report</button>
      </div>
      <div class="row" style="margin-top:20px;">
        <div class="col-md-12">
          <div class="table-responsive">
              <div id="deviceToolbar" class="device-toolbar">
                <button class="btn btn-add btn-sm" id="addDeviceBtn" title="Manually add a device to MAC Mapping"><i class="fa fa-plus"></i> Add Device</button>
                <button class="btn btn-success btn-sm" id="autoAssignBtn" title="Auto-assign next available extension to devices without one"><i class="fa fa-magic"></i> Auto-Assign</button>
                <button class="btn btn-danger btn-sm" id="deleteSelectedBtn" disabled title="Delete selected devices from MAC Mapping"><i class="fa fa-trash"></i> Delete</button>
                <button class="btn btn-secondary btn-sm" id="discardAllBtn" style="display:none;" title="Discard all pending changes on all devices">
                  <i class="fa fa-undo"></i> Discard All
                </button>
                <button class="btn btn-default btn-sm action-button" id="applyChangesBtn" disabled title="Select mappings to push. Missing handset identity is explained in Batch Reports.">
                  <i class="fa fa-paper-plane"></i> <span class="push-action-label">Push Config</span> <span id="pendingBadge" class="badge badge-light" style="display:none;">0</span>
                </button>
              </div>
              <table id="devicesTable"
                     data-url="ajax.php?module=provisioner&amp;command=getDeviceGrid"
                     data-toolbar="#deviceToolbar"
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
                <div id="discoveryPushHint" class="alert alert-info" style="display:none;" role="status"></div>
                <div id="discoveryReportReturn" class="bulk-report-controls" style="display:none; margin-bottom:12px;">
                  <button type="button" class="btn btn-default" id="returnToDiscoveryReportBtn"><i class="fa fa-file-text-o"></i> Return to Batch Report</button>
                  <button type="button" class="btn btn-default" id="discoveryReportPhoneStatusBtn"><i class="fa fa-phone"></i> Open Phone Status</button>
                </div>
                <div id="scanStatus" style="display:none;margin-bottom:8px;"></div>
                <div id="callbackTestResult" style="display:none; margin-bottom:8px;" role="status" aria-live="polite"></div>
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
                  <button type="button" class="btn btn-default" id="testDiscoveryCallbackBtn" title="Check the PBX identity receiver without contacting or changing phones"><i class="fa fa-stethoscope"></i> Test Callback Access</button>
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
        <div class="col-sm-12">
          <ul class="nav nav-tabs bulk-handler-nav" role="tablist" aria-label="Logs views">
            <li role="presentation" class="active"><a href="#activityLogsSection" class="logs-pill active">Activity Logs</a></li>
            <li role="presentation"><a href="#bulkReportsSection" class="logs-pill" id="batchHistoryTab">Batch Reports</a></li>
          </ul>
          <?php include __DIR__ . '/batch_reports.php'; ?>
          <div id="activityLogsSection" class="logs-section">
            <div class="bulk-section-header">
              <h3>Activity Logs</h3>
              <p>Review provisioning, discovery, configuration pull, and push activity.</p>
            </div>
            <div id="activityLogsError" class="alert alert-danger" role="alert" style="display:none;">
              Could not load activity logs. Previously loaded rows may be out of date.
              <button type="button" class="btn btn-default btn-sm" id="retryActivityLogsBtn">Try Again</button>
            </div>
          <div class="table-responsive">
              <div id="logsToolbar">
                <button class="btn btn-danger" id="clearLogsBtn">
                  <i class="fa fa-trash"></i> Clear Logs
                </button>
              </div>
              <table id="logsTable"
                     data-url="ajax.php?module=provisioner&amp;command=getLogGrid"
                     data-toolbar="#logsToolbar"
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
                  <p class="help-block" id="settingsScanDetection">Detecting subnet from the selected PBX interface...</p>
                </div>
                <details id="settingsPushAdvanced" class="bulk-report-recovery-help">
                  <summary>Advanced settings</summary>
                  <p class="help-block">Optional rollout tuning and approved automatic search networks. Existing values apply while this section is closed.</p>
                  <div class="form-group">
                  <label for="settingsRolloutWaveSize">Phones per wave</label>
                  <input type="number" class="form-control" id="settingsRolloutWaveSize" name="rollout_wave_size" min="1" max="30" value="25" aria-describedby="settingsRolloutWaveHelp">
                  <p id="settingsRolloutWaveHelp" class="help-block">How many phones are processed together (1–30; default 25). Selecting 300 phones with 25 per wave creates 12 groups. The next group starts only after the current group verifies; an incomplete group stops for review. Extension-swap partners stay together, so the limit must fit the whole swap group.</p>
                  <label for="settingsRolloutSettleSeconds">Wait after config downloads (seconds)</label>
                  <input type="number" class="form-control" id="settingsRolloutSettleSeconds" name="rollout_settle_seconds" min="5" max="120" value="20" aria-describedby="settingsRolloutSettleHelp">
                  <p id="settingsRolloutSettleHelp" class="help-block">Extra pause after a wave's downloads, before checking final registration, MAC and IP. This gives phones time to apply settings and register again (5–120 seconds; default 20). Verification can keep waiting after this pause; this is not the overall verification timeout.</p>
                  <label for="settingsPushSearchSubnets">Networks allowed for automatic phone search</label>
                  <input type="text" class="form-control" id="settingsPushSearchSubnets" name="push_search_subnets" placeholder="Optional: 192.168.188.0/24" aria-describedby="settingsPushSearchHelp settingsPushSearchExample">
                  <p id="settingsPushSearchHelp" class="help-block">Fallback search area for Push Config when known phone IPs do not identify the handset. Leave blank to try known IPs only. This does not change phone IP settings or start a search when saved.</p>
                  <p id="settingsPushSearchExample" class="help-block">Example: <code>192.168.188.0/24</code> allows searching <code>192.168.188.1–254</code>. Enter the phone network you want searched, not automatically the PBX network.</p>
                  <details class="bulk-report-recovery-help"><summary>Search format, limits and safety</summary><p class="help-block">Use CIDR notation; separate up to two private IPv4 ranges with commas. Each range must be /22 or smaller. No subnet mask is guessed. Searching may identify other supported responders in the allowed range, but unselected phones are never provisioned.</p></details>
                  <div id="pushSearchReportReturn" style="display:none;"><button type="button" class="btn btn-default" id="pushSearchReturnToReportBtn">Return to Batch Report</button><p class="help-block">Save the range, return to the report and use Try Finding Again. Nothing resumes or pushes automatically.</p></div>
                  </div>
                </details>
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
          <div id="diagnosticCopyFallback" style="display:none; margin-top:12px;">
            <label for="diagnosticCopyText">Diagnostic summary — select and copy</label>
            <textarea id="diagnosticCopyText" class="form-control" rows="10" readonly></textarea>
          </div>
          <div id="manualVerificationNotice" class="alert alert-info" style="display:none;" role="status"></div>
          <p id="diagnosticCopyStatus" class="text-muted" role="status" aria-live="polite"></p>
        </div>
        <div class="modal-footer">
          <button type="button" class="btn btn-default" id="copyDiagnosticSummaryBtn" disabled><i class="fa fa-copy"></i> Copy Diagnostic Summary</button>
          <button type="button" class="btn btn-info" id="diagnosticDiscoveryBtn" style="display:none;"><i class="fa fa-search"></i> Open Device Discovery</button>
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
          <p class="alert alert-info">This job checks handset identity before delivery. Routed phones without matching evidence may receive SIP probes and an identification-only callback request. Wider discovery uses only approved automatic search ranges and may identify other responders; it never provisions unselected phones. Local ARP is a best-effort refresh, not a new push requirement.</p>
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
          <button type="button" class="btn btn-warning" id="retryDeliveryFailuresBtn" style="display:none;">Retry Delivery Failures</button>
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
