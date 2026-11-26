-- Select the intended database in phpMyAdmin before importing this file.
-- Safe to import again: existing entries and the counter are preserved.
CREATE TABLE IF NOT EXISTS waitlist_counter (
    id TINYINT UNSIGNED NOT NULL PRIMARY KEY,
    next_number INT UNSIGNED NOT NULL DEFAULT 1,
    CONSTRAINT waitlist_counter_singleton CHECK (id = 1),
    CONSTRAINT waitlist_counter_range CHECK (next_number BETWEEN 1 AND 10001)
) ENGINE=InnoDB;

INSERT IGNORE INTO waitlist_counter (id, next_number) VALUES (1, 1);

CREATE TABLE IF NOT EXISTS waitlist_entries (
    member_number INT UNSIGNED NOT NULL PRIMARY KEY,
    email VARCHAR(254) CHARACTER SET ascii COLLATE ascii_general_ci NOT NULL,
    first_name VARCHAR(100) NULL,
    professional_title VARCHAR(100) NULL,
    referral_code CHAR(18) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
    referred_by INT UNSIGNED NULL,
    edit_token_hash CHAR(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    profile_completed_at DATETIME NULL,
    email_verified_at DATETIME NULL,
    UNIQUE KEY waitlist_email_unique (email),
    UNIQUE KEY waitlist_referral_unique (referral_code),
    UNIQUE KEY waitlist_token_unique (edit_token_hash),
    CONSTRAINT waitlist_member_range CHECK (member_number BETWEEN 1 AND 10000),
    CONSTRAINT waitlist_referrer_fk FOREIGN KEY (referred_by)
        REFERENCES waitlist_entries (member_number) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS waitlist_skills (
    member_number INT UNSIGNED NOT NULL,
    position TINYINT UNSIGNED NOT NULL,
    skill VARCHAR(80) NOT NULL,
    PRIMARY KEY (member_number, position),
    UNIQUE KEY waitlist_skill_unique (member_number, skill),
    CONSTRAINT waitlist_skill_position CHECK (position BETWEEN 1 AND 3),
    CONSTRAINT waitlist_skills_entry_fk FOREIGN KEY (member_number)
        REFERENCES waitlist_entries (member_number) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_bin;

CREATE TABLE IF NOT EXISTS waitlist_rate_limits (
    bucket_hash CHAR(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL PRIMARY KEY,
    hits INT UNSIGNED NOT NULL DEFAULT 1,
    expires_at DATETIME NOT NULL,
    KEY waitlist_rate_expiry (expires_at)
) ENGINE=InnoDB;
