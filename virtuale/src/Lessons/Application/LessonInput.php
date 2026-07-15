<?php

declare(strict_types=1);

namespace KurseInformatike\Lessons\Application;

final class LessonInput
{
    private const CATEGORIES = ['LEKSION', 'VIDEO', 'LINK', 'FILE', 'REFERENCA', 'LAB', 'TJETER'];

    public static function normalize(array $input): array
    {
        $title = trim((string) ($input['title'] ?? ''));
        $category = strtoupper(trim((string) ($input['category'] ?? 'LEKSION')));
        $url = trim((string) ($input['url'] ?? ''));
        if ($title === '' || mb_strlen($title) > 255) {
            throw new \DomainException('Titulli është i detyrueshëm dhe duhet të ketë maksimumi 255 karaktere.');
        }
        if (!in_array($category, self::CATEGORIES, true)) {
            throw new \DomainException('Kategoria nuk është e vlefshme.');
        }
        if ($url !== '') {
            $scheme = strtolower((string) parse_url($url, PHP_URL_SCHEME));
            if (!filter_var($url, FILTER_VALIDATE_URL) || !in_array($scheme, ['http', 'https'], true)) {
                throw new \DomainException('URL-ja duhet të përdorë HTTP ose HTTPS.');
            }
        }
        $videos = [];
        foreach (($input['video_urls'] ?? []) as $video) {
            $video = trim((string) $video);
            $scheme = strtolower((string) parse_url($video, PHP_URL_SCHEME));
            if ($video !== '' && filter_var($video, FILTER_VALIDATE_URL) && in_array($scheme, ['http', 'https'], true)) {
                $videos[$video] = $video;
            }
        }
        return [
            'course_id' => (int) ($input['course_id'] ?? 0),
            'section_id' => max(0, (int) ($input['section_id'] ?? 0)),
            'title' => $title,
            'category' => $category,
            'url' => $url,
            'notebook_path' => trim((string) ($input['notebook_path'] ?? '')),
            'video_urls' => array_values($videos),
        ];
    }
}
