<?php

declare(strict_types=1);

use KurseInformatike\Shared\Auth\Authorization;
use KurseInformatike\Shared\Storage\FileStorage;
use KurseInformatike\Lessons\Infrastructure\LessonAttachmentStorage;

$root = dirname(__DIR__, 2);

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

$autoload = $root . '/vendor/autoload.php';
if (!is_file($autoload)) {
    throw new RuntimeException('Composer autoload missing. Run composer install.');
}
require_once $autoload;
require_once $root . '/database.php';

if (!isset($pdo) || !$pdo instanceof PDO) {
    throw new RuntimeException('Database connection unavailable.');
}

$pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
$pdo->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);
$pdo->setAttribute(PDO::ATTR_EMULATE_PREPARES, false);
$pdo->exec('SET NAMES utf8mb4 COLLATE utf8mb4_general_ci');

ini_set('display_errors', '0');
set_exception_handler(static function (Throwable $exception): void {
    error_log(sprintf(
        '[%s] %s in %s:%d',
        get_class($exception),
        $exception->getMessage(),
        $exception->getFile(),
        $exception->getLine()
    ));
    if (!headers_sent()) {
        http_response_code(500);
    }
    echo 'Ndodhi një gabim i papritur. Ju lutemi provoni përsëri.';
});

return [
    'root' => $root,
    'pdo' => $pdo,
    'authorization' => new Authorization($pdo),
    'lesson_media_storage' => new FileStorage(
        __DIR__ . '/../uploads/lesson-media',
        'uploads/lesson-media'
    ),
    'lesson_attachment_storage' => new LessonAttachmentStorage(__DIR__ . '/../uploads/lessons'),
    'lesson_media_max_bytes' => 10 * 1024 * 1024,
    'lesson_media_max_width' => 12000,
    'lesson_media_max_height' => 12000,
    'lesson_media_max_pixels' => 40_000_000,
];
