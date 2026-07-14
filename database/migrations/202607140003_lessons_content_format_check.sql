ALTER TABLE lessons
    ADD CONSTRAINT chk_lessons_content_format
    CHECK (content_format IN ('legacy_markdown', 'blocks_v1'));
