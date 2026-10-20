(function (root, factory) {
  if (typeof module === 'object' && module.exports) module.exports = factory();
  else root.ProvisionerLiveStatus = factory();
}(typeof window !== 'undefined' ? window : this, function () {
  'use strict';

  function LiveStatus(options) {
    this.options = options;
    this.enabled = true;
    try { this.enabled = options.storage.getItem('provisioner-live-updates') !== 'off'; } catch (e) {}
    this.source = null;
    this.retryTimer = null;
    this.recoveryTimer = null;
    this.generation = 0;
    this.lastMessage = 0;
    this.amiConnected = false;
    this.lastRevision = null;
    this.pendingSnapshot = null;
    this.recovering = false;
    this.lastRecovery = null;
    this.updateSerial = 0;
  }

  LiveStatus.prototype.setEnabled = function (enabled) {
    this.enabled = enabled;
    try { this.options.storage.setItem('provisioner-live-updates', enabled ? 'on' : 'off'); } catch (e) {}
    this.sync();
  };

  LiveStatus.prototype.active = function () {
    return this.enabled && this.options.isVisible();
  };

  LiveStatus.prototype.connected = function () {
    return this.active() && this.source !== null && this.amiConnected;
  };

  LiveStatus.prototype.pause = function () {
    this.generation++;
    if (this.source) this.source.close();
    this.source = null;
    if (this.retryTimer !== null) this.options.clearTimeout(this.retryTimer);
    if (this.recoveryTimer !== null) this.options.clearTimeout(this.recoveryTimer);
    this.retryTimer = this.recoveryTimer = null;
    this.pendingSnapshot = null;
    this.amiConnected = false;
    this.recovering = false;
    this.options.setState('Paused', this.enabled);
  };

  LiveStatus.prototype.sync = function () {
    if (!this.active()) { this.pause(); return; }
    if (!this.source && this.retryTimer === null) this.open();
    this.scheduleRecovery();
    this.flush();
  };

  LiveStatus.prototype.open = function () {
    var self = this;
    var generation = ++this.generation;
    this.recovering = false;
    this.options.setState('Reconnecting', this.enabled);
    try {
      var source = this.options.createSource();
      this.source = source;
      function current() { return generation === self.generation && self.active(); }
      source.addEventListener('status', function (event) {
        if (!current()) return;
        var snapshot;
        try { snapshot = JSON.parse(event.data); } catch (e) { return; }
        if (!snapshot || !Array.isArray(snapshot.rows) || !snapshot.pending) return;
        self.lastMessage = self.options.now();
        self.amiConnected = snapshot.ami_connected === true;
        self.options.setState(self.amiConnected ? 'Live' : 'Reconnecting', self.enabled);
        self.accept(snapshot);
      });
      source.addEventListener('heartbeat', function () {
        if (current()) self.lastMessage = self.options.now();
      });
      source.addEventListener('unavailable', function () { if (current()) self.retry(); });
      source.addEventListener('reconnect', function () {
        if (!current()) return;
        source.close();
        self.source = null;
        self.open();
      });
      source.onerror = function () { if (current()) self.retry(); };
    } catch (e) { this.retry(); }
  };

  LiveStatus.prototype.retry = function () {
    var self = this;
    if (this.source) this.source.close();
    this.source = null;
    this.generation++;
    this.amiConnected = false;
    if (!this.active()) { this.pause(); return; }
    this.options.setState('Reconnecting', this.enabled);
    if (this.retryTimer === null) this.retryTimer = this.options.setTimeout(function () {
      self.retryTimer = null;
      self.sync();
    }, 5000);
    this.recover();
  };

  LiveStatus.prototype.scheduleRecovery = function () {
    var self = this;
    if (this.recoveryTimer !== null || !this.active()) return;
    this.recoveryTimer = this.options.setTimeout(function () {
      self.recoveryTimer = null;
      if (!self.active()) return;
      if (!self.source || !self.amiConnected || self.options.now() - self.lastMessage > 35000) self.recover();
      self.scheduleRecovery();
    }, 30000);
  };

  LiveStatus.prototype.recover = function () {
    if (this.recovering || !this.active()) return;
    if (this.lastRecovery !== null && this.options.now() - this.lastRecovery < 30000) return;
    var self = this;
    var generation = this.generation;
    var serial = this.updateSerial;
    this.recovering = true;
    this.lastRecovery = this.options.now();
    this.options.fetchSnapshot(function (snapshot) {
      if (generation !== self.generation) return;
      self.recovering = false;
      if (self.active() && serial === self.updateSerial && snapshot && Array.isArray(snapshot.rows) && snapshot.pending) self.accept(snapshot);
    });
  };

  LiveStatus.prototype.accept = function (snapshot) {
    // Dialogs can remain open while events arrive; apply the newest snapshot on close.
    this.pendingSnapshot = snapshot;
    this.updateSerial++;
    this.flush();
  };

  LiveStatus.prototype.flush = function () {
    if (!this.active() || this.options.isBlocked() || !this.pendingSnapshot) return;
    var snapshot = this.pendingSnapshot;
    this.pendingSnapshot = null;
    if (snapshot.revision === this.lastRevision) {
      if (this.options.onSuccessfulUpdate) this.options.onSuccessfulUpdate(snapshot);
      return;
    }
    var existing = this.options.getRows();
    var old = {};
    var present = {};
    var updates = [];
    var added = [];
    existing.forEach(function (row) { old[row.mac] = row; });
    snapshot.rows.forEach(function (row) {
      present[row.mac] = true;
      if (!old[row.mac]) added.push(row);
      else if (Object.keys(row).some(function (key) { return JSON.stringify(old[row.mac][key]) !== JSON.stringify(row[key]); })) {
        updates.push({ id: row.mac, row: row });
      }
    });
    var removed = existing.filter(function (row) { return !present[row.mac]; }).map(function (row) { return row.mac; });
    if (updates.length || added.length || removed.length) this.options.applyRows(updates, added, removed);
    this.options.applyPending(snapshot.pending);
    this.lastRevision = snapshot.revision;
    if (this.options.onSuccessfulUpdate) this.options.onSuccessfulUpdate(snapshot);
  };

  return LiveStatus;
}));
