# Formato blocchi Lessons v1

`lessons.content_format = blocks_v1` e `content_version = 1` indicano che la fonte canonica è `lesson_blocks`, ordinata per `position`. `lessons.description` contiene esclusivamente una proiezione plain-text per ricerca e anteprime.

## Envelope Editor.js

Il client invia `{ "blocks": [...] }`. Il server ricostruisce sempre la posizione, accetta massimo 200 blocchi, massimo 1 MB e profondità JSON 20. Ogni blocco ha `id` stabile (8–64 caratteri alfanumerici, `_` o `-`), `type` allowlisted e `data` specifico. Campi inattesi vengono rimossi.

## Tipi

- `paragraph`: `text`, inline HTML limitato a `b`, `strong`, `i`, `em`, `br`, `a` HTTP/HTTPS.
- `heading`: `text`, `level` 2/3/4.
- `list`: `style` `ordered|unordered|checklist`, massimo 100 item.
- `table`: `withHeadings`, matrice `content`, massimo 30×20 e 500 caratteri/cella.
- `image`: `mediaId`, `caption`, `alt` obbligatorio, `withBorder`, `withBackground`, `stretched`. L'URL non è canonico.
- `quote`: `text`, `caption`, `alignment` `left|center`.
- `callout`: `variant` `info|success|warning|danger|tip`, `title`, `text`.
- `code`: `language` allowlisted e `code`; il codice viene sempre escapato e mai eseguito.
- `delimiter`: payload vuoto.

## Sicurezza e rendering

Il payload viene normalizzato da `LessonBlockValidator` prima della persistenza e nuovamente validato quando viene letto. `LessonBlockRenderer` usa template per tipo e escaping contestuale; non stampa HTML arbitrario. I link esterni ricevono `rel="noopener noreferrer"`. Gli anchor degli heading sono slug deterministici con suffisso numerico per i duplicati. Indice, proiezione testo, conteggio parole e tempo di lettura derivano dalla collezione di blocchi.

## Compatibilità

`legacy_markdown` continua a usare Parsedown safe mode solo nel viewer. La conversione è esplicita, mostra un'anteprima, copia l'originale in `legacy_description` e non riconverte una lesson già `blocks_v1`.
