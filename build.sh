#!/bin/bash

mkdir -p legacy/images
rm -f articles_page*.txt temp.jpg

# generate all post page
php generate.php

# render all page
for i in {0..9}; do
    if [ -f "articles_page${i}.txt" ]; then
        page=index.html
        [ $i -gt 0 ] && page="index$((i+1)).html"

        PAGINATION=""
        [ -f "articles_page$((i+1)).txt" ] && PAGINATION="<a href=\"index$((i+2)).html\">下一页</a>"
        [ $i -gt 0 ] && PAGINATION="<a href=\"index$((i)).html\">上一页</a> | $PAGINATION"

        sed "/<!-- Begin Articles -->/,/<!-- End Articles -->/{
            /<!-- Begin Articles -->/r articles_page${i}.txt
            /<!-- Begin Articles -->/,/<!-- End Articles -->/d
        };/<!-- Begin Pagination -->/,/<!-- End Pagination -->/c\
        $PAGINATION" template.html > legacy/$page
    fi
done

echo "Build OK!"
