/* MartPOS — barcode SVG banane wala (EAN-13 aur Code 128). Internet ki zaroorat nahi. */
(function (global) {
    'use strict';

    var L = ['0001101', '0011001', '0010011', '0111101', '0100011', '0110001', '0101111', '0111011', '0110111', '0001011'];
    var G = ['0100111', '0110011', '0011011', '0100001', '0011101', '0111001', '0000101', '0010001', '0001001', '0010111'];
    var R = ['1110010', '1100110', '1101100', '1000010', '1011100', '1001110', '1010000', '1000100', '1001000', '1110100'];
    var PARITY = ['LLLLLL', 'LLGLGG', 'LLGGLG', 'LLGGGL', 'LGLLGG', 'LGGLLG', 'LGGGLL', 'LGLGLG', 'LGLGGL', 'LGGLGL'];

    var C128 = ['212222', '222122', '222221', '121223', '121322', '131222', '122213', '122312', '132212', '221213',
        '221312', '231212', '112232', '122132', '122231', '113222', '123122', '123221', '223211', '221132',
        '221231', '213212', '223112', '312131', '311222', '321122', '321221', '312212', '322112', '322211',
        '212123', '212321', '232121', '111323', '131123', '131321', '112313', '132113', '132311', '211313',
        '231113', '231311', '112133', '112331', '132131', '113123', '113321', '133121', '313121', '211331',
        '231131', '213113', '213311', '213131', '311123', '311321', '331121', '312113', '312311', '332111',
        '314111', '221411', '431111', '111224', '111422', '121124', '121421', '141122', '141221', '112214',
        '112412', '122114', '122411', '142112', '142211', '241211', '221114', '413111', '241112', '134111',
        '111242', '121142', '121241', '114212', '124112', '124211', '411212', '421112', '421211', '212141',
        '214121', '412121', '111143', '111341', '131141', '114113', '114311', '411113', '411311', '113141',
        '114131', '311141', '411131', '211412', '211214', '211232', '2331112'];

    function eanCheck(twelve) {
        var s = 0;
        for (var i = 0; i < 12; i++) s += +twelve[i] * (i % 2 ? 3 : 1);
        return (10 - (s % 10)) % 10;
    }

    function isEan13(code) {
        return /^\d{13}$/.test(code) && eanCheck(code.slice(0, 12)) === +code[12];
    }

    function esc(s) {
        return String(s).replace(/[&<>"]/g, function (c) { return { '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;' }[c]; });
    }

    /** bits string (1 = kali line) ko rects mein */
    function bitsToRects(bits, x0, mod, y, h, tallMask, extra) {
        var out = '', i = 0;
        while (i < bits.length) {
            if (bits[i] === '1') {
                var j = i;
                while (j < bits.length && bits[j] === '1' && (!tallMask || tallMask[j] === tallMask[i])) j++;
                var hh = h + (tallMask && tallMask[i] === '1' ? extra : 0);
                out += '<rect x="' + (x0 + i * mod).toFixed(3) + '" y="' + y + '" width="' + ((j - i) * mod).toFixed(3) + '" height="' + hh + '"/>';
                i = j;
            } else i++;
        }
        return out;
    }

    function ean13(code, opt) {
        var mod = opt.module || 1, h = opt.height || 50, fs = Math.max(8, mod * 9);
        var first = +code[0], par = PARITY[first];
        var bits = '101', tall = '111';
        for (var i = 1; i <= 6; i++) {
            bits += (par[i - 1] === 'L' ? L : G)[+code[i]];
            tall += '0000000';
        }
        bits += '01010'; tall += '11111';
        for (i = 7; i <= 12; i++) { bits += R[+code[i]]; tall += '0000000'; }
        bits += '101'; tall += '111';
        var quietL = 11, quietR = 7, total = quietL + bits.length + quietR;
        var w = total * mod, ht = h + fs + 2;
        var svg = '<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 ' + w + ' ' + ht + '" width="' + w + '" height="' + ht + '" shape-rendering="crispEdges"><g fill="#000">';
        svg += bitsToRects(bits, quietL * mod, mod, 0, h, tall, fs * 0.55);
        svg += '</g><g font-family="Consolas,monospace" font-size="' + fs + '" text-anchor="middle" fill="#000">';
        var ty = h + fs;
        svg += '<text x="' + (quietL * mod / 2) + '" y="' + ty + '">' + code[0] + '</text>';
        svg += '<text x="' + ((quietL + 3 + 21) * mod) + '" y="' + ty + '" letter-spacing="' + (mod * 1.6) + '">' + code.slice(1, 7) + '</text>';
        svg += '<text x="' + ((quietL + 50 + 21) * mod) + '" y="' + ty + '" letter-spacing="' + (mod * 1.6) + '">' + code.slice(7) + '</text>';
        return svg + '</g></svg>';
    }

    function code128(code, opt) {
        var mod = opt.module || 1, h = opt.height || 50, fs = Math.max(8, mod * 9);
        var vals = [104], i;
        for (i = 0; i < code.length; i++) {
            var c = code.charCodeAt(i);
            if (c < 32 || c > 126) c = 63; // '?'
            vals.push(c - 32);
        }
        var sum = vals[0];
        for (i = 1; i < vals.length; i++) sum += vals[i] * i;
        vals.push(sum % 103);
        vals.push(106);
        var bits = '';
        vals.forEach(function (v) {
            var p = C128[v], black = true;
            for (var k = 0; k < p.length; k++) {
                bits += (black ? '1' : '0').repeat(+p[k]);
                black = !black;
            }
        });
        var quiet = 10, total = quiet * 2 + bits.length, w = total * mod, ht = h + fs + 2;
        var svg = '<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 ' + w + ' ' + ht + '" width="' + w + '" height="' + ht + '" shape-rendering="crispEdges"><g fill="#000">';
        svg += bitsToRects(bits, quiet * mod, mod, 0, h, null, 0);
        svg += '</g><text x="' + (w / 2) + '" y="' + (h + fs) + '" font-family="Consolas,monospace" font-size="' + fs + '" text-anchor="middle" fill="#000">' + esc(code) + '</text></svg>';
        return svg;
    }

    global.Barcode = {
        isEan13: isEan13,
        eanCheck: eanCheck,
        svg: function (code, opt) {
            code = String(code || '').trim();
            opt = opt || {};
            if (/^\d{12}$/.test(code)) code = '0' + code; // UPC-A
            return isEan13(code) ? ean13(code, opt) : code128(code, opt);
        }
    };
})(window);
