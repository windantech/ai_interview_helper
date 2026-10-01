-- Adds per-interview instructions (e.g. "When asked for a sample project, use finKAP").
-- Run once on databases created before this feature:
--   mysql -u USER -p DBNAME < database/migrations/2026_10_01_add_session_instructions.sql
-- or paste it into phpMyAdmin → SQL. Fresh installs from schema.sql already include it.
ALTER TABLE interview_sessions
    ADD COLUMN instructions TEXT NULL COMMENT 'Candidate''s own instructions for this interview (sent with every question)' AFTER company;
