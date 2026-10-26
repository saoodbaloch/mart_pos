/*
 * MartPOS offline engine
 * - Products ki local copy (IndexedDB) taake net ke baghair scan chale
 * - Outbox: bills aur naye products pehle yahan save, net aate hi server par
 * - Service worker register
 */
(function (global) {
    'use strict';

    var DB_NAME = 'martpos', DB_VER = 2, dbp = null;
    var listeners = [];
    var state = { online: navigator.onLine, pending: 0, lastSync: null, syncing: false, auth: true, failed: 0 };

    function openDb() {
        if (dbp) return dbp;
        dbp = new Promise(function (resolve, reject) {
            var req = indexedDB.open(DB_NAME, DB_VER);
            req.onupgradeneeded = function () {
                var db = req.result;
                if (!db.objectStoreNames.contains('products')) {
                    var ps = db.createObjectStore('products', { keyPath: 'id' });
                    ps.createIndex('barcode', 'barcode', { unique: false });
                }
                if (!db.objectStoreNames.contains('outbox')) db.createObjectStore('outbox', { keyPath: 'seq', autoIncrement: true });
                if (!db.objectStoreNames.contains('held')) db.createObjectStore('held', { keyPath: 'id' });
                if (!db.objectStoreNames.contains('meta')) db.createObjectStore('meta', { keyPath: 'k' });
                if (!db.objectStoreNames.contains('customers')) db.createObjectStore('customers', { keyPath: 'uuid' });
            };
            req.onsuccess = function () { resolve(req.result); };
            req.onerror = function () { reject(req.error); };
        });
        return dbp;
    }

    function tx(store, mode, fn) {
        return openDb().then(function (db) {
            return new Promise(function (resolve, reject) {
                var t = db.transaction(store, mode), s = t.objectStore(store), out;
                Promise.resolve(fn(s, t)).then(function (v) { out = v; });
                t.oncomplete = function () { resolve(out); };
                t.onerror = function () { reject(t.error); };
                t.onabort = function () { reject(t.error); };
            });
        });
    }

    function reqP(r) {
        return new Promise(function (resolve, reject) {
            r.onsuccess = function () { resolve(r.result); };
            r.onerror = function () { reject(r.error); };
        });
    }

    var Store = {
        getAll: function (store) { return tx(store, 'readonly', function (s) { return reqP(s.getAll()); }); },
        get: function (store, key) { return tx(store, 'readonly', function (s) { return reqP(s.get(key)); }); },
        put: function (store, val) { return tx(store, 'readwrite', function (s) { return reqP(s.put(val)); }); },
        del: function (store, key) { return tx(store, 'readwrite', function (s) { return reqP(s['delete'](key)); }); },
        count: function (store) { return tx(store, 'readonly', function (s) { return reqP(s.count()); }); },
        meta: function (k, v) {
            if (arguments.length === 1) return Store.get('meta', k).then(function (r) { return r ? r.v : null; });
            return Store.put('meta', { k: k, v: v });
        },
        byBarcode: function (code) {
            return tx('products', 'readonly', function (s) { return reqP(s.index('barcode').getAll(code)); })
                .then(function (rows) { return rows.filter(function (p) { return !p.deleted; })[0] || null; });
        }
    };

    /* ---------- Server calls ---------- */

    function csrf() {
        var m = document.querySelector('meta[name=csrf]');
        return m ? m.content : '';
    }

    function refreshCsrf() {
        return fetch('api/ping.php', { credentials: 'same-origin', cache: 'no-store' })
            .then(function (r) { if (r.status === 401) throw { auth: true }; return r.json(); })
            .then(function (d) {
                var m = document.querySelector('meta[name=csrf]');
                if (m && d.csrf) m.content = d.csrf;
                return d;
            });
    }

    function api(url, body, retried) {
        return fetch(url, {
            method: body ? 'POST' : 'GET',
            credentials: 'same-origin',
            cache: 'no-store',
            headers: body ? { 'Content-Type': 'application/json', 'X-CSRF': csrf() } : {},
            body: body ? JSON.stringify(body) : undefined
        }).then(function (r) {
            if (r.status === 401) throw { auth: true };
            if (r.status === 419 && !retried) return refreshCsrf().then(function () { return api(url, body, true); });
            return r.json().then(function (d) {
                if (!r.ok) throw { status: r.status, data: d };
                return d;
            });
        });
    }

    /* ---------- Products sync ---------- */

    function syncProducts() {
        return Promise.all([Store.meta('productsSince'), Store.meta('dataVersion')]).then(function (m) {
            var since = m[0], dv = m[1];
            return api('api/products.php?since=' + encodeURIComponent(since || '') + '&v=' + encodeURIComponent(dv || '')).then(function (d) {
                return tx('products', 'readwrite', function (s) {
                    if (d.full) s.clear();
                    var barcodes = {};
                    d.items.forEach(function (p) {
                        if (p.deleted) s['delete'](p.id);
                        else { s.put(p); if (p.barcode) barcodes[p.barcode] = 1; }
                    });
                    // Offline mein bane temporary products (minus id) ab server se aa gaye
                    var cur = s.openCursor();
                    cur.onsuccess = function () {
                        var c = cur.result;
                        if (!c) return;
                        if (c.value.id < 0 && c.value.barcode && barcodes[c.value.barcode]) c['delete']();
                        c['continue']();
                    };
                }).then(function () {
                    return Store.meta('dataVersion', d.data_version);
                }).then(function () {
                    return Store.meta('productsSince', d.server_time);
                }).then(function () {
                    state.lastSync = new Date();
                    return Store.meta('lastSyncAt', state.lastSync.toISOString());
                }).then(function () { return d; });
            });
        });
    }

    /* ---------- Customers (udhaar) ---------- */

    function syncCustomers() {
        return api('api/customers.php').then(function (d) {
            return tx('customers', 'readwrite', function (s) {
                var server = {};
                d.items.forEach(function (c) { server[c.uuid] = 1; });
                var keep = [];
                var cur = s.openCursor();
                cur.onsuccess = function () {
                    var c = cur.result;
                    if (c) { if (c.value.local && !server[c.value.uuid]) keep.push(c.value); c['continue'](); return; }
                    s.clear().onsuccess = function () {
                        d.items.forEach(function (x) { s.put(x); });
                        keep.forEach(function (x) { s.put(x); });
                    };
                };
            });
        })['catch'](function (e) { if (e && e.auth) throw e; /* staff ke paas ijazat na ho to chhor do */ });
    }

    /* ---------- Outbox ---------- */

    function queue(kind, payload) {
        return Store.put('outbox', { kind: kind, payload: payload, created: new Date().toISOString(), error: null })
            .then(updatePending);
    }

    function flushOutbox() {
        return Store.getAll('outbox').then(function (items) {
            var chain = Promise.resolve(), failed = 0;
            items.forEach(function (it) {
                chain = chain.then(function () {
                    return api('api/sync.php', { kind: it.kind, payload: it.payload }).then(function () {
                        return Store.del('outbox', it.seq);
                    }).catch(function (err) {
                        if (err && err.status === 422) { // server ne data reject kiya — rakh lo, aage chalo
                            failed++;
                            it.error = (err.data && err.data.message) || 'Rejected';
                            return Store.put('outbox', it);
                        }
                        throw err; // net / login masla — ruk jao
                    });
                });
            });
            return chain.then(function () { state.failed = failed; });
        });
    }

    function updatePending() {
        return Store.count('outbox').then(function (n) { state.pending = n; emit(); return n; });
    }

    var syncTimer = null;
    function sync() {
        if (state.syncing) return Promise.resolve();
        if (!navigator.onLine) { state.online = false; emit(); return updatePending(); }
        state.syncing = true; emit();
        return flushOutbox()
            .then(syncProducts)
            .then(syncCustomers)
            .then(function () { state.online = true; state.auth = true; })
            .catch(function (err) {
                if (err && err.auth) state.auth = false;
                else if (!err || !err.status) state.online = false;
            })
            .then(function () { state.syncing = false; return updatePending(); });
    }

    function scheduleSync(ms) {
        clearTimeout(syncTimer);
        syncTimer = setTimeout(function () { sync().then(function () { scheduleSync(60000); }); }, ms);
    }

    function emit() {
        listeners.forEach(function (fn) { try { fn(state); } catch (e) { /* ignore */ } });
        var pill = document.getElementById('syncPill');
        if (pill) {
            var txt = !state.auth ? 'Please log in again' : state.pending ? state.pending + ' bill(s) to sync' : (!state.online ? 'Offline' : '');
            pill.textContent = txt;
            pill.hidden = !txt;
        }
    }

    global.addEventListener('online', function () { state.online = true; emit(); scheduleSync(500); });
    global.addEventListener('offline', function () { state.online = false; emit(); });

    if ('serviceWorker' in navigator && (location.protocol === 'https:' || location.hostname === 'localhost' || location.hostname === '127.0.0.1')) {
        global.addEventListener('load', function () {
            navigator.serviceWorker.register('sw.js', { scope: './' })['catch'](function () { /* ignore */ });
        });
    }

    global.POS = {
        store: Store,
        state: state,
        api: api,
        queue: queue,
        sync: sync,
        syncProducts: syncProducts,
        scheduleSync: scheduleSync,
        onStatus: function (fn) { listeners.push(fn); fn(state); },
        uuid: function () {
            if (global.crypto && crypto.randomUUID) return crypto.randomUUID();
            return 'xxxxxxxx-xxxx-4xxx-yxxx-xxxxxxxxxxxx'.replace(/[xy]/g, function (c) {
                var r = Math.random() * 16 | 0; return (c === 'x' ? r : (r & 0x3 | 0x8)).toString(16);
            });
        }
    };

    Store.meta('lastSyncAt').then(function (v) { if (v) state.lastSync = new Date(v); emit(); })['catch'](function () { /* ignore */ });
    scheduleSync(800);
})(window);
