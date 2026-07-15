# Dipendenze dell'editor Lessons

Versioni verificate sul registro npm il 14 luglio 2026 e fissate in `package-lock.json`:

| Pacchetto | Versione | Licenza | Ultima release della versione | Uso |
|---|---:|---|---|---|
| `@editorjs/editorjs` | 2.31.6 | Apache-2.0 | 2026-04-07 | core editor |
| `@editorjs/header` | 2.8.9 | MIT | 2026-05-12 | heading 2–4 |
| `@editorjs/list` | 2.0.9 | MIT | 2025-11-13 | liste controllate |
| `@editorjs/table` | 2.4.5 | MIT | 2025-05-13 | tabelle |
| `@editorjs/quote` | 2.7.6 | MIT | 2024-12-03 | citazioni |
| `@editorjs/delimiter` | 1.4.2 | MIT | 2024-08-17 | separatori |
| `esbuild` | 0.28.1 | MIT | 2026-06-11 | bundle locale |

Repository upstream: organizzazione `editor-js`/`codex-team` su GitHub; esbuild è mantenuto da evanw. Image, callout e code sono tool locali piccoli perché i tool generici non espongono esattamente `mediaId`, alt obbligatorio e language allowlist richiesti. L'alternativa considerata era un editor custom o un framework SPA: entrambi avrebbero mantenuto conversioni ad hoc o ampliato inutilmente il perimetro. Nessun asset Editor.js usa CDN in produzione; `npm run build` genera il bundle committato.
