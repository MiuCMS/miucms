<?php
declare(strict_types=1);

namespace app\services;

/** 校验官网发布的增量升级包签名。 */
final class UpgradeSignature
{
    public static function verifyEntry(array $entry, string $trustedPublicKey): bool
    {
        foreach (['from_version', 'version', 'package_url', 'sha256', 'signature'] as $field) {
            if (!isset($entry[$field]) || !is_string($entry[$field]) || $entry[$field] === '') return false;
        }
        if (($entry['signature_algorithm'] ?? '') !== 'RSA-SHA256'
            || !self::validVersion((string) $entry['from_version'])
            || !self::validVersion((string) $entry['version'])
            || version_compare($entry['from_version'], '1.0.0', '<')
            || version_compare($entry['version'], $entry['from_version'], '<=' )
            || !preg_match('/\A[a-f0-9]{64}\z/D', $entry['sha256'])
            || !preg_match('#\A/upgrade/[A-Za-z0-9._-]+\.zip\z#iD', $entry['package_url'])
            || str_contains($entry['package_url'], '..')) {
            return false;
        }

        $publicKey = openssl_pkey_get_public($trustedPublicKey);
        $details = $publicKey === false ? false : openssl_pkey_get_details($publicKey);
        $signature = base64_decode($entry['signature'], true);
        if (!$details || $details['type'] !== OPENSSL_KEYTYPE_RSA || $details['bits'] < 2048 || $signature === false) {
            return false;
        }

        return openssl_verify(self::payload($entry), $signature, $publicKey, OPENSSL_ALGO_SHA256) === 1;
    }

    public static function verifyPackage(array $entry, string $package, string $trustedPublicKey): bool
    {
        if (!is_file($package) || !self::verifyEntry($entry, $trustedPublicKey)) return false;
        $hash = hash_file('sha256', $package);
        return is_string($hash) && hash_equals($entry['sha256'], $hash);
    }

    public static function validVersion(string $version): bool
    {
        return preg_match('/\A[0-9][0-9A-Za-z.+_-]{0,63}\z/D', $version) === 1;
    }

    private static function payload(array $entry): string
    {
        return $entry['from_version'] . "\n" . $entry['version'] . "\n" . $entry['package_url'] . "\n" . $entry['sha256'];
    }
}
