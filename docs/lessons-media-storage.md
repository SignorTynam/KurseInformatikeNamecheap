# Storage media Lessons

## Root canonica

I nuovi media immagine sono salvati esclusivamente sotto:

```text
virtuale/uploads/lesson-media/draft-<uuid-senza-trattini>/<nome-casuale>
virtuale/uploads/lesson-media/lesson-<id>/<nome-casuale>
```

Il database conserva path relativi e metadati. Il client conserva soltanto `mediaId`; `lesson_media.php?id=<id>` ricostruisce l'accesso dopo autenticazione/autorizzazione. I path assoluti non sono mai restituiti.

## Upload

`POST virtuale/api/lessons/media/upload.php` richiede sessione, ruolo manager del corso, `csrf_token`, `draft_token` UUID v4 e campo file `image`. Sono ammessi JPEG, PNG, GIF e WebP fino a 10 MB, 12.000×12.000 e 40 milioni di pixel. Estensione, fileinfo MIME e `getimagesize` devono concordare. SVG, HTML, PHP, file vuoti e protocolli non previsti sono rifiutati.

I nomi fisici usano 192 bit casuali. Nome originale, MIME, dimensione e dimensioni immagine sono soltanto metadati.

## Associazione e cleanup

Create/Update verificano owner, draft e/o lesson di ogni `mediaId`. L'associazione del draft alla lesson avviene nella transazione DB del salvataggio. I media rimossi da un update vengono cancellati dal DB nella transazione e dal filesystem dopo commit; un fallimento fisico viene loggato.

Eseguire almeno ogni ora:

```bash
php virtuale/scripts/cleanup_lesson_media.php
```

Lo script elimina draft non associati più vecchi di 24 ore, massimo 1.000 per esecuzione.

## Legacy

`uploads/images/lessons` e `uploads/lessons/images` restano leggibili per contenuti storici ma non ricevono nuovi upload. La cancellazione/copia accetta solo path risolti dentro `virtuale/uploads`; nessun `../` o path condiviso viene eliminato. Le copie creano file fisici distinti.
