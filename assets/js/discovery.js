(function (root, factory) {
  if (typeof module === 'object' && module.exports) module.exports = factory();
  else root.ProvisionerDiscovery = factory();
})(typeof window !== 'undefined' ? window : this, function () {
  'use strict';
  function normalizeRows(rows) {
    return (rows || []).map(function (row) {
      var mac = String(row.mac || '').replace(/[^a-f0-9]/gi, '').toUpperCase();
      return Object.assign({}, row, {
        id: mac || 'IP:' + row.ip,
        mac: mac, ip: row.ip || '', extension: row.extension || '',
        vendor: row.vendor || 'Unidentified SIP', brand: row.brand || '',
        model: row.model || '', firmware: row.firmware || '', source: row.source || '',
        pjsip_status: row.pjsip_status || '',
        is_phone: row.is_phone === true || row.is_phone === 1 || row.is_phone === '1',
        is_supported: row.is_supported === true || row.is_supported === 1 || row.is_supported === '1',
        can_identify: !mac && !!row.can_identify,
        identity_pending: !mac && !!row.identity_pending,
        seen_now: !(row.seen_now === false || row.seen_now === 0 || row.seen_now === '0'),
        neighbor_state: row.neighbor_state || '', last_seen: row.last_seen || '',
        status: row.status || (row.in_provisioner ? 'added' : 'new')
      });
    });
  }
  function mergeRows(existing, incoming, preserveOtherSources) {
    var rows = new Map();
    normalizeRows(existing).forEach(function (row) {
      rows.set(row.id, Object.assign({}, row, {
        seen_now: preserveOtherSources && String(row.source).indexOf('PJSIP') !== 0 ? row.seen_now : false
      }));
    });
    normalizeRows(incoming).forEach(function (row) {
      var old = rows.get(row.id) || {};
      if (row.mac) rows.delete('IP:' + row.ip);
      if (!row.extension && old.extension) row.extension = old.extension;
      row.last_seen = row.last_seen || (row.seen_now ? new Date().toISOString() : old.last_seen || '');
      rows.set(row.id, Object.assign({}, old, row));
    });
    return Array.from(rows.values());
  }
  return {normalizeRows: normalizeRows, mergeRows: mergeRows};
});
