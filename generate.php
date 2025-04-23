<?php
require('wp-load.php');

function extract_and_convert_images($content, $post_id, &$image_counter) {
    preg_match_all('/<img[^>]+src=[\"\']([^\\"\']+)[\"\']/i', $content, $matches);
    $converted_images = [];

    foreach ($matches[1] as $img_src) {
        $img_ext = pathinfo(parse_url($img_src, PHP_URL_PATH), PATHINFO_EXTENSION);
        $gif_name = "post{$post_id}_img{$image_counter}.gif";
        $output_path = "legacy/images/$gif_name";

        $image_data = @file_get_contents($img_src);
        if ($image_data !== false) {
            file_put_contents("temp.$img_ext", $image_data);
            exec("convert temp.$img_ext -resize 500 -colors 64 $output_path");
            unlink("temp.$img_ext");
            $converted_images[$img_src] = $gif_name;
            $image_counter++;
        }
    }

    return $converted_images;
}

$posts = get_posts([
    'numberposts' => -1,
    'post_status' => 'publish'
]);

$per_page = 5;
$total_pages = ceil(count($posts) / $per_page);

for ($page = 0; $page < $total_pages; $page++) {
    ob_start();
    $start = $page * $per_page;
    $slice = array_slice($posts, $start, $per_page);
    $index = 0;

    foreach ($slice as $post) {
        $title = iconv("UTF-8", "GB2312//IGNORE", $post->post_title);
        $summary = iconv("UTF-8", "GB2312//IGNORE", wp_trim_words(strip_tags($post->post_content), 30));
        $img_url = get_the_post_thumbnail_url($post->ID, 'medium');
        $gif_name = "post{$page}_{$index}.gif";
        $link = "post_" . $post->ID . ".html";

        echo "<a href=\"$link\"><b>$title</b></a><br>\n";
        if ($img_url) {
            file_put_contents("temp.jpg", file_get_contents($img_url));
            exec("convert temp.jpg -resize 300 -colors 64 legacy/images/$gif_name");
            echo "<img src=\"images/$gif_name\" width=\"300\" alt=\"图像\"><br>\n";
        }
        echo "$summary<br><br>\n";
       
        $content = $post->post_content;

        $content = preg_replace('/(<br[^>]*>)/i', '<br>', $content); 
        $content = preg_replace('/(<br>\s*){2,}/', '<br>', $content);  
        $content = preg_replace('/\s+/', ' ', $content);
        

        // remove all <iframe> <video> <audio> <script> <style> tags
        $content = preg_replace([
            '/<iframe[^>]*>.*?<\/iframe>/is',
            '/<video[^>]*>.*?<\/video>/is',
            '/<audio[^>]*>.*?<\/audio>/is',
            '/<script[^>]*>.*?<\/script>/is',
            '/<style[^>]*>.*?<\/style>/is'
        ], '', $content);

        // process <figcaption> tags
        $content = preg_replace_callback(
            '/<figcaption[^>]*>(.*?)<\/figcaption>/is',
            function ($matches) {
                $text = strip_tags($matches[1]);
                return "\n<font face=\"黑体\" color=\"#808080\" size=\"2\"><i>$text</i></font>\n";
            },
            $content
        );

        // remove <span> tags
        $content = preg_replace('/<span[^>]*>.*?<\/span>/is', '', $content);

        $image_counter = 0;
        $converted_images = extract_and_convert_images($content, $post->ID, $image_counter);
        
        $patterns = [];
        $replacements = [];
        // replace all <img> tags src
        foreach ($converted_images as $original => $converted) {
            $escaped = preg_quote($original, '/');
            $patterns[] = '/<img([^>]+?)src\s*=\s*(["\'])' . $escaped . '\2/i';
            $replacements[] = '<img$1src="images/' . $converted . '"';
        }
        $content = preg_replace($patterns, $replacements, $content);

        $content = wpautop($content);
        $content = strip_tags($content, "<p><img><br><table><tr><td><th><a><b><i><u>");

        $content = iconv("UTF-8", "GB2312//IGNORE", $content);

        $post_html = "<!DOCTYPE html PUBLIC \"-//W3C//DTD HTML 3.2 Final//EN\">
<html>
<head><meta http-equiv=\"Content-Type\" content=\"text/html; charset=GB2312\">
<title>$title</title></head>
<body bgcolor=\"#FFFFFF\" text=\"#000000\">
<center><table width=\"600\"><tr><td>
<font face=\"宋体\" size=\"5\"><b>$title</b></font><br><br>
<font face=\"宋体\" size=\"3\"><style>table,td,th{font-family: 宋体; font-size: 12pt;}</style>$content</font>
<a href=\"index.html\">返回首页</a>
</td></tr></table></center></body></html>";

        file_put_contents("legacy/post_" . $post->ID . ".html", $post_html);
        $index++;
    }

    file_put_contents("articles_page{$page}.txt", ob_get_clean());
}
