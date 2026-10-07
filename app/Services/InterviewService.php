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
    public const ANSWER_MODES = ['quick', 'star', 'technical', 'leadership', 'general', 'written'];
    public const USER_MODES = ['auto', 'quick', 'star', 'technical', 'leadership', 'written'];
    /** How well the CV backs an answer: exact experience, a bridged one, or nothing to build on. */
    public const EVIDENCE_STRENGTHS = ['direct', 'adjacent', 'general'];

    /** Modes that produce a typed/written answer rather than lines to say out loud. */
    public const WRITTEN_MODES = ['written'];

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

    // ================================================================ transcription vocabulary

    /**
     * Names and terms the speech-to-text model should expect (projects, tools, employers, certifications,
     * job title/company/skills). Helps it hear "Eval360" instead of "Harvard 360".
     */
    public static function vocabularyHint(int $userId, ?array $job, int $maxChars = 600): string
    {
        $terms = [];
        $profile = CV::profile(CV::forUser($userId));
        if ($profile) {
            foreach ($profile['projects'] ?? [] as $p) { $terms[] = $p['name'] ?? ''; }
            foreach ($profile['roles'] ?? [] as $r) { $terms[] = $r['employer'] ?? ''; }
            $terms = array_merge($terms, $profile['tools'] ?? [], $profile['certifications'] ?? [], array_slice($profile['skills'] ?? [], 0, 15));
        }
        if ($job) {
            $terms[] = $job['company'] ?? '';
            $terms[] = $job['title'] ?? '';
            $terms = array_merge($terms, array_map('trim', explode(',', (string) ($job['main_skills'] ?? ''))));
            $summary = !empty($job['jd_summary_json']) ? json_decode((string) $job['jd_summary_json'], true) : null;
            if (is_array($summary)) {
                $terms = array_merge($terms, $summary['tools'] ?? [], array_slice($summary['required_skills'] ?? [], 0, 8));
            }
        }
        $seen = [];
        $out = '';
        foreach ($terms as $t) {
            $t = trim(preg_replace('/\s+/', ' ', (string) $t) ?? '');
            $key = mb_strtolower($t);
            if ($t === '' || mb_strlen($t) > 40 || isset($seen[$key])) {
                continue;
            }
            if (mb_strlen($out) + mb_strlen($t) + 2 > $maxChars) {
                break;
            }
            $seen[$key] = true;
            $out .= ($out === '' ? '' : ', ') . $t;
        }
        return $out;
    }

    /** Full transcription prompt for a session: who is speaking + expected vocabulary. */
    public static function transcriptionPrompt(int $userId, ?array $session): string
    {
        $job = !empty($session['job_id']) ? Database::fetch('SELECT * FROM jobs WHERE id = ? AND user_id = ?', [(int) $session['job_id'], $userId]) : null;
        $role = $session['job_title'] ?? null;
        $lead = ($session['session_type'] ?? 'live') === 'practice'
            ? 'A candidate answering a job interview question' . ($role ? " for the role of $role" : '') . '.'
            : 'A job interview' . ($role ? " for the role of $role" : '') . (!empty($session['company']) ? ' at ' . $session['company'] : '') . '. The interviewer asks a question.';
        $vocab = self::vocabularyHint($userId, $job);
        return $vocab === '' ? $lead : $lead . ' Names and terms that may be mentioned: ' . $vocab . '.';
    }

    // ================================================================ answer generation

    /**
     * @param array<string,mixed> $user
     * @param array<string,mixed> $session interview_sessions row
     * @param int|null $existingQuestionId practice question to attach the guidance to (instead of inserting a new row)
     * @param callable(string):void|null $onDelta receives raw JSON text deltas while the answer streams
     * @return array<string,mixed> {is_question, question, answer, question_id, ...}
     */
    public function generateAnswer(array $user, array $session, string $transcript, string $mode, string $source, ?int $existingQuestionId = null, ?callable $onDelta = null): array
    {
        $userId = (int) $user['id'];
        $settings = Settings::forUser($userId);
        $transcript = trim(mb_substr(preg_replace('/\s+/u', ' ', $transcript) ?? $transcript, 0, 4000));
        $mode = in_array($mode, self::USER_MODES, true) ? $mode : (string) $settings['default_answer_mode'];
        $detail = (string) $settings['response_detail'];
        // Auto-detection is a filter for live speech only: typed, practice and scanned questions
        // were handed to us deliberately, so they are always treated as questions.
        $autoDetect = (bool) $settings['auto_detect_question'] && in_array($source, ['live', 'recorded'], true);

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

        $payload = $this->buildAnswerPayload($transcript, $mode, $detail, $job, $cv, $history, $autoDetect, $session['instructions'] ?? null);
        $answer = $this->callJson($userId, 'answer', $payload, fn (array $d) => self::normaliseAnswer($d, $mode), $onDelta);

        if (!$answer['is_question']) {
            return ['is_question' => false, 'question' => '', 'raw_transcript' => $transcript, 'reason' => 'No interview question detected yet.'];
        }

        $latency = (int) round((microtime(true) - $started) * 1000);
        $questionId = null;
        if ($existingQuestionId !== null) {
            $questionId = $existingQuestionId;
            InterviewSession::updateQuestion($existingQuestionId, [
                'answer_json'   => $answer,
                'answer_mode'   => $answer['answer_mode'],
                'question_type' => $answer['question_type'],
            ]);
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
    public function buildAnswerPayload(string $transcript, string $mode, string $detail, ?array $job, ?array $cv, array $history, bool $autoDetect, ?string $instructions = null): array
    {
        $model = (string) config('openai.answer_model');
        $instructions = trim((string) $instructions);
        $context = "## CANDIDATE PROFILE (from their CV — " . ($instructions !== '' ? 'together with the candidate\'s own instructions below, the ONLY source' : 'the ONLY source') . " of facts about the candidate)\n"
            . self::candidateContext($cv)
            . "\n\n## TARGET ROLE\n" . self::jobContext($job);
        if ($instructions !== '') {
            $context .= "\n\n## CANDIDATE'S OWN INSTRUCTIONS AND EXTRA EXPERIENCE\n"
                . "The candidate wrote this themselves about their own career. Follow it whenever it applies to the question (which project or example to use, what to emphasise or avoid, tone). "
                . "It overrides your default choices.\n"
                . "ANY EXPERIENCE STATED HERE IS FIRST-HAND AND AS AUTHORITATIVE AS THE CV — a CV is a summary and routinely leaves work out, so this is where the candidate fills the gaps. "
                . "Treat it as fact: answer from it with the SAME confidence as a CV fact, set evidence_strength to \"direct\", and do NOT hedge, qualify or say they have not done it. "
                . "Write the detail around it the way you would for any real project: how they approached it, what they weighed up, what it taught them. "
                . "Where a specific they have not given you is needed — a metric, a date, an employer — leave a short square-bracket gap for them to fill rather than inventing one.\n"
                . "\"\"\"\n" . mb_substr($instructions, 0, InterviewSession::MAX_INSTRUCTIONS) . "\n\"\"\"";
        }

        $turn = [];
        if ($history) {
            $turn[] = "## EARLIER QUESTIONS IN THIS INTERVIEW (stay consistent; do not repeat the same example unless asked)";
            foreach ($history as $i => $h) {
                $turn[] = ($i + 1) . '. ' . mb_substr($h['question'], 0, 300) . ($h['key_message'] ? ' → ' . mb_substr($h['key_message'], 0, 160) : '');
            }
            $turn[] = '';
        }
        $written = self::isWritten($mode);
        $turn[] = '## ANSWER SETTINGS';
        $turn[] = 'Requested mode: ' . strtoupper($mode) . ' — ' . self::modeInstruction($mode);
        $turn[] = 'Detail level: ' . match (true) {
            $written && $detail === 'medium' => 'MEDIUM — 3-5 sentences per paragraph.',
            $written                        => 'SHORT — 2-3 sentences per paragraph.',
            $detail === 'medium'            => 'MEDIUM — 2-3 sentences per section, each sentence up to ~22 words.',
            default                         => 'SHORT — 1-2 sentences per section (Action may have 3), each sentence up to ~18 words.',
        };
        $turn[] = match (true) {
            $autoDetect => 'The text below is a live speech transcript. It may contain filler, small talk or background talk. Set is_question=false if it does not contain an interview question directed at the candidate.',
            $written    => 'The text below was read off a question paper the candidate has to answer. Treat it as a question (is_question=true) and keep its wording; fix only scanning slips.',
            default     => 'The candidate typed this question themselves: treat it as a question (is_question=true) and clean up wording only.',
        };
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
            'max_output_tokens' => $written
                ? (int) config('openai.max_output_tokens.written', 2600)
                : (int) config('openai.max_output_tokens.' . $detail, 900),
        ];
        $this->applyReasoning($payload);
        return $payload;
    }

    public static function isWritten(string $mode): bool
    {
        return in_array($mode, self::WRITTEN_MODES, true);
    }

    private static function modeInstruction(string $mode): string
    {
        $technical = 'sections exactly: "Approach" (ONE sentence naming the 3-4 areas you will cover, e.g. "I would approach this by isolating the regression, finding the bottleneck, fixing it and validating the result."), '
            . '"Key points" (3-4 sentences, one per area, opening with "First,", "Second,", "Third," / "Finally,"; precise technical terms), '
            . '"From my experience" (one concrete example from the CV: "I applied this in <real project>, where I…" plus what it taught me), '
            . '"Validation" (how I would confirm it works: tests, monitoring, metrics).';
        $writtenRules = 'answer_mode="written". THIS ANSWER WILL BE TYPED OR HANDWRITTEN, NOT SPOKEN, so the spoken-delivery rules in the system instructions do not apply here. '
            . 'Write connected prose, not talking points. sections = one per part of the answer, each with a short plain heading (e.g. "Opening", "My experience", "How I would approach it", "Conclusion"); '
            . 'choose 3-5 headings that suit the question. Each bullet in a section is ONE complete paragraph in flowing first-person prose — full sentences, joined with connectives, no fragments and no bullet-style lists. '
            . 'Respect any word limit or mark allocation stated with the question: roughly 120-150 words per 10 marks, and stay under a stated word limit. '
            . 'points = 2-3 sentences summarising the argument. closing_line = the answer\'s final sentence.';

        return match ($mode) {
            'written'    => $writtenRules,
            'quick'      => 'answer_mode="quick". Put 3-5 spoken sentences in points, in the order to say them: approach first, the key points, one real example, a closing line. sections must be [].',
            'star'       => 'answer_mode="star". sections exactly: Situation, Task, Action, Result. Use ONE real example from the CV. If that example is only adjacent to the question, Situation opens by saying so ("I have not run one exactly like that, but on <project>..."). Action has 2-3 sentences opening with "First,", "Then," / "Finally,". points = 2-3 summary sentences.',
            'technical'  => 'answer_mode="technical". ' . $technical . ' points = 2-3 summary sentences.',
            'leadership' => 'answer_mode="leadership". sections exactly: "Approach" (one sentence), "Challenge", "Decision", "People", "Result" — anchored in ONE real example from the CV. points = 2-3 summary sentences.',
            default      => 'AUTO: classify the question and choose the structure. '
                . 'If the question is one the candidate has to WRITE out (an essay or exam prompt, an assignment, or a form field with a word limit or marks) rather than say out loud, use ' . $writtenRules . ' '
                . 'Otherwise: '
                . 'technical/problem_solving → answer_mode "technical" with ' . $technical . ' '
                . 'Pick the framework that fits: system design = architecture, security, scalability, observability; '
                . 'debugging/incident = reproduce, measure, isolate, fix, validate; code review = correctness, security, maintainability, tests. '
                . 'behavioural/competency/situational → answer_mode "star" (Situation, Task, Action, Result) using ONE real CV example; '
                . 'leadership/management → answer_mode "leadership" (Approach, Challenge, Decision, People, Result); '
                . 'everything else (motivation, career_history, salary_hr, communication, general) → answer_mode "general" with sections Direct answer, Supporting point, Evidence, Conclusion. '
                . 'points = 2-3 summary sentences.',
        };
    }

    public static function candidateContext(?array $cv): string
    {
        if (!$cv) {
            return 'NO CV UPLOADED. You have no facts about this candidate. Still answer every question in full, as a strong candidate for this role and seniority would, and leave short square-bracket gaps where their own example belongs. Never invent employers, job titles, dates, qualifications or numbers. Set evidence_strength to "general".';
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
        return 'CV uploaded but could not be read, so you have no facts about this candidate. Still answer every question in full, leaving short square-bracket gaps where their own example belongs. Never invent employers, job titles, dates, qualifications or numbers. Set evidence_strength to "general".';
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

Your job is to help the candidate give an authentic answer using their CV and the interview context. Every line you write under a section is something the candidate will read out loud, word for word, during a live interview.

THE CV IS A STARTING POINT, NOT A LIMIT. It is the best evidence you have about this candidate, but it is a summary written for a different purpose: it will not list every project, tool, task or situation they have handled. A subject missing from the CV is NOT proof they have never done it.

So ALWAYS answer the question in full, in words they can say. Never hand back a non-answer, never tell the candidate you have no evidence, and never replace the answer with instructions to them:
  - The CV covers the question → answer from it, naming the real project.
  - The CV does not cover it → still give the whole answer: how a strong candidate at this seniority would approach it, anchored in the CLOSEST real project on the CV. Bridge honestly, in the candidate's own voice: "I have not done exactly that, but on <real project> I <real thing>, and the same approach applies here because...". That is a good answer, not a disclaimer.
    The gap must be owned in the VERY FIRST sentence the candidate says — the opening bullet of the first section, whatever the mode is called (Situation, Approach, Opening). Never let the answer open as though they did the thing and admit the gap later: a question like "describe a project where you did X" is asking what they have done, so an answer that starts "On <project> I did X" when they did not is a lie the interviewer may well catch. Lead with the honest clause, then spend the rest of the answer on the transferable substance.
  - Nothing on the CV is even adjacent → give the strong general answer for this role and seniority, and leave a short square-bracket gap where their own example belongs: "A good example of this for me was [project]."

What you must NEVER invent are the checkable facts: employers, job titles, dates, qualifications, certifications, and numbers or metrics. Those are what an interviewer can verify, and getting them wrong costs the candidate the job. Everything else — how they would approach a problem, what they would weigh up, what they learned — is yours to write, because it is reasoning rather than biography.

Answers must be concise enough for the candidate to scan within seconds and natural enough to say out loud.

Process:
1. QUESTION DETECTION: Decide if the transcript contains an interview question (or a request such as "Tell me about...", "Walk me through...") directed at the candidate. Small talk, thanks, logistics, or the interviewer describing the company are NOT questions (is_question=false). If several sentences precede the question, extract only the actual question. Fix transcription errors and return it as one clean sentence in "question". When is_question=false return empty strings/arrays for every other text field and question_type "unknown".
2. CLASSIFY question_type.
3. Compare the question against the job requirements (skills, competencies, tools, leadership and technical requirements) and pick the evidence that comes CLOSEST, from the CV AND from any extra experience the candidate stated in their own instructions. If their instructions cover the subject of the question, that is direct evidence and outranks anything you would otherwise bridge to. Closest counts: a project that shares the skill, the problem shape, the stakeholder situation, the constraint or the scale is usable evidence even when the subject is different. Only treat the CV as offering nothing when nothing on it is even adjacent.
4. WRITE the guidance:
   - key_message: one sentence — what the answer must demonstrate (shown as a heading, not spoken).
   - sections: labelled structure per the requested mode. Each bullet is ONE complete, natural, first-person sentence the candidate can say exactly as written, e.g. "I led the requirements, design and build of the finKAP platform." Plain conversational English; contractions are fine.
   - Bullets must NEVER be instructions to the candidate: do not write "Explain…", "Mention…", "Describe…", "Talk about…", "if you can recall one". Say it for them instead.
   - points: 2-3 complete spoken sentences that summarise the whole answer (for quick mode, 3-5 sentences that ARE the answer).
   - Employers, job titles, dates, qualifications and numbers: use ONLY what appears in the candidate profile. Where a detail like that is missing, phrase it generally ("the payment integration", "a tight deadline") or leave a short square-bracket gap the candidate fills in, e.g. "One defect I fixed was [the defect]." Never make up a metric. This restriction is on checkable facts only — it is not a reason to withhold the answer.
   - cv_evidence: up to 4 short facts copied/paraphrased from the CV that support this answer, INCLUDING ones that support it by analogy. Empty only when nothing on the CV is relevant even loosely.
   - evidence_strength: "direct" when this exact experience is shown in the CV OR stated by the candidate in their own instructions — both are first-hand and neither gets hedged. "adjacent" when you bridged from a related project. "general" when there was nothing to build on.
   - evidence_note: "" when evidence_strength is "direct". Otherwise ONE short sentence addressed to the candidate (never spoken aloud) saying what you built the answer on and inviting the better example they may have, e.g. "Built from your Eval360 work — swap in a closer example if you have one." Assume the CV is incomplete rather than assuming they lack the experience.
   - closing_line: one natural spoken sentence to end the answer, linking back to the role.
   - keywords: 3-6 single words or short phrases to emphasise (prefer job-description vocabulary).
5. SPEAKING STYLE — make it sound deliberate and senior:
   - Open with the approach before any detail. Think in groups of 3-4 points and signpost them ("First,", "Second,", "Finally,").
   - Make each point once. Never repeat an idea or a phrase across sections; move to the next layer instead.
   - No filler: never start with "So", and do not use "whereby", "maybe", "that is", "basically", "kind of", "you know".
   - Be precise with technical terms. Say "slow-query logs, execution plans, lock contention and connection-pool saturation", not "check database waits".
   - Include exactly ONE concrete example, named, with what it taught the candidate ("I applied this in Eval360, where I designed RBAC and tenant-aware data structures. That taught me to treat tenant isolation as an architectural concern."). When you bridged from a related project, name that real project and own the gap in a single clause — do not pretend the match is exact, and do not apologise for it either.
   - Finish with how the result would be validated or what the outcome was.
   - Short sentences with a natural pause between points, easy to read aloud at a calm pace.
No markdown, no emojis, no long sentences.
TXT;

    /** @return array<string,mixed> strict JSON schema for answer guidance */
    public static function answerSchema(): array
    {
        $strArr = ['type' => 'array', 'items' => ['type' => 'string']];
        return [
            'type' => 'object',
            'additionalProperties' => false,
            'required' => ['is_question', 'question', 'question_type', 'answer_mode', 'key_message', 'points', 'sections', 'cv_evidence', 'evidence_strength', 'evidence_note', 'closing_line', 'keywords'],
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
                'evidence_strength' => ['type' => 'string', 'enum' => self::EVIDENCE_STRENGTHS],
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

        $type = in_array($d['question_type'] ?? '', self::QUESTION_TYPES, true) ? $d['question_type'] : 'unknown';
        $mode = in_array($d['answer_mode'] ?? '', self::ANSWER_MODES, true) ? $d['answer_mode']
            : ($requestedMode !== 'auto' && in_array($requestedMode, self::ANSWER_MODES, true) ? $requestedMode : 'general');

        // Written answers are paragraphs the candidate will type, so they get much longer limits
        // than spoken lines, which must stay glanceable.
        $written = self::isWritten($mode);
        $perSection = $written ? 6 : 4;
        $bulletMax = $written ? 1400 : 220;

        $sections = [];
        foreach (array_slice(is_array($d['sections'] ?? null) ? $d['sections'] : [], 0, 7) as $sec) {
            if (!is_array($sec)) {
                continue;
            }
            $label = $s($sec['label'] ?? '', 40);
            $bullets = $list($sec['bullets'] ?? [], $perSection, $bulletMax);
            if ($label !== '' && $bullets) {
                $sections[] = ['label' => $label, 'bullets' => $bullets];
            }
        }
        $points = $list($d['points'] ?? [], 6, $written ? 400 : 220);
        if (!$points && !$sections) {
            throw new \UnexpectedValueException('Answer has no content');
        }

        $evidence = $list($d['cv_evidence'] ?? [], 4, 260);
        // Fall back from what the model claimed to what it actually produced.
        $strength = in_array($d['evidence_strength'] ?? '', self::EVIDENCE_STRENGTHS, true)
            ? $d['evidence_strength']
            : ($evidence ? 'adjacent' : 'general');

        $out = [
            'is_question'   => true,
            'question'      => $question,
            'question_type' => $type,
            'answer_mode'   => $mode,
            'key_message'   => $s($d['key_message'] ?? '', 300),
            'points'        => $points,
            'sections'      => $mode === 'quick' ? [] : $sections,
            'cv_evidence'   => $evidence,
            'evidence_strength' => $strength,
            'evidence_note' => $s($d['evidence_note'] ?? '', 300),
            'closing_line'  => trim($s($d['closing_line'] ?? '', $written ? 500 : 300), '"“”'),
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
     * When $onDelta is given the first attempt is streamed (text deltas are forwarded as they arrive).
     * @param callable(array):array $normalise
     * @param callable(string):void|null $onDelta
     */
    private function callJson(int $userId, string $action, array $payload, callable $normalise, ?callable $onDelta = null): array
    {
        $attempts = 0;
        while (true) {
            $attempts++;
            $res = ($onDelta !== null && $attempts === 1)
                ? $this->ai->streamResponse($payload, $onDelta)
                : $this->ai->createResponse($payload);
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
            'instructions' => "You are a supportive but honest interview coach. Evaluate the candidate's spoken answer (a speech transcript) to the question for the target role. Be specific and brief; quote short phrases from the answer as evidence.\n"
                . "Assess these speaking habits:\n"
                . "1. Repetition: ideas or phrases said more than once instead of moving to the next layer.\n"
                . "2. Pace/clarity: garbled or merged phrases in the transcript usually mean the candidate spoke too fast; list them in possible_mishears with what was probably meant, and suggest a brief pause between points.\n"
                . "3. Approach first: did they open with their overall approach before details?\n"
                . "4. Structure: did they group points in 3-4 (e.g. debugging: reproduce, measure, isolate, fix, validate; system design: architecture, security, scalability, observability; code review: correctness, security, maintainability, tests)?\n"
                . "5. One concrete example from their CV, named, with what it taught them.\n"
                . "6. Filler words (So, whereby, maybe, that is, basically, kind of, you know): list the ones used with counts, e.g. \"So (×4)\".\n"
                . "7. Precise technical terminology instead of vague wording.\n"
                . "The improved answer must follow that format (approach → 3-4 signposted points → one real CV example → validation), be natural to say aloud, and only use facts from the candidate's answer or CV profile — never invent experience or numbers; use [placeholders] where a detail is needed.",
            'input' => [['role' => 'user', 'content' => [
                ['type' => 'input_text', 'text' => "## CANDIDATE PROFILE\n" . self::candidateContext($cv) . "\n\n## TARGET ROLE\n" . self::jobContext($job)],
                ['type' => 'input_text', 'text' => "## QUESTION\n$question\n\n## CANDIDATE'S ANSWER (transcribed)\n\"\"\"\n" . mb_substr($answerText, 0, 6000) . "\n\"\"\""],
            ]]],
            'text' => ['format' => ['type' => 'json_schema', 'name' => 'practice_feedback', 'strict' => true, 'schema' => [
                'type' => 'object', 'additionalProperties' => false,
                'required' => ['score', 'summary', 'strengths', 'missing_points', 'better_structure', 'delivery_tips', 'filler_words', 'possible_mishears', 'improved_answer'],
                'properties' => [
                    'score' => ['type' => 'integer', 'description' => '1-10 overall'],
                    'summary' => ['type' => 'string'],
                    'strengths' => $strArr,
                    'missing_points' => $strArr,
                    'better_structure' => $strArr,
                    'delivery_tips' => ['type' => 'array', 'items' => ['type' => 'string'], 'description' => 'Up to 4 tips on repetition, pace, opening with the approach, precision — each with a short "instead of X, say Y" where useful'],
                    'filler_words' => ['type' => 'array', 'items' => ['type' => 'string'], 'description' => 'Filler words used, with counts, e.g. "So (×4)"'],
                    'possible_mishears' => ['type' => 'array', 'items' => ['type' => 'string'], 'description' => 'Garbled transcript phrases and the likely intended words, e.g. "Harvard 360" → probably "Eval360"'],
                    'improved_answer' => ['type' => 'string', 'description' => 'Spoken-style improved answer, max ~150 words'],
                ],
            ]]],
            'max_output_tokens' => 2000,
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
                'delivery_tips'    => $l($d['delivery_tips'] ?? []),
                'filler_words'     => $l($d['filler_words'] ?? []),
                'possible_mishears'=> $l($d['possible_mishears'] ?? []),
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
