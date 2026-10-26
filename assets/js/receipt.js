/* MartPOS — 80mm / 58mm receipt (billing aur reprint dono yahi use karte hain) */
(function (global) {
    'use strict';

    function esc(s) {
        return String(s == null ? '' : s).replace(/[&<>"']/g, function (c) {
            return { '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[c];
        });
    }

    function money(n) {
        n = Math.round((+n || 0) * 100) / 100;
        var whole = Math.abs(n - Math.round(n)) < 0.005;
        return n.toLocaleString('en-US', { minimumFractionDigits: whole ? 0 : 2, maximumFractionDigits: whole ? 0 : 2 });
    }

    function qty(n) {
        n = Math.round((+n || 0) * 1000) / 1000;
        return String(n);
    }

    function fmtDate(s) {
        // "2026-10-24 21:30:05" -> "24-10-2026  09:30 PM"
        var m = /^(\d{4})-(\d{2})-(\d{2}) (\d{2}):(\d{2})/.exec(s || '');
        if (!m) return esc(s);
        var h = +m[4], ap = h >= 12 ? 'PM' : 'AM';
        h = h % 12 || 12;
        return m[3] + '-' + m[2] + '-' + m[1] + '&nbsp;&nbsp;' + (h < 10 ? '0' : '') + h + ':' + m[5] + ' ' + ap;
    }

    var METHOD_LABELS = { cash: 'Cash', jazzcash: 'JazzCash', easypaisa: 'Easypaisa', bank: 'Bank transfer', card: 'Card', udhaar: 'Credit' };

    /** Paper ke hisaab se page CSS (80mm ya 58mm thermal roll) */
    function pageCss(shop) {
        var w = (shop && +shop.paper === 58) ? 58 : 80;
        return '@page{size:' + w + 'mm auto;margin:0}' +
            'body{margin:0;width:' + w + 'mm;padding:' + (w === 58 ? '2mm 2.5mm 5mm' : '3mm 4mm 6mm') + ';font-family:Arial,Helvetica,sans-serif;font-size:' + (w === 58 ? '11px' : '12px') + ';color:#000;line-height:1.3}';
    }

    function html(sale, shop) {
        var cur = shop.currency || 'Rs';
        var rows = sale.items.map(function (it) {
            return '<tr><td colspan="2" class="nm">' + esc(it.name) + '</td></tr>' +
                '<tr><td class="q">' + qty(it.qty) + (it.unit && it.unit !== 'pcs' ? ' ' + esc(it.unit) : '') + ' &times; ' + money(it.price) + '</td>' +
                '<td class="r">' + money(it.qty * it.price) + '</td></tr>';
        }).join('');
        var count = sale.items.reduce(function (a, it) { return a + (/^(kg|ltr|m)$/.test(it.unit) ? 1 : (+it.qty || 0)); }, 0);

        var h = '<!doctype html><html><head><meta charset="utf-8"><title>Bill ' + esc(sale.bill_no) + '</title><style>' +
            '*{box-sizing:border-box}' + pageCss(shop) +
            '.c{text-align:center}.r{text-align:right;white-space:nowrap}' +
            'h1{font-size:18px;margin:0 0 1mm;text-align:center;font-weight:800}' +
            '.sm{font-size:11px}' +
            'hr{border:0;border-top:1px dashed #000;margin:2mm 0}' +
            'table{width:100%;border-collapse:collapse}' +
            'td{padding:0;vertical-align:top}' +
            '.nm{font-weight:700;padding-top:1.2mm}' +
            '.q{color:#000}' +
            '.tot td{font-size:16px;font-weight:800;padding-top:1mm}' +
            '.meta td{padding:.3mm 0}' +
            '.stamp{border:2px solid #000;text-align:center;font-weight:800;font-size:15px;padding:1mm;margin:2mm 0}' +
            '</style></head><body>';
        h += '<h1>' + esc(shop.name) + '</h1>';
        if (shop.address) h += '<div class="c sm">' + esc(shop.address) + '</div>';
        if (shop.phone) h += '<div class="c sm">Ph: ' + esc(shop.phone) + '</div>';
        if (shop.ntn) h += '<div class="c sm">NTN: ' + esc(shop.ntn) + '</div>';
        h += '<hr><table class="meta sm"><tr><td>Bill #</td><td class="r"><b>' + esc(sale.bill_no) + '</b></td></tr>' +
            '<tr><td>Date</td><td class="r">' + fmtDate(sale.created_at) + '</td></tr></table>';
        if (sale.reprint) h += '<div class="c sm"><b>** DUPLICATE COPY **</b></div>';
        if (sale.status === 'cancelled') h += '<div class="stamp">BILL CANCELLED</div>';
        h += '<hr><table>' + rows + '</table><hr>';
        h += '<table>';
        if (+sale.discount > 0) {
            h += '<tr><td>Subtotal</td><td class="r">' + money(sale.subtotal) + '</td></tr>';
            h += '<tr><td>Discount</td><td class="r">-' + money(sale.discount) + '</td></tr>';
        }
        h += '<tr class="tot"><td>TOTAL</td><td class="r">' + esc(cur) + ' ' + money(sale.total) + '</td></tr>';
        var pays = sale.payments && sale.payments.length ? sale.payments : [{ method: 'cash', amount: sale.paid }];
        var udhaar = 0;
        pays.forEach(function (p) {
            if (p.method === 'udhaar') udhaar += +p.amount;
            h += '<tr><td>' + esc(METHOD_LABELS[p.method] || p.method) + (p.ref ? '<div class="sm">Ref: ' + esc(p.ref) + '</div>' : '') +
                '</td><td class="r">' + money(p.amount) + '</td></tr>';
        });
        if (+sale.change > 0) h += '<tr><td>Change</td><td class="r">' + money(sale.change) + '</td></tr>';
        if (+sale.returned_amount > 0) h += '<tr><td>Returned</td><td class="r">-' + money(sale.returned_amount) + '</td></tr>';
        h += '</table>';
        if (sale.customer && udhaar > 0) {
            h += '<hr><div class="sm"><b>Credit: ' + esc(sale.customer.name) + '</b>' + (sale.customer.phone ? ' (' + esc(sale.customer.phone) + ')' : '') + '</div><table class="sm">';
            if (sale.customer_prev != null) {
                h += '<tr><td>Previous balance</td><td class="r">' + money(sale.customer_prev) + '</td></tr>';
                h += '<tr><td>This bill on credit</td><td class="r">' + money(udhaar) + '</td></tr>';
                h += '<tr><td><b>Total balance</b></td><td class="r"><b>' + money(+sale.customer_prev + udhaar) + '</b></td></tr>';
            } else if (sale.customer.balance != null) {
                h += '<tr><td><b>Total balance (to date)</b></td><td class="r"><b>' + money(sale.customer.balance) + '</b></td></tr>';
            }
            h += '</table><div class="sm" style="margin-top:3mm">Signature: ____________________</div>';
        }
        h += '<hr>';
        h += '<div class="c sm">Items: ' + qty(count) + (sale.cashier ? ' &nbsp;·&nbsp; Cashier: ' + esc(sale.cashier) : '') + '</div>';
        if (shop.accounts_on_receipt && shop.accounts) {
            Object.keys(shop.accounts).forEach(function (k) {
                h += '<div class="c sm">' + esc(shop.accounts[k].label) + ': ' + esc(shop.accounts[k].value) + '</div>';
            });
        }
        if (shop.footer) h += '<div class="c" style="margin-top:2mm;font-weight:700">' + esc(shop.footer) + '</div>';
        h += '</body></html>';
        return h;
    }

    /** Chhupi iframe mein receipt daal kar print */
    function print(sale, shop) {
        return new Promise(function (resolve) {
            var old = document.getElementById('receiptFrame');
            if (old) old.remove();
            var f = document.createElement('iframe');
            f.id = 'receiptFrame';
            f.setAttribute('aria-hidden', 'true');
            f.style.cssText = 'position:fixed;right:0;bottom:0;width:0;height:0;border:0;visibility:hidden';
            document.body.appendChild(f);
            var doc = f.contentWindow.document;
            doc.open();
            doc.write(html(sale, shop));
            doc.close();
            setTimeout(function () {
                try {
                    f.contentWindow.focus();
                    f.contentWindow.print();
                } catch (e) { /* ignore */ }
                resolve();
            }, 120);
        });
    }

    global.Receipt = { html: html, print: print, money: money, qty: qty, pageCss: pageCss };
})(window);
