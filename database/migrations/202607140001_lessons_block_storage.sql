-- Lessons block storage. Run with: php database/migrate.php

ALTER TABLE lessons
    ADD COLUMN IF NOT EXISTS content_format VARCHAR(30) NOT NULL DEFAULT 'legacy_markdown' AFTER description,
    ADD COLUMN IF NOT EXISTS content_version SMALLINT UNSIGNED NOT NULL DEFAULT 1 AFTER content_format,
    ADD COLUMN IF NOT EXISTS legacy_description MEDIUMTEXT NULL AFTER content_version;

CREATE TABLE IF NOT EXISTS lesson_blocks (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    lesson_id INT NOT NULL,
    block_uid VARCHAR(64) NOT NULL,
    block_type VARCHAR(40) NOT NULL,
    position INT UNSIGNED NOT NULL,
    data_json LONGTEXT NOT NULL,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    UNIQUE KEY uq_lesson_blocks_uid (lesson_id, block_uid),
    KEY idx_lesson_blocks_order (lesson_id, position),
    CONSTRAINT chk_lesson_blocks_json CHECK (JSON_VALID(data_json)),
    CONSTRAINT fk_lesson_blocks_lesson FOREIGN KEY (lesson_id)
        REFERENCES lessons(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

CREATE TABLE IF NOT EXISTS lesson_media (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    lesson_id INT NULL,
    owner_user_id INT NOT NULL,
    draft_token CHAR(36) NULL,
    media_type VARCHAR(30) NOT NULL,
    storage_path VARCHAR(500) NOT NULL,
    original_name VARCHAR(255) NOT NULL,
    mime_type VARCHAR(100) NOT NULL,
    size_bytes BIGINT UNSIGNED NOT NULL,
    width INT UNSIGNED NULL,
    height INT UNSIGNED NULL,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    KEY idx_lesson_media_lesson (lesson_id),
    KEY idx_lesson_media_draft (draft_token),
    KEY idx_lesson_media_owner (owner_user_id),
    CONSTRAINT fk_lesson_media_lesson FOREIGN KEY (lesson_id)
        REFERENCES lessons(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
