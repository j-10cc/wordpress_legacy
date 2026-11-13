<?php

// Source encoding: GBK. Output charset is configured per edition.

if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    exit("This exporter must be run from the command line.\n");
}

require_once __DIR__ . '/exporter/config.php';

define('LEGACY_IMAGE_VSPACE', '6');

function legacy_join_path($base, $child) {
    return rtrim($base, "/\\") . DIRECTORY_SEPARATOR . ltrim($child, "/\\");
}

function legacy_is_absolute_path($path) {
    return preg_match('/^(?:[A-Za-z]:[\\\\\/]|[\\\\\/]{1,2})/', $path) === 1;
}

function legacy_resolve_path($path, $base) {
    if (legacy_is_absolute_path($path)) {
        return rtrim($path, "/\\");
    }

    return legacy_join_path($base, $path);
}

function legacy_source_to_utf8($text) {
    $converted = iconv('GBK', 'UTF-8//IGNORE', $text);
    return $converted === false ? '' : $converted;
}

function legacy_escape($text) {
    return htmlspecialchars($text, ENT_QUOTES, 'UTF-8');
}

function legacy_normalize_dashes($text) {
    $em_dash = "\xE2\x80\x94";
    $dash_tokens = array($em_dash, '&mdash;', '&#8212;', '&#x2014;', '&#X2014;');

    foreach ($dash_tokens as $dash) {
        $text = str_replace($dash, $em_dash, $text);
    }
    while (strpos($text, $em_dash . $em_dash) !== false) {
        $text = str_replace($em_dash . $em_dash, $em_dash, $text);
    }

    return $text;
}

function legacy_write_html($path, $utf8_html) {
    $utf8_html = legacy_normalize_dashes($utf8_html);
    $encoded = export_encode_early($utf8_html, $path);

    if (@file_put_contents($path, $encoded) === false) {
        throw new RuntimeException("Cannot write output: $path");
    }
}

function legacy_ensure_directory($path) {
    if (is_dir($path)) {
        return;
    }

    if (!@mkdir($path, 0775, true) && !is_dir($path)) {
        throw new RuntimeException("Cannot create directory: $path");
    }
}

function legacy_path_key($path) {
    return str_replace('\\', '/', $path);
}

function legacy_track_generated($path, &$generated_files) {
    $generated_files[legacy_path_key($path)] = true;
}

function legacy_find_wp_load($base_dir, $options) {
    $candidates = array();

    if (isset($options['wordpress-root'])) {
        $candidates[] = legacy_join_path(
            legacy_resolve_path($options['wordpress-root'], $base_dir),
            'wp-load.php'
        );
    } else {
        $environment_root = getenv('WORDPRESS_ROOT');
        if ($environment_root !== false && $environment_root !== '') {
            $candidates[] = legacy_join_path($environment_root, 'wp-load.php');
        }
        $candidates[] = legacy_join_path($base_dir, 'wp-load.php');
        $candidates[] = legacy_join_path(getcwd(), 'wp-load.php');
    }

    foreach ($candidates as $candidate) {
        if (is_file($candidate)) {
            return $candidate;
        }
    }

    throw new RuntimeException(
        "Cannot find wp-load.php. Put the exporter in the WordPress root, "
        . "set WORDPRESS_ROOT, or use --wordpress-root=PATH."
    );
}

function legacy_normalize_image_url($url) {
    $url = trim(html_entity_decode($url, ENT_QUOTES, 'UTF-8'));
    if ($url === '') {
        return false;
    }

    if (strpos($url, '//') === 0) {
        $url = (function_exists('is_ssl') && is_ssl() ? 'https:' : 'http:') . $url;
    }

    $scheme = parse_url($url, PHP_URL_SCHEME);
    if ($scheme === null || $scheme === false || $scheme === '') {
        if (!function_exists('home_url')) {
            return false;
        }
        $url = home_url('/' . ltrim($url, '/'));
        $scheme = parse_url($url, PHP_URL_SCHEME);
    }

    $scheme = strtolower((string) $scheme);
    if ($scheme !== 'http' && $scheme !== 'https') {
        return false;
    }

    return $url;
}

function legacy_download_image_data($url, &$error = null) {
    $error = null;
    $url = legacy_normalize_image_url($url);
    if ($url === false) {
        $error = 'Invalid or unsupported image URL (expected HTTP/HTTPS)';
        return false;
    }
    $limit = 20 * 1024 * 1024;
    if (function_exists('wp_safe_remote_get')) {
        $response = wp_safe_remote_get($url, array(
            'timeout' => 300,
            'redirection' => 3,
            // One extra byte distinguishes an oversized response from a complete image.
            'limit_response_size' => $limit + 1
        ));
        if (is_wp_error($response)) {
            $error = $response->get_error_code() . ': ' . $response->get_error_message();
            return false;
        }
        $status = (int) wp_remote_retrieve_response_code($response);
        if ($status < 200 || $status >= 300) {
            $error = 'HTTP ' . $status;
            return false;
        }
        $body = wp_remote_retrieve_body($response);
    } else {
        $context = stream_context_create(array('http' => array(
            'timeout' => 300, 'follow_location' => 1, 'max_redirects' => 3
        )));
        $body = @file_get_contents($url, false, $context, 0, $limit + 1);
        if ($body === false) {
            $last = error_get_last();
            $error = $last ? $last['message'] : 'HTTP download failed';
            return false;
        }
    }
    if ($body === '') { $error = 'Empty response body'; return false; }
    if (strlen($body) > $limit) { $error = 'Image exceeds the 20 MiB download limit'; return false; }
    return $body;
}

// Check cached and newly converted files before publishing their dimensions/URLs.
function legacy_image_size($path, $format) {
    $types = array('jpg' => IMAGETYPE_JPEG, 'png' => IMAGETYPE_PNG, 'gif' => IMAGETYPE_GIF);
    $size = is_file($path) ? @getimagesize($path) : false;
    return $size && $size[0] > 0 && $size[1] > 0 && isset($types[$format]) && $size[2] === $types[$format] ? $size : false;
}

function legacy_convert_image($image_data, $output_path, $max_width, $format, &$error = null, $max_height = 0) {
    $error = null;
    if (!function_exists('exec')) {
        $error = 'PHP exec() is unavailable; ImageMagick cannot be started';
        return false;
    }
    $temp_path = @tempnam(sys_get_temp_dir(), 'wp_legacy_');
    if ($temp_path === false) { $error = 'Cannot create temporary source image'; return false; }
    if (@file_put_contents($temp_path, $image_data) !== strlen($image_data)) {
        @unlink($temp_path);
        $error = 'Cannot write complete temporary source image';
        return false;
    }
    $convert_binary = getenv('IMAGEMAGICK_CONVERT');
    if ($convert_binary === false || $convert_binary === '') { $convert_binary = 'convert'; }
    $converted_path = @tempnam(dirname($output_path), '.image-');
    if ($converted_path === false || dirname($converted_path) !== realpath(dirname($output_path))) {
        @unlink($temp_path);
        if ($converted_path !== false) { @unlink($converted_path); }
        $error = 'Cannot create temporary output in ' . dirname($output_path);
        return false;
    }
    $geometry = max(1, (int) $max_width) . 'x' . ($max_height > 0 ? (int)$max_height : '') . '>';
    if ($format === 'gif') {
        // Bound decoded pixels as well as download size; no dithering noise or interlace passes.
        $encoding = ' +dither -colors 32 -interlace None';
    } elseif ($format === 'jpg') {
        // Baseline JPEG remains usable by the old desktop/mobile decoders.
        $encoding = ' -colorspace sRGB -sampling-factor 2x2 -quality 78 -interlace None -define jpeg:optimize-coding=true';
    } else {
        // Screenshots and transparent graphics keep their pixels and alpha, without quantization.
        $encoding = ' -define png:compression-level=9 -define png:compression-filter=5 -interlace None';
    }
    $command = escapeshellarg($convert_binary)
        . ' ' . escapeshellarg($temp_path . '[0]')
        . ' -auto-orient -resize ' . escapeshellarg($geometry)
        . $encoding . ' -strip ' . escapeshellarg($format . ':' . $converted_path)
        . ' 2>&1';
    $command_output = array(); $exit_code = 1;
    @exec($command, $command_output, $exit_code);
    @unlink($temp_path);
    $size = legacy_image_size($converted_path, $format);
    if ($exit_code !== 0 || !$size || $size[0] > max(1, (int)$max_width) || ($max_height > 0 && $size[1] > $max_height)) {
        $error = 'ImageMagick exit ' . $exit_code . ' (' . $convert_binary . '): '
            . ($command_output ? implode(' | ', $command_output) : 'No diagnostic output');
        if (!$size) { $error .= '; missing, invalid or wrong-format output'; }
        elseif ($size[0] > max(1, (int)$max_width) || ($max_height > 0 && $size[1] > $max_height)) { $error .= '; output exceeds requested dimensions'; }
        @unlink($converted_path);
        return false;
    }
    if (!@chmod($converted_path, 0644) || !@rename($converted_path, $output_path)) {
        @unlink($converted_path);
        $error = 'Cannot set permissions or publish converted image: ' . $output_path;
        return false;
    }
    return true;
}

function legacy_collect_nodes($node_list) {
    $nodes = array();
    if ($node_list === false) {
        return $nodes;
    }

    foreach ($node_list as $node) {
        $nodes[] = $node;
    }

    return $nodes;
}

function legacy_load_fragment($html) {
    $dom = new DOMDocument('1.0', 'UTF-8');
    $previous_errors = libxml_use_internal_errors(true);
    $document = '<?xml encoding="UTF-8">'
        . '<!DOCTYPE html><html><head><meta http-equiv="Content-Type" '
        . 'content="text/html; charset=UTF-8"></head><body>'
        . '<div id="legacy-root">' . $html . '</div></body></html>';
    $loaded = $dom->loadHTML($document, LIBXML_NONET);
    libxml_clear_errors();
    libxml_use_internal_errors($previous_errors);

    if (!$loaded) {
        throw new RuntimeException('Cannot parse article HTML.');
    }

    $xpath = new DOMXPath($dom);
    $root = $xpath->query('//*[@id="legacy-root"]')->item(0);
    if (!$root) {
        throw new RuntimeException('Cannot create article DOM root.');
    }

    return array($dom, $xpath, $root);
}

function legacy_remove_nodes($xpath, $root, $query) {
    $nodes = legacy_collect_nodes($xpath->query($query, $root));
    foreach ($nodes as $node) {
        if ($node->parentNode) {
            $node->parentNode->removeChild($node);
        }
    }
}

function legacy_move_children($source, $destination) {
    while ($source->firstChild) {
        $destination->appendChild($source->firstChild);
    }
}

function legacy_replace_element($dom, $node, $tag_name) {
    $replacement = $dom->createElement($tag_name);
    legacy_move_children($node, $replacement);
    $node->parentNode->replaceChild($replacement, $node);
    return $replacement;
}

function legacy_transform_inline_semantics($dom, $xpath, $root) {
    $mapping = array('strong' => 'b', 'em' => 'i');
    foreach ($mapping as $source => $target) {
        $nodes = legacy_collect_nodes($xpath->query('.//' . $source, $root));
        foreach ($nodes as $node) {
            if ($node->parentNode) {
                legacy_replace_element($dom, $node, $target);
            }
        }
    }
}

function legacy_create_caption_font($dom, $text, $song_font) {
    $small = $dom->createElement('small');
    $small->appendChild($dom->createTextNode($text));
    return $small;
}

function legacy_normalize_caption_text($text) {
    $normalized = preg_replace('/\s+/u', ' ', trim($text));
    return $normalized === null ? trim($text) : $normalized;
}

function legacy_caption_belongs_to_figure($caption, $figure) {
    return legacy_nearest_ancestor($caption, 'figure') === $figure;
}

function legacy_figure_contains_image_unit($xpath, $figure) {
    return $xpath->query('.//center[.//img]', $figure)->length > 0;
}

function legacy_transform_figures($dom, $xpath, $root, $song_font) {
    $figures = legacy_collect_nodes($xpath->query('.//figure', $root));
    for ($figure_index = count($figures) - 1; $figure_index >= 0; $figure_index--) {
        $figure = $figures[$figure_index];
        if (!$figure->parentNode) {
            continue;
        }

        $caption_parts = array();
        $captions = legacy_collect_nodes($xpath->query('.//figcaption', $figure));
        foreach ($captions as $caption) {
            if (!legacy_caption_belongs_to_figure($caption, $figure)) {
                continue;
            }
            $text = legacy_normalize_caption_text($caption->textContent);
            if ($text !== '') {
                $caption_parts[] = $text;
            }
            if ($caption->parentNode) {
                $caption->parentNode->removeChild($caption);
            }
        }

        if (legacy_figure_contains_image_unit($xpath, $figure)) {
            if (!empty($caption_parts)) {
                $caption = $dom->createElement('center');
                $caption->appendChild(
                    legacy_create_caption_font(
                        $dom,
                        implode(' ', $caption_parts),
                        $song_font
                    )
                );
                $figure->appendChild($caption);
            }
            while ($figure->firstChild) {
                $figure->parentNode->insertBefore($figure->firstChild, $figure);
            }
            $figure->parentNode->removeChild($figure);
            continue;
        }

        $center = $dom->createElement('center');
        legacy_move_children($figure, $center);
        if (!empty($caption_parts)) {
            $center->appendChild($dom->createElement('br'));
            $center->appendChild(
                legacy_create_caption_font($dom, implode(' ', $caption_parts), $song_font)
            );
        }

        $figure->parentNode->replaceChild($center, $figure);
    }

    $captions = legacy_collect_nodes($xpath->query('.//figcaption', $root));
    foreach ($captions as $caption) {
        if (!$caption->parentNode) {
            continue;
        }
        $center = $dom->createElement('center');
        $center->appendChild(
            legacy_create_caption_font(
                $dom,
                legacy_normalize_caption_text($caption->textContent),
                $song_font
            )
        );
        $caption->parentNode->replaceChild($center, $caption);
    }
}

function legacy_transform_headings($dom, $xpath, $root, $song_font, $black_font) {
    foreach (legacy_collect_nodes($xpath->query('.//h1', $root)) as $heading) {
        legacy_replace_element($dom, $heading, 'h2');
    }
}

function legacy_nearest_ancestor($node, $tag_name) {
    $current = $node->parentNode;
    while ($current) {
        if ($current->nodeType === XML_ELEMENT_NODE
            && strtolower($current->nodeName) === $tag_name) {
            return $current;
        }
        $current = $current->parentNode;
    }

    return null;
}

function legacy_transform_tables($dom, $xpath, $root, $song_font, $black_font) {
    $tables = legacy_collect_nodes($xpath->query('.//table', $root));
    foreach ($tables as $table) {
        $table->setAttribute('width', '100%');
        $table->setAttribute('border', '1');
        $table->setAttribute('cellspacing', '0');
        $table->setAttribute('cellpadding', '2');

    }
}

function legacy_extract_code_node_text($node) {
    if ($node->nodeType === XML_TEXT_NODE
        || $node->nodeType === XML_CDATA_SECTION_NODE) {
        return $node->nodeValue;
    }

    if ($node->nodeType !== XML_ELEMENT_NODE) {
        return '';
    }

    $tag = strtolower($node->nodeName);
    if ($tag === 'br') {
        return "\n";
    }

    $text = '';
    foreach ($node->childNodes as $child) {
        $text .= legacy_extract_code_node_text($child);
    }

    if (in_array($tag, array('div', 'p', 'li', 'tr'), true)
        && $text !== '' && substr($text, -1) !== "\n") {
        $text .= "\n";
    }

    return $text;
}

function legacy_extract_code_text($block) {
    $text = '';
    foreach ($block->childNodes as $child) {
        $text .= legacy_extract_code_node_text($child);
    }
    return $text;
}

function legacy_normalize_code_text($code) {
    $code = str_replace(
        array("\r\n", "\r", "\xC2\x85", "\xE2\x80\xA8", "\xE2\x80\xA9"),
        "\n",
        $code
    );
    return str_replace("\t", '    ', $code);
}

function legacy_transform_code_blocks($dom, $xpath, $root) {
    foreach (legacy_collect_nodes($xpath->query('.//pre', $root)) as $block) {
        $text = legacy_normalize_code_text(legacy_extract_code_text($block));
        // Native PRE preserves spaces/newlines without NBSP entities or a layout table.
        $pre = $dom->createElement('pre');
        $pre->appendChild($dom->createTextNode(implode("\n", export_ie_wrap_text($text, 56))));
        $block->parentNode->replaceChild($pre, $block);
    }
    foreach (legacy_collect_nodes($xpath->query('.//code', $root)) as $code) {
        legacy_replace_element($dom, $code, 'tt');
    }
}

function legacy_localize_images($xpath, $root, $media) {
    foreach (legacy_collect_nodes($xpath->query('.//img', $root)) as $img) {
        $src = $img->getAttribute('src');
        if (empty($media[$src]['early'])) {
            $img->parentNode->replaceChild($img->ownerDocument->createTextNode($img->getAttribute('alt')), $img);
            continue;
        }
        $file = $media[$src]['early'];
        $img->setAttribute('src', $file['src']);
        $img->setAttribute('width', $file['w']);
        $img->setAttribute('height', $file['h']);
        $img->setAttribute('border', '0');
    }
}

function legacy_has_ancestor_tag($node, $tags) {
    $current = $node->parentNode;
    while ($current) {
        if ($current->nodeType === XML_ELEMENT_NODE
            && in_array(strtolower($current->nodeName), $tags, true)) {
            return true;
        }
        $current = $current->parentNode;
    }

    return false;
}

function legacy_center_standalone_images($dom, $xpath, $root) {
    $images = legacy_collect_nodes($xpath->query('.//img', $root));
    foreach ($images as $image) {
        $image->setAttribute('vspace', LEGACY_IMAGE_VSPACE);
        if (!$image->parentNode
            || legacy_has_ancestor_tag($image, array('center', 'td', 'th', 'pre'))) {
            continue;
        }

        $target = $image;
        $parent = $image->parentNode;
        if ($parent->nodeType === XML_ELEMENT_NODE
            && strtolower($parent->nodeName) === 'a'
            && trim($parent->textContent) === '') {
            $target = $parent;
            $parent = $parent->parentNode;
        }

        if ($parent && $parent->nodeType === XML_ELEMENT_NODE
            && strtolower($parent->nodeName) === 'p'
            && trim($parent->textContent) === '') {
            $parent->setAttribute('align', 'center');
            continue;
        }

        if ($target->parentNode) {
            $center = $dom->createElement('center');
            $target->parentNode->replaceChild($center, $target);
            $center->appendChild($target);
        }
    }
}

function legacy_url_is_safe($url, $allow_mailto) {
    $url = trim($url);
    if ($url === '' || preg_match('/[\x00-\x1f\x7f]/', $url)) {
        return false;
    }

    $scheme = parse_url($url, PHP_URL_SCHEME);
    if ($scheme === null || $scheme === false || $scheme === '') {
        return true;
    }

    $scheme = strtolower($scheme);
    return $scheme === 'http' || $scheme === 'https'
        || ($allow_mailto && $scheme === 'mailto');
}

function legacy_clean_attributes($element, $allowed_attributes) {
    $tag = strtolower($element->nodeName);
    $allowed = isset($allowed_attributes[$tag]) ? $allowed_attributes[$tag] : array();
    $names = array();
    foreach ($element->attributes as $attribute) {
        $names[] = $attribute->nodeName;
    }

    foreach ($names as $name) {
        $lower_name = strtolower($name);
        if (!in_array($lower_name, $allowed, true)) {
            $element->removeAttribute($name);
        }
    }

    if ($tag === 'a' && $element->hasAttribute('href')
        && !legacy_url_is_safe($element->getAttribute('href'), true)) {
        $element->removeAttribute('href');
    }
    if ($tag === 'img' && $element->hasAttribute('src')
        && !legacy_url_is_safe($element->getAttribute('src'), false)) {
        $element->removeAttribute('src');
    }
}

function legacy_sanitize_children($parent, $allowed_tags, $allowed_attributes) {
    $child = $parent->firstChild;
    while ($child) {
        $next = $child->nextSibling;

        if ($child->nodeType === XML_ELEMENT_NODE) {
            legacy_sanitize_children($child, $allowed_tags, $allowed_attributes);
            $tag = strtolower($child->nodeName);

            if (!isset($allowed_tags[$tag])) {
                while ($child->firstChild) {
                    $parent->insertBefore($child->firstChild, $child);
                }
                $parent->removeChild($child);
            } else {
                legacy_clean_attributes($child, $allowed_attributes);
            }
        } elseif ($child->nodeType !== XML_TEXT_NODE) {
            $parent->removeChild($child);
        }

        $child = $next;
    }
}

function legacy_node_has_visible_content($node) {
    foreach ($node->childNodes as $child) {
        if ($child->nodeType === XML_TEXT_NODE) {
            $text = str_replace("\xC2\xA0", '', $child->nodeValue);
            if (trim($text) !== '') {
                return true;
            }
        } elseif ($child->nodeType === XML_ELEMENT_NODE) {
            $tag = strtolower($child->nodeName);
            if (in_array($tag, array('img', 'table', 'hr'), true)
                || legacy_node_has_visible_content($child)) {
                return true;
            }
        }
    }

    return false;
}

function legacy_is_spacing_br($node, $root) {
    if (!$node || $node->nodeType !== XML_ELEMENT_NODE
        || strtolower($node->nodeName) !== 'br') {
        return false;
    }

    return !legacy_has_ancestor_tag($node, array('table')) && $node !== $root;
}

function legacy_nearest_content_sibling($node, $direction) {
    $sibling = $direction < 0 ? $node->previousSibling : $node->nextSibling;
    while ($sibling && $sibling->nodeType === XML_TEXT_NODE
        && trim(str_replace("\xC2\xA0", '', $sibling->nodeValue)) === '') {
        $sibling = $direction < 0 ? $sibling->previousSibling : $sibling->nextSibling;
    }
    return $sibling;
}

function legacy_is_block_element($node) {
    return $node && $node->nodeType === XML_ELEMENT_NODE
        && in_array(strtolower($node->nodeName), array(
            'p', 'center', 'table', 'ul', 'ol', 'dl', 'blockquote', 'hr'
        ), true);
}

function legacy_cleanup_spacing($xpath, $root) {
    $empty_containers = legacy_collect_nodes(
        $xpath->query('.//p | .//center | .//blockquote | .//li | .//dt | .//dd', $root)
    );
    for ($index = count($empty_containers) - 1; $index >= 0; $index--) {
        $container = $empty_containers[$index];
        if ($container->parentNode && !legacy_has_ancestor_tag($container, array('table'))
            && !legacy_node_has_visible_content($container)) {
            $container->parentNode->removeChild($container);
        }
    }

    $breaks = legacy_collect_nodes($xpath->query('.//br', $root));
    foreach ($breaks as $break) {
        if (!$break->parentNode || !legacy_is_spacing_br($break, $root)) {
            continue;
        }

        $previous = legacy_nearest_content_sibling($break, -1);
        $next = legacy_nearest_content_sibling($break, 1);
        if ($previous === null || $next === null
            || legacy_is_spacing_br($previous, $root)
            || legacy_is_block_element($previous)
            || legacy_is_block_element($next)) {
            $break->parentNode->removeChild($break);
        }
    }
}

function legacy_serialize_children($dom, $root) {
    $html = '';
    foreach ($root->childNodes as $child) {
        $html .= $dom->saveHTML($child);
    }
    return $html;
}

function legacy_compact_html($html) {
    // Whitespace inside PRE is source code, not markup padding.
    $parts = preg_split('~(<pre\b[^>]*>.*?</pre>)~is', $html, -1, PREG_SPLIT_DELIM_CAPTURE);
    foreach ($parts as $i => $part) {
        if ($i % 2 === 0) { $parts[$i] = preg_replace('/[ \t\r\n]+/', ' ', $part); }
    }
    return implode('', $parts);
}

function legacy_transform_content($content, $media, $song_font, $black_font) {
    $fragment = legacy_load_fragment($content);
    $dom = $fragment[0];
    $xpath = $fragment[1];
    $root = $fragment[2];

    legacy_remove_nodes(
        $xpath,
        $root,
        './/iframe | .//video | .//audio | .//script | .//style'
        . ' | .//object | .//embed | .//form | .//input | .//button'
        . ' | .//select | .//textarea'
    );
    legacy_transform_inline_semantics($dom, $xpath, $root);
    legacy_transform_figures($dom, $xpath, $root, $song_font);
    legacy_transform_headings($dom, $xpath, $root, $song_font, $black_font);
    legacy_transform_tables($dom, $xpath, $root, $song_font, $black_font);
    legacy_transform_code_blocks($dom, $xpath, $root);
    legacy_localize_images($xpath, $root, $media);
    legacy_center_standalone_images($dom, $xpath, $root);

    $allowed_tags = array_fill_keys(array(
        'p', 'center', 'small', 'pre', 'h2', 'h3', 'h4', 'h5', 'h6', 'img', 'br', 'table', 'tr', 'td', 'th',
        'a', 'b', 'i', 'u', 'tt', 'ul', 'ol', 'li', 'dl', 'dt',
        'dd', 'blockquote', 'hr'
    ), true);
    $allowed_attributes = array(
        'p' => array('align'),
        'img' => array('src', 'alt', 'width', 'height', 'border', 'vspace'),
        'a' => array('href', 'name', 'title'),
        'table' => array(
            'width', 'border', 'cellspacing', 'cellpadding', 'bordercolor',
            'align', 'bgcolor'
        ),
        'tr' => array('align', 'valign', 'bgcolor'),
        'td' => array('colspan', 'rowspan', 'align', 'valign', 'width', 'bgcolor'),
        'th' => array('colspan', 'rowspan', 'align', 'valign', 'width', 'bgcolor'),
        'ul' => array('type', 'compact'),
        'ol' => array('type', 'start', 'compact'),
        'li' => array('type', 'value'),
        'hr' => array('width', 'size', 'align', 'noshade')
    );
    legacy_sanitize_children($root, $allowed_tags, $allowed_attributes);
    legacy_cleanup_spacing($xpath, $root);

    return legacy_serialize_children($dom, $root);
}

function legacy_post_thumbnail_url($post_id) {
    if (function_exists('get_the_post_thumbnail_url')) {
        return get_the_post_thumbnail_url($post_id, 'full');
    }

    if (function_exists('get_post_thumbnail_id') && function_exists('wp_get_attachment_image_src')) {
        $attachment_id = get_post_thumbnail_id($post_id);
        $image = wp_get_attachment_image_src($attachment_id, 'full');
        return $image ? $image[0] : false;
    }

    return false;
}

function legacy_index_filename($page_index) {
    return $page_index === 0 ? 'index.html' : 'index' . ($page_index + 1) . '.html';
}

function legacy_cleanup_stale_output($output_dir, $images_dir, $generated_files) {
    $html_files = glob(legacy_join_path($output_dir, '*.html'));
    if ($html_files !== false) {
        foreach ($html_files as $path) {
            $name = basename($path);
            $generated_name = preg_match('/^index(?:[2-9]|[1-9][0-9]+)?\.html$/', $name)
                || preg_match('/^post_[0-9]+\.html$/', $name);
            if ($generated_name && !isset($generated_files[legacy_path_key($path)])) {
                if (!unlink($path)) { throw new RuntimeException("Cannot remove obsolete root page: $path"); }
            }
        }
    }

    $image_files = glob(legacy_join_path($images_dir, '*.gif'));
    if ($image_files !== false) {
        foreach ($image_files as $path) {
            $name = basename($path);
            $generated_name = preg_match(
                '/^post(?:[0-9]+_img[0-9]+|[0-9]+_thumb|[0-9]+_[0-9]+)\.gif$/',
                $name
            );
            if ($generated_name && !isset($generated_files[legacy_path_key($path)])) {
                @unlink($path);
            }
        }
    }
}

function legacy_cleanup_old_intermediate_files($base_dir) {
    $article_files = glob(legacy_join_path($base_dir, 'articles_page*.txt'));
    if ($article_files !== false) {
        foreach ($article_files as $path) {
            @unlink($path);
        }
    }
    @unlink(legacy_join_path($base_dir, 'temp.jpg'));
}

function legacy_print_usage() {
    echo "Usage: php generate.php [options]\n"
        . "  --wordpress-root=PATH  Directory containing wp-load.php\n"
        . "  --output=PATH          Output directory (default: legacy)\n"
        . "  --per-page=NUMBER      Posts per index page (default: 5)\n"
        . "  --config=PATH          JSON colors, language and Early encoding\n"
        . "  --help                 Show this help\n";
}

function legacy_main() {
    $options = getopt('', array(
        'wordpress-root:', 'output:', 'per-page:', 'config:', 'help'
    ));
    if ($options === false) {
        $options = array();
    }
    if (isset($options['help'])) {
        legacy_print_usage();
        return 0;
    }

    if (!function_exists('iconv')) {
        throw new RuntimeException('The PHP iconv extension is required.');
    }
    if (!class_exists('DOMDocument')) {
        throw new RuntimeException('The PHP DOM extension is required.');
    }

    export_load_config(isset($options['config']) ? legacy_resolve_path($options['config'], getcwd()) : null);
    $base_dir = dirname(__FILE__);
    $wp_load = legacy_find_wp_load($base_dir, $options);
    $output_dir = isset($options['output'])
        ? legacy_resolve_path($options['output'], $base_dir)
        : legacy_join_path($base_dir, 'legacy');
    $per_page = isset($options['per-page']) ? (int) $options['per-page'] : 5;
    if ($per_page < 1 || $per_page > 100) {
        throw new RuntimeException('--per-page must be between 1 and 100.');
    }
    require_once $wp_load;
    if (!function_exists('get_posts') || !function_exists('wpautop')) {
        throw new RuntimeException('WordPress did not load correctly.');
    }

    $posts = get_posts(array(
        'numberposts' => -1,
        'post_status' => 'publish',
        'post_type' => 'post',
        'orderby' => array('date' => 'DESC', 'ID' => 'DESC'),
        'order' => 'DESC'
    ));

    export_publish_site($posts, $output_dir, $per_page);
    legacy_cleanup_old_intermediate_files($base_dir);
    return 0;
}

require_once __DIR__ . '/exporter/render.php';
require_once __DIR__ . '/exporter/publish.php';

try {
    exit(legacy_main());
} catch (Exception $exception) {
    fwrite(STDERR, 'Build failed: ' . $exception->getMessage() . "\n");
    exit(1);
}
