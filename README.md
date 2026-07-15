# Kurse Informatike

Applicazione PHP per corsi online. Il modulo Lessons usa un editor visuale Editor.js e persistenza strutturata a blocchi; le lesson precedenti continuano a essere lette tramite il fallback legacy.

## Setup sviluppo

```bash
composer install
npm install
npm run build
php database/migrate.php
```

Il server web deve puntare alla root del repository e PHP deve avere PDO MySQL, DOM, fileinfo e mbstring. Il target di produzione è PHP 8.3 e MariaDB 10.4 o successiva.

## Verifica

```bash
composer validate --no-check-publish
composer dump-autoload --optimize
composer lint
vendor/bin/phpunit
npm run build
```

Consultare `docs/lessons-migration-runbook.md` prima del deploy e `docs/lessons-manual-test-checklist.md` per la validazione dei ruoli e dell'interfaccia.
