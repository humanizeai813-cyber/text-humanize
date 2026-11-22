<?php
/**
 * The writing area at /admin: sign in, list posts, write one, add pictures, publish.
 *
 * Nothing here is reachable without the password set on first use. Everything that changes
 * something is a POST carrying a one-time token, and every piece of text from the editor goes
 * through Html::clean() before it is stored or shown.
 */
require_once __DIR__ . '/lib/bootstrap.php';

Auth::start();
header('X-Robots-Tag: noindex, nofollow');
header('Cache-Control: no-store');

$action = (string) ($_POST['action'] ?? $_GET['action'] ?? '');
$isPost = ($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST';
$error  = null;

// ── First use: choose a password ────────────────────────────────────────────
if (!Auth::isSetUp()) {
    if ($isPost && $action === 'setup') {
        $error = Auth::setPassword((string) ($_POST['password'] ?? ''), (string) ($_POST['password2'] ?? ''));
        if ($error === null) redirect('/admin');
    }
    echo layout('Set a password', setupView($error));
    exit;
}

// ── Sign in ─────────────────────────────────────────────────────────────────
if (!Auth::check()) {
    if ($isPost && $action === 'login') {
        $error = Auth::attempt((string) ($_POST['password'] ?? ''));
        if ($error === null) redirect('/admin');
    }
    echo layout('Sign in', loginView($error));
    exit;
}

// ── Signed in ───────────────────────────────────────────────────────────────
if ($isPost && !Auth::verifyCsrf($_POST['csrf'] ?? null)) {
    http_response_code(400);
    echo layout('Session expired', '<div class="alert alert-warn"><p>That form had expired. Please go back and try again.</p></div><p><a class="btn btn-primary" href="/admin">Back to posts</a></p>');
    exit;
}

if ($action === 'logout') {
    Auth::logout();
    redirect('/admin');
}

if ($isPost && $action === 'upload') {
    header('Content-Type: application/json');
    echo json_encode(Images::store($_FILES['image'] ?? [], (string) ($_POST['slug'] ?? 'post')));
    exit;
}

if ($isPost && $action === 'delete') {
    Store::delete((string) ($_POST['slug'] ?? ''));
    redirect('/admin?deleted=1');
}

if ($isPost && $action === 'save') {
    [$slug, $error] = savePost();
    if ($error === null) redirect('/admin?edit=' . urlencode($slug) . '&saved=1');
}

$editing = null;
if (isset($_GET['edit'])) {
    $editing = Store::find((string) $_GET['edit']) ?? [];
} elseif ($isPost && $error !== null) {
    $editing = postFromForm();   // keep what was typed when saving failed
}

echo $editing !== null
    ? layout($editing ? 'Edit post' : 'New post', editorView($editing, $error))
    : layout('Posts', listView());

// ── Actions ─────────────────────────────────────────────────────────────────

/** @return array{0: string, 1: ?string} the slug that was saved, or an error to show */
function savePost(): array
{
    $form     = postFromForm();
    $existing = $form['currentSlug'] !== '' ? Store::find($form['currentSlug']) : null;

    if ($form['h1'] === '')                      return ['', 'Give the post a title.'];
    if (Html::toText($form['body']) === '')      return ['', 'The post has no text yet.'];

    $slug = Store::uniqueSlug($form['slug'] !== '' ? $form['slug'] : $form['h1'], (string) ($existing['slug'] ?? ''));
    $post = [
        'slug'        => $slug,
        'title'       => mb_substr($form['title'] !== '' ? $form['title'] : $form['h1'] . ' | ' . Store::site('name'), 0, 120),
        'h1'          => mb_substr($form['h1'], 0, 140),
        'short'       => mb_substr($form['short'] !== '' ? $form['short'] : $form['h1'], 0, 60),
        'description' => mb_substr($form['description'] !== '' ? $form['description'] : firstWords($form['body'], 155), 0, 300),
        'excerpt'     => mb_substr($form['excerpt'] !== '' ? $form['excerpt'] : firstWords($form['body'], 140), 0, 300),
        'category'    => $form['category'],
        'body'        => $form['body'],
        'status'      => $form['status'],
        'date'        => (string) ($existing['date'] ?? gmdate('Y-m-d')),
        'updated'     => gmdate('Y-m-d'),
        'minutes'     => Html::readingMinutes($form['body']),
        'createdAt'   => (string) ($existing['createdAt'] ?? gmdate('c')),
        'updatedAt'   => gmdate('c'),
    ];

    if (!Store::save($post)) {
        return ['', 'The post could not be saved. Check that the storage folder on the server is writable.'];
    }
    if ($existing && $existing['slug'] !== $slug) {
        Store::delete($existing['slug']);   // the address changed, so drop the old file
    }

    return [$slug, null];
}

function postFromForm(): array
{
    $categories = array_column(Store::categories(), 'name');
    $category   = (string) ($_POST['category'] ?? '');

    return [
        'currentSlug' => trim((string) ($_POST['current_slug'] ?? '')),
        'slug'        => trim((string) ($_POST['slug'] ?? '')),
        'h1'          => trim((string) ($_POST['h1'] ?? '')),
        'title'       => trim((string) ($_POST['title'] ?? '')),
        'short'       => trim((string) ($_POST['short'] ?? '')),
        'description' => trim((string) ($_POST['description'] ?? '')),
        'excerpt'     => trim((string) ($_POST['excerpt'] ?? '')),
        'category'    => in_array($category, $categories, true) ? $category : (string) ($categories[0] ?? ''),
        'body'        => Html::clean((string) ($_POST['body'] ?? '')),
        'status'      => ($_POST['status'] ?? 'draft') === 'published' ? 'published' : 'draft',
    ];
}

function firstWords(string $html, int $limit): string
{
    $text = Html::toText($html);
    if (mb_strlen($text) <= $limit) return $text;

    $cut = mb_substr($text, 0, $limit);
    $lastSpace = mb_strrpos($cut, ' ');

    return rtrim($lastSpace ? mb_substr($cut, 0, $lastSpace) : $cut, " ,.;:") . '…';
}

function redirect(string $to): void
{
    header('Location: ' . $to);
    exit;
}

// ── Views ───────────────────────────────────────────────────────────────────

function layout(string $title, string $body): string
{
    $css = '/css/site.css?v=' . (int) @filemtime(Store::root() . '/css/site.css');
    $e   = static fn($v) => Render::esc($v);

    return '<!doctype html>
<html lang="en">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<meta name="robots" content="noindex, nofollow">
<title>' . $e($title) . ' · ' . $e(Store::site('name', 'Site')) . ' admin</title>
<link rel="icon" href="/favicon.svg" type="image/svg+xml">
<link rel="stylesheet" href="' . $e($css) . '">
<style>
  body { background: var(--bg-soft); }
  .admin-bar { display: flex; flex-wrap: wrap; align-items: center; gap: 12px; padding: 14px 20px; background: #fff; border-bottom: 1px solid var(--line); }
  .admin-bar .brand { margin-right: auto; }
  .admin-main { max-width: 1040px; margin: 0 auto; padding: 24px 20px 64px; }
  .admin-narrow { max-width: 460px; }
  .panel { padding: 20px; margin-bottom: 20px; background: #fff; border: 1px solid var(--line); border-radius: var(--r-card, 20px); box-shadow: var(--shadow-sm); }
  .panel h1, .panel h2 { margin-top: 0; }
  .field { margin-bottom: 16px; }
  .field label { display: block; margin-bottom: 6px; font-size: 0.875rem; font-weight: 600; color: var(--ink-2); }
  .field .hint { margin: 6px 0 0; font-size: 0.8125rem; color: var(--muted); }
  input[type="text"], input[type="password"], textarea, select {
    width: 100%; padding: 10px 12px; border: 1px solid var(--line-strong); border-radius: var(--r-input, 12px);
    font: inherit; color: var(--ink); background: #fff;
  }
  textarea { min-height: 76px; resize: vertical; }
  .grid-2 { display: grid; gap: 0 18px; grid-template-columns: repeat(auto-fit, minmax(240px, 1fr)); }
  .toolbar { display: flex; flex-wrap: wrap; gap: 6px; padding: 8px; border: 1px solid var(--line-strong); border-bottom: 0; border-radius: var(--r-input, 12px) var(--r-input, 12px) 0 0; background: #fbfcff; }
  .toolbar button { height: 32px; min-width: 34px; padding: 0 10px; border: 1px solid transparent; border-radius: 8px; background: #fff; font: inherit; font-weight: 600; color: var(--ink-2); cursor: pointer; }
  .toolbar button:hover { border-color: var(--brand); color: var(--brand-ink); }
  .editor { min-height: 420px; padding: 18px; border: 1px solid var(--line-strong); border-radius: 0 0 var(--r-input, 12px) var(--r-input, 12px); background: #fff; overflow-wrap: anywhere; }
  .editor:focus-visible { box-shadow: var(--focus); outline: none; }
  .editor h2 { font-size: 1.375rem; margin: 1.4em 0 .5em; }
  .editor h3 { font-size: 1.125rem; margin: 1.2em 0 .4em; }
  .editor p { margin: 0 0 1em; }
  .editor img { max-width: 100%; height: auto; border-radius: 12px; }
  .editor blockquote { margin: 0 0 1em; padding-left: 14px; border-left: 3px solid var(--brand-soft-2); color: var(--ink-2); }
  .editor:empty::before { content: "Write the post here."; color: var(--muted); }
  .post-row { display: flex; flex-wrap: wrap; align-items: center; gap: 10px 14px; padding: 14px 0; border-top: 1px solid var(--line); }
  .post-row:first-of-type { border-top: 0; }
  .post-row .post-title { flex: 1 1 260px; font-weight: 600; }
  .post-row .actions { display: flex; gap: 8px; margin-left: auto; }
  .row-actions { display: flex; flex-wrap: wrap; gap: 10px; align-items: center; }
  .muted-line { font-size: 0.8125rem; color: var(--muted); }
  .count-hint { font-variant-numeric: tabular-nums; }
</style>
</head>
<body>
<div class="admin-bar">
  <a class="brand" href="/admin"><span class="brand-mark" aria-hidden="true">T</span><span>' . $e(Store::site('name', 'Site')) . ' admin</span></a>
  <a class="btn btn-secondary btn-sm" href="/blog/" target="_blank" rel="noopener">View guides</a>' .
  (Auth::check() ? '
  <form method="post" action="/admin"><input type="hidden" name="csrf" value="' . $e(Auth::csrf()) . '"><input type="hidden" name="action" value="logout"><button class="btn btn-ghost btn-sm" type="submit">Sign out</button></form>' : '') . '
</div>
<main class="admin-main">
' . $body . '
</main>
</body>
</html>';
}

function setupView(?string $error): string
{
    return '<div class="panel admin-narrow">
  <h1>Set your password</h1>
  <p>This is the first time anyone has opened the writing area. Choose a password to protect it. Only you will know it: the site stores a scrambled version that can\'t be turned back into the password.</p>
  ' . alertHtml($error) . '
  <form method="post" action="/admin">
    <input type="hidden" name="action" value="setup">
    <div class="field">
      <label for="password">Password</label>
      <input type="password" id="password" name="password" autocomplete="new-password" minlength="12" required autofocus>
      <p class="hint">At least 12 characters. Use something you don\'t use anywhere else, and save it in your password manager.</p>
    </div>
    <div class="field">
      <label for="password2">Type it again</label>
      <input type="password" id="password2" name="password2" autocomplete="new-password" minlength="12" required>
    </div>
    <button class="btn btn-primary" type="submit">Save password and start</button>
  </form>
</div>';
}

function loginView(?string $error): string
{
    return '<div class="panel admin-narrow">
  <h1>Sign in</h1>
  ' . alertHtml($error) . '
  <form method="post" action="/admin">
    <input type="hidden" name="action" value="login">
    <div class="field">
      <label for="password">Password</label>
      <input type="password" id="password" name="password" autocomplete="current-password" required autofocus>
    </div>
    <button class="btn btn-primary" type="submit">Sign in</button>
  </form>
</div>';
}

function listView(): string
{
    $e     = static fn($v) => Render::esc($v);
    $posts = Store::all(false);
    $rows  = '';

    foreach ($posts as $post) {
        $published = ($post['status'] ?? 'draft') === 'published';
        $rows .= '<div class="post-row">
      <span class="post-title">' . $e($post['h1'] ?? $post['slug']) . '</span>
      <span class="tag ' . ($published ? 'tint-mint' : 'tint-amber') . '">' . ($published ? 'Published' : 'Draft') . '</span>
      <span class="muted-line">' . $e(Render::dateText((string) ($post['updated'] ?? $post['date'] ?? ''))) . '</span>
      <span class="actions">
        <a class="btn btn-secondary btn-sm" href="/admin?edit=' . urlencode((string) $post['slug']) . '">Edit</a>
        <a class="btn btn-ghost btn-sm" href="/blog/' . urlencode((string) $post['slug']) . '" target="_blank" rel="noopener">' . ($published ? 'View' : 'Preview') . '</a>
      </span>
    </div>';
    }

    $notice = isset($_GET['deleted']) ? '<div class="alert alert-info"><p>That post has been deleted.</p></div>' : '';

    return $notice . '<div class="panel">
  <div class="row-actions" style="margin-bottom: 8px">
    <h1 style="margin: 0; margin-right: auto">Your posts</h1>
    <a class="btn btn-primary" href="/admin?edit=">Write a new post</a>
  </div>
  <p class="muted-line">Posts written here are stored on the server, so uploading the site from your computer never removes them. The 14 original guides are built from your computer and aren\'t edited here.</p>
</div>
<div class="panel">' . ($rows !== ''
        ? $rows
        : '<p>No posts yet. <a href="/admin?edit=">Write your first one</a>.</p>') . '</div>';
}

function editorView(array $post, ?string $error): string
{
    $e         = static fn($v) => Render::esc($v);
    $isNew     = empty($post['slug']);
    $published = ($post['status'] ?? 'draft') === 'published';
    $slug      = (string) ($post['slug'] ?? '');
    $saved     = isset($_GET['saved']);

    $categoryOptions = '';
    foreach (Store::categories() as $category) {
        $name = (string) ($category['name'] ?? '');
        $categoryOptions .= '<option value="' . $e($name) . '"' . (($post['category'] ?? '') === $name ? ' selected' : '') . '>' . $e($name) . '</option>';
    }

    $notice = '';
    if ($saved) {
        $link = '/blog/' . urlencode($slug);
        $notice = '<div class="alert alert-info"><p>Saved. ' . ($published
            ? 'It is live at <a href="' . $e($link) . '" target="_blank" rel="noopener">' . $e($link) . '</a>.'
            : 'It is still a draft, so only you can see it. <a href="' . $e($link) . '" target="_blank" rel="noopener">Preview it</a>.') . '</p></div>';
    }

    return $notice . alertHtml($error) . warningsHtml($post) . '<form method="post" action="/admin" id="post-form">
<input type="hidden" name="csrf" value="' . $e(Auth::csrf()) . '">
<input type="hidden" name="action" value="save">
<input type="hidden" name="current_slug" value="' . $e($slug) . '">
<input type="hidden" name="body" id="body-html">

<div class="panel">
  <div class="field">
    <label for="h1">Title</label>
    <input type="text" id="h1" name="h1" value="' . $e($post['h1'] ?? '') . '" maxlength="140" required autofocus>
  </div>
  <div class="toolbar" role="toolbar" aria-label="Formatting">
    <button type="button" data-cmd="bold" title="Bold"><strong>B</strong></button>
    <button type="button" data-cmd="italic" title="Italic"><em>I</em></button>
    <button type="button" data-cmd="formatBlock" data-value="h2" title="Section heading">H2</button>
    <button type="button" data-cmd="formatBlock" data-value="h3" title="Smaller heading">H3</button>
    <button type="button" data-cmd="formatBlock" data-value="p" title="Normal text">Text</button>
    <button type="button" data-cmd="insertUnorderedList" title="Bullet list">• List</button>
    <button type="button" data-cmd="insertOrderedList" title="Numbered list">1. List</button>
    <button type="button" data-cmd="formatBlock" data-value="blockquote" title="Quote">&ldquo; Quote</button>
    <button type="button" data-cmd="createLink" title="Add a link">Link</button>
    <button type="button" data-cmd="unlink" title="Remove link">Unlink</button>
    <button type="button" data-cmd="image" title="Add a picture">Picture</button>
    <button type="button" data-cmd="removeFormat" title="Clear formatting">Clear</button>
  </div>
  <div class="editor prose" id="body-editor" contenteditable="true" role="textbox" aria-multiline="true" aria-label="Post text">' . ($post['body'] ?? '') . '</div>
  <p class="hint"><span class="count-hint" id="word-count">0 words</span> · <span id="editor-status">Pictures are shrunk automatically and saved with the site.</span></p>
  <input type="file" id="image-input" accept="image/jpeg,image/png,image/webp" hidden>
</div>

<div class="panel">
  <h2>Search and sharing</h2>
  <div class="grid-2">
    <div class="field">
      <label for="category">Category</label>
      <select id="category" name="category">' . $categoryOptions . '</select>
      <p class="hint">Decides which section of the guides page it appears in.</p>
    </div>
    <div class="field">
      <label for="slug">Web address</label>
      <input type="text" id="slug" name="slug" value="' . $e($slug) . '" maxlength="80" pattern="[a-z0-9-]*">
      <p class="hint">Leave blank to build it from the title. Changing it later breaks old links.</p>
    </div>
  </div>
  <div class="field">
    <label for="description">Search description</label>
    <textarea id="description" name="description" maxlength="300">' . $e($post['description'] ?? '') . '</textarea>
    <p class="hint">Shown under the title in Google. <span class="count-hint" id="description-count">0</span> characters, aim for 120 to 160. Leave blank to use the opening lines.</p>
  </div>
  <div class="field">
    <label for="excerpt">Card summary</label>
    <textarea id="excerpt" name="excerpt" maxlength="300">' . $e($post['excerpt'] ?? '') . '</textarea>
    <p class="hint">One sentence shown on the guides page card.</p>
  </div>
  <div class="grid-2">
    <div class="field">
      <label for="title">Browser tab title</label>
      <input type="text" id="title" name="title" value="' . $e($post['title'] ?? '') . '" maxlength="120">
      <p class="hint">Leave blank to use the title plus the site name.</p>
    </div>
    <div class="field">
      <label for="short">Short name</label>
      <input type="text" id="short" name="short" value="' . $e($post['short'] ?? '') . '" maxlength="60">
      <p class="hint">Used in the breadcrumb trail above the post.</p>
    </div>
  </div>
</div>

<div class="panel">
  <div class="row-actions">
    <button class="btn btn-primary" type="submit" name="status" value="published">' . ($published ? 'Save changes' : 'Publish') . '</button>
    <button class="btn btn-secondary" type="submit" name="status" value="draft">' . ($published ? 'Unpublish' : 'Save draft') . '</button>
    <a class="btn btn-ghost" href="/admin">Back to posts</a>' . ($isNew ? '' : '
    <button class="btn btn-ghost" type="submit" form="delete-form" style="margin-left:auto; color: var(--danger)">Delete</button>') . '
  </div>
</div>
</form>' . ($isNew ? '' : '
<form method="post" action="/admin" id="delete-form" onsubmit="return confirm(\'Delete this post? This cannot be undone.\')">
  <input type="hidden" name="csrf" value="' . $e(Auth::csrf()) . '">
  <input type="hidden" name="action" value="delete">
  <input type="hidden" name="slug" value="' . $e($slug) . '">
</form>') . '
<script>
(function () {
  var editor = document.getElementById("body-editor");
  var hidden = document.getElementById("body-html");
  var status = document.getElementById("editor-status");
  var CSRF = ' . json_encode(Auth::csrf()) . ';
  try { document.execCommand("defaultParagraphSeparator", false, "p"); } catch (e) {}

  function sync() {
    hidden.value = editor.innerHTML;
    var words = editor.innerText.trim() ? editor.innerText.trim().split(/\s+/).length : 0;
    document.getElementById("word-count").textContent = words + (words === 1 ? " word" : " words");
  }
  function exec(command, value) {
    editor.focus();
    document.execCommand(command, false, value || null);
    sync();
  }

  Array.prototype.forEach.call(document.querySelectorAll("[data-cmd]"), function (button) {
    button.addEventListener("click", function () {
      var command = button.getAttribute("data-cmd");
      if (command === "createLink") {
        var url = window.prompt("Link address, for example https://example.com or /about");
        if (url) exec("createLink", url.trim());
      } else if (command === "image") {
        document.getElementById("image-input").click();
      } else {
        exec(command, button.getAttribute("data-value"));
      }
    });
  });

  // Counting words reads the editor\'s layout, so while typing do it at most every 300 ms.
  var pendingSync = null;
  editor.addEventListener("input", function () {
    clearTimeout(pendingSync);
    pendingSync = setTimeout(sync, 300);
  });
  editor.addEventListener("blur", sync);
  document.getElementById("post-form").addEventListener("submit", sync);

  // Paste as plain text, so styling from Word or a web page cannot come with it.
  editor.addEventListener("paste", function (event) {
    event.preventDefault();
    var text = (event.clipboardData || window.clipboardData).getData("text/plain");
    var html = text.split(/\n{2,}/).map(function (block) {
      var safe = block.replace(/&/g, "&amp;").replace(/</g, "&lt;").replace(/>/g, "&gt;").replace(/\n/g, "<br>");
      return "<p>" + safe + "</p>";
    }).join("");
    document.execCommand("insertHTML", false, html);
    sync();
  });

  // Double-click a picture to describe it (its alt text, read by screen readers and search engines).
  editor.addEventListener("dblclick", function (event) {
    if (event.target.tagName !== "IMG") return;
    var alt = window.prompt("Describe this picture in a few words, for screen readers and search engines:", event.target.getAttribute("alt") || "");
    if (alt !== null) {
      event.target.setAttribute("alt", alt.trim());
      sync();
    }
  });

  document.getElementById("image-input").addEventListener("change", function () {
    var file = this.files && this.files[0];
    this.value = "";
    if (!file) return;

    var data = new FormData();
    data.append("image", file);
    data.append("csrf", CSRF);
    data.append("action", "upload");
    data.append("slug", document.getElementById("slug").value || document.getElementById("h1").value || "post");
    status.textContent = "Adding the picture…";

    fetch("/admin", { method: "POST", body: data, credentials: "same-origin" })
      .then(function (response) { return response.json(); })
      .then(function (result) {
        if (!result || result.error) { status.textContent = (result && result.error) || "The picture could not be added."; return; }
        var alt = window.prompt("Describe the picture in a few words, for screen readers and search engines:", "") || "";
        exec("insertHTML", \'<img src="\' + result.url + \'" alt="\' + alt.replace(/"/g, "&quot;") + \'" width="\' + result.width + \'" height="\' + result.height + \'">\');
        status.textContent = "Picture added.";
      })
      .catch(function () { status.textContent = "The picture could not be added. Please try again."; });
  });

  var description = document.getElementById("description");
  var descriptionCount = document.getElementById("description-count");
  function countDescription() { descriptionCount.textContent = description.value.length; }
  description.addEventListener("input", countDescription);

  countDescription();
  sync();
})();
</script>';
}

/** Search basics worth fixing before publishing. Shown in the editor; never blocks saving. */
function seoWarnings(array $post): array
{
    if (empty($post['slug'])) return [];

    $warnings = [];
    $title = (string) ($post['title'] ?? '');
    if (mb_strlen($title) > 65) {
        $warnings[] = 'The browser tab title is ' . mb_strlen($title) . ' characters and Google shows about 60. Set a shorter "Browser tab title" below.';
    }
    $length = mb_strlen((string) ($post['description'] ?? ''));
    if ($length < 70 || $length > 165) {
        $warnings[] = 'The search description is ' . $length . ' characters. Aim for 120 to 160 so Google can show all of it.';
    }

    $body  = (string) ($post['body'] ?? '');
    $noAlt = preg_match_all('/<img\b(?![^>]*\balt="[^"]*[^"\s][^"]*")[^>]*>/i', $body);
    if ($noAlt) {
        $warnings[] = $noAlt . ($noAlt === 1 ? ' picture has' : ' pictures have') . ' no description (alt text). Double-click a picture in the editor to add one.';
    }

    $previous = 1;   // the page title is the H1
    foreach (preg_match_all('/<h([2-4])\b/i', $body, $m) ? $m[1] : [] as $level) {
        if ((int) $level > $previous + 1) {
            $warnings[] = 'A heading skips a level, such as an H3 with no H2 above it. Use H2 for sections and H3 for parts of a section.';
            break;
        }
        $previous = (int) $level;
    }

    return $warnings;
}

function warningsHtml(array $post): string
{
    $warnings = seoWarnings($post);
    if (!$warnings) return '';

    $items = implode('', array_map(static fn(string $w): string => '<li>' . Render::esc($w) . '</li>', $warnings));
    return '<div class="alert alert-warn"><p><strong>Worth fixing before you publish:</strong></p><ul style="margin:0; padding-left:18px">' . $items . '</ul></div>';
}

function alertHtml(?string $error): string
{
    return $error === null ? '' : '<div class="alert alert-error" role="alert"><p>' . Render::esc($error) . '</p></div>';
}
