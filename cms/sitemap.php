<?php
/**
 * Serves /sitemap-posts.xml: every post written on the site.
 *
 * The built sitemap.xml covers the static pages and guides. Keeping posts in a second sitemap means
 * uploading the site from a PC never drops them, and robots.txt points search engines at both.
 */
require_once __DIR__ . '/lib/bootstrap.php';

header('Content-Type: application/xml; charset=utf-8');
header('Cache-Control: public, max-age=3600');

$base = Store::site('url');
$urls = '';
foreach (Store::all() as $post) {
    $lastmod = (string) ($post['updated'] ?? $post['date'] ?? '');
    $urls .= '  <url><loc>' . Render::esc($base . '/blog/' . rawurlencode((string) $post['slug'])) . '</loc>'
        . ($lastmod !== '' ? '<lastmod>' . Render::esc($lastmod) . '</lastmod>' : '')
        . "</url>\n";
}

echo '<?xml version="1.0" encoding="UTF-8"?>' . "\n"
    . '<urlset xmlns="http://www.sitemaps.org/schemas/sitemap/0.9">' . "\n"
    . $urls
    . '</urlset>' . "\n";
