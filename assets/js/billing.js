/* MartPOS — Billing screen */
(function () {
    'use strict';

    var SHOP = window.SHOP;
    var S = POS.store;
    var $ = function (id) { return document.getElementById(id); };
    var money = Receipt.money, fmtQty = Receipt.qty;
    var MEASURED = { kg: 1, ltr: 1, m: 1 };

    var byId = {}, byBarcode = {}, byQuick = {}, list = [];
    var cart = [], sel = -1, discount = 0, mult = null, approvals = [];
    var suggestions = [], sugSel = 0;
    var lastSale = null, openModalEl = null, lastSyncSeen = null;

    var scan = $('scan'), cash = $('cash');

    /* ---------- helpers ---------- */
    function r2(n) { return Math.round((+n || 0) * 100) / 100; }
    function r3(n) { return Math.round((+n || 0) * 1000) / 1000; }
    function num(v) { v = String(v == null ? '' : v).replace(/,/g, '').trim(); return v === '' ? NaN : parseFloat(v); }
    function pad(n, l) { n = String(n); while (n.length < l) n = '0' + n; return n; }

    var toastTimer = null;
    function toast(msg, kind, ms) {
        var t = $('toast');
        if (!t) { t = document.createElement('div'); t.id = 'toast'; document.body.appendChild(t); }
        t.className = 'toast ' + (kind || '');
        t.textContent = msg;
        t.hidden = false;
        clearTimeout(toastTimer);
        toastTimer = setTimeout(function () { t.hidden = true; }, ms || 2600);
    }

    function beep(ok) {
        try {
            var ctx = beep.ctx || (beep.ctx = new (window.AudioContext || window.webkitAudioContext)());
            var o = ctx.createOscillator(), g = ctx.createGain();
            o.frequency.value = ok ? 1250 : 320;
            g.gain.value = 0.05;
            o.connect(g); g.connect(ctx.destination);
            o.start(); o.stop(ctx.currentTime + (ok ? 0.06 : 0.25));
        } catch (e) { /* ignore */ }
    }

    function localTs(d) {
        d = d || new Date();
        return d.getFullYear() + '-' + pad(d.getMonth() + 1, 2) + '-' + pad(d.getDate(), 2) + ' ' +
            pad(d.getHours(), 2) + ':' + pad(d.getMinutes(), 2) + ':' + pad(d.getSeconds(), 2);
    }

    function nextBillNo() {
        var d = new Date(Date.now() - (RULES.day_start_hour || 0) * 3600000); // kaarobari din
        var day = String(d.getFullYear()).slice(2) + pad(d.getMonth() + 1, 2) + pad(d.getDate(), 2);
        var seq = {};
        try { seq = JSON.parse(localStorage.getItem('martpos.seq') || '{}'); } catch (e) { seq = {}; }
        var n = seq.d === day ? seq.n + 1 : 1;
        try { localStorage.setItem('martpos.seq', JSON.stringify({ d: day, n: n })); } catch (e) { /* ignore */ }
        var dev = '';
        try { dev = (localStorage.getItem('martpos.device') || '').replace(/[^A-Z0-9]/gi, '').toUpperCase(); } catch (e) { /* ignore */ }
        return (dev ? dev + '-' : '') + day + '-' + pad(n, 3);
    }

    /* ---------- products (local copy) ---------- */
    function loadProducts() {
        return S.getAll('products').then(function (rows) {
            byId = {}; byBarcode = {}; byQuick = {}; list = [];
            rows.forEach(function (p) {
                if (p.deleted) return;
                byId[p.id] = p;
                list.push(p);
                if (p.barcode) byBarcode[p.barcode] = p;
                if (p.quick != null) byQuick[p.quick] = p;
            });
            list.sort(function (a, b) { return a.name.localeCompare(b.name); });
            renderQuick();
            // cart ke rates update na karo (bill ke beech price na badle), sirf naam
            return list.length;
        });
    }

    function findByCode(code) {
        var p = byBarcode[code];
        if (!p && /^\d{12}$/.test(code)) p = byBarcode['0' + code];
        if (!p && /^0\d{12}$/.test(code)) p = byBarcode[code.slice(1)];
        return p || null;
    }

    function renderQuick() {
        var keys = Object.keys(byQuick).map(Number).sort(function (a, b) { return a - b; });
        var grid = $('quickGrid');
        grid.innerHTML = '';
        $('quickBox').hidden = !keys.length;
        keys.forEach(function (k) {
            var p = byQuick[k];
            var b = document.createElement('button');
            b.type = 'button';
            b.innerHTML = '<span class="qk"></span><span class="qn"></span><span class="qp"></span>';
            b.querySelector('.qk').textContent = k;
            b.querySelector('.qn').textContent = p.name;
            b.querySelector('.qp').textContent = money(p.price) + (p.unit !== 'pcs' ? ' / ' + p.unit : '');
            b.addEventListener('click', function () {
                var q = mult; setMult(null);
                addProduct(p, q);
            });
            grid.appendChild(b);
        });
    }

    /* ---------- cart ---------- */
    function totals() {
        var sub = 0;
        cart.forEach(function (it) { sub += r2(it.qty * it.price); });
        sub = r2(sub);
        var d = Math.min(Math.max(0, discount), sub);
        return { sub: sub, disc: d, total: r2(sub - d) };
    }

    function saveDraft() {
        try { localStorage.setItem('martpos.cart', JSON.stringify({ cart: cart, discount: discount, sel: sel, approvals: approvals })); } catch (e) { /* ignore */ }
    }

    function render(flashIdx) {
        var body = $('cartBody');
        body.innerHTML = '';
        cart.forEach(function (it, i) {
            var tr = document.createElement('tr');
            if (i === sel) tr.className = 'sel';
            if (i === flashIdx) tr.className += ' flash';
            tr.innerHTML =
                '<td class="muted">' + (i + 1) + '</td>' +
                '<td><div class="item-name"></div><div class="item-bc"></div></td>' +
                '<td class="num"><span class="qty-ctl"><button type="button" tabindex="-1" data-a="dec">&minus;</button><input inputmode="decimal"><button type="button" tabindex="-1" data-a="inc">+</button></span></td>' +
                '<td class="num"></td><td class="num"><b></b></td>' +
                '<td><button class="del" type="button" tabindex="-1" title="Remove (Del)"><svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round"><path d="M18 6L6 18M6 6l12 12"/></svg></button></td>';
            tr.querySelector('.item-name').textContent = it.name;
            tr.querySelector('.item-bc').textContent = [it.barcode, it.unit !== 'pcs' ? it.unit : ''].filter(Boolean).join('  ·  ');
            var inp = tr.querySelector('input');
            inp.value = fmtQty(it.qty);
            tr.children[3].textContent = money(it.price);
            tr.children[3].title = 'Click to change the rate (F2)';
            tr.children[3].style.cursor = 'pointer';
            if (it.base != null && it.price !== it.base) tr.children[3].innerHTML = '<s class="muted small">' + money(it.base) + '</s> ' + money(it.price);

            tr.children[4].querySelector('b').textContent = money(r2(it.qty * it.price));

            tr.addEventListener('mousedown', function (ev) {
                if (ev.target.closest('input,button')) { sel = i; return; }
                if (ev.target.closest('td') === tr.children[3]) { ev.preventDefault(); sel = i; render(); openQty(true); return; }
                sel = i; render(); setTimeout(focusScan, 0);
            });
            inp.addEventListener('focus', function () { sel = i; inp.select(); });
            inp.addEventListener('change', function () { setQty(i, num(inp.value)); });
            inp.addEventListener('keydown', function (ev) {
                if (ev.key === 'Enter' || ev.key === 'Escape') { ev.preventDefault(); ev.stopPropagation(); setQty(i, num(inp.value)); focusScan(); }
            });
            tr.querySelector('[data-a=dec]').addEventListener('click', function () { bump(i, -1); focusScan(); });
            tr.querySelector('[data-a=inc]').addEventListener('click', function () { bump(i, 1); focusScan(); });
            tr.querySelector('.del').addEventListener('click', function () { removeAt(i); focusScan(); });
            body.appendChild(tr);
        });
        $('cartEmpty').hidden = cart.length > 0;
        var count = cart.reduce(function (a, it) { return a + (MEASURED[it.unit] ? 1 : it.qty); }, 0);
        $('itemCount').textContent = cart.length + (cart.length === 1 ? ' line · ' : ' lines · ') + fmtQty(count) + (count === 1 ? ' item' : ' items');

        var t = totals();
        $('tSub').textContent = money(t.sub);
        $('tDiscRow').hidden = !t.disc;
        $('tDisc').textContent = '-' + money(t.disc);
        $('tTotal').textContent = money(t.total);
        updateChange();
        saveDraft();

        var selRow = body.children[sel];
        if (selRow) selRow.scrollIntoView({ block: 'nearest' });
    }

    function updateChange() {
        var t = totals(), paid = num(cash.value), row = $('changeRow');
        if (isNaN(paid) || !cart.length) {
            $('tChange').textContent = '0'; row.classList.remove('short');
            row.firstChild.textContent = 'Change';
            return;
        }
        var ch = r2(paid - t.total);
        row.classList.toggle('short', ch < 0);
        row.firstChild.textContent = ch < 0 ? 'Short' : 'Change';
        $('tChange').textContent = money(Math.abs(ch));
    }

    function addItem(p, q) {
        q = r3(q || 1);
        if (q <= 0) return;
        var idx = -1;
        for (var i = 0; i < cart.length; i++) {
            if (cart[i].pid === p.id && cart[i].price === p.price) { idx = i; break; }
        }
        if (idx >= 0) {
            cart[idx].qty = r3(cart[idx].qty + q);
        } else {
            cart.push({ pid: p.id, barcode: p.barcode || null, name: p.name, unit: p.unit || 'pcs', price: +p.price, base: +p.price, qty: q });
            idx = cart.length - 1;
        }
        sel = idx;
        render(idx);
        beep(true);
    }

    function addProduct(p, q) {
        if (MEASURED[p.unit] && !q) { openWeigh(p); return; }
        addItem(p, q || 1);
    }

    function setQty(i, q) {
        if (!cart[i]) return;
        if (isNaN(q)) { render(); return; }
        if (q <= 0) { removeAt(i); return; }
        cart[i].qty = r3(q);
        render();
    }

    function bump(i, d) {
        if (!cart[i]) return;
        setQty(i, cart[i].qty + d);
    }

    function removeAt(i) {
        if (!cart[i]) return;
        cart.splice(i, 1);
        if (sel >= cart.length) sel = cart.length - 1;
        render();
    }

    function clearBill() {
        cart = []; sel = -1; discount = 0; cash.value = ''; approvals = [];
        setMult(null);
        render();
    }

    function setMult(q) {
        mult = q;
        $('multChip').hidden = !q;
        $('multChip').textContent = q ? '× ' + fmtQty(q) : '';
    }

    /* ---------- scanning ---------- */
    function focusScan() {
        if (openModalEl) return;
        scan.focus();
    }

    function handleEnter() {
        var raw = scan.value.trim();
        scan.value = '';
        var picked = !$('suggest').hidden && suggestions.length ? suggestions[sugSel] : null;
        hideSuggest();

        if (raw === '') {
            if (picked) { var q0 = mult; setMult(null); addProduct(picked, q0); return; }
            if (cart.length) { cash.focus(); cash.select(); }
            return;
        }

        var q = mult, m = /^(\d+(?:\.\d+)?)\s*[*xX]\s*(.*)$/.exec(raw);
        if (m) {
            var mq = parseFloat(m[1]);
            raw = m[2].trim();
            if (!raw) {
                if (mq > 0) setMult(mq);
                return;
            }
            q = mq;
        }
        setMult(null);

        var p = findByCode(raw);
        if (!p && /^\d{1,3}$/.test(raw)) p = byQuick[+raw] || null;
        if (p) { addProduct(p, q); return; }

        if (picked && /[a-z]/i.test(raw)) { addProduct(picked, q); return; }

        if (/^[\x21-\x7E]{4,64}$/.test(raw) && !/[a-z]{3,}/i.test(raw.replace(/\d/g, ''))) {
            beep(false);
            openNew(raw, q);
            return;
        }
        var found = searchByName(raw);
        if (found.length === 1) { addProduct(found[0], q); return; }
        if (found.length) { scan.value = raw; setMult(q); showSuggest(found); return; }
        beep(false);
        toast('"' + raw + '" not found', 'err');
    }

    function searchByName(text) {
        var words = text.toLowerCase().split(/\s+/).filter(Boolean);
        if (!words.length) return [];
        var out = [];
        for (var i = 0; i < list.length && out.length < 8; i++) {
            var n = list[i].name.toLowerCase(), ok = true;
            for (var w = 0; w < words.length; w++) if (n.indexOf(words[w]) === -1) { ok = false; break; }
            if (ok) out.push(list[i]);
        }
        return out;
    }

    function showSuggest(items) {
        suggestions = items; sugSel = 0;
        var box = $('suggest');
        box.innerHTML = '';
        if (!items.length) { box.hidden = true; return; }
        items.forEach(function (p, i) {
            var b = document.createElement('button');
            b.type = 'button';
            if (i === sugSel) b.className = 'sel';
            b.innerHTML = '<span><b></b><br><span class="bc"></span></span><span class="num"></span>';
            b.querySelector('b').textContent = p.name;
            b.querySelector('.bc').textContent = p.barcode || (p.quick != null ? 'Quick ' + p.quick : '');
            b.querySelector('.num').textContent = money(p.price) + (p.unit !== 'pcs' ? ' / ' + p.unit : '');
            b.addEventListener('mousedown', function (ev) {
                ev.preventDefault();
                scan.value = ''; hideSuggest();
                var q = mult; setMult(null);
                addProduct(p, q);
            });
            box.appendChild(b);
        });
        box.hidden = false;
    }

    function hideSuggest() { suggestions = []; $('suggest').hidden = true; }

    scan.addEventListener('input', function () {
        var v = scan.value.trim().replace(/^\d+(?:\.\d+)?\s*[*xX]\s*/, '');
        if (v.length >= 2 && /[a-z]/i.test(v)) showSuggest(searchByName(v));
        else hideSuggest();
    });

    scan.addEventListener('keydown', function (ev) {
        var sugOpen = !$('suggest').hidden && suggestions.length;
        if (ev.key === 'Enter') { ev.preventDefault(); handleEnter(); return; }
        if (sugOpen && (ev.key === 'ArrowDown' || ev.key === 'ArrowUp')) {
            ev.preventDefault();
            sugSel = (sugSel + (ev.key === 'ArrowDown' ? 1 : -1) + suggestions.length) % suggestions.length;
            Array.prototype.forEach.call($('suggest').children, function (b, i) { b.className = i === sugSel ? 'sel' : ''; });
            return;
        }
        if (scan.value !== '') return;
        if (ev.key === 'ArrowDown' || ev.key === 'ArrowUp') {
            ev.preventDefault();
            if (!cart.length) return;
            sel = Math.max(0, Math.min(cart.length - 1, sel + (ev.key === 'ArrowDown' ? 1 : -1)));
            render();
        } else if (ev.key === '+' || ev.key === '-') {
            ev.preventDefault();
            if (sel >= 0) bump(sel, ev.key === '+' ? 1 : -1);
        } else if (ev.key === 'Delete') {
            ev.preventDefault();
            if (sel >= 0) removeAt(sel);
        } else if (ev.key === 'End') {
            ev.preventDefault();
            if (cart.length) { cash.focus(); cash.select(); }
        }
    });

    /* ---------- payment ---------- */
    cash.addEventListener('input', updateChange);
    cash.addEventListener('keydown', function (ev) {
        if (ev.key === 'Enter') { ev.preventDefault(); complete(); }
        else if (ev.key === 'Escape') { ev.preventDefault(); focusScan(); }
    });
    $('notes').addEventListener('click', function (ev) {
        var b = ev.target.closest('button');
        if (!b) return;
        var t = totals();
        cash.value = b.dataset.note === 'exact' ? t.total : b.dataset.note;
        updateChange();
        cash.focus();
    });
    $('payBtn').addEventListener('click', complete);

    /** Cash wala seedha raasta: paisay likho, Enter */
    function complete() {
        if (!cart.length) { toast('The bill is empty — scan a product first', 'err'); focusScan(); return; }
        var t = totals();
        var paid = num(cash.value);
        if (isNaN(paid)) paid = t.total;
        if (paid < t.total - 0.004) {
            beep(false);
            toast('Not enough cash: ' + money(r2(t.total - paid)) + ' more needed (or choose Split / Credit)', 'err');
            cash.focus(); cash.select();
            return;
        }
        finalize([{ method: 'cash', amount: r2(paid) }], null);
    }

    var completing = false;
    /** Bill save (pehle is PC mein, phir server) + print */
    function finalize(payments, customer) {
        if (completing || !cart.length) return;
        var t = totals();
        var cashT = 0, other = 0, udhaar = 0;
        payments.forEach(function (p) {
            if (p.method === 'cash') cashT += p.amount; else other += p.amount;
            if (p.method === 'udhaar') udhaar += p.amount;
        });
        var change = r2(cashT - Math.max(0, t.total - other));
        completing = true;
        var sale = {
            uuid: POS.uuid(),
            bill_no: nextBillNo(),
            created_at: localTs(),
            user_id: ME.id,
            cashier: ME.name,
            items: cart.map(function (it) {
                return { product_id: it.pid > 0 ? it.pid : 0, barcode: it.barcode, name: it.name, unit: it.unit, qty: it.qty, price: it.price };
            }),
            subtotal: t.sub,
            discount: t.disc,
            total: t.total,
            paid: r2(cashT + other),
            change: change,
            payments: payments.filter(function (p) { return p.amount > 0; }),
            approvals: approvals.slice()
        };
        if (customer) {
            sale.customer = { uuid: customer.uuid, name: customer.name, phone: customer.phone || null };
            sale.customer_prev = customer.balance || 0; // receipt ke liye
        }
        var save = POS.queue('sale', sale);
        if (customer && udhaar > 0) {
            customer.balance = r2((customer.balance || 0) + udhaar);
            save = save.then(function () { return S.put('customers', customer); });
        }
        save.then(function () {
            lastSale = sale;
            try { localStorage.setItem('martpos.last', JSON.stringify(sale)); } catch (e) { /* ignore */ }
            clearBill();
            closeModal();
            showLast();
            toast('Bill ' + sale.bill_no + (change > 0 ? ' · Change: ' + SHOP.currency + ' ' + money(change) : '') +
                (udhaar > 0 ? ' · Credit: ' + money(udhaar) : ''), 'ok', 5000);
            return Receipt.print(sale, SHOP);
        }).then(function () {
            completing = false;
            focusScan();
            POS.sync();
        })['catch'](function (e) {
            completing = false;
            alert('The bill could not be saved! Check the browser storage.\n' + (e && e.message ? e.message : ''));
        });
    }

    /* ---------- Payment modal (JazzCash, Easypaisa, Bank, Card, Udhaar, Split) ---------- */
    var METHODS = { cash: 'Cash', jazzcash: 'JazzCash', easypaisa: 'Easypaisa', bank: 'Bank transfer', card: 'Card', udhaar: 'Credit' };
    var pmLines = [], pmCustomer = null, customersCache = [], custSel = 0;

    function openPay(method) {
        if (!cart.length) { toast('The bill is empty — scan a product first', 'err'); return; }
        var t = totals();
        pmCustomer = null;
        if (method === 'split') {
            var c = num(cash.value);
            pmLines = [{ method: 'cash', amount: c > 0 ? Math.min(c, t.total) : 0, ref: '' }, { method: 'jazzcash', amount: 0, ref: '' }];
            var left = r2(t.total - pmLines[0].amount);
            pmLines[1].amount = left > 0 ? left : 0;
        } else {
            pmLines = [{ method: method, amount: t.total, ref: '' }];
        }
        $('pmTotal').textContent = SHOP.currency + ' ' + money(t.total);
        $('pmCustQ').value = ''; $('pmNewName').value = ''; $('pmNewPhone').value = '';
        S.getAll('customers').then(function (rows) {
            customersCache = rows.filter(function (c) { return c.active !== false; })
                .sort(function (a, b) { return a.name.localeCompare(b.name); });
            renderPay();
            openModal('payModal');
            setTimeout(function () {
                if ($('payModal').contains(document.activeElement)) return; // user pehle hi type kar raha hai
                var target = hasUdhaar() ? $('pmCustQ') : $('pmLines').querySelector(pmLines.length > 1 ? '.amt' : '.ref:not([hidden])') || $('pmOk');
                target.focus(); if (target.select) target.select();
            }, 40);
        });
    }

    function hasUdhaar() { return pmLines.some(function (l) { return l.method === 'udhaar'; }); }

    function renderPay() {
        var box = $('pmLines');
        box.innerHTML = '';
        pmLines.forEach(function (l, i) {
            var row = document.createElement('div');
            row.className = 'pm-line';
            var sel = document.createElement('select');
            sel.className = 'input';
            Object.keys(METHODS).forEach(function (k) {
                var o = document.createElement('option'); o.value = k; o.textContent = METHODS[k];
                if (k === l.method) o.selected = true;
                sel.appendChild(o);
            });
            sel.addEventListener('change', function () { l.method = sel.value; renderPay(); });
            var amt = document.createElement('input');
            amt.className = 'input amt'; amt.inputMode = 'decimal'; amt.value = l.amount ? l.amount : '';
            amt.placeholder = '0';
            amt.addEventListener('input', function () { l.amount = r2(num(amt.value) || 0); updatePaySum(); });
            var ref = document.createElement('input');
            ref.className = 'input ref'; ref.maxLength = 80;
            ref.placeholder = l.method === 'card' ? 'Slip / last 4 digits (optional)' : 'Transaction ID (optional)';
            ref.value = l.ref || '';
            ref.hidden = l.method === 'cash' || l.method === 'udhaar';
            ref.addEventListener('input', function () { l.ref = ref.value.trim(); });
            var x = document.createElement('button');
            x.type = 'button'; x.className = 'x'; x.innerHTML = '&times;'; x.title = 'Remove';
            x.hidden = pmLines.length < 2;
            x.addEventListener('click', function () { pmLines.splice(i, 1); renderPay(); });
            var spacer = document.createElement('span');
            row.appendChild(sel); row.appendChild(amt);
            row.appendChild(ref.hidden ? spacer : ref);
            row.appendChild(x);
            box.appendChild(row);
        });
        // Dukaan ke account numbers
        var acc = $('pmAccounts'), html = [];
        pmLines.forEach(function (l) {
            var a = SHOP.accounts && SHOP.accounts[l.method];
            if (a && html.indexOf(l.method) === -1) html.push(l.method);
        });
        acc.innerHTML = '';
        html.forEach(function (k) {
            var d = document.createElement('div');
            d.innerHTML = '<b></b> ';
            d.querySelector('b').textContent = SHOP.accounts[k].label + ':';
            d.appendChild(document.createTextNode(SHOP.accounts[k].value));
            acc.appendChild(d);
        });
        acc.hidden = !html.length;
        $('pmCustBox').hidden = !hasUdhaar();
        renderCustomer();
        updatePaySum();
    }

    function paySummary() {
        var t = totals(), cashT = 0, other = 0, udhaar = 0;
        pmLines.forEach(function (l) {
            if (l.method === 'cash') cashT += l.amount || 0; else other += l.amount || 0;
            if (l.method === 'udhaar') udhaar += l.amount || 0;
        });
        var need = r2(t.total - other);
        return { total: t.total, cash: r2(cashT), other: r2(other), udhaar: r2(udhaar), short: r2(need - cashT), change: r2(cashT - Math.max(0, need)), over: other > t.total + 0.004 };
    }

    function updatePaySum() {
        var s = paySummary(), el = $('pmSum');
        var parts = ['<span>Received: <b>' + money(r2(s.cash + s.other)) + '</b></span>'];
        if (s.over) parts.push('<span class="bad">Non-cash payments are more than the total</span>');
        else if (s.short > 0.004) parts.push('<span class="bad">Remaining: ' + money(s.short) + '</span>');
        else parts.push('<span class="good">' + (s.change > 0.004 ? 'Change (cash): ' + money(s.change) : 'Paid in full') + '</span>');
        el.innerHTML = parts.join('');
    }

    $('pmAdd').addEventListener('click', function () {
        var s = paySummary();
        pmLines.push({ method: pmLines.some(function (l) { return l.method === 'cash'; }) ? 'jazzcash' : 'cash', amount: s.short > 0 ? s.short : 0, ref: '' });
        renderPay();
        var amts = $('pmLines').querySelectorAll('.amt');
        amts[amts.length - 1].focus();
    });

    // --- Udhaar customer ---
    function renderCustomer() {
        var picked = $('pmCustPicked'), search = $('pmCustSearch'), info = $('pmCustInfo');
        if (pmCustomer) {
            picked.hidden = false; search.hidden = true;
            picked.innerHTML = '<span><b></b><br><span class="small muted"></span></span><button class="btn sm" type="button">Change</button>';
            picked.querySelector('b').textContent = pmCustomer.name;
            picked.querySelector('.muted').textContent = (pmCustomer.phone || '') + (pmCustomer.local ? '  (new)' : '');
            picked.querySelector('button').addEventListener('click', function () { pmCustomer = null; renderCustomer(); $('pmCustQ').focus(); });
            var lim = pmCustomer.limit;
            info.textContent = 'Previous balance: ' + money(pmCustomer.balance || 0) + (lim != null ? ' · Limit: ' + money(lim) : '');
        } else {
            picked.hidden = true; search.hidden = false; info.textContent = '';
            renderCustList();
        }
    }

    function renderCustList() {
        var q = $('pmCustQ').value.trim().toLowerCase(), list = $('pmCustList');
        var rows = customersCache.filter(function (c) {
            return !q || c.name.toLowerCase().indexOf(q) !== -1 || (c.phone || '').replace(/\D/g, '').indexOf(q.replace(/\D/g, '') || '§') !== -1;
        }).slice(0, 8);
        custSel = Math.min(custSel, Math.max(0, rows.length - 1));
        list.innerHTML = '';
        rows.forEach(function (c, i) {
            var b = document.createElement('button');
            b.type = 'button';
            if (i === custSel) b.className = 'sel';
            b.innerHTML = '<span><b></b> <span class="small muted"></span></span><span class="small"></span>';
            b.querySelector('b').textContent = c.name;
            b.querySelector('.muted').textContent = c.phone || '';
            b.lastChild.textContent = 'due ' + money(c.balance || 0);
            b.addEventListener('click', function () { pickCustomer(c); });
            list.appendChild(b);
        });
        list._rows = rows;
        $('pmNewCust').hidden = !q || rows.some(function (c) { return c.name.toLowerCase() === q; });
        if (!$('pmNewCust').hidden && !$('pmNewName').value) $('pmNewName').value = $('pmCustQ').value.trim();
    }

    function pickCustomer(c) { pmCustomer = c; renderCustomer(); $('pmOk').focus(); }

    $('pmCustQ').addEventListener('input', function () { custSel = 0; $('pmNewName').value = ''; renderCustList(); });
    $('pmCustQ').addEventListener('keydown', function (ev) {
        var rows = $('pmCustList')._rows || [];
        if (ev.key === 'ArrowDown' || ev.key === 'ArrowUp') {
            ev.preventDefault();
            if (rows.length) { custSel = (custSel + (ev.key === 'ArrowDown' ? 1 : -1) + rows.length) % rows.length; renderCustList(); }
        } else if (ev.key === 'Enter') {
            ev.preventDefault();
            if (rows[custSel]) pickCustomer(rows[custSel]);
            else if (!$('pmNewCust').hidden) $('pmNewPhone').focus();
        }
    });
    $('pmNewPhone').addEventListener('keydown', function (ev) {
        if (ev.key === 'Enter') { ev.preventDefault(); $('payForm').requestSubmit(); }
    });

    $('payForm').addEventListener('submit', function (ev) {
        ev.preventDefault();
        var s = paySummary();
        if (s.over) { toast('JazzCash / Card / Credit etc. cannot be more than the bill total', 'err'); return; }
        if (s.short > 0.004) { toast('Payment incomplete — ' + money(s.short) + ' remaining', 'err'); return; }
        var customer = null;
        if (s.udhaar > 0) {
            if (!pmCustomer) {
                var nn = $('pmNewName').value.trim();
                if (!nn) { toast('Choose a customer for credit, or type a new name', 'err'); $('pmCustQ').focus(); return; }
                pmCustomer = { uuid: POS.uuid(), name: nn, phone: $('pmNewPhone').value.trim() || null, balance: 0, limit: RULES.default_limit, local: true, active: true };
            }
            customer = pmCustomer;
        }
        var go = function () {
            var lines = pmLines.filter(function (l) { return l.amount > 0; }).map(function (l) {
                return { method: l.method, amount: r2(l.amount), ref: l.method === 'cash' || l.method === 'udhaar' ? '' : (l.ref || '') };
            });
            finalize(lines, customer);
        };
        if (customer && !RULES.admin && customer.limit != null && r2((customer.balance || 0) + s.udhaar) > customer.limit + 0.004) {
            askPin('Credit limit exceeded: ' + customer.name + "'s balance will be " + money(r2((customer.balance || 0) + s.udhaar)) + ' (limit ' + money(customer.limit) + ')')
                .then(function (admin) {
                    approvals.push({ what: 'Credit limit exceeded: ' + customer.name + ' ' + money(s.udhaar), admin_id: admin.id });
                    go();
                }, function () { /* cancel */ });
            return;
        }
        go();
    });

    /* ---------- Admin PIN (staff ke liye) ---------- */
    var pinResolve = null, pinReject = null;
    function hex(buf) { return Array.prototype.map.call(new Uint8Array(buf), function (b) { return ('0' + b.toString(16)).slice(-2); }).join(''); }
    function pbkdf2(pin, salt) {
        var enc = new TextEncoder();
        return crypto.subtle.importKey('raw', enc.encode(pin), 'PBKDF2', false, ['deriveBits']).then(function (key) {
            return crypto.subtle.deriveBits({ name: 'PBKDF2', salt: enc.encode(salt), iterations: RULES.iterations, hash: 'SHA-256' }, key, 256);
        }).then(hex);
    }
    function askPin(why) {
        return new Promise(function (resolve, reject) {
            if (RULES.admin) { resolve({ id: ME.id, name: ME.name }); return; }
            if (!RULES.pins || !RULES.pins.length) {
                toast('The owner has not set a PIN yet — set one on the Staff page', 'err', 5000);
                reject(); return;
            }
            var prevModal = openModalEl;
            if (prevModal) prevModal.hidden = true;
            pinResolve = function (a) { $('pinModal').hidden = true; openModalEl = prevModal; if (prevModal) prevModal.hidden = false; resolve(a); };
            pinReject = function () { $('pinModal').hidden = true; openModalEl = prevModal; if (prevModal) prevModal.hidden = false; reject(); };
            $('pinWhy').textContent = why;
            $('pinVal').value = ''; $('pinMsg').textContent = '';
            $('pinModal').hidden = false;
            openModalEl = $('pinModal');
            setTimeout(function () { $('pinVal').focus(); }, 30);
        });
    }
    $('pinForm').addEventListener('submit', function (ev) {
        ev.preventDefault();
        var pin = $('pinVal').value.trim();
        var lock = {};
        try { lock = JSON.parse(localStorage.getItem('martpos.pinlock') || '{}'); } catch (e) { lock = {}; }
        if (lock.until && lock.until > Date.now()) {
            $('pinMsg').textContent = 'Too many wrong attempts — try in ' + Math.ceil((lock.until - Date.now()) / 60000) + ' min';
            return;
        }
        if (!/^\d{4,8}$/.test(pin)) { $('pinMsg').textContent = 'PIN is 4-8 digits'; return; }
        $('pinMsg').textContent = 'Checking...';
        var tries = RULES.pins.map(function (a) { return pbkdf2(pin, a.salt).then(function (h) { return h === a.kdf ? a : null; }); });
        Promise.all(tries).then(function (res) {
            var ok = res.filter(Boolean)[0];
            var l = {};
            try { l = JSON.parse(localStorage.getItem('martpos.pinlock') || '{}'); } catch (e) { l = {}; }
            if (ok) {
                try { localStorage.removeItem('martpos.pinlock'); } catch (e) { /* ignore */ }
                pinResolve && pinResolve(ok);
            } else {
                l.n = (l.n || 0) + 1;
                if (l.n >= 5) { l = { until: Date.now() + 10 * 60000 }; }
                try { localStorage.setItem('martpos.pinlock', JSON.stringify(l)); } catch (e) { /* ignore */ }
                $('pinMsg').textContent = l.until ? 'Wrong PIN 5 times — locked for 10 minutes' : 'Wrong PIN';
                $('pinVal').select(); beep(false);
            }
        })['catch'](function () { $('pinMsg').textContent = 'PIN cannot be checked in this browser (https required)'; });
    });
    $('pinCancel').addEventListener('click', function () { pinReject && pinReject(); });

    $('methods').addEventListener('click', function (ev) {
        var b = ev.target.closest('button');
        if (b) openPay(b.dataset.m);
    });

    function showLast() {
        var el = $('lastBill');
        if (!lastSale) { el.textContent = ''; return; }
        var how = (lastSale.payments || []).map(function (p) { return (METHODS[p.method] || p.method) + ' ' + money(p.amount); }).join(' + ');
        el.textContent = 'Last bill ' + lastSale.bill_no + ' — Total ' + money(lastSale.total) +
            (how ? ' · ' + how : '') + (lastSale.change > 0 ? ' · change ' + money(lastSale.change) : '');
    }

    function reprint() {
        if (!lastSale) { toast('No bill made yet'); return; }
        var copy = JSON.parse(JSON.stringify(lastSale));
        copy.reprint = true;
        Receipt.print(copy, SHOP).then(focusScan);
    }

    /* ---------- modals ---------- */
    function openModal(id, focusEl) {
        closeModal();
        openModalEl = $(id);
        openModalEl.hidden = false;
        hideSuggest();
        if (focusEl) setTimeout(function () { focusEl.focus(); if (focusEl.select) focusEl.select(); }, 30);
    }
    function closeModal() {
        if (openModalEl) openModalEl.hidden = true;
        openModalEl = null;
    }
    document.querySelectorAll('[data-close]').forEach(function (b) {
        b.addEventListener('click', function () { closeModal(); focusScan(); });
    });
    document.querySelectorAll('.modal-backdrop').forEach(function (bd) {
        bd.addEventListener('mousedown', function (ev) { if (ev.target === bd) { closeModal(); focusScan(); } });
    });

    // Naya product
    var newQty = null;
    function openNew(code, q) {
        newQty = q;
        $('nBarcode').value = code;
        $('nName').value = ''; $('nPrice').value = ''; $('nCost').value = ''; $('nUnit').value = 'pcs';
        openModal('newModal', $('nName'));
    }
    $('newForm').addEventListener('submit', function (ev) {
        ev.preventDefault();
        var name = $('nName').value.trim(), price = num($('nPrice').value);
        if (!name || !(price > 0)) { toast('Enter the name and sale price', 'err'); return; }
        var p = {
            id: -Date.now(), barcode: $('nBarcode').value.trim(), name: name, unit: $('nUnit').value,
            price: r2(price), stock: 0, quick: null, deleted: false, local: true
        };
        S.put('products', p).then(function () {
            byId[p.id] = p; byBarcode[p.barcode] = p; list.push(p);
            return POS.queue('product', { barcode: p.barcode, name: p.name, price: p.price, purchase_price: num($('nCost').value) || 0, unit: p.unit });
        }).then(function () {
            closeModal();
            addProduct(p, newQty);
            focusScan();
            POS.sync();
        });
    });
    // Naam ke baad Enter = price wala box
    $('nName').addEventListener('keydown', function (ev) {
        if (ev.key === 'Enter') { ev.preventDefault(); $('nPrice').focus(); }
    });

    // Khula saaman (kg)
    var weighP = null;
    function openWeigh(p) {
        weighP = p;
        $('wTitle').textContent = p.name;
        $('wRate').textContent = 'Rate: ' + SHOP.currency + ' ' + money(p.price) + ' per ' + p.unit;
        $('wUnitLbl').textContent = 'Quantity (' + p.unit + ')';
        $('wQty').value = ''; $('wAmt').value = ''; $('wCalc').textContent = '';
        openModal('weighModal', $('wQty'));
    }
    $('wQty').addEventListener('input', function () {
        var q = num($('wQty').value);
        $('wAmt').value = '';
        $('wCalc').textContent = q > 0 ? fmtQty(q) + ' ' + weighP.unit + ' = ' + SHOP.currency + ' ' + money(r2(q * weighP.price)) : '';
    });
    $('wAmt').addEventListener('input', function () {
        var a = num($('wAmt').value);
        $('wQty').value = '';
        $('wCalc').textContent = a > 0 ? SHOP.currency + ' ' + money(a) + ' = ' + fmtQty(r3(a / weighP.price)) + ' ' + weighP.unit : '';
    });
    $('wQty').addEventListener('keydown', function (ev) {
        if (ev.key === 'Enter' && !$('wQty').value.trim()) { ev.preventDefault(); $('wAmt').focus(); }
    });
    $('weighForm').addEventListener('submit', function (ev) {
        ev.preventDefault();
        var q = num($('wQty').value), a = num($('wAmt').value);
        if (!(q > 0) && a > 0) q = r3(a / weighP.price);
        if (!(q > 0)) { toast('Enter the quantity or amount', 'err'); return; }
        closeModal();
        addItem(weighP, q);
        focusScan();
    });

    // Discount
    function openDisc() {
        if (!cart.length) { toast('Scan items first'); return; }
        $('dAmt').value = discount ? discount : ''; $('dPct').value = ''; $('dCalc').textContent = '';
        openModal('discModal', $('dAmt'));
    }
    $('dPct').addEventListener('input', function () {
        var p = num($('dPct').value), t = totals();
        if (p > 0 && p <= 100) { $('dAmt').value = r2(t.sub * p / 100); $('dCalc').textContent = p + '% = ' + money(r2(t.sub * p / 100)); }
    });
    $('discForm').addEventListener('submit', function (ev) {
        ev.preventDefault();
        var a = num($('dAmt').value), t = totals();
        if (isNaN(a) || a < 0) a = 0;
        if (a > t.sub) { toast('Discount cannot be more than the total', 'err'); return; }
        var apply = function () { discount = r2(a); closeModal(); render(); focusScan(); };
        var pct = t.sub > 0 ? a / t.sub * 100 : 0;
        if (!RULES.admin && RULES.discount_pct != null && pct > RULES.discount_pct + 0.001) {
            askPin('Discount ' + pct.toFixed(1) + '% (' + money(a) + ') — staff limit is ' + RULES.discount_pct + '%').then(function (admin) {
                approvals.push({ what: 'Discount ' + pct.toFixed(1) + '% (' + money(a) + ')', admin_id: admin.id });
                apply();
            }, function () { $('dAmt').focus(); });
            return;
        }
        apply();
    });
    $('dRemove').addEventListener('click', function () { discount = 0; closeModal(); render(); focusScan(); });

    // Qty aur rate (F2) — rate kam karna staff ke liye PIN se
    function openQty(focusRate) {
        if (sel < 0 || !cart[sel]) { toast('Select an item first'); return; }
        var it = cart[sel], base = it.base != null ? it.base : it.price;
        $('qTitle').textContent = it.name;
        $('qBase').textContent = 'Original rate: ' + SHOP.currency + ' ' + money(base) + (it.price !== base ? ' · now: ' + money(it.price) : '');
        $('qVal').value = fmtQty(it.qty);
        $('qRate').value = money(it.price).replace(/,/g, '');
        openModal('qtyModal', focusRate ? $('qRate') : $('qVal'));
    }
    $('qVal').addEventListener('keydown', function (ev) {
        if (ev.key === 'Enter') { ev.preventDefault(); $('qtyForm').requestSubmit(); }
    });
    $('qtyForm').addEventListener('submit', function (ev) {
        ev.preventDefault();
        var it = cart[sel];
        if (!it) { closeModal(); return; }
        var q = num($('qVal').value), rate = r2(num($('qRate').value));
        var base = it.base != null ? it.base : it.price;
        if (isNaN(rate) || rate <= 0) { toast('Enter a valid rate', 'err'); return; }
        var apply = function () {
            if (rate !== it.price) {
                // same product ki doosri line isi rate par ho to mila do
                it.price = rate;
            }
            closeModal();
            if (q >= 0) setQty(sel, q); else render();
            focusScan();
        };
        if (rate < base - 0.004 && !RULES.admin) {
            askPin('Lower rate: ' + it.name + ' ' + money(base) + ' → ' + money(rate)).then(function (admin) {
                approvals.push({ what: 'Rate changed: ' + it.name + ' ' + money(base) + ' → ' + money(rate), admin_id: admin.id });
                apply();
            }, function () { $('qRate').focus(); });
            return;
        }
        apply();
    });

    // Hold
    function hold() {
        if (!cart.length) { toast('Nothing to hold — the bill is empty'); return Promise.resolve(); }
        var t = totals();
        var h = { id: POS.uuid(), at: new Date().toISOString(), cart: cart, discount: discount, approvals: approvals, total: t.total,
            label: cart.slice(0, 3).map(function (i) { return i.name; }).join(', ') + (cart.length > 3 ? ' +' + (cart.length - 3) : '') };
        return S.put('held', h).then(function () {
            clearBill();
            toast('Bill on hold (F9 to resume)', 'ok');
            updateHeld();
            focusScan();
        });
    }

    function updateHeld() {
        return S.count('held').then(function (n) { $('heldInfo').textContent = n ? n + ' bill(s) on hold' : ''; return n; });
    }

    var heldItems = [], heldSel = 0;
    function openHeld() {
        S.getAll('held').then(function (rows) {
            heldItems = rows.sort(function (a, b) { return a.at < b.at ? -1 : 1; });
            heldSel = 0;
            if (!heldItems.length) { toast('No bills on hold'); return; }
            renderHeld();
            openModal('heldModal');
            setTimeout(function () { var b = $('heldList').querySelector('button'); if (b) b.focus(); }, 40);
        });
    }
    function renderHeld() {
        var box = $('heldList');
        box.innerHTML = '';
        heldItems.forEach(function (h, i) {
            var b = document.createElement('button');
            b.type = 'button';
            if (i === heldSel) b.className = 'sel';
            var d = new Date(h.at);
            b.innerHTML = '<span><b></b><br><span class="muted small"></span></span><span class="num"><b></b></span>';
            b.querySelector('b').textContent = h.label;
            b.querySelector('.muted').textContent = pad(d.getHours() % 12 || 12, 2) + ':' + pad(d.getMinutes(), 2) + (d.getHours() >= 12 ? ' PM' : ' AM') + ' · ' + h.cart.length + ' lines';
            b.querySelector('.num b').textContent = money(h.total);
            b.addEventListener('click', function () { resume(h); });
            b.addEventListener('keydown', function (ev) {
                if (ev.key === 'ArrowDown' || ev.key === 'ArrowUp') {
                    ev.preventDefault();
                    heldSel = (heldSel + (ev.key === 'ArrowDown' ? 1 : -1) + heldItems.length) % heldItems.length;
                    renderHeld();
                    box.children[heldSel].focus();
                }
            });
            box.appendChild(b);
        });
    }
    function resume(h) {
        var p = cart.length ? hold() : Promise.resolve();
        p.then(function () { return S.del('held', h.id); }).then(function () {
            cart = h.cart; discount = h.discount || 0; approvals = h.approvals || []; sel = cart.length - 1; cash.value = '';
            closeModal(); render(); updateHeld(); focusScan();
        });
    }

    /* ---------- keyboard ---------- */
    document.addEventListener('keydown', function (ev) {
        if (openModalEl) {
            if (ev.key === 'Escape') {
                ev.preventDefault();
                if (openModalEl.id === 'pinModal') { pinReject && pinReject(); return; }
                closeModal(); focusScan();
            }
            return;
        }
        var k = ev.key;
        if (k === 'F2') { ev.preventDefault(); openQty(); }
        else if (k === 'F4') { ev.preventDefault(); openDisc(); }
        else if (k === 'F8') { ev.preventDefault(); hold(); }
        else if (k === 'F9') { ev.preventDefault(); openHeld(); }
        else if (k === 'F10') { ev.preventDefault(); openPay('split'); }
        else if ((ev.ctrlKey || ev.metaKey) && (k === 'p' || k === 'P')) { ev.preventDefault(); reprint(); }
        else if ((ev.ctrlKey || ev.metaKey) && k === 'Delete') { ev.preventDefault(); cancelBill(); }
        else if (k === 'Escape') { ev.preventDefault(); hideSuggest(); setMult(null); focusScan(); }
        else {
            // Focus kahin aur ho aur scanner chale, to type scan box mein jaye
            var a = document.activeElement;
            var typing = a && (a.tagName === 'INPUT' || a.tagName === 'TEXTAREA' || a.tagName === 'SELECT');
            if (!typing && k.length === 1 && !ev.ctrlKey && !ev.metaKey && !ev.altKey) scan.focus();
        }
    });

    function cancelBill() {
        if (!cart.length) return;
        if (confirm('Cancel this bill? All items will be removed.')) clearBill();
        focusScan();
    }

    $('discBtn').addEventListener('click', openDisc);
    $('holdBtn').addEventListener('click', hold);
    $('heldBtn').addEventListener('click', openHeld);
    $('reprintBtn').addEventListener('click', reprint);
    $('clearBtn').addEventListener('click', cancelBill);
    $('syncBtn').addEventListener('click', function () {
        toast('Syncing...');
        POS.sync().then(function () {
            var st = POS.state;
            toast(!st.auth ? 'Please log in first' : !st.online ? 'No internet — bills are safe and will sync later' :
                st.pending ? st.pending + ' bill(s) still pending' : 'Everything is synced', st.online && st.auth ? 'ok' : 'err');
        });
    });

    /* ---------- status ---------- */
    POS.onStatus(function (st) {
        var net = $('net');
        net.textContent = st.online ? (st.syncing ? 'Sync...' : 'Online') : 'Offline';
        net.classList.toggle('off', !st.online);
        $('pending').hidden = !st.pending;
        $('pending').textContent = st.pending + ' bill(s) to sync';
        $('authBar').hidden = st.auth;
        var ls = st.lastSync ? st.lastSync.getTime() : null;
        if (ls && ls !== lastSyncSeen) {
            lastSyncSeen = ls;
            loadProducts();
        }
    });

    function tick() {
        var d = new Date(), h = d.getHours();
        $('clock').textContent = pad(d.getDate(), 2) + '-' + pad(d.getMonth() + 1, 2) + '-' + d.getFullYear() + '  ' +
            pad(h % 12 || 12, 2) + ':' + pad(d.getMinutes(), 2) + (h >= 12 ? ' PM' : ' AM');
    }
    tick(); setInterval(tick, 15000);

    $('logoutForm').addEventListener('submit', function (ev) {
        if (!navigator.onLine) { ev.preventDefault(); toast('No internet — if you log out now you cannot log back in', 'err', 5000); return; }
        if (POS.state.pending && !confirm(POS.state.pending + ' bill(s) have not reached the server yet. They stay safe on this PC after logout. Log out?')) ev.preventDefault();
    });

    document.querySelectorAll('[data-autohide]').forEach(function (el) { setTimeout(function () { el.remove(); }, 6000); });

    /* ---------- start ---------- */
    try {
        var draft = JSON.parse(localStorage.getItem('martpos.cart') || 'null');
        if (draft && draft.cart && draft.cart.length) {
            cart = draft.cart; discount = draft.discount || 0; sel = Math.min(draft.sel, cart.length - 1); approvals = draft.approvals || [];
            toast('Your unfinished bill was restored', 'ok');
        }
        lastSale = JSON.parse(localStorage.getItem('martpos.last') || 'null');
    } catch (e) { /* ignore */ }

    render();
    showLast();
    updateHeld();
    loadProducts().then(function (n) {
        if (!n && !navigator.onLine) toast('Products are not downloaded yet — internet is needed the first time', 'err', 6000);
    });
    focusScan();
    window.addEventListener('focus', focusScan);

    // Billing page ki copy offline ke liye tayyar rakho
    if ('serviceWorker' in navigator) {
        navigator.serviceWorker.ready.then(function (reg) {
            if (reg.active && navigator.onLine) reg.active.postMessage('cache-billing');
        })['catch'](function () { /* ignore */ });
    }
})();
