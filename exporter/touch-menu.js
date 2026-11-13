/* ES3: immediate toggles, no animation, page relayout, or scroll polling. */
(function () {
    if (!document.getElementById) { return; }
    var toggle = document.getElementById('touch-menu-toggle'), menu = document.getElementById('touch-index');
    var layer = document.getElementById('touch-menu-layer'), shade = document.getElementById('touch-menu-shade');
    var close = document.getElementById('touch-menu-close'), root = document.documentElement;
    if (!toggle || !menu || !layer || !shade || !close) { return; }
    var openLabel = toggle.getAttribute('data-open-label'), closeLabel = toggle.getAttribute('data-close-label');
    var opened = false, previousY = null;
    var oldViewport = /Android [12]\.|OS [1-4]_|Windows Phone (?:OS )?7\./.test(navigator.userAgent || '');
    function fit() {
        if (!opened || !oldViewport) { return; }
        // Only old engines need explicit geometry; read it before showing the panel.
        var top = window.pageYOffset || root.scrollTop || document.body.scrollTop || 0;
        var height = window.innerHeight || root.clientHeight;
        layer.style.top = top + 'px'; layer.style.height = height + 'px';
    }
    function show(value, keyboard) {
        if (value === opened) { return; }
        opened = value;
        fit();
        layer.style.display = value ? 'block' : 'none';
        toggle.setAttribute('aria-expanded', value ? 'true' : 'false');
        toggle.setAttribute('aria-label', value ? closeLabel : openLabel);
        if (keyboard) { (value ? close : toggle).focus(); }
    }
    function stop(event) { if (event.preventDefault) { event.preventDefault(); } event.returnValue = false; }
    function tap(node, action) {
        var x = null, y = null, moved = false, lastTouch = 0;
        node.onclick = function (event) {
            event = event || window.event;
            if (new Date().getTime() - lastTouch > 700) { action(event && event.detail === 0); }
            return false;
        };
        if (!node.addEventListener) { return; }
        node.addEventListener('touchstart', function (event) {
            moved = false; x = y = null;
            if (event.touches.length === 1) { x = event.touches[0].clientX; y = event.touches[0].clientY; }
        }, false);
        node.addEventListener('touchmove', function (event) {
            if (event.touches.length !== 1 || x === null || Math.abs(event.touches[0].clientX - x) > 10 || Math.abs(event.touches[0].clientY - y) > 10) { moved = true; }
        }, false);
        node.addEventListener('touchcancel', function () { x = y = null; }, false);
        node.addEventListener('touchend', function (event) {
            if (x !== null && !moved && event.touches.length === 0) { stop(event); lastTouch = new Date().getTime(); action(false); }
            x = y = null;
        }, false);
    }
    tap(toggle, function (keyboard) { show(!opened, keyboard); });
    tap(close, function (keyboard) { show(false, keyboard); });
    tap(shade, function (keyboard) { show(false, keyboard); });
    function key(event) {
        event = event || window.event;
        var code = event ? event.keyCode : 0;
        if (code === 27 && opened) { show(false, true); return false; }
        if (code === 32 && this === toggle) { show(!opened, true); return false; }
        if (code === 9 && opened) {
            var links = menu.getElementsByTagName('a'), last = links[links.length - 1] || close;
            var target = event.target || event.srcElement;
            if (event.shiftKey && target === close) { last.focus(); return false; }
            if (!event.shiftKey && target === last) { close.focus(); return false; }
        }
    }
    toggle.onkeydown = key; layer.onkeydown = key;
    menu.onclick = function (event) {
        event = event || window.event;
        var node = event ? (event.target || event.srcElement) : null;
        while (node && node !== menu) {
            if (node.nodeName && node.nodeName.toLowerCase() === 'a') { show(false, false); break; }
            node = node.parentNode;
        }
    };
    function edge(delta) { return delta < 0 ? menu.scrollTop <= 0 : menu.scrollTop + menu.clientHeight >= menu.scrollHeight; }
    if (menu.addEventListener) {
        shade.addEventListener('touchmove', function (event) { if (event.touches.length === 1) { stop(event); } }, false);
        menu.addEventListener('touchstart', function (event) { previousY = event.touches.length === 1 ? event.touches[0].clientY : null; }, false);
        menu.addEventListener('touchmove', function (event) {
            if (previousY === null || event.touches.length !== 1) { return; }
            var y = event.touches[0].clientY, delta = previousY - y;
            if (oldViewport) { menu.scrollTop += delta; stop(event); }
            else if (edge(delta)) { stop(event); }
            previousY = y;
        }, false);
        function wheel(event) {
            var node = event.target, inside = false;
            while (node && node !== layer) { if (node === menu) { inside = true; break; } node = node.parentNode; }
            if (!inside || edge(event.deltaY || -event.wheelDelta || 0)) { stop(event); }
        }
        layer.addEventListener('wheel', wheel, false);
        layer.addEventListener('mousewheel', wheel, false);
    }
    if (oldViewport) {
        var oldResize = window.onresize, oldOrientation = window.onorientationchange;
        window.onresize = function () { if (typeof oldResize === 'function') { oldResize(); } fit(); };
        window.onorientationchange = function () { if (typeof oldOrientation === 'function') { oldOrientation(); } fit(); };
    }
    layer.className = 'touch-menu-layer touch-menu-ready' + (oldViewport ? ' touch-menu-absolute' : '');
    toggle.setAttribute('role', 'button'); toggle.setAttribute('aria-label', openLabel);
    close.style.display = 'block';
}());
