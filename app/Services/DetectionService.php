<?php

declare(strict_types=1);

namespace App\Services;

/**
 * Research instrumentation: how detectable is an AI-assisted interview?
 *
 * This is the defensive half of the project. It scores a recorded session on signals a hiring
 * platform or a human interviewer could plausibly observe, using only what the app already stores,
 * and is used to measure which signals actually separate assisted sessions from unassisted ones.
 *
 * Every method here is pure — session and question rows in, numbers out — so the signals can be
 * evaluated against transcripts without a database.
 *
 * READ THIS BEFORE QUOTING A SCORE. These are heuristics, not proof. Each one has an innocent
 * explanation: a fast answer may be a rehearsed one, uniform structure may be a candidate who was
 * taught STAR, job-description vocabulary is what a well-prepared candidate uses on purpose. The
 * score is a research instrument for comparing populations, and is not fit to judge an individual.
 */
final class DetectionService
{
    /** Pauses shorter than this are too quick to have been composed while speaking. */
    private const FAST_ANSWER_MS = 8000;

    /** Below this coefficient of variation, answers are suspiciously alike in shape. */
    private const UNIFORM_CV = 0.22;

    /** Share of an answer's keywords lifted from the job description. */
    private const JD_ECHO_HIGH = 0.5;

    /** Wording overlap below which the candidate was plainly answering in their own words. */
    private const RECITE_LOW = 0.25;

    /** ...and at or above which they were reading the supplied answer back. */
    private const RECITE_HIGH = 0.6;

    /** Spontaneous speech runs a few percent "um / you know"; reading aloud runs near zero. */
    private const FILLER_GAP = 0.02;

    private const FILLERS = ['um', 'uh', 'er', 'erm', 'like', 'basically', 'actually', 'you know', 'sort of', 'kind of', 'i mean'];

    /**
     * Score one session.
     *
     * @param array<string,mixed> $session interview_sessions row
     * @param list<array<string,mixed>> $questions interview_questions rows (answer already decoded)
     * @param array<string,mixed>|null $job jobs row, for the vocabulary-echo signal
     * @return array{score:int,confidence:string,signals:list<array<string,mixed>>,summary:array<string,mixed>}
     */
    public static function analyse(array $session, array $questions, ?array $job = null): array
    {
        $answered = array_values(array_filter($questions, fn ($q) => !empty($q['answer'])));

        $signals = [
            self::latencySignal($answered),
            self::groundingSignal($answered),
            self::uniformitySignal($answered),
            self::vocabularySignal($answered, $job),
            self::registerSignal($answered),
        ];

        // Weighted mean over the signals that could actually be measured.
        $weighted = 0.0;
        $weight = 0.0;
        foreach ($signals as $s) {
            if ($s['measured']) {
                $weighted += $s['level'] * $s['weight'];
                $weight += $s['weight'];
            }
        }
        $score = $weight > 0.0 ? (int) round(100 * $weighted / $weight) : 0;
        $measured = count(array_filter($signals, fn ($s) => $s['measured']));

        return [
            'score'      => $score,
            'confidence' => self::confidence($measured, count($answered)),
            'signals'    => $signals,
            'summary'    => [
                'questions'       => count($questions),
                'answered'        => count($answered),
                'session_type'    => (string) ($session['session_type'] ?? 'live'),
                'signals_measured' => $measured,
            ],
        ];
    }

    /** How much weight the score deserves, given how much there was to measure. */
    private static function confidence(int $measured, int $answered): string
    {
        if ($answered < 3 || $measured < 2) {
            return 'insufficient';
        }
        return $answered >= 6 && $measured >= 4 ? 'moderate' : 'low';
    }

    // ================================================================ signals

    /**
     * How quickly a complete answer was available. This is the floor on the candidate's pause: an
     * assisted candidate cannot answer faster than the model, but can always be slower.
     */
    private static function latencySignal(array $answered): array
    {
        $ms = array_values(array_filter(array_map(fn ($q) => (int) ($q['latency_ms'] ?? 0), $answered), fn ($v) => $v > 0));
        if (count($ms) < 2) {
            return self::signal('latency', 'Answer available within', 0.0, false, '', 1.0);
        }
        $median = self::median($ms);
        return self::signal(
            'latency',
            'Answer available within',
            self::ramp(self::FAST_ANSWER_MS * 2 - $median, 0, self::FAST_ANSWER_MS * 2),
            true,
            self::seconds($median) . ' (median of ' . count($ms) . ')',
            1.0,
            $median <= self::FAST_ANSWER_MS
        );
    }

    /** Share of answers not grounded directly in the candidate's own evidence. */
    private static function groundingSignal(array $answered): array
    {
        $strengths = array_map(fn ($q) => (string) ($q['answer']['evidence_strength'] ?? ''), $answered);
        $known = array_values(array_filter($strengths, fn ($s) => $s !== ''));
        if (!$known) {
            return self::signal('grounding', 'Answers not grounded in own evidence', 0.0, false, '', 0.8);
        }
        $ungrounded = count(array_filter($known, fn ($s) => $s !== 'direct'));
        $share = $ungrounded / count($known);
        return self::signal(
            'grounding',
            'Answers not grounded in own evidence',
            $share,
            true,
            round($share * 100) . '% (' . $ungrounded . ' of ' . count($known) . ')',
            0.8,
            $share >= 0.5
        );
    }

    /**
     * Humans vary: some answers run long, some are two sentences. Generated ones tend to land on the
     * same shape every time, which shows up as a low coefficient of variation.
     */
    private static function uniformitySignal(array $answered): array
    {
        $shape = [];
        foreach ($answered as $q) {
            $lines = self::answerLines($q['answer']);
            if ($lines) {
                $shape[] = (float) count($lines);
            }
        }
        if (count($shape) < 3) {
            return self::signal('uniformity', 'Answers alike in shape', 0.0, false, '', 0.9);
        }
        $cv = self::coefficientOfVariation($shape);
        return self::signal(
            'uniformity',
            'Answers alike in shape',
            self::ramp(self::UNIFORM_CV * 2 - $cv, 0, self::UNIFORM_CV * 2),
            true,
            'variation ' . number_format($cv, 2) . ' across ' . count($shape) . ' answers',
            0.9,
            $cv <= self::UNIFORM_CV
        );
    }

    /** How much of the answer vocabulary is lifted from the job description. */
    private static function vocabularySignal(array $answered, ?array $job): array
    {
        $jd = self::jobTerms($job);
        $keywords = [];
        foreach ($answered as $q) {
            foreach ((array) ($q['answer']['keywords'] ?? []) as $k) {
                $k = self::normalise((string) $k);
                if ($k !== '') {
                    $keywords[] = $k;
                }
            }
        }
        if (!$jd || count($keywords) < 4) {
            return self::signal('vocabulary', 'Vocabulary echoing the job description', 0.0, false, '', 0.7);
        }
        $hits = count(array_filter($keywords, fn ($k) => self::termMatches($k, $jd)));
        $share = $hits / count($keywords);
        return self::signal(
            'vocabulary',
            'Vocabulary echoing the job description',
            $share,
            true,
            round($share * 100) . '% of ' . count($keywords) . ' keywords',
            0.7,
            $share >= self::JD_ECHO_HIGH
        );
    }

    /**
     * The strongest signal available here: did the candidate RECITE the answer they were shown?
     *
     * Comparing generated text against human speech in general proves nothing — a written answer
     * never says "um", assisted or not. What distinguishes the two is whether the words the
     * candidate produced are the words the assistant handed them. So this measures, per question,
     * how much of what they said already appears in what they were shown.
     *
     * Reciting also flattens delivery, so a near-zero filler rate in their own speech reinforces it.
     * Both are computed from word overlap and need no punctuation, which spoken transcripts lack.
     */
    private static function registerSignal(array $answered): array
    {
        $overlaps = [];
        $ownText = [];
        foreach ($answered as $q) {
            $own = trim((string) ($q['user_answer'] ?? ''));
            $lines = self::answerLines($q['answer']);
            if ($own === '' || !$lines) {
                continue;
            }
            $ownText[] = $own;
            $overlaps[] = self::overlap($own, implode(' ', $lines));
        }
        if (count($overlaps) < 2) {
            return self::signal('register', 'Spoken answer matches the text supplied', 0.0, false, '', 1.2);
        }
        $overlap = array_sum($overlaps) / count($overlaps);
        $filler = self::fillerRate(implode('. ', $ownText));

        // Recited wording, and flat delivery to go with it.
        $levels = [
            self::ramp($overlap, self::RECITE_LOW, self::RECITE_HIGH),
            self::ramp(self::FILLER_GAP - $filler, 0, self::FILLER_GAP),
        ];

        return self::signal(
            'register',
            'Spoken answer matches the text supplied',
            array_sum($levels) / count($levels),
            true,
            round($overlap * 100) . '% of their wording was in the suggested answer; '
                . number_format($filler * 100, 1) . '% fillers in their own speech',
            1.2,
            $overlap >= self::RECITE_HIGH
        );
    }

    /**
     * Share of the content words in $said that also appear in $shown. Short and common words are
     * dropped so the measure is not dominated by "the" and "and".
     */
    public static function overlap(string $said, string $shown): float
    {
        $shownSet = [];
        foreach (self::words(mb_strtolower($shown)) as $w) {
            $shownSet[$w] = true;
        }
        $content = array_values(array_filter(self::words(mb_strtolower($said)), fn ($w) => mb_strlen($w) > 3));
        if (!$content) {
            return 0.0;
        }
        $hits = 0;
        foreach ($content as $w) {
            if (isset($shownSet[$w])) {
                $hits++;
            }
        }
        return $hits / count($content);
    }

    // ================================================================ helpers

    /**
     * @return array<string,mixed>
     */
    private static function signal(string $key, string $label, float $level, bool $measured, string $detail, float $weight, bool $flag = false): array
    {
        return [
            'key'      => $key,
            'label'    => $label,
            'level'    => max(0.0, min(1.0, $level)),
            'measured' => $measured,
            'detail'   => $measured ? $detail : 'not enough data in this session',
            'weight'   => $weight,
            'flag'     => $measured && $flag,
        ];
    }

    /** Every spoken line of an answer: section bullets, or points when there are no sections. */
    public static function answerLines(?array $answer): array
    {
        if (!$answer) {
            return [];
        }
        $lines = [];
        foreach ((array) ($answer['sections'] ?? []) as $sec) {
            foreach ((array) ($sec['bullets'] ?? []) as $b) {
                if (is_string($b) && trim($b) !== '') {
                    $lines[] = $b;
                }
            }
        }
        if (!$lines) {
            foreach ((array) ($answer['points'] ?? []) as $p) {
                if (is_string($p) && trim($p) !== '') {
                    $lines[] = $p;
                }
            }
        }
        return $lines;
    }

    public static function wordsPerSentence(string $text): float
    {
        $n = self::sentenceCount($text);
        return $n ? count(self::words($text)) / $n : 0.0;
    }

    /** Sentences as punctuation marks them. Unpunctuated speech reads as one, which callers check for. */
    public static function sentenceCount(string $text): int
    {
        return count(array_values(array_filter(array_map('trim', preg_split('/[.!?]+/u', $text) ?: []), fn ($s) => $s !== '')));
    }

    public static function fillerRate(string $text): float
    {
        $words = self::words($text);
        if (!$words) {
            return 0.0;
        }
        $lower = ' ' . mb_strtolower(implode(' ', $words)) . ' ';
        $hits = 0;
        foreach (self::FILLERS as $f) {
            $hits += substr_count($lower, ' ' . $f . ' ');
        }
        return $hits / count($words);
    }

    /** @return list<string> */
    public static function words(string $text): array
    {
        return array_values(array_filter(preg_split('/[^\p{L}\p{N}\'-]+/u', $text) ?: [], fn ($w) => $w !== ''));
    }

    public static function median(array $values): float
    {
        if (!$values) {
            return 0.0;
        }
        sort($values);
        $n = count($values);
        $mid = intdiv($n, 2);
        return $n % 2 ? (float) $values[$mid] : ($values[$mid - 1] + $values[$mid]) / 2;
    }

    /** Spread relative to the mean — scale-free, so long and short answers compare fairly. */
    public static function coefficientOfVariation(array $values): float
    {
        $n = count($values);
        if ($n < 2) {
            return 0.0;
        }
        $mean = array_sum($values) / $n;
        if ($mean <= 0.0) {
            return 0.0;
        }
        $var = 0.0;
        foreach ($values as $v) {
            $var += ($v - $mean) ** 2;
        }
        return sqrt($var / $n) / $mean;
    }

    /** Map a raw measurement onto 0..1. */
    private static function ramp(float $value, float $low, float $high): float
    {
        if ($high <= $low) {
            return 0.0;
        }
        return max(0.0, min(1.0, ($value - $low) / ($high - $low)));
    }

    private static function seconds(float $ms): string
    {
        return number_format($ms / 1000, 1) . 's';
    }

    private static function normalise(string $s): string
    {
        return trim(mb_strtolower(preg_replace('/[^\p{L}\p{N} ]+/u', ' ', $s) ?? $s));
    }

    /** @return list<string> */
    private static function jobTerms(?array $job): array
    {
        if (!$job) {
            return [];
        }
        $terms = array_map('trim', explode(',', (string) ($job['main_skills'] ?? '')));
        $summary = !empty($job['jd_summary_json']) ? json_decode((string) $job['jd_summary_json'], true) : null;
        if (is_array($summary)) {
            foreach (['required_skills', 'tools', 'competencies', 'technical_requirements'] as $k) {
                $terms = array_merge($terms, (array) ($summary[$k] ?? []));
            }
        }
        $out = [];
        foreach ($terms as $t) {
            $t = self::normalise((string) $t);
            if ($t !== '' && mb_strlen($t) > 2) {
                $out[$t] = true;
            }
        }
        return array_keys($out);
    }

    /** A keyword counts as echoing the job description if either contains the other. */
    private static function termMatches(string $keyword, array $jdTerms): bool
    {
        foreach ($jdTerms as $t) {
            if ($keyword === $t || str_contains($t, $keyword) || str_contains($keyword, $t)) {
                return true;
            }
        }
        return false;
    }
}
