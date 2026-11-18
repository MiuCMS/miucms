<?php

declare(strict_types=1);

namespace app\event;

use think\facade\Cache;
use think\facade\Config;
use think\facade\Db;

/**
 * 在缓存首次使用前，从网站核心配置应用缓存驱动。
 */
class CacheDriver
{
    private const DRIVER_FILE = 'cache_driver.txt';

    /** 将缓存驱动保存在运行目录，避免每次请求都查询数据库。 */
    public static function persistDriver(string $driver): bool
    {
        if (!in_array($driver, ['file', 'redis'], true)) {
            return false;
        }

        $path = self::driverFilePath();
        if (!is_dir(dirname($path)) || !is_writable(dirname($path))) {
            return false;
        }

        if (@file_put_contents($path, $driver, LOCK_EX) !== false) {
            return true;
        }

        //写入失败时移除旧值，避免继续使用和数据库设置不一致的驱动。
        @unlink($path);
        return false;
    }

    private static function driverFilePath(): string
    {
        return rtrim(root_path(), '\\/') . DIRECTORY_SEPARATOR . 'runtime' . DIRECTORY_SEPARATOR . self::DRIVER_FILE;
    }

    private static function readPersistedDriver(): ?string
    {
        $path = self::driverFilePath();
        if (!is_file($path) || !is_readable($path)) {
            return null;
        }

        $driver = trim((string) @file_get_contents($path));
        return in_array($driver, ['file', 'redis'], true) ? $driver : null;
    }

    public function handle($event): void
    {
        $settings = [];
        $settingsFromDatabase = false;
        $driver = self::readPersistedDriver();

        if ($driver === null) {
            try {
                //仅首次启动或运行目录清空后回源数据库，随后从运行目录读取驱动。
                $content = Db::name('config')->where('name', 'global')->value('content');
                $settingsFromDatabase = true;
                if (is_string($content) && $content !== '') {
                    $decoded = @unserialize($content, ['allowed_classes' => false]);
                    $settings = is_array($decoded) ? $decoded : [];
                }
                //兼容缓存选项此前临时保存在 SEO 配置组的情况；网站配置一旦保存便优先使用 global。
                if (!array_key_exists('cache_driver', $settings)) {
                    $seoContent = Db::name('config')->where('name', 'seo')->value('content');
                    if (is_string($seoContent) && $seoContent !== '') {
                        $seoSettings = @unserialize($seoContent, ['allowed_classes' => false]);
                        if (is_array($seoSettings) && array_key_exists('cache_driver', $seoSettings)) {
                            $settings['cache_driver'] = $seoSettings['cache_driver'];
                        }
                    }
                }
            } catch (\Throwable $e) {
                //未安装或配置表暂不可用时保持文件缓存，避免影响前后台启动。
            }

            $driver = in_array(($settings['cache_driver'] ?? 'file'), ['file', 'redis'], true)
                ? (string) ($settings['cache_driver'] ?? 'file')
                : 'file';
            if ($settingsFromDatabase) {
                self::persistDriver($driver);
            }
        }

        if ($driver === 'redis') {
            try {
                $store = Cache::store('redis');
                //确认 Redis 可连接；服务临时不可用时回退文件缓存，保持网站可访问。
                $store->has('__miucms_cache_driver_check__');
                Config::set(['default' => 'redis'], 'cache');
            } catch (\Throwable $e) {
                $driver = 'file';
                Config::set(['default' => 'file'], 'cache');
            }
        } else {
            Config::set(['default' => 'file'], 'cache');
        }

        if ($settingsFromDatabase && $settings) {
            try {
                //同步当前配置组缓存，避免切换驱动后读到该驱动中残留的旧网站配置。
                Cache::store($driver)->tag('system_config_global')->set('system_config_global', $settings, 86400);
            } catch (\Throwable $e) {
                //Redis 在健康检查后仍可能断开；此时回退文件缓存，保证应用可启动。
                if ($driver === 'redis') {
                    $driver = 'file';
                    Config::set(['default' => 'file'], 'cache');
                    try {
                        Cache::store('file')->tag('system_config_global')->set('system_config_global', $settings, 86400);
                    } catch (\Throwable $ignored) {
                        //缓存写入失败时，后续读取会回源数据库。
                    }
                }
            }
        }
    }
}
