'use strict';
const assert = require('node:assert/strict');
const LiveStatus = require('../assets/js/live-status.js');
const fs = require('node:fs');
const vm = require('node:vm');

function fixture(saved) {
  const state = {visible: true, blocked: false, time: 100000, rows: [], sources: [], timers: new Map(),
    labels: [], changes: [], pending: [], fetches: [], successes: [], saved: saved, nextTimer: 1};
  const controller = new LiveStatus({
    storage: {getItem() { return state.saved; }, setItem(key, value) { state.saved = value; }},
    now: () => state.time,
    setTimeout(fn, delay) { const id = state.nextTimer++; state.timers.set(id, {fn, delay}); return id; },
    clearTimeout(id) { state.timers.delete(id); },
    isVisible: () => state.visible,
    isBlocked: () => state.blocked,
    setState(label, enabled) { state.labels.push({label, enabled}); },
    createSource() {
      const source = {handlers: {}, closed: false,
        addEventListener(type, fn) { this.handlers[type] = fn; },
        close() { this.closed = true; },
        emit(snapshot) { this.handlers.status({data: JSON.stringify(snapshot)}); }};
      state.sources.push(source);
      return source;
    },
    fetchSnapshot(callback) { state.fetches.push(callback); },
    getRows: () => state.rows,
    applyRows(updates, added, removed) {
      state.changes.push({updates, added, removed});
      state.rows = state.rows.filter(row => !removed.includes(row.mac));
      state.rows.push(...added);
      updates.forEach(update => Object.assign(state.rows.find(row => row.mac === update.id), update.row));
    },
    applyPending(pending) { state.pending.push(pending); },
    onSuccessfulUpdate(snapshot) { state.successes.push(snapshot.revision); }
  });
  state.controller = controller;
  state.fireTimer = delay => {
    const timer = [...state.timers.entries()].find(([, value]) => value.delay === delay);
    assert.ok(timer, 'Expected timer at ' + delay);
    state.timers.delete(timer[0]);
    timer[1].fn();
  };
  return state;
}

function snapshot(revision, rows, ami = true) {
  return {revision, rows, pending: {count: 0}, ami_connected: ami};
}

const tests = {
  'Provisioner initializes the live control on direct entry and resumes across tabs'() {
    const nodes = new Map();
    const document = {hidden: false};
    const sources = [];
    const storage = new Map();
    const window = {ProvisionerLiveStatus: LiveStatus,
      localStorage: {getItem: key => storage.get(key), setItem: (key, value) => storage.set(key, value)},
      setTimeout() { return 1; }, clearTimeout() {},
      EventSource: function () {
        this.handlers = {};
        this.addEventListener = (type, fn) => { this.handlers[type] = fn; };
        this.close = () => { this.closed = true; };
        sources.push(this);
      }};
    function $(selector) {
      if (selector && selector._node) return selector;
      if (nodes.has(selector)) return nodes.get(selector).proxy;
      const node = {handlers: new Map(), data: {}, classes: new Set(selector === '#phones' ? ['active'] : []), visible: false};
      const proxy = new Proxy({}, {get(target, key) {
        if (key === '_node') return node;
        if (key === 'length') return 0;
        if (key === 'ready') return fn => { fn(); return proxy; };
        if (key === 'on') return (event, ...args) => { node.handlers.set(event, args.at(-1)); return proxy; };
        if (key === 'data') return (name, value) => {
          if (value === undefined) return node.data[name];
          node.data[name] = value; return proxy;
        };
        if (key === 'show') return () => { node.visible = true; return proxy; };
        if (key === 'hasClass') return name => node.classes.has(name);
        if (key === 'bootstrapTable') return method => method === 'getData' || method === 'getSelections' ? [] : proxy;
        return () => proxy;
      }});
      node.proxy = proxy;
      nodes.set(selector, node);
      return proxy;
    }
    $.ajax = () => { const request = {done() { return request; }, fail() { return request; }}; return request; };
    vm.runInNewContext(fs.readFileSync(require.resolve('../assets/js/provisioner.js'), 'utf8'), {$, window, document, console});
    const button = nodes.get('#liveToggleBtn');
    assert.ok(button.visible, 'The control must appear without a tab switch');
    assert.equal(sources.length, 1);
    const tabs = nodes.get('#provisionerTabs a[data-toggle="tab"]');
    nodes.get('#phones').classes.delete('active');
    tabs.handlers.get('shown.bs.tab.provisionerLive')();
    assert.ok(sources[0].closed);
    nodes.get('#phones').classes.add('active');
    tabs.handlers.get('shown.bs.tab.provisionerLive')();
    assert.equal(sources.length, 2);
    button.handlers.get('click').call(button.proxy);
    assert.equal(storage.get('provisioner-live-updates'), 'off');
    assert.ok(sources[1].closed);
    tabs.handlers.get('shown.bs.tab.provisionerLive')();
    assert.equal(sources.length, 2, 'Returning to the tab must respect the off preference');
  },
  'initial Phone Status starts live updates and restores the off preference'() {
    const on = fixture();
    on.controller.sync();
    assert.equal(on.sources.length, 1);
    on.sources[0].emit(snapshot('first', []));
    assert.equal(on.labels.at(-1).label, 'Live');
    const off = fixture('off');
    off.controller.sync();
    assert.equal(off.sources.length, 0);
    assert.equal(off.labels.at(-1).label, 'Paused');
    off.controller.setEnabled(true);
    assert.equal(off.saved, 'on');
    assert.equal(off.sources.length, 1);
  },
  'tab and page visibility pause subscriptions while preserving preference'() {
    const s = fixture();
    s.controller.sync();
    const old = s.sources[0];
    s.visible = false;
    s.controller.sync();
    assert.ok(old.closed);
    assert.equal(s.timers.size, 0);
    assert.equal(s.controller.enabled, true);
    old.emit(snapshot('late', [{mac: 'wrong'}]));
    assert.equal(s.changes.length, 0);
    s.visible = true;
    s.controller.sync();
    assert.equal(s.sources.length, 2);
    s.controller.setEnabled(false);
    assert.equal(s.saved, 'off');
    assert.ok(s.sources[1].closed);
  },
  'changed rows are merged while selections and unchanged rows are retained'() {
    const s = fixture();
    s.rows = [{mac: 'a', ip: '10.0.0.1', selected: true}, {mac: 'b', ip: '10.0.0.2'}];
    s.controller.sync();
    s.sources[0].emit(snapshot('one', [{mac: 'a', ip: '10.0.0.3'}, {mac: 'b', ip: '10.0.0.2'}]));
    assert.equal(s.changes.length, 1);
    assert.equal(s.changes[0].updates.length, 1);
    assert.equal(s.rows[0].selected, true);
    s.sources[0].emit(snapshot('one', [{mac: 'a', ip: '10.0.0.3'}, {mac: 'b', ip: '10.0.0.2'}]));
    s.sources[0].emit(snapshot('two', [{mac: 'a', ip: '10.0.0.3'}, {mac: 'b', ip: '10.0.0.2'}]));
    assert.equal(s.changes.length, 1);
    s.sources[0].emit(snapshot('three', [{mac: 'b', ip: '10.0.0.2'}, {mac: 'c', ip: '10.0.0.4'}]));
    assert.deepEqual(s.changes[1].removed, ['a']);
    assert.equal(s.changes[1].added[0].mac, 'c');
  },
  'successful status sync timestamps both changed and unchanged snapshots'() {
    const s = fixture(); s.controller.sync();
    s.sources[0].emit(snapshot('one', []));
    s.sources[0].emit(snapshot('one', []));
    assert.deepEqual(s.successes, ['one', 'one']);
  },
  'dialogs defer changes and apply the newest snapshot when closed'() {
    const s = fixture();
    s.controller.sync();
    s.blocked = true;
    s.sources[0].emit(snapshot('one', [{mac: 'a', ip: '10.0.0.1'}]));
    s.sources[0].emit(snapshot('two', [{mac: 'a', ip: '10.0.0.2'}]));
    assert.equal(s.changes.length, 0);
    s.blocked = false;
    s.controller.flush();
    assert.equal(s.rows[0].ip, '10.0.0.2');
  },
  'disconnect recovery is bounded and cannot overwrite newer events'() {
    const s = fixture();
    s.controller.sync();
    s.sources[0].onerror();
    assert.equal(s.labels.at(-1).label, 'Reconnecting');
    assert.equal(s.fetches.length, 1);
    s.fireTimer(5000);
    s.sources[1].emit(snapshot('fresh', [{mac: 'a', ip: '10.0.0.2'}]));
    s.fetches[0](snapshot('old', [{mac: 'a', ip: '10.0.0.1'}]));
    assert.equal(s.rows[0].ip, '10.0.0.2');
    s.sources[1].onerror();
    assert.equal(s.fetches.length, 1, 'Recovery should not become fast polling');
    s.time += 30000;
    s.fireTimer(30000);
    assert.equal(s.fetches.length, 2);
  },
  'healthy streams use heartbeats without table polling and rotate cleanly'() {
    const s = fixture();
    s.controller.sync();
    s.sources[0].emit(snapshot('one', []));
    s.time += 30000;
    s.sources[0].handlers.heartbeat();
    s.fireTimer(30000);
    assert.equal(s.fetches.length, 0);
    s.sources[0].handlers.reconnect();
    assert.ok(s.sources[0].closed);
    assert.equal(s.sources.length, 2);
    assert.equal(s.fetches.length, 0);
  }
};

let failures = 0;
for (const [name, run] of Object.entries(tests)) {
  try { run(); console.log('PASS ' + name); }
  catch (error) { failures++; console.error('FAIL ' + name + '\n' + error.stack); }
}
console.log(Object.keys(tests).length - failures + ' live UI tests passed, ' + failures + ' failed');
process.exitCode = failures ? 1 : 0;
