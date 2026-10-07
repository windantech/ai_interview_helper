-- =====================================================================
-- AI Interview Copilot — MySQL 8+ schema
-- Import:  mysql -u USER -p interview_copilot < database/schema.sql
-- Charset: utf8mb4 / utf8mb4_unicode_ci. All timestamps are stored in UTC.
-- =====================================================================

SET NAMES utf8mb4;
SET time_zone = '+00:00';
SET FOREIGN_KEY_CHECKS = 0;

DROP TABLE IF EXISTS rate_limits;
DROP TABLE IF EXISTS password_resets;
DROP TABLE IF EXISTS usage_logs;
DROP TABLE IF EXISTS interview_questions;
DROP TABLE IF EXISTS interview_sessions;
DROP TABLE IF EXISTS user_settings;
DROP TABLE IF EXISTS jobs;
DROP TABLE IF EXISTS user_cvs;
DROP TABLE IF EXISTS users;

SET FOREIGN_KEY_CHECKS = 1;

-- ---------------------------------------------------------------------
-- users
-- ---------------------------------------------------------------------
CREATE TABLE users (
    id              INT UNSIGNED NOT NULL AUTO_INCREMENT,
    name            VARCHAR(120) NOT NULL,
    email           VARCHAR(190) NOT NULL,
    password_hash   VARCHAR(255) NOT NULL,
    status          ENUM('active','suspended') NOT NULL DEFAULT 'active',
    last_login_at   DATETIME NULL,
    created_at      DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at      DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    UNIQUE KEY uq_users_email (email),
    KEY idx_users_status (status)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ---------------------------------------------------------------------
-- user_cvs  (one current CV per user; original file stored privately in
--            /storage/users/{user_id}/cv/{stored_filename})
-- ---------------------------------------------------------------------
CREATE TABLE user_cvs (
    id                 INT UNSIGNED NOT NULL AUTO_INCREMENT,
    user_id            INT UNSIGNED NOT NULL,
    original_filename  VARCHAR(255) NOT NULL,
    stored_filename    VARCHAR(100) NOT NULL,
    mime_type          VARCHAR(120) NOT NULL,
    file_size          INT UNSIGNED NOT NULL,
    cv_text            MEDIUMTEXT NULL,
    cv_profile_json    JSON NULL,
    openai_file_id     VARCHAR(100) NULL,
    status             ENUM('processing','ready','failed') NOT NULL DEFAULT 'processing',
    extraction_error   VARCHAR(255) NULL,
    created_at         DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at         DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    UNIQUE KEY uq_user_cvs_user (user_id),
    UNIQUE KEY uq_user_cvs_stored (stored_filename),
    CONSTRAINT fk_user_cvs_user FOREIGN KEY (user_id) REFERENCES users (id) ON DELETE CASCADE,
    CONSTRAINT chk_user_cvs_size CHECK (file_size > 0)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ---------------------------------------------------------------------
-- jobs (target positions)
-- ---------------------------------------------------------------------
CREATE TABLE jobs (
    id              INT UNSIGNED NOT NULL AUTO_INCREMENT,
    user_id         INT UNSIGNED NOT NULL,
    title           VARCHAR(160) NOT NULL,
    company         VARCHAR(160) NULL,
    industry        VARCHAR(120) NULL,
    location        VARCHAR(120) NULL,
    description     MEDIUMTEXT NOT NULL,
    main_skills     VARCHAR(500) NULL,
    interview_type  ENUM('general','hr','technical','behavioural','leadership','panel','management','graduate','executive') NOT NULL DEFAULT 'general',
    seniority       ENUM('intern','entry','mid','senior','manager','director','executive') NOT NULL DEFAULT 'mid',
    jd_summary_json JSON NULL,
    created_at      DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at      DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    KEY idx_jobs_user (user_id, updated_at),
    CONSTRAINT fk_jobs_user FOREIGN KEY (user_id) REFERENCES users (id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ---------------------------------------------------------------------
-- user_settings (interview preferences)
-- ---------------------------------------------------------------------
CREATE TABLE user_settings (
    user_id               INT UNSIGNED NOT NULL,
    default_answer_mode   ENUM('auto','quick','star','technical','leadership') NOT NULL DEFAULT 'auto',
    response_detail       ENUM('short','medium') NOT NULL DEFAULT 'short',
    default_interview_type ENUM('general','hr','technical','behavioural','leadership','panel','management','graduate','executive') NOT NULL DEFAULT 'general',
    auto_detect_question  TINYINT(1) NOT NULL DEFAULT 1,
    show_transcript       TINYINT(1) NOT NULL DEFAULT 1,
    save_history          TINYINT(1) NOT NULL DEFAULT 1,
    transcription_mode    ENUM('auto','live','recorded') NOT NULL DEFAULT 'auto',
    active_job_id         INT UNSIGNED NULL,
    mic_consent_at        DATETIME NULL,
    updated_at            DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (user_id),
    KEY idx_user_settings_job (active_job_id),
    CONSTRAINT fk_user_settings_user FOREIGN KEY (user_id) REFERENCES users (id) ON DELETE CASCADE,
    CONSTRAINT fk_user_settings_job FOREIGN KEY (active_job_id) REFERENCES jobs (id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ---------------------------------------------------------------------
-- interview_sessions
-- job_title / company are snapshots so history stays readable if the job is deleted.
-- ---------------------------------------------------------------------
CREATE TABLE interview_sessions (
    id            INT UNSIGNED NOT NULL AUTO_INCREMENT,
    user_id       INT UNSIGNED NOT NULL,
    job_id        INT UNSIGNED NULL,
    title         VARCHAR(200) NOT NULL,
    job_title     VARCHAR(160) NULL,
    company       VARCHAR(160) NULL,
    instructions  TEXT NULL COMMENT 'Candidate''s own instructions for this interview (sent with every question)',
    session_type  ENUM('live','practice','scan') NOT NULL DEFAULT 'live',
    status        ENUM('active','ended') NOT NULL DEFAULT 'active',
    started_at    DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    ended_at      DATETIME NULL,
    created_at    DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    KEY idx_sessions_user_started (user_id, started_at),
    KEY idx_sessions_user_status (user_id, status),
    KEY idx_sessions_job (job_id),
    CONSTRAINT fk_sessions_user FOREIGN KEY (user_id) REFERENCES users (id) ON DELETE CASCADE,
    CONSTRAINT fk_sessions_job FOREIGN KEY (job_id) REFERENCES jobs (id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ---------------------------------------------------------------------
-- interview_questions
-- ---------------------------------------------------------------------
CREATE TABLE interview_questions (
    id             INT UNSIGNED NOT NULL AUTO_INCREMENT,
    session_id     INT UNSIGNED NOT NULL,
    question       TEXT NOT NULL,
    question_number VARCHAR(20) NULL COMMENT 'Number/label as printed on a scanned paper (e.g. "3(b)")',
    raw_transcript TEXT NULL,
    question_type  VARCHAR(32) NOT NULL DEFAULT 'unknown',
    answer_mode    VARCHAR(20) NOT NULL DEFAULT 'auto',
    answer_json    JSON NULL,
    source         ENUM('live','recorded','typed','practice','scan') NOT NULL DEFAULT 'typed',
    user_answer    TEXT NULL,
    feedback_json  JSON NULL,
    latency_ms     INT UNSIGNED NULL,
    created_at     DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    KEY idx_questions_session (session_id, created_at),
    KEY idx_questions_type (question_type),
    CONSTRAINT fk_questions_session FOREIGN KEY (session_id) REFERENCES interview_sessions (id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ---------------------------------------------------------------------
-- usage_logs (OpenAI token usage / cost tracking)
-- ---------------------------------------------------------------------
CREATE TABLE usage_logs (
    id              BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    user_id         INT UNSIGNED NULL,
    action          VARCHAR(50) NOT NULL,
    model           VARCHAR(80) NOT NULL,
    input_tokens    INT UNSIGNED NOT NULL DEFAULT 0,
    output_tokens   INT UNSIGNED NOT NULL DEFAULT 0,
    estimated_cost  DECIMAL(12,6) NOT NULL DEFAULT 0,
    created_at      DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    KEY idx_usage_user_created (user_id, created_at),
    KEY idx_usage_action (action),
    CONSTRAINT fk_usage_user FOREIGN KEY (user_id) REFERENCES users (id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ---------------------------------------------------------------------
-- password_resets (only a SHA-256 hash of the token is stored)
-- ---------------------------------------------------------------------
CREATE TABLE password_resets (
    id          INT UNSIGNED NOT NULL AUTO_INCREMENT,
    user_id     INT UNSIGNED NOT NULL,
    token_hash  CHAR(64) NOT NULL,
    expires_at  DATETIME NOT NULL,
    used_at     DATETIME NULL,
    created_at  DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    UNIQUE KEY uq_password_resets_token (token_hash),
    KEY idx_password_resets_user (user_id),
    KEY idx_password_resets_expires (expires_at),
    CONSTRAINT fk_password_resets_user FOREIGN KEY (user_id) REFERENCES users (id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ---------------------------------------------------------------------
-- rate_limits (hashed keys; fixed window)
-- ---------------------------------------------------------------------
CREATE TABLE rate_limits (
    rate_key      CHAR(64) NOT NULL,
    action        VARCHAR(40) NOT NULL,
    hits          INT UNSIGNED NOT NULL DEFAULT 0,
    window_start  INT UNSIGNED NOT NULL,
    PRIMARY KEY (rate_key),
    KEY idx_rate_limits_window (window_start)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
