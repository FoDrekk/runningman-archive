-- ============================================================
-- Running Man Archive — PR #4 AI reasoning + diagnostics tables
--
-- Run once in phpMyAdmin -> runningman_archive -> SQL tab, after
-- database/research_engine.sql. ADDITIVE ONLY: new tables only,
-- nothing existing is altered. Every feature that uses them degrades
-- to a safe no-op when they are absent (AI generation is simply
-- skipped, field locks are treated as "nothing is locked", diagnostic
-- report runs still work from in-memory data), so the site works
-- either way.
-- ============================================================

SET NAMES utf8mb4;

-- ── AI generation log ────────────────────────────────────────
-- One row per AI decision, whatever it decided. Existing verified data
-- is never touched by this table (RmAiSynopsisService never overwrites
-- a usable synopsis) — this is a record of what the AI layer was
-- asked, what it decided, and why, so "why does EP809 have an
-- AI-generated synopsis?" and "how many synopses has the AI written
-- this month?" both have an answer.
CREATE TABLE IF NOT EXISTS ai_generation_log (
    log_id            BIGINT AUTO_INCREMENT PRIMARY KEY,
    run_id            INT          NULL,
    episode_number    INT          NOT NULL,
    field_name        VARCHAR(40)  NOT NULL DEFAULT 'synopsis',
    model             VARCHAR(60)  NULL,
    decision          ENUM('GENERATE','REQUEST_REVIEW','REJECT','INSUFFICIENT_EVIDENCE',
                            'KEEP_EXISTING','NO_USABLE_DATA','RETRY_LATER','DISABLED')
                      NOT NULL,
    confidence        TINYINT      NULL,          -- 0..100
    evidence_basis    VARCHAR(300) NULL,           -- which fields/sources it drew from
    generated_value   TEXT         NULL,
    grounding_status  ENUM('passed','revised','rejected') NULL,
    grounding_issues  VARCHAR(500) NULL,
    applied           TINYINT(1)   NOT NULL DEFAULT 0,
    created_at        TIMESTAMP    NOT NULL DEFAULT CURRENT_TIMESTAMP,
    INDEX idx_ep       (episode_number),
    INDEX idx_run       (run_id),
    INDEX idx_decision  (decision),
    INDEX idx_created   (created_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ── Field locks ──────────────────────────────────────────────
-- A locked field is never touched by the research engine or AI layer —
-- every decision for it is forced to KEEP before anything else runs.
-- field_name = '*' locks the whole episode.
CREATE TABLE IF NOT EXISTS field_locks (
    episode_number INT          NOT NULL,
    field_name      VARCHAR(40) NOT NULL,
    locked_by       VARCHAR(80) NULL,
    reason          VARCHAR(300) NULL,
    locked_at       TIMESTAMP   NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (episode_number, field_name)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

SELECT 'PR #4 AI + diagnostics tables created' AS status;
