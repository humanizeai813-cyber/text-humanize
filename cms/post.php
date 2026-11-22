<?php
/**
 * Serves a post written on the site at /blog/<slug>.
 *
 * Apache only sends a request here when no built guide of that name exists, so the 14 static
 * guides always win and nothing that already worked can be shadowed by a post.
 */
require_once __DIR__ . '/lib/bootstrap.php';

$post  = Store::find((string) ($_GET['slug'] ?? ''));
$draft = $post !== null && ($post['status'] ?? 'draft') !== 'published';

if ($draft) {
    // Drafts are visible only to the signed-in owner, for previewing.
    Auth::start();
    if (!Auth::check()) $post = null;
}

$html = $post ? Render::post($post) : '';
if ($html === '') {
    http_response_code(404);
    header('Content-Type: text/html; charset=utf-8');
    $notFound = Store::root() . '/404.html';
    echo is_file($notFound) ? file_get_contents($notFound) : '<!doctype html><title>Page not found</title><p>Page not found.</p>';
    exit;
}

header('Content-Type: text/html; charset=utf-8');
if ($draft) {
    header('Cache-Control: no-store');
    header('X-Robots-Tag: noindex, nofollow');
} else {
    header('Cache-Control: public, max-age=0, must-revalidate');
}
echo $html;
