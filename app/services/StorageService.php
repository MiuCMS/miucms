<?php

declare(strict_types=1);

namespace app\services;

use app\services\storage\AliyunOss;
use app\services\storage\TencentCos;
use think\facade\Filesystem;

final class StorageService
{
    // file.filename 保留本地旧路径；云端记录用 provider 前缀标明对象键，切换配置后仍能定位原文件。
    public const ALIYUN_PREFIX = 'aliyun-oss://';
    public const TENCENT_PREFIX = 'tencent-cos://';

    public static function uploadFile(string $key, $file, string $mimeType = ''): array
    {
        $key = self::normalizeKey($key);
        if ($key === null || !is_object($file) || !method_exists($file, 'getPathname')) {
            throw new \InvalidArgumentException('上传文件路径无效');
        }

        $type = self::activeType();
        if ($type === 'oss') {
            $storedName = self::ALIYUN_PREFIX . $key;
            AliyunOss::uploadFile($key, (string) $file->getPathname(), $mimeType);
        } elseif ($type === 'cos') {
            $storedName = self::TENCENT_PREFIX . $key;
            TencentCos::uploadFile($key, (string) $file->getPathname(), $mimeType);
        } else {
            $directory = dirname($key);
            $baseName = pathinfo($key, PATHINFO_FILENAME);
            $storedName = Filesystem::disk('public')->putFile(
                $directory === '.' ? '' : $directory,
                $file,
                static fn() => $baseName
            );
            if (!is_string($storedName) || $storedName === '') {
                throw new \RuntimeException('文件保存失败');
            }
            $storedName = self::normalizeKey($storedName);
            if ($storedName === null) {
                throw new \RuntimeException('本地存储返回了无效文件路径');
            }
        }

        return ['filename' => $storedName, 'filepath' => self::urlFor($storedName)];
    }

    public static function putContents(string $key, string $contents, string $mimeType = ''): array
    {
        $key = self::normalizeKey($key);
        if ($key === null) {
            throw new \InvalidArgumentException('存储路径无效');
        }

        $type = self::activeType();
        if ($type === 'oss') {
            $storedName = self::ALIYUN_PREFIX . $key;
            AliyunOss::putContents($key, $contents, $mimeType);
        } elseif ($type === 'cos') {
            $storedName = self::TENCENT_PREFIX . $key;
            TencentCos::putContents($key, $contents, $mimeType);
        } else {
            if (!Filesystem::disk('public')->put($key, $contents)) {
                throw new \RuntimeException('文件保存失败');
            }
            $storedName = $key;
        }

        return ['filename' => $storedName, 'filepath' => self::urlFor($storedName)];
    }

    public static function parseFilename(string $filename): ?array
    {
        $filename = trim($filename);
        if (str_starts_with($filename, self::TENCENT_PREFIX)) {
            $key = self::normalizeKey(substr($filename, strlen(self::TENCENT_PREFIX)));
            return $key === null ? null : [
                'provider' => 'cos',
                'key' => $key,
                'filename' => self::TENCENT_PREFIX . $key,
            ];
        }
        if (str_starts_with($filename, self::ALIYUN_PREFIX)) {
            $key = self::normalizeKey(substr($filename, strlen(self::ALIYUN_PREFIX)));
            return $key === null ? null : [
                'provider' => 'oss',
                'key' => $key,
                'filename' => self::ALIYUN_PREFIX . $key,
            ];
        }

        $key = self::normalizeKey($filename);
        return $key === null ? null : ['provider' => 'local', 'key' => $key, 'filename' => $key];
    }

    public static function delete(string $filename): bool
    {
        $stored = self::parseFilename($filename);
        if ($stored === null) {
            return false;
        }
        if ($stored['provider'] === 'oss') {
            return AliyunOss::deleteObject($stored['key']);
        }
        if ($stored['provider'] === 'cos') {
            return TencentCos::deleteObject($stored['key']);
        }

        $root = realpath((string) config('filesystem.disks.public.root', ''));
        if ($root === false) {
            return false;
        }
        $candidate = $root . DIRECTORY_SEPARATOR . str_replace('/', DIRECTORY_SEPARATOR, $stored['key']);
        if (!file_exists($candidate) && !is_link($candidate)) {
            return true;
        }
        $realPath = realpath($candidate);
        $rootPrefix = rtrim($root, '/\\') . DIRECTORY_SEPARATOR;
        $insideRoot = $realPath !== false && (DIRECTORY_SEPARATOR === '\\'
            ? strncasecmp($realPath, $rootPrefix, strlen($rootPrefix)) === 0
            : strncmp($realPath, $rootPrefix, strlen($rootPrefix)) === 0);
        if (!$insideRoot || !is_file($realPath)) {
            return false;
        }

        return Filesystem::disk('public')->delete($stored['key']);
    }

    public static function read(string $filename): ?string
    {
        $stored = self::parseFilename($filename);
        if ($stored === null) {
            return null;
        }
        if ($stored['provider'] === 'oss') {
            return AliyunOss::getContents($stored['key']);
        }
        if ($stored['provider'] === 'cos') {
            return TencentCos::getContents($stored['key']);
        }

        $root = realpath((string) config('filesystem.disks.public.root', ''));
        if ($root === false) {
            return null;
        }
        $candidate = $root . DIRECTORY_SEPARATOR . str_replace('/', DIRECTORY_SEPARATOR, $stored['key']);
        $realPath = realpath($candidate);
        $rootPrefix = rtrim($root, '/\\') . DIRECTORY_SEPARATOR;
        $insideRoot = $realPath !== false && (DIRECTORY_SEPARATOR === '\\'
            ? strncasecmp($realPath, $rootPrefix, strlen($rootPrefix)) === 0
            : strncmp($realPath, $rootPrefix, strlen($rootPrefix)) === 0);
        if (!$insideRoot || !is_file($realPath)) {
            return null;
        }

        $contents = @file_get_contents($realPath);
        return is_string($contents) ? $contents : null;
    }

    public static function urlFor(string $filename): string
    {
        $stored = self::parseFilename($filename);
        if ($stored === null) {
            throw new \InvalidArgumentException('存储路径无效');
        }
        if ($stored['provider'] === 'oss') {
            return AliyunOss::publicUrl($stored['key']);
        }
        if ($stored['provider'] === 'cos') {
            return TencentCos::publicUrl($stored['key']);
        }

        $base = rtrim((string) get_config('filesystem.disks.public.url'), '/');
        return ($base !== '' ? $base : '/storage') . '/' . self::encodeKey($stored['key']);
    }

    public static function normalizeKey(string $key): ?string
    {
        $key = trim(str_replace('\\', '/', $key));
        if ($key === '' || str_starts_with($key, '/') || str_contains($key, "\0")
            || !preg_match('/^[A-Za-z0-9._-]+(?:\/[A-Za-z0-9._-]+)*$/D', $key)) {
            return null;
        }
        foreach (explode('/', $key) as $segment) {
            if ($segment === '.' || $segment === '..') {
                return null;
            }
        }
        return $key;
    }

    private static function activeType(): string
    {
        $type = (string) (get_config_group('global')['storage_type'] ?? 'local');
        if (!in_array($type, ['local', 'oss', 'cos'], true)) {
            throw new \RuntimeException('附件存储类型配置无效');
        }
        return $type;
    }

    private static function encodeKey(string $key): string
    {
        return implode('/', array_map('rawurlencode', explode('/', $key)));
    }
}
