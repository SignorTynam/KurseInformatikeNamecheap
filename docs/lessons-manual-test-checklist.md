# Checklist manuale Lessons

Per ogni caso registrare browser, viewport, utente, lesson ID e risultato. Verificare console browser e log PHP.

## Ruoli e accesso

- [ ] Administrator desktop: crea, modifica, copia, converte e cancella lesson di ogni corso.
- [ ] Administrator mobile: toolbar, dialog preview e riordino con pulsanti sono utilizzabili.
- [ ] Instruktor proprietario: CRUD, upload e conversione consentiti.
- [ ] Instruktor non proprietario: CRUD, upload, copia e media restituiscono accesso negato.
- [ ] Student iscritto: vede lesson visibile, media, materiali e può segnare come letto.
- [ ] Student non iscritto: lesson e media sono negati.
- [ ] Sezione nascosta: negata allo Student, disponibile a Administrator/proprietario.

## Contenuto

- [ ] Nuova lesson usa testo, heading 2/3/4, lista ordinata/non ordinata/checklist.
- [ ] Tabella 30×20 scorre orizzontalmente su mobile; limiti superiori sono rifiutati.
- [ ] Foto JPEG/PNG/GIF/WebP: alt obbligatorio, caption e opzioni layout.
- [ ] File SVG/PHP/HTML, MIME falso, file vuoto e >10 MB sono rifiutati.
- [ ] Citazione, callout per ogni variante, codice e separatore sono renderizzati.
- [ ] Codice appare come testo, highlight.js funziona e il pulsante copia copia il contenuto.
- [ ] MathJax continua a processare formule nei blocchi testuali.
- [ ] Heading duplicati producono anchor uniche e indice corretto.
- [ ] Preview usa il renderer server-side e non mostra JSON.
- [ ] Uscita con modifiche non salvate mostra conferma.
- [ ] Riordino drag, su/giù, duplicazione e cancellazione funzionano da tastiera.
- [ ] Payload con `<script>`, handler, `javascript:` e `data:` non viene eseguito.

## Compatibilità e lifecycle

- [ ] Lesson legacy è visualizzata normalmente con Parsedown safe mode.
- [ ] Conversione legacy mostra anteprima, richiede conferma e conserva `legacy_description`.
- [ ] Lesson `blocks_v1` non usa Parsedown e ricarica tutti i blocchi in edit.
- [ ] Video multipli, allegati, notebook e tab materiali restano disponibili.
- [ ] Navigazione precedente/successiva, assignment, quiz e stato letto restano invariati.
- [ ] Copia lesson duplica blocchi, media, allegati, immagini legacy e video con file distinti.
- [ ] Cancellazione rimuove blocchi/media/file/video/section item/read e gestisce file mancanti con warning.
- [ ] Cleanup rimuove draft media >24 ore e non tocca media associati.
- [ ] Ricerca trova testo proveniente da heading, paragraph, liste, tabelle, callout, quote e alt/caption.
