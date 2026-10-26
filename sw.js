/* MartPOS service worker — net ke baghair billing screen khulti rahe */
'use strict';

var VERSION = '1.4.1';
var CACHE = 'martpos-' + VERSION;
var ASSETS = [
    'offline.html',
    'manifest.webmanifest',
    'assets/css/app.css?v=' + VERSION,
    'assets/js/store.js?v=' + VERSION,
    'assets/js/receipt.js?v=' + VERSION,
    'assets/js/billing.js?v=' + VERSION,
    'assets/icons/icon-192.png',
    'assets/icons/icon-512.png'
];

/** Billing page ki taaza copy cache mein (sirf jab login ho) */
function cacheBilling() {
    return fetch('billing.php', { credentials: 'same-origin', cache: 'no-store' }).then(function (res) {
        if (res.ok && !res.redirected) {
            return caches.open(CACHE).then(function (c) { return c.put('billing.php', res); });
        }
    })['catch'](function () { /* offline — koi baat nahi */ });
}

self.addEventListener('install', function (ev) {
    ev.waitUntil(caches.open(CACHE).then(function (c) { return c.addAll(ASSETS); })
        .then(cacheBilling)
        .then(function () { return self.skipWaiting(); }));
});

self.addEventListener('message', function (ev) {
    if (ev.data === 'cache-billing') {
        ev.waitUntil ? ev.waitUntil(cacheBilling()) : cacheBilling();
    }
});

self.addEventListener('activate', function (ev) {
    ev.waitUntil(caches.keys().then(function (keys) {
        return Promise.all(keys.filter(function (k) { return k.indexOf('martpos-') === 0 && k !== CACHE; })
            .map(function (k) { return caches.delete(k); }));
    }).then(function () { return self.clients.claim(); }));
});

function timeout(ms, p) {
    return new Promise(function (resolve, reject) {
        var t = setTimeout(function () { reject(new Error('timeout')); }, ms);
        p.then(function (v) { clearTimeout(t); resolve(v); }, function (e) { clearTimeout(t); reject(e); });
    });
}

self.addEventListener('fetch', function (ev) {
    var req = ev.request;
    if (req.method !== 'GET') return;
    var url = new URL(req.url);
    if (url.origin !== self.location.origin) return;
    if (url.pathname.indexOf('/api/') !== -1) return; // API hamesha network se

    var isBilling = /\/billing\.php$/.test(url.pathname);

    // Billing page: pehle network (4 sec), na mile to cache
    if (isBilling) {
        ev.respondWith(
            timeout(4000, fetch(req)).then(function (res) {
                if (res.ok && !res.redirected) {
                    var copy = res.clone();
                    caches.open(CACHE).then(function (c) { c.put('billing.php', copy); });
                }
                return res;
            })['catch'](function () {
                return caches.match('billing.php').then(function (r) { return r || caches.match('offline.html'); });
            })
        );
        return;
    }

    // CSS / JS / icons: cache se, peeche se update
    if (url.pathname.indexOf('/assets/') !== -1 || /manifest\.webmanifest$/.test(url.pathname)) {
        ev.respondWith(caches.match(req).then(function (hit) {
            var net = fetch(req).then(function (res) {
                if (res.ok) { var copy = res.clone(); caches.open(CACHE).then(function (c) { c.put(req, copy); }); }
                return res;
            })['catch'](function () { return hit; });
            return hit || net;
        }));
        return;
    }

    // Baqi pages: network, na mile to offline page
    if (req.mode === 'navigate') {
        ev.respondWith(fetch(req)['catch'](function () { return caches.match('offline.html'); }));
    }
});
