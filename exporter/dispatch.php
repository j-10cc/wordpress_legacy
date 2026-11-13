<?php
// This file is copied into the exported site. Serving pages does not load WordPress.
function legacy_edition($ua) {
    $rules = json_decode(file_get_contents(__DIR__ . '/ua-rules.json'), true);
    foreach ($rules as $rule) {
        if (preg_match('~' . $rule[1] . '~i', $ua)) { return $rule[0]; }
    }
    return 'classic';
}

function legacy_pocket_mobile_ie($ua) {
    // Alternative browsers often carry an MSIE compatibility token too.
    if (preg_match('~Opera|OPR/|Mini|NetFront|UCBrowser|UCWEB|Firefox|Fennec|AppleWebKit|BlackBerry|BB10~i', $ua)) { return false; }
    return (bool) preg_match('~IEMobile|MSPIE|(?:MSIE.*(?:Windows[ ]*CE|WinCE|Windows Mobile|Pocket[ ]?PC))|(?:(?:Windows[ ]*CE|WinCE|Windows Mobile|Pocket[ ]?PC).*MSIE)~i', $ua);
}

function legacy_pocket_styles($html, $ua) {
    if (!legacy_pocket_mobile_ie($ua)) { return $html; }
    // One Pocket document; only enable its IE CSS and narrow-screen hint.
    return str_replace(array('<body class="pocket">', '</head>'),
        array('<body class="pocket pocket-ie">', '<meta name="MobileOptimized" content="240"></head>'), $html);
}

function legacy_dispatch() {
    $path = parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH);
    if (PHP_SAPI === 'cli-server' && preg_match('~^/(?:images/[\w.-]+\.(?:gif|png|jpg)|images/wp-[a-f0-9]{24}-touch_list-poster\.html|styles/(?:reader|list)\.css)$~D', $path)) {
        return false; // PHP's development server serves these files directly.
    }
    if (PHP_SAPI === 'cli-server' && $path !== '/' && !preg_match('~^/[^/]+$~D', $path)) {
        http_response_code(404);
        return true;
    }
    $name = substr($path, -1) === '/' ? 'index.html' : basename($path);
    if (!preg_match('~^(?:index(?:[2-9]|[1-9][0-9]+)?|post_[0-9]+)\.html$~D', $name)) {
        http_response_code(404);
        return true;
    }
    $ua = isset($_SERVER['HTTP_USER_AGENT']) ? $_SERVER['HTTP_USER_AGENT'] : '';
    $edition = legacy_edition($ua);
    $dir = __DIR__ . '/editions/' . $edition;
    $file = $dir . '/' . $name;
    header('Vary: User-Agent');
    if (!is_file($file)) {
        http_response_code(404);
        return true;
    }
    if (preg_match('~\b(?:IBM[- ]?)?WebExplorer\b~i', $ua)) {
        // Use a bare MIME type for WebExplorer; keep the encoding in the HTML meta.
        // PHP otherwise appends default_charset even to an explicit text/html header.
        ini_set('default_charset', '');
        header('Content-Type: text/html');
    } else {
        header('Content-Type: text/html; charset=' . ($edition === 'early' ? 'GB2312' : 'UTF-8'));
    }
    if ($edition === 'pocket') {
        $html = legacy_pocket_styles(file_get_contents($file), $ua);
        header('Content-Length: ' . strlen($html));
        if ($_SERVER['REQUEST_METHOD'] !== 'HEAD') { echo $html; }
    } else {
        header('Content-Length: ' . filesize($file));
        if ($_SERVER['REQUEST_METHOD'] !== 'HEAD') { readfile($file); }
    }
    return true;
}
if (PHP_SAPI !== 'cli') {
    return legacy_dispatch();
}
