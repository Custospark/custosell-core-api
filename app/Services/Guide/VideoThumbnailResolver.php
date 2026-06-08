<?php

namespace App\Services\Guide;

use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;

final class VideoThumbnailResolver
{
    public static function resolve(?string $videoUrl): ?string
    {
        if ($videoUrl === null || trim($videoUrl) === '') {
            return null;
        }

        $normalized = trim($videoUrl);
        $youtubeId = self::extractYouTubeId($normalized);
        if ($youtubeId !== null) {
            return 'https://img.youtube.com/vi/'.$youtubeId.'/mqdefault.jpg';
        }

        return self::fetchVimeoThumbnail($normalized);
    }

    private static function extractYouTubeId(string $url): ?string
    {
        if (! Str::contains($url, ['youtube.com', 'youtu.be', 'youtube-nocookie.com'], true)) {
            return null;
        }

        $parts = parse_url($url);
        $host = strtolower($parts['host'] ?? '');
        $path = $parts['path'] ?? '';
        $query = [];
        if (! empty($parts['query'])) {
            parse_str($parts['query'], $query);
        }

        if (str_contains($host, 'youtu.be')) {
            return self::normalizeYouTubeId(trim($path, '/'));
        }

        if (! empty($query['v'])) {
            return self::normalizeYouTubeId((string) $query['v']);
        }

        if (preg_match('#/(embed|shorts|live)/([a-zA-Z0-9_-]{6,})#', $path, $m)) {
            return self::normalizeYouTubeId($m[2]);
        }

        return null;
    }

    private static function normalizeYouTubeId(string $id): ?string
    {
        $id = trim($id);

        return ($id !== '' && preg_match('/^[a-zA-Z0-9_-]{6,}$/', $id)) ? $id : null;
    }

    private static function fetchVimeoThumbnail(string $url): ?string
    {
        if (! str_contains(strtolower($url), 'vimeo.com')) {
            return null;
        }

        try {
            $response = Http::timeout(6)->acceptJson()->get('https://vimeo.com/api/oembed.json', ['url' => $url]);
            if (! $response->successful()) {
                return null;
            }
            $thumb = $response->json('thumbnail_url');

            return is_string($thumb) && filter_var($thumb, FILTER_VALIDATE_URL) ? $thumb : null;
        } catch (\Throwable) {
            return null;
        }
    }
}
