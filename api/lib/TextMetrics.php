<?php
/**
 * TextMetrics — Real linguistic metrics for AI vs human text discrimination.
 *
 * All methods are pure PHP (no external API calls).
 * Used by both detect.php (scoring) and humanize.php (diagnostic + self-verify loop).
 */
class TextMetrics
{
    // ── Public entry point ────────────────────────────────────────────────────

    /**
     * Full linguistic profile of a text string.
     *
     * @return array{
     *   burstiness: float,
     *   sentenceCv: float,
     *   mattr: float,
     *   hedgingDensity: float,
     *   fleschVariance: float,
     *   transitionDensity: float,
     *   sentences: string[],
     *   wordCount: int,
     *   sentenceCount: int,
     *   dominantFlaw: string
     * }
     */
    public static function analyze(string $text): array
    {
        $sentences = self::tokenizeSentences($text);
        $words     = self::tokenizeWords($text);
        $wordCount = count($words);

        $burstiness        = self::burstiness($sentences);
        $sentenceCv        = self::sentenceLengthCv($sentences);
        $mattr             = self::mattr($words);
        $hedgingDensity    = self::hedgingDensity($text, $wordCount);
        $fleschVariance    = self::fleschVariance($text);
        $transitionDensity = self::transitionDensity($text, count($sentences));
        $zipfR2            = self::zipfCompliance($words);         // NEW
        $entityOverlap     = self::entityCoherenceOverlap($sentences); // NEW

        $aiPhrases    = self::aiPhrases($text);
        $dominantFlaw = self::dominantFlaw($sentenceCv, $mattr, count($sentences), count($aiPhrases), $transitionDensity);

        return [
            'burstiness'        => round($burstiness, 4),
            'sentenceCv'        => round($sentenceCv, 4),
            'mattr'             => round($mattr, 4),
            'hedgingDensity'    => round($hedgingDensity, 4),
            'fleschVariance'    => round($fleschVariance, 2),
            'transitionDensity' => round($transitionDensity, 4),
            'zipfR2'            => round($zipfR2, 4),
            'entityOverlap'     => round($entityOverlap, 4),
            'aiPhrases'         => $aiPhrases,
            'sentences'         => $sentences,
            'wordCount'         => $wordCount,
            'sentenceCount'     => count($sentences),
            'dominantFlaw'      => $dominantFlaw,
        ];
    }

    // ── Core Metrics ─────────────────────────────────────────────────────────

    /**
     * Burstiness coefficient: B = (σ − μ) / (σ + μ) on sentence word-counts.
     *
     * This equals (CV − 1) / (CV + 1), so it carries the same information as the
     * sentence-length CV and is negative for almost all prose, human or AI. It is
     * reported for reference only; scoring uses sentenceLengthCv().
     */
    public static function burstiness(array $sentences): float
    {
        if (count($sentences) < 3) return 0.0;

        $lengths = array_map('str_word_count', $sentences);
        $mean    = array_sum($lengths) / count($lengths);

        if ($mean <= 0) return 0.0;

        $variance = array_sum(array_map(fn($l) => pow($l - $mean, 2), $lengths)) / count($lengths);
        $sigma    = sqrt($variance);

        $denom = $sigma + $mean;
        if ($denom <= 0) return 0.0;

        return ($sigma - $mean) / $denom;
    }

    /**
     * Sentence-length coefficient of variation: stdDev / mean.
     *
     * Human target: CV > 0.40
     * AI signal:    CV < 0.20
     */
    public static function sentenceLengthCv(array $sentences): float
    {
        if (count($sentences) < 3) return 0.5; // assume OK for short texts

        $lengths = array_map('str_word_count', $sentences);
        $mean    = array_sum($lengths) / count($lengths);

        if ($mean <= 0) return 0.0;

        $variance = array_sum(array_map(fn($l) => pow($l - $mean, 2), $lengths)) / count($lengths);
        return sqrt($variance) / $mean;
    }

    /**
     * Moving Average Type-Token Ratio (MATTR) over a 50-word sliding window.
     *
     * Normalizes lexical diversity for text length (plain TTR degrades with length).
     *
     * Human text:   MATTR > 0.72
     * AI text:      MATTR ≈ 0.55–0.68
     * AI threshold: MATTR < 0.68
     */
    public static function mattr(array $words, int $window = 50): float
    {
        $n = count($words);
        if ($n === 0) return 0.0;

        $words = array_map('strtolower', $words);
        if ($n < $window) {
            return count(array_unique($words)) / $n;
        }

        $freq = [];
        $unique = 0;
        for ($i = 0; $i < $window; $i++) {
            $word = $words[$i];
            if (!isset($freq[$word])) {
                $freq[$word] = 0;
                $unique++;
            }
            $freq[$word]++;
        }

        $ratios = [];
        for ($i = 0; $i <= $n - $window; $i++) {
            $ratios[] = $unique / $window;

            if ($i === $n - $window) break;

            $out = $words[$i];
            $freq[$out]--;
            if ($freq[$out] === 0) {
                unset($freq[$out]);
                $unique--;
            }

            $in = $words[$i + $window];
            if (!isset($freq[$in])) {
                $freq[$in] = 0;
                $unique++;
            }
            $freq[$in]++;
        }

        return array_sum($ratios) / count($ratios);
    }

    /**
     * Hedging language density: epistemic markers per 100 words.
     *
     * Human text:   > 0.5 per 100 words
     * AI (confident mode): < 0.3 per 100 words
     */
    public static function hedgingDensity(string $text, int $wordCount): float
    {
        if ($wordCount === 0) return 0.0;

        $patterns = [
            '/\bmight\b/i', '/\bcould\b/i', '/\bseems?\b/i',
            '/\bappears? to\b/i', '/\bI think\b/i', '/\bI feel\b/i',
            '/\bpossibly\b/i', '/\bperhaps\b/i', '/\bargua[b]ly\b/i',
            '/\btends? to\b/i', '/\bin some cases\b/i', '/\bin some situations\b/i',
            '/\bsomewhat\b/i', '/\bsort of\b/i', '/\bkind of\b/i',
            '/\bgenerally\b/i', '/\boften\b/i', '/\busually\b/i',
            '/\bmay\b/i', '/\bseemingly\b/i',
        ];

        $count = 0;
        foreach ($patterns as $p) {
            $count += preg_match_all($p, $text);
        }

        return ($count / $wordCount) * 100;
    }

    /**
     * Inter-paragraph Flesch-Kincaid Reading Ease variance.
     *
     * Low variance = AI signal (all paragraphs equally dense/readable).
     * AI threshold: variance < 5.0
     */
    public static function fleschVariance(string $text): float
    {
        $paragraphs = array_values(array_filter(
            preg_split('/\n{2,}/', trim($text)),
            fn($p) => str_word_count($p) >= 10
        ));

        if (count($paragraphs) < 2) return 20.0; // assume OK for single-paragraph text

        $scores = array_map([self::class, 'fleschScore'], $paragraphs);
        $mean   = array_sum($scores) / count($scores);
        $var    = array_sum(array_map(fn($s) => pow($s - $mean, 2), $scores)) / count($scores);

        return $var;
    }

    /**
     * Transition markers per sentence, counting only the connectors AI drafts stack far more
     * often than people do. Plain connectors such as "therefore" or "thus" are left out:
     * they are just as common in human writing. Whole words only.
     */
    public static function transitionDensity(string $text, int $sentenceCount): float
    {
        if ($sentenceCount === 0) return 0.0;

        $anywhere = preg_match_all('/\b(?:furthermore|moreover|additionally|it is (?:also )?(?:worth noting|important to note)|in conclusion|to summarize|in summary)\b/i', $text);
        $opening  = preg_match_all('/(?:^|[.!?]["”’)]?\s+)(?:ultimately|notably|overall|in addition|consequently|importantly),/imu', $text);

        return ($anywhere + $opening) / $sentenceCount;
    }

    // ── Helpers ───────────────────────────────────────────────────────────────

    /**
     * Split text into sentences on . ! ? boundaries.
     */
    public static function tokenizeSentences(string $text): array
    {
        $raw = preg_split('/(?<=[.!?])\s+/', trim($text), -1, PREG_SPLIT_NO_EMPTY);
        return array_values(array_filter($raw, fn($s) => str_word_count($s) > 0));
    }

    /**
     * Split text into word tokens (alpha-only, strips punctuation).
     */
    public static function tokenizeWords(string $text): array
    {
        preg_match_all('/\b[a-zA-Z\']+\b/', $text, $m);
        return $m[0];
    }

    /**
     * Distinct AI-typical words and stock phrases in the text, lower-cased, whole words only.
     * Shared by the detector's vocabulary signal and the humanizer's rewrite instructions.
     */
    public static function aiPhrases(string $text): array
    {
        static $pattern = null;
        if ($pattern === null) {
            $phrases = [
                // Buzzwords
                'leverage', 'leverages', 'leveraged', 'leveraging', 'synergy', 'synergies', 'seamless', 'seamlessly',
                'cutting-edge', 'state-of-the-art', 'innovative', 'game-changer', 'game changer', 'groundbreaking',
                'revolutionary', 'transformative', 'holistic', 'robust', 'paradigm', 'pivotal', 'crucial', 'paramount',
                'multifaceted', 'nuanced', 'tapestry', 'realm', 'landscape', 'ecosystem', 'delve', 'delves', 'delving',
                'foster', 'fosters', 'fostering', 'underscore', 'underscores', 'underscoring', 'empower', 'empowers',
                'empowering', 'elevate', 'elevates', 'elevating', 'embrace', 'embraces', 'embracing', 'unlock', 'unlocks',
                'unlocking', 'unleash', 'harness', 'harnessing', 'streamline', 'streamlines', 'streamlining', 'utilize',
                'utilizes', 'utilizing', 'utilization', 'facilitate', 'facilitates', 'facilitating', 'resonate', 'resonates',
                'captivating', 'compelling', 'profound', 'profoundly', 'unprecedented', 'ever-evolving', 'ever-changing',
                'fast-paced', 'next-level', 'bespoke', 'meticulous', 'meticulously', 'testament',
                // Stock phrases
                "in today's", 'it is important to note', 'it is worth noting', 'it is crucial to', 'it is essential to',
                'plays a crucial role', 'plays a vital role', 'plays a pivotal role', 'plays a key role',
                'navigate the complexities', 'in the realm of', 'full potential', 'stay ahead of the curve',
                'at the end of the day', 'in conclusion', 'furthermore', 'moreover', 'additionally',
                'i hope this email finds you well', 'i hope this message finds you well', 'do not hesitate to',
                "don't hesitate to", 'for generations to come', 'truly', 'incredibly',
            ];
            usort($phrases, fn($a, $b) => strlen($b) <=> strlen($a));
            $alternatives = array_map(fn($p) => str_replace("'", "['’]", preg_quote($p, '/')), $phrases);
            $pattern = '/(?<![\p{L}\p{N}\'’-])(?:' . implode('|', $alternatives) . ')(?![\p{L}\p{N}\'’-])/iu';
        }

        if (!preg_match_all($pattern, $text, $m)) return [];
        return array_values(array_unique(array_map(fn($p) => strtolower(str_replace('’', "'", $p)), $m[0])));
    }

    /**
     * Flesch-Kincaid Reading Ease score for a single paragraph.
     * Score 0–100; higher = easier to read.
     */
    public static function fleschScore(string $text): float
    {
        $sentences = self::tokenizeSentences($text);
        $words     = self::tokenizeWords($text);

        $sentCount = max(1, count($sentences));
        $wordCount = max(1, count($words));
        $syllables = array_sum(array_map([self::class, 'countSyllables'], $words));

        return 206.835
            - 1.015  * ($wordCount / $sentCount)
            - 84.6   * ($syllables / $wordCount);
    }

    /**
     * Approximate syllable count for an English word.
     */
    public static function countSyllables(string $word): int
    {
        $word = strtolower(preg_replace('/[^a-z]/', '', $word));
        if (strlen($word) <= 3) return 1;

        $word    = preg_replace('/(?:[^laeiouy]es|ed|[^laeiouy]e)$/', '', $word);
        $word    = preg_replace('/^y/', '', $word);
        $matches = preg_match_all('/[aeiouy]{1,2}/', $word, $m);

        return max(1, (int) $matches);
    }

    /**
     * The draft's most obvious AI-style weakness, used to focus the humanizer prompt:
     * ai_phrasing, transitions, uniform_length, low_mattr, or none.
     */
    private static function dominantFlaw(float $sentenceCv, float $mattr, int $sentenceCount, int $aiPhraseCount, float $transitionDensity): string
    {
        $gaps = [
            'ai_phrasing'    => min(1.0, $aiPhraseCount / 5) * 2.5,
            'transitions'    => min(1.0, $transitionDensity / 0.3) * 2.0,
            'uniform_length' => $sentenceCount >= 4 ? (max(0.0, 0.40 - $sentenceCv) / 0.40) * 2.2 : 0.0,
            'low_mattr'      => (max(0.0, 0.64 - $mattr) / 0.64) * 3.0,
        ];

        arsort($gaps);
        $top = (string) array_key_first($gaps);
        return $gaps[$top] > 0 ? $top : 'none';
    }

    // ── Additional Quality Metrics ────────────────────────────────────────────

    /**
     * Zipf's Law compliance — R² of log-rank vs log-frequency regression.
     *
     * Natural language follows Zipf's law closely (R² > 0.85).
     * AI text in formal mode deviates: over-uses mid-frequency "safe" words.
     *
     * AI signal: R² < 0.82
     */
    public static function zipfCompliance(array $words): float
    {
        if (count($words) < 30) return 0.90; // not enough data, assume OK

        $freq = array_count_values(array_map('strtolower', $words));
        arsort($freq);
        $freqValues = array_values($freq);

        $n = min(30, count($freqValues)); // use top-30 words
        if ($n < 5) return 0.90;

        $logRanks  = [];
        $logFreqs  = [];
        for ($i = 0; $i < $n; $i++) {
            $logRanks[] = log($i + 1);
            $logFreqs[] = log(max(1, $freqValues[$i]));
        }

        return self::pearsonR2($logRanks, $logFreqs);
    }

    /**
     * Entity coherence overlap — average noun-stem overlap between consecutive sentences.
     *
     * AI text: very high overlap (every sentence references the same topic).
     * Human text: lower overlap (drifts, adds tangents, backtracks).
     *
     * AI signal: overlap > 0.55
     */
    public static function entityCoherenceOverlap(array $sentences): float
    {
        if (count($sentences) < 3) return 0.30; // assume OK

        $nounSets = [];
        foreach ($sentences as $s) {
            preg_match_all('/\b[A-Z][a-z]{2,}|[a-z]{4,}\b/', $s, $m);
            // Crude noun extraction: capitalized words + longer lowercase words
            $stems = array_map(fn($w) => strtolower(substr($w, 0, 5)), $m[0]);
            $nounSets[] = array_unique($stems);
        }

        $overlaps = [];
        for ($i = 0; $i < count($nounSets) - 1; $i++) {
            $a = $nounSets[$i];
            $b = $nounSets[$i + 1];
            if (empty($a) || empty($b)) continue;
            $intersection = count(array_intersect($a, $b));
            $union        = count(array_unique(array_merge($a, $b)));
            $overlaps[]   = $union > 0 ? $intersection / $union : 0;
        }

        return empty($overlaps) ? 0.30 : array_sum($overlaps) / count($overlaps);
    }

    /**
     * Pearson R² between two equal-length arrays of floats.
     */
    private static function pearsonR2(array $x, array $y): float
    {
        $n    = count($x);
        if ($n < 2) return 0.0;

        $meanX = array_sum($x) / $n;
        $meanY = array_sum($y) / $n;

        $num  = 0.0;
        $denX = 0.0;
        $denY = 0.0;

        for ($i = 0; $i < $n; $i++) {
            $dx    = $x[$i] - $meanX;
            $dy    = $y[$i] - $meanY;
            $num  += $dx * $dy;
            $denX += $dx * $dx;
            $denY += $dy * $dy;
        }

        $denom = sqrt($denX * $denY);
        if ($denom < 1e-10) return 0.0;

        $r = $num / $denom;
        return $r * $r; // R²
    }
}
