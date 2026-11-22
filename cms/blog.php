<?php
/**
 * Serves /blog/ with posts written on the site added to the built page.
 *
 * The built page (blog/index.html) stays exactly as `node tools/build.mjs` made it. This script
 * only slots extra cards into the markers the build leaves behind, so a post published from /admin
 * appears in its category straight away. If anything goes wrong, the built page is served untouched.
 */
require_once __DIR__ . '/lib/bootstrap.php';

$file  = Store::root() . '/blog/index.html';
$built = @file_get_contents($file);

header('Content-Type: text/html; charset=utf-8');
header('Cache-Control: public, max-age=0, must-revalidate');

if ($built === false) {
    http_response_code(500);
    echo '<!doctype html><title>Guides</title><p>The guides page is missing.</p>';
    exit;
}

/** A category section in the same shape as the build's, for categories with no built guides yet. */
function cmsSection(string $category, string $cards): string
{
    $slug  = Store::categorySlug($category);
    $blurb = '';
    foreach (Store::categories() as $c) {
        if (($c['name'] ?? '') === $category) $blurb = (string) ($c['blurb'] ?? '');
    }

    return '<section class="guide-group" id="' . Render::esc($slug) . '" aria-labelledby="' . Render::esc($slug) . '-title">'
        . '<div class="container">'
        . '<div class="guide-group-head"><h2 id="' . Render::esc($slug) . '-title">' . Render::esc($category) . '</h2>'
        . ($blurb !== '' ? '<p>' . Render::esc($blurb) . '</p>' : '') . '</div>'
        . '<div class="card-grid">' . $cards . '</div>'
        . '</div></section>';
}

$html = $built;
try {
    $posts = Store::all();
    if ($posts) {
        $byCategory = [];
        foreach ($posts as $post) {
            $byCategory[(string) ($post['category'] ?? '')][] = $post;
        }

        foreach ($byCategory as $category => $items) {
            $cards  = implode('', array_map([Render::class, 'card'], $items));
            $slug   = Store::categorySlug($category);
            $marker = '<!--cms:cards:' . $slug . '-->';

            if (str_contains($html, $marker)) {
                $html = str_replace($marker, $cards, $html);
            } else {
                $html = str_replace('<!--cms:sections-->', cmsSection($category, $cards) . "\n<!--cms:sections-->", $html);
            }

            $html = preg_replace_callback(
                '/(data-cms-count="' . preg_quote($slug, '/') . '">)(\d+)(<)/',
                static fn(array $m): string => $m[1] . ((int) $m[2] + count($items)) . $m[3],
                $html
            ) ?? $html;
        }

        $html = preg_replace_callback(
            '/(data-cms-total>)(\d+)( free guides)/',
            static fn(array $m): string => $m[1] . ((int) $m[2] + count($posts)) . $m[3],
            $html
        ) ?? $html;
    }
} catch (Throwable $e) {
    error_log('cms/blog.php: ' . $e->getMessage());
    $html = $built;
}

echo $html;
