<?php

declare(strict_types=1);

namespace app\services\storage;

use AlibabaCloud\Oss\V2 as Oss;

final class AliyunOss
{
    public static function isSdkAvailable(): bool
    {
        return class_exists(Oss\Client::class);
    }

    public static function uploadFile(string $key, string $localPath, string $mimeType = ''): void
    {
        if (!is_file($localPath)) {
            throw new \RuntimeException('待上传的临时文件不存在');
        }
        $request = new Oss\Models\PutObjectRequest(
            bucket: self::settings()['bucket'],
            key: $key,
            acl: Oss\Models\ObjectACLType::PUBLIC_READ,
            contentType: $mimeType !== '' ? $mimeType : 'application/octet-stream'
        );
        self::client()->putObjectFromFile($request, $localPath);
    }

    public static function putContents(string $key, string $contents, string $mimeType = ''): void
    {
        $request = new Oss\Models\PutObjectRequest(
            bucket: self::settings()['bucket'],
            key: $key,
            acl: Oss\Models\ObjectACLType::PUBLIC_READ,
            contentType: $mimeType !== '' ? $mimeType : 'application/octet-stream',
            body: Oss\Utils::streamFor($contents)
        );
        self::client()->putObject($request);
    }

    public static function deleteObject(string $key): bool
    {
        self::client()->deleteObject(new Oss\Models\DeleteObjectRequest(self::settings()['bucket'], $key));
        return true;
    }

    public static function getContents(string $key): ?string
    {
        $result = self::client()->getObject(new Oss\Models\GetObjectRequest(self::settings()['bucket'], $key));
        if ($result->body === null) {
            return null;
        }
        $contents = $result->body->getContents();
        return is_string($contents) ? $contents : null;
    }

    public static function publicUrl(string $key): string
    {
        $settings = self::settings(false);
        $domain = trim((string) ($settings['domain'] ?? ''));
        if ($domain !== '') {
            return rtrim($domain, '/') . '/' . self::encodeKey($key);
        }
        if ($settings['region'] === '' || $settings['bucket'] === '') {
            throw new \RuntimeException('请先填写 OSS 地域和 Bucket');
        }
        $endpoint = self::endpointHost((string) ($settings['endpoint'] ?? ''));
        if ($endpoint === '') {
            $endpoint = 'oss-' . $settings['region'] . '.aliyuncs.com';
        }
        $bucketPrefix = $settings['bucket'] . '.';
        $host = str_starts_with(strtolower($endpoint), strtolower($bucketPrefix)) ? $endpoint : $bucketPrefix . $endpoint;
        return 'https://' . $host . '/' . self::encodeKey($key);
    }

    private static function client(): Oss\Client
    {
        if (!self::isSdkAvailable()) {
            throw new \RuntimeException('阿里云 OSS SDK 未安装，请在项目根目录运行 composer install');
        }
        $settings = self::settings();
        $config = Oss\Config::loadDefault();
        $config->setCredentialsProvider(new Oss\Credentials\StaticCredentialsProvider(
            $settings['access_key_id'],
            $settings['access_key_secret']
        ));
        $config->setRegion($settings['region']);
        $config->setConnectTimeout(15);
        $config->setReadwriteTimeout(120);
        $endpoint = self::endpointHost((string) ($settings['endpoint'] ?? ''));
        if ($endpoint !== '') {
            $config->setEndpoint($endpoint);
        }
        return new Oss\Client($config);
    }

    private static function settings(bool $requireCredentials = true): array
    {
        $config = get_config_group('global');
        // Endpoint 使用 oss- 前缀，SDK 签名地域使用 cn-beijing 形式。
        $region = preg_replace('/^oss-/i', '', trim((string) ($config['oss_region'] ?? '')));
        $settings = [
            'region' => $region ?? '',
            'bucket' => trim((string) ($config['oss_bucket'] ?? '')),
            'access_key_id' => trim((string) ($config['oss_access_key_id'] ?? '')),
            'access_key_secret' => trim((string) ($config['oss_access_key_secret'] ?? '')),
            'endpoint' => trim((string) ($config['oss_endpoint'] ?? '')),
            'domain' => trim((string) ($config['oss_domain'] ?? '')),
        ];
        if ($requireCredentials && ($settings['region'] === '' || $settings['bucket'] === ''
            || $settings['access_key_id'] === '' || $settings['access_key_secret'] === '')) {
            throw new \RuntimeException('请先填写阿里云 OSS 地域、Bucket、AccessKey ID 和 AccessKey Secret');
        }
        return $settings;
    }

    private static function endpointHost(string $endpoint): string
    {
        $endpoint = trim($endpoint);
        if ($endpoint === '') {
            return '';
        }
        $parts = parse_url(str_contains($endpoint, '://') ? $endpoint : 'https://' . $endpoint);
        if (!is_array($parts) || empty($parts['host']) || isset($parts['user']) || isset($parts['pass'])
            || (isset($parts['path']) && $parts['path'] !== '' && $parts['path'] !== '/')
            || isset($parts['query']) || isset($parts['fragment'])) {
            throw new \RuntimeException('阿里云 OSS Endpoint 格式无效');
        }
        return (string) $parts['host'] . (isset($parts['port']) ? ':' . (int) $parts['port'] : '');
    }

    private static function encodeKey(string $key): string
    {
        return implode('/', array_map('rawurlencode', explode('/', $key)));
    }
}
