-- ============================================================
-- Running Man Archive — Database Schema (Clean)
-- Run this FIRST on a fresh database named: runningman_archive
-- ============================================================

SET NAMES utf8mb4;
SET FOREIGN_KEY_CHECKS = 0;

-- Years
CREATE TABLE IF NOT EXISTS years (
    year_id     INT AUTO_INCREMENT PRIMARY KEY,
    year_label  YEAR        NOT NULL UNIQUE,
    total_eps   INT         NOT NULL DEFAULT 0,
    notes       TEXT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Locations
CREATE TABLE IF NOT EXISTS locations (
    location_id  INT AUTO_INCREMENT PRIMARY KEY,
    name         VARCHAR(200) NOT NULL,
    country      VARCHAR(100),
    city         VARCHAR(100),
    is_overseas  TINYINT(1) NOT NULL DEFAULT 0,
    latitude     DECIMAL(9,6),
    longitude    DECIMAL(9,6)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Themes
CREATE TABLE IF NOT EXISTS themes (
    theme_id    INT AUTO_INCREMENT PRIMARY KEY,
    name        VARCHAR(150) NOT NULL UNIQUE,
    description TEXT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Thumbnails
CREATE TABLE IF NOT EXISTS thumbnails (
    thumbnail_id    INT AUTO_INCREMENT PRIMARY KEY,
    episode_number  INT          NOT NULL UNIQUE,
    local_path      VARCHAR(300),
    thumbnail_url   VARCHAR(500),
    verified        TINYINT(1) NOT NULL DEFAULT 0,
    created_at      TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    INDEX idx_ep_num (episode_number),
    INDEX idx_verified (verified)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Episodes (main table)
CREATE TABLE IF NOT EXISTS episodes (
    episode_id           INT AUTO_INCREMENT PRIMARY KEY,
    episode_number       INT          NOT NULL UNIQUE,
    year_id              INT,
    title                VARCHAR(300) NOT NULL DEFAULT '',
    air_date             DATE,
    runtime_minutes      SMALLINT     DEFAULT 90,
    synopsis             TEXT,
    main_mission         VARCHAR(300),
    special_notes        TEXT,
    is_special           TINYINT(1)  NOT NULL DEFAULT 0,
    special_type         VARCHAR(80),
    location_id          INT,
    theme_id             INT,
    thumbnail_id         INT,
    verification_required TINYINT(1) NOT NULL DEFAULT 1,
    created_at           TIMESTAMP  DEFAULT CURRENT_TIMESTAMP,
    updated_at           TIMESTAMP  DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    FOREIGN KEY (year_id)     REFERENCES years(year_id)     ON DELETE SET NULL,
    FOREIGN KEY (location_id) REFERENCES locations(location_id) ON DELETE SET NULL,
    FOREIGN KEY (theme_id)    REFERENCES themes(theme_id)   ON DELETE SET NULL,
    FOREIGN KEY (thumbnail_id) REFERENCES thumbnails(thumbnail_id) ON DELETE SET NULL,
    INDEX idx_ep_num    (episode_number),
    INDEX idx_air_date  (air_date),
    INDEX idx_year      (year_id),
    INDEX idx_special   (is_special),
    INDEX idx_verify    (verification_required),
    FULLTEXT ft_search  (title, synopsis)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Guests
CREATE TABLE IF NOT EXISTS guests (
    guest_id            INT AUTO_INCREMENT PRIMARY KEY,
    name_romanized      VARCHAR(150) NOT NULL,
    name_korean         VARCHAR(100),
    profession          VARCHAR(100),
    nationality         VARCHAR(80) DEFAULT 'Korean',
    verification_required TINYINT(1) DEFAULT 0,
    INDEX idx_name (name_romanized)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Episode ↔ Guest (many-to-many)
CREATE TABLE IF NOT EXISTS episode_guests (
    episode_id  INT NOT NULL,
    guest_id    INT NOT NULL,
    PRIMARY KEY (episode_id, guest_id),
    FOREIGN KEY (episode_id) REFERENCES episodes(episode_id) ON DELETE CASCADE,
    FOREIGN KEY (guest_id)   REFERENCES guests(guest_id)     ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Tags
CREATE TABLE IF NOT EXISTS tags (
    tag_id  INT AUTO_INCREMENT PRIMARY KEY,
    name    VARCHAR(100) NOT NULL UNIQUE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Episode ↔ Tag (many-to-many)
CREATE TABLE IF NOT EXISTS episode_tags (
    episode_id  INT NOT NULL,
    tag_id      INT NOT NULL,
    PRIMARY KEY (episode_id, tag_id),
    FOREIGN KEY (episode_id) REFERENCES episodes(episode_id) ON DELETE CASCADE,
    FOREIGN KEY (tag_id)     REFERENCES tags(tag_id)         ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

SET FOREIGN_KEY_CHECKS = 1;
