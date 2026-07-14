# Audit del modulo Lessons

Data audit: 14 luglio 2026  
Branch di partenza: `main`  
Commit verificato dopo `git fetch origin --prune`: `ed3e889fec650fd62a8645d84336a02caf3d59aa`

## Ambiente verificato

- Repository: `SignorTynam/KurseInformatikeNamecheap`.
- Database locale: MariaDB `10.4.32`.
- PHP CLI locale: `8.2.12`; il target applicativo richiesto è PHP 8.3.
- Composer: `2.8.11`; non erano presenti `composer.json`, PHPUnit o un package JavaScript.
- Node: `24.14.0`; PowerShell blocca `npm.ps1`, ma `npm.cmd` è utilizzabile.
- Il dump `kursqiyd_online_courses.sql` e lo schema locale sono stati entrambi verificati.

## Flusso corrente

### Create

`virtuale/admin/add_lesson.php` contiene autenticazione, ownership del corso, CSRF, query, validazione, upload, HTML e JavaScript. Il browser costruisce pseudo-blocchi, li converte in Markdown e invia sia `description` sia `blocks_json`; il server ignora `blocks_json` e salva `description`. Le immagini interne usano placeholder `#IMG<n>` e vengono salvate in `virtuale/uploads/images/lessons/<lesson-id>`. La pagina crea inoltre `lesson_images` durante la richiesta se la tabella manca. Lesson, video, allegati e `section_items` sono inseriti in una transazione DB, ma i file fisici vengono mossi prima o durante la transazione e non vengono compensati in caso di rollback.

### Read

`virtuale/lesson_details.php` carica direttamente lesson, corso e sezione, applica i controlli di ruolo/iscrizione e rende sempre `lessons.description` con Parsedown in safe mode. Corregge poi con regex gli URL legacy degli upload. Il tempo di lettura deriva dal Markdown con una regex approssimativa e l'indice viene ricavato nel browser dagli heading del DOM. Video, allegati, notebook, navigazione precedente/successiva, stato letto, assignment e quiz sono gestiti nella stessa pagina.

### Update

`virtuale/admin/edit_lesson.php` ripete autorizzazione, CSRF, upload e query. Ricostruisce i blocchi dal Markdown, permette “Normal View”/“Markdown View” e riconverte i blocchi in Markdown al submit. Le nuove immagini sono salvate in `virtuale/uploads/lessons/images`, diversa dalla directory usata in creazione. Aggiorna lesson, video, allegati e `section_items` in una transazione DB, senza una gestione transazionale dei file fisici.

### Delete

`virtuale/admin/delete_lesson.php` accetta più varianti CSRF, carica gli allegati, elimina `section_items`, `user_reads` e lesson. Presume che `lesson_files` venga eliminata in cascade, ma nel database reale `lesson_files` è MyISAM e non ha foreign key: la cancellazione può lasciare righe orfane. Non elimina in modo completo `lesson_blocks`, media strutturati, `lesson_images`, `lesson_videos` o relativi file. La funzione `safe_unlink` controlla solo un prefisso relativo e non usa una root canonica risolta.

### Copy

Esistono tre percorsi di copia:

- `virtuale/lib/copy_utils.php::copy_lesson_deep`, usato dalle copie di singoli item/sezioni;
- `virtuale/copy_course.php`, copia completa corso;
- `virtuale/admin/add_course.php`, copia durante creazione corso.

Tutti copiano `lessons.description`. `copy_lesson_deep` copia righe `lesson_files` e `lesson_images` mantenendo gli stessi path fisici, quindi la cancellazione di una copia può rompere l'altra. `add_lesson.php?copy_lesson_id=...` duplica fisicamente gli allegati ma non dispone di blocchi strutturati. I video sono copiati tramite `lv_copy_lesson_videos`.

## Tabelle coinvolte

| Tabella | Engine locale | Relazione rilevante | Rischio |
|---|---|---|---|
| `lessons` | InnoDB | FK solo `section_id -> sections.id SET NULL` | `description` è fonte canonica Markdown |
| `lesson_files` | MyISAM | nessuna FK | niente transazioni/cascade |
| `lesson_images` | InnoDB | nessuna FK | righe/file orfani possibili |
| `lesson_videos` | InnoDB | nessuna FK | schema creato a runtime |
| `section_items` | InnoDB | nessuna FK verso lesson | cancellazione esplicita obbligatoria |
| `user_reads` | InnoDB | FK solo verso user | riferimenti lesson non vincolati |
| `tests` | InnoDB | FK verso lesson `SET NULL` | deve sopravvivere alla cancellazione |
| `courses`, `sections`, `enroll` | InnoDB | autorizzazione/navigazione | ownership e visibilità da preservare |

Nuove tabelle necessarie: `schema_migrations`, `lesson_blocks`, `lesson_media`.

## Motori, foreign key e diagnostica

Il database reale conferma `lesson_files` MyISAM e tutte le altre tabelle Lessons principali InnoDB. Non esistono FK per `lesson_files`, `lesson_images`, `lesson_videos`, `section_items` o `user_reads` verso `lessons`. Il dump dichiara gli stessi motori. Prima di qualsiasi conversione di `lesson_files` a InnoDB devono essere eseguite le query diagnostiche incluse nella migrazione; la conversione non fa parte della migrazione automatica di questa fase.

MariaDB 10.4 espone `JSON` come alias validato di `LONGTEXT`. Per rendere il comportamento esplicito e portabile la migrazione usa `LONGTEXT` con `CHECK (JSON_VALID(data_json))`, mantenendo in ogni caso validazione applicativa obbligatoria.

## Letture e scritture di `lessons.description`

- Scrittura CRUD: `admin/add_lesson.php`, `admin/edit_lesson.php`.
- Rendering lesson: `lesson_details.php`.
- Ricerca: `search.php` (`LIKE` su title, description e URL).
- Liste/materiali che selezionano la descrizione: `course_details_student.php`.
- Copie: `lib/copy_utils.php`, `copy_course.php`, `admin/add_course.php`, prefill di `admin/add_lesson.php`.
- Query generiche `SELECT *` che ricevono anche description: `tabs/course_overview.php` e i percorsi di copia.

Per `blocks_v1`, `description` diventerà una proiezione plain-text per conservare la compatibilità della ricerca. Il viewer non la userà come contenuto.

## Upload rilevati

- Allegati: `virtuale/uploads/lessons/`.
- Immagini create: `virtuale/uploads/images/lessons/<lesson-id>/`.
- Immagini modificate: `virtuale/uploads/lessons/images/`.
- Nuova root canonica proposta: `virtuale/uploads/lesson-media/<lesson-or-draft>/`.

Gli asset legacy restano leggibili, ma ogni nuovo upload immagine deve usare esclusivamente la nuova root e un nome casuale generato dal server.

## Dipendenze frontend

Non esisteva una pipeline JavaScript. Bootstrap, Font Awesome, highlight.js, MathJax e font erano caricati da CDN nelle pagine. L'editor custom era inline nelle pagine add/edit. Editor.js e i tool supportati devono essere fissati a versioni precise e bundle-izzati localmente. highlight.js e MathJax restano compatibili nel viewer esistente.

## Configurazione e bootstrap

La connessione PDO è definita in `database.php`, escluso da Git, con `utf8` e gestione errori che stampa il messaggio tecnico. `virtuale/lib/bootstrap.php`, `auth.php` e `csrf.php` offrono helper parziali ma non un bootstrap PSR-4. Alcune pagine usano direttamente `session_start()` e includono diversi file DB. Il nuovo bootstrap deve riusare `$pdo` per compatibilità, impostare `utf8mb4`, error handling server-side e servizi condivisi senza cambiare tutto il monolite.

## Rischi di regressione

1. Perdita dei tab video, file e notebook quando si assottiglia il viewer.
2. Rottura dei link legacy alle due directory immagini esistenti.
3. Copie con path fisici condivisi e cancellazioni distruttive.
4. `lesson_files` MyISAM impedisce atomicità completa e cascade.
5. Le copie corso fuori dal CRUD principale possono creare lesson `blocks_v1` incomplete se non aggiornate.
6. Differenze di collation/tipi tra `lessons.id` e le nuove FK.
7. Payload Editor.js malevoli, URL non HTTP(S), HTML incollato e XSS stored.
8. Media draft orfani o associati a un altro utente/corso.
9. Modifica simultanea da due schede senza optimistic locking.
10. Ambiente locale PHP 8.2, un minor sotto il target 8.3: il codice deve evitare API non disponibili in 8.2 per consentire i test locali pur dichiarando il target 8.3.

## Confini del refactoring

Il lavoro resta nel modular monolith e riguarda Lessons. Assignment, quiz e blocchi TEXT continuano a usare Markdown. Le rotte pubbliche e i parametri URL esistenti restano stabili. Non vengono eseguite migrazioni distruttive durante richieste HTTP e non viene effettuata conversione bulk automatica dei contenuti legacy.
