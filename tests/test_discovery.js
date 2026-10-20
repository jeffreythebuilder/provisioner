'use strict';
const assert = require('node:assert/strict');
const fs = require('node:fs');
const vm = require('node:vm');
const Discovery = require('../assets/js/discovery.js');
const LiveStatus = require('../assets/js/live-status.js');

function fixture(respond) {
  const nodes = new Map(), requests = [], timers = [], downloads = [], storage = {'provisioner-live-updates': 'off'};
  const timerCallbacks = new Map();
  let lastBlob;
  const document = {hidden: false, createElement() { return {click() { downloads.push({filename: this.download, blob: lastBlob}); }}; }};
  class Blob { constructor(parts, options) { this.parts = parts; this.options = options; } }
  const URL = {createObjectURL(blob) { lastBlob = blob; return 'blob:test'; }, revokeObjectURL() {}};
  const window = {ProvisionerDiscovery: Discovery, ProvisionerLiveStatus: LiveStatus,
    localStorage: {getItem(key) { return storage[key] || null; }, setItem(key, value) { storage[key] = value; }},
    setTimeout(fn) {
      const id = timerCallbacks.size + 1;
      const callback = () => { if (!callback.cancelled) fn(); };
      timerCallbacks.set(id, callback); timers.push(callback); return id;
    }, clearTimeout(id) { if (timerCallbacks.has(id)) timerCallbacks.get(id).cancelled = true; }};
  function tableData(node) {
    if (!node.tableOptions) return node.rows;
    const options = node.tableOptions;
    let rows = options.customSearch ? options.customSearch(node.rows, options.searchText, node.filterBy || {}) : node.rows;
    if (options.sortName) {
      rows = [...rows].sort((a, b) => String(a[options.sortName] || '').localeCompare(String(b[options.sortName] || ''), undefined, {numeric: true}) * (options.sortOrder === 'desc' ? -1 : 1));
    }
    return rows;
  }
  function renderTable(node) {
    const options = node.tableOptions;
    if (options) {
      const rows = tableData(node);
      const total = options.sidePagination === 'server' ? node.totalRows : rows.length;
      options.pageNumber = Math.max(1, Math.min(options.pageNumber, Math.ceil(total / options.pageSize) || 1));
      node.expanded = new Set();
      if (options.onPostBody) options.onPostBody();
    }
    emit(node, 'post-body.bs.table');
  }
  function requestTable(node) {
    const options = node.tableOptions;
    if (!options || !options.ajax) { renderTable(node); return; }
    options.ajax({data: {offset: (options.pageNumber - 1) * options.pageSize, limit: options.pageSize,
      search: options.searchText, sort: options.sortName, order: options.sortOrder},
      success(res) { node.rows = res.rows; node.totalRows = res.total; renderTable(node); },
      error() { node.rows = []; node.totalRows = 0; renderTable(node); }});
  }
  function $(selector) {
    if (selector && selector._node) return selector;
    if (nodes.has(selector)) return nodes.get(selector).proxy;
    const node = {selector, handlers: new Map(), data: {}, props: {}, rows: [], text: '', value: '', visible: false,
      classes: new Set(selector === '#phones' ? ['active'] : [])};
    const proxy = new Proxy({}, {get(target, key) {
      if (key === '_node') return node;
      if (key === 'length') return 0;
      if (key === 'ready') return fn => { fn(); return proxy; };
      if (key === 'on' || key === 'one') return (event, ...args) => { node.handlers.set(event, args.at(-1)); return proxy; };
      if (key === 'find') return child => $(String(selector) + ' ' + child);
      if (key === 'data' || key === 'prop' || key === 'attr') return (name, value) => {
        const store = key === 'data' ? node.data : node.props;
        if (value === undefined) return store[name];
        store[name] = value; return proxy;
      };
      if (key === 'val') return value => { if (value === undefined) return node.value; node.value = value; return proxy; };
      if (key === 'text') return value => { if (value === undefined) return node.text; node.text = String(value); return proxy; };
      if (key === 'html') return value => {
        if (value === undefined) return node.text.replace(/&/g, '&amp;').replace(/</g, '&lt;').replace(/>/g, '&gt;').replace(/"/g, '&quot;');
        node.text = value; return proxy;
      };
      if (key === 'show' || key === 'hide') return () => { node.visible = key === 'show'; return proxy; };
      if (key === 'toggle') return value => { node.visible = value === undefined ? !node.visible : !!value; return proxy; };
      if (key === 'hasClass') return name => node.classes.has(name);
      if (key === 'is') return () => node.visible;
      if (key === 'trigger') return event => { emit(node, event); return proxy; };
      if (key === 'bootstrapTable') return (method, argument) => {
        node.tableCalls = node.tableCalls || [];
        node.tableCalls.push({method, argument});
        if (method && typeof method === 'object') {
          node.initializations = (node.initializations || 0) + 1;
          node.tableOptions = Object.assign({pageNumber: 1, pageSize: 10, searchText: '', sortOrder: 'asc'}, method);
          node.rows = method.data || [];
          requestTable(node); return proxy;
        }
        if (method === 'getOptions') return node.tableOptions;
        if (method === 'getData') {
          if (!node.tableOptions || argument && argument.unfiltered) return node.rows;
          const rows = tableData(node), options = node.tableOptions;
          return argument && argument.useCurrentPage && options.sidePagination !== 'server'
            ? rows.slice((options.pageNumber - 1) * options.pageSize, options.pageNumber * options.pageSize) : rows;
        }
        if (method === 'getSelections') return node.rows.filter(row => row.state);
        if (method === 'filterBy') { node.filterBy = argument; if (node.tableOptions) node.tableOptions.pageNumber = 1; renderTable(node); return proxy; }
        if (method === 'load') { node.rows = Array.isArray(argument) ? argument : argument.rows; renderTable(node); }
        if (method === 'refresh') requestTable(node);
        if (method === 'resetSearch' && node.tableOptions) { node.tableOptions.searchText = argument; node.tableOptions.pageNumber = 1; requestTable(node); }
        if (method === 'selectPage' && node.tableOptions && node.tableOptions.pageNumber !== argument) { node.tableOptions.pageNumber = argument; requestTable(node); }
        if (method === 'toggleDetailView' || method === 'expandRowByUniqueId') {
          const options = node.tableOptions;
          const rows = tableData(node);
          const row = method === 'toggleDetailView' ? rows[argument] : rows.find(row => row[options.uniqueId] === argument);
          node.expanded = node.expanded || new Set();
          const collapse = method === 'toggleDetailView' && node.expanded.has(row.mac);
          if (collapse) node.expanded.delete(row.mac); else node.expanded.add(row.mac);
          const callback = collapse ? options.onCollapseRow : options.onExpandRow;
          if (callback) callback(rows.indexOf(row), row);
        }
        if (method === 'uncheckAll') { node.rows.forEach(row => { row.state = false; }); emit(node, 'uncheck-all.bs.table'); }
        if (method === 'updateByUniqueId') Object.assign(node.rows.find(row => row.id === argument.id) || {}, argument.row);
        return proxy;
      };
      return () => proxy;
    }});
    node.proxy = proxy; nodes.set(selector, node); return proxy;
  }
  function emit(node, event) {
    for (const [events, handler] of node.handlers) if (events.split(' ').includes(event)) handler.call(node.proxy);
    for (const other of nodes.values()) {
      if (other !== node && typeof other.selector === 'string' && other.selector.includes(',') && other.selector.split(',').map(value => value.trim()).includes(node.selector)) {
        for (const [events, handler] of other.handlers) if (events.split(' ').includes(event)) handler.call(node.proxy);
      }
    }
  }
  $.each = (values, fn) => Object.entries(values).forEach(([key, value]) => fn(key, value));
  $.extend = Object.assign;
  $.ajax = options => {
    const command = options.data.command || options.url.split('command=')[1];
    requests.push(JSON.parse(JSON.stringify(Object.assign({command}, options.data))));
    const response = respond(command, options.data);
    const request = {done(fn) { if (typeof response === 'function') response(fn); else if (response !== undefined) fn(response); return request; }, fail() { return request; }};
    return request;
  };
  vm.runInNewContext(fs.readFileSync(require.resolve('../assets/js/provisioner.js'), 'utf8'),
    {$, window, document, Blob, URL, console, setTimeout: window.setTimeout, clearTimeout: window.clearTimeout});
  return {$, window, nodes, requests, timers, downloads, storage,
    click(selector) { emit($(selector)._node, 'click'); },
    tableAction(selector, action, row) {
      const table = $(selector)._node;
      const column = table.tableOptions.columns.find(column => column.events && column.events['click ' + action]);
      column.events['click ' + action]({preventDefault() {}}, undefined, row, tableData(table).indexOf(row));
    },
    enterDiscovery() { emit($('a[href="#discovered"]')._node, 'shown.bs.tab'); },
    select(ids) {
      const table = $('#discoveredTable')._node;
      table.rows.forEach(row => { row.state = ids.includes(row.id); });
      emit(table, 'check.bs.table');
    },
    selectRows(selector, rows) {
      const table = $(selector)._node;
      table.rows = rows.map(row => Object.assign({}, row, {state:true}));
      emit(table, 'check.bs.table');
    }};
}

const unknown = ip => ({ip, mac: '', can_identify: true, identity_pending: true, seen_now: true,
  source: 'SIP OPTIONS (UDP 5060)'});
const known = (ip, mac) => ({ip, mac, brand: 'digium', model: 'A20', firmware: '2.4.0.5722', is_supported: true, seen_now: true});
const tests = {
  'bulk import previews before saving, invalidates edits, and saves only the reviewed revision'() {
    const importId = 'd'.repeat(32);
    const s = fixture((command, data) => {
      if (command === 'getPendingCount') return {count: 0, deploy_mode: 'sip_notify'};
      if (command === 'previewBulkImport') return {success: true, can_import: true, revision: 'a'.repeat(64),
        summary: {new: 1, update: 0, unchanged: 0, invalid: 0, ready: 0, blocked: 1},
        rows: [{row: 1, mac: '000fd3000700', extension: '200', action: 'new', valid: true, changes: [], readiness: 'Identify MAC', errors: [], warnings: ['<script>']}]};
      if (command === 'bulkImport') return {success: 1, errors: [], batch_id: importId, macs: ['000fd3000700']};
      if (command === 'getBatchReport') return {success: true, batch: {id: importId, kind: 'import', status: 'complete', created_at: '2026-10-15'},
        items: [], retry_macs: [], active: false};
    });
    s.$('#importCsv').val('200,000fd3000700');
    s.click('#importBtn');
    assert.equal(s.requests.filter(row => row.command === 'bulkImport').length, 0);
    assert.equal(s.$('#commitImportBtn').prop('disabled'), false);
    assert.match(s.$('#importResults')._node.text, /&lt;script&gt;/);
    s.$('#importCsv').val('201,000fd3000700').trigger('input');
    assert.equal(s.$('#commitImportBtn').prop('disabled'), true);
    s.click('#commitImportBtn');
    assert.equal(s.requests.filter(row => row.command === 'bulkImport').length, 0);
    s.click('#importBtn');
    s.click('#commitImportBtn');
    const saved = s.requests.find(row => row.command === 'bulkImport');
    assert.equal(saved.csv_content, '201,000fd3000700');
    assert.equal(saved.revision, 'a'.repeat(64));
    assert.equal(s.$('#importSavedSummary').text(), '1 mapping saved');
    assert.equal(s.$('#importSavedDetails').text(), '1 new · Configurations have not been pushed');
    assert.equal(s.$('#pushImportedPhonesCount').text(), '1');
    assert.ok(!s.requests.some(row => row.command === 'getBatchReport'), 'The report is an explicit post-save action');
    assert.equal(s.$('#applyModal')._node.visible, false);
    s.click('#viewImportReport');
    assert.ok(s.requests.some(row => row.command === 'getBatchReport' && row.batch_id === importId));
    assert.ok(!s.requests.some(row => /pushToPhones|applyChangesWithNotify|retryBatch/.test(row.command)), 'Saving import must not push');
  },
  'batch history opens persistent report and retry uses batch ID rather than browser MAC selections'() {
    const id = 'e'.repeat(32), retryId = 'f'.repeat(32);
    const s = fixture((command, data) => {
      if (command === 'getBatchHistory') return {success: true, offset: 0, total: 1, batches: [{id,kind: 'push',status: 'incomplete',created_at: '2026-10-15',counts: {failed: 1}}]};
      if (command === 'getBatchReport') return {success: true, batch: {id: data.batch_id,kind: 'push',status: 'incomplete',created_at: '2026-10-15',parent_id: data.batch_id === retryId ? id : null},
        active: false, retry_macs: ['000fd3000700'], items: [{mac: '000fd3000700',extension: '200',import: 'mapped',generation: 'generated',trigger: 'failed',delivery: 'not_started',verification: 'not_started',outcome: 'failed',error: '<bad reply>'}]};
      if (command === 'retryBatch') return {success: true,batch_id: retryId};
    });
    s.click('#batchHistoryTab');
    const history = s.$('#batchHistoryTable')._node;
    assert.match(history.tableOptions.columns.find(column => column.field === 'status').formatter('incomplete', history.rows[0]), /Needs attention/);
    s.tableAction('#batchHistoryTable', '.batch-report-link', history.rows[0]);
    const report = s.$('#batchReportTable')._node;
    assert.match(report.tableOptions.detailFormatter(0, report.rows[0]), /&lt;bad reply&gt;/);
    assert.deepEqual(Array.from(report.tableOptions.columns, column => column.title), ['Extension', 'Phone / MAC', 'Target IP', 'Result', 'Details']);
    assert.equal(s.$('#batchHistoryView')._node.visible, false);
    assert.equal(s.$('#batchReportResults')._node.visible, true);
    assert.equal(s.$('#retryBatchBtn').prop('disabled'), false);
    s.$('#batchReportFilter').val('completed').trigger('change');
    assert.equal(s.$('#batchReportTable').bootstrapTable('getData').length, 0);
    assert.equal(s.$('#retryBatchCount').text(), '1', 'The retry badge always counts eligible phones in the full batch');
    assert.match(s.$('#retryBatchBtn').attr('title'), /Search and filters do not change this scope/);
    s.click('#retryBatchBtn');
    const confirm = [...s.nodes.values()].find(node => String(node.selector).endsWith(' #confirmOk'));
    confirm.handlers.get('click').call(confirm.proxy);
    assert.equal(s.requests.find(row => row.command === 'retryBatch').batch_id, id);
    assert.ok(s.requests.some(row => row.command === 'getBatchReport' && row.batch_id === retryId));
    assert.match(s.$('#batchReportLinks')._node.text, /Retry of/);
  },
  'report history uses global search and standard pagination and keeps its place after viewing a report'() {
    const batches = Array.from({length: 45}, (_, i) => ({id: (i + 1).toString(16).padStart(32, '0'),
      kind: 'import', status: 'complete', created_at: '2026-10-15', device_count: 1, counts: {imported: 1}}));
    const s = fixture((command, data) => {
      if (command === 'getBatchHistory') {
        const results = data.search ? batches.filter(row => row.id === data.search) : batches;
        return {success: true, total: results.length, total_all: batches.length, offset: data.offset, batches: results.slice(data.offset, data.offset + data.limit)};
      }
      if (command === 'getBatchReport') return {success: true, batch: batches.find(row => row.id === data.batch_id), items: [], retry_macs: [], active: false};
    });
    s.click('#batchHistoryTab');
    const table = s.$('#batchHistoryTable');
    assert.equal(table._node.tableOptions.sidePagination, 'server');
    assert.equal(table._node.rows.length, 10);
    table.bootstrapTable('selectPage', 3);
    assert.equal(s.requests.filter(row => row.command === 'getBatchHistory').at(-1).offset, 20);
    s.tableAction('#batchHistoryTable', '.batch-report-link', table._node.rows[0]);
    assert.equal(s.$('#batchHistoryView')._node.visible, false);
    s.click('#backToBatchHistoryBtn');
    assert.equal(table._node.tableOptions.pageNumber, 3);
    assert.equal(table._node.initializations, 1);
    assert.equal(s.$('#batchReportResults')._node.visible, false);
    table.bootstrapTable('resetSearch', batches[39].id);
    assert.equal(table._node.rows[0].id, batches[39].id, 'Search must reach reports outside the previously visible history page');
    assert.equal(table._node.totalRows, 1);
    assert.equal(s.requests.filter(row => row.command === 'getBatchHistory').at(-1).search, batches[39].id);
  },
  'live report refresh preserves search sorting pagination filters and expanded phone details'() {
    const id = '1'.repeat(32);
    let polls = 0;
    const items = Array.from({length: 90}, (_, i) => ({mac: '000fd3' + i.toString(16).padStart(6, '0'), extension: String(1000 + i),
      brand: 'digium', target_ip: '192.0.2.' + (i + 1), outcome: i < 60 ? 'verifying' : i < 80 ? 'failed' : 'verified',
      generation: 'generated', trigger: 'accepted', delivery: 'downloaded', verification: 'pending', error: '', retryable: i >= 60 && i < 80}));
    const s = fixture((command, data) => {
      if (command === 'getBatchHistory') return {success: true, total: 1, offset: 0, batches: [{id, kind: 'push', counts: {verifying: 60, failed: 20, verified: 10}}]};
      if (command === 'getBatchReport') {
        polls++;
        return {success: true, batch: {id, kind: 'push', status: 'submitted', created_at: '2026-10-15'},
          active: true, retry_macs: items.filter(row => row.retryable).map(row => row.mac), items: items.map(row => Object.assign({}, row))};
      }
    });
    s.$('#bulkReportsSection').show();
    s.click('#batchHistoryTab');
    s.tableAction('#batchHistoryTable', '.batch-report-link', s.$('#batchHistoryTable')._node.rows[0]);
    const table = s.$('#batchReportTable');
    s.$('#batchReportFilter').val('progress').trigger('change');
    table.bootstrapTable('resetSearch', 'digium');
    table._node.tableOptions.sortOrder = 'desc';
    table._node.tableOptions.pageSize = 25;
    table.bootstrapTable('selectPage', 3);
    const row = table.bootstrapTable('getData', {useCurrentPage: true})[2];
    s.tableAction('#batchReportTable', '.batch-phone-details', row);
    items.find(item => item.mac === row.mac).error = '<fresh diagnostic>';
    items[0].outcome = 'verified';
    const poll = s.timers.find(timer => !timer.cancelled);
    poll();
    assert.equal(polls, 2);
    assert.equal(table._node.initializations, 1, 'Live refresh must not rebuild the table widget');
    assert.equal(table._node.tableOptions.searchText, 'digium');
    assert.equal(table._node.tableOptions.sortOrder, 'desc');
    assert.equal(table._node.tableOptions.pageSize, 25);
    assert.equal(table._node.tableOptions.pageNumber, 3);
    assert.equal(s.$('#batchReportFilter').val(), 'progress');
    assert.ok(table._node.expanded.has(row.mac), 'The same visible phone remains expanded after a live update');
    const updated = table._node.rows.find(item => item.mac === row.mac);
    assert.match(table._node.tableOptions.detailFormatter(0, updated), /&lt;fresh diagnostic&gt;/);
    assert.equal(s.$('#retryBatchBtn').prop('disabled'), true, 'Active batches cannot be retried');
    s.$('#batchReportFilter').val('attention').trigger('change');
    assert.equal(table._node.tableOptions.pageNumber, 1);
    assert.equal(table.bootstrapTable('getData').length, 20);
    table.bootstrapTable('resetSearch', '00:0f:d3:00:00:46');
    assert.equal(table.bootstrapTable('getData')[0].extension, '1070');
    s.click('#downloadBatchCsvBtn');
    assert.equal(s.downloads[0].blob.parts[0].split('\r\n').length, 91, 'CSV must include the full report, not only searched/filtered rows');
    table.bootstrapTable('resetSearch', '');
    s.$('#batchReportFilter').val('completed').trigger('change');
    assert.equal(table.bootstrapTable('getData').length, 11);
    table.bootstrapTable('resetSearch', 'nothing matches');
    assert.equal(table.bootstrapTable('getData').length, 0);
    assert.match(table._node.tableOptions.formatNoMatches(), /No phones match/);
  },
  'report navigation ignores late responses and report failures leave a refreshable inline error'() {
    const first = '2'.repeat(32), second = '3'.repeat(32), pending = [];
    const batches = [{id: first, kind: 'push'}, {id: second, kind: 'import'}];
    const s = fixture((command, data) => {
      if (command === 'getBatchHistory') return {success: true, total: 2, offset: 0, batches};
      if (command === 'getBatchReport') return callback => pending.push({id: data.batch_id, callback});
    });
    const reply = (request, mac, error) => request.callback(error ? {success: false, error} : {success: true,
      batch: {id: request.id, kind: 'import', status: 'complete'}, active: false, retry_macs: [],
      items: [{mac, extension: '200', brand: 'digium', outcome: 'imported'}]});
    s.click('#batchHistoryTab');
    s.tableAction('#batchHistoryTable', '.batch-report-link', batches[0]);
    assert.equal(s.$('#batchReportMessageText').text(), 'Loading report…');
    s.click('#backToBatchHistoryBtn');
    s.tableAction('#batchHistoryTable', '.batch-report-link', batches[1]);
    reply(pending[0], '000fd3000001');
    assert.equal(s.$('#batchReportTable')._node.initializations, undefined, 'A late response cannot replace the newly selected report');
    reply(pending[1], '000fd3000002');
    assert.equal(s.$('#batchReportTable')._node.rows[0].mac, '000fd3000002');
    s.click('#refreshBatchReportBtn');
    reply(pending[2], '', '<offline>');
    assert.equal(s.$('#batchReportMessageText').text(), '<offline>');
    assert.equal(s.$('#reloadBatchReportBtn')._node.visible, true);
    assert.equal(s.$('#retryBatchBtn').prop('disabled'), true);
    assert.equal(s.$('#batchReportTable')._node.rows[0].mac, '000fd3000002', 'A failed refresh preserves the last readable data');
    s.click('#reloadBatchReportBtn');
    reply(pending[3], '000fd3000002');
    assert.equal(s.$('#batchReportMessage')._node.visible, false);
    s.click('#backToBatchHistoryBtn');
    assert.equal(s.$('#batchHistoryView')._node.visible, true);
    assert.equal(s.$('#batchReportResults')._node.visible, false);
  },
  'report history has distinct loading failure empty and no-match states with an inline retry'() {
    let failed = true;
    const s = fixture(command => command === 'getBatchHistory'
      ? failed ? {success: false, error: 'History temporarily unavailable'} : {success: true, total: 0, offset: 0, batches: []}
      : undefined);
    s.click('#batchHistoryTab');
    assert.equal(s.$('#batchHistoryMessageText').text(), 'History temporarily unavailable');
    assert.equal(s.$('#reloadBatchHistoryBtn')._node.visible, true);
    assert.match(s.$('#batchHistoryTable')._node.tableOptions.formatNoMatches(), /could not be loaded/);
    failed = false;
    s.click('#reloadBatchHistoryBtn');
    assert.equal(s.$('#batchHistoryMessage')._node.visible, false);
    assert.match(s.$('#batchHistoryTable')._node.tableOptions.formatNoMatches(), /No reports yet/);
    s.$('#batchHistoryTable').bootstrapTable('resetSearch', 'missing report');
    assert.match(s.$('#batchHistoryTable')._node.tableOptions.formatNoMatches(), /No reports match your search/);
    assert.equal(s.$('#batchHistoryTable')._node.initializations, 1);
  },
  'MAC-less candidates have distinct selectable IP identities'() {
    const rows = Discovery.normalizeRows([unknown('192.168.188.68'), unknown('192.168.188.67')]);
    assert.deepEqual(rows.map(row => row.id), ['IP:192.168.188.68', 'IP:192.168.188.67']);
    assert.ok(rows.every(row => row.can_identify && !row.is_supported));
  },
  'identification replaces the selected IP candidate and preserves other known rows'() {
    const merged = Discovery.mergeRows([unknown('192.168.188.68'), unknown('192.168.188.67')],
      [known('192.168.188.68', '00:0f:d3:cf:40:af'), unknown('192.168.188.67')]);
    assert.equal(merged.length, 2);
    assert.equal(merged.find(row => row.ip.endsWith('.68')).id, '000FD3CF40AF');
    assert.ok(!merged.find(row => row.id === 'IP:192.168.188.68'));
    assert.equal(merged.find(row => row.ip.endsWith('.67')).seen_now, true);
  },
  'rescan retains old observations with stale labels and moves a confirmed MAC by identity'() {
    const merged = Discovery.mergeRows([known('192.168.188.68', '000fd3cf40af'), unknown('192.168.188.67')],
      [known('192.168.188.69', '000fd3cf40af')]);
    assert.equal(merged.length, 2);
    assert.equal(merged.find(row => row.mac).ip, '192.168.188.69');
    assert.equal(merged.find(row => !row.mac).seen_now, false);
  },
  'scan, explicit selected identification, and Add use separate UI commands'() {
    const scanId = 'a'.repeat(32), identifyId = 'b'.repeat(32);
    let polls = 0;
    const s = fixture((command, data) => {
      if (command === 'getPendingCount') return {count: 0, deploy_mode: 'sip_notify'};
      if (command === 'getSettings') return {deploy_mode: 'sip_notify'};
      if (command === 'getNetworkSubnets') return {interfaces: [{subnet: '172.16.138.0/24', ip: '172.16.138.13'}]};
      if (command === 'startDiscoveryScan') return {success: true, job_id: scanId};
      if (command === 'startIdentifyMac') return {success: true, job_id: identifyId};
      if (command === 'getDiscoveryJobStatus') {
        if (data.job_id === scanId) return {success: true, type: 'scan', scan_mode: 'routed', subnet: '192.168.188.0/24', complete: ++polls > 1, done: 2, total: 2,
          excluded_responders: polls > 1 ? [{ip:'192.168.188.99',status:'SIP/2.0 403 Forbidden',reason:'SIP response was not 200 OK.'}] : [],
          devices: [unknown('192.168.188.68'), unknown('192.168.188.67')]};
        return {success: true, type: 'identify', complete: true, done: 1, total: 1,
          devices: [known('192.168.188.68', '000fd3cf40af'), unknown('192.168.188.67')]};
      }
      if (command === 'addDiscoveredDevices') return {success: true, added: 1, devices: data.devices};
    });
    s.$('#scan-subnet-input').val('192.168.188.0/24');
    s.enterDiscovery();
    assert.equal(s.$('#scan-subnet-input').val(), '192.168.188.0/24', 'Tab entry must keep a routed subnet typed by the user');
    s.click('#scanDiscoveryBtn');
    assert.equal(s.requests.find(request => request.command === 'startDiscoveryScan').subnet, '192.168.188.0/24');
    assert.ok(!s.requests.some(request => request.command === 'startIdentifyMac'));
    s.select(['IP:192.168.188.68']);
    assert.equal(s.$('#identifyMacBtn').prop('disabled'), true, 'Wait for the scan to finish');
    s.timers.shift()();
    s.select(['IP:192.168.188.68']);
    assert.equal(s.$('#identifyMacBtn').prop('disabled'), false);
    assert.equal(s.$('#addSelectedDiscoveryBtn').prop('disabled'), true);
    s.click('#identifyMacBtn');
    const request = s.requests.find(row => row.command === 'startIdentifyMac');
    assert.equal(request.scan_id, scanId);
    assert.deepEqual(request.ids, ['IP:192.168.188.68']);
    assert.equal(s.$('#discoveredTable')._node.rows.length, 2);
    s.select(['000FD3CF40AF']);
    assert.equal(s.$('#identifyMacBtn').prop('disabled'), true);
    assert.equal(s.$('#addSelectedDiscoveryBtn').prop('disabled'), false);
    s.click('#addSelectedDiscoveryBtn');
    const confirm = [...s.nodes.values()].find(node => String(node.selector).endsWith(' #confirmOk'));
    confirm.handlers.get('click').call(confirm.proxy);
    assert.equal(s.requests.filter(row => row.command === 'addDiscoveredDevices').length, 1);
    assert.ok(!s.requests.some(row => /push|Notify|pullPhone/i.test(row.command)), 'Discovery and Add cannot start provisioning or pull handset WebUI settings');
  },
  'local scan shows ping/ARP progress and keeps its confirmed MAC during SIP enrichment'() {
    const scanId = 'c'.repeat(32);
    let polls = 0;
    const local = Object.assign(known('172.16.138.50', '000fd3000630'), {model: '', firmware: '', source: 'Local ping/ARP', can_identify: false});
    const stale = Object.assign(known('172.16.138.51', '000fd3000631'), {seen_now: false, source: 'Local ARP cache'});
    const s = fixture(command => {
      if (command === 'getPendingCount' || command === 'getSettings') return {count: 0, deploy_mode: 'sip_notify'};
      if (command === 'startDiscoveryScan') return {success: true, job_id: scanId, scan_mode: 'local'};
      if (command === 'getDiscoveryJobStatus') {
        polls++;
        return {success: true, type: 'scan', scan_mode: 'local', phase: polls === 1 ? 'local_arp' : 'sip_options',
          complete: polls > 1, done: 1, total: polls === 1 ? 3 : 1,
          devices: [polls === 1 ? local : Object.assign({}, local, {model: 'A20', firmware: '2.4.0.5722', source: 'Local ping/ARP + SIP OPTIONS (UDP 5060)'}), stale]};
      }
    });
    s.$('#scan-subnet-input').val('172.16.138.0/24');
    s.enterDiscovery();
    s.click('#scanDiscoveryBtn');
    assert.match(s.$('#scanStatus')._node.text, /Local ping\/ARP sweep: 1\/3 passes/);
    assert.match(s.$('#scanStatus')._node.text, /<strong>1<\/strong> identified Digium\/ALE/);
    assert.equal(s.$('#discoveredTable')._node.rows[0].mac, '000FD3000630');
    s.timers.shift()();
    assert.match(s.$('#scanStatus')._node.text, /Local ping\/ARP \+ SIP OPTIONS scan/);
    assert.equal(s.$('#discoveredTable')._node.rows.length, 2);
    assert.equal(s.$('#discoveredTable')._node.rows[0].model, 'A20');
    assert.match(s.$('#discoveryCoverage')._node.text, /Already mapped/);
    s.select(['000FD3000630']);
    assert.equal(s.$('#identifyMacBtn').prop('disabled'), true, 'Local ARP already supplied the MAC');
    assert.equal(s.$('#addSelectedDiscoveryBtn').prop('disabled'), false);
    assert.ok(!s.requests.some(row => /Identify|push|Notify/i.test(row.command)));
    assert.match(s.window.discoveredStatusFormatter('', {seen_now: true, identity_conflict: true}), /MAC identity conflict/);
  },
  'discovery formatters show New / Unassigned and escape identity text'() {
    const s = fixture(() => undefined);
    assert.match(s.window.discoveredStatusFormatter('', known('192.168.188.68', '000fd3cf40af')), /New \/ Unassigned/);
    assert.match(s.window.discoveredMacFormatter(''), /Identify required/);
    assert.match(s.window.discoveredModelFormatter('<model>', {firmware: '<version>'}), /&lt;model&gt;/);
    assert.doesNotMatch(s.window.discoveredModelFormatter('<model>', {firmware: '<version>'}), /<version>/);
    const source = s.window.discoveredSourceFormatter('SIP discovery fetch (HTTP 404)', {seen_now: true, last_seen: ''});
    assert.match(source, /MAC identified via handset request/);
    assert.match(source, /HTTP 404/);
    assert.doesNotMatch(source, /SIP discovery fetch/);
  },
  'Phone Status presents model and MAC together, and separates registration from push state'() {
    const s = fixture(() => undefined);
    const phone = s.window.phoneStatusPhoneFormatter('', {model:'A20',brand:'Digium',description:'Office',mac:'000fd3000700'});
    assert.match(phone, /<strong>A20<\/strong>/);
    assert.match(phone, /<code[^>]*>000fd3000700<\/code>/);
    assert.match(s.window.phoneStatusRegistrationFormatter('Registered'), /Registered/);
    assert.match(s.window.phoneStatusPushFormatter('<span>Verified<\/span>', {last_push_time:'2026-10-15 13:00:00'}), /Verified/);
  }
};

tests['Phone Status and discovery filters select the intended categories'] = function () {
  const s = fixture(() => undefined);
  s.$('#phoneStatusFilter').val('issues').trigger('change');
  assert.equal(s.$('#phoneStatusTable')._node.filterBy.push_issue, true);
  assert.equal(s.storage['provisioner-phone-filter'], 'issues');
  s.$('#discoveryFilter').val('stale').trigger('change');
  assert.equal(s.$('#discoveredTable')._node.filterBy.discovery_filter, 'stale');
  assert.equal(s.storage['provisioner-discovery-filter'], 'stale');
};

tests['import preview identifies both partners in an extension swap'] = function () {
  const s = fixture(command => command === 'previewBulkImport' ? {success:true,can_import:true,revision:'b'.repeat(64),
    summary:{new:0,update:2,unchanged:0,invalid:0,ready:2,blocked:0}, rows:[
      {row:1,mac:'000fd3000700',extension:'2000',old_extension:'200',brand:'digium',action:'update',changes:['extension: 200 -> 2000'],push_ready:true,valid:true,readiness:'Ready (rechecked at push)',errors:[],warnings:['Reassignment/swap with 3C28A6000701; push partners together.']},
      {row:2,mac:'3c28a6000701',extension:'200',old_extension:'2000',brand:'alcatel',action:'update',changes:['extension: 2000 -> 200'],push_ready:true,valid:true,readiness:'Ready (rechecked at push)',errors:[],warnings:['Reassignment/swap with 000FD3000700; push partners together.']}
    ]} : undefined);
  s.$('#importCsv').val('2000,000fd3000700\n200,3c28a6000701');
  s.click('#importBtn');
  const html = s.$('#importResults')._node.text;
  assert.match(html, /Extension swap partners \(1\)/);
  assert.match(html, /000fd3000700/);
  assert.match(html, /3c28a6000701/);
  assert.match(html, /2000/);
  assert.match(html, /import-swap-row/);
};

tests['Push Config badges show the number of selected phones instead of pending changes'] = function () {
  const s = fixture(command => command === 'getPendingCount' ? {count:1,deploy_mode:'sip_notify'} : undefined);
  assert.equal(s.$('#pendingBadgePhone').text(), '1', 'Without a selection, show pending phone count');
  assert.equal(s.$('#applyChangesPhoneBtn').prop('disabled'), true, 'Pending changes alone do not enable an unscoped push');
  s.selectRows('#phoneStatusTable', [
    {mac:'000fd3000700',extension:'200'},
    {mac:'3c28a6000701',extension:'201'}
  ]);
  assert.equal(s.$('#pendingBadgePhone').text(), '2');
  assert.match(s.$('#applyChangesPhoneBtn').attr('title'), /2 selected phone/);
  s.selectRows('#phoneStatusTable', []);
  assert.equal(s.$('#pendingBadgePhone').text(), '1', 'Clearing the selection restores the pending-change count');
  s.selectRows('#devicesTable', [{id:1,mac_address:'000fd3000700'}, {id:2,mac_address:'3c28a6000701'}]);
  assert.equal(s.$('#pendingBadge').text(), '2');
  assert.match(s.$('#applyChangesBtn').attr('title'), /2 selected phones/);
};

tests['Push and reboot actions become scoped retry or all actions from live row state'] = function () {
  const s = fixture(command => command === 'getPendingCount' ? {count:0,deploy_mode:'sip_notify'} : undefined);
  s.$('#phoneStatusTable').bootstrapTable('load', [
    {mac:'000fd3000700',extension:'200',push_retryable:true,reboot_eligible:true},
    {mac:'3c28a6000701',extension:'201',push_retryable:false,reboot_eligible:false}
  ]);
  assert.equal(s.$('#applyChangesPhoneBtn').prop('disabled'), false);
  assert.match(s.$('#applyChangesPhoneBtn').attr('title'), /Retry push for 1 phone/);
  assert.equal(s.$('#rebootActionBtn').prop('disabled'), false);
  assert.match(s.$('#rebootActionBtn').attr('title'), /Reboot all 1 eligible/);
  s.selectRows('#phoneStatusTable', [
    {mac:'000fd3000700',extension:'200',push_retryable:true,reboot_eligible:true},
    {mac:'3c28a6000701',extension:'201',push_retryable:false,reboot_eligible:false}
  ]);
  assert.match(s.$('#applyChangesPhoneBtn').attr('title'), /2 selected phone/);
  assert.match(s.$('#rebootActionBtn').attr('title'), /Reboot 1 selected/);
};

let failed = 0;
for (const [name, run] of Object.entries(tests)) {
  try { run(); console.log('PASS ' + name); }
  catch (error) { failed++; console.error('FAIL ' + name + '\n' + error.stack); }
}
console.log(Object.keys(tests).length - failed + ' discovery UI tests passed, ' + failed + ' failed');
process.exitCode = failed ? 1 : 0;
