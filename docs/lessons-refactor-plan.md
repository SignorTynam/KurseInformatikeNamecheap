# Piano di refactoring del modulo Lessons

## Stato attuale

Il contenuto è Markdown in `lessons.description`; add/edit contengono un editor custom che converte pseudo-blocchi ↔ Markdown. Upload, autorizzazione, SQL, rendering e schema sono mescolati nelle pagine. La lettura usa Parsedown e deriva indice/tempo di lettura dal DOM o dal Markdown.

## Architettura target

Il modulo resta un modular monolith PHP senza framework SPA:

- `virtuale/bootstrap/app.php`: sessione, autoload, PDO, error handling e servizi condivisi.
- `virtuale/src/Shared`: CSRF, risposta HTTP, autorizzazione, sanitizzazione e storage sicuro.
- `virtuale/src/Lessons/Domain`: formato, blocco e collezione.
- `virtuale/src/Lessons/Application`: create/update/delete/copy/upload/convert/get.
- `virtuale/src/Lessons/Infrastructure`: repository PDO e storage locale.
- `virtuale/src/Lessons/Presentation`: renderer server-side allowlist.
- rotte esistenti: controller compatibili che delegano ai servizi.
- Editor.js: bundle locale versionato, senza React/Vue.

Le classi sono concrete e specifiche di Lessons; non viene introdotto un framework o un ORM.

## Schema target

- `lessons.content_format`: `legacy_markdown` o `blocks_v1`.
- `lessons.content_version`: versione dello schema blocchi.
- `lessons.legacy_description`: copia reversibile del Markdown originario.
- `lesson_blocks`: una riga per blocco, UID stabile, posizione e JSON validato.
- `lesson_media`: metadati, ownership, draft token e path storage non esposto al client.
- `schema_migrations`: registro delle migrazioni manuali.

`lessons.description` resta temporaneamente la proiezione plain-text per non rompere `search.php`; non conterrà nuovo Markdown per `blocks_v1`.

## Strategia legacy

Le lesson esistenti restano `legacy_markdown` e vengono renderizzate con Parsedown safe mode. Un utente autorizzato può aprire un'anteprima di conversione e confermarla. La conferma salva `legacy_description`, blocchi e formato in transazione. Una lesson già `blocks_v1` non viene riconvertita.

## Strategia media

Gli upload Editor.js usano `draft_token` UUID, owner autenticato e una sola root. Il client conserva soltanto `mediaId`; l'URL è prodotto dal server. Create associa i media del draft nella stessa transazione DB. Update accetta solo media della lesson o del draft dell'utente. I file fisici vengono eliminati soltanto dopo commit o con cleanup compensativo sicuro.

## Strategia rollback

- Ogni migrazione ha uno script rollback separato.
- Il rollback applicativo rimette una lesson convertita a `legacy_markdown` usando `legacy_description` prima di eliminare i blocchi.
- Le colonne legacy non vengono rimosse.
- Nessuna conversione di engine o cancellazione bulk viene eseguita automaticamente.
- I file media vengono preservati se una cancellazione fisica fallisce; l'errore viene registrato e non viene seguito un path fuori root.

## File principali da modificare

- `composer.json`, `phpunit.xml`, `virtuale/bootstrap/app.php`.
- `database/migrations/*`, `database/rollbacks/*`, `database/migrate.php`.
- `virtuale/src/Shared/*`, `virtuale/src/Lessons/**/*`.
- `virtuale/api/lessons/media/upload.php`, `delete.php` e cleanup CLI.
- `virtuale/admin/add_lesson.php`, `edit_lesson.php`, `delete_lesson.php`.
- `virtuale/lesson_details.php`, `virtuale/lib/copy_utils.php`, `virtuale/copy_course.php`.
- `virtuale/assets/src|dist/lesson-editor.js`, CSS Lessons.
- test e documentazione Lessons.

## Ordine delle attività

1. Aggiungere autoload, bootstrap e migrazioni non distruttive.
2. Implementare modello blocchi, validator, sanitizzatore e proiezione testo.
3. Implementare repository e servizi transazionali CRUD.
4. Implementare storage/media endpoint e cleanup draft.
5. Integrare Editor.js bundle locale nei form add/edit.
6. Renderizzare `blocks_v1` server-side con fallback legacy.
7. Aggiungere conversione legacy con preview/conferma.
8. Rendere delete/copy consapevoli di blocchi e media.
9. Eseguire unit/integration smoke test e checklist manuale.
10. Aggiornare runbook, commit, push e PR draft.

## Test

- Unit: validator per ogni tipo, sanitizzazione inline, URL, proiezione, renderer, anchor, tempo lettura, converter.
- Integration con database dedicato quando disponibile: create/update/delete/copy/media/authorization/CSRF/legacy.
- Statici: Composer validate/dump-autoload, `php -l` su tutti i file cambiati, build JavaScript.
- Manuali: ruoli, desktop/mobile, legacy/blocks, immagini, tabella, codice, copia e cancellazione.

## Rischi e mitigazioni

- Engine MyISAM: cancellazioni esplicite e diagnostica prima di una futura conversione.
- File system non transazionale: nomi casuali, root verificata, cleanup post-rollback/post-commit.
- Compatibilità copie esterne: repository di copia condiviso e aggiornamento dei call site Lessons.
- XSS: allowlist, normalizzazione server-side e renderer senza HTML arbitrario.
- Deployment parziale: migrazione richiesta prima del codice; il viewer mantiene fallback legacy e segnala in modo controllato schema mancante agli amministratori.
- Asset Editor.js: versioni npm bloccate e bundle committato per evitare CDN in produzione.
