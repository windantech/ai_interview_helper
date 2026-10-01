<?php

declare(strict_types=1);

namespace App\Models;

use App\Core\Database;

final class Job
{
    public const FIELDS = ['title', 'company', 'industry', 'location', 'description', 'main_skills', 'interview_type', 'seniority'];

    /** @return list<array<string,mixed>> */
    public static function forUser(int $userId): array
    {
        return Database::fetchAll(
            'SELECT j.*, (SELECT COUNT(*) FROM interview_sessions s WHERE s.job_id = j.id) AS session_count
             FROM jobs j WHERE j.user_id = ? ORDER BY j.updated_at DESC',
            [$userId]
        );
    }

    /** @return array<string,mixed>|null */
    public static function findForUser(int $id, int $userId): ?array
    {
        return Database::fetch('SELECT * FROM jobs WHERE id = ? AND user_id = ?', [$id, $userId]);
    }

    /** @param array<string,mixed> $data */
    public static function create(int $userId, array $data): int
    {
        return Database::insert(
            'INSERT INTO jobs (user_id, title, company, industry, location, description, main_skills, interview_type, seniority)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)',
            [
                $userId, $data['title'], $data['company'] ?: null, $data['industry'] ?: null, $data['location'] ?: null,
                $data['description'], $data['main_skills'] ?: null, $data['interview_type'], $data['seniority'],
            ]
        );
    }

    /** @param array<string,mixed> $data */
    public static function update(int $id, int $userId, array $data): void
    {
        Database::execute(
            'UPDATE jobs SET title = ?, company = ?, industry = ?, location = ?, description = ?, main_skills = ?,
             interview_type = ?, seniority = ?, jd_summary_json = NULL WHERE id = ? AND user_id = ?',
            [
                $data['title'], $data['company'] ?: null, $data['industry'] ?: null, $data['location'] ?: null,
                $data['description'], $data['main_skills'] ?: null, $data['interview_type'], $data['seniority'], $id, $userId,
            ]
        );
    }

    public static function delete(int $id, int $userId): bool
    {
        return Database::execute('DELETE FROM jobs WHERE id = ? AND user_id = ?', [$id, $userId]) > 0;
    }

    public static function count(int $userId): int
    {
        return (int) Database::value('SELECT COUNT(*) FROM jobs WHERE user_id = ?', [$userId]);
    }

    /** @return array<string,mixed> sample job used by "Add sample job" */
    public static function sample(): array
    {
        return [
            'title'          => 'Project Manager',
            'company'        => 'Northwind Infrastructure Ltd',
            'industry'       => 'Construction & Infrastructure',
            'location'       => 'London (Hybrid)',
            'main_skills'    => 'Stakeholder management, risk management, budgeting, scheduling, team leadership, PRINCE2',
            'interview_type' => 'behavioural',
            'seniority'      => 'senior',
            'description'    => "We are looking for an experienced Project Manager to lead the delivery of mid-sized infrastructure and technology projects from initiation to handover.\n\n"
                . "Responsibilities:\n- Plan, schedule and manage projects valued between £2m and £10m against scope, time, cost and quality targets.\n"
                . "- Lead cross-functional teams of engineers, contractors and suppliers.\n"
                . "- Manage stakeholders including clients, senior leadership and regulators; run steering-committee reporting.\n"
                . "- Identify, track and mitigate risks and issues; maintain RAID logs.\n"
                . "- Control budgets, forecasts and change requests.\n"
                . "- Drive continuous improvement and lessons-learned reviews.\n\n"
                . "Requirements:\n- 5+ years of project management experience.\n- PRINCE2, APM or PMP certification (or equivalent).\n"
                . "- Strong stakeholder management, communication and negotiation skills.\n"
                . "- Experience with MS Project, Jira or similar planning tools.\n"
                . "- Proven ability to lead and motivate teams and resolve conflict.\n"
                . "- Commercial awareness and experience managing contractors.",
        ];
    }
}
