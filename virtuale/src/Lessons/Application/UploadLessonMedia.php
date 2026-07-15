<?php

declare(strict_types=1);

namespace KurseInformatike\Lessons\Application;

use KurseInformatike\Lessons\Infrastructure\PdoLessonMediaRepository;
use KurseInformatike\Shared\Storage\FileStorage;

final class UploadLessonMedia
{
    private const MIME_TO_EXTENSION = [
        'image/jpeg' => 'jpg',
        'image/png' => 'png',
        'image/gif' => 'gif',
        'image/webp' => 'webp',
    ];

    public function __construct(
        private readonly PdoLessonMediaRepository $media,
        private readonly FileStorage $storage,
        private readonly int $maxBytes = 10_485_760,
        private readonly int $maxWidth = 12_000,
        private readonly int $maxHeight = 12_000,
        private readonly int $maxPixels = 40_000_000,
    ) {
    }

    public function handle(array $file, int $ownerId, string $draftToken): int
    {
        if (!preg_match('/^[0-9a-f]{8}-[0-9a-f]{4}-4[0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}$/i', $draftToken)) {
            throw new \DomainException('Identifikuesi i draftit nuk është i vlefshëm.');
        }
        if (($file['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK) {
            throw new \DomainException('Fotoja nuk u ngarkua.');
        }
        $tmp = (string) ($file['tmp_name'] ?? '');
        $size = (int) ($file['size'] ?? 0);
        if ($tmp === '' || !is_uploaded_file($tmp) || $size < 1 || $size > $this->maxBytes) {
            throw new \DomainException('Fotoja është bosh, e pavlefshme ose tejkalon 10 MB.');
        }
        $mime = (string) (new \finfo(FILEINFO_MIME_TYPE))->file($tmp);
        $extension = self::MIME_TO_EXTENSION[$mime] ?? null;
        $clientExtension = strtolower(pathinfo((string) ($file['name'] ?? ''), PATHINFO_EXTENSION));
        $allowedClientExtensions = $extension === 'jpg' ? ['jpg', 'jpeg'] : [$extension];
        if ($extension === null || !in_array($clientExtension, $allowedClientExtensions, true)) {
            throw new \DomainException('Lejohen vetëm JPEG, PNG, GIF dhe WebP.');
        }
        $info = getimagesize($tmp);
        if ($info === false || ($info['mime'] ?? '') !== $mime) {
            throw new \DomainException('Skedari nuk është një foto e vlefshme.');
        }
        $width = (int) $info[0];
        $height = (int) $info[1];
        if ($width < 1 || $height < 1 || $width > $this->maxWidth || $height > $this->maxHeight
            || $width * $height > $this->maxPixels) {
            throw new \DomainException('Përmasat e fotos janë shumë të mëdha.');
        }

        $path = $this->storage->storeUploadedImage($tmp, 'draft-' . str_replace('-', '', $draftToken), $extension);
        try {
            return $this->media->create([
                'owner_user_id' => $ownerId,
                'draft_token' => strtolower($draftToken),
                'media_type' => 'image',
                'storage_path' => $path,
                'original_name' => mb_substr(basename((string) ($file['name'] ?? 'image')), 0, 255),
                'mime_type' => $mime,
                'size_bytes' => $size,
                'width' => $width,
                'height' => $height,
            ]);
        } catch (\Throwable $exception) {
            $this->storage->delete($path);
            throw $exception;
        }
    }
}
