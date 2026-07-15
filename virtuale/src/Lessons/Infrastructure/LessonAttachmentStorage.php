<?php

declare(strict_types=1);

namespace KurseInformatike\Lessons\Infrastructure;

final class LessonAttachmentStorage
{
    private const EXTENSIONS = [
        'pdf' => 'PDF', 'ppt' => 'SLIDES', 'pptx' => 'SLIDES',
        'mp4' => 'VIDEO', 'mov' => 'VIDEO', 'avi' => 'VIDEO',
        'doc' => 'DOC', 'docx' => 'DOC', 'xls' => 'DOC', 'xlsx' => 'DOC',
        'csv' => 'DOC', 'txt' => 'DOC', 'zip' => 'DOC', 'rar' => 'DOC', '7z' => 'DOC',
        'jpg' => 'DOC', 'jpeg' => 'DOC', 'png' => 'DOC', 'gif' => 'DOC', 'webp' => 'DOC',
    ];

    private string $root;

    public function __construct(string $root, private readonly int $maxBytes = 15_728_640)
    {
        if (!is_dir($root) && !mkdir($root, 0775, true) && !is_dir($root)) {
            throw new \RuntimeException('Attachment storage is unavailable.');
        }
        $this->root = realpath($root) ?: $root;
    }

    public function store(array $file): ?array
    {
        if (($file['error'] ?? UPLOAD_ERR_NO_FILE) === UPLOAD_ERR_NO_FILE) return null;
        if (($file['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK) throw new \DomainException('Skedari nuk u ngarkua.');
        $tmp = (string) ($file['tmp_name'] ?? '');
        $size = (int) ($file['size'] ?? 0);
        if ($tmp === '' || !is_uploaded_file($tmp) || $size < 1) throw new \DomainException('Skedari nuk është i vlefshëm.');
        $extension = strtolower(pathinfo((string) ($file['name'] ?? ''), PATHINFO_EXTENSION));
        $type = self::EXTENSIONS[$extension] ?? null;
        if ($type === null) throw new \DomainException('Lloji i skedarit nuk lejohet.');
        if ($extension !== 'pdf' && $size > $this->maxBytes) throw new \DomainException('Skedari tejkalon kufirin 15 MB.');
        $mime = (string) (new \finfo(FILEINFO_MIME_TYPE))->file($tmp);
        if ($mime === '' || in_array($mime, ['text/html', 'application/x-httpd-php'], true)) {
            throw new \DomainException('Përmbajtja e skedarit nuk lejohet.');
        }
        $name = bin2hex(random_bytes(24)) . '.' . $extension;
        if (!move_uploaded_file($tmp, $this->root . DIRECTORY_SEPARATOR . $name)) throw new \RuntimeException('Skedari nuk mund të ruhet.');
        return ['file_path' => 'uploads/lessons/' . $name, 'file_type' => $type];
    }

    public function delete(string $relativePath): bool
    {
        if (!preg_match('~^uploads/lessons/[A-Za-z0-9/_-]+(?:\.[A-Za-z0-9]+)?$~', $relativePath)) return false;
        $candidate = realpath(dirname($this->root) . DIRECTORY_SEPARATOR . str_replace('/', DIRECTORY_SEPARATOR, substr($relativePath, strlen('uploads/'))));
        if ($candidate === false || !is_file($candidate)) return false;
        $prefix = strtolower(str_replace('\\', '/', $this->root)) . '/';
        if (!str_starts_with(strtolower(str_replace('\\', '/', $candidate)), $prefix)) return false;
        return unlink($candidate);
    }
}
