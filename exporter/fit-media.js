/* Explicit pixel widths for browsers with unreliable percentage iframe/image sizing. */
(function () {
    if (!document.getElementsByTagName) { return; }
    var items = [], timer = null, i, nodes, node;
    function container(node) {
        var p = node.parentNode, tag;
        while (p && p !== document.body) {
            tag = p.nodeName.toLowerCase();
            if (tag === 'div' || tag === 'p' || tag === 'td' || tag === 'th' || tag === 'li') { return p; }
            p = p.parentNode;
        }
        return p;
    }
    function width(p) {
        if (!p || !(p.clientWidth > 0)) { return 0; }
        var style = p.currentStyle || (window.getComputedStyle ? window.getComputedStyle(p, null) : null);
        var padding = style ? (parseFloat(style.paddingLeft) || 0) + (parseFloat(style.paddingRight) || 0) : 0;
        var available = p.clientWidth - padding, cell = p.parentNode;
        if (p.className === 'item' && cell && cell.nodeName.toLowerCase() === 'td' && cell.className === 'posts') {
            // IE can expand the item around an image before its first sizing pass.
            // The fixed table cell, not that expanded item, owns the column width.
            var margins = style ? (parseFloat(style.marginLeft) || 0) + (parseFloat(style.marginRight) || 0) : 0;
            available = Math.min(available, width(cell) - margins - padding);
        }
        return available;
    }
    function size(item, available, force) {
        var w = Math.floor(available), h;
        if (item.original) { w = Math.min(w, item.original); }
        if (w <= 0) { return; }
        if (!force && w === item.width) { return; }
        if (item.height && item.original) {
            h = Math.max(1, Math.round(item.height * w / item.original));
            // Set both axes: image decoding can reset dimensions in old engines.
            item.node.style.height = h + 'px';
            item.node.setAttribute('height', h);
        }
        item.node.style.width = w + 'px';
        item.node.setAttribute('width', w);
        item.width = w;
    }
    function fit() {
        var widths = [], j;
        for (j = 0; j < items.length; j++) { widths[j] = width(items[j].parent); }
        for (j = 0; j < items.length; j++) { size(items[j], widths[j]); }
    }
    function watch(item) {
        var old = item.node.onload;
        item.node.onload = function () {
            if (typeof old === 'function') { old.call(this); }
            size(item, width(item.parent), true);
        };
    }
    function schedule() {
        if (timer !== null) { window.clearTimeout(timer); }
        timer = window.setTimeout(function () { timer = null; fit(); }, 150);
    }
    nodes = document.getElementsByTagName('iframe');
    for (i = 0; i < nodes.length; i++) {
        node = nodes[i];
        if (node.parentNode.className === 'video-frame') { items[items.length] = {node:node,parent:node.parentNode}; }
    }
    nodes = document.getElementsByTagName('img');
    for (i = 0; i < nodes.length; i++) {
        node = nodes[i];
        var p = container(node), w = parseInt(node.getAttribute('data-fit-width'), 10), h = parseInt(node.getAttribute('data-fit-height'), 10);
        if (p && w > 0 && h > 0) {
            var item = {node:node,parent:p,original:w,height:h};
            items[items.length] = item;
            watch(item);
        }
    }
    var oldResize = window.onresize, oldLoad = window.onload, oldOrientation = window.onorientationchange;
    window.onresize = function () { if (typeof oldResize === 'function') { oldResize(); } schedule(); };
    window.onload = function () { if (typeof oldLoad === 'function') { oldLoad(); } schedule(); };
    window.onorientationchange = function () { if (typeof oldOrientation === 'function') { oldOrientation(); } schedule(); };
    fit();
}());
