<?php

declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}

$root = dirname(__DIR__);
require $root . '/database.php';

if (!isset($pdo) || !$pdo instanceof PDO) {
    fwrite(STDERR, "PDO connection is unavailable.\n");
    exit(1);
}

$pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
$pdo->exec("SET NAMES utf8mb4 COLLATE utf8mb4_general_ci");
$pdo->exec(
    "CREATE TABLE IF NOT EXISTS schema_migrations (
        migration VARCHAR(190) NOT NULL,
        applied_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
        PRIMARY KEY (migration)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci"
);

$applied = $pdo->query('SELECT migration FROM schema_migrations')
    ->fetchAll(PDO::FETCH_COLUMN);
$known = array_fill_keys(array_map('strval', $applied ?: []), true);
$files = glob(__DIR__ . '/migrations/*.sql') ?: [];
sort($files, SORT_STRING);

foreach ($files as $file) {
    $name = basename($file);
    if (isset($known[$name])) {
        fwrite(STDOUT, "skip  {$name}\n");
        continue;
    }

    $sql = file_get_contents($file);
    if ($sql === false || trim($sql) === '') {
        fwrite(STDERR, "empty {$name}\n");
        exit(1);
    }

    fwrite(STDOUT, "apply {$name}\n");
    try {
        $statements = preg_split('/;\s*(?:\r?\n|$)/', $sql) ?: [];
        foreach ($statements as $statementSql) {
            if (trim($statementSql) === '') {
                continue;
            }
            $statement = $pdo->prepare($statementSql);
            $statement->execute();
            while ($statement->nextRowset()) {
                // Drain every result set before executing the migration registry insert.
            }
            $statement->closeCursor();
        }
        $record = $pdo->prepare('INSERT INTO schema_migrations (migration) VALUES (?)');
        $record->execute([$name]);
    } catch (Throwable $exception) {
        error_log(sprintf('Migration %s failed: %s', $name, $exception->getMessage()));
        fwrite(STDERR, "failed {$name}; see server log\n");
        exit(1);
    }
}

fwrite(STDOUT, "Migrations complete.\n");
