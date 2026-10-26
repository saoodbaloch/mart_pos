/* Product picker: scan ya naam likh kar product chunna (labels / stock pages) */
(function (global) {
    'use strict';

    function Picker(input, onPick) {
        var box = document.createElement('div');
        box.className = 'suggest';
        box.hidden = true;
        input.parentNode.style.position = 'relative';
        input.parentNode.appendChild(box);
        var items = [], sel = 0, timer = null, seq = 0;

        function render() {
            box.innerHTML = '';
            if (!items.length) { box.hidden = true; return; }
            items.forEach(function (p, i) {
                var b = document.createElement('button');
                b.type = 'button';
                if (i === sel) b.className = 'sel';
                var l = document.createElement('span');
                l.innerHTML = '<b></b><br><span class="bc"></span>';
                l.querySelector('b').textContent = p.name;
                l.querySelector('.bc').textContent = p.barcode || 'no barcode';
                var r = document.createElement('span');
                r.textContent = p.price + ' · stock ' + p.stock;
                b.appendChild(l); b.appendChild(r);
                b.addEventListener('mousedown', function (ev) { ev.preventDefault(); pick(p); });
                box.appendChild(b);
            });
            box.hidden = false;
        }

        function search(q, exactPick) {
            var my = ++seq;
            return fetch('api/search.php?q=' + encodeURIComponent(q), { credentials: 'same-origin' })
                .then(function (r) { return r.json(); })
                .then(function (d) {
                    if (my !== seq) return;
                    items = d.items || []; sel = 0;
                    if (exactPick) {
                        var ex = items.filter(function (p) { return p.barcode === q; })[0];
                        if (ex) { pick(ex); return; }
                        if (items.length === 1) { pick(items[0]); return; }
                        if (!items.length) { onPick(null, q); return; }
                    }
                    render();
                });
        }

        function pick(p) {
            items = []; render();
            input.value = '';
            onPick(p);
        }

        input.addEventListener('input', function () {
            clearTimeout(timer);
            var q = input.value.trim();
            if (q.length < 2 || /^\d{6,}$/.test(q)) { items = []; render(); return; }
            timer = setTimeout(function () { search(q, false); }, 180);
        });
        input.addEventListener('keydown', function (ev) {
            if (ev.key === 'ArrowDown' && items.length) { ev.preventDefault(); sel = (sel + 1) % items.length; render(); }
            else if (ev.key === 'ArrowUp' && items.length) { ev.preventDefault(); sel = (sel - 1 + items.length) % items.length; render(); }
            else if (ev.key === 'Escape') { items = []; render(); }
            else if (ev.key === 'Enter') {
                ev.preventDefault();
                var q = input.value.trim();
                if (!q) return;
                if (items.length && !box.hidden) { pick(items[sel]); return; }
                clearTimeout(timer);
                search(q, true);
            }
        });
        input.addEventListener('blur', function () { setTimeout(function () { box.hidden = true; }, 150); });
    }

    global.Picker = Picker;
})(window);
