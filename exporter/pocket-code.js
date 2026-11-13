/* ES3: no framework, canvas, polling, or extra network request. */
(function () {
    if (!document.getElementsByTagName || !document.createElement) { return; }
    var entries = [], blocks = document.getElementsByTagName('div'), timer = null, settleTimer = null, busy = false, fonts = {}, samples = 32;

    function source(node) {
        var text = '', child;
        for (child = node.firstChild; child; child = child.nextSibling) {
            if (child.nodeType === 3) { text += child.nodeValue.split('\u00a0').join(' '); }
            else if (child.nodeType === 1) {
                if (child.nodeName.toLowerCase() === 'br') {
                    if (child.className !== 'code-wrap') { text += '\n'; }
                } else { text += source(child); }
            }
        }
        return text;
    }

    function escape(text) {
        if (text.length === 1) {
            return text === '&' ? '&amp;' : text === '<' ? '&lt;' : text === '>' ? '&gt;' : text === ' ' ? '&nbsp;' : text;
        }
        return text.split('&').join('&amp;').split('<').join('&lt;').split('>').join('&gt;').split(' ').join('&nbsp;');
    }

    function fontKey(code, index) {
        var style = code.currentStyle || (window.getComputedStyle ? window.getComputedStyle(code, null) : null);
        // Some mobile DOMs expose sizes but no computed font. Such blocks must
        // not share measurements: their actual fonts can still differ.
        return style && style.fontFamily && style.fontSize ? [style.fontFamily, style.fontSize, style.fontWeight, style.fontStyle, style.letterSpacing, style.wordSpacing].join('|') : 'block-' + index;
    }

    function contentWidth(code) {
        var w = code.clientWidth || code.offsetWidth;
        if (w > 0) { return w; }
        var box = code.parentNode, style = box.currentStyle || (window.getComputedStyle ? window.getComputedStyle(box, null) : null);
        w = box.clientWidth || box.offsetWidth;
        if (!(w > 0)) { return 0; }
        // Older DOMs sometimes expose dimensions only on DIV, not CODE.
        return w - (style ? (parseFloat(style.paddingLeft) || 0) + (parseFloat(style.paddingRight) || 0) : 8);
    }

    function pixels(span) {
        if (span.getBoundingClientRect) {
            var r = span.getBoundingClientRect();
            if (r.right > r.left) { return (r.right - r.left) / samples; }
        }
        return span.offsetWidth > 0 ? (span.offsetWidth + 0.5) / samples : 0;
    }

    function repeated(text) {
        var html = '', i, encoded = escape(text);
        for (i = 0; i < samples; i++) { html += encoded; }
        return '<span>' + html + '</span>';
    }

    function metrics(code, glyphs, previous) {
        var probe = document.createElement('span'), spans, ascii = 0, wide = 0, font, missing = [], html = '', key, i, w;
        probe.style.position = 'absolute';
        probe.style.visibility = 'hidden';
        // Do not inherit break-word/break-all from the page. Even the measurement
        // spans must remain on one line on browsers which ignore white-space:pre.
        probe.style.whiteSpace = 'nowrap';
        probe.style.wordWrap = 'normal';
        probe.style.wordBreak = 'normal';
        probe.style.width = 'auto';
        try {
            code.appendChild(probe);
            probe.innerHTML = repeated('W') + repeated('\u6c49');
            spans = probe.getElementsByTagName('span');
            ascii = pixels(spans[0]); wide = pixels(spans[1]);
            if (!(ascii > 0 && wide > 0)) { return null; }
            font = previous && previous.ascii === ascii && previous.wide === wide ? previous : {ascii:ascii,wide:wide,widths:{}};
            for (key in glyphs) {
                if (typeof font.widths[key] !== 'number') { missing[missing.length] = key; html += repeated(glyphs[key]); }
            }
            if (missing.length) {
                // Insert all distinct glyph probes together; never force layout once per source character.
                probe.innerHTML = html;
                spans = probe.getElementsByTagName('span');
                for (i = 0; i < missing.length; i++) {
                    w = pixels(spans[i]);
                    font.widths[missing[i]] = w > 0 ? w : Math.max(ascii, wide);
                }
            }
            return font;
        } catch (ignore) {}
        finally { if (probe.parentNode) { probe.parentNode.removeChild(probe); } }
        return null;
    }

    function point(text, at) {
        var n = text.charCodeAt(at), size = 1, low = text.charCodeAt(at + 1);
        if (n >= 55296 && n <= 56319 && low >= 56320 && low <= 57343) { size = 2; }
        return text.substr(at, size);
    }

    function cluster(text, at) {
        var ch = point(text, at), next, n;
        at += ch.length;
        while (at < text.length) {
            n = text.charCodeAt(at);
            if ((n >= 768 && n <= 879) || (n >= 65024 && n <= 65039)) { ch += text.charAt(at++); }
            else if (n === 8205 && at + 1 < text.length) {
                ch += text.charAt(at++); next = point(text, at); ch += next; at += next.length;
            } else { break; }
        }
        return ch;
    }

    function glyphs(text) {
        var result = {}, i = 0, ch;
        while (i < text.length) {
            ch = cluster(text, i); i += ch.length;
            if (ch !== '\n') { result['$' + ch] = ch; }
        }
        return result;
    }

    function wrap(text, width, font) {
        var out = [], used = 0, i = 0, ch, unit;
        while (i < text.length) {
            ch = cluster(text, i); i += ch.length;
            if (ch === '\n') { out[out.length] = '<br>'; used = 0; continue; }
            unit = font.widths['$' + ch];
            if (used > 0 && used + unit > width) { out[out.length] = '<br class="code-wrap">'; used = 0; }
            used += unit;
            out[out.length] = escape(ch);
        }
        return out.join('');
    }

    function reflow(settled) {
        if (busy || !entries.length) { return; }
        busy = true;
        try {
            var i, font, widths = [], entry, width, key, g, groups = {}, group, changed = false;
            for (i = 0; i < entries.length; i++) {
                entry = entries[i]; entry.font = '$' + fontKey(entry.code, i);
                if (!(contentWidth(entry.code) > 0)) { continue; }
                if (!groups[entry.font]) { groups[entry.font] = {code:entry.code,glyphs:{}}; }
                group = groups[entry.font];
                for (g in entry.glyphs) { group.glyphs[g] = entry.glyphs[g]; }
            }
            for (key in groups) { fonts[key] = metrics(groups[key].code, groups[key].glyphs, fonts[key]); }
            // Read every content width before any visible DOM rewrite to avoid layout thrashing.
            for (i = 0; i < entries.length; i++) { widths[i] = contentWidth(entries[i].code); }
            for (i = 0; i < entries.length; i++) {
                entry = entries[i]; width = widths[i] - 1; font = fonts[entry.font];
                if (!font || width <= 0 || (entry.width === width && entry.lastFont === entry.font && entry.measured === font)) { continue; }
                entry.code.innerHTML = wrap(entry.text, width, font);
                // BRs are the only wrap points after measurement. Otherwise the
                // browser can move a whole word before our character-level BR.
                entry.code.className = 'code-measured';
                entry.width = width; entry.lastFont = entry.font; entry.measured = font;
                changed = true;
            }
            if (changed && !settled) {
                // Rewrapping may add/remove the page scrollbar. Allow one bounded settling pass.
                if (settleTimer !== null) { window.clearTimeout(settleTimer); }
                settleTimer = window.setTimeout(function () {
                    settleTimer = null;
                    for (var j = 0; j < entries.length; j++) {
                        if (contentWidth(entries[j].code) > 0 && contentWidth(entries[j].code) - 1 !== entries[j].width) { reflow(true); break; }
                    }
                }, 0);
            }
        } catch (ignore) {
            // Keep the readable server-generated fallback if this DOM implementation is incomplete.
        } finally { busy = false; }
    }

    function schedule() {
        if (timer !== null) { window.clearTimeout(timer); }
        timer = window.setTimeout(function () { timer = null; reflow(); }, 150);
    }

    var i, code, text;
    for (i = 0; i < blocks.length; i++) {
        if (blocks[i].className === 'code' && blocks[i].getElementsByTagName) {
            code = blocks[i].getElementsByTagName('code')[0];
            if (code && code.firstChild) {
                text = source(code); entries[entries.length] = {code:code,text:text,glyphs:glyphs(text)};
            }
        }
    }
    if (!entries.length) { return; }
    var oldResize = window.onresize, oldLoad = window.onload, oldOrientation = window.onorientationchange;
    window.onresize = function () { if (typeof oldResize === 'function') { oldResize(); } schedule(); };
    window.onload = function () { if (typeof oldLoad === 'function') { oldLoad(); } schedule(); };
    window.onorientationchange = function () { if (typeof oldOrientation === 'function') { oldOrientation(); } schedule(); };
    reflow();
}());
