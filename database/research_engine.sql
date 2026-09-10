-- ============================================================
-- Running Man Archive — Research Engine tables (PR #4)
--
-- Run once in phpMyAdmin → runningman_archive → SQL tab, or from
-- Admin → Auto Sync → "Install research tables".
--
-- ADDITIVE AND IDEMPOTENT. It creates new tables and adds new columns
-- to scrape_runs / scrape_changes. It does not drop anything, does not
-- rewrite a single existing row, and does not touch the archive tables
-- (episodes, guests, thumbnails, …). Everything that reads these
-- degrades to a safe no-op when they are absent, so the site works
-- whether or not this has been run.
--
-- Requires database/scraping_engine.sql to have been run first.
-- ============================================================

SET NAMES utf8mb4;

-- ── Runs: the same run row, told properly ────────────────────
-- PR #1 gave scrape_runs its episode/source counters. What the Auto
-- Sync page needed and did not have is the difference between the RUN
-- being finished and the ARCHIVE being complete — so a run now records
-- what it was asked for, what it processed, and what is left, and its
-- status can say "completed with warnings", "paused" or "cancelled"
-- instead of collapsing all three into "partial".
ALTER TABLE scrape_runs
    MODIFY COLUMN status ENUM(
        'created','queued','running','paused',
        'completed','completed_with_warnings','failed','cancelled',
        'partial','aborted'                      -- legacy values, kept so old rows still read
    ) NOT NULL DEFAULT 'running';

ALTER TABLE scrape_runs
    ADD COLUMN IF NOT EXISTS run_ref            VARCHAR(40)  NULL AFTER run_id,
    ADD COLUMN IF NOT EXISTS research_mode      VARCHAR(20)  NULL AFTER mode,
    ADD COLUMN IF NOT EXISTS episodes_requested INT NOT NULL DEFAULT 0 AFTER episodes_checked,
    ADD COLUMN IF NOT EXISTS episodes_no_data   INT NOT NULL DEFAULT 0 AFTER episodes_skipped,
    ADD COLUMN IF NOT EXISTS episodes_review    INT NOT NULL DEFAULT 0 AFTER episodes_no_data,
    ADD COLUMN IF NOT EXISTS episodes_remaining INT NOT NULL DEFAULT 0 AFTER episodes_failed,
    ADD COLUMN IF NOT EXISTS evidence_count     INT NOT NULL DEFAULT 0 AFTER sources_skipped,
    ADD COLUMN IF NOT EXISTS avg_confidence     INT          NULL AFTER evidence_count,
    ADD COLUMN IF NOT EXISTS summary            VARCHAR(500) NULL AFTER notes,
    ADD COLUMN IF NOT EXISTS error_summary      VARCHAR(500) NULL AFTER summary,
    ADD COLUMN IF NOT EXISTS cancel_requested   TINYINT(1) NOT NULL DEFAULT 0 AFTER error_summary,
    ADD COLUMN IF NOT EXISTS heartbeat_at       DATETIME     NULL AFTER cancel_requested;

-- A run is looked up by its human reference constantly ("RUN-2026-09-04-001").
CREATE INDEX IF NOT EXISTS idx_run_ref ON scrape_runs (run_ref);

-- ── Change history: enough to answer "undo that" ─────────────
-- scrape_changes already records old → new with the reason. What it
-- lacked is which decision produced it and whether it was later undone.
ALTER TABLE scrape_changes
    ADD COLUMN IF NOT EXISTS decision    VARCHAR(12) NULL AFTER confidence,
    ADD COLUMN IF NOT EXISTS reverted_at DATETIME    NULL AFTER reason;

-- ── The research queue ───────────────────────────────────────
-- Persistent, so closing the tab does not destroy a run in progress
-- and a refresh cannot silently start a second one. Every row carries
-- WHY the episode is queued, because "386 episodes need sync" with no
-- reason is what made the old page impossible to act on.
CREATE TABLE IF NOT EXISTS research_queue (
    queue_id       BIGINT AUTO_INCREMENT PRIMARY KEY,
    run_id         INT          NOT NULL,
    episode_number INT          NOT NULL,
    position       INT          NOT NULL DEFAULT 0,
    priority       TINYINT      NOT NULL DEFAULT 5,      -- 1 = new episode … 6 = secondary metadata
    state          ENUM('queued','researching','completed','no_data','needs_review','failed','skipped','cancelled')
                   NOT NULL DEFAULT 'queued',
    reason         VARCHAR(300) NULL,                    -- why this episode is in the queue
    fields_wanted  VARCHAR(300) NULL,                    -- comma-separated; empty = everything
    attempts       INT          NOT NULL DEFAULT 0,
    confidence     INT          NULL,
    result_summary VARCHAR(300) NULL,
    started_at     DATETIME     NULL,
    finished_at    DATETIME     NULL,
    UNIQUE KEY uq_run_ep (run_id, episode_number),
    INDEX idx_run_state (run_id, state),
    INDEX idx_position  (run_id, position)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ── Per-episode research state ───────────────────────────────
-- The memory that stops the same 386 episodes being queued forever.
-- "We looked, on this date, with these sources, and this is what we
-- found" is a different fact from "this episode is incomplete", and
-- the archive needs both.
CREATE TABLE IF NOT EXISTS research_state (
    episode_number     INT PRIMARY KEY,
    status             ENUM('never_researched','researched','researched_no_new_data','researched_and_updated',
                            'researched_needs_review','research_failed','stale','conflict')
                       NOT NULL DEFAULT 'never_researched',
    last_researched_at DATETIME     NULL,
    last_run_id        INT          NULL,
    research_attempts  INT          NOT NULL DEFAULT 0,
    last_reason        VARCHAR(300) NULL,
    next_eligible_at   DATETIME     NULL,      -- do not re-research before this
    missing_fields     VARCHAR(300) NULL,      -- comma-separated snapshot at last research
    completeness       TINYINT      NULL,      -- 0..100
    confidence         TINYINT      NULL,      -- 0..100, lowest field confidence
    source_signature   CHAR(40)     NULL,      -- which sources+parser versions were tried
    updated_at         TIMESTAMP    NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    INDEX idx_status   (status),
    INDEX idx_eligible (next_eligible_at),
    INDEX idx_last     (last_researched_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ── Evidence ─────────────────────────────────────────────────
-- One row per (episode, field, source): what that source actually
-- said, normalised, and which independence group it belongs to. Four
-- sites carrying the same copied sentence are one piece of evidence,
-- not four, and that has to be recorded rather than recomputed.
CREATE TABLE IF NOT EXISTS research_evidence (
    evidence_id        BIGINT AUTO_INCREMENT PRIMARY KEY,
    run_id             INT          NULL,
    episode_number     INT          NOT NULL,
    field_name         VARCHAR(40)  NOT NULL,
    source_name        VARCHAR(40)  NOT NULL,
    source_url         VARCHAR(500) NULL,
    source_status      VARCHAR(30)  NOT NULL DEFAULT 'found',
    raw_value          TEXT         NULL,
    normalized_value   TEXT         NULL,
    value_hash         CHAR(40)     NULL,
    independence_group VARCHAR(60)  NULL,
    reliability        TINYINT      NOT NULL DEFAULT 50,   -- 0..100 for this source AND field
    created_at         TIMESTAMP    NOT NULL DEFAULT CURRENT_TIMESTAMP,
    INDEX idx_ep_field (episode_number, field_name),
    INDEX idx_run      (run_id),
    INDEX idx_group    (independence_group),
    INDEX idx_source   (source_name)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ── Decisions ────────────────────────────────────────────────
-- Why the engine chose what it chose. Every automatic write must be
-- answerable after the fact, or it is indistinguishable from a guess.
CREATE TABLE IF NOT EXISTS research_decisions (
    decision_id     BIGINT AUTO_INCREMENT PRIMARY KEY,
    run_id          INT          NULL,
    episode_number  INT          NOT NULL,
    field_name      VARCHAR(40)  NOT NULL,
    decision        ENUM('KEEP','UPDATE','FILL','REJECT','REVIEW','UNKNOWN') NOT NULL,
    chosen_value    TEXT         NULL,
    existing_value  TEXT         NULL,
    confidence      TINYINT      NOT NULL DEFAULT 0,       -- 0..100
    reason          VARCHAR(400) NULL,
    supporting      VARCHAR(300) NULL,                     -- sources agreeing
    dissenting      VARCHAR(300) NULL,                     -- sources disagreeing
    independent_n   TINYINT      NOT NULL DEFAULT 0,       -- independent groups agreeing
    applied         TINYINT(1)   NOT NULL DEFAULT 0,
    review_status   ENUM('open','accepted','kept_existing','ignored') NULL,
    decided_at      TIMESTAMP    NOT NULL DEFAULT CURRENT_TIMESTAMP,
    INDEX idx_ep      (episode_number),
    INDEX idx_run     (run_id),
    INDEX idx_dec     (decision),
    INDEX idx_review  (review_status),
    INDEX idx_ep_field(episode_number, field_name)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ── Source reputation ────────────────────────────────────────
-- Earned, not declared. field_name '*' is the source's global score;
-- one row per field records that SBS is excellent at air dates and
-- indifferent at synopses, which a single global number cannot say.
CREATE TABLE IF NOT EXISTS source_reputation (
    source_name     VARCHAR(40) NOT NULL,
    field_name      VARCHAR(40) NOT NULL DEFAULT '*',
    samples         INT NOT NULL DEFAULT 0,
    agreements      INT NOT NULL DEFAULT 0,
    disagreements   INT NOT NULL DEFAULT 0,
    parser_failures INT NOT NULL DEFAULT 0,
    fetch_failures  INT NOT NULL DEFAULT 0,
    contributions   INT NOT NULL DEFAULT 0,   -- times this source won the field
    reputation      TINYINT NOT NULL DEFAULT 50,
    updated_at      TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (source_name, field_name),
    INDEX idx_rep (reputation)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ── Discovered URLs ──────────────────────────────────────────
-- What public discovery found for an episode, so the next run does not
-- re-crawl a sitemap to learn the same thing.
CREATE TABLE IF NOT EXISTS research_discovery (
    discovery_id   BIGINT AUTO_INCREMENT PRIMARY KEY,
    episode_number INT          NOT NULL,
    source_name    VARCHAR(40)  NOT NULL,
    url            VARCHAR(500) NOT NULL,
    strategy       VARCHAR(30)  NOT NULL,     -- pattern|sitemap|feed|jsonld|canonical|internal_link|search
    status         VARCHAR(30)  NOT NULL DEFAULT 'candidate',
    confirmed_at   DATETIME     NULL,
    created_at     TIMESTAMP    NOT NULL DEFAULT CURRENT_TIMESTAMP,
    UNIQUE KEY uq_ep_url (episode_number, url(200)),
    INDEX idx_ep     (episode_number),
    INDEX idx_source (source_name)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Seed reputation rows for every registered source so the table is
-- never empty on a fresh install. Real scores replace these as soon as
-- the first run produces evidence.
--
-- PR #4 source policy: MyDramaList, TMDB, Wikidata and AsianWiki were
-- removed as unreliable/unused; TheTVDB and KShow123 replace them.
-- IMDb stays, but only as a diagnostics/verification-only source (see
-- config/scraping.php `sources.imdb.verification_only`) — installs
-- that already ran the old seed list should also run
-- database/pr4_source_cleanup.sql once to retire the stale rows.
INSERT IGNORE INTO source_reputation (source_name, field_name, reputation) VALUES
    ('sbs','*',75), ('wikipedia','*',75), ('kowiki','*',70),
    ('myrunningman','*',70), ('myrm','*',60),
    ('tvdb','*',65), ('kshow123','*',55), ('imdb','*',60),
    -- PR12: unproven in production — start below every existing source
    -- until each has an actual track record (see config/scraping.php
    -- sources.fandom/sources.tvmaze, tier=>1).
    ('fandom','*',50), ('tvmaze','*',50);

SELECT 'Research engine tables created' AS status;
