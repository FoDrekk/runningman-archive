-- ============================================================
-- Stability Infrastructure — Activity Log + Sync State + Lock
-- Run this once in phpMyAdmin → runningman_archive → SQL tab
-- ============================================================

SET NAMES utf8mb4;

-- Centralized log for every admin action (fetch, sync, cron, import, thumbnail)
-- Replaces the plain-text cron.log with something queryable + viewable in UI
CREATE TABLE IF NOT EXISTS activity_log (
    log_id         INT AUTO_INCREMENT PRIMARY KEY,
    action_type    VARCHAR(30)  NOT NULL,   -- fetch | sync | cron | import | thumbnail
    episode_number INT          NULL,
    status         ENUM('success','failed','skipped') NOT NULL,
    message        VARCHAR(500) NULL,
    duration_ms    INT          NULL,
    created_at     TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    INDEX idx_action  (action_type),
    INDEX idx_status  (status),
    INDEX idx_created (created_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Key-value store for persistent state: lock flags, resume position, last-run timestamps
-- Survives page refresh, browser close, and works across concurrent PHP requests
CREATE TABLE IF NOT EXISTS sync_state (
    state_key   VARCHAR(60) PRIMARY KEY,
    state_value TEXT,
    updated_at  TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Seed default state
INSERT IGNORE INTO sync_state (state_key, state_value) VALUES
    ('sync_lock',        '0'),
    ('sync_last_ep',     '0'),
    ('sync_started_at',  ''),
    ('sync_total',       '0'),
    ('sync_done',        '0'),
    ('cron_lock',        '0'),
    ('last_health_check','');

SELECT 'Stability tables created' AS status;
