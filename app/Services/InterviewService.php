<?php

declare(strict_types=1);

namespace App\Services;

use App\Core\Database;
use App\Core\HttpException;
use App\Core\Logger;
use App\Models\CV;
use App\Models\InterviewSession;
use App\Models\Settings;
use App\Models\UsageLog;

/**
 * Question detection, answer generation, practice questions and practice feedback.
 *
 * One Responses API call both (a) decides whether the transcript contains a real interview question
 * and cleans it up, and (b) produces glanceable, CV-grounded answer guidance as strict JSON.
 * Static context (instructions, candidate profile, job) comes first so OpenAI prompt caching applies.
 */
final class InterviewService
{
    public const QUESTION_TYPES = ['behavioural', 'technical', 'situational', 'leadership', 'competency', 'motivation', 'career_history', 'salary_hr', 'problem_solving', 'management', 'communication', 'general', 'unknown'];
    public const ANSWER_MODES = ['quick', 'star', 'technical', 'leadership', 'general'];
    public const USER_MODES = ['auto', 'quick', 'star', 'technical', 'leadership'];

    private const MAX_JD_CHARS = 5000;
    private const MAX_CV_FALLBACK_CHARS = 9000;

    public function __construct(private readonly OpenAIClient $ai = new OpenAIClient())
    {
    }

    // ================================================================ question heuristics

    /**
     * Cheap pre-filter run before calling the model. Returns false only for transcripts that are
     * obviously not questions (empty, filler, pleasantries). Ambiguous text is left to the model.
     */
    public static function plausibleQuestion(string $text): bool
    {
        $t = mb_strtolower(trim($text));
        $t = trim(preg_replace('/[^\p{L}\p{N}\s\?\']/u', ' ', $t) ?? '');
        $t = preg_replace('/\s+/', ' ', $t) ?? $t;
        if ($t === '' || mb_strlen($t) < 8) {
            return false;
        }
        $words = explode(' ', $t);
        if (count($words) < 3 && !str_contains($text, '?')) {
            return false;
        }
        $pleasantries = [
            'okay thank you', 'ok thank you', 'thank you', 'thank you very much', 'thanks', 'thanks a lot', 'okay thank you very much',
            'great thank you', 'right okay', 'okay great', 'sounds good', 'perfect thank you', 'nice to meet you', 'good morning',
            'good afternoon', 'hello', 'hi there', 'can you hear me', 'you are on mute', 'thank you for your time', 'bye', 'goodbye',
        ];
        $stripped = trim(str_replace('?', '', $t));
        return !in_array($stripped, $pleasantries, true);
    }

    // ================================================================ answer generation

    /**
     * @param array<string,mixed> $user
     * @param array<string,mixed> $session interview_sessions row
     * @param int|null $existingQuestionId practice question to attach the guidance to (instead of inserting a new row)
     * @return array<string,mixed> {is_question, question, answer, question_id, ...}
     */
    public function generateAnswer(array $user, array $session, string $transcript, string $mode, string $source, ?int $existingQuestionId = null): array
    {
        $userId = (int) $user['id'];
        $settings = Settings::forUser($userId);
        $transcript = trim(mb_substr(preg_replace('/\s+/u', ' ', $transcript) ?? $transcript, 0, 4000));
        $mode = in_array($mode, self::USER_MODES, true) ? $mode : (string) $settings['default_answer_mode'];
        $detail = (string) $settings['response_detail'];
        $autoDetect = (bool) $settings['auto_detect_question'] && $source !== 'typed';

        if ($transcript === '') {
            throw new HttpException(422, "We couldn't hear the question clearly.", ['error_kind' => 'empty']);
        }
        if ($autoDetect && !self::plausibleQuestion($transcript)) {
            return ['is_question' => false, 'question' => '', 'raw_transcript' => $transcript, 'reason' => 'No interview question detected yet.'];
        }

        $started = microtime(true);
        $job = $session['job_id'] ? Database::fetch('SELECT * FROM jobs WHERE id = ? AND user_id = ?', [(int) $session['job_id'], $userId]) : null;
        $cv = CV::forUser($userId);
        $history = $this->recentContext($session, (bool) $settings['save_history']);

        $payload = $this->buildAnswerPayload($transcript, $mode, $detail, $job, $cv, $history, $autoDetect);
        $answer = $this->callJson($userId, 'answer', $payload, fn (array $d) => self::normaliseAnswer($d, $mode));

        if (!$answer['is_question']) {
            return ['is_question' => false, 'question' => '', 'raw_transcript' => $transcript, 'reason' => 'No interview question detected yet.'];
        }

        $latency = (int) round((microtime(true) - $started) * 1000);
        $questionId = null;
        if ($existingQuestionId !== null) {
            $questionId = $existingQuestionId;
            InterviewSession::updateQuestion($existingQuestionId, ['answer_json' => $answer, 'answer_mode' => $mode]);
        } elseif ($settings['save_history']) {
            $questionId = InterviewSession::addQuestion((int) $session['id'], [
                'question'       => $answer['question'],
                'raw_transcript' => $source === 'typed' ? null : $transcript,
                'question_type'  => $answer['question_type'],
                'answer_mode'    => $mode,
                'answer'         => $answer,
                'source'         => $source,
                'latency_ms'     => $latency,
            ]);
        } else {
            $this->rememberInSession((int) $session['id'], $answer);
        }

        return [
            'is_question'    => true,
            'question'       => $answer['question'],
            'raw_transcript' => $transcript,
            'answer'         => $answer,
            'question_id'    => $questionId,
            'latency_ms'     => $latency,
            'saved'          => (bool) $settings['save_history'],
        ];
    }

    /** @return list<array{question:string,key_message:string}> */
    private function recentContext(array $session, bool $fromDb): array
    {
        if ($fromDb) {
            return InterviewSession::recentContext((int) $session['id'], 3);
        }
        return array_slice($_SESSION['interview_ctx'][(int) $session['id']] ?? [], -3);
    }

    private function rememberInSession(int $sessionId, array $answer): void
    {
        $ctx = $_SESSION['interview_ctx'][$sessionId] ?? [];
        $ctx[] = ['question' => $answer['question'], 'key_message' => $answer['key_message']];
        $_SESSION['interview_ctx'] = [$sessionId => array_slice($ctx, -3)];
    }

    /** @return array<string,mixed> */
    public function buildAnswerPayload(string $transcript, string $mode, string $detail, ?array $job, ?array $cv, array $history, bool $autoDetect): array
    {
        $model = (string) config('openai.answer_model');
        $context = "## CANDIDATE PROFILE (from their CV — the ONLY source of facts about the candidate)\n" . self::candidateContext($cv)
            . "\n\n## TARGET ROLE\n" . self::jobContext($job);

        $turn = [];
        if ($history) {
            $turn[] = "## EARLIER QUESTIONS IN THIS INTERVIEW (stay consistent; do not repeat the same example unless asked)";
            foreach ($history as $i => $h) {
                $turn[] = ($i + 1) . '. ' . mb_substr($h['question'], 0, 300) . ($h['key_message'] ? ' → ' . mb_substr($h['key_message'], 0, 160) : '');
            }
            $turn[] = '';
        }
        $turn[] = '## ANSWER SETTINGS';
        $turn[] = 'Requested mode: ' . strtoupper($mode) . ' — ' . self::modeInstruction($mode);
        $turn[] = 'Detail level: ' . ($detail === 'medium'
            ? 'MEDIUM — bullets up to ~18 words, 2-3 bullets per section.'
            : 'SHORT — bullets up to ~12 words, 1-2 bullets per section (Action may have 3).');
        $turn[] = $autoDetect
            ? 'The text below is a live speech transcript. It may contain filler, small talk or background talk. Set is_question=false if it does not contain an interview question directed at the candidate.'
            : 'The candidate typed this question themselves: treat it as a question (is_question=true) and clean up wording only.';
        $turn[] = '';
        $turn[] = "## TRANSCRIPT\n\"\"\"\n" . $transcript . "\n\"\"\"";

        $payload = [
            'model'        => $model,
            'store'        => false,
            'instructions' => self::ANSWER_INSTRUCTIONS,
            'input'        => [
                ['role' => 'user', 'content' => [
                    ['type' => 'input_text', 'text' => $context],
                    ['type' => 'input_text', 'text' => implode("\n", $turn)],
                ]],
            ],
            'text' => ['format' => [
                'type'   => 'json_schema',
                'name'   => 'interview_answer',
                'strict' => true,
                'schema' => self::answerSchema(),
            ]],
            'max_output_tokens' => (int) config('openai.max_output_tokens.' . $detail, 900),
        ];
        $this->applyReasoning($payload);
        return $payload;
    }

    private static function modeInstruction(string $mode): string
    {
        return match ($mode) {
            'quick'      => 'answer_mode="quick". Put 3-5 short bullets in points. sections must be [].',
            'star'       => 'answer_mode="star". sections exactly: Situation, Task, Action, Result. points = 2-3 headline bullets.',
            'technical'  => 'answer_mode="technical". sections exactly: Concept, Steps, Example, Conclusion. points = 2-3 headline bullets.',
            'leadership' => 'answer_mode="leadership". sections exactly: Challenge, Decision, Action, People, Result. points = 2-3 headline bullets.',
            default      => 'AUTO: classify the question and choose the structure: '
                . 'behavioural/competency → answer_mode "star" with sections Situation, Task, Action, Result; '
                . 'technical/problem_solving → answer_mode "technical" with sections Concept, Approach, Practical example, Risk / consideration, Conclusion; '
                . 'leadership/management → answer_mode "leadership" with sections Context, Leadership decision, Action, People management, Outcome, Lesson; '
                . 'situational → "star" (use a real past example if the CV has one, otherwise describe the approach); '
                . 'everything else (motivation, career_history, salary_hr, communication, general) → answer_mode "general" with sections Direct answer, Supporting point, Evidence, Conclusion. '
                . 'points = 2-3 headline bullets.',
        };
    }

    public static function candidateContext(?array $cv): string
    {
        if (!$cv) {
            return 'NO CV UPLOADED. You have no facts about the candidate. Give approaches and say what kind of example to use; never invent experience.';
        }
        $profile = CV::profile($cv);
        if ($profile) {
            unset($profile['plain_text']);
            $json = json_encode(self::compactProfile($profile), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
            return (string) $json;
        }
        if (!empty($cv['cv_text'])) {
            return mb_substr((string) $cv['cv_text'], 0, self::MAX_CV_FALLBACK_CHARS);
        }
        return 'CV uploaded but could not be read. Give approaches only; never invent experience.';
    }

    /** Drop empty fields to save tokens. */
    private static function compactProfile(array $p): array
    {
        return array_filter($p, fn ($v) => $v !== '' && $v !== [] && $v !== null);
    }

    public static function jobContext(?array $job): string
    {
        if (!$job) {
            return 'No specific job selected. Give strong general answers.';
        }
        $lines = [
            'Title: ' . $job['title'],
            $job['company'] ? 'Company: ' . $job['company'] : null,
            $job['industry'] ? 'Industry: ' . $job['industry'] : null,
            'Seniority: ' . label_for('seniority', $job['seniority']),
            'Interview type: ' . label_for('interview_type', $job['interview_type']),
            $job['main_skills'] ? 'Main skills required: ' . $job['main_skills'] : null,
        ];
        $summary = !empty($job['jd_summary_json']) ? json_decode((string) $job['jd_summary_json'], true) : null;
        if (is_array($summary)) {
            $lines[] = 'Job description analysis: ' . json_encode(array_filter($summary), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        } else {
            $lines[] = "Job description:\n" . mb_substr((string) $job['description'], 0, self::MAX_JD_CHARS);
        }
        return implode("\n", array_filter($lines));
    }

    private const ANSWER_INSTRUCTIONS = <<<TXT
You are an interview response coach.

Your job is to help the candidate quickly structure an authentic answer using information contained in their CV and interview context.

Never invent employment history, qualifications, achievements, employers, numbers or experience.

When evidence is unavailable, provide a suggested approach rather than pretending the candidate has the experience.

Answers must be concise enough for the candidate to scan within seconds.

Process:
1. QUESTION DETECTION: Decide if the transcript contains an interview question (or a request such as "Tell me about...", "Walk me through...") directed at the candidate. Small talk, thanks, logistics, or the interviewer describing the company are NOT questions (is_question=false). If several sentences precede the question, extract only the actual question. Fix transcription errors and return it as one clean sentence in "question". When is_question=false return empty strings/arrays for every other text field and question_type "unknown".
2. CLASSIFY question_type.
3. Compare the question against the job requirements (skills, competencies, tools, leadership and technical requirements) and pick the MOST relevant CV evidence.
4. WRITE the guidance:
   - key_message: one sentence — what the answer must demonstrate.
   - points: 2-5 glanceable bullets (fragments, not paragraphs; start with a verb or keyword).
   - sections: labelled structure per the requested mode, each with short bullets. Bullets are prompts the candidate speaks from, in first person where natural.
   - Use real names, employers, tools and numbers ONLY when they appear in the candidate profile. If no metric exists, say "mention a measurable result if you have one" — never make one up.
   - cv_evidence: up to 4 short facts copied/paraphrased from the CV that support this answer (empty if none).
   - evidence_note: if the CV has no relevant evidence, one short sentence saying so and what kind of example to use; otherwise "".
   - closing_line: one natural spoken sentence to end the answer.
   - keywords: 3-6 single words or short phrases to emphasise (prefer job-description vocabulary).
No markdown, no emojis, no long sentences.
TXT;

    /** @return array<string,mixed> strict JSON schema for answer guidance */
    public static function answerSchema(): array
    {
        $strArr = ['type' => 'array', 'items' => ['type' => 'string']];
        return [
            'type' => 'object',
            'additionalProperties' => false,
            'required' => ['is_question', 'question', 'question_type', 'answer_mode', 'key_message', 'points', 'sections', 'cv_evidence', 'evidence_note', 'closing_line', 'keywords'],
            'properties' => [
                'is_question'   => ['type' => 'boolean'],
                'question'      => ['type' => 'string'],
                'question_type' => ['type' => 'string', 'enum' => self::QUESTION_TYPES],
                'answer_mode'   => ['type' => 'string', 'enum' => self::ANSWER_MODES],
                'key_message'   => ['type' => 'string'],
                'points'        => $strArr,
                'sections'      => ['type' => 'array', 'items' => [
                    'type' => 'object', 'additionalProperties' => false,
                    'required' => ['label', 'bullets'],
                    'properties' => ['label' => ['type' => 'string'], 'bullets' => $strArr],
                ]],
                'cv_evidence'   => $strArr,
                'evidence_note' => ['type' => 'string'],
                'closing_line'  => ['type' => 'string'],
                'keywords'      => $strArr,
            ],
        ];
    }

    /**
     * Validate + normalise model output. Throws when the structure is unusable.
     * Adds a "star" object for STAR answers (matches the documented response format).
     * @return array<string,mixed>
     */
    public static function normaliseAnswer(array $d, string $requestedMode = 'auto'): array
    {
        if (!array_key_exists('is_question', $d) || !array_key_exists('question', $d)) {
            throw new \UnexpectedValueException('Missing required fields');
        }
        $s = fn ($v, $max = 400) => mb_substr(trim(preg_replace('/\s+/u', ' ', is_scalar($v) ? (string) $v : '') ?? ''), 0, $max);
        $list = fn ($v, $n, $max = 220) => array_values(array_slice(array_filter(array_map(fn ($x) => $s($x, $max), is_array($v) ? $v : []), fn ($x) => $x !== ''), 0, $n));

        $isQuestion = filter_var($d['is_question'], FILTER_VALIDATE_BOOLEAN);
        $question = $s($d['question'] ?? '', 600);
        if (!$isQuestion || $question === '') {
            return ['is_question' => false, 'question' => ''];
        }

        $sections = [];
        foreach (array_slice(is_array($d['sections'] ?? null) ? $d['sections'] : [], 0, 7) as $sec) {
            if (!is_array($sec)) {
                continue;
            }
            $label = $s($sec['label'] ?? '', 40);
            $bullets = $list($sec['bullets'] ?? [], 4);
            if ($label !== '' && $bullets) {
                $sections[] = ['label' => $label, 'bullets' => $bullets];
            }
        }
        $points = $list($d['points'] ?? [], 6);
        if (!$points && !$sections) {
            throw new \UnexpectedValueException('Answer has no content');
        }

        $type = in_array($d['question_type'] ?? '', self::QUESTION_TYPES, true) ? $d['question_type'] : 'unknown';
        $mode = in_array($d['answer_mode'] ?? '', self::ANSWER_MODES, true) ? $d['answer_mode']
            : ($requestedMode !== 'auto' && in_array($requestedMode, self::ANSWER_MODES, true) ? $requestedMode : 'general');

        $out = [
            'is_question'   => true,
            'question'      => $question,
            'question_type' => $type,
            'answer_mode'   => $mode,
            'key_message'   => $s($d['key_message'] ?? '', 300),
            'points'        => $points,
            'sections'      => $mode === 'quick' ? [] : $sections,
            'cv_evidence'   => $list($d['cv_evidence'] ?? [], 4, 260),
            'evidence_note' => $s($d['evidence_note'] ?? '', 300),
            'closing_line'  => trim($s($d['closing_line'] ?? '', 300), '"“”'),
            'keywords'      => $list($d['keywords'] ?? [], 6, 40),
        ];
        if ($mode === 'quick' && !$out['points'] && $sections) {
            $out['points'] = array_slice(array_merge(...array_column($sections, 'bullets')), 0, 5);
        }
        if ($mode === 'star') {
            $find = function (string $label) use ($sections): array {
                foreach ($sections as $sec) {
                    if (strcasecmp($sec['label'], $label) === 0) {
                        return $sec['bullets'];
                    }
                }
                return [];
            };
            $out['star'] = [
                'situation' => implode(' ', $find('Situation')),
                'task'      => implode(' ', $find('Task')),
                'action'    => $find('Action'),
                'result'    => implode(' ', $find('Result')),
            ];
        }
        return $out;
    }

    /** Remove code fences / leading text around a JSON object. */
    public static function stripJson(string $text): string
    {
        $text = trim($text);
        if (str_starts_with($text, '```')) {
            $text = preg_replace('/^```[a-zA-Z]*\s*|\s*```$/', '', $text) ?? $text;
        }
        $start = strpos($text, '{');
        $end = strrpos($text, '}');
        if ($start !== false && $end !== false && $end > $start) {
            return substr($text, $start, $end - $start + 1);
        }
        return $text;
    }

    /**
     * Call the Responses API expecting JSON; validates with $normalise and retries once on malformed output.
     * @param callable(array):array $normalise
     */
    private function callJson(int $userId, string $action, array $payload, callable $normalise): array
    {
        $attempts = 0;
        while (true) {
            $attempts++;
            $res = $this->ai->createResponse($payload);
            $usage = OpenAIClient::usage($res);
            UsageLog::record($userId, $action, (string) $payload['model'], $usage['input'], $usage['output']);
            try {
                $data = json_decode(self::stripJson(OpenAIClient::outputText($res)), true, 32);
                if (!is_array($data)) {
                    throw new \UnexpectedValueException('Invalid JSON');
                }
                return $normalise($data);
            } catch (\UnexpectedValueException $e) {
                Logger::warning('Malformed AI JSON', ['action' => $action, 'attempt' => $attempts, 'error' => $e->getMessage(), 'status' => $res['status'] ?? null]);
                if ($attempts >= 2) {
                    throw new OpenAIException(502, 'The AI returned an incomplete answer. Please try again.', 'invalid_json');
                }
            }
        }
    }

    // ================================================================ job description analysis

    /** Analyse the JD once and cache it on the job row (used instead of the raw JD during interviews). */
    public function ensureJobSummary(int $userId, ?array $job): ?array
    {
        if (!$job || !empty($job['jd_summary_json']) || mb_strlen((string) $job['description']) < 400) {
            return $job;
        }
        try {
            $model = (string) config('openai.answer_model');
            $payload = [
                'model' => $model,
                'store' => false,
                'instructions' => 'Extract the hiring requirements from this job description as compact JSON. Use short phrases. Only include what the text states or clearly implies.',
                'input' => [['role' => 'user', 'content' => [['type' => 'input_text', 'text' => "Job title: {$job['title']}\n\n" . mb_substr((string) $job['description'], 0, 15000)]]]],
                'text' => ['format' => ['type' => 'json_schema', 'name' => 'job_requirements', 'strict' => true, 'schema' => self::jdSchema()]],
                'max_output_tokens' => 1500,
            ];
            $this->applyReasoning($payload);
            $summary = $this->callJson($userId, 'jd_summary', $payload, function (array $d) {
                $out = [];
                foreach (array_keys(self::jdSchema()['properties']) as $k) {
                    $v = $d[$k] ?? ($k === 'summary' ? '' : []);
                    $out[$k] = is_array($v)
                        ? array_values(array_slice(array_filter(array_map(fn ($x) => mb_substr(trim((string) (is_scalar($x) ? $x : '')), 0, 160), $v)), 0, 12))
                        : mb_substr(trim((string) $v), 0, 500);
                }
                if (!$out['required_skills'] && !$out['key_responsibilities']) {
                    throw new \UnexpectedValueException('Empty job summary');
                }
                return $out;
            });
            Database::execute('UPDATE jobs SET jd_summary_json = ? WHERE id = ? AND user_id = ?', [json_encode($summary, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES), (int) $job['id'], $userId]);
            $job['jd_summary_json'] = json_encode($summary);
        } catch (\Throwable $e) {
            // Non-fatal: interviews fall back to the raw (truncated) job description.
            Logger::warning('Job description analysis skipped', ['error' => $e->getMessage()]);
        }
        return $job;
    }

    public static function jdSchema(): array
    {
        $strArr = ['type' => 'array', 'items' => ['type' => 'string']];
        return [
            'type' => 'object', 'additionalProperties' => false,
            'required' => ['summary', 'required_skills', 'competencies', 'tools', 'leadership_requirements', 'technical_requirements', 'key_responsibilities'],
            'properties' => [
                'summary' => ['type' => 'string'],
                'required_skills' => $strArr, 'competencies' => $strArr, 'tools' => $strArr,
                'leadership_requirements' => $strArr, 'technical_requirements' => $strArr, 'key_responsibilities' => $strArr,
            ],
        ];
    }

    // ================================================================ practice mode

    /** @return array{question:string,question_type:string,why_asked:string,tip:string} */
    public function practiceQuestion(array $user, array $session, ?array $job, array $previous, ?string $focus = null): array
    {
        $userId = (int) $user['id'];
        $cv = CV::forUser($userId);
        $prev = $previous ? "Already asked (do NOT repeat or paraphrase):\n- " . implode("\n- ", array_map(fn ($q) => mb_substr($q, 0, 200), array_slice($previous, -15))) : 'No questions asked yet — start with a natural opening question.';
        $focusLine = $focus && in_array($focus, self::QUESTION_TYPES, true) ? "Focus on a $focus question." : 'Vary the question types across the interview (behavioural, technical, motivation, situational, leadership as appropriate to the role).';

        $payload = [
            'model' => (string) config('openai.answer_model'),
            'store' => false,
            'instructions' => 'You are an experienced interviewer for the target role. Ask ONE realistic interview question at a time, tailored to the job description, seniority and the candidate\'s CV (e.g. probe a role or claim on their CV). Keep the question to one or two sentences.',
            'input' => [['role' => 'user', 'content' => [
                ['type' => 'input_text', 'text' => "## CANDIDATE PROFILE\n" . self::candidateContext($cv) . "\n\n## TARGET ROLE\n" . self::jobContext($job)],
                ['type' => 'input_text', 'text' => $prev . "\n\n" . $focusLine],
            ]]],
            'text' => ['format' => ['type' => 'json_schema', 'name' => 'practice_question', 'strict' => true, 'schema' => [
                'type' => 'object', 'additionalProperties' => false,
                'required' => ['question', 'question_type', 'why_asked', 'tip'],
                'properties' => [
                    'question' => ['type' => 'string'],
                    'question_type' => ['type' => 'string', 'enum' => self::QUESTION_TYPES],
                    'why_asked' => ['type' => 'string', 'description' => 'What the interviewer is testing, one short sentence'],
                    'tip' => ['type' => 'string', 'description' => 'One short coaching tip'],
                ],
            ]]],
            'max_output_tokens' => 600,
        ];
        $this->applyReasoning($payload);

        return $this->callJson($userId, 'practice_question', $payload, function (array $d) {
            $q = trim((string) ($d['question'] ?? ''));
            if ($q === '') {
                throw new \UnexpectedValueException('Empty question');
            }
            return [
                'question'      => mb_substr($q, 0, 500),
                'question_type' => in_array($d['question_type'] ?? '', self::QUESTION_TYPES, true) ? $d['question_type'] : 'general',
                'why_asked'     => mb_substr(trim((string) ($d['why_asked'] ?? '')), 0, 300),
                'tip'           => mb_substr(trim((string) ($d['tip'] ?? '')), 0, 300),
            ];
        });
    }

    /** Analyse the candidate's spoken/typed practice answer. @return array<string,mixed> */
    public function practiceFeedback(array $user, array $session, string $question, string $answerText): array
    {
        $userId = (int) $user['id'];
        $cv = CV::forUser($userId);
        $job = $session['job_id'] ? Database::fetch('SELECT * FROM jobs WHERE id = ? AND user_id = ?', [(int) $session['job_id'], $userId]) : null;
        $strArr = ['type' => 'array', 'items' => ['type' => 'string']];
        $payload = [
            'model' => (string) config('openai.answer_model'),
            'store' => false,
            'instructions' => "You are a supportive but honest interview coach. Evaluate the candidate's answer to the question for the target role. "
                . "Be specific and brief. The improved answer must only use facts from the candidate's answer or CV profile — never invent experience or numbers; use [placeholders] where a metric would help.",
            'input' => [['role' => 'user', 'content' => [
                ['type' => 'input_text', 'text' => "## CANDIDATE PROFILE\n" . self::candidateContext($cv) . "\n\n## TARGET ROLE\n" . self::jobContext($job)],
                ['type' => 'input_text', 'text' => "## QUESTION\n$question\n\n## CANDIDATE'S ANSWER (transcribed)\n\"\"\"\n" . mb_substr($answerText, 0, 6000) . "\n\"\"\""],
            ]]],
            'text' => ['format' => ['type' => 'json_schema', 'name' => 'practice_feedback', 'strict' => true, 'schema' => [
                'type' => 'object', 'additionalProperties' => false,
                'required' => ['score', 'summary', 'strengths', 'missing_points', 'better_structure', 'improved_answer'],
                'properties' => [
                    'score' => ['type' => 'integer', 'description' => '1-10 overall'],
                    'summary' => ['type' => 'string'],
                    'strengths' => $strArr,
                    'missing_points' => $strArr,
                    'better_structure' => $strArr,
                    'improved_answer' => ['type' => 'string', 'description' => 'Spoken-style improved answer, max ~150 words'],
                ],
            ]]],
            'max_output_tokens' => 1400,
        ];
        $this->applyReasoning($payload);

        return $this->callJson($userId, 'practice_feedback', $payload, function (array $d) {
            $l = fn ($v) => array_values(array_slice(array_filter(array_map(fn ($x) => mb_substr(trim((string) (is_scalar($x) ? $x : '')), 0, 260), is_array($v) ? $v : [])), 0, 5));
            if (!isset($d['improved_answer'])) {
                throw new \UnexpectedValueException('Missing improved answer');
            }
            return [
                'score'            => max(1, min(10, (int) ($d['score'] ?? 5))),
                'summary'          => mb_substr(trim((string) ($d['summary'] ?? '')), 0, 400),
                'strengths'        => $l($d['strengths'] ?? []),
                'missing_points'   => $l($d['missing_points'] ?? []),
                'better_structure' => $l($d['better_structure'] ?? []),
                'improved_answer'  => mb_substr(trim((string) $d['improved_answer']), 0, 1500),
            ];
        });
    }

    /** Add the configured reasoning effort; reasoning tokens count toward max_output_tokens. */
    private function applyReasoning(array &$payload): void
    {
        $effort = (string) config('openai.answer_reasoning', '');
        if ($effort !== '') {
            $payload['reasoning'] = ['effort' => $effort];
            if ($effort !== 'none') {
                $payload['max_output_tokens'] += 2000;
            }
        }
    }
}
