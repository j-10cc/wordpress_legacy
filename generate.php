<?php

// Source encoding: GBK. Generated HTML encoding: GB2312.

if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    exit("This exporter must be run from the command line.\n");
}

define('LEGACY_CONTENT_WIDTH', '600');
define('LEGACY_BODY_LINE_HEIGHT', '135%');
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

function legacy_read_gbk_file($path) {
    $data = @file_get_contents($path);
    if ($data === false) {
        throw new RuntimeException("Cannot read template: $path");
    }

    $converted = iconv('GBK', 'UTF-8//IGNORE', $data);
    if ($converted === false) {
        throw new RuntimeException("Cannot decode template as GBK: $path");
    }

    return $converted;
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
    $dash_marker = '__WP_LEGACY_GB2312_DASH__';
    $utf8_html = str_replace("\xE2\x80\x94", $dash_marker, $utf8_html);
    $encoded = iconv('UTF-8', 'GB2312//IGNORE', $utf8_html);
    if ($encoded === false) {
        throw new RuntimeException("Cannot encode output as GB2312: $path");
    }
    $encoded = str_replace($dash_marker, "\xA1\xAA", $encoded);

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

function legacy_download_image_data($url) {
    $url = legacy_normalize_image_url($url);
    if ($url === false) {
        return false;
    }

    if (function_exists('wp_safe_remote_get')) {
        $response = wp_safe_remote_get($url, array(
            'timeout' => 20,
            'redirection' => 3,
            'limit_response_size' => 20 * 1024 * 1024
        ));

        if (is_wp_error($response)) {
            return false;
        }

        $status = (int) wp_remote_retrieve_response_code($response);
        $body = wp_remote_retrieve_body($response);
        return ($status >= 200 && $status < 300 && $body !== '') ? $body : false;
    }

    $context = stream_context_create(array(
        'http' => array(
            'timeout' => 20,
            'follow_location' => 1,
            'max_redirects' => 3
        )
    ));
    $body = @file_get_contents($url, false, $context);

    return ($body !== false && $body !== '') ? $body : false;
}

function legacy_convert_image_to_gif($image_data, $output_path, $max_width) {
    if (!function_exists('exec')) {
        return false;
    }

    $temp_path = tempnam(sys_get_temp_dir(), 'wp_legacy_');
    if ($temp_path === false) {
        return false;
    }

    if (@file_put_contents($temp_path, $image_data) === false) {
        @unlink($temp_path);
        return false;
    }

    if (is_file($output_path)) {
        @unlink($output_path);
    }

    $convert_binary = getenv('IMAGEMAGICK_CONVERT');
    if ($convert_binary === false || $convert_binary === '') {
        $convert_binary = 'convert';
    }

    $geometry = max(1, (int) $max_width) . 'x>';
    $command = escapeshellarg($convert_binary)
        . ' ' . escapeshellarg($temp_path)
        . ' -resize ' . escapeshellarg($geometry)
        . ' -colors 64 -strip ' . escapeshellarg($output_path)
        . ' 2>&1';
    $command_output = array();
    $exit_code = 1;
    @exec($command, $command_output, $exit_code);
    @unlink($temp_path);

    $output_size = is_file($output_path) ? @filesize($output_path) : 0;
    if ($exit_code !== 0 || $output_size === false || $output_size <= 0) {
        @unlink($output_path);
        return false;
    }

    return true;
}

function legacy_set_image_dimensions($element, $path) {
    $dimensions = @getimagesize($path);
    if ($dimensions === false) {
        return;
    }

    $element->setAttribute('width', (string) $dimensions[0]);
    $element->setAttribute('height', (string) $dimensions[1]);
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
    $font = $dom->createElement('font');
    $font->setAttribute('face', $song_font);
    $font->setAttribute('size', '2');
    $font->setAttribute('color', '#666666');
    $font->appendChild($dom->createTextNode($text));
    return $font;
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
    $query = './/h1 | .//h2 | .//h3 | .//h4 | .//h5 | .//h6';
    $headings = legacy_collect_nodes($xpath->query($query, $root));
    $sizes = array(1 => '5', 2 => '4', 3 => '3', 4 => '3', 5 => '2', 6 => '2');

    foreach ($headings as $heading) {
        if (!$heading->parentNode) {
            continue;
        }

        $level = (int) substr(strtolower($heading->nodeName), 1);
        $paragraph = $dom->createElement('p');
        $font = $dom->createElement('font');
        $font->setAttribute('face', $level <= 3 ? $black_font : $song_font);
        $font->setAttribute('size', $sizes[$level]);
        $bold = $dom->createElement('b');

        if ($level === 6) {
            $underline = $dom->createElement('u');
            legacy_move_children($heading, $underline);
            $bold->appendChild($underline);
        } else {
            legacy_move_children($heading, $bold);
        }

        $font->appendChild($bold);
        $paragraph->appendChild($font);
        $heading->parentNode->replaceChild($paragraph, $heading);
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
        $table->setAttribute('width', LEGACY_CONTENT_WIDTH);
        $table->setAttribute('border', '1');
        $table->setAttribute('cellspacing', '0');
        $table->setAttribute('cellpadding', '3');
        $table->setAttribute('bordercolor', '#000000');

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

function legacy_append_code_line($dom, $parent, $line) {
    $parts = preg_split('/( +)/', $line, -1, PREG_SPLIT_DELIM_CAPTURE);
    if ($parts === false) {
        $parent->appendChild($dom->createTextNode($line));
        return;
    }

    $at_line_start = true;
    foreach ($parts as $part) {
        if ($part === '') {
            continue;
        }

        if (preg_match('/^ +$/D', $part)) {
            $space_count = strlen($part);
            $non_breaking_count = $at_line_start ? $space_count : max(0, $space_count - 1);
            for ($index = 0; $index < $non_breaking_count; $index++) {
                $parent->appendChild($dom->createEntityReference('nbsp'));
            }
            if (!$at_line_start) {
                $parent->appendChild($dom->createTextNode(' '));
            }
            continue;
        }

        $parent->appendChild($dom->createTextNode($part));
        $at_line_start = false;
    }
}

function legacy_append_code_text($dom, $parent, $code) {
    $lines = explode("\n", legacy_normalize_code_text($code));
    $last_index = count($lines) - 1;

    foreach ($lines as $index => $line) {
        legacy_append_code_line($dom, $parent, $line);
        if ($index < $last_index) {
            $parent->appendChild($dom->createElement('br'));
        }
    }
}

function legacy_transform_code_blocks($dom, $xpath, $root) {
    $blocks = legacy_collect_nodes($xpath->query('.//pre', $root));
    foreach ($blocks as $block) {
        if (!$block->parentNode) {
            continue;
        }

        $code = legacy_extract_code_text($block);
        $table = $dom->createElement('table');
        $table->setAttribute('width', LEGACY_CONTENT_WIDTH);
        $table->setAttribute('border', '1');
        $table->setAttribute('cellspacing', '0');
        $table->setAttribute('cellpadding', '4');
        $table->setAttribute('bordercolor', '#808080');
        $table->setAttribute('bgcolor', '#F5F5F5');

        $row = $dom->createElement('tr');
        $cell = $dom->createElement('td');
        $cell->setAttribute('valign', 'top');
        $teletype = $dom->createElement('tt');
        $font = $dom->createElement('font');
        $font->setAttribute('face', 'Courier New');
        $font->setAttribute('size', '2');
        legacy_append_code_text($dom, $font, $code);
        $teletype->appendChild($font);
        $cell->appendChild($teletype);
        $row->appendChild($cell);
        $table->appendChild($row);
        $block->parentNode->replaceChild($table, $block);
    }

    $inline_codes = legacy_collect_nodes($xpath->query('.//code', $root));
    foreach ($inline_codes as $code) {
        if (!$code->parentNode) {
            continue;
        }
        $teletype = $dom->createElement('tt');
        $font = $dom->createElement('font');
        $font->setAttribute('face', 'Courier New');
        $font->setAttribute('size', '2');
        legacy_move_children($code, $font);
        $teletype->appendChild($font);
        $code->parentNode->replaceChild($teletype, $code);
    }
}

function legacy_localize_images(
    $dom,
    $xpath,
    $root,
    $post_id,
    $images_dir,
    &$generated_files,
    &$image_warnings
) {
    $images = legacy_collect_nodes($xpath->query('.//img', $root));
    $converted = array();
    $counter = 0;

    foreach ($images as $image) {
        $source = $image->getAttribute('src');
        if ($source === '') {
            continue;
        }

        if (!array_key_exists($source, $converted)) {
            $gif_name = 'post' . (int) $post_id . '_img' . $counter . '.gif';
            $output_path = legacy_join_path($images_dir, $gif_name);
            $image_data = legacy_download_image_data($source);

            if ($image_data !== false
                && legacy_convert_image_to_gif($image_data, $output_path, 500)) {
                $converted[$source] = array($gif_name, $output_path);
                legacy_track_generated($output_path, $generated_files);
                $counter++;
            } else {
                $converted[$source] = false;
                $image_warnings++;
            }
        }

        if ($converted[$source] === false) {
            continue;
        }

        $image->setAttribute('src', 'images/' . $converted[$source][0]);
        $image->setAttribute('border', '0');
        if (!$image->hasAttribute('alt')) {
            $image->setAttribute('alt', legacy_source_to_utf8('图像'));
        }
        legacy_set_image_dimensions($image, $converted[$source][1]);
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

function legacy_add_image_block_spacing($dom, $xpath, $root) {
    $images = legacy_collect_nodes($xpath->query('.//img', $root));
    $spaced_containers = array();

    foreach ($images as $image) {
        $container = $image->parentNode;
        while ($container && $container !== $root) {
            if ($container->nodeType === XML_ELEMENT_NODE) {
                $tag = strtolower($container->nodeName);
                if ($tag === 'center'
                    || ($tag === 'p' && strtolower($container->getAttribute('align')) === 'center')) {
                    break;
                }
                if ($tag === 'td' || $tag === 'th') {
                    $container = null;
                    break;
                }
            }
            $container = $container->parentNode;
        }

        if (!$container || $container === $root) {
            continue;
        }

        $key = spl_object_hash($container);
        if (!isset($spaced_containers[$key])) {
            $container->appendChild($dom->createElement('br'));
            $spaced_containers[$key] = true;
        }
    }
}

function legacy_url_is_safe($url, $allow_mailto) {
    $url = trim($url);
    if ($url === '') {
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

function legacy_apply_body_text_style($element, $song_font) {
    $element->setAttribute(
        'style',
        'font-size: medium; line-height: ' . LEGACY_BODY_LINE_HEIGHT
        . '; font-family: ' . $song_font . ';'
    );
}

function legacy_style_body_blocks($dom, $root, $song_font) {
    $block_tags = array('p', 'center', 'table', 'ul', 'ol', 'dl', 'blockquote', 'hr');
    $styled_tags = array('p', 'center', 'ul', 'ol', 'dl', 'blockquote');
    $children = legacy_collect_nodes($root->childNodes);
    $paragraph = null;

    foreach ($children as $child) {
        if ($child->nodeType === XML_ELEMENT_NODE
            && in_array(strtolower($child->nodeName), $block_tags, true)) {
            if (in_array(strtolower($child->nodeName), $styled_tags, true)) {
                legacy_apply_body_text_style($child, $song_font);
            }
            $paragraph = null;
            continue;
        }

        if ($child->nodeType === XML_TEXT_NODE && trim($child->nodeValue) === ''
            && $paragraph === null) {
            continue;
        }

        if ($paragraph === null) {
            $paragraph = $dom->createElement('p');
            legacy_apply_body_text_style($paragraph, $song_font);
            $root->insertBefore($paragraph, $child);
        }
        $paragraph->appendChild($child);
    }
}

function legacy_style_table_cells($xpath, $root, $song_font, $black_font) {
    $cells = legacy_collect_nodes($xpath->query('.//td | .//th', $root));
    foreach ($cells as $cell) {
        $cell->setAttribute(
            'style',
            'font-size: small; font-family: '
            . (strtolower($cell->nodeName) === 'th' ? $black_font : $song_font) . ';'
        );
    }
}

function legacy_transform_content(
    $content,
    $post_id,
    $images_dir,
    &$generated_files,
    &$image_warnings,
    $song_font,
    $black_font
) {
    $content = wpautop($content);
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
    legacy_localize_images(
        $dom,
        $xpath,
        $root,
        $post_id,
        $images_dir,
        $generated_files,
        $image_warnings
    );
    legacy_center_standalone_images($dom, $xpath, $root);

    $allowed_tags = array_fill_keys(array(
        'p', 'center', 'font', 'img', 'br', 'table', 'tr', 'td', 'th',
        'a', 'b', 'i', 'u', 'tt', 'ul', 'ol', 'li', 'dl', 'dt',
        'dd', 'blockquote', 'hr'
    ), true);
    $allowed_attributes = array(
        'p' => array('align'),
        'font' => array('face', 'size', 'color'),
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
    legacy_add_image_block_spacing($dom, $xpath, $root);
    legacy_style_body_blocks($dom, $root, $song_font);
    legacy_style_table_cells($xpath, $root, $song_font, $black_font);

    return legacy_serialize_children($dom, $root);
}

function legacy_post_thumbnail_url($post_id) {
    if (function_exists('get_the_post_thumbnail_url')) {
        return get_the_post_thumbnail_url($post_id, 'medium');
    }

    if (function_exists('get_post_thumbnail_id') && function_exists('wp_get_attachment_image_src')) {
        $attachment_id = get_post_thumbnail_id($post_id);
        $image = wp_get_attachment_image_src($attachment_id, 'medium');
        return $image ? $image[0] : false;
    }

    return false;
}

function legacy_render_post_page($title, $content, $song_font, $return_label) {
    return '<!DOCTYPE html PUBLIC "-//W3C//DTD HTML 3.2 Final//EN">' . "\n"
        . "<html>\n<head>\n"
        . '<meta http-equiv="Content-Type" content="text/html; charset=GB2312">' . "\n"
        . '<title>' . $title . "</title>\n</head>\n"
        . '<body bgcolor="#FFFFFF" text="#000000" link="#0000FF" vlink="#800080">' . "\n"
        . '<center><table width="' . LEGACY_CONTENT_WIDTH . '"><tr><td>' . "\n"
        . '<font face="' . $song_font . '" size="5"><b>' . $title . '</b></font><br><br>' . "\n"
        . $content . "\n"
        . '<br><br><a href="index.html">' . $return_label . '</a>' . "\n"
        . "</td></tr></table></center>\n</body>\n</html>";
}

function legacy_replace_marker($template, $begin_marker, $end_marker, $replacement) {
    $pattern = '/' . preg_quote($begin_marker, '/') . '.*?'
        . preg_quote($end_marker, '/') . '/s';
    $count = 0;
    $result = preg_replace_callback(
        $pattern,
        function () use ($begin_marker, $end_marker, $replacement) {
            return $begin_marker . "\n" . $replacement . "\n" . $end_marker;
        },
        $template,
        1,
        $count
    );

    if ($result === null || $count !== 1) {
        throw new RuntimeException("Template marker not found: $begin_marker");
    }

    return $result;
}

function legacy_index_filename($page_index) {
    return $page_index === 0 ? 'index.html' : 'index' . ($page_index + 1) . '.html';
}

function legacy_render_index_page(
    $template,
    $article_entries,
    $page_index,
    $page_count,
    $song_font
) {
    if (empty($article_entries)) {
        $articles = legacy_source_to_utf8('暂无文章。');
    } else {
        $articles = implode("\n", $article_entries);
    }

    $articles = '<font face="' . $song_font . '" size="3">' . "\n"
        . $articles . "\n</font>";
    $pagination_parts = array();
    if ($page_index > 0) {
        $pagination_parts[] = '<a href="'
            . legacy_index_filename($page_index - 1) . '">'
            . legacy_source_to_utf8('上一页') . '</a>';
    }
    if ($page_index + 1 < $page_count) {
        $pagination_parts[] = '<a href="'
            . legacy_index_filename($page_index + 1) . '">'
            . legacy_source_to_utf8('下一页') . '</a>';
    }

    $page = legacy_replace_marker(
        $template,
        '<!-- Begin Articles -->',
        '<!-- End Articles -->',
        $articles
    );
    return legacy_replace_marker(
        $page,
        '<!-- Begin Pagination -->',
        '<!-- End Pagination -->',
        implode(' | ', $pagination_parts)
    );
}

function legacy_cleanup_stale_output($output_dir, $images_dir, $generated_files) {
    $html_files = glob(legacy_join_path($output_dir, '*.html'));
    if ($html_files !== false) {
        foreach ($html_files as $path) {
            $name = basename($path);
            $generated_name = preg_match('/^index(?:[2-9]|[1-9][0-9]+)?\.html$/', $name)
                || preg_match('/^post_[0-9]+\.html$/', $name);
            if ($generated_name && !isset($generated_files[legacy_path_key($path)])) {
                @unlink($path);
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
        . "  --template=PATH        GBK index template (default: template.html)\n"
        . "  --per-page=NUMBER      Posts per index page (default: 5)\n"
        . "  --help                 Show this help\n";
}

function legacy_main() {
    $options = getopt('', array(
        'wordpress-root:', 'output:', 'template:', 'per-page:', 'help'
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

    $base_dir = dirname(__FILE__);
    $wp_load = legacy_find_wp_load($base_dir, $options);
    $output_dir = isset($options['output'])
        ? legacy_resolve_path($options['output'], $base_dir)
        : legacy_join_path($base_dir, 'legacy');
    $template_path = isset($options['template'])
        ? legacy_resolve_path($options['template'], $base_dir)
        : legacy_join_path($base_dir, 'template.html');
    $per_page = isset($options['per-page']) ? (int) $options['per-page'] : 5;
    if ($per_page < 1 || $per_page > 100) {
        throw new RuntimeException('--per-page must be between 1 and 100.');
    }
    require_once $wp_load;
    if (!function_exists('get_posts') || !function_exists('wpautop')) {
        throw new RuntimeException('WordPress did not load correctly.');
    }

    $images_dir = legacy_join_path($output_dir, 'images');
    legacy_ensure_directory($images_dir);
    $template = legacy_read_gbk_file($template_path);
    $song_font = legacy_source_to_utf8('宋体');
    $black_font = legacy_source_to_utf8('黑体');
    $return_label = legacy_source_to_utf8('返回首页');
    $image_alt = legacy_source_to_utf8('图像');

    $posts = get_posts(array(
        'numberposts' => -1,
        'post_status' => 'publish',
        'post_type' => 'post',
        'orderby' => 'date',
        'order' => 'DESC'
    ));

    $generated_files = array();
    $article_entries = array();
    $image_warnings = 0;

    foreach ($posts as $post) {
        $post_id = (int) $post->ID;
        $title = legacy_escape(
            html_entity_decode($post->post_title, ENT_QUOTES, 'UTF-8')
        );
        $summary_source = function_exists('wp_strip_all_tags')
            ? wp_strip_all_tags($post->post_content, true)
            : strip_tags($post->post_content);
        $summary_source = html_entity_decode($summary_source, ENT_QUOTES, 'UTF-8');
        $summary = wp_trim_words($summary_source, 30);
        $summary = str_replace(
            array('&hellip;', html_entity_decode('&hellip;', ENT_QUOTES, 'UTF-8')),
            '...',
            $summary
        );

        $entry = '<a href="post_' . $post_id . '.html"><b>' . $title . '</b></a><br>' . "\n";
        $thumbnail_url = legacy_post_thumbnail_url($post_id);
        if ($thumbnail_url) {
            $thumbnail_name = 'post' . $post_id . '_thumb.gif';
            $thumbnail_path = legacy_join_path($images_dir, $thumbnail_name);
            $thumbnail_data = legacy_download_image_data($thumbnail_url);
            if ($thumbnail_data !== false
                && legacy_convert_image_to_gif($thumbnail_data, $thumbnail_path, 300)) {
                legacy_track_generated($thumbnail_path, $generated_files);
                $dimensions = @getimagesize($thumbnail_path);
                $size_attributes = '';
                if ($dimensions !== false) {
                    $size_attributes = ' width="' . $dimensions[0]
                        . '" height="' . $dimensions[1] . '"';
                }
                $entry .= '<img src="images/' . $thumbnail_name . '"'
                    . $size_attributes . ' border="0" alt="' . $image_alt
                    . '"><br>' . "\n";
            } else {
                $image_warnings++;
            }
        }
        $entry .= legacy_escape($summary) . '<br><br>' . "\n";
        $article_entries[] = $entry;

        $content = legacy_transform_content(
            $post->post_content,
            $post_id,
            $images_dir,
            $generated_files,
            $image_warnings,
            $song_font,
            $black_font
        );
        $post_page = legacy_render_post_page($title, $content, $song_font, $return_label);
        $post_path = legacy_join_path($output_dir, 'post_' . $post_id . '.html');
        legacy_write_html($post_path, $post_page);
        legacy_track_generated($post_path, $generated_files);
    }

    $page_count = max(1, (int) ceil(count($article_entries) / $per_page));
    for ($page_index = 0; $page_index < $page_count; $page_index++) {
        $entries = array_slice($article_entries, $page_index * $per_page, $per_page);
        $page = legacy_render_index_page(
            $template,
            $entries,
            $page_index,
            $page_count,
            $song_font
        );
        $page_path = legacy_join_path($output_dir, legacy_index_filename($page_index));
        legacy_write_html($page_path, $page);
        legacy_track_generated($page_path, $generated_files);
    }

    legacy_cleanup_stale_output($output_dir, $images_dir, $generated_files);
    legacy_cleanup_old_intermediate_files($base_dir);

    echo 'Build OK: ' . count($posts) . ' posts, ' . $page_count . " index pages.\n";
    if ($image_warnings > 0) {
        fwrite(STDERR, 'Warning: ' . $image_warnings . " images could not be converted.\n");
    }

    return 0;
}

try {
    exit(legacy_main());
} catch (Exception $exception) {
    fwrite(STDERR, 'Build failed: ' . $exception->getMessage() . "\n");
    exit(1);
}
