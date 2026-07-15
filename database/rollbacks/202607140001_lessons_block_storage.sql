-- Destructive rollback. Back up first. Legacy lesson descriptions are not removed.

DROP TABLE IF EXISTS lesson_media;
DROP TABLE IF EXISTS lesson_blocks;

ALTER TABLE lessons
    DROP CONSTRAINT IF EXISTS chk_lessons_content_format,
    DROP COLUMN IF EXISTS legacy_description,
    DROP COLUMN IF EXISTS content_version,
    DROP COLUMN IF EXISTS content_format;

DELETE FROM schema_migrations
WHERE migration IN (
    '202607140001_lessons_block_storage.sql',
    '202607140002_lesson_files_diagnostics.sql'
    ,'202607140003_lessons_content_format_check.sql'
);
