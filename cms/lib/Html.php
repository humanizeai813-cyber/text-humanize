<?php
/**
 * Turns whatever the editor sends into a small, safe subset of HTML.
 *
 * Everything that isn't on the allow list is removed. Formatting tags keep their text, dangerous
 * elements and attributes are dropped outright, and links and images are limited to addresses that
 * can't run code. Nothing from the editor is ever written to a page without passing through here.
 */
class Html
{
    /** Tag => attributes that may stay. */
    private const ALLOWED = [
        'p' => [], 'h2' => [], 'h3' => [], 'h4' => [],
        'ul' => [], 'ol' => [], 'li' => [],
        'blockquote' => [], 'figure' => [], 'figcaption' => [],
        'strong' => [], 'em' => [], 'code' => [], 'br' => [], 'hr' => [],
        'a' => ['href', 'title', 'rel'],
        'img' => ['src', 'alt', 'width', 'height', 'loading', 'decoding'],
    ];
    private const RENAME  = ['b' => 'strong', 'i' => 'em', 'h1' => 'h2', 'h5' => 'h4', 'h6' => 'h4'];
    private const UNWRAP  = ['div', 'span', 'font', 'section', 'article', 'main', 'header', 'footer', 'u', 's', 'strike', 'small', 'big', 'center', 'tt', 'sub', 'sup', 'label', 'body', 'html'];
    private const DROP    = ['script', 'style', 'iframe', 'object', 'embed', 'form', 'input', 'select', 'textarea', 'button', 'svg', 'math', 'noscript', 'link', 'meta', 'head', 'title', 'video', 'audio', 'canvas'];
    private const BLOCKS  = ['p', 'h2', 'h3', 'h4', 'ul', 'ol', 'blockquote', 'figure', 'hr'];

    public static function clean(string $html): string
    {
        $html = trim($html);
        if ($html === '') return '';

        $doc = new DOMDocument();
        $previous = libxml_use_internal_errors(true);
        $doc->loadHTML('<?xml encoding="utf-8"?><body><div id="th-root">' . $html . '</div></body>', LIBXML_NOERROR | LIBXML_NOWARNING);
        libxml_clear_errors();
        libxml_use_internal_errors($previous);

        // getElementById works for HTML documents, but fall back to the wrapper itself just in case.
        $rootNode = $doc->getElementById('th-root') ?? $doc->getElementsByTagName('div')->item(0);
        if (!$rootNode instanceof DOMElement) return '';

        self::cleanChildren($rootNode);
        self::wrapLooseText($rootNode);

        $out = '';
        foreach ($rootNode->childNodes as $child) {
            $out .= $doc->saveHTML($child);
        }

        $out = preg_replace('/<p>(\s|&nbsp;|<br\s*\/?>)*<\/p>/i', '', $out) ?? $out;   // empty paragraphs
        $out = preg_replace('/\s*\n\s*/', "\n", trim($out)) ?? $out;

        return $out;
    }

    private static function cleanChildren(DOMNode $parent): void
    {
        foreach (iterator_to_array($parent->childNodes) as $node) {
            if ($node instanceof DOMText) continue;
            if ($node instanceof DOMComment) { $parent->removeChild($node); continue; }
            if (!($node instanceof DOMElement)) { $parent->removeChild($node); continue; }

            $tag = strtolower($node->nodeName);
            if (in_array($tag, self::DROP, true)) { $parent->removeChild($node); continue; }

            if (isset(self::RENAME[$tag])) {
                $node = self::rename($node, self::RENAME[$tag]);
                $tag  = strtolower($node->nodeName);
            }
            if (!isset(self::ALLOWED[$tag]) || in_array($tag, self::UNWRAP, true)) {
                self::cleanChildren($node);
                self::unwrap($node);
                continue;
            }

            self::cleanAttributes($node, $tag);
            self::cleanChildren($node);
        }
    }

    private static function cleanAttributes(DOMElement $node, string $tag): void
    {
        foreach (iterator_to_array($node->attributes) as $attr) {
            $name = strtolower($attr->nodeName);
            if (!in_array($name, self::ALLOWED[$tag], true)) {
                $node->removeAttribute($attr->nodeName);
            }
        }

        if ($tag === 'a') {
            $href = self::safeHref($node->getAttribute('href'));
            if ($href === null) { self::unwrap($node); return; }
            $node->setAttribute('href', $href);
            if (preg_match('#^https?://#i', $href)) {
                $node->setAttribute('rel', 'noopener');
            } else {
                $node->removeAttribute('rel');
            }
        }

        if ($tag === 'img') {
            $src = self::safeSrc($node->getAttribute('src'));
            if ($src === null || $node->parentNode === null) { $node->parentNode?->removeChild($node); return; }
            $node->setAttribute('src', $src);
            $node->setAttribute('alt', trim($node->getAttribute('alt')));
            foreach (['width', 'height'] as $size) {
                $value = $node->getAttribute($size);
                if ($value === '' || !ctype_digit($value)) $node->removeAttribute($size);
            }
            $node->setAttribute('loading', 'lazy');
            $node->setAttribute('decoding', 'async');
        }
    }

    /** Links may only point at this site, another https page, an anchor or an email address. */
    private static function safeHref(string $href): ?string
    {
        $href = trim(preg_replace('/[\x00-\x20]/', '', $href) ?? '');
        if ($href === '') return null;
        if (preg_match('#^(https?://[^\s"<>]+|/[^\s"<>]*|\#[A-Za-z0-9_-]+|mailto:[^\s"<>]+)$#i', $href)) return $href;

        return null;
    }

    /** Images may only come from this site's own folders or another https address. */
    private static function safeSrc(string $src): ?string
    {
        $src = trim(preg_replace('/[\x00-\x20]/', '', $src) ?? '');
        if (preg_match('#^(/images/[A-Za-z0-9._/-]+|https://[^\s"<>]+)$#', $src)) return $src;

        return null;
    }

    private static function rename(DOMElement $node, string $tag): DOMElement
    {
        $replacement = $node->ownerDocument->createElement($tag);
        while ($node->firstChild) {
            $replacement->appendChild($node->firstChild);
        }
        $node->parentNode->replaceChild($replacement, $node);

        return $replacement;
    }

    /** Remove a tag but keep what was inside it. */
    private static function unwrap(DOMElement $node): void
    {
        $parent = $node->parentNode;
        if (!$parent) return;

        while ($node->firstChild) {
            $parent->insertBefore($node->firstChild, $node);
        }
        $parent->removeChild($node);
    }

    /** Text that ended up outside a block (pasted plain text, for instance) becomes a paragraph. */
    private static function wrapLooseText(DOMElement $root): void
    {
        $doc = $root->ownerDocument;
        $paragraph = null;
        foreach (iterator_to_array($root->childNodes) as $node) {
            $isBlock = $node instanceof DOMElement && in_array(strtolower($node->nodeName), self::BLOCKS, true);
            if ($isBlock) { $paragraph = null; continue; }
            if ($node instanceof DOMText && trim($node->textContent) === '') { $root->removeChild($node); continue; }

            if ($paragraph === null) {
                $paragraph = $doc->createElement('p');
                $root->insertBefore($paragraph, $node);
            }
            $paragraph->appendChild($node);
        }
    }

    public static function toText(string $html): string
    {
        $text = preg_replace('/<[^>]+>/', ' ', $html) ?? $html;
        $text = html_entity_decode($text, ENT_QUOTES | ENT_HTML5, 'UTF-8');

        return trim(preg_replace('/\s+/u', ' ', $text) ?? $text);
    }

    public static function wordCount(string $html): int
    {
        $text = self::toText($html);

        return $text === '' ? 0 : count(preg_split('/\s+/u', $text) ?: []);
    }

    /** Matches the build: at least 3 minutes, otherwise words / 230. */
    public static function readingMinutes(string $html): int
    {
        return max(3, (int) round(self::wordCount($html) / 230));
    }
}
