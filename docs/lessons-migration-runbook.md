# Runbook migrazione Lessons

## Prerequisiti

1. Backup completo DB e `virtuale/uploads`.
2. PHP 8.3 con PDO MySQL, DOM, fileinfo, mbstring.
3. MariaDB 10.4+ e utente con privilegi `ALTER`, `CREATE`, `INDEX`, `REFERENCES`.
4. Deploy di `vendor/` generato da Composer e del bundle `virtuale/assets/dist/lesson-editor.js`.

## Applicazione

```bash
composer install --no-dev --optimize-autoloader
npm ci
npm run build
php database/migrate.php
php database/migrate.php   # deve mostrare solo skip
```

La prima migrazione aggiunge colonne compatibili, `lesson_blocks` e `lesson_media`. La seconda esegue diagnostica non distruttiva. MariaDB 10.4 locale ha confermato `LONGTEXT + CHECK(JSON_VALID(data_json))` e le foreign key verso `lessons`.

Verificare:

```sql
SELECT * FROM schema_migrations ORDER BY applied_at;
SHOW CREATE TABLE lesson_blocks;
SHOW CREATE TABLE lesson_media;
SELECT content_format, COUNT(*) FROM lessons GROUP BY content_format;
```

## Diagnostica MyISAM

`lesson_files` resta intenzionalmente MyISAM. Prima di convertirla eseguire le query in `database/migrations/202607140002_lesson_files_diagnostics.sql`, controllare tipi signed/unsigned, indici, collation e finestra di manutenzione. Sul database locale dell'audit erano presenti 21 orfani in `lesson_files` e 21 in `lesson_videos`; questi numeri non vanno assunti per produzione e richiedono revisione manuale.

## Conversione contenuti

Non eseguire conversioni bulk. Aprire una lesson legacy come Administrator/Instruktor proprietario, scegliere “Konverto në editorin e ri”, verificare l'anteprima e confermare. Controllare poi l'editor e salvare. `legacy_description` permette rollback contenuto.

Rollback di una singola conversione:

```sql
START TRANSACTION;
DELETE FROM lesson_blocks WHERE lesson_id = :lesson_id;
UPDATE lessons
SET description = legacy_description,
    content_format = 'legacy_markdown',
    content_version = 1
WHERE id = :lesson_id AND legacy_description IS NOT NULL;
COMMIT;
```

## Rollback schema

Solo dopo avere riportato tutte le lesson a `legacy_markdown` e fatto backup:

```bash
mysql DATABASE < database/rollbacks/202607140001_lessons_block_storage.sql
```

Il rollback elimina tabelle/colonne nuove e quindi è distruttivo. I media fisici vanno archiviati o rimossi separatamente dopo verifica. Non convertire automaticamente `lesson_files` e non rimuovere `legacy_description` durante il primo ciclo di deploy.
