<?php

function export_theme_defaults() {
    return array(
        'primary'=>'#006699', 'on_primary'=>'#ffffff', 'background'=>'#eff3ff', 'surface'=>'#ffffff',
        'text'=>'#333333', 'muted'=>'#707070', 'link'=>'#333333', 'visited'=>'#333333', 'date'=>'#666666',
        'early_link'=>'#006699', 'early_visited'=>'#707070',
        'border'=>'#cccccc', 'heading_border'=>'#eaeaea', 'list_border'=>'#dddddd', 'frame_border'=>'#bcccd8',
        'header_border'=>'#b8d5e4', 'title_link'=>'#064e71', 'code_background'=>'#f7f7f7',
        'table_header_background'=>'#f1f1f1', 'sidebar_background'=>'#f4f7fb', 'sidebar_border'=>'#d6e1e8',
        'sidebar_heading_border'=>'#c8d8e3', 'sidebar_heading'=>'#174a65', 'sidebar_link'=>'#245874',
        'menu_button'=>'#005480', 'menu_heading'=>'#496879', 'menu_link'=>'#174a65',
        'menu_subtitle'=>'#e2f1f8', 'menu_muted'=>'#6b7d88', 'menu_border'=>'#e4eaf0',
        'menu_active'=>'#dcebf5', 'focus'=>'#d39c36', 'overlay'=>'#50616c'
    );
}

function export_messages($language) {
    if (strtolower($language) === 'zh-cn') {
        return array('notice'=>'本站为旧式浏览器设计。', 'main_site'=>'返回主站', 'home'=>'首页',
            'previous_post'=>'上一篇', 'next_post'=>'下一篇', 'previous_page'=>'上一页', 'next_page'=>'下一页',
            'page_status'=>'第 {page} / {pages} 页', 'empty'=>'暂无文章。', 'index'=>'索引', 'index_label'=>'文章索引',
            'open'=>'展开文章索引', 'close'=>'关闭文章索引', 'browse_all'=>'浏览全部文章', 'recent_posts'=>'近期文章', 'recent_updates'=>'最近更新',
            'by_year'=>'按年浏览', 'year_label'=>'{year} 年', 'post_count'=>'{count} 篇');
    }
    return array('notice'=>'Designed for older browsers.', 'main_site'=>'Main site', 'home'=>'Home',
        'previous_post'=>'Previous post', 'next_post'=>'Next post', 'previous_page'=>'Previous page', 'next_page'=>'Next page',
        'page_status'=>'Page {page} / {pages}', 'empty'=>'No posts yet.', 'index'=>'Index', 'index_label'=>'Article index',
        'open'=>'Open article index', 'close'=>'Close article index', 'browse_all'=>'Browse all posts', 'recent_posts'=>'Recent posts', 'recent_updates'=>'Recent updates',
        'by_year'=>'Browse by year', 'year_label'=>'{year}', 'post_count'=>'{count} posts');
}

function export_config($replacement = null) {
    static $config = null;
    if ($replacement !== null) { $config = $replacement; }
    if ($config === null) {
        $config = array('language'=>'zh-CN', 'early_charset'=>'GB2312', 'unmappable'=>'entity',
            'theme'=>export_theme_defaults(), 'messages'=>export_messages('zh-CN'), 'visited_override'=>false);
    }
    return $config;
}

function export_load_config($path = null) {
    $input = new stdClass();
    if ($path !== null) {
        $bytes = @file_get_contents($path);
        if ($bytes === false) { throw new RuntimeException('Cannot read config: ' . $path); }
        $input = json_decode($bytes);
        if (!($input instanceof stdClass) || json_last_error() !== JSON_ERROR_NONE) {
            throw new RuntimeException('Config must be a UTF-8 JSON object: ' . $path);
        }
    }
    $config = array('language'=>'zh-CN', 'early_charset'=>'GB2312', 'unmappable'=>'entity',
        'theme'=>export_theme_defaults(), 'messages'=>array());
    foreach ($input as $key => $value) {
        if (!array_key_exists($key, $config)) { throw new RuntimeException('Unknown config key: ' . $key); }
        if ($key === 'theme' || $key === 'messages') {
            if (!($value instanceof stdClass)) { throw new RuntimeException($key . ' must be an object'); }
        } elseif (!is_string($value)) { throw new RuntimeException($key . ' must be a string'); }
    }
    foreach (array('language','early_charset','unmappable') as $key) {
        if (isset($input->$key)) { $config[$key] = $input->$key; }
    }
    if (!preg_match('/^[A-Za-z]{2,3}(?:-[A-Za-z0-9]{2,8})*$/D', $config['language'])) {
        throw new RuntimeException('language must be a language tag, e.g. zh-CN or en');
    }
    $encodings = array('UTF-8','GB2312','GBK','GB18030','Big5','Shift_JIS','EUC-JP','EUC-KR',
        'windows-1252','windows-1251','ISO-8859-1','KOI8-R');
    $matched = false;
    foreach ($encodings as $encoding) {
        if (strcasecmp($encoding, $config['early_charset']) === 0) { $config['early_charset'] = $encoding; $matched = true; break; }
    }
    if (!$matched || @iconv('UTF-8', $config['early_charset'], '<html>ASCII</html>') !== '<html>ASCII</html>') {
        throw new RuntimeException('Unsupported early_charset; choose: ' . implode(', ', $encodings));
    }
    if (!in_array($config['unmappable'], array('entity','error'), true)) {
        throw new RuntimeException('unmappable must be entity or error');
    }
    if (isset($input->theme)) {
        foreach ($input->theme as $key => $value) {
            if (!array_key_exists($key, $config['theme'])) { throw new RuntimeException('Unknown theme color: ' . $key); }
            if (!is_string($value) || !preg_match('/^#(?:[0-9a-f]{3}|[0-9a-f]{6})$/iD', $value)) {
                throw new RuntimeException('Theme color ' . $key . ' must be #RGB or #RRGGBB');
            }
            if (strlen($value) === 4) { $value = '#' . $value[1] . $value[1] . $value[2] . $value[2] . $value[3] . $value[3]; }
            $config['theme'][$key] = strtolower($value);
        }
        if (!isset($input->theme->early_link)) {
            if (isset($input->theme->link)) { $config['theme']['early_link'] = $config['theme']['link']; }
            elseif (isset($input->theme->primary)) { $config['theme']['early_link'] = $config['theme']['primary']; }
        }
        if (!isset($input->theme->early_visited) && isset($input->theme->visited)) {
            $config['theme']['early_visited'] = $config['theme']['visited'];
        }
    }
    $config['messages'] = export_messages($config['language']);
    if (isset($input->messages)) {
        foreach ($input->messages as $key => $value) {
            if (!array_key_exists($key, $config['messages'])) { throw new RuntimeException('Unknown message: ' . $key); }
            if (!is_string($value) || preg_match('/[\x00-\x1f\x7f]/', $value)) { throw new RuntimeException('Message ' . $key . ' must be plain single-line text'); }
            preg_match_all('/\{[^{}]*\}/', $config['messages'][$key], $required);
            preg_match_all('/\{[^{}]*\}/', $value, $provided);
            $a = array_unique($required[0]); $b = array_unique($provided[0]); sort($a); sort($b);
            if ($a !== $b) { throw new RuntimeException('Message ' . $key . ' must preserve placeholders: ' . implode(', ', $a)); }
            $config['messages'][$key] = $value;
        }
    }
    $config['visited_override'] = isset($input->theme->visited);
    export_config($config);
    return $config;
}

function export_text($key, $values = array()) {
    $config = export_config(); $message = $config['messages'][$key]; $replace = array();
    foreach ($values as $name => $value) { $replace['{' . $name . '}'] = (string)$value; }
    return htmlspecialchars(strtr($message, $replace), ENT_QUOTES, 'UTF-8');
}

function export_color($key) { $config = export_config(); return $config['theme'][$key]; }
function export_charset() { $config = export_config(); return $config['early_charset']; }

function export_theme_css($css) {
    $config = export_config();
    // Keep each component's existing link colors unless a visited color was requested.
    if (!$config['visited_override']) { $css = preg_replace('/^.*:visited[^\r\n]*(?:\r?\n|$)/m', '', $css); }
    return preg_replace_callback('/\{\{([a-z_]+)\}\}/', function ($match) {
        $config = export_config();
        if (!isset($config['theme'][$match[1]])) { throw new RuntimeException('Unknown CSS color token: ' . $match[1]); }
        return $config['theme'][$match[1]];
    }, $css);
}

function export_encode_early($html, $path) {
    $config = export_config(); $encoding = $config['early_charset'];
    // Retain the original GB2312 dash mapping (A1AA is U+2015 in iconv).
    if ($encoding === 'GB2312') { $html = str_replace("\xE2\x80\x94", "\xE2\x80\x95", $html); }
    $bytes = @iconv('UTF-8', $encoding, $html);
    if ($bytes !== false) { return $bytes; }
    if (!preg_match('//u', $html)) { throw new RuntimeException('Invalid UTF-8 HTML: ' . $path); }
    if ($config['unmappable'] === 'error') { throw new RuntimeException('Characters cannot be encoded as ' . $encoding . ': ' . $path); }
    $count = 0; $cache = array();
    $bytes = preg_replace_callback('/[^\x00-\x7f]/u', function ($match) use ($encoding, &$count, &$cache) {
        $char = $match[0];
        if (!isset($cache[$char])) {
            $encoded = @iconv('UTF-8', $encoding, $char);
            $code = unpack('Nvalue', iconv('UTF-8', 'UTF-32BE', $char));
            $cache[$char] = array($encoded === false ? '&#' . $code['value'] . ';' : $encoded, $encoded === false);
        }
        if ($cache[$char][1]) { $count++; }
        return $cache[$char][0];
    }, $html);
    if ($count) { fwrite(STDERR, 'Warning: ' . $path . ': ' . $count . ' characters unavailable in ' . $encoding . '; preserved as numeric character references.' . "\n"); }
    return $bytes;
}
