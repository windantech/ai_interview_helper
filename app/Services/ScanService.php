<?php

declare(strict_types=1);

namespace App\Services;

use App\Core\HttpException;
use App\Core\Logger;
use App\Models\UsageLog;

/**
 * Reads a photographed or uploaded question paper and returns every question on it.
 *
 * Page images are sent inline as data URLs (one Responses API call for the whole paper, so
 * numbering and continuation across pages stays correct). A PDF goes through the Files API as an
 * input_file instead, and the temporary OpenAI file is deleted again straight away.
 *
 * Nothing is answered here — the extracted questions are handed to InterviewService one by one.
 */
final class ScanService
{
    public const DOC_TYPES = ['interview_questions', 'application_form', 'essay_exam', 'assignment', 'job_description', 'other', 'unreadable'];

    public const MAX_QUESTIONS = 40;
    private const MAX_QUESTION_CHARS = 1200;

    public function __construct(private readonly OpenAIClient $ai = new OpenAIClient())
    {
    }

    /**
     * @param list<array{tmp:string,ext:string,mime:string,size:int,original:string}> $pages validated uploads
     * @param array<string,mixed>|null $job target job (named in the prompt so jargon reads correctly)
     * @param string $vocab names/terms from the CV and job — see InterviewService::vocabularyHint()
     * @return array<string,mixed> {document_type, document_title, instructions_text, notes, questions: list<...>}
     */
    public function extract(int $userId, array $pages, ?array $job = null, ?string $hint = null, string $vocab = ''): array
    {
        if (!$pages) {
            throw new HttpException(400, 'Please add at least one page to scan.');
        }

        $fileIds = [];
        try {
            $inputs = [];
            foreach ($pages as $i => $page) {
                if ($page['ext'] === 'pdf') {
                    $fileId = $this->ai->uploadFile($page['tmp'], 'paper-' . ($i + 1) . '.pdf', 'application/pdf');
                    $fileIds[] = $fileId;
                    $inputs[] = ['type' => 'input_file', 'file_id' => $fileId];
                } else {
                    $inputs[] = self::imageInput($page);
                }
            }
            $payload = self::buildPayload($inputs, $job, $hint, $vocab);
            $model = (string) $payload['model'];

            $res = $this->ai->createResponse($payload, 150);
            $usage = OpenAIClient::usage($res);
            UsageLog::record($userId, 'scan_extract', $model, $usage['input'], $usage['output']);

            $data = json_decode(InterviewService::stripJson(OpenAIClient::outputText($res)), true, 32);
            if (!is_array($data)) {
                Logger::warning('Malformed scan JSON', ['user_id' => $userId]);
                throw new OpenAIException(502, 'We could not read that page. Please try again.', 'invalid_json');
            }
            return self::normalise($data);
        } finally {
            foreach ($fileIds as $id) {
                $this->ai->deleteFile($id);
            }
        }
    }

    /**
     * One page as an inline image input. Pages are sent as data URLs rather than uploaded files:
     * one request, nothing left behind on the OpenAI account.
     * @param array{tmp:string,mime:string} $page
     * @return array<string,string>
     */
    public static function imageInput(array $page): array
    {
        $bytes = file_get_contents($page['tmp']);
        if ($bytes === false || $bytes === '') {
            throw new HttpException(400, 'One of the pages could not be read. Please capture it again.');
        }
        return [
            'type'      => 'input_image',
            'image_url' => 'data:' . $page['mime'] . ';base64,' . base64_encode($bytes),
            'detail'    => 'high',   // printed and handwritten text needs the full-resolution pass
        ];
    }

    /**
     * The Responses API payload for one scan.
     * @param list<array<string,string>> $inputs one content block per page, in page order
     * @return array<string,mixed>
     */
    public static function buildPayload(array $inputs, ?array $job = null, ?string $hint = null, string $vocab = ''): array
    {
        $content = $inputs;
        $content[] = ['type' => 'input_text', 'text' => self::taskText(count($inputs), $job, $hint, $vocab)];

        $payload = [
            'model'        => (string) config('openai.scan_model'),
            'store'        => false,
            'instructions' => self::EXTRACT_INSTRUCTIONS,
            'input'        => [['role' => 'user', 'content' => $content]],
            'text'         => ['format' => [
                'type'   => 'json_schema',
                'name'   => 'scanned_questions',
                'strict' => true,
                'schema' => self::schema(),
            ]],
            'max_output_tokens' => (int) config('openai.scan_max_output_tokens', 4000),
        ];
        $effort = (string) config('openai.scan_reasoning', '');
        if ($effort !== '' && $effort !== 'none') {
            $payload['reasoning'] = ['effort' => $effort];
        }
        return $payload;
    }

    public static function taskText(int $pageCount, ?array $job, ?string $hint, string $vocab = ''): string
    {
        $lines = [$pageCount === 1
            ? 'The image above is one page of a question paper. Extract every question on it.'
            : "The $pageCount images above are consecutive pages of the same question paper, in order. Extract every question across all of them, numbered continuously, and never list the same question twice."];
        // Names from the CV and job help the model read project names and jargon correctly.
        $vocab = trim($vocab);
        if ($job) {
            $lines[] = 'Context: the candidate is applying for ' . $job['title'] . (!empty($job['company']) ? ' at ' . $job['company'] : '') . '.'
                . ($vocab !== '' ? ' Terms that may appear: ' . $vocab . '.' : '');
        } elseif ($vocab !== '') {
            $lines[] = 'Terms that may appear on the page: ' . $vocab . '.';
        }
        $hint = trim((string) $hint);
        if ($hint !== '') {
            $lines[] = "The candidate says this about the paper:\n\"\"\"\n" . mb_substr($hint, 0, 500) . "\n\"\"\"";
        }
        return implode("\n\n", $lines);
    }

    private const EXTRACT_INSTRUCTIONS = <<<TXT
You read photographs and scans of question papers and return the questions on them as structured JSON.

What counts as a question: anything the candidate is expected to answer — a direct question, an
instruction such as "Describe...", "Discuss...", "Walk me through...", a numbered essay prompt, or a
form field such as "Why do you want to work here?".

What does NOT count: headings, page numbers, candidate details, timing or rubric text ("Answer any
three questions", "Time allowed: 2 hours"), mark schemes, footers, and anything already answered.
Put paper-wide directions in instructions_text, not in questions.

Rules:
- Transcribe each question faithfully, including handwriting. Fix only obvious OCR slips; never
  rewrite, shorten, translate or complete a question, and never invent one that is not on the page.
- Keep the printed numbering in "number" exactly as shown ("3", "3(b)", "Q4", "Section B 2") and ""
  when the question is unnumbered. Keep the paper's order.
- A question with lettered parts (a), (b), (c) that each need their own answer: return one entry per
  part, each carrying the full context it needs to be answerable on its own.
- "marks": the marks or word limit printed with the question ("[10 marks]", "max 300 words"), else "".
- "question_type": your best classification of what the question is testing.
- "written_answer_expected": true when the paper is to be written on or typed into (essay, exam,
  assignment, application form), false when the questions are to be answered out loud (an
  interview question list).
- If a page is too blurred, dark or cropped to read, set document_type "unreadable", return the
  questions you can read, and say what went wrong in "notes".
- "notes": one or two short sentences for the candidate — anything they should know about the paper
  (pages missing, text cut off, choose-three instructions). "" when there is nothing to say.
TXT;

    /** @return array<string,mixed> strict JSON schema for an extracted question paper */
    public static function schema(): array
    {
        return [
            'type' => 'object',
            'additionalProperties' => false,
            'required' => ['document_type', 'document_title', 'instructions_text', 'written_answer_expected', 'notes', 'questions'],
            'properties' => [
                'document_type'    => ['type' => 'string', 'enum' => self::DOC_TYPES],
                'document_title'   => ['type' => 'string', 'description' => 'Title printed on the paper, or "" if none'],
                'instructions_text' => ['type' => 'string', 'description' => 'Paper-wide directions such as "Answer any three questions"'],
                'written_answer_expected' => ['type' => 'boolean'],
                'notes'            => ['type' => 'string'],
                'questions' => ['type' => 'array', 'items' => [
                    'type' => 'object',
                    'additionalProperties' => false,
                    'required' => ['number', 'text', 'marks', 'question_type'],
                    'properties' => [
                        'number'        => ['type' => 'string'],
                        'text'          => ['type' => 'string'],
                        'marks'         => ['type' => 'string'],
                        'question_type' => ['type' => 'string', 'enum' => InterviewService::QUESTION_TYPES],
                    ],
                ]],
            ],
        ];
    }

    /**
     * Validate + clean model output. Duplicates are dropped and the list is capped.
     * @return array<string,mixed>
     */
    public static function normalise(array $d): array
    {
        $s = fn ($v, $max) => mb_substr(trim(preg_replace('/\s+/u', ' ', is_scalar($v) ? (string) $v : '') ?? ''), 0, $max);

        $questions = [];
        $seen = [];
        foreach (is_array($d['questions'] ?? null) ? $d['questions'] : [] as $q) {
            if (!is_array($q)) {
                continue;
            }
            $text = $s($q['text'] ?? '', self::MAX_QUESTION_CHARS);
            if (mb_strlen($text) < 8) {
                continue;
            }
            $key = mb_strtolower(preg_replace('/[^\p{L}\p{N}]+/u', '', $text) ?? $text);
            if (isset($seen[$key])) {
                continue;
            }
            $seen[$key] = true;
            $questions[] = [
                'number'        => $s($q['number'] ?? '', 20),
                'text'          => $text,
                'marks'         => $s($q['marks'] ?? '', 40),
                'question_type' => in_array($q['question_type'] ?? '', InterviewService::QUESTION_TYPES, true) ? $q['question_type'] : 'unknown',
            ];
            if (count($questions) >= self::MAX_QUESTIONS) {
                break;
            }
        }

        return [
            'document_type'     => in_array($d['document_type'] ?? '', self::DOC_TYPES, true) ? $d['document_type'] : 'other',
            'document_title'    => $s($d['document_title'] ?? '', 160),
            'instructions_text' => $s($d['instructions_text'] ?? '', 400),
            'written_answer_expected' => filter_var($d['written_answer_expected'] ?? false, FILTER_VALIDATE_BOOLEAN),
            'notes'             => $s($d['notes'] ?? '', 400),
            'questions'         => $questions,
        ];
    }

    /** Short label for the session title, e.g. "Scan: Essay paper — Management". */
    public static function sessionTitle(array $doc): string
    {
        $kind = match ($doc['document_type']) {
            'interview_questions' => 'Interview questions',
            'application_form'    => 'Application form',
            'essay_exam'          => 'Essay / exam paper',
            'assignment'          => 'Assignment',
            'job_description'     => 'Job description',
            default               => 'Scanned questions',
        };
        return $doc['document_title'] !== '' ? $kind . ' — ' . $doc['document_title'] : $kind;
    }
}
