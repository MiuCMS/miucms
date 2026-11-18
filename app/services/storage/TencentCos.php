<?php

declare(strict_types=1);

namespace app\services\storage;

use Qcloud\Cos\Client;

final class TencentCos
{
    public static function isSdkAvailable(): bool
    {
        return class_exists(Client::class);
    }

    public static function uploadFile(string $key, string $localPath, string $mimeType = ''): void
    {
        if (!is_file($localPath)) {
            throw new \RuntimeException('待上传的临时文件不存在');
        }
        $settings = self::settings();
        $body = @fopen($localPath, 'rb');
        if ($body === false) {
            throw new \RuntimeException('无法读取待上传文件');
        }
        try {
            self::client($settings)->putObject([
                'Bucket' => $settings['bucket'],
                'Key' => $key,
                'Body' => $body,
                'ACL' => 'public-read',
                'ContentType' => $mimeType !== '' ? $mimeType : 'application/octet-stream',
            ]);
        } finally {
            if (is_resource($body)) {
                fclose($body);
            }
        }
    }

    public static function putContents(string $key, string $contents, string $mimeType = ''): void
    {
        $settings = self::settings();
        self::client($settings)->putObject([
            'Bucket' => $settings['bucket'],
            'Key' => $key,
            'Body' => $contents,
            'ACL' => 'public-read',
            'ContentType' => $mimeType !== '' ? $mimeType : 'application/octet-stream',
        ]);
    }

    public static function deleteObject(string $key): bool
    {
        $settings = self::settings();
        self::client($settings)->deleteObject([
            'Bucket' => $settings['bucket'],
            'Key' => $key,
        ]);
        return true;
    }

    public static function getContents(string $key): ?string
    {
        $settings = self::settings();
        $result = self::client($settings)->getObject([
            'Bucket' => $settings['bucket'],
            'Key' => $key,
        ]);
        $body = $result['Body'] ?? null;
        return $body === null ? null : (string) $body;
    }

    public static function publicUrl(string $key): string
    {
        $settings = self::settings(false);
        if ($settings['region'] === '' || $settings['bucket'] === '') {
            throw new \RuntimeException('请先填写腾讯云 COS 地域和存储桶');
        }
        $domain = trim($settings['domain']);
        $baseUrl = $domain !== ''
            ? rtrim($domain, '/')
            : 'https://' . $settings['bucket'] . '.cos.' . $settings['region'] . '.myqcloud.com';
        return $baseUrl . '/' . self::encodeKey($key);
    }

    private static function client(array $settings): Client
    {
        if (!self::isSdkAvailable()) {
            throw new \RuntimeException('腾讯云 COS SDK 未安装，请在项目根目录运行 composer install');
        }
        return new Client([
            'region' => $settings['region'],
            'scheme' => 'https',
            'timeout' => 120,
            'connect_timeout' => 15,
            'credentials' => [
                'secretId' => $settings['secret_id'],
                'secretKey' => $settings['secret_key'],
            ],
        ]);
    }

    private static function settings(bool $requireCredentials = true): array
    {
        $config = get_config_group('global');
        $settings = [
            'region' => trim((string) ($config['cos_region'] ?? '')),
            'bucket' => trim((string) ($config['cos_bucket'] ?? '')),
            'secret_id' => trim((string) ($config['cos_secret_id'] ?? '')),
            'secret_key' => trim((string) ($config['cos_secret_key'] ?? '')),
            'domain' => trim((string) ($config['cos_domain'] ?? '')),
        ];
        if ($requireCredentials && ($settings['region'] === '' || $settings['bucket'] === ''
            || $settings['secret_id'] === '' || $settings['secret_key'] === '')) {
            throw new \RuntimeException('请先填写腾讯云 COS 地域、存储桶、SecretId 和 SecretKey');
        }
        return $settings;
    }

    private static function encodeKey(string $key): string
    {
        return implode('/', array_map('rawurlencode', explode('/', $key)));
    }
}
