-- ============================================================
-- Running Man Archive — Scraping Engine tables
-- Run once in phpMyAdmin → runningman_archive → SQL tab,
-- or from Admin → Scraper Control Centre → "Install tables".
--
-- ADDITIVE ONLY. This migration creates new tables and does not
-- ALTER, drop or rewrite anything that already exists — no existing
-- row, column, index or episode URL is affected. Every feature that
-- uses these tables degrades to a safe no-op when they are absent,
-- so the site keeps working whether or not this has been run.
-- ============================================================

SET NAMES utf8mb4;

-- ── Runs ─────────────────────────────────────────────────────
-- One row per scraping run: what it was asked to do, what it did,
-- how long it took, and where to resume if it was interrupted.
CREATE TABLE IF NOT EXISTS scrape_runs (
    run_id            INT AUTO_INCREMENT PRIMARY KEY,
    mode              VARCHAR(30)  NOT NULL,            -- latest|single|range|missing|failed|unstable|thumbnails|full
    scope             VARCHAR(120) NULL,
    dry_run           TINYINT(1)   NOT NULL DEFAULT 0,
    status            ENUM('running','completed','partial','failed','aborted') NOT NULL DEFAULT 'running',
    started_at        TIMESTAMP    NOT NULL DEFAULT CURRENT_TIMESTAMP,
    finished_at       DATETIME     NULL,
    duration_ms       INT          NULL,
    episodes_checked  INT          NOT NULL DEFAULT 0,
    episodes_added    INT          NOT NULL DEFAULT 0,
    episodes_updated  INT          NOT NULL DEFAULT 0,
    episodes_skipped  INT          NOT NULL DEFAULT 0,
    episodes_failed   INT          NOT NULL DEFAULT 0,
    -- Source outcomes are counted separately because they mean different
    -- things: ok = gave data · empty = healthy but has nothing for this
    -- episode · warned = reachable but parsed nothing (stale selectors)
    -- · failed = unreachable · skipped = never contacted.
    sources_ok        INT          NOT NULL DEFAULT 0,
    sources_empty     INT          NOT NULL DEFAULT 0,
    sources_warned    INT          NOT NULL DEFAULT 0,
    sources_failed    INT          NOT NULL DEFAULT 0,
    sources_skipped   INT          NOT NULL DEFAULT 0,
    last_episode      INT          NULL,                -- resume point
    cursor_state      TEXT         NULL,                -- JSON: episodes still queued
    notes             TEXT         NULL,
    INDEX idx_started (started_at),
    INDEX idx_status  (status),
    INDEX idx_mode    (mode)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ── Activity log ─────────────────────────────────────────────
-- Per-episode, per-source detail. This is what makes a failure
-- diagnosable instead of just "the scraper failed".
CREATE TABLE IF NOT EXISTS scrape_log (
    log_id         BIGINT AUTO_INCREMENT PRIMARY KEY,
    run_id         INT          NULL,
    episode_number INT          NULL,
    source_name    VARCHAR(40)  NULL,
    level          ENUM('debug','info','warning','error') NOT NULL DEFAULT 'info',
    event          VARCHAR(60)  NOT NULL,
    message        VARCHAR(500) NULL,
    duration_ms    INT          NULL,
    created_at     TIMESTAMP    NOT NULL DEFAULT CURRENT_TIMESTAMP,
    INDEX idx_run     (run_id),
    INDEX idx_ep      (episode_number),
    INDEX idx_created (created_at),
    INDEX idx_level   (level)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ── Source-level provenance ──────────────────────────────────
-- "Which sources were consulted for EP809, and what did each give?"
CREATE TABLE IF NOT EXISTS episode_sources (
    id              INT AUTO_INCREMENT PRIMARY KEY,
    episode_number  INT          NOT NULL,
    source_name     VARCHAR(40)  NOT NULL,
    source_url      VARCHAR(500) NULL,
    status          VARCHAR(30)  NOT NULL,      -- ok|empty|parser_warning|blocked|rate_limited|missing_episode|fetch_failed
    http_status     INT          NULL,
    parser_version  VARCHAR(20)  NULL,
    fields_provided VARCHAR(300) NULL,          -- comma-separated
    content_hash    CHAR(40)     NULL,
    duration_ms     INT          NULL,
    fetched_at      TIMESTAMP    NOT NULL DEFAULT CURRENT_TIMESTAMP,
    UNIQUE KEY uq_ep_source (episode_number, source_name),
    INDEX idx_source (source_name),
    INDEX idx_status (status)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ── Field-level provenance ───────────────────────────────────
-- "Where did EP809's air date come from, and how sure are we?"
CREATE TABLE IF NOT EXISTS episode_field_sources (
    id               INT AUTO_INCREMENT PRIMARY KEY,
    episode_number   INT          NOT NULL,
    field_name       VARCHAR(40)  NOT NULL,
    source_name      VARCHAR(40)  NOT NULL,
    source_url       VARCHAR(500) NULL,
    confidence       ENUM('high','medium','low','conflict') NOT NULL DEFAULT 'medium',
    agreeing_sources VARCHAR(200) NULL,
    conflicting      VARCHAR(400) NULL,
    value_hash       CHAR(40)     NULL,
    updated_at       TIMESTAMP    NOT NULL DEFAULT CURRENT_TIMESTAMP,
    UNIQUE KEY uq_ep_field (episode_number, field_name),
    INDEX idx_conf   (confidence),
    INDEX idx_source (source_name)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ── Change / diff log ────────────────────────────────────────
-- Every proposed change, applied or refused, with the reason.
CREATE TABLE IF NOT EXISTS scrape_changes (
    change_id      BIGINT AUTO_INCREMENT PRIMARY KEY,
    run_id         INT          NULL,
    episode_number INT          NOT NULL,
    field_name     VARCHAR(40)  NOT NULL,
    change_type    ENUM('added','changed','removed','item_added','item_removed','rejected','conflict','unchanged') NOT NULL,
    old_value      TEXT         NULL,
    new_value      TEXT         NULL,
    source_name    VARCHAR(40)  NULL,
    confidence     VARCHAR(10)  NULL,
    applied        TINYINT(1)   NOT NULL DEFAULT 0,
    reason         VARCHAR(200) NULL,
    created_at     TIMESTAMP    NOT NULL DEFAULT CURRENT_TIMESTAMP,
    INDEX idx_ep      (episode_number),
    INDEX idx_run     (run_id),
    INDEX idx_created (created_at),
    INDEX idx_type    (change_type)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ── Source health ────────────────────────────────────────────
CREATE TABLE IF NOT EXISTS source_health (
    source_name          VARCHAR(40) PRIMARY KEY,
    status               VARCHAR(20)  NOT NULL DEFAULT 'unknown',  -- ok|parser_warning|degraded|rate_limited|blocked|down|unknown
    last_status_class    VARCHAR(30)  NULL,
    last_error           VARCHAR(300) NULL,
    last_success_at      DATETIME     NULL,
    last_failure_at      DATETIME     NULL,
    last_attempt_at      DATETIME     NULL,
    success_count        INT          NOT NULL DEFAULT 0,
    failure_count        INT          NOT NULL DEFAULT 0,
    consecutive_failures INT          NOT NULL DEFAULT 0,
    empty_count          INT          NOT NULL DEFAULT 0,
    parser_warnings      INT          NOT NULL DEFAULT 0,
    avg_ms               INT          NOT NULL DEFAULT 0,
    parser_version       VARCHAR(20)  NULL,
    disabled_until       DATETIME     NULL,                        -- automatic cool-down
    updated_at           TIMESTAMP    NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    INDEX idx_status (status)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ── Guest aliases ────────────────────────────────────────────
-- "Lee Kwang-soo" / "Lee Kwang Soo" / "Lee Kwangsoo" → one guest_id.
CREATE TABLE IF NOT EXISTS guest_aliases (
    alias_id    INT AUTO_INCREMENT PRIMARY KEY,
    alias_key   VARCHAR(150) NOT NULL,          -- normalised identity key
    alias_raw   VARCHAR(150) NOT NULL,          -- spelling as the source wrote it
    guest_id    INT          NULL,
    status      ENUM('confirmed','auto','review') NOT NULL DEFAULT 'auto',
    source_name VARCHAR(40)  NULL,
    created_at  TIMESTAMP    NOT NULL DEFAULT CURRENT_TIMESTAMP,
    -- Prefixed so the key stays under InnoDB's 767-byte legacy index limit.
    UNIQUE KEY uq_alias (alias_key(80), alias_raw(80)),
    INDEX idx_key   (alias_key),
    INDEX idx_guest (guest_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ── Review flags ─────────────────────────────────────────────
-- Anything uncertain lands here instead of being auto-resolved:
-- possible duplicates, near-identical guest names, source conflicts,
-- parser warnings. Nothing in this table is ever acted on automatically.
CREATE TABLE IF NOT EXISTS review_flags (
    flag_id        INT AUTO_INCREMENT PRIMARY KEY,
    flag_type      VARCHAR(40) NOT NULL,        -- duplicate_episode|similar_guest|field_conflict|parser_warning|latest_ep_conflict|duplicate_thumbnail
    entity_type    VARCHAR(20) NOT NULL,        -- episode|guest|location|source
    entity_id      INT         NULL,
    episode_number INT         NULL,
    detail         TEXT        NULL,
    status         ENUM('open','resolved','ignored') NOT NULL DEFAULT 'open',
    created_at     TIMESTAMP   NOT NULL DEFAULT CURRENT_TIMESTAMP,
    resolved_at    DATETIME    NULL,
    INDEX idx_type   (flag_type),
    INDEX idx_status (status),
    INDEX idx_ep     (episode_number)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ── Thumbnail metadata ───────────────────────────────────────
-- Kept in its own table so the existing `thumbnails` table (and every
-- query and page that reads it) is left exactly as it is.
CREATE TABLE IF NOT EXISTS thumbnail_meta (
    episode_number  INT PRIMARY KEY,
    source_name     VARCHAR(40)  NULL,
    source_url      VARCHAR(500) NULL,
    local_path      VARCHAR(300) NULL,
    content_hash    CHAR(40)     NULL,
    width           INT          NULL,
    height          INT          NULL,
    bytes           INT          NULL,
    content_type    VARCHAR(60)  NULL,
    status          VARCHAR(20)  NOT NULL DEFAULT 'unknown',   -- ok|broken|rejected|duplicate|missing
    last_checked_at DATETIME     NULL,
    updated_at      TIMESTAMP    NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    INDEX idx_hash   (content_hash),
    INDEX idx_status (status)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ── Alternate titles ─────────────────────────────────────────
-- Korean/original titles preserved alongside the canonical English
-- one, rather than being discarded when the canonical title wins.
CREATE TABLE IF NOT EXISTS episode_alt_titles (
    id             INT AUTO_INCREMENT PRIMARY KEY,
    episode_number INT          NOT NULL,
    title          VARCHAR(300) NOT NULL,
    lang           VARCHAR(10)  NOT NULL DEFAULT 'ko',
    source_name    VARCHAR(40)  NULL,
    created_at     TIMESTAMP    NOT NULL DEFAULT CURRENT_TIMESTAMP,
    UNIQUE KEY uq_alt (episode_number, title(150)),
    INDEX idx_ep (episode_number)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Installs created before the source counters were split need the two
-- new columns added. CREATE TABLE IF NOT EXISTS above is a no-op for
-- them, so the ALTER carries the change; it is a no-op in turn on a
-- fresh install that already has the columns.
ALTER TABLE scrape_runs
    ADD COLUMN IF NOT EXISTS sources_empty  INT NOT NULL DEFAULT 0 AFTER sources_ok,
    ADD COLUMN IF NOT EXISTS sources_warned INT NOT NULL DEFAULT 0 AFTER sources_empty;

-- Seed the health table so every registered source has a row from day one.
INSERT IGNORE INTO source_health (source_name, status) VALUES
    ('sbs','unknown'), ('wikipedia','unknown'), ('kowiki','unknown'),
    ('myrunningman','unknown'), ('myrm','unknown'), ('mydramalist','unknown'),
    ('tmdb','unknown'), ('wikidata','unknown'),
    ('asianwiki','unknown'), ('imdb','unknown');

SELECT 'Scraping engine tables created' AS status;
