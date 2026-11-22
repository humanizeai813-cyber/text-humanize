<?php
/**
 * Pre-warm the humanize cache with the homepage "Try Example" text so the
 * demo returns instantly (served from cache) for first-time visitors.
 *
 * CLI only. Run once after each deploy (or whenever the prompt version bumps):
 *   php api/warm-cache.php https://text-humanize.com
 *   php api/warm-cache.php http://localhost:8000      # local testing
 *
 * Requires an AI provider key in .env (GROQ_API_KEY, CLOUDFLARE_* or NVIDIA_API_KEY). It makes real LLM calls once,
 * then every visitor who clicks "Humanize" on the example gets a cache hit.
 */

if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    exit('This script can only be run from the command line.');
}

$base     = rtrim($argv[1] ?? 'https://text-humanize.com', '/');
$endpoint = $base . '/api/humanize.php';

// Must match the SAMPLE string in js/humanizer.js ("Try a sample" button)
$example = "In today\u{2019}s fast-paced digital landscape, effective communication has become more important than ever. "
    . "Furthermore, organizations must leverage cutting-edge tools to streamline their workflows and foster collaboration. "
    . "By embracing innovative solutions, teams can unlock their full potential and drive meaningful results. "
    . "Additionally, it is crucial to note that clear writing plays a pivotal role in building trust with customers.";

// Default + most common tone/level combinations users actually click
$combos = [
    ['tone' => 'natural',      'level' => 'balanced'],   // page default
    ['tone' => 'natural',      'level' => 'light'],
    ['tone' => 'professional', 'level' => 'balanced'],
    ['tone' => 'academic',     'level' => 'balanced'],
];

echo "Warming humanize cache at {$endpoint}\n\n";

foreach ($combos as $c) {
    $payload = json_encode([
        'text'    => $example,
        'tone'    => $c['tone'],
        'level'   => $c['level'],
        'purpose' => 'general',
    ]);

    $ch = curl_init($endpoint);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_POST           => true,
        CURLOPT_POSTFIELDS     => $payload,
        CURLOPT_HTTPHEADER     => ['Content-Type: application/json', 'Origin: ' . $base],
        CURLOPT_TIMEOUT        => 60,
    ]);

    $start = microtime(true);
    $res   = curl_exec($ch);
    $code  = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $err   = curl_error($ch);
    curl_close($ch);

    $ms     = (int) round((microtime(true) - $start) * 1000);
    $cached = (json_decode($res, true)['cached'] ?? false) ? 'CACHED' : 'fresh';
    $tag    = ($code === 200) ? 'OK ' : 'ERR';

    printf("[%s] %-12s / %-9s — HTTP %d — %5dms (%s)%s\n",
        $tag, $c['tone'], $c['level'], $code, $ms, $cached, $err ? " — {$err}" : '');

    sleep(1); // stay under the 10 req/60s rate limit
}

echo "\nDone. Re-run after each deploy.\n";
