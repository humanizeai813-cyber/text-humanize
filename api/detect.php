<?php
require_once __DIR__ . '/config.php';
require_once __DIR__ . '/rate_limit.php';
require_once __DIR__ . '/lib/TextMetrics.php';
require_once __DIR__ . '/lib/Response.php';
require_once __DIR__ . '/lib/Cache.php';

// Part of the result cache key: bump whenever scoring changes so old results are not served.
const DETECT_CACHE_VERSION = '2026-09-18.1';

header('Content-Type: application/json');

$origin = ALLOWED_ORIGIN;
if ($origin) {
    header('Access-Control-Allow-Origin: ' . $origin);
} else {
    header('Access-Control-Allow-Origin: ' . ($_SERVER['HTTP_ORIGIN'] ?? ''));
}
header('Access-Control-Allow-Methods: POST');
header('Access-Control-Allow-Headers: Content-Type');

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') { http_response_code(200); exit; }
if ($_SERVER['REQUEST_METHOD'] !== 'POST') { http_response_code(405); echo json_encode(['error' => 'Method not allowed']); exit; }

if (!checkRateLimit('detect', 20, 60)) {
    http_response_code(429);
    echo json_encode(['error' => 'Too many requests. Please wait a moment and try again.']);
    exit;
}

$res   = new Response();
$input = json_decode(file_get_contents('php://input'), true);
$text  = trim($input['text'] ?? '');

if (empty($text))           $res->error('MISSING_TEXT', 'Text is required', 400);
if (strlen($text) > 10000)  $res->error('TEXT_TOO_LONG', 'Text must be under 10,000 characters', 400);

// The same text checked again within the hour (the sample text, a double click, a reload) gets the
// stored result instead of new calls to the external models. Retention is stated in the privacy
// policy. The key includes which models are enabled, so switching one on never serves a stale result.
$cacheKey = 'detect_' . hash('sha256', json_encode([
    'text'    => $text,
    'models'  => [SAPLING_API_KEY !== '', GPTZERO_API_KEY !== '', HF_TOKEN !== ''],
    'version' => DETECT_CACHE_VERSION,
]));
$cachedResult = Cache::get($cacheKey);
if ($cachedResult !== null) {
    echo json_encode($cachedResult + ['cached' => true]);
    exit;
}

// ── Real linguistic metrics (burstiness, MATTR, hedging, etc.) ───────────────
$profile = TextMetrics::analyze($text);

$burstiness      = $profile['burstiness'];
$sentenceCv      = $profile['sentenceCv'];
$mattr           = $profile['mattr'];
$hedgingDensity  = $profile['hedgingDensity'];
$fleschVariance  = $profile['fleschVariance'];
$transitionDens  = $profile['transitionDensity'];
$sentences       = $profile['sentences'];
$sentCount       = $profile['sentenceCount'];
$wordCount       = $profile['wordCount'];

$signals  = [];
$passes   = [];
$rawScore = 0.0;   // weighted 0–100 sum

// ── Metric-weighted scoring ───────────────────────────────────────────────────
// Weights were checked against known human writing (historical, literary and plain personal
// texts) and typical AI drafts. Measures that fire on most human prose as often as on AI text
// (burstiness, MATTR, hedging) are still reported in `metrics` but score little or nothing.
$signal = function (string $name, int $weight, int $contribution, string $detail) use (&$signals, &$rawScore): void {
    $signals[] = ['name' => $name, 'weight' => $weight, 'contribution' => $contribution, 'detail' => $detail];
    $rawScore += $contribution;
};

// 1. Sentence length variation (up to 25). Burstiness is the same measure transformed,
//    B = (CV - 1) / (CV + 1), so it is not scored a second time.
if ($sentCount >= 4 && $sentenceCv < 0.20) {
    $signal('Very uniform sentence length', 25, 25, 'CV=' . $sentenceCv . ': sentences are nearly the same length (human writing is usually above 0.35)');
} elseif ($sentCount >= 4 && $sentenceCv < 0.32) {
    $signal('Limited sentence length variation', 25, 10, 'CV=' . $sentenceCv . ' (human writing is usually above 0.35)');
} elseif ($sentCount >= 4) {
    $passes[] = 'Varied sentence length (CV=' . $sentenceCv . ')';
}

// 2. Stacked AI-typical connectors (up to 20): furthermore, moreover, additionally, in conclusion...
if ($transitionDens >= 0.25) {
    $signal('Stacked AI transition words', 20, 20, 'Connectors such as "furthermore" and "moreover" appear ' . round($transitionDens, 2) . ' times per sentence');
} elseif ($transitionDens > 0) {
    $signal('AI transition words present', 20, 8, 'Connectors such as "furthermore" appear ' . round($transitionDens, 2) . ' times per sentence');
} else {
    $passes[] = 'No stacked AI transition words';
}

// 3. AI-typical vocabulary and stock phrases, whole words only (up to 22).
$aiPhrases   = $profile['aiPhrases'];
$phraseCount = count($aiPhrases);
if ($phraseCount >= 5) {
    $signal('High density of AI-typical phrasing', 22, 22, 'Includes: ' . implode(', ', array_slice($aiPhrases, 0, 5)));
} elseif ($phraseCount >= 3) {
    $signal('Several AI-typical words and phrases', 22, 14, implode(', ', $aiPhrases));
} elseif ($phraseCount >= 1) {
    $signal('Some AI-typical wording', 22, 5, implode(', ', $aiPhrases));
} else {
    $passes[] = 'No common AI stock phrases';
}

// 4. The three patterns above together (8). Each one alone also turns up in human writing.
if ($sentCount >= 4 && $sentenceCv < 0.32 && $transitionDens > 0 && $phraseCount >= 1) {
    $signal('Several AI patterns together', 8, 8, 'Uniform sentences, stacked connectors and stock phrasing appear in the same text');
}

// 5. Uniform readability across paragraphs (6).
if ($fleschVariance < 5.0) {
    $signal('Uniform paragraph readability', 6, 6, 'Flesch variance=' . $fleschVariance . ': every paragraph is equally dense');
} else {
    $passes[] = 'Varied paragraph complexity (Flesch variance=' . $fleschVariance . ')';
}

// 6. A long text that never qualifies a claim (4). Weak on its own: plenty of people don't hedge.
if ($wordCount >= 150 && $hedgingDensity == 0.0) {
    $signal('No hedging language', 4, 4, 'No words such as "may", "often" or "usually" in ' . $wordCount . ' words');
} elseif ($wordCount >= 150) {
    $passes[] = 'Qualifies claims naturally';
}

// 7. Very repetitive vocabulary (6). AI drafts usually score a higher MATTR than people, not a
//    lower one, so only unusually repetitive text is flagged.
if ($wordCount >= 60 && $mattr < 0.55) {
    $signal('Repetitive vocabulary', 6, 6, 'MATTR=' . $mattr . ': the same words repeat within short stretches');
}

// 8. Word frequency distribution (6).
if ($wordCount >= 50 && $profile['zipfR2'] < 0.75) {
    $signal('Unnatural word frequency distribution', 6, 6, 'Zipf R²=' . $profile['zipfR2'] . ': vocabulary skewed toward mid-frequency words');
}

// 9. Every sentence about exactly the same nouns (6).
if ($sentCount >= 4 && $profile['entityOverlap'] > 0.60) {
    $signal('Excessive topical repetition', 6, 6, 'Entity overlap=' . $profile['entityOverlap'] . ': consecutive sentences keep repeating the same nouns');
}

// 10. Human signals: contractions and informal sentence starters lower the score.
$contractionCount = preg_match_all("/\b\w+['’](s|t|re|ve|ll|d|m)\b/iu", $text);
$informalStarters = preg_match_all('/(?:^|[.!?]\s+)(And|But|So|Because|Or|Nor|Yet)\b/m', $text);

$humanSignals = 0;
if ($contractionCount >= 2) { $humanSignals++; $passes[] = 'Uses contractions naturally'; }
if ($informalStarters >= 1) { $humanSignals++; $passes[] = 'Informal sentence starters (And/But/So)'; }

if ($humanSignals >= 2)            $rawScore -= 10;
elseif ($humanSignals === 1)       $rawScore -= 4;
elseif ($contractionCount === 0 && $sentCount >= 6) {
    $signal('No contractions', 3, 3, 'Zero contractions across ' . $sentCount . ' sentences (formal human writing often has none either)');
}

// ── ML detector ensemble (Sapling AI + GPTZero) ───────────────────────────────
// For accuracy, use every configured detector and calibrate by agreement.
// Fallback remains 100% local when external APIs are unavailable.

$localScore     = max(0.0, min(100.0, $rawScore));
$providerScores = [];
$sentenceScores = [];  // per-sentence AI probability, for highlighting

$fakespot = null;  // open-source model's AI probability (0-100), scored separately below
foreach (runExternalDetectors($text) as $source => $result) {
    if ($source === 'fakespot') {
        $fakespot = (float) $result['score'];
        continue;
    }
    $providerScores[] = ['source' => $source, 'score' => (float) $result['score']];
    if (empty($sentenceScores)) $sentenceScores = $result['sentences'];
}

$mlScore  = null;
$mlSource = null;
if (!empty($providerScores)) {
    $mlSource = implode('+', array_map(fn($p) => $p['source'], $providerScores));
    $mlScore  = weightedProviderScore($providerScores);
    $rawScore = blendDetectorScores($localScore, $mlScore, $wordCount, count($providerScores));
}

// The open-source model catches modern AI writing that the pattern signals miss, but it also rated
// some formal human writing 50-98% AI in testing. Its calibrated score can raise the result, never
// lower it, and only its most confident results (99%+) reach "Likely AI".
if ($fakespot !== null) {
    $modelScore = calibrateFakespot($fakespot);
    if ($modelScore > $rawScore) {
        $signals[] = [
            'name'         => 'Open-source AI detection model',
            'weight'       => 85,
            'contribution' => (int) round($modelScore - max(0.0, $rawScore)),
            'detail'       => 'Rates the text ' . number_format($fakespot, $fakespot >= 99 ? 1 : 0) . '% likely AI'
                . ($fakespot < 99 ? ', below the 99% level treated as strong evidence' : ''),
        ];
        $rawScore = $modelScore;
    }
    $providerScores[] = ['source' => 'fakespot', 'score' => $fakespot];
    $mlSource = $mlSource ? $mlSource . '+fakespot' : 'fakespot';
    $mlScore ??= $modelScore;
}

// ── Clamp, calibrate, and classify ───────────────────────────────────────────
$score      = max(0, min(100, (int) round($rawScore)));
$confidence = detectorConfidence($score, $localScore, $providerScores, $wordCount, count($signals));
$reliabilityNotes = reliabilityNotes($wordCount, $confidence, $providerScores);

// Sort signals by contribution descending
usort($signals, fn($a, $b) => $b['contribution'] <=> $a['contribution']);

// Determine verdict
if ($confidence['label'] === 'low' && $score > 25 && $score < 75) {
    $verdict = 'Uncertain';
} elseif ($wordCount < 40) {
    $verdict = 'Too short to judge reliably';
} elseif ($score <= 10) {
    $verdict = 'Low AI patterns';
} elseif ($score <= 30) {
    $verdict = 'Some AI patterns';
} elseif ($score <= 55) {
    $verdict = 'Uncertain';
} elseif ($score <= 75) {
    $verdict = 'Likely AI patterns';
} else {
    $verdict = 'Strong AI patterns';
}

// Top 4 reason strings for backward-compatible 'reasons' field
$reasons = array_map(fn($s) => $s['name'] . ' — ' . $s['detail'], array_slice($signals, 0, 4));
if (empty($reasons)) $reasons[] = 'No strong AI fingerprints detected';
if (count($reasons) < 3 && !empty($passes)) $reasons[] = '✓ ' . $passes[0];
foreach ($reliabilityNotes as $note) {
    if (count($reasons) >= 4) break;
    $reasons[] = $note;
}

// dominant signal label
$dominantSignal = !empty($signals) ? $signals[0]['name'] : 'None';

// ── Emit response (backward-compatible + enriched) ───────────────────────────
$payload = [
    // Backward-compatible fields (frontend uses these)
    'score'   => $score,
    'verdict' => $verdict,
    'reasons' => array_values(array_slice($reasons, 0, 4)),

    // New enriched fields
    'metrics' => [
        'burstiness'        => $burstiness,
        'sentenceCv'        => $sentenceCv,
        'mattr'             => $mattr,
        'hedgingDensity'    => $hedgingDensity,
        'fleschVariance'    => $fleschVariance,
        'transitionDensity' => $transitionDens,
        'zipfR2'            => $profile['zipfR2'],
        'entityOverlap'     => $profile['entityOverlap'],
    ],
    'signals'        => array_values(array_slice($signals, 0, 6)),
    'dominantSignal' => $dominantSignal,
    'mlSource'       => $mlSource,          // 'sapling' | 'gptzero' | null
    'sentenceScores' => $sentenceScores,    // per-sentence AI probability for highlighting
    'confidence'     => $confidence,
    'calibration'    => [
        'localScore'     => (int) round($localScore),
        'mlScore'        => $mlScore !== null ? (int) round($mlScore) : null,
        'providerScores' => $providerScores,
        'wordCount'      => $wordCount,
    ],
];

// Only a complete result is stored: if an enabled model failed this time, the next check retries it.
$complete = true;
foreach (['sapling' => SAPLING_API_KEY, 'gptzero' => GPTZERO_API_KEY, 'fakespot' => HF_TOKEN] as $source => $key) {
    if ($key !== '' && !str_contains((string) $mlSource, $source)) $complete = false;
}
if ($complete) Cache::set($cacheKey, $payload, 3600);

echo json_encode($payload + ['cached' => false]);

function weightedProviderScore(array $providerScores): float
{
    if (empty($providerScores)) return 0.0;

    $weights = [
        'sapling' => 0.52,
        'gptzero' => 0.48,
    ];

    $total = 0.0;
    $weightTotal = 0.0;
    foreach ($providerScores as $provider) {
        $weight = $weights[$provider['source']] ?? 0.50;
        $total += $provider['score'] * $weight;
        $weightTotal += $weight;
    }

    return $weightTotal > 0 ? $total / $weightTotal : 0.0;
}

function blendDetectorScores(float $localScore, float $mlScore, int $wordCount, int $providerCount): float
{
    $mlWeight = $providerCount >= 2 ? 0.76 : 0.66;

    if ($wordCount < 40) {
        $mlWeight -= 0.16;
    } elseif ($wordCount < 80) {
        $mlWeight -= 0.08;
    }

    $mlWeight = max(0.50, min(0.80, $mlWeight));
    return ($mlScore * $mlWeight) + ($localScore * (1.0 - $mlWeight));
}

function detectorConfidence(int $score, float $localScore, array $providerScores, int $wordCount, int $signalCount): array
{
    $confidence = 45;

    if ($wordCount >= 250)      $confidence += 18;
    elseif ($wordCount >= 120)  $confidence += 12;
    elseif ($wordCount >= 60)   $confidence += 6;
    elseif ($wordCount < 40)    $confidence -= 18;

    $distanceFromMiddle = abs($score - 50);
    $confidence += (int) round(min(22, $distanceFromMiddle * 0.45));
    $confidence += min(10, $signalCount * 2);

    if (!empty($providerScores)) {
        $confidence += count($providerScores) >= 2 ? 18 : 10;
        $scores = array_column($providerScores, 'score');
        $spread = max($scores) - min($scores);
        if ($spread <= 12)      $confidence += 10;
        elseif ($spread <= 25)  $confidence += 3;
        else                    $confidence -= 12;

        $mlAverage = array_sum($scores) / count($scores);
        if (abs($mlAverage - $localScore) <= 18) {
            $confidence += 8;
        } elseif (abs($mlAverage - $localScore) > 35) {
            $confidence -= 10;
        }
    } else {
        $confidence -= 8;
    }

    $confidence = max(5, min(99, $confidence));
    $label = $confidence >= 75 ? 'high' : ($confidence >= 50 ? 'medium' : 'low');

    return [
        'label' => $label,
        'score' => $confidence,
    ];
}

function reliabilityNotes(int $wordCount, array $confidence, array $providerScores): array
{
    $notes = [];

    if ($wordCount < 40) {
        $notes[] = 'Reliability note — text is short, so the score is directional rather than definitive';
    } elseif ($wordCount < 80) {
        $notes[] = 'Reliability note — longer samples improve detection accuracy';
    }

    if (empty($providerScores)) {
        $notes[] = 'Reliability note — using local linguistic signals only';
    } elseif (count($providerScores) >= 2) {
        $scores = array_column($providerScores, 'score');
        if ((max($scores) - min($scores)) > 25) {
            $notes[] = 'Reliability note — external detectors disagree, so the verdict is conservative';
        }
    }

    if ($confidence['label'] === 'high') {
        $notes[] = 'High confidence — multiple signals point in the same direction';
    }

    return $notes;
}

// ── ML API helpers ────────────────────────────────────────────────────────────

/**
 * Ask every configured external detector at the same time, so a check takes as long as the
 * slowest provider instead of all of them added together. A provider that fails is skipped
 * for a while (a minute after a network error, an hour after a rejected key) so visitors
 * don't keep waiting on it.
 *
 * @return array<string, array{score: float, sentences: array}>
 */
function runExternalDetectors(string $text): array
{
    $requests = [];
    if (SAPLING_API_KEY !== '' && Cache::get('detector_down_sapling') === null) {
        // Free tier: 2,000 requests/month. https://sapling.ai/docs/api/ai-detect
        $requests['sapling'] = ['https://api.sapling.ai/api/v1/aidetect', ['key' => SAPLING_API_KEY, 'text' => $text], ['Content-Type: application/json']];
    }
    if (GPTZERO_API_KEY !== '' && Cache::get('detector_down_gptzero') === null) {
        // Free tier: 10,000 words/month. https://gptzero.me/docs
        $requests['gptzero'] = ['https://api.gptzero.me/v2/predict/text', ['document' => $text], ['Content-Type: application/json', 'x-api-key: ' . GPTZERO_API_KEY]];
    }
    if (HF_TOKEN !== '' && Cache::get('detector_down_fakespot') === null) {
        // Open-source (Apache-2.0) Fakespot detector, run by Hugging Face Inference Providers.
        // Free accounts get monthly credits. https://huggingface.co/fakespot-ai/roberta-base-ai-text-detection-v1
        $requests['fakespot'] = ['https://router.huggingface.co/hf-inference/models/fakespot-ai/roberta-base-ai-text-detection-v1', ['inputs' => fakespotInput($text)], ['Content-Type: application/json', 'Authorization: Bearer ' . HF_TOKEN]];
    }
    if (!$requests) return [];

    $multi   = curl_multi_init();
    $handles = [];
    foreach ($requests as $source => [$url, $body, $headers]) {
        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_POST           => true,
            CURLOPT_POSTFIELDS     => json_encode($body),
            CURLOPT_HTTPHEADER     => $headers,
            CURLOPT_CONNECTTIMEOUT => 3,
            CURLOPT_TIMEOUT        => 8,
        ]);
        curl_multi_add_handle($multi, $ch);
        $handles[$source] = $ch;
    }

    do {
        $status = curl_multi_exec($multi, $running);
        if ($running && curl_multi_select($multi, 0.5) === -1) usleep(20000);
    } while ($running && $status === CURLM_OK);

    $results = [];
    foreach ($handles as $source => $ch) {
        $code   = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $data   = $code === 200 ? json_decode((string) curl_multi_getcontent($ch), true) : null;
        $parsed = is_array($data) ? match ($source) {
            'sapling'  => parseSaplingResult($data),
            'gptzero'  => parseGptZeroResult($data),
            'fakespot' => parseFakespotResult($data),
        } : null;
        curl_multi_remove_handle($multi, $ch);

        if ($parsed !== null) {
            $results[$source] = $parsed;
            continue;
        }
        // A rejected key won't fix itself soon; network trouble usually does.
        Cache::set('detector_down_' . $source, ['status' => $code], in_array($code, [401, 402, 403], true) ? 3600 : 60);
        error_log("detect.php: {$source} gave no usable result (HTTP {$code}); skipping it for a while");
    }
    curl_multi_close($multi);

    return $results;
}

/** Sapling's response as a 0-100 AI score plus per-sentence scores for highlighting. */
function parseSaplingResult(array $data): ?array
{
    if (!isset($data['score'])) return null;

    $sentences = [];
    foreach ($data['sentence_scores'] ?? [] as $item) {
        $sentences[] = ['text' => $item[0] ?? '', 'score' => isset($item[1]) ? (float) $item[1] * 100 : null];
    }
    return ['score' => (float) $data['score'] * 100, 'sentences' => $sentences];
}

/**
 * The model reads about 512 tokens, so send the first ~350 words as plain single-line text,
 * as its model card recommends.
 */
function fakespotInput(string $text): string
{
    $plain = html_entity_decode(strip_tags($text), ENT_QUOTES | ENT_HTML5, 'UTF-8');
    $plain = str_replace(' ,', ',', trim(preg_replace('/\s+/u', ' ', $plain) ?? $plain));
    return implode(' ', array_slice(explode(' ', $plain), 0, 350));
}

/** Hugging Face text-classification output, e.g. [[{"label":"AI","score":0.99},{"label":"Human","score":0.01}]]. */
function parseFakespotResult(array $data): ?array
{
    $labels = isset($data[0][0]) && is_array($data[0][0]) ? $data[0] : $data;
    foreach ($labels as $item) {
        if (!is_array($item) || !isset($item['label'], $item['score'])) continue;
        if (strcasecmp((string) $item['label'], 'AI') === 0) return ['score' => (float) $item['score'] * 100, 'sentences' => []];
        if (strcasecmp((string) $item['label'], 'Human') === 0) return ['score' => (1 - (float) $item['score']) * 100, 'sentences' => []];
    }
    return null;
}

/**
 * Fakespot's AI probability mapped onto the detector's 0-100 scale. In testing it gave AI text
 * 99.6-100% but some formal human writing up to 98.6%, so only 99%+ counts as likely AI (60-85),
 * 95-99% stays uncertain (45-55), 50-95% is weak evidence (25-45) and below 50% counts as low.
 */
function calibrateFakespot(float $probability): float
{
    if ($probability >= 99.0) return 60 + ($probability - 99.0) * 25;
    if ($probability >= 95.0) return 45 + ($probability - 95.0) * 2.5;
    if ($probability >= 50.0) return 25 + ($probability - 50.0) * (20 / 45);
    return $probability * 0.5;
}

/** GPTZero's response as a 0-100 AI score plus per-sentence scores for highlighting. */
function parseGptZeroResult(array $data): ?array
{
    $doc = $data['documents'][0] ?? null;
    if (!$doc) return null;

    $sentences = [];
    foreach ($doc['sentences'] ?? [] as $s) {
        $sentences[] = ['text' => $s['sentence'] ?? '', 'score' => isset($s['generated_prob']) ? (float) $s['generated_prob'] * 100 : null];
    }
    return ['score' => (float) ($doc['completely_generated_prob'] ?? $doc['average_generated_prob'] ?? 0) * 100, 'sentences' => $sentences];
}
