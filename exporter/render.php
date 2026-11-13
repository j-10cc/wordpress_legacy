<?php

require_once __DIR__ . '/pocket-ie.php';

function export_write($path, $bytes) {
    if ($bytes === false) { throw new RuntimeException("Missing source data for $path"); }
    $tmp = tempnam(dirname($path), '.export-');
    if ($tmp === false || file_put_contents($tmp, $bytes) !== strlen($bytes) || !chmod($tmp, 0644) || !rename($tmp, $path)) {
        if ($tmp !== false) { @unlink($tmp); }
        throw new RuntimeException("Cannot publish $path");
    }
}

function export_image_warning($url, $stage, $reason, $context, &$warnings) {
    $warnings++;
    // Keep each diagnostic on one line, including errors returned by remote servers.
    $detail = preg_replace('/[\x00-\x1f\x7f]+/', ' ', $context . ' [' . $stage . '] ' . $url . ' - ' . $reason);
    fwrite(STDERR, 'Warning: image ' . substr($detail, 0, 4096) . "\n");
}

// Each source is fetched once per article. All stored references are local, including enlarged photos.
function export_media($url, $dir, $cover, &$generated, &$warnings, $context = '') {
    $error = null;
    $data = legacy_download_image_data($url, $error);
    if ($data === false) {
        export_image_warning($url, 'download', $error, $context, $warnings);
        return array();
    }
    $info = @getimagesizefromstring($data);
    if (!$info || $info[0] < 1 || $info[1] < 1) {
        export_image_warning($url, 'decode', 'Response is not a raster image recognized by PHP (invalid data or unsupported format)', $context, $warnings);
        return array();
    }
    $ext = $info[2] === IMAGETYPE_JPEG ? 'jpg' : 'png';
    // The large variant keeps both pixel dimensions, including after EXIF rotation.
    $sizes = array('large'=>array(max(1, $info[0], $info[1]), 0), 'early'=>array(320, 240), 'pocket'=>array(192, 0));
    if ($cover) {
        $sizes += array('early_list'=>array(200, 150), 'classic_list'=>array(672, 0), 'touch_list'=>array(480, 0));
    }
    $result = array();
    // Bump this policy key when encoding changes, so a valid old cache is not reused.
    $hash = substr(hash('sha256', "image-policy-2\0" . $data), 0, 24);
    foreach ($sizes as $kind => $bounds) {
        list($width, $height) = $bounds;
        $format = strpos($kind, 'early') === 0 ? 'gif' : $ext;
        $name = "wp-$hash-$kind.$format";
        $path = "$dir/$name";
        $size = legacy_image_size($path, $format);
        if (!$size || $size[0] > $width || ($height && $size[1] > $height)) {
            if (!legacy_convert_image($data, $path, $width, $format, $error, $height)) {
                export_image_warning($url, 'convert ' . $kind . ' ' . $format . ' ' . $width . ($height ? 'x' . $height : '') . 'px', $error, $context, $warnings);
                continue;
            }
            $size = legacy_image_size($path, $format);
        }
        if (!$size) {
            export_image_warning($url, 'validate ' . $kind, 'Cannot read converted image: ' . $path, $context, $warnings);
            continue;
        }
        $result[$kind] = array('src'=>'images/' . $name, 'w'=>$size[0], 'h'=>$size[1]);
        legacy_track_generated($path, $generated);
    }
    return $result;
}

function export_img($images, $kind, $limit, $caption = '', $class = '', $edition = '') {
    if (empty($images[$kind])) { return ''; }
    $im = $images[$kind];
    $w = min($im['w'], $limit);
    $h = max(1, round($im['h'] * $w / $im['w']));
    $sizing = '';
    if (in_array($edition, array('classic','pocket','touch'), true)) {
        // Old IE may expose rendered dimensions through getAttribute(width/height).
        // Preserve the export-time dimensions independently of layout and decoding.
        $sizing = ' data-fit-width="' . $w . '" data-fit-height="' . $h . '"';
        if ($edition !== 'touch') {
            $sizing .= ' style="width:' . $w . 'px;height:' . $h . 'px"';
        }
    }
    return '<img class="' . $class . '" src="' . $im['src'] . '" width="' . $w . '" height="' . $h
        . '"' . $sizing . ' border="0" alt="' . legacy_escape($caption) . '">';
}

function export_picture($images, $edition, $caption, $limit, $linked = false) {
    $kind = in_array($edition, array('classic','touch'), true) ? 'large' : $edition;
    $img = export_img($images, $kind, $limit, $caption, '', $edition);
    if ($img && !$linked && isset($images['large'])) {
        $img = '<a href="' . $images['large']['src'] . '">' . $img . '</a>';
    }
    return $img;
}

function export_video_poster($image) {
    return '<!DOCTYPE html><html><head><meta name="viewport" content="width=device-width, initial-scale=1"><title>Video</title></head>'
        . '<body style="margin:0;text-align:center;background:#eff3ff;overflow:hidden">'
        . '<img style="display:block;width:100%;max-width:480px;height:auto;margin:0 auto" src="' . legacy_escape($image) . '" alt=""></body></html>';
}

function export_video($node, $edition) {
    if (!in_array($edition, array('classic','touch'), true)) { return ''; }
    $src = $node->getAttribute('src');
    if (strpos($src, '//') === 0) { $src = 'https:' . $src; }
    $url = parse_url($src);
    if (!isset($url['host'], $url['query']) || strtolower($url['host']) !== 'player.bilibili.com') { return ''; }
    parse_str($url['query'], $query);
    if (empty($query['bvid']) || !is_string($query['bvid']) || !preg_match('/^BV[0-9A-Za-z]+$/D', $query['bvid'])) { return ''; }
    $bv = $query['bvid'];
    $query['autoplay'] = '0';
    $player = 'https://player.bilibili.com/player.html?' . legacy_escape(http_build_query($query));
    $target = 'player-' . $node->getLineNo() . '-' . $bv;
    $poster = legacy_escape($node->getAttribute('data-export-poster'));
    return '<div class="video"><a href="' . $player . '" target="' . $target . '">载入网页播放器</a>'
        . '<div class="video-frame"><iframe title="视频演示" name="' . $target . '" src="' . $poster . '" width="100%" height="240" frameborder="0" scrolling="no"></iframe></div>'
        . '<a href="https://www.bilibili.com/video/' . $bv . '/">在哔哩哔哩观看</a> · ' . $bv . '</div>';
}

function export_children($parent, $edition, &$media) {
    $html = '';
    foreach ($parent->childNodes as $node) { $html .= export_node($node, $edition, $media); }
    return $html;
}

function export_node($node, $edition, &$media) {
    if ($node->nodeType === XML_TEXT_NODE) { return legacy_escape($node->nodeValue); }
    if ($node->nodeType !== XML_ELEMENT_NODE) { return ''; }
    $tag = strtolower($node->nodeName);
    if (in_array($tag, array('script','style','object','embed','form','textarea','button','input','svg','video','audio','select'), true)) { return ''; }
    if ($tag === 'iframe') { return export_video($node, $edition); }
    if ($tag === 'a' && $edition === 'pocket' && export_is_bilibili_link($node->getAttribute('href'))) {
        return export_children($node, $edition, $media);
    }
    if ($tag === 'img') {
        $src = $node->getAttribute('src');
        $width = $edition === 'pocket' ? 192 : 700;
        $figure = legacy_nearest_ancestor($node, 'figure');
        if ($edition === 'classic' && $figure && legacy_nearest_ancestor($figure, 'figure')) { $width = 330; }
        $img = export_picture(isset($media[$src]) ? $media[$src] : array(), $edition,
            $node->getAttribute('alt'), $width, legacy_has_ancestor_tag($node, array('a')));
        return $img ?: legacy_escape($node->getAttribute('alt'));
    }
    if ($tag === 'pre') {
        $plain = str_replace("\xC2\xA0", ' ', legacy_normalize_code_text(legacy_extract_code_text($node)));
        if ($edition === 'pocket') { return export_ie_code($plain); }
        $code = legacy_escape($plain);
        return '<div class="scroll code"><pre><code>' . $code . '</code></pre></div>';
    }
    if ($tag === 'figure' && $edition === 'classic' && strpos($node->getAttribute('class'), 'wp-block-gallery') !== false) {
        $rows = ''; $cells = array();
        foreach ($node->childNodes as $child) {
            if ($child->nodeName === 'figure') { $cells[] = '<td>' . export_node($child, $edition, $media) . '</td>'; }
        }
        if ($cells) {
            foreach (array_chunk($cells, 2) as $pair) { $rows .= '<tr>' . implode('', $pair) . '</tr>'; }
            $captions = '';
            foreach ($node->childNodes as $child) {
                if ($child->nodeName === 'figcaption') { $captions .= export_node($child, $edition, $media); }
            }
            return '<table class="gallery" role="presentation">' . $rows . '</table>' . $captions;
        }
    }
    $inner = export_children($node, $edition, $media);
    if ($tag === 'figure' || $tag === 'figcaption') { return '<div class="picture">' . $inner . '</div>'; }
    if ($tag === 'table') {
        $table = '<table width="100%" cellspacing="0" border="1">' . $inner . '</table>';
        if ($edition === 'pocket') { $table = export_ie_table($table); }
        return '<div class="scroll">' . $table . '</div>';
    }
    if (in_array($tag, array('br','hr'), true)) { return '<' . $tag . '>'; }
    if (!in_array($tag, array('div','span','p','a','b','strong','em','i','u','s','sub','sup','code','blockquote','dl','dt','dd','ul','ol','li',
        'thead','tbody','tfoot','caption','tr','th','td','h1','h2','h3','h4','h5','h6'), true)) { return $inner; }
    $attrs = '';
    foreach (array('id','name','colspan','rowspan','start','href','title') as $key) {
        if (!$node->hasAttribute($key)) { continue; }
        $val = $node->getAttribute($key);
        if ($key === 'href' && ($tag !== 'a' || !legacy_url_is_safe($val, true))) { continue; }
        $attrs .= ' ' . $key . '="' . legacy_escape($val) . '"';
    }
    if ($tag === 'h1') { $tag = 'h2'; } elseif ($tag === 'h2') { $tag = 'h3'; }
    return '<' . $tag . $attrs . '>' . $inner . '</' . $tag . '>';
}

function export_page($edition, $title, $body, $stylesheet) {
    $script = '';
    if ($edition === 'pocket' && strpos($body, 'class="code"') !== false) {
        static $wrap_script = null;
        if ($wrap_script === null) { $wrap_script = file_get_contents(__DIR__ . '/pocket-code.js'); }
        $script = '<script type="text/javascript">' . $wrap_script . '</script>';
    }
    if (in_array($edition, array('classic','pocket','touch'), true) && (strpos($body, '<img ') !== false || strpos($body, '<iframe ') !== false)) {
        static $media_script = null;
        if ($media_script === null) { $media_script = file_get_contents(__DIR__ . '/fit-media.js'); }
        $script .= '<script type="text/javascript">' . $media_script . '</script>';
    }
    if ($edition === 'early') {
        $head = '<!DOCTYPE html PUBLIC "-//W3C//DTD HTML 3.2 Final//EN">';
        $meta = '<meta http-equiv="Content-Type" content="text/html; charset=GB2312">';
        // Plain flow lets early browsers paint paragraphs without laying out one giant table.
        $body = '<body bgcolor="#ffffff" text="#333333" link="#006699" vlink="#707070">' . $body;
    } else {
        $head = '<!DOCTYPE html>';
        $meta = '<meta http-equiv="Content-Type" content="text/html; charset=UTF-8">'
            . '<meta name="viewport" content="width=device-width, initial-scale=1"><link rel="stylesheet" href="styles/' . $stylesheet . '.css">';
        if ($edition === 'pocket') {
            $meta .= '<meta name="HandheldFriendly" content="true">';
        }
        $body = '<body class="' . $edition . '"><div class="page">' . $body . '</div>';
    }
    return $head . '<html><head>' . $meta . '<title>' . $title . '</title></head>' . $body . $script . '</body></html>';
}

function export_mast($edition, $site, $is_list, $sidebar = '') {
    $notice = '本站为旧式浏览器设计。';
    if ($edition === 'early') {
        $text = '<font color="#006699"><b>' . export_early_label(html_entity_decode($site['name'], ENT_QUOTES, 'UTF-8'), 40) . '</b></font>';
        if ($is_list) {
            $text .= '<br>' . $site['description'] . '<br>' . $notice
                . ' <a href="' . $site['url'] . '">返回主站</a>';
        }
        return '<p>' . $text . '</p><hr>';
    }
    $toggle = ''; $menu = '';
    if ($edition === 'touch' && $sidebar !== '') {
        $toggle = '<a class="touch-menu-toggle" id="touch-menu-toggle" href="#touch-index" aria-controls="touch-index" aria-expanded="false" aria-label="文章索引">'
            . '<span class="menu-icon" aria-hidden="true"><span></span><span></span><span></span></span>索引</a>';
        $menu = '<div class="touch-menu-layer" id="touch-menu-layer" style="display:none">'
            . '<div class="touch-menu-shade" id="touch-menu-shade" aria-hidden="true"></div>'
            . '<div class="touch-menu" id="touch-index" role="navigation" aria-label="文章索引">'
            . '<div class="touch-menu-head"><button type="button" class="touch-menu-close" id="touch-menu-close" aria-label="关闭文章索引">&#215;</button><h2>文章索引</h2></div>'
            . '<div class="touch-menu-content"><a class="touch-home" href="index.html">首页<span>浏览全部文章 &#8250;</span></a>' . $sidebar . '</div></div></div>';
        static $menu_script = null;
        if ($menu_script === null) { $menu_script = file_get_contents(__DIR__ . '/touch-menu.js'); }
        // Hide in the markup itself, and initialize before parsing the long article/media.
        $menu .= '<noscript><style type="text/css">#touch-menu-layer { display:block !important; }</style></noscript>'
            . '<script type="text/javascript">' . $menu_script . '</script>';
    }
    if (!$is_list) {
        $name = $toggle === '' ? $site['name'] : '<span class="mast-title">' . $site['name'] . '</span>';
        return '<div class="mast">' . $toggle . $name . '</div>' . $menu;
    }
    $name = $edition === 'classic' ? '<a href="index.html">' . $site['name'] . '</a>' : $site['name'];
    return '<div class="mast">' . $toggle . '<h1>' . $name . '</h1><p>' . $site['description'] . '</p><p class="notice">'
        . $notice . ' <a href="' . $site['url'] . '">返回主站</a></p></div>' . $menu;
}

function export_early_label($text, $columns = 48) {
    $html = '';
    foreach (preg_split('/(\s+)/u', $text, -1, PREG_SPLIT_DELIM_CAPTURE) as $part) {
        $html .= implode('<br>', array_map('legacy_escape', export_ie_wrap_text($part, $columns)));
    }
    return $html;
}

function export_post_link($post, $prefix = '', $columns = 0) {
    $title = html_entity_decode($post->post_title, ENT_QUOTES, 'UTF-8');
    return '<a href="post_' . (int)$post->ID . '.html">' . $prefix . ($columns ? export_early_label($title, $columns) : legacy_escape($title)) . '</a>';
}

function export_sidebar($posts, $per_page) {
    $recent = ''; $years = array();
    foreach ($posts as $i => $post) {
        if ($i < 5) { $recent .= '<li>' . export_post_link($post) . '</li>'; }
        $year = substr($post->post_date, 0, 4);
        if (!isset($years[$year])) {
            $years[$year] = '<li><a href="' . legacy_index_filename((int)floor($i / $per_page)) . '#post-' . (int)$post->ID . '">' . legacy_escape($year) . ' 年</a></li>';
        }
    }
    return '<div class="sidebar"><h2>近期文章</h2><ul>' . $recent . '</ul><h2>按年浏览</h2><ul>' . implode('', $years) . '</ul></div>';
}

function export_touch_index($posts, $per_page) {
    $recent = ''; $years = array();
    foreach ($posts as $i => $post) {
        if ($i < 5) {
            $recent .= '<li><a href="post_' . (int)$post->ID . '.html"><span class="touch-recent-title">'
                . legacy_escape(html_entity_decode($post->post_title, ENT_QUOTES, 'UTF-8')) . '</span><span class="touch-recent-date">'
                . legacy_escape(substr($post->post_date, 0, 10)) . '</span></a></li>';
        }
        $year = substr($post->post_date, 0, 4);
        if (!isset($years[$year])) {
            $years[$year] = array('href'=>legacy_index_filename((int)floor($i / $per_page)) . '#post-' . (int)$post->ID, 'count'=>0);
        }
        $years[$year]['count']++;
    }
    $archive = '';
    foreach ($years as $year => $item) {
        $archive .= '<li><a href="' . $item['href'] . '">' . legacy_escape((string)$year) . '<span>' . $item['count'] . ' 篇</span></a></li>';
    }
    return '<h3>最近更新</h3><ul class="touch-recent">' . $recent . '</ul>'
        . '<h3>按年浏览</h3><ul class="touch-years">' . $archive . '</ul>';
}

function export_entry($post, $edition, $images, $summary) {
    $title = export_post_link($post, '', $edition === 'early' ? 48 : 0);
    $date = '<small>' . legacy_escape(substr($post->post_date, 0, 10)) . '</small>';
    $kinds = array('early'=>'early_list','classic'=>'classic_list','touch'=>'touch_list','pocket'=>'pocket');
    $cover = export_img($images, $kinds[$edition], 672, '', 'thumb', $edition);
    $limit = in_array($edition, array('early','pocket'), true) ? 70 : 120;
    if (iconv_strlen($summary, 'UTF-8') > $limit) { $summary = rtrim(iconv_substr($summary, 0, $limit, 'UTF-8')) . '…'; }
    $snippet = $summary === '' ? '' : '<p>' . legacy_escape($summary) . '</p>';
    if ($edition === 'early') {
        // CE2 Pocket IE can place the date and image on one baseline even with BR.
        // Separate table rows enforce the layout without a page-wide layout table.
        return '<table border="0" cellspacing="0" cellpadding="0">'
            . '<tr><td align="left"><b>' . $title . '</b></td></tr>'
            . '<tr><td align="left">' . $date . '</td></tr>'
            . ($cover ? '<tr><td align="left">' . $cover . '</td></tr>' : '')
            . '</table>' . $snippet . '<hr>';
    }
    $heading = '<h2>' . $title . '</h2>';
    if ($edition === 'touch') { return '<div class="item" id="post-' . (int)$post->ID . '">' . $cover . '<div class="copy">' . $date . $heading . $snippet . '</div></div>'; }
    return '<div class="item" id="post-' . (int)$post->ID . '">' . $heading . $date . $cover . $snippet . '</div>';
}
