<div id="bulkReportsSection" class="logs-section" style="display:none;">
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
        <div class="bulk-report-header-status">
          <button type="button" class="btn btn-default btn-sm" id="batchReportLiveToggleBtn" aria-pressed="false" title="Pause or resume report refreshes only; the provisioning job continues.">
            <i class="fa fa-pause"></i> Live Updates · Paused
          </button>
          <small id="batchReportPollStatus" class="bulk-report-poll-status" aria-live="polite"></small>
          <span id="batchReportStatus" class="bulk-status neutral"></span>
        </div>
      </div>
      <div class="bulk-report-subnav-bar">
        <div class="btn-group bulk-report-view-switcher" role="tablist" aria-label="Batch report views">
          <button type="button" class="btn btn-default active" id="batchReportOverviewTab" role="tab" aria-selected="true"><i class="fa fa-tachometer"></i> Overview</button>
          <button type="button" class="btn btn-default" id="batchReportPhonesTab" role="tab" aria-selected="false"><i class="fa fa-list"></i> Phones <span id="batchReportPhonesCount" class="badge">0</span></button>
        </div>
        <div class="bulk-report-control-group">
          <button type="button" class="btn btn-default" id="refreshBatchReportBtn"><i class="fa fa-refresh"></i> Refresh</button>
          <button type="button" class="btn btn-default" id="downloadBatchCsvBtn"><i class="fa fa-download"></i> Download CSV</button>
          <button type="button" class="btn btn-warning" id="retryBatchBtn" disabled title="Retry eligible phones in this batch, including required swap partners"><i class="fa fa-repeat"></i> <span id="retryBatchLabel">Retry Eligible Phones</span> <span id="retryBatchCount" class="badge">0</span></button>
        </div>
      </div>
      <div id="batchReportRecovery" class="alert alert-info" style="display:none;" role="status" aria-live="polite"></div>
      <div id="batchReportLinks" class="bulk-report-links" style="display:none;"></div>
      <div id="batchReportOverviewView" role="tabpanel" aria-labelledby="batchReportOverviewTab">
        <div id="batchReportOverview" aria-live="polite"></div>
      </div>
      <div id="batchReportPhonesView" role="tabpanel" aria-labelledby="batchReportPhonesTab" style="display:none;">
        <div id="batchReportSummary" class="provisioner-summary-group bulk-report-summary" aria-live="polite"></div>
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
              <span id="batchReportStageFilterChip" class="bulk-report-stage-chip" style="display:none;">
                <span id="batchReportStageFilterText"></span>
                <button type="button" class="btn btn-link btn-xs" id="clearBatchReportStageFilterBtn" style="padding:0 2px;color:#1d6fa5;" title="Clear stage filter"><i class="fa fa-times"></i></button>
              </span>
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
