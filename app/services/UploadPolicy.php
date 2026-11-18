<?php

declare(strict_types=1);

namespace app\services;

final class UploadPolicy
{
    public const EXTENSIONS = [
        'image' => ['jpg', 'png', 'jpeg', 'gif', 'bmp', 'webp'],
        'avatar' => ['jpg', 'jpeg', 'png', 'gif', 'webp'],
        'doc' => ['doc', 'docx', 'ppt', 'pptx', 'xls', 'xlsx', 'pdf', 'txt'],
        'source' => ['svg', 'html', 'css', 'js'],
        'file' => ['zip', 'gz', '7z', 'rar', 'tar'],
        'video' => ['mpg', 'mp4', 'mpeg', 'avi', 'wmv', 'mov', 'flv', 'm4v', 'mkv', 'webm'],
        'audio' => ['mp3', 'wav', 'wma', 'flac', 'midi'],
    ];

    private const IMAGE_MIME_EXTENSIONS = [
        'image/jpeg' => ['jpg', 'jpeg'],
        'image/png' => ['png'],
        'image/gif' => ['gif'],
        'image/bmp' => ['bmp'],
        'image/webp' => ['webp'],
    ];

    public static function extensions(string $type = ''): array
    {
        if ($type !== '') {
            return self::EXTENSIONS[$type] ?? [];
        }

        return array_values(array_unique(array_merge(...array_values(self::EXTENSIONS))));
    }

    public static function extensionForImageMime(string $mime): ?string
    {
        $extensions = self::IMAGE_MIME_EXTENSIONS[strtolower(trim($mime))] ?? [];
        return $extensions[0] ?? null;
    }

    public static function imageMimeMatches(string $mime, string $extension, string $type = 'image'): bool
    {
        $extension = strtolower(ltrim(trim($extension), '.'));
        return in_array($extension, self::extensions($type), true)
            && in_array($extension, self::IMAGE_MIME_EXTENSIONS[strtolower(trim($mime))] ?? [], true);
    }
}
