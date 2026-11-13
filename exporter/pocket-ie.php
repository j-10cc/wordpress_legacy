<?php

// Shared Pocket markup: distinguish source newlines from the no-script fallback breaks.
function export_ie_wrap_text($text, $columns) {
    $lines = array(); $line = ''; $used = 0;
    preg_match_all('/\X/u', $text, $matches);
    foreach ($matches[0] as $char) {
        if ($char === "\n") { $lines[] = $line; $line = ''; $used = 0; continue; }
        $width = preg_match('/^[\x20-\x7e]$/D', $char) ? 1 : 2;
        if ($used && $used + $width > $columns) { $lines[] = $line; $line = ''; $used = 0; }
        $line .= $char; $used += $width;
    }
    $lines[] = $line;
    return $lines;
}

function export_ie_code($text, $columns = 20) {
    $lines = array();
    // Visible BRs remain usable without JavaScript. The client removes only code-wrap BRs
    // when recovering the source, then reflows against each code element's measured width.
    foreach (explode("\n", $text) as $source_line) {
        $parts = array();
        foreach (export_ie_wrap_text($source_line, $columns) as $line) {
            $parts[] = preg_replace_callback('/(^ +)|( {2,})/', function ($m) {
                $length = strlen($m[0]);
                return !empty($m[1]) ? str_repeat('&nbsp;', $length) : str_repeat('&nbsp;', $length - 1) . ' ';
            }, legacy_escape($line));
        }
        $lines[] = implode('<br class="code-wrap">', $parts);
    }
    return '<div class="code"><code>' . implode('<br>', $lines) . '</code></div>';
}

function export_ie_wrap_cell($parent, $columns, &$used) {
    $dom = $parent->ownerDocument;
    foreach (iterator_to_array($parent->childNodes) as $node) {
        if ($node->nodeType === XML_TEXT_NODE) {
            $text = preg_replace('/\s+/u', ' ', str_replace("\xC2\xA0", ' ', $node->nodeValue));
            preg_match_all('/\X/u', $text, $matches);
            $fragment = $dom->createDocumentFragment(); $part = '';
            foreach ($matches[0] as $char) {
                $width = preg_match('/^[\x20-\x7e]$/D', $char) ? 1 : 2;
                if ($used && $used + $width > $columns) {
                    $fragment->appendChild($dom->createTextNode($part));
                    $br = $fragment->appendChild($dom->createElement('br'));
                    $br->setAttribute('class', 'ie-wrap');
                    $part = ''; $used = 0;
                }
                $part .= $char; $used += $width;
            }
            $fragment->appendChild($dom->createTextNode($part));
            $parent->replaceChild($fragment, $node);
        } elseif ($node->nodeType === XML_ELEMENT_NODE) {
            $tag = strtolower($node->nodeName);
            $block = in_array($tag, array('br','hr','p','div','ul','ol','li','dl','dt','dd','blockquote','table'), true);
            if ($block) { $used = 0; }
            if ($node->getAttribute('class') === 'code') {
                // Recover the source before generating the narrower no-script fallback.
                foreach (iterator_to_array($node->getElementsByTagName('br')) as $br) {
                    if ($br->getAttribute('class') === 'code-wrap') { $br->parentNode->removeChild($br); }
                }
                $text = str_replace("\xC2\xA0", ' ', legacy_extract_code_text($node));
                list($code_dom, $code_xpath, $code_root) = legacy_load_fragment(export_ie_code($text, $columns));
                $parent->replaceChild($dom->importNode($code_root->firstChild, true), $node);
            } elseif ($tag !== 'table') {
                // Nested tables have already been formatted separately.
                export_ie_wrap_cell($node, $columns, $used);
            }
            if ($block) { $used = 0; }
        }
    }
}

function export_ie_table($html) {
    list($dom, $xpath, $root) = legacy_load_fragment($html);
    $table = $xpath->query('./table', $root)->item(0);
    $rows = $xpath->query('./tr|./thead/tr|./tbody/tr|./tfoot/tr', $table);
    $occupied = array(); $cells = array(); $columns = 1; $group = null; $group_end = 0;
    foreach ($rows as $r => $row) {
        // Rowspans cannot continue into a different thead/tbody/tfoot group.
        if ($group === null || !$group->isSameNode($row->parentNode)) {
            $occupied = array(); $group = $row->parentNode; $group_end = $r + 1;
            while ($group_end < $rows->length && $group->isSameNode($rows->item($group_end)->parentNode)) { $group_end++; }
        }
        $col = 0;
        foreach ($xpath->query('./th|./td', $row) as $cell) {
            $span = max(1, min(100, (int)$cell->getAttribute('colspan')));
            // Find a contiguous free interval, including columns occupied by earlier rowspans.
            do {
                $free = true;
                for ($i = $col; $i < $col + $span; $i++) {
                    if (isset($occupied[$i]) && $occupied[$i] > $r) { $col = $i + 1; $free = false; break; }
                }
            } while (!$free);
            $remaining = $group_end - $r;
            $height = $cell->getAttribute('rowspan') === '0' ? $remaining : min($remaining, max(1, (int)$cell->getAttribute('rowspan')));
            // Old table engines do not implement rowspan=0 consistently.
            if ($cell->hasAttribute('rowspan')) { $cell->setAttribute('rowspan', $height); }
            if ($cell->hasAttribute('colspan')) { $cell->setAttribute('colspan', $span); }
            for ($i = $col; $i < $col + $span; $i++) { $occupied[$i] = $r + $height; }
            $cells[] = array($cell, $span, $col); $col += $span;
            $columns = max($columns, $col);
        }
    }
    // Every row uses the same logical grid, independent of its text lengths.
    // COL widths drive fixed-layout engines; cell WIDTH attributes also reach
    // older mobile engines which ignore COL/CSS. Rounded boundaries total 100%.
    $boundaries = array();
    for ($i = 0; $i <= $columns; $i++) { $boundaries[] = (int)round(10000 * $i / $columns); }
    $colgroup = $dom->createElement('colgroup');
    for ($i = 0; $i < $columns; $i++) {
        $col = $colgroup->appendChild($dom->createElement('col'));
        $width = (($boundaries[$i + 1] - $boundaries[$i]) / 100) . '%';
        $col->setAttribute('width', $width);
        $col->setAttribute('style', 'width:' . $width);
    }
    // CAPTION precedes COLGROUP in the table content model.
    $before = $table->firstChild;
    while ($before && ($before->nodeType !== XML_ELEMENT_NODE || strtolower($before->nodeName) === 'caption')) { $before = $before->nextSibling; }
    $table->insertBefore($colgroup, $before);
    foreach ($cells as $entry) {
        list($cell, $span, $start) = $entry;
        $width = (($boundaries[$start + $span] - $boundaries[$start]) / 100) . '%';
        $cell->setAttribute('width', $width);
        $cell->setAttribute('style', 'width:' . $width);
        $used = 0;
        export_ie_wrap_cell($cell, max(2, (int)floor(24 * $span / $columns) - 2), $used);
    }
    return $dom->saveHTML($table);
}
