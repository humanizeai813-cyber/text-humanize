<?php
/**
 * Builds the finished page for a post written on the site.
 *
 * The header, footer, styles, scripts and article layout all come from src/generated/post-shell.html,
 * which `node tools/build.mjs` produces from the same code that builds the 14 static guides. This
 * file only fills in the gaps, so the design is never copied and can never drift.
 */
class Render
{
    public static function post(array $post): string
    {
        $shell = Store::shell();
        if ($shell === '') return '';

        [$body, $toc] = self::headingsAndToc((string) ($post['body'] ?? ''));
        $category = (string) ($post['category'] ?? '');
        $date     = (string) ($post['date'] ?? gmdate('Y-m-d'));
        $updated  = (string) ($post['updated'] ?? $date);

        $html = strtr($shell, [
            '@@SLUG@@'         => rawurlencode((string) $post['slug']),
            '@@TITLE@@'        => self::esc($post['title'] ?? $post['h1'] ?? ''),
            '@@DESCRIPTION@@'  => self::esc($post['description'] ?? ''),
            '@@H1@@'           => self::esc($post['h1'] ?? ''),
            '@@SHORT@@'        => self::esc($post['short'] ?? $post['h1'] ?? ''),
            '@@CATEGORY@@'     => self::esc($category),
            '@@TINT@@'         => Store::tintFor($category),
            '@@DATE@@'         => self::esc($date),
            '@@UPDATED@@'      => self::esc($updated),
            '@@UPDATED_TEXT@@' => self::esc(self::dateText($updated)),
            '@@MINUTES@@'      => (string) ($post['minutes'] ?? Html::readingMinutes($body)),
            '@@TOC@@'          => $toc,
            '@@TOC_CLASS@@'    => $toc === '' ? ' no-toc' : '',
            '@@BODY@@'         => $body,
            '@@RELATED@@'      => self::relatedCards($post),
            '@@JSONLD@@'       => self::jsonLd($post, $body, $date, $updated),
        ]);

        if (($post['status'] ?? 'draft') !== 'published') {
            // A draft is only ever shown to the signed-in owner, and never to a search engine.
            $html = preg_replace('/<meta name="robots" content="[^"]*">/', '<meta name="robots" content="noindex, nofollow">', $html, 1) ?? $html;
        }

        return $html;
    }

    /** One card for the guides index, the related list and anywhere else cards are shown. */
    public static function card(array $item): string
    {
        $template = Store::cardTemplate();
        if ($template === '') return '';

        return strtr($template, [
            '@@SLUG@@'     => rawurlencode((string) ($item['slug'] ?? '')),
            '@@TINT@@'     => Store::tintFor((string) ($item['category'] ?? '')),
            '@@CATEGORY@@' => self::esc($item['category'] ?? ''),
            '@@H1@@'       => self::esc($item['h1'] ?? ''),
            '@@EXCERPT@@'  => self::esc($item['excerpt'] ?: ($item['description'] ?? '')),
            '@@MINUTES@@'  => (string) ($item['minutes'] ?? 3),
        ]);
    }

    /** Three more things to read: same category first, then anything else, newest first. */
    public static function relatedCards(array $post): string
    {
        $others = array_merge(
            array_filter(Store::all(), static fn(array $p) => $p['slug'] !== $post['slug']),
            Store::guides()
        );
        $sameCategory = array_filter($others, static fn(array $p) => ($p['category'] ?? '') === ($post['category'] ?? ''));
        $rest         = array_filter($others, static fn(array $p) => ($p['category'] ?? '') !== ($post['category'] ?? ''));

        $cards = '';
        foreach (array_slice(array_merge($sameCategory, $rest), 0, 3) as $item) {
            $cards .= self::card($item);
        }

        return $cards;
    }

    /** Gives every h2 an id and builds the "On this page" list, matching the build's rules. */
    private static function headingsAndToc(string $html): array
    {
        $used  = [];
        $items = [];
        $out = preg_replace_callback('/<h2(?:\s[^>]*)?>(.*?)<\/h2>/is', static function (array $m) use (&$used, &$items): string {
            $text = Html::toText($m[1]);
            $id   = Store::slugify($text) ?: 'section';
            while (isset($used[$id])) $id .= '-2';
            $used[$id] = true;
            $items[]   = ['id' => $id, 'text' => $text];

            return '<h2 id="' . $id . '">' . $m[1] . '</h2>';
        }, $html) ?? $html;

        if (count($items) < 3) return [$out, ''];

        $links = '';
        foreach ($items as $item) {
            $links .= '<li><a href="#' . $item['id'] . '">' . self::esc($item['text']) . '</a></li>';
        }

        return [$out, '<nav class="toc" aria-label="On this page"><p class="toc-title">On this page</p><ol>' . $links . '</ol></nav>'];
    }

    private static function jsonLd(array $post, string $body, string $date, string $updated): string
    {
        $base = Store::site('url');
        $url  = $base . '/blog/' . $post['slug'];
        $blocks = [
            [
                '@context'         => 'https://schema.org',
                '@type'            => 'Article',
                'headline'         => (string) ($post['h1'] ?? ''),
                'description'      => (string) ($post['description'] ?? ''),
                'datePublished'    => $date,
                'dateModified'     => $updated,
                'image'            => self::ogImage(),
                'mainEntityOfPage' => $url,
                'inLanguage'       => 'en',
                'articleSection'   => (string) ($post['category'] ?? ''),
                'wordCount'        => Html::wordCount($body),
                'isAccessibleForFree' => true,
                'author'    => ['@type' => 'Organization', 'name' => Store::site('editorialTeam'), 'url' => $base . '/about#editorial-policy'],
                'publisher' => ['@id' => $base . '/#organization', '@type' => 'Organization', 'name' => Store::site('name')],
            ],
            [
                '@context'        => 'https://schema.org',
                '@type'           => 'BreadcrumbList',
                'itemListElement' => [
                    ['@type' => 'ListItem', 'position' => 1, 'name' => 'Home', 'item' => $base . '/'],
                    ['@type' => 'ListItem', 'position' => 2, 'name' => 'Guides', 'item' => $base . '/blog/'],
                    ['@type' => 'ListItem', 'position' => 3, 'name' => (string) ($post['short'] ?? $post['h1'] ?? ''), 'item' => $url],
                ],
            ],
        ];

        $out = '';
        foreach ($blocks as $block) {
            $json = json_encode($block, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
            $out .= '<script type="application/ld+json">' . str_replace('<', '\\u003c', (string) $json) . "</script>\n";
        }

        return trim($out);
    }

    /** The share image the build put in the shell, complete with its version tag. */
    private static function ogImage(): string
    {
        return preg_match('/<meta property="og:image" content="([^"]+)"/', Store::shell(), $m)
            ? $m[1]
            : Store::site('url') . '/images/og-image.png';
    }

    public static function dateText(string $iso): string
    {
        $time = strtotime($iso . ' 12:00:00 UTC');

        return $time ? gmdate('F j, Y', $time) : $iso;
    }

    public static function rssDate(string $iso): string
    {
        $time = strtotime($iso . ' 09:00:00 UTC');

        return gmdate('D, d M Y H:i:s', $time ?: time()) . ' GMT';
    }

    public static function esc(?string $value): string
    {
        return htmlspecialchars((string) $value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
    }
}
