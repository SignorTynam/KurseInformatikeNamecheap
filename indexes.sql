/* =========================
   USERS
   ========================= */
-- UNIQUE su email (se manca)
SET @idx := (
  SELECT COUNT(*) FROM information_schema.STATISTICS
  WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME='users'
    AND NON_UNIQUE=0 AND INDEX_NAME <> 'PRIMARY' AND COLUMN_NAME='email'
);
SET @sql := IF(@idx=0, 'ALTER TABLE users ADD UNIQUE KEY uq_users_email (email);', 'SELECT 1');
PREPARE s FROM @sql; EXECUTE s; DEALLOCATE PREPARE s;

/* =========================
   MESSAGES
   ========================= */
SET @idx := (
  SELECT COUNT(*) FROM information_schema.STATISTICS
   WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='messages' AND INDEX_NAME='idx_msg_unread_created'
);
SET @sql := IF(@idx=0, 'CREATE INDEX idx_msg_unread_created ON messages(read_status, created_at);', 'SELECT 1');
PREPARE s FROM @sql; EXECUTE s; DEALLOCATE PREPARE s;

/* =========================
   SECTIONS
   ========================= */
SET @idx := (
  SELECT COUNT(*) FROM information_schema.STATISTICS
   WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='sections' AND INDEX_NAME='idx_sections_course'
);
SET @sql := IF(@idx=0, 'CREATE INDEX idx_sections_course ON sections(course_id);', 'SELECT 1');
PREPARE s FROM @sql; EXECUTE s; DEALLOCATE PREPARE s;

SET @idx := (
  SELECT COUNT(*) FROM information_schema.STATISTICS
   WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='sections' AND INDEX_NAME='uq_sections_course_position'
);
SET @sql := IF(@idx=0,
  'ALTER TABLE sections ADD UNIQUE KEY uq_sections_course_position (course_id, position);',
  'SELECT 1');
PREPARE s FROM @sql; EXECUTE s; DEALLOCATE PREPARE s;

/* =========================
   LESSONS
   ========================= */
SET @idx := (
  SELECT COUNT(*) FROM information_schema.STATISTICS
   WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='lessons' AND INDEX_NAME='idx_lessons_course_section'
);
SET @sql := IF(@idx=0, 'CREATE INDEX idx_lessons_course_section ON lessons(course_id, section_id);', 'SELECT 1');
PREPARE s FROM @sql; EXECUTE s; DEALLOCATE PREPARE s;

SET @idx := (
  SELECT COUNT(*) FROM information_schema.STATISTICS
   WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='lessons' AND INDEX_NAME='idx_lessons_cat'
);
SET @sql := IF(@idx=0, 'CREATE INDEX idx_lessons_cat ON lessons(category);', 'SELECT 1');
PREPARE s FROM @sql; EXECUTE s; DEALLOCATE PREPARE s;

SET @idx := (
  SELECT COUNT(*) FROM information_schema.STATISTICS
   WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='lessons' AND INDEX_NAME='idx_lessons_cat_sec'
);
SET @sql := IF(@idx=0, 'CREATE INDEX idx_lessons_cat_sec ON lessons(category, section_id);', 'SELECT 1');
PREPARE s FROM @sql; EXECUTE s; DEALLOCATE PREPARE s;

/* =========================
   LESSON_FILES
   ========================= */
SET @idx := (
  SELECT COUNT(*) FROM information_schema.STATISTICS
   WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='lesson_files' AND INDEX_NAME='idx_lf_lesson'
);
SET @sql := IF(@idx=0, 'CREATE INDEX idx_lf_lesson ON lesson_files(lesson_id);', 'SELECT 1');
PREPARE s FROM @sql; EXECUTE s; DEALLOCATE PREPARE s;

/* =========================
   ENROLL
   ========================= */
SET @idx := (
  SELECT COUNT(*) FROM information_schema.STATISTICS
   WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='enroll' AND INDEX_NAME='uq_course_user'
);
SET @sql := IF(@idx=0, 'ALTER TABLE enroll ADD UNIQUE KEY uq_course_user (course_id, user_id);', 'SELECT 1');
PREPARE s FROM @sql; EXECUTE s; DEALLOCATE PREPARE s;

SET @idx := (
  SELECT COUNT(*) FROM information_schema.STATISTICS
   WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='enroll' AND INDEX_NAME='idx_enroll_course'
);
SET @sql := IF(@idx=0, 'CREATE INDEX idx_enroll_course ON enroll(course_id);', 'SELECT 1');
PREPARE s FROM @sql; EXECUTE s; DEALLOCATE PREPARE s;

SET @idx := (
  SELECT COUNT(*) FROM information_schema.STATISTICS
   WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='enroll' AND INDEX_NAME='idx_enroll_user'
);
SET @sql := IF(@idx=0, 'CREATE INDEX idx_enroll_user ON enroll(user_id);', 'SELECT 1');
PREPARE s FROM @sql; EXECUTE s; DEALLOCATE PREPARE s;

/* =========================
   PAYMENTS
   ========================= */
SET @idx := (
  SELECT COUNT(*) FROM information_schema.STATISTICS
   WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='payments' AND INDEX_NAME='idx_pay_course'
);
SET @sql := IF(@idx=0, 'CREATE INDEX idx_pay_course ON payments(course_id);', 'SELECT 1');
PREPARE s FROM @sql; EXECUTE s; DEALLOCATE PREPARE s;

SET @idx := (
  SELECT COUNT(*) FROM information_schema.STATISTICS
   WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='payments' AND INDEX_NAME='idx_pay_user'
);
SET @sql := IF(@idx=0, 'CREATE INDEX idx_pay_user ON payments(user_id);', 'SELECT 1');
PREPARE s FROM @sql; EXECUTE s; DEALLOCATE PREPARE s;

SET @idx := (
  SELECT COUNT(*) FROM information_schema.STATISTICS
   WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='payments' AND INDEX_NAME='idx_pay_lesson'
);
SET @sql := IF(@idx=0, 'CREATE INDEX idx_pay_lesson ON payments(lesson_id);', 'SELECT 1');
PREPARE s FROM @sql; EXECUTE s; DEALLOCATE PREPARE s;

SET @idx := (
  SELECT COUNT(*) FROM information_schema.STATISTICS
   WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='payments' AND INDEX_NAME='idx_pay_status_date'
);
SET @sql := IF(@idx=0, 'CREATE INDEX idx_pay_status_date ON payments(payment_status, payment_date);', 'SELECT 1');
PREPARE s FROM @sql; EXECUTE s; DEALLOCATE PREPARE s;

/* =========================
   APPOINTMENTS
   ========================= */
SET @idx := (
  SELECT COUNT(*) FROM information_schema.STATISTICS
  WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='appointments' AND INDEX_NAME='idx_appt_course_date'
);
SET @sql := IF(@idx=0, 'CREATE INDEX idx_appt_course_date ON appointments(course_id, appointment_date);', 'SELECT 1');
PREPARE s FROM @sql; EXECUTE s; DEALLOCATE PREPARE s;

/* =========================
   ASSIGNMENTS
   ========================= */
SET @idx := (
  SELECT COUNT(*) FROM information_schema.STATISTICS
  WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='assignments' AND INDEX_NAME='idx_asg_course'
);
SET @sql := IF(@idx=0, 'CREATE INDEX idx_asg_course ON assignments(course_id);', 'SELECT 1');
PREPARE s FROM @sql; EXECUTE s; DEALLOCATE PREPARE s;

SET @idx := (
  SELECT COUNT(*) FROM information_schema.STATISTICS
  WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='assignments' AND INDEX_NAME='idx_asg_section'
);
SET @sql := IF(@idx=0, 'CREATE INDEX idx_asg_section ON assignments(section_id);', 'SELECT 1');
PREPARE s FROM @sql; EXECUTE s; DEALLOCATE PREPARE s;

/* =========================
   ASSIGNMENTS_FILES
   ========================= */
SET @idx := (
  SELECT COUNT(*) FROM information_schema.STATISTICS
  WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='assignments_files' AND INDEX_NAME='idx_asgf_asg'
);
SET @sql := IF(@idx=0, 'CREATE INDEX idx_asgf_asg ON assignments_files(assignment_id);', 'SELECT 1');
PREPARE s FROM @sql; EXECUTE s; DEALLOCATE PREPARE s;

/* =========================
   ASSIGNMENTS_SUBMITTED
   ========================= */
SET @idx := (
  SELECT COUNT(*) FROM information_schema.STATISTICS
  WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='assignments_submitted' AND INDEX_NAME='idx_asgs_asg'
);
SET @sql := IF(@idx=0, 'CREATE INDEX idx_asgs_asg ON assignments_submitted(assignment_id);', 'SELECT 1');
PREPARE s FROM @sql; EXECUTE s; DEALLOCATE PREPARE s;

SET @idx := (
  SELECT COUNT(*) FROM information_schema.STATISTICS
  WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='assignments_submitted' AND INDEX_NAME='idx_asgs_user'
);
SET @sql := IF(@idx=0, 'CREATE INDEX idx_asgs_user ON assignments_submitted(user_id);', 'SELECT 1');
PREPARE s FROM @sql; EXECUTE s; DEALLOCATE PREPARE s;

/* =========================
   EVENTS
   ========================= */
SET @idx := (
  SELECT COUNT(*) FROM information_schema.STATISTICS
  WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='events' AND INDEX_NAME='idx_events_creator'
);
SET @sql := IF(@idx=0, 'CREATE INDEX idx_events_creator ON events(id_creator);', 'SELECT 1');
PREPARE s FROM @sql; EXECUTE s; DEALLOCATE PREPARE s;

SET @idx := (
  SELECT COUNT(*) FROM information_schema.STATISTICS
  WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='events' AND INDEX_NAME='idx_events_datetime'
);
SET @sql := IF(@idx=0, 'CREATE INDEX idx_events_datetime ON events(event_datetime);', 'SELECT 1');
PREPARE s FROM @sql; EXECUTE s; DEALLOCATE PREPARE s;

/* =========================
   ENROLL_EVENTS
   ========================= */
SET @idx := (
  SELECT COUNT(*) FROM information_schema.STATISTICS
  WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='enroll_events' AND INDEX_NAME='idx_enre_event'
);
SET @sql := IF(@idx=0, 'CREATE INDEX idx_enre_event ON enroll_events(event_id);', 'SELECT 1');
PREPARE s FROM @sql; EXECUTE s; DEALLOCATE PREPARE s;

/* =========================
   NOTES
   ========================= */
SET @idx := (
  SELECT COUNT(*) FROM information_schema.STATISTICS
  WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='notes' AND INDEX_NAME='idx_notes_user'
);
SET @sql := IF(@idx=0, 'CREATE INDEX idx_notes_user ON notes(user_id);', 'SELECT 1');
PREPARE s FROM @sql; EXECUTE s; DEALLOCATE PREPARE s;

SET @idx := (
  SELECT COUNT(*) FROM information_schema.STATISTICS
  WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='notes' AND INDEX_NAME='idx_notes_lesson'
);
SET @sql := IF(@idx=0, 'CREATE INDEX idx_notes_lesson ON notes(lesson_id);', 'SELECT 1');
PREPARE s FROM @sql; EXECUTE s; DEALLOCATE PREPARE s;

/* =========================
   THREADS
   ========================= */
SET @idx := (
  SELECT COUNT(*) FROM information_schema.STATISTICS
  WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='threads' AND INDEX_NAME='idx_thr_lesson'
);
SET @sql := IF(@idx=0, 'CREATE INDEX idx_thr_lesson ON threads(lesson_id);', 'SELECT 1');
PREPARE s FROM @sql; EXECUTE s; DEALLOCATE PREPARE s;

SET @idx := (
  SELECT COUNT(*) FROM information_schema.STATISTICS
  WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='threads' AND INDEX_NAME='idx_thr_user'
);
SET @sql := IF(@idx=0, 'CREATE INDEX idx_thr_user ON threads(user_id);', 'SELECT 1');
PREPARE s FROM @sql; EXECUTE s; DEALLOCATE PREPARE s;

/* =========================
   THREAD_REPLIES
   ========================= */
SET @idx := (
  SELECT COUNT(*) FROM information_schema.STATISTICS
  WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='thread_replies' AND INDEX_NAME='idx_tr_thread'
);
SET @sql := IF(@idx=0, 'CREATE INDEX idx_tr_thread ON thread_replies(thread_id);', 'SELECT 1');
PREPARE s FROM @sql; EXECUTE s; DEALLOCATE PREPARE s;

SET @idx := (
  SELECT COUNT(*) FROM information_schema.STATISTICS
  WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='thread_replies' AND INDEX_NAME='idx_tr_user'
);
SET @sql := IF(@idx=0, 'CREATE INDEX idx_tr_user ON thread_replies(user_id);', 'SELECT 1');
PREPARE s FROM @sql; EXECUTE s; DEALLOCATE PREPARE s;

/* =========================
   LESSON_IMAGES (optional)
   ========================= */
SET @tbl := (
  SELECT COUNT(*) FROM information_schema.TABLES
  WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='lesson_images'
);

SET @idx := (
  SELECT COUNT(*) FROM information_schema.STATISTICS
  WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='lesson_images' AND INDEX_NAME='idx_limg_lesson'
);
SET @sql := IF(@tbl=0 OR @idx>0, 'SELECT 1', 'CREATE INDEX idx_limg_lesson ON lesson_images(lesson_id);');
PREPARE s FROM @sql; EXECUTE s; DEALLOCATE PREPARE s;

SET @idx := (
  SELECT COUNT(*) FROM information_schema.STATISTICS
  WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='lesson_images' AND INDEX_NAME='idx_limg_lesson_pos'
);
SET @sql := IF(@tbl=0 OR @idx>0, 'SELECT 1', 'CREATE INDEX idx_limg_lesson_pos ON lesson_images(lesson_id, position);');
PREPARE s FROM @sql; EXECUTE s; DEALLOCATE PREPARE s;

/* =========================
   QUIZZES
   ========================= */
SET @idx := (
  SELECT COUNT(*) FROM information_schema.STATISTICS
  WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='quizzes' AND INDEX_NAME='idx_quizzes_course'
);
SET @sql := IF(@idx=0, 'CREATE INDEX idx_quizzes_course ON quizzes(course_id);', 'SELECT 1');
PREPARE s FROM @sql; EXECUTE s; DEALLOCATE PREPARE s;

SET @idx := (SELECT COUNT(*) FROM information_schema.STATISTICS
  WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='quizzes' AND INDEX_NAME='idx_quizzes_section');
SET @sql := IF(@idx=0, 'CREATE INDEX idx_quizzes_section ON quizzes(section_id);', 'SELECT 1');
PREPARE s FROM @sql; EXECUTE s; DEALLOCATE PREPARE s;

SET @idx := (SELECT COUNT(*) FROM information_schema.STATISTICS
  WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='quizzes' AND INDEX_NAME='idx_quizzes_open');
SET @sql := IF(@idx=0, 'CREATE INDEX idx_quizzes_open ON quizzes(open_at);', 'SELECT 1');
PREPARE s FROM @sql; EXECUTE s; DEALLOCATE PREPARE s;

SET @idx := (SELECT COUNT(*) FROM information_schema.STATISTICS
  WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='quizzes' AND INDEX_NAME='idx_quizzes_close');
SET @sql := IF(@idx=0, 'CREATE INDEX idx_quizzes_close ON quizzes(close_at);', 'SELECT 1');
PREPARE s FROM @sql; EXECUTE s; DEALLOCATE PREPARE s;

/* =========================
   QUIZ_QUESTIONS
   ========================= */
SET @idx := (SELECT COUNT(*) FROM information_schema.STATISTICS
  WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='quiz_questions' AND INDEX_NAME='idx_qq_quiz');
SET @sql := IF(@idx=0, 'CREATE INDEX idx_qq_quiz ON quiz_questions(quiz_id);', 'SELECT 1');
PREPARE s FROM @sql; EXECUTE s; DEALLOCATE PREPARE s;

SET @idx := (SELECT COUNT(*) FROM information_schema.STATISTICS
  WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='quiz_questions' AND INDEX_NAME='idx_qq_pos');
SET @sql := IF(@idx=0, 'CREATE INDEX idx_qq_pos ON quiz_questions(position);', 'SELECT 1');
PREPARE s FROM @sql; EXECUTE s; DEALLOCATE PREPARE s;

/* =========================
   QUIZ_ANSWERS
   ========================= */
-- assicurati che la colonna generata esista
SET @col := (
  SELECT COUNT(*) FROM information_schema.COLUMNS
   WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='quiz_answers' AND COLUMN_NAME='correct_key'
);
SET @sql := IF(@col=0,
  'ALTER TABLE quiz_answers ADD COLUMN correct_key INT GENERATED ALWAYS AS (CASE WHEN is_correct=1 THEN question_id ELSE NULL END) VIRTUAL;',
  'SELECT 1');
PREPARE s FROM @sql; EXECUTE s; DEALLOCATE PREPARE s;

-- unique: una sola risposta corretta per domanda
SET @idx := (SELECT COUNT(*) FROM information_schema.STATISTICS
  WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='quiz_answers' AND INDEX_NAME='uq_one_correct');
SET @sql := IF(@idx=0, 'CREATE UNIQUE INDEX uq_one_correct ON quiz_answers(correct_key);', 'SELECT 1');
PREPARE s FROM @sql; EXECUTE s; DEALLOCATE PREPARE s;

-- indici normali
SET @idx := (SELECT COUNT(*) FROM information_schema.STATISTICS
  WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='quiz_answers' AND INDEX_NAME='idx_qa_question');
SET @sql := IF(@idx=0, 'CREATE INDEX idx_qa_question ON quiz_answers(question_id);', 'SELECT 1');
PREPARE s FROM @sql; EXECUTE s; DEALLOCATE PREPARE s;

SET @idx := (SELECT COUNT(*) FROM information_schema.STATISTICS
  WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='quiz_answers' AND INDEX_NAME='idx_qa_pos');
SET @sql := IF(@idx=0, 'CREATE INDEX idx_qa_pos ON quiz_answers(position);', 'SELECT 1');
PREPARE s FROM @sql; EXECUTE s; DEALLOCATE PREPARE s;

SET @idx := (SELECT COUNT(*) FROM information_schema.STATISTICS
  WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='quiz_answers' AND INDEX_NAME='idx_qa_q_pos');
SET @sql := IF(@idx=0, 'CREATE INDEX idx_qa_q_pos ON quiz_answers(question_id, position);', 'SELECT 1');
PREPARE s FROM @sql; EXECUTE s; DEALLOCATE PREPARE s;

/* =========================
   QUIZ_ATTEMPTS
   ========================= */
-- colonne generate per partial unique (se mancano)
SET @col := (
  SELECT COUNT(*) FROM information_schema.COLUMNS
   WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='quiz_attempts' AND COLUMN_NAME='open_quiz_key'
);
SET @sql := IF(@col=0,
  'ALTER TABLE quiz_attempts
     ADD COLUMN open_quiz_key INT GENERATED ALWAYS AS (CASE WHEN submitted_at IS NULL THEN quiz_id ELSE NULL END) VIRTUAL,
     ADD COLUMN open_user_key INT GENERATED ALWAYS AS (CASE WHEN submitted_at IS NULL THEN user_id ELSE NULL END) VIRTUAL;',
  'SELECT 1');
PREPARE s FROM @sql; EXECUTE s; DEALLOCATE PREPARE s;

-- unique: un solo tentativo aperto per (quiz, utente)
SET @idx := (SELECT COUNT(*) FROM information_schema.STATISTICS
  WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='quiz_attempts' AND INDEX_NAME='uq_one_open_attempt');
SET @sql := IF(@idx=0, 'CREATE UNIQUE INDEX uq_one_open_attempt ON quiz_attempts(open_quiz_key, open_user_key);', 'SELECT 1');
PREPARE s FROM @sql; EXECUTE s; DEALLOCATE PREPARE s;

-- altri indici
SET @idx := (SELECT COUNT(*) FROM information_schema.STATISTICS
  WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='quiz_attempts' AND INDEX_NAME='idx_qatt_quiz');
SET @sql := IF(@idx=0, 'CREATE INDEX idx_qatt_quiz ON quiz_attempts(quiz_id);', 'SELECT 1');
PREPARE s FROM @sql; EXECUTE s; DEALLOCATE PREPARE s;

SET @idx := (SELECT COUNT(*) FROM information_schema.STATISTICS
  WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='quiz_attempts' AND INDEX_NAME='idx_qatt_user');
SET @sql := IF(@idx=0, 'CREATE INDEX idx_qatt_user ON quiz_attempts(user_id);', 'SELECT 1');
PREPARE s FROM @sql; EXECUTE s; DEALLOCATE PREPARE s;

SET @idx := (SELECT COUNT(*) FROM information_schema.STATISTICS
  WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='quiz_attempts' AND INDEX_NAME='idx_qatt_quiz_user_submitted');
SET @sql := IF(@idx=0, 'CREATE INDEX idx_qatt_quiz_user_submitted ON quiz_attempts(quiz_id, user_id, submitted_at);', 'SELECT 1');
PREPARE s FROM @sql; EXECUTE s; DEALLOCATE PREPARE s;

/* =========================
   USER_READS
   ========================= */
SET @idx := (SELECT COUNT(*) FROM information_schema.STATISTICS
  WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='user_reads' AND INDEX_NAME='uq_user_item');
SET @sql := IF(@idx=0, 'ALTER TABLE user_reads ADD UNIQUE KEY uq_user_item (user_id, item_type, item_id);', 'SELECT 1');
PREPARE s FROM @sql; EXECUTE s; DEALLOCATE PREPARE s;

SET @idx := (SELECT COUNT(*) FROM information_schema.STATISTICS
  WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='user_reads' AND INDEX_NAME='idx_user_type');
SET @sql := IF(@idx=0, 'CREATE INDEX idx_user_type ON user_reads(user_id, item_type);', 'SELECT 1');
PREPARE s FROM @sql; EXECUTE s; DEALLOCATE PREPARE s;
