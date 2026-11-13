<?php

function export_is_bilibili_link($href) {
    return preg_match('~(^|\.)bilibili\.com$|^b23\.tv$~i', (string) parse_url($href, PHP_URL_HOST));
}

function export_prepare_links($root, $xpath, $permalink, $targets, $media) {
    foreach ($xpath->query('.//a[@href]', $root) as $a) {
        $href = $a->getAttribute('href');
        if ($href === '' || $href[0] === '#' || !legacy_url_is_safe($href, true)) { continue; }
        $absolute = WP_Http::make_absolute_url($href, $permalink);
        $parts = explode('#', $absolute, 2);
        $key = rtrim($parts[0], '/');
        if (isset($targets[$key])) {
            $a->setAttribute('href', $targets[$key] . (isset($parts[1]) ? '#' . $parts[1] : ''));
        } elseif (isset($media[$href]['large'])) {
            $a->setAttribute('href', $media[$href]['large']['src']);
        } elseif (!parse_url($href, PHP_URL_SCHEME)) {
            $a->setAttribute('href', $absolute);
        }
    }
}

function export_clear_editions($directory) {
    if (is_link($directory)) {
        throw new RuntimeException("Editions directory must not be a symbolic link: $directory");
    }
    if (!is_dir($directory)) { return; }
    $items = new RecursiveIteratorIterator(
        new RecursiveDirectoryIterator($directory, FilesystemIterator::SKIP_DOTS),
        RecursiveIteratorIterator::CHILD_FIRST
    );
    foreach ($items as $item) {
        $path = $item->getPathname();
        $removed = $item->isDir() && !$item->isLink() ? rmdir($path) : unlink($path);
        if (!$removed) { throw new RuntimeException("Cannot remove $path"); }
    }
}

function export_publish_site($posts, $out, $per_page) {
    legacy_ensure_directory($out);
    $lock = fopen("$out/.export.lock", 'c');
    if (!$lock || !flock($lock, LOCK_EX | LOCK_NB)) { throw new RuntimeException('Another export is running or output is not writable.'); }
    $base = "$out/editions";
    export_clear_editions($base);
    $profiles = array('early','classic','pocket','touch');
    foreach ($profiles as $profile) { legacy_ensure_directory("$base/$profile"); }
    legacy_ensure_directory("$out/images");
    legacy_ensure_directory("$out/styles");
    $site = array('name'=>legacy_escape(get_bloginfo('name')), 'description'=>legacy_escape(get_bloginfo('description')), 'url'=>legacy_escape(home_url('/')));
    $generated = array(); $warnings = 0;
    $entries = array_fill_keys($profiles, array());
    $song = '宋体'; $black = '黑体';
    $count = count($posts);
    $sidebar = export_sidebar($posts, $per_page);
    $touch_index = export_touch_index($posts, $per_page);
    $targets = array();
    foreach ($posts as $post) {
        $name = 'post_' . (int)$post->ID . '.html';
        $targets[rtrim(get_permalink($post), '/')] = $name;
        $targets[home_url('/?p=' . (int)$post->ID)] = $name;
    }
    foreach ($posts as $i => $post) {
        $id = (int)$post->ID;
        $title = legacy_escape(html_entity_decode($post->post_title, ENT_QUOTES, 'UTF-8'));
        $source = function_exists('has_blocks') && has_blocks($post->post_content)
            ? do_blocks($post->post_content) : wpautop($post->post_content);
        list($dom, $xpath, $root) = legacy_load_fragment($source);
        $cover_url = legacy_post_thumbnail_url($id);
        $media = array(); $cover = array();
        if ($cover_url) {
            $cover = export_media($cover_url, "$out/images", true, $generated, $warnings, 'post_' . $id . ' cover');
            $media[$cover_url] = $cover;
        }
        foreach ($xpath->query('.//img[@src]', $root) as $img) {
            $url = $img->getAttribute('src');
            if ($url !== '' && !isset($media[$url])) {
                $absolute = WP_Http::make_absolute_url($url, get_permalink($post));
                $media[$url] = export_media($absolute, "$out/images", false, $generated, $warnings, 'post_' . $id);
            }
        }
        export_prepare_links($root, $xpath, get_permalink($post), $targets, $media);
        foreach ($xpath->query('.//iframe', $root) as $frame) {
            $poster = 'about:blank';
            if (isset($cover['touch_list'])) {
                $image = basename($cover['touch_list']['src']);
                $name = substr($image, 0, -4) . '-poster.html';
                $path = "$out/images/$name";
                if (!isset($generated[legacy_path_key($path)])) {
                    export_write($path, export_video_poster($image));
                    legacy_track_generated($path, $generated);
                }
                $poster = 'images/' . $name;
            }
            $frame->setAttribute('data-export-poster', $poster);
        }
        $summary = trim($post->post_excerpt);
        if ($summary === '') { $summary = $xpath->evaluate('string((.//p[normalize-space() and not(a and not(normalize-space(text())))])[1])', $root); }
        if ($summary === '') { $summary = $xpath->evaluate('string(.//iframe/@title)', $root); }
        if ($summary === '') { $summary = wp_strip_all_tags($source); }
        $summary = trim(preg_replace('/\s+/u', ' ', html_entity_decode(wp_strip_all_tags($summary), ENT_QUOTES, 'UTF-8')));
        $links = array();
        if ($i + 1 < $count) { $links[] = export_post_link($posts[$i + 1], '上一篇 '); }
        if ($i > 0) { $links[] = export_post_link($posts[$i - 1], '下一篇 '); }
        $links[] = '<a href="index.html">首页</a>';
        foreach ($profiles as $profile) {
            $entries[$profile][] = export_entry($post, $profile, $cover, $summary);
            $hero = export_picture($cover, $profile, $post->post_title, 700);
            if ($hero) {
                $hero = $profile === 'early' ? '<p align="center">' . $hero . '</p>' : '<div class="picture">' . $hero . '</div>';
            }
            $content = $profile === 'early' ? legacy_transform_content(legacy_serialize_children($dom, $root), $media, $song, $black) : export_children($root, $profile, $media);
            $heading = $title; $post_links = $links;
            if ($profile === 'early') {
                $heading = export_early_label(html_entity_decode($post->post_title, ENT_QUOTES, 'UTF-8'), 28);
                $post_links = array();
                if ($i + 1 < $count) { $post_links[] = export_post_link($posts[$i + 1], '上一篇 ', 48); }
                if ($i > 0) { $post_links[] = export_post_link($posts[$i - 1], '下一篇 ', 48); }
                $post_links[] = '<a href="index.html">首页</a>';
            }
            $heading_tag = $profile === 'early' ? 'h2' : 'h1';
            $body = export_mast($profile, $site, false, $profile === 'touch' ? $touch_index : $sidebar) . '<' . $heading_tag . '>' . $heading . '</' . $heading_tag . '>' . $hero . $content . '<hr><p>' . implode('<br>', $post_links) . '</p>';
            $bytes = export_page($profile, $title, $body, 'reader');
            export_save_page("$base/$profile/post_$id.html", $profile, $bytes);
        }
    }
    $pages = max(1, (int)ceil($count / $per_page));
    for ($n = 0; $n < $pages; $n++) {
        $links = array();
        if ($n) { $links[] = '<a href="' . legacy_index_filename($n - 1) . '">上一页</a>'; }
        $links[] = '第 ' . ($n + 1) . ' / ' . $pages . ' 页';
        if ($n + 1 < $pages) { $links[] = '<a href="' . legacy_index_filename($n + 1) . '">下一页</a>'; }
        $nav = implode(' &nbsp; ', $links);
        foreach ($profiles as $profile) {
            $items = implode("\n", array_slice($entries[$profile], $n * $per_page, $per_page));
            if (!$count) { $items = '<p>暂无文章。</p>'; }
            $pagination = $profile === 'early' ? '<p align="center">' . $nav . '</p>'
                : '<div class="pagination" align="center">' . $nav . '</div>';
            $listing = $items . $pagination;
            if ($profile === 'classic') {
                // A single table row keeps the sidebar beside oversized/slow-loading
                // images in old IE; floats can expand and drop the second column.
                $listing = '<table class="columns" width="100%" border="0" cellspacing="0" cellpadding="0" role="presentation">'
                    . '<colgroup><col width="73%"><col width="27%"></colgroup><tr>'
                    . '<td class="posts" width="73%" valign="top">' . $listing . '</td>'
                    . '<td class="sidebar-cell" width="27%" valign="top">' . $sidebar . '</td></tr></table>';
            }
            $html = export_page($profile, $site['name'], export_mast($profile, $site, true, $profile === 'touch' ? $touch_index : $sidebar) . $listing, 'list');
            export_save_page("$base/$profile/" . legacy_index_filename($n), $profile, $html);
        }
    }
    $assets = array_map('basename', array_keys($generated));
    // Include the small compatibility rules in the existing sheet: no extra request on slow devices.
    $pocket_css = file_get_contents(__DIR__ . '/pocket-ie.css');
    $touch_css = file_get_contents(__DIR__ . '/touch-menu.css');
    foreach (array('list.css','reader.css') as $css) {
        export_write("$out/styles/$css", file_get_contents(__DIR__ . "/$css") . "\n" . $pocket_css . "\n" . $touch_css);
    }
    if (is_file("$out/styles/pocket-ie.css") && !unlink("$out/styles/pocket-ie.css")) {
        throw new RuntimeException('Cannot remove obsolete Pocket stylesheet');
    }
    export_write("$out/ua-rules.json", file_get_contents(__DIR__ . '/ua-rules.json'));
    export_write("$out/dispatch.php", file_get_contents(__DIR__ . '/dispatch.php'));
    $config = "$out/.htaccess";
    $rules = file_get_contents(__DIR__ . '/apache.conf');
    $existing = is_file($config) ? file_get_contents($config) : '';
    $custom = preg_replace('/^# BEGIN WordPress Legacy Exporter\R.*?^# END WordPress Legacy Exporter\R?/ms', '', $existing);
    $updated = rtrim($rules) . "\n" . $custom;
    if ($updated !== $existing) { export_write($config, $updated); }

    // Public URLs are served by dispatch.php; remove static copies from older exports.
    legacy_cleanup_stale_output($out, "$out/images", array());
    $keep = array_fill_keys($assets, true);
    foreach (glob("$out/images/wp-*") as $file) {
        $name = basename($file);
        if (preg_match('/^wp-[a-f0-9]{24}-(?:(?:large|early|pocket|early_list|classic_list|touch_list)\.(?:jpg|png|gif)|touch_list-poster\.html)$/D', $name) && !isset($keep[$name])) { unlink($file); }
    }
    fclose($lock);
    echo "Build OK: $count posts, $pages index pages, 4 editions.\n";
    if ($warnings) { fwrite(STDERR, "Warning: $warnings image operations failed; see details above (counted per source/variant, not unique images).\n"); }
}

function export_save_page($path, $profile, $html) {
    if ($profile === 'early') { legacy_write_html($path, legacy_compact_html($html)); }
    else { export_write($path, $html); }
}
