-- ============================================================
-- IndicLex — Multilingual Dictionary Management & Search Platform
-- ICS325 — Web Application Development
-- MySQL Database Schema  v1.1
--
-- Changes from v1:
--   • dictionaries : added "type" column (bilingual / trilingual)
--                    added source_lang_3 for the optional 3rd language
--   • dictionary_entries : replaced word + translation with
--                          lang_1, lang_2, lang_3 columns
--                          lang_3 is NULL when type = 'bilingual'
-- ============================================================

-- Change to your database name
-- CREATE DATABASE IF NOT EXISTS indiclex CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
-- USE indiclex;

-- ============================================================
-- TABLE: users
-- ============================================================
CREATE TABLE users (
    user_id       INT AUTO_INCREMENT PRIMARY KEY,
    username      VARCHAR(50)  NOT NULL UNIQUE,
    email         VARCHAR(100) NOT NULL UNIQUE,
    password_hash VARCHAR(255) NOT NULL,           -- bcrypt hash, never plaintext
    role          ENUM('admin', 'visitor') NOT NULL DEFAULT 'visitor',
    is_active     BOOLEAN      NOT NULL DEFAULT TRUE,
    created_at    DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
    last_login    DATETIME     NULL
) ENGINE=InnoDB;

-- ============================================================
-- TABLE: dictionaries
--
-- type           : 'bilingual'  — two languages (lang_3 unused)
--                  'trilingual' — three languages (lang_3 filled)
--
-- source_lang_1  : primary / source language    (always required)
-- source_lang_2  : second language              (always required)
-- source_lang_3  : third language               (NULL when bilingual)
-- ============================================================
CREATE TABLE dictionaries (
    dict_id         INT AUTO_INCREMENT PRIMARY KEY,
    dict_identifier VARCHAR(100) NOT NULL UNIQUE,   -- e.g. 'english-telugu-hindi'
    name            VARCHAR(150) NOT NULL,           -- e.g. 'English–Telugu–Hindi Dictionary'
    type            ENUM('bilingual', 'trilingual') NOT NULL DEFAULT 'bilingual',
    source_lang_1   VARCHAR(80)  NOT NULL,           -- e.g. 'Telugu'
    source_lang_2   VARCHAR(80)  NOT NULL,           -- e.g. 'English'
    source_lang_3   VARCHAR(80)  NULL,               -- e.g. 'Hindi' — NULL if bilingual
    description     TEXT         NULL,
    entry_count     INT          NOT NULL DEFAULT 0, -- kept in sync by triggers
    is_active       BOOLEAN      NOT NULL DEFAULT TRUE,
    created_by      INT          NULL,
    created_at      DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at      DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,

    CONSTRAINT fk_dict_created_by FOREIGN KEY (created_by) REFERENCES users(user_id) ON DELETE SET NULL
) ENGINE=InnoDB;

-- ============================================================
-- TABLE: dictionary_entries
--
-- lang_1  : word in source_lang_1  (always required)
-- lang_2  : word in source_lang_2  (always required)
-- lang_3  : word in source_lang_3  (NULL when dictionary type = 'bilingual')
-- ============================================================
CREATE TABLE dictionary_entries (
    entry_id       INT AUTO_INCREMENT PRIMARY KEY,
    dict_id        INT          NOT NULL,
    lang_1         VARCHAR(255) NOT NULL,            -- Word / phrase in language 1
    lang_2         VARCHAR(255) NOT NULL,            -- Word / phrase in language 2
    lang_3         VARCHAR(255) NULL,                -- Word / phrase in language 3 (NULL if bilingual)
    pronunciation  VARCHAR(255) NULL,                -- Optional phonetic guide (for lang_1)
    part_of_speech VARCHAR(50)  NULL,                -- e.g. noun, verb, adjective
    example        TEXT         NULL,                -- Usage example sentence
    notes          TEXT         NULL,
    is_active      BOOLEAN      NOT NULL DEFAULT TRUE,
    created_at     DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at     DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,

    CONSTRAINT fk_entry_dict FOREIGN KEY (dict_id) REFERENCES dictionaries(dict_id) ON DELETE CASCADE,

    -- Prevent duplicate lang_1 entries within the same dictionary
    UNIQUE KEY uq_dict_lang1 (dict_id, lang_1),

    -- Indexes for all four search modes across all three word columns
    INDEX idx_lang1 (lang_1),
    INDEX idx_lang2 (lang_2),
    INDEX idx_lang3 (lang_3),
    FULLTEXT INDEX ft_all_langs (lang_1, lang_2, lang_3)
) ENGINE=InnoDB;

-- ============================================================
-- TABLE: preferences
-- ============================================================
CREATE TABLE preferences (
    pref_id     INT AUTO_INCREMENT PRIMARY KEY,
    pref_key    VARCHAR(80)  NOT NULL UNIQUE,  -- e.g. 'default_dict', 'results_per_page', 'theme'
    pref_value  VARCHAR(255) NOT NULL,
    description VARCHAR(255) NULL,
    updated_at  DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
) ENGINE=InnoDB;

-- ============================================================
-- TRIGGERS: Keep entry_count in sync
-- ============================================================
DELIMITER //

CREATE TRIGGER trg_entry_insert
AFTER INSERT ON dictionary_entries
FOR EACH ROW
BEGIN
    UPDATE dictionaries SET entry_count = entry_count + 1 WHERE dict_id = NEW.dict_id;
END;
//

CREATE TRIGGER trg_entry_delete
AFTER DELETE ON dictionary_entries
FOR EACH ROW
BEGIN
    UPDATE dictionaries SET entry_count = entry_count - 1 WHERE dict_id = OLD.dict_id;
END;
//

DELIMITER ;

-- ============================================================
-- SEED DATA: System Default Preferences
-- ============================================================
INSERT INTO preferences (pref_key, pref_value, description) VALUES
    ('default_dict',     'all',   'Default dictionary pre-selected on search page. "all" = search across all.'),
    ('results_per_page', '10',    'Default number of search results displayed per page.'),
    ('theme',            'light', 'Default UI theme. Options: light, dark.');

-- ============================================================
-- SEED DATA: Default Admin User
-- ⚠ Replace the hash below with a real bcrypt hash before deploying!
-- ============================================================
INSERT INTO users (username, email, password_hash, role) VALUES
    ('admin', 'admin@indiclex.com', '$2y$12$placeholderHashReplaceMe', 'admin');

-- ============================================================
-- SAMPLE DATA: Dictionaries
-- ============================================================
INSERT INTO dictionaries (dict_identifier, name, type, source_lang_1, source_lang_2, source_lang_3, description, created_by)
VALUES
    ('telugu-english',
     'Telugu–English Dictionary',
     'bilingual', 'Telugu', 'English', NULL,
     'Comprehensive Telugu to English word list.', 1),

    ('sanskrit-english',
     'Sanskrit–English Dictionary',
     'bilingual', 'Sanskrit', 'English', NULL,
     'Classical Sanskrit vocabulary with English translations.', 1),

    ('english-telugu-hindi',
     'English–Telugu–Hindi Dictionary',
     'trilingual', 'English', 'Telugu', 'Hindi',
     'Trilingual dictionary covering English, Telugu, and Hindi.', 1);

-- ============================================================
-- SAMPLE DATA: Entries
-- ============================================================

-- Dict 1: Telugu–English (bilingual — lang_3 is NULL)
INSERT INTO dictionary_entries (dict_id, lang_1, lang_2, lang_3, pronunciation, part_of_speech) VALUES
    (1, 'నమస్కారం', 'greeting / salutation', NULL, 'namaskaaram', 'noun'),
    (1, 'ప్రేమ',    'love / affection',       NULL, 'prema',       'noun'),
    (1, 'ధర్మం',   'duty / righteousness',   NULL, 'dharmam',     'noun');

-- Dict 2: Sanskrit–English (bilingual — lang_3 is NULL)
INSERT INTO dictionary_entries (dict_id, lang_1, lang_2, lang_3, pronunciation, part_of_speech) VALUES
    (2, 'धर्म',  'duty / righteousness / moral law', NULL, 'dharma',  'noun'),
    (2, 'कर्म',  'action / deed / fate',             NULL, 'karma',   'noun'),
    (2, 'योग',   'union / discipline / practice',    NULL, 'yoga',    'noun');

-- Dict 3: English–Telugu–Hindi (trilingual — all three columns filled)
INSERT INTO dictionary_entries (dict_id, lang_1, lang_2, lang_3, pronunciation, part_of_speech) VALUES
    (3, 'hello', 'నమస్కారం', 'नमस्ते', 'hɛloʊ',  'exclamation'),
    (3, 'love',  'ప్రేమ',    'प्यार',  NULL,      'noun'),
    (3, 'water', 'నీరు',     'पानी',   NULL,      'noun');

-- ============================================================
-- USEFUL VIEWS
-- ============================================================

-- View: Dictionary stats for the Reports page
CREATE VIEW vw_dictionary_stats AS
SELECT
    dict_id,
    dict_identifier,
    name,
    type,
    source_lang_1,
    source_lang_2,
    source_lang_3,
    entry_count,
    is_active,
    created_at
FROM dictionaries
WHERE is_active = TRUE;

-- View: Language-wise word count for the Reports page
CREATE VIEW vw_language_stats AS
SELECT source_lang_1 AS language, COUNT(*) AS dictionary_count, SUM(entry_count) AS total_entries
FROM dictionaries WHERE is_active = TRUE GROUP BY source_lang_1
UNION
SELECT source_lang_2, COUNT(*), SUM(entry_count)
FROM dictionaries WHERE is_active = TRUE GROUP BY source_lang_2
UNION
SELECT source_lang_3, COUNT(*), SUM(entry_count)
FROM dictionaries WHERE is_active = TRUE AND source_lang_3 IS NOT NULL GROUP BY source_lang_3;

-- ============================================================
-- END OF SCHEMA  v1.1
-- ============================================================
