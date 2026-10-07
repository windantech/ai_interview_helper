-- Adds "Scan paper" support: a camera/file scan of a question paper becomes a session of its own,
-- and each question extracted from it is stored with source 'scan'.
-- Run once on databases created before this feature:
--   mysql -u USER -p DBNAME < database/migrations/2026_10_07_add_scan_sessions.sql
-- or paste it into phpMyAdmin → SQL. Fresh installs from schema.sql already include it.
ALTER TABLE interview_sessions
    MODIFY COLUMN session_type ENUM('live','practice','scan') NOT NULL DEFAULT 'live';

ALTER TABLE interview_questions
    MODIFY COLUMN source ENUM('live','recorded','typed','practice','scan') NOT NULL DEFAULT 'typed',
    ADD COLUMN question_number VARCHAR(20) NULL COMMENT 'Number/label as printed on a scanned paper (e.g. "3(b)")' AFTER question;
