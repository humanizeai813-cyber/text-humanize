<?php
/** Serves /blog/feed.xml: the built feed for the guides, plus posts written on the site. */
require_once __DIR__ . '/lib/bootstrap.php';

$built = @file_get_contents(Store::root() . '/blog/feed.xml');

header('Content-Type: application/rss+xml; charset=utf-8');
header('Cache-Control: public, max-age=3600');

if ($built === false) {
    http_response_code(500);
    exit;
}

$base  = Store::site('url');
$items = '';
foreach (Store::all() as $post) {
    $url = $base . '/blog/' . rawurlencode((string) $post['slug']);
    $items .= "  <item>\n"
        . '    <title>' . Render::esc($post['h1'] ?? '') . "</title>\n"
        . '    <link>' . Render::esc($url) . "</link>\n"
        . '    <guid isPermaLink="true">' . Render::esc($url) . "</guid>\n"
        . '    <pubDate>' . Render::rssDate((string) ($post['date'] ?? gmdate('Y-m-d'))) . "</pubDate>\n"
        . '    <category>' . Render::esc($post['category'] ?? '') . "</category>\n"
        . '    <description>' . Render::esc($post['description'] ?? '') . "</description>\n"
        . "  </item>\n";
}

echo $items === '' ? $built : str_replace('</channel>', $items . '</channel>', $built);
