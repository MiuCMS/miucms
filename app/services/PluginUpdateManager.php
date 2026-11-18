<?php
declare(strict_types=1);

namespace app\services;

use app\extend\plugin\PluginManager;
use think\facade\Db;

/** 官网插件清单校验、下载和安全替换。 */
final class PluginUpdateManager
{
    private const MAX_MANIFEST_BYTES = 1048576;
    private const MAX_PACKAGE_BYTES = 104857600;
    private const MAX_PACKAGE_FILES = 5000;
    private const MAX_UNPACKED_BYTES = 209715200;

    public function checkUpdates(): array
    {
        try {
            $entries = $this->readManifest();
            $local = PluginManager::instance()->getLocalPlugins();
            $pluginRows = [];
            foreach (PluginManager::instance()->getInstalledPlugins() as $row) {
                $pluginRows[(string) $row['name']] = $row;
            }

            $updates = [];
            foreach ($entries as $name => $entry) {
                if (!isset($local[$name])) {
                    continue;
                }
                $current = trim((string) ($local[$name]['version'] ?? ''));
                if (!$this->validVersion($current) || version_compare($entry['version'], $current, '<=')) {
                    continue;
                }
                $required = (string) $entry['min_cms_version'];
                $compatible = $required === '' || version_compare((string) CMS_VERSION, $required, '>=');
                $updates[$name] = [
                    'version' => $entry['version'],
                    'changelog' => $entry['changelog'],
                    'min_cms_version' => $required,
                    'compatible' => $compatible,
                    'installed' => (int) ($pluginRows[$name]['installed'] ?? 0) === 1,
                    'reason' => $compatible ? '' : '当前 MiuCMS 版本过低，请升级后再更新此插件。',
                ];
            }
            return ['code' => 0, 'msg' => '插件更新检查完成', 'data' => ['updates' => $updates]];
        } catch (\Throwable $e) {
            trace('插件更新检查失败：' . $e->getMessage(), 'error');
            return ['code' => 1, 'msg' => $e->getMessage(), 'data' => []];
        }
    }

    public function updatePlugin(string $name): array
    {
        $manager = PluginManager::instance();
        if (!$manager->isValidName($name) || $manager->isPhpKeyword($name)) {
            return ['code' => 1, 'msg' => '插件标识不合法'];
        }

        $runtime = runtime_path() . 'plugin_updates' . DIRECTORY_SEPARATOR;
        $lockDir = $runtime . 'locks' . DIRECTORY_SEPARATOR;
        if (!is_dir($lockDir) && !@mkdir($lockDir, 0755, true) && !is_dir($lockDir)) {
            return ['code' => 1, 'msg' => '无法创建插件更新锁目录，请检查 runtime 目录权限'];
        }
        $lockPath = $lockDir . hash('sha256', strtolower($name)) . '.lock';
        $lock = @fopen($lockPath, 'c');
        if (!is_resource($lock) || !flock($lock, LOCK_EX | LOCK_NB)) {
            if (is_resource($lock)) fclose($lock);
            return ['code' => 1, 'msg' => '该插件正在更新，请稍后重试'];
        }

        $package = '';
        $backup = '';
        $pluginDir = $manager->getPluginPath() . $name;
        $pluginMoved = false;
        $migrationAttempted = false;
        try {
            $entries = $this->readManifest();
            if (!isset($entries[$name])) {
                throw new \RuntimeException('官网清单中没有该插件的官方更新包');
            }
            $entry = $entries[$name];
            $row = Db::name('plugin')->where('name', $name)->find();
            $local = $manager->getPluginInfoFromFile($name);
            if (!$local) {
                throw new \RuntimeException('本地插件文件不存在');
            }
            $isInstalled = (int) ($row['installed'] ?? 0) === 1;
            $current = trim((string) ($local['version'] ?? ''));
            if (!$this->validVersion($current) || ($isInstalled && (string) ($row['version'] ?? '') !== $current)) {
                throw new \RuntimeException('本地插件文件版本与安装记录不一致，请先核对插件文件');
            }
            if (version_compare($entry['version'], $current, '<=')) {
                throw new \RuntimeException('当前插件已经是官网清单中的最新版本');
            }
            if ($entry['min_cms_version'] !== '' && version_compare((string) CMS_VERSION, $entry['min_cms_version'], '<')) {
                throw new \RuntimeException('当前 MiuCMS 版本过低，请升级后再更新此插件。');
            }

            $packageDir = $runtime . 'packages' . DIRECTORY_SEPARATOR;
            if (!is_dir($packageDir) && !@mkdir($packageDir, 0755, true) && !is_dir($packageDir)) {
                throw new \RuntimeException('无法创建插件更新目录，请检查 runtime 目录权限');
            }
            $package = $packageDir . strtolower($name) . '-' . bin2hex(random_bytes(12)) . '.zip';
            $this->downloadPackage($entry, $package);
            $archiveInfo = $this->inspectPackage($package, $name, $entry['version'], $entry['min_cms_version']);
            // 未安装的插件只更新本地插件包；数据库迁移由后续正常安装流程执行。
            $migrationFiles = $isInstalled
                ? $this->selectMigrations($archiveInfo['migrations'], $current, $entry['version'])
                : [];

            $backupRoot = $manager->getPluginPath() . '.miucms-update-backups' . DIRECTORY_SEPARATOR . $name . DIRECTORY_SEPARATOR;
            $backupBase = dirname(rtrim($backupRoot, '\\/'));
            if (is_link($pluginDir) || is_link($backupBase) || is_link(rtrim($backupRoot, '\\/'))) {
                throw new \RuntimeException('插件目录或备份目录不能是符号链接');
            }
            if (!is_dir($backupRoot) && !@mkdir($backupRoot, 0755, true) && !is_dir($backupRoot)) {
                throw new \RuntimeException('无法创建插件备份目录，请检查 plugins 目录权限');
            }
            $backup = $backupRoot . $current . '-to-' . $entry['version'] . '-' . date('YmdHis') . '-' . bin2hex(random_bytes(4));
            if (!@rename($pluginDir, $backup)) {
                throw new \RuntimeException('无法备份当前插件目录，更新已停止');
            }
            $pluginMoved = true;

            $install = $manager->installFromZip($package, false);
            if ((int) ($install['code'] ?? 1) !== 0 || !is_dir($pluginDir)) {
                throw new \RuntimeException((string) ($install['msg'] ?? '插件包安装失败'));
            }
            $this->preservePluginStorage($backup, $pluginDir);
            if ($migrationFiles) {
                $migrationAttempted = true;
                $manager->applyUpdateMigrations($name, $migrationFiles);
            }

            if ($isInstalled) {
                $updated = Db::name('plugin')->where('name', $name)->where('installed', 1)->update([
                    'version' => $entry['version'],
                    'update_time' => time(),
                ]);
                if ($updated === false) {
                    throw new \RuntimeException('插件文件已更新，但保存插件版本记录失败');
                }
            }

            $this->invalidatePluginOpcache($pluginDir);
            $manager->clearCache();
            $manager->clearMenuCache();
            $message = $isInstalled
                ? '插件已更新至 v' . $entry['version'] . '，旧文件已备份'
                : '本地插件包已更新至 v' . $entry['version'] . '，当前尚未安装；如需使用，请点击安装';
            return ['code' => 0, 'msg' => $message, 'version' => $entry['version']];
        } catch (\Throwable $e) {
            if ($migrationAttempted) {
                $e = new \RuntimeException($e->getMessage() . '；数据库迁移脚本可能已部分执行，请查看服务器日志后再处理');
            }
            if ($pluginMoved && $backup !== '') {
                if (is_dir($pluginDir)) {
                    $this->removeDirectory($pluginDir);
                }
                if (is_dir($backup) && !@rename($backup, $pluginDir)) {
                    $e = new \RuntimeException($e->getMessage() . '；旧插件备份保留在 ' . $backup . '，请手动恢复');
                }
            }
            trace('插件更新失败：' . $name . '：' . $e->getMessage(), 'error');
            return ['code' => 1, 'msg' => '插件更新失败：' . $e->getMessage()];
        } finally {
            if ($package !== '' && is_file($package)) @unlink($package);
            flock($lock, LOCK_UN);
            fclose($lock);
        }
    }

    private function readManifest(): array
    {
        $raw = $this->readRemote(rtrim((string) config('official.website'), '/') . '/upgrade/plugins/latest.json', self::MAX_MANIFEST_BYTES);
            $manifest = json_decode($raw, true);
        if (!is_array($manifest) || (int) ($manifest['schema_version'] ?? 0) !== 1
            || !is_array($manifest['plugins'] ?? null) || array_values($manifest['plugins']) !== $manifest['plugins']
            || count($manifest['plugins']) > 500) {
            throw new \RuntimeException('官网插件更新清单格式无效');
        }

        $keyPath = root_path() . 'config' . DIRECTORY_SEPARATOR . 'upgrade_public_key.pem';
        $publicKeyText = is_file($keyPath) ? @file_get_contents($keyPath) : false;
        if (!is_string($publicKeyText) || trim($publicKeyText) === ''
            || !function_exists('openssl_pkey_get_public') || !function_exists('openssl_verify')) {
            throw new \RuntimeException('缺少插件更新验证公钥或 OpenSSL 扩展');
        }
        $publicKey = openssl_pkey_get_public($publicKeyText);
        $keyDetails = $publicKey === false ? false : openssl_pkey_get_details($publicKey);
        if (!$keyDetails || $keyDetails['type'] !== OPENSSL_KEYTYPE_RSA || (int) $keyDetails['bits'] < 2048) {
            throw new \RuntimeException('插件更新验证公钥格式无效');
        }

        $entries = [];
        $seenNames = [];
        foreach ($manifest['plugins'] as $entry) {
            if (!is_array($entry)) {
                throw new \RuntimeException('官网插件更新清单包含无效记录');
            }
            foreach (['name', 'version', 'min_cms_version', 'package_url', 'sha256', 'signature_algorithm', 'signature', 'changelog'] as $field) {
                if (!isset($entry[$field]) || !is_string($entry[$field])) {
                    throw new \RuntimeException('官网插件更新记录缺少有效字段：' . $field);
                }
            }
            $name = $entry['name'];
            $required = $entry['min_cms_version'];
            if (!preg_match('/\A[A-Za-z][A-Za-z0-9_]{0,49}\z/D', $name)
                || !$this->validVersion($entry['version'])
                || ($required !== '' && !$this->validVersion($required))
                || !preg_match('/\A[a-f0-9]{64}\z/D', $entry['sha256'])
                || $entry['signature_algorithm'] !== 'RSA-SHA256'
                || strlen($entry['changelog']) > 2000
                || preg_match('/[\x00-\x08\x0B\x0C\x0E-\x1F\x7F]/', $entry['changelog'])
                || !preg_match('#\A/upgrade/plugins/' . preg_quote($name, '#') . '/[A-Za-z0-9._-]+\.zip\z#D', $entry['package_url'])
                || str_contains($entry['package_url'], '..')
                || isset($seenNames[strtolower($name)])) {
                throw new \RuntimeException('官网插件更新记录包含无效或重复字段');
            }
            $seenNames[strtolower($name)] = true;
            $signature = base64_decode($entry['signature'], true);
            $payload = implode("\n", [
                $name,
                $entry['version'],
                $required,
                $entry['package_url'],
                $entry['sha256'],
                $entry['changelog'],
            ]);
            if ($signature === false || strlen($signature) !== (int) ceil($keyDetails['bits'] / 8)
                || openssl_verify($payload, $signature, $publicKey, OPENSSL_ALGO_SHA256) !== 1) {
                throw new \RuntimeException('官网插件更新清单签名无效');
            }
            $entries[$name] = $entry;
        }
        return $entries;
    }

    private function downloadPackage(array $entry, string $destination): void
    {
        $url = rtrim((string) config('official.service'), '/') . $entry['package_url'];
        $contents = $this->readRemote($url, self::MAX_PACKAGE_BYTES);
        if (strlen($contents) < 4 || substr($contents, 0, 2) !== 'PK') {
            throw new \RuntimeException('官网返回的插件包不是有效 ZIP 文件');
        }
        $hash = hash('sha256', $contents);
        if (!hash_equals($entry['sha256'], $hash)) {
            throw new \RuntimeException('插件包 SHA-256 校验失败，已拒绝更新');
        }
        if (file_put_contents($destination, $contents, LOCK_EX) !== strlen($contents)) {
            throw new \RuntimeException('无法保存插件更新包，请检查 runtime 目录权限和磁盘空间');
        }
    }

    private function readRemote(string $url, int $limit): string
    {
        $context = stream_context_create([
            'http' => [
                'method' => 'GET',
                'timeout' => 15,
                'follow_location' => 0,
                'ignore_errors' => true,
                'header' => "Accept: application/json, application/zip, application/octet-stream\r\nAccept-Encoding: identity\r\nCache-Control: no-cache\r\nUser-Agent: MiuCMS-PluginUpdater/" . CMS_VERSION . "\r\n",
            ],
            'ssl' => ['verify_peer' => true, 'verify_peer_name' => true],
        ]);
        $stream = @fopen($url, 'rb', false, $context);
        if (!is_resource($stream)) {
            throw new \RuntimeException('无法连接插件更新服务器');
        }
        $meta = stream_get_meta_data($stream);
        $status = '';
        foreach (($meta['wrapper_data'] ?? []) as $header) {
            if (preg_match('#\AHTTP/\S+\s+(\d{3})#i', (string) $header, $match)) {
                $status = $match[1];
            }
        }
        if ($status !== '200') {
            fclose($stream);
            throw new \RuntimeException('插件更新服务器返回 HTTP ' . ($status !== '' ? $status : '未知状态'));
        }
        $body = '';
        while (!feof($stream)) {
            $chunk = fread($stream, 65536);
            if ($chunk === false) {
                fclose($stream);
                throw new \RuntimeException('读取插件更新服务器响应失败');
            }
            $body .= $chunk;
            if (strlen($body) > $limit) {
                fclose($stream);
                throw new \RuntimeException('插件更新服务器响应超过大小限制');
            }
        }
        fclose($stream);
        return $body;
    }

    private function inspectPackage(string $path, string $name, string $version, string $minimumCmsVersion): array
    {
        if (!class_exists(\ZipArchive::class)) {
            throw new \RuntimeException('服务器未启用 PHP ZIP 扩展，无法更新插件');
        }
        $zip = new \ZipArchive();
        if ($zip->open($path) !== true || $zip->numFiles < 1 || $zip->numFiles > self::MAX_PACKAGE_FILES) {
            if (isset($zip)) $zip->close();
            throw new \RuntimeException('插件包 ZIP 结构无效或文件数量超限');
        }
        $unpacked = 0;
        $hasPlugin = false;
        $migrations = [];
        $seenEntries = [];
        try {
            for ($i = 0; $i < $zip->numFiles; $i++) {
                $stat = $zip->statIndex($i);
                $entry = (string) ($stat['name'] ?? '');
                $normalized = str_replace('\\', '/', $entry);
                $trimmed = rtrim($normalized, '/');
                $segments = explode('/', $trimmed);
                if ($entry === '' || str_contains($entry, "\0") || str_starts_with($normalized, '/')
                    || preg_match('/\A[A-Za-z]:/', $normalized) || str_contains($entry, '\\')
                    || in_array('.', $segments, true) || in_array('..', $segments, true)
                    || ($trimmed !== $name && !str_starts_with($normalized, $name . '/'))) {
                    throw new \RuntimeException('插件包包含越界路径或目录标识不匹配');
                }
                $entryKey = strtolower($trimmed);
                if (isset($seenEntries[$entryKey])) {
                    throw new \RuntimeException('插件包包含大小写重复的文件路径');
                }
                $seenEntries[$entryKey] = true;
                $relative = $trimmed === $name ? '' : substr($trimmed, strlen($name) + 1);
                if (str_starts_with($relative, 'storage/') && !in_array($relative, [
                    'storage/memory', 'storage/memory/.gitignore', 'storage/memory/.htaccess',
                ], true)) {
                    throw new \RuntimeException('插件包不能覆盖 storage 中的运行数据');
                }
                $unpacked += (int) ($stat['size'] ?? 0);
                if ($unpacked > self::MAX_UNPACKED_BYTES) {
                    throw new \RuntimeException('插件包解压体积超过 200 MB');
                }
                if (method_exists($zip, 'getExternalAttributesIndex')) {
                    $opsys = 0;
                    $attributes = 0;
                    if ($zip->getExternalAttributesIndex($i, $opsys, $attributes)
                        && (($attributes >> 16) & 0170000) === 0120000) {
                        throw new \RuntimeException('插件包不允许包含符号链接');
                    }
                }
                if ($normalized === $name . '/Plugin.php') {
                    $hasPlugin = true;
                }
                if (preg_match('#\A' . preg_quote($name, '#') . '/migrations/([0-9][A-Za-z0-9.+_-]{0,63})_to_([0-9][A-Za-z0-9.+_-]{0,63})\.sql\z#D', $normalized, $match)) {
                    $migrations[] = ['file' => substr($normalized, strlen($name) + 1), 'from' => $match[1], 'to' => $match[2]];
                }
            }
            if (!$hasPlugin) {
                throw new \RuntimeException('插件包缺少标识目录下的 Plugin.php');
            }
            $source = $zip->getFromName($name . '/Plugin.php');
            if (!is_string($source) || $source === '') {
                throw new \RuntimeException('无法读取插件包中的 Plugin.php');
            }
            try {
                token_get_all($source, TOKEN_PARSE);
            } catch (\ParseError $e) {
                throw new \RuntimeException('插件包中的 Plugin.php 存在 PHP 语法错误');
            }
            $packageVersion = $this->readLiteralProperty($source, 'version');
            if ($packageVersion !== $version) {
                throw new \RuntimeException('插件包声明的版本与官网清单不一致');
            }
            $packageMinimum = $this->readLiteralProperty($source, 'minCmsVersion') ?? '';
            if ($packageMinimum !== $minimumCmsVersion) {
                throw new \RuntimeException('插件包声明的最低 CMS 版本与官网清单不一致');
            }
        } finally {
            $zip->close();
        }
        return ['migrations' => $migrations];
    }

    private function readLiteralProperty(string $source, string $property): ?string
    {
        $tokens = token_get_all($source);
        $count = count($tokens);
        for ($i = 0; $i < $count; $i++) {
            if (!is_array($tokens[$i]) || $tokens[$i][0] !== T_VARIABLE || $tokens[$i][1] !== '$' . $property) {
                continue;
            }
            $j = $i + 1;
            while ($j < $count && is_array($tokens[$j]) && in_array($tokens[$j][0], [T_WHITESPACE, T_COMMENT, T_DOC_COMMENT], true)) $j++;
            if (($tokens[$j] ?? null) !== '=') continue;
            $j++;
            while ($j < $count && is_array($tokens[$j]) && in_array($tokens[$j][0], [T_WHITESPACE, T_COMMENT, T_DOC_COMMENT], true)) $j++;
            if (!isset($tokens[$j]) || !is_array($tokens[$j]) || $tokens[$j][0] !== T_CONSTANT_ENCAPSED_STRING) continue;
            $literal = $tokens[$j][1];
            $quote = $literal[0];
            $value = substr($literal, 1, -1);
            return $quote === "'" ? str_replace(["\\\\", "\\'"], ["\\", "'"], $value) : stripcslashes($value);
        }
        return null;
    }

    private function selectMigrations(array $migrations, string $current, string $latest): array
    {
        if (!$migrations) return [];
        $selected = [];
        $cursor = $current;
        for ($guard = 0; version_compare($cursor, $latest, '<') && $guard < 100; $guard++) {
            $matches = array_values(array_filter($migrations, static fn(array $migration): bool =>
                $migration['from'] === $cursor && version_compare($migration['to'], $latest, '<=')
            ));
            if (count($matches) !== 1 || version_compare($matches[0]['to'] ?? $cursor, $cursor, '<=')) {
                throw new \RuntimeException('插件包缺少从当前版本到最新版的完整数据库迁移链');
            }
            $selected[] = $matches[0]['file'];
            $cursor = $matches[0]['to'];
        }
        if (version_compare($cursor, $latest, '!=')) {
            throw new \RuntimeException('插件包数据库迁移链未到达目标版本');
        }
        return $selected;
    }

    private function preservePluginStorage(string $backup, string $current): void
    {
        $source = $backup . DIRECTORY_SEPARATOR . 'storage';
        if (!is_dir($source) || is_link($source)) return;
        $destination = $current . DIRECTORY_SEPARATOR . 'storage';
        if (!is_dir($destination) && !@mkdir($destination, 0755, true) && !is_dir($destination)) {
            throw new \RuntimeException('无法保留插件 storage 数据');
        }
        $iterator = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($source, \FilesystemIterator::SKIP_DOTS),
            \RecursiveIteratorIterator::SELF_FIRST
        );
        foreach ($iterator as $item) {
            if ($item->isLink()) continue;
            $relative = substr($item->getPathname(), strlen($source) + 1);
            $target = $destination . DIRECTORY_SEPARATOR . $relative;
            if ($item->isDir()) {
                if (!is_dir($target) && !@mkdir($target, 0755, true) && !is_dir($target)) {
                    throw new \RuntimeException('无法恢复插件 storage 子目录');
                }
            } elseif (!@copy($item->getPathname(), $target)) {
                throw new \RuntimeException('无法恢复插件 storage 文件');
            }
        }
    }

    private function invalidatePluginOpcache(string $pluginDir): void
    {
        clearstatcache(true);
        if (!function_exists('opcache_invalidate')) return;
        $iterator = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($pluginDir, \FilesystemIterator::SKIP_DOTS)
        );
        foreach ($iterator as $file) {
            if ($file->isFile() && strtolower($file->getExtension()) === 'php') {
                @opcache_invalidate($file->getPathname(), true);
            }
        }
    }

    private function removeDirectory(string $directory): void
    {
        if (is_link($directory)) {
            @unlink($directory);
            return;
        }
        if (!is_dir($directory)) return;
        foreach (scandir($directory) ?: [] as $item) {
            if ($item === '.' || $item === '..') continue;
            $path = $directory . DIRECTORY_SEPARATOR . $item;
            if (is_link($path)) @unlink($path);
            elseif (is_dir($path)) $this->removeDirectory($path);
            else @unlink($path);
        }
        @rmdir($directory);
    }

    private function validVersion(string $version): bool
    {
        return preg_match('/\A[0-9][0-9A-Za-z.+_-]{0,63}\z/D', $version) === 1;
    }
}
