<?php

declare(strict_types=1);

namespace App\Models;

use App\Core\Database;

/**
 * Interview sessions and the questions asked within them.
 */
final class InterviewSession
{
    /** @return array<string,mixed>|null */
    public static function findForUser(int $id, int $userId): ?array
    {
        return Database::fetch('SELECT * FROM interview_sessions WHERE id = ? AND user_id = ?', [$id, $userId]);
    }

    public static function create(int $userId, ?array $job, string $type = 'live'): int
    {
        $title = $job
            ? ($type === 'practice' ? 'Practice: ' : '') . $job['title'] . ($job['company'] ? ' — ' . $job['company'] : '')
            : ($type === 'practice' ? 'Practice interview' : 'Interview');
        return Database::insert(
            'INSERT INTO interview_sessions (user_id, job_id, title, job_title, company, session_type, status, started_at)
             VALUES (?, ?, ?, ?, ?, ?, \'active\', UTC_TIMESTAMP())',
            [$userId, $job['id'] ?? null, mb_substr($title, 0, 200), $job['title'] ?? null, $job['company'] ?? null, $type]
        );
    }

    public static function end(int $id, int $userId): bool
    {
        return Database::execute(
            'UPDATE interview_sessions SET status = \'ended\', ended_at = COALESCE(ended_at, UTC_TIMESTAMP()) WHERE id = ? AND user_id = ?',
            [$id, $userId]
        ) > 0;
    }

    /** End any stale active sessions of the given type (keeps one active session per type). */
    public static function endActive(int $userId, string $type): void
    {
        Database::execute(
            'UPDATE interview_sessions SET status = \'ended\', ended_at = UTC_TIMESTAMP()
             WHERE user_id = ? AND session_type = ? AND status = \'active\'',
            [$userId, $type]
        );
    }

    /** @return array<string,mixed>|null */
    public static function active(int $userId, string $type = 'live'): ?array
    {
        return Database::fetch(
            'SELECT * FROM interview_sessions WHERE user_id = ? AND session_type = ? AND status = \'active\' ORDER BY id DESC LIMIT 1',
            [$userId, $type]
        );
    }

    public static function delete(int $id, int $userId): bool
    {
        return Database::execute('DELETE FROM interview_sessions WHERE id = ? AND user_id = ?', [$id, $userId]) > 0;
    }

    public static function deleteAllForUser(int $userId): int
    {
        return Database::execute('DELETE FROM interview_sessions WHERE user_id = ?', [$userId]);
    }

    /**
     * Paginated history with search/filters.
     * @param array{q?:string,job_id?:int|null,from?:string|null,to?:string|null,type?:string|null} $filters
     * @return array{items:list<array<string,mixed>>,total:int}
     */
    public static function history(int $userId, array $filters, int $page = 1, int $perPage = 15): array
    {
        $where = ['s.user_id = :uid'];
        $params = ['uid' => $userId];
        if (!empty($filters['job_id'])) {
            $where[] = 's.job_id = :job';
            $params['job'] = (int) $filters['job_id'];
        }
        if (!empty($filters['type']) && in_array($filters['type'], ['live', 'practice'], true)) {
            $where[] = 's.session_type = :stype';
            $params['stype'] = $filters['type'];
        }
        if (!empty($filters['from'])) {
            $where[] = 's.started_at >= :from';
            $params['from'] = $filters['from'] . ' 00:00:00';
        }
        if (!empty($filters['to'])) {
            $where[] = 's.started_at <= :to';
            $params['to'] = $filters['to'] . ' 23:59:59';
        }
        if (!empty($filters['q'])) {
            $where[] = '(s.title LIKE :q1 OR s.company LIKE :q2 OR s.job_title LIKE :q3 OR EXISTS (
                SELECT 1 FROM interview_questions iq WHERE iq.session_id = s.id AND iq.question LIKE :q4))';
            $like = '%' . str_replace(['\\', '%', '_'], ['\\\\', '\\%', '\\_'], $filters['q']) . '%';
            $params['q1'] = $params['q2'] = $params['q3'] = $params['q4'] = $like;
        }
        $whereSql = implode(' AND ', $where);
        $total = (int) Database::value("SELECT COUNT(*) FROM interview_sessions s WHERE $whereSql", $params);

        $params['limit'] = $perPage;
        $params['offset'] = max(0, ($page - 1) * $perPage);
        $items = Database::fetchAll(
            "SELECT s.*, (SELECT COUNT(*) FROM interview_questions q WHERE q.session_id = s.id) AS question_count
             FROM interview_sessions s WHERE $whereSql ORDER BY s.started_at DESC, s.id DESC LIMIT :limit OFFSET :offset",
            $params
        );
        return ['items' => $items, 'total' => $total];
    }

    // ------------------------------------------------------------------ questions

    /** @param array<string,mixed> $data */
    public static function addQuestion(int $sessionId, array $data): int
    {
        return Database::insert(
            'INSERT INTO interview_questions (session_id, question, raw_transcript, question_type, answer_mode, answer_json, source, user_answer, feedback_json, latency_ms)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)',
            [
                $sessionId,
                $data['question'],
                $data['raw_transcript'] ?? null,
                $data['question_type'] ?? 'unknown',
                $data['answer_mode'] ?? 'auto',
                isset($data['answer']) ? json_encode($data['answer'], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) : null,
                $data['source'] ?? 'typed',
                $data['user_answer'] ?? null,
                isset($data['feedback']) ? json_encode($data['feedback'], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) : null,
                $data['latency_ms'] ?? null,
            ]
        );
    }

    /** @return array<string,mixed>|null question owned by user (via session) */
    public static function findQuestionForUser(int $questionId, int $userId): ?array
    {
        return Database::fetch(
            'SELECT q.* FROM interview_questions q JOIN interview_sessions s ON s.id = q.session_id WHERE q.id = ? AND s.user_id = ?',
            [$questionId, $userId]
        );
    }

    public static function updateQuestion(int $questionId, array $fields): void
    {
        $allowed = ['answer_json', 'user_answer', 'feedback_json', 'answer_mode', 'question_type'];
        $sets = [];
        $params = [];
        foreach ($fields as $k => $v) {
            if (in_array($k, $allowed, true)) {
                $sets[] = "$k = ?";
                $params[] = is_array($v) ? json_encode($v, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) : $v;
            }
        }
        if ($sets) {
            $params[] = $questionId;
            Database::execute('UPDATE interview_questions SET ' . implode(', ', $sets) . ' WHERE id = ?', $params);
        }
    }

    /** @return list<array<string,mixed>> */
    public static function questions(int $sessionId): array
    {
        $rows = Database::fetchAll('SELECT * FROM interview_questions WHERE session_id = ? ORDER BY created_at ASC, id ASC', [$sessionId]);
        foreach ($rows as &$r) {
            $r['answer'] = $r['answer_json'] ? json_decode((string) $r['answer_json'], true) : null;
            $r['feedback'] = $r['feedback_json'] ? json_decode((string) $r['feedback_json'], true) : null;
            unset($r['answer_json'], $r['feedback_json']);
        }
        return $rows;
    }

    /** Recent questions for context (most recent last). @return list<array{question:string,key_message:string}> */
    public static function recentContext(int $sessionId, int $limit = 4): array
    {
        $rows = Database::fetchAll(
            'SELECT question, JSON_UNQUOTE(JSON_EXTRACT(answer_json, \'$.key_message\')) AS key_message
             FROM interview_questions WHERE session_id = ? ORDER BY id DESC LIMIT ' . max(1, min(10, $limit)),
            [$sessionId]
        );
        return array_reverse(array_map(fn ($r) => [
            'question'    => (string) $r['question'],
            'key_message' => (string) ($r['key_message'] ?? ''),
        ], $rows));
    }

    /** Questions previously asked in a practice session (for de-duplication). @return list<string> */
    public static function questionTexts(int $sessionId): array
    {
        return array_column(Database::fetchAll('SELECT question FROM interview_questions WHERE session_id = ? ORDER BY id', [$sessionId]), 'question');
    }

    // ------------------------------------------------------------------ dashboard stats

    /** @return array<string,mixed> */
    public static function stats(int $userId): array
    {
        $row = Database::fetch(
            'SELECT
                (SELECT COUNT(*) FROM interview_sessions WHERE user_id = :u1) AS sessions,
                (SELECT COUNT(*) FROM interview_questions q JOIN interview_sessions s ON s.id = q.session_id WHERE s.user_id = :u2) AS questions,
                (SELECT COUNT(DISTINCT q.question_type) FROM interview_questions q JOIN interview_sessions s ON s.id = q.session_id
                    WHERE s.user_id = :u3 AND q.question_type <> \'unknown\') AS types',
            ['u1' => $userId, 'u2' => $userId, 'u3' => $userId]
        ) ?? [];
        $types = Database::fetchAll(
            'SELECT q.question_type, COUNT(*) AS c FROM interview_questions q JOIN interview_sessions s ON s.id = q.session_id
             WHERE s.user_id = ? GROUP BY q.question_type ORDER BY c DESC LIMIT 6',
            [$userId]
        );
        return [
            'sessions'  => (int) ($row['sessions'] ?? 0),
            'questions' => (int) ($row['questions'] ?? 0),
            'types'     => (int) ($row['types'] ?? 0),
            'type_breakdown' => $types,
        ];
    }

    /** @return list<array<string,mixed>> */
    public static function recent(int $userId, int $limit = 5): array
    {
        return Database::fetchAll(
            'SELECT s.*, (SELECT COUNT(*) FROM interview_questions q WHERE q.session_id = s.id) AS question_count
             FROM interview_sessions s WHERE s.user_id = ? ORDER BY s.started_at DESC LIMIT ' . max(1, min(20, $limit)),
            [$userId]
        );
    }

    /** @return list<array<string,mixed>> */
    public static function recentQuestions(int $userId, int $limit = 6): array
    {
        return Database::fetchAll(
            'SELECT q.id, q.question, q.question_type, q.created_at, s.id AS session_id, s.title
             FROM interview_questions q JOIN interview_sessions s ON s.id = q.session_id
             WHERE s.user_id = ? ORDER BY q.id DESC LIMIT ' . max(1, min(20, $limit)),
            [$userId]
        );
    }
}
