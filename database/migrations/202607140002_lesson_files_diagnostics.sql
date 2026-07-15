-- Non-destructive diagnostics only. These result sets must be empty before a future
-- lesson_files MyISAM -> InnoDB migration is considered.

SELECT 'lesson_files' AS relation_name, COUNT(*) AS orphan_count
FROM lesson_files lf LEFT JOIN lessons l ON l.id = lf.lesson_id WHERE l.id IS NULL
UNION ALL
SELECT 'lesson_images', COUNT(*)
FROM lesson_images li LEFT JOIN lessons l ON l.id = li.lesson_id WHERE l.id IS NULL
UNION ALL
SELECT 'lesson_videos', COUNT(*)
FROM lesson_videos lv LEFT JOIN lessons l ON l.id = lv.lesson_id WHERE l.id IS NULL
UNION ALL
SELECT 'section_items:LESSON', COUNT(*)
FROM section_items si LEFT JOIN lessons l ON l.id = si.item_ref_id
WHERE si.item_type = 'LESSON' AND l.id IS NULL;

-- Deliberately no ALTER TABLE here. lesson_files remains MyISAM until orphan rows,
-- column signedness, collation and deployment downtime have been reviewed.
