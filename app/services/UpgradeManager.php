<?php
declare(strict_types=1);

namespace app\services;

use backup\Backup;
use think\facade\Db;

/** 检查并应用官网发布的单版本增量升级包。 */
final class UpgradeManager
{
    private const MIN_VERSION = '1.0.0';
    private const MAX_MANIFEST_BYTES = 1048576;
    private const MAX_PACKAGE_BYTES = 536870912;
    private const MAX_ARCHIVE_FILES = 20000;
    private const MAX_UNPACKED_BYTES = 1073741824;
    private const MAX_SQL_BYTES = 16777216;

    public static function maintenancePath(): string
    {
        return rtrim(root_path(), '\\/') . DIRECTORY_SEPARATOR . 'runtime' . DIRECTORY_SEPARATOR . 'upgrade' . DIRECTORY_SEPARATOR . 'maintenance.json';
    }

    public function check(): array
    {
        $lock = $this->acquireLock();
        if (!$lock) return $this->errorStatus('升级任务正在执行，请稍后重试');
        try {
            $recoveryError = $this->recoverInterruptedLocked();
            if ($recoveryError !== null) return $this->errorStatus($recoveryError);
            return $this->checkLocked();
        } catch (\Throwable $e) {
            return $this->errorStatus($e->getMessage());
        } finally {
            $this->releaseLock($lock);
        }
    }

    /** 每次只应用一个 from_version 与当前版本完全匹配的升级包。 */
    public function applyNext(): array
    {
        $lock = $this->acquireLock();
        if (!$lock) return ['error' => '已有升级任务正在执行，请稍后重试'];

        $package = '';
        $stage = '';
        $backupRelative = '';
        $journalWritten = false;
        $journal = [];
        try {
            $recoveryError = $this->recoverInterruptedLocked();
            if ($recoveryError !== null) throw new \RuntimeException($recoveryError);

            @set_time_limit(0);
            $status = $this->checkLocked();
            if ($status['error'] !== '') throw new \RuntimeException($status['error']);
            if (empty($status['available']) || empty($status['release'])) {
                throw new \RuntimeException('当前版本没有可直接应用的下一版本升级包');
            }
            $release = $status['release'];
            if ((string) CMS_VERSION !== $release['from_version']) {
                throw new \RuntimeException('升级包起始版本与当前 CMS 版本不一致，已停止升级');
            }
            if (!class_exists(\ZipArchive::class)) throw new \RuntimeException('服务器未启用 PHP ZIP 扩展，无法解压升级包');

            $runtime = $this->runtimeDir();
            $downloadDir = $runtime . DIRECTORY_SEPARATOR . 'downloads';
            if (!is_dir($downloadDir) && !@mkdir($downloadDir, 0755, true) && !is_dir($downloadDir)) {
                throw new \RuntimeException('无法创建升级包下载目录，请检查 runtime 目录写入权限');
            }
            $package = $downloadDir . DIRECTORY_SEPARATOR . 'miucms-' . bin2hex(random_bytes(12)) . '.zip';
            $this->downloadPackage($release, $package);

            $stage = $runtime . DIRECTORY_SEPARATOR . 'stage-' . bin2hex(random_bytes(12));
            if (!@mkdir($stage, 0755, true)) throw new \RuntimeException('无法创建升级暂存目录');
            $operations = $this->stagePackage($release, $package, $stage);
            $this->preflightOperations($operations);

            $backupId = preg_replace('/[^A-Za-z0-9._-]/', '_', $release['from_version'] . '-to-' . $release['version'])
                . '-' . date('YmdHis') . '-' . bin2hex(random_bytes(4));
            $backupRelative = 'runtime/upgrade/backups/' . $backupId;
            $backupDirectory = $this->projectPath($backupRelative);
            if (!is_dir($backupDirectory) && !@mkdir($backupDirectory, 0755, true) && !is_dir($backupDirectory)) {
                throw new \RuntimeException('无法创建升级回滚目录，请检查 runtime 目录写入权限');
            }

            $journal = [
                'status' => 'preparing',
                'from_version' => $release['from_version'],
                'version' => $release['version'],
                'backup_dir' => $backupRelative,
                'database_time' => 0,
                'database_changed' => false,
                'files' => [],
                'created_dirs' => [],
            ];
            $this->writeJson($this->journalPath(), $journal);
            $journalWritten = true;
            // 先暂停业务请求，再备份，避免备份期间产生的新数据在回滚时丢失。
            $this->writeJson(self::maintenancePath(), ['from_version' => $release['from_version'], 'version' => $release['version'], 'started_at' => time()]);

            $databaseBackup = $this->backupDatabase($backupDirectory);
            $journalFiles = $this->backupChangedFiles($operations, $backupDirectory);
            $createdDirs = $this->findCreatedDirectories($operations);
            $journal = array_merge($journal, [
                'status' => 'applying',
                'database_time' => $databaseBackup['time'],
                'database_tables_before' => $databaseBackup['tables'],
                'files' => $journalFiles,
                'created_dirs' => $createdDirs,
            ]);
            $this->writeJson($this->journalPath(), $journal);

            try {
                $this->applyOperations($operations);
                $this->runUpgradeSql($operations['sql_file'], $journal);
                try {
                    \think\facade\Cache::clear();
                    if (function_exists('clear_tpl_cache')) clear_tpl_cache();
                } catch (\Throwable $ignored) {
                    // 缓存清理失败不回滚已经完成的程序升级；缓存仍可由后台手动清理。
                }
                $journal['status'] = 'complete';
                $this->writeJson($this->journalPath(), $journal);
                @unlink(self::maintenancePath());
                @unlink($this->journalPath());
                if (function_exists('opcache_reset')) @opcache_reset();
            } catch (\Throwable $e) {
                try {
                    $this->rollback($journal);
                    @unlink(self::maintenancePath());
                    @unlink($this->journalPath());
                    throw new \RuntimeException('升级失败，已自动恢复程序文件和数据库：' . $e->getMessage(), 0, $e);
                } catch (\RuntimeException $rollbackError) {
                    if (str_starts_with($rollbackError->getMessage(), '升级失败，已自动恢复')) throw $rollbackError;
                    throw new \RuntimeException('升级失败且自动回滚未完成：' . $rollbackError->getMessage() . '；维护保护仍开启，备份位于 ' . $backupRelative, 0, $e);
                } catch (\Throwable $rollbackError) {
                    throw new \RuntimeException('升级失败且自动回滚未完成：' . $rollbackError->getMessage() . '；维护保护仍开启，备份位于 ' . $backupRelative, 0, $e);
                }
            }

            return [
                'from_version' => $release['from_version'],
                'version' => $release['version'],
                'backup' => $backupRelative,
                'skipped_protected' => $operations['skipped_protected'],
            ];
        } catch (\Throwable $e) {
            $this->recordFailure($e, $journal);
            if ($journalWritten && ($journal['status'] ?? '') === 'preparing') {
                @unlink(self::maintenancePath());
                @unlink($this->journalPath());
                if ($backupRelative !== '') $this->removeTree($this->projectPath($backupRelative));
            } elseif ($journalWritten && is_file($this->journalPath()) && is_file(self::maintenancePath())) {
                // 回滚失败时保留 journal 和维护标记，后续打开升级页会继续恢复。
            } elseif ($backupRelative !== '' && !$journalWritten) {
                $this->removeTree($this->projectPath($backupRelative));
            }
            return ['error' => $e->getMessage()];
        } finally {
            if ($package !== '' && is_file($package)) @unlink($package);
            if ($stage !== '') $this->removeTree($stage);
            $this->releaseLock($lock);
        }
    }

    private function checkLocked(): array
    {
        // 每次检查都读取官网最新清单，本地文件仅保存检查记录。
        $checkedAt = time();
        try {
            $manifest = json_decode($this->fetchManifest(), true, 512, JSON_THROW_ON_ERROR);
            $manifest = $this->validateManifest($manifest);
            $this->writeJson($this->statePath(), ['checked_at' => $checkedAt, 'manifest' => $manifest, 'error' => '']);
            return $this->statusFromManifest($manifest, $checkedAt);
        } catch (\Throwable $e) {
            $error = $e->getMessage();
            try {
                $this->writeJson($this->statePath(), ['checked_at' => $checkedAt, 'manifest' => null, 'error' => $error]);
            } catch (\Throwable $ignored) {
                $error .= '；检查结果无法保存，请检查 runtime/upgrade 写入权限';
            }
            return $this->errorStatus($error, $checkedAt);
        }
    }

    private function validateManifest($manifest): array
    {
        if (!is_array($manifest) || !$this->isList($manifest) || count($manifest) > 500) {
            throw new \RuntimeException('升级清单格式无效，必须是版本记录数组');
        }
        $keyPath = root_path() . 'config' . DIRECTORY_SEPARATOR . 'upgrade_public_key.pem';
        $publicKey = is_file($keyPath) ? @file_get_contents($keyPath) : false;
        if (!is_string($publicKey) || trim($publicKey) === '') throw new \RuntimeException('缺少升级验证公钥 config/upgrade_public_key.pem');
        if (!function_exists('openssl_pkey_get_public') || !function_exists('openssl_verify')) {
            throw new \RuntimeException('服务器未启用 OpenSSL 扩展，无法验证升级签名');
        }

        $entries = [];
        $versions = [];
        $urls = [];
        foreach ($manifest as $entry) {
            if (!is_array($entry) || !UpgradeSignature::verifyEntry($entry, $publicKey)) {
                throw new \RuntimeException('升级清单签名无效或缺少 from_version，请检查发布端清单');
            }
            if (isset($versions[$entry['version']]) || isset($urls[$entry['package_url']])) {
                throw new \RuntimeException('升级清单包含重复版本或升级包地址');
            }
            $versions[$entry['version']] = true;
            $urls[$entry['package_url']] = true;
            $entries[] = $entry;
        }
        usort($entries, static fn(array $a, array $b): int => version_compare($a['version'], $b['version']));
        $expectedFrom = self::MIN_VERSION;
        foreach ($entries as $entry) {
            if ($entry['from_version'] !== $expectedFrom || version_compare($entry['version'], $expectedFrom, '<=')) {
                throw new \RuntimeException('升级清单版本链不连续，最低起始版本为 ' . self::MIN_VERSION);
            }
            $expectedFrom = $entry['version'];
        }
        return $entries;
    }

    private function statusFromManifest(array $manifest, int $checkedAt): array
    {
        $current = defined('CMS_VERSION') ? (string) CMS_VERSION : '';
        if (!UpgradeSignature::validVersion($current) || version_compare($current, self::MIN_VERSION, '<')) {
            return $this->errorStatus('当前 CMS 版本低于升级支持的最低版本 ' . self::MIN_VERSION, $checkedAt);
        }
        $latest = $manifest ? (string) $manifest[count($manifest) - 1]['version'] : self::MIN_VERSION;
        $next = null;
        foreach ($manifest as $entry) {
            if ($entry['from_version'] === $current) {
                $next = $entry;
                break;
            }
        }
        if (version_compare($current, $latest, '>=')) {
            return ['current_version' => $current, 'latest_version' => $latest, 'available' => false, 'release' => null, 'checked_at' => $checkedAt, 'error' => ''];
        }
        if ($next === null) {
            return $this->errorStatus('未找到从当前版本 ' . $current . ' 开始的升级包；系统不会跨版本升级', $checkedAt, $latest);
        }
        return ['current_version' => $current, 'latest_version' => $latest, 'available' => true, 'release' => $next, 'checked_at' => $checkedAt, 'error' => ''];
    }

    private function fetchManifest(): string
    {
        if (filter_var(ini_get('allow_url_fopen'), FILTER_VALIDATE_BOOLEAN) !== true) {
            throw new \RuntimeException('PHP 未启用 allow_url_fopen，无法连接升级服务器');
        }
        $context = stream_context_create([
            'http' => ['method' => 'GET', 'timeout' => 8, 'follow_location' => 0, 'ignore_errors' => true, 'header' => "Accept: application/json\r\nAccept-Encoding: identity\r\nCache-Control: no-cache\r\nUser-Agent: MiuCMS-Updater/" . (defined('CMS_VERSION') ? CMS_VERSION : 'unknown') . "\r\n"],
            'ssl' => ['verify_peer' => true, 'verify_peer_name' => true],
        ]);
        $manifestUrl = rtrim((string) config('official.upgrade'), '/') . '/upgrade/stable/latest.json';
        $body = @file_get_contents($manifestUrl, false, $context, 0, self::MAX_MANIFEST_BYTES + 1);
        $headers = $http_response_header ?? [];
        if (!is_string($body)) throw new \RuntimeException('无法连接升级服务器，请检查服务器网络和 HTTPS 证书');
        if (strlen($body) > self::MAX_MANIFEST_BYTES) throw new \RuntimeException('升级清单超过 1MB，已停止读取');
        $statusLine = (string) ($headers[0] ?? '');
        if (!preg_match('/\s200(?:\s|$)/', $statusLine)) throw new \RuntimeException('升级服务器返回异常状态：' . ($statusLine ?: '未知'));
        return $body;
    }

    private function errorStatus(string $error, int $checkedAt = 0, string $latest = ''): array
    {
        return [
            'current_version' => defined('CMS_VERSION') ? (string) CMS_VERSION : '',
            'latest_version' => $latest,
            'available' => false,
            'release' => null,
            'checked_at' => $checkedAt,
            'error' => $error,
        ];
    }

    private function downloadPackage(array $release, string $destination): void
    {
        $url = rtrim((string) config('official.upgrade'), '/') . $release['package_url'];
        $context = stream_context_create([
            'http' => ['method' => 'GET', 'timeout' => 45, 'follow_location' => 0, 'ignore_errors' => true, 'header' => "Accept: application/zip, application/octet-stream\r\nAccept-Encoding: identity\r\nUser-Agent: MiuCMS-Updater/" . CMS_VERSION . "\r\n"],
            'ssl' => ['verify_peer' => true, 'verify_peer_name' => true],
        ]);
        $input = @fopen($url, 'rb', false, $context);
        $headers = $http_response_header ?? [];
        $statusLine = (string) ($headers[0] ?? '');
        if (!is_resource($input)) throw new \RuntimeException('无法下载升级包，请检查升级服务器连接');
        if (!preg_match('/\s200(?:\s|$)/', $statusLine)) {
            fclose($input);
            throw new \RuntimeException('升级包服务器返回异常状态：' . ($statusLine ?: '未知'));
        }
        $output = @fopen($destination, 'xb');
        if (!is_resource($output)) {
            fclose($input);
            throw new \RuntimeException('无法保存升级包，请检查 runtime 目录写入权限');
        }
        $bytes = 0;
        try {
            while (!feof($input)) {
                $chunk = fread($input, 1024 * 1024);
                if ($chunk === false) throw new \RuntimeException('读取升级包时连接中断');
                if ($chunk === '') continue;
                $bytes += strlen($chunk);
                if ($bytes > self::MAX_PACKAGE_BYTES) throw new \RuntimeException('升级包超过 512MB，已停止下载');
                $offset = 0;
                while ($offset < strlen($chunk)) {
                    $written = fwrite($output, substr($chunk, $offset));
                    if ($written === false || $written === 0) throw new \RuntimeException('写入升级包失败，请检查磁盘空间');
                    $offset += $written;
                }
            }
        } finally {
            fclose($input);
            fclose($output);
        }
        if ($bytes < 1) throw new \RuntimeException('升级服务器返回了空文件');

        $keyPath = root_path() . 'config' . DIRECTORY_SEPARATOR . 'upgrade_public_key.pem';
        $publicKey = @file_get_contents($keyPath);
        if (!is_string($publicKey) || !UpgradeSignature::verifyPackage($release, $destination, $publicKey)) {
            throw new \RuntimeException('升级包 SHA-256 或 RSA-SHA256 签名校验失败，已拒绝安装');
        }
    }

    /** 安全解包到 runtime 暂存区，不直接使用 ZipArchive::extractTo。 */
    private function stagePackage(array $release, string $package, string $stage): array
    {
        $zip = new \ZipArchive();
        if ($zip->open($package) !== true) throw new \RuntimeException('升级包不是有效的 ZIP 文件');
        $operations = [];
        $seen = [];
        $deleteJson = null;
        $sqlFile = null;
        $prefix = '';
        $unpacked = 0;
        try {
            if ($zip->numFiles < 1 || $zip->numFiles > self::MAX_ARCHIVE_FILES) throw new \RuntimeException('升级包文件数量超出限制');
            $names = [];
            $hasRootVersionFile = false;
            $prefixCandidates = [];
            for ($i = 0; $i < $zip->numFiles; $i++) {
                $stat = $zip->statIndex($i);
                if (!is_array($stat) || !isset($stat['name'])) throw new \RuntimeException('升级包包含无法读取的目录项');
                $name = (string) $stat['name'];
                if (str_contains($name, '\\') || str_contains($name, "\0") || str_starts_with($name, '/') || preg_match('/\A[A-Za-z]:/', $name)) {
                    throw new \RuntimeException('升级包包含非法路径');
                }
                if (str_ends_with($name, '/')) {
                    $directoryName = rtrim($name, '/');
                    if ($directoryName !== '') $this->assertRelativePath($directoryName);
                    $names[$i] = ['name' => $name, 'directory' => true];
                    continue;
                }
                $this->assertRelativePath($name);
                if (in_array(strtolower($name), ['public/index.php', 'config/version.php'], true)
                    && !in_array($name, ['public/index.php', 'config/version.php'], true)) {
                    throw new \RuntimeException('升级包中的入口和版本文件路径大小写必须完全一致');
                }
                if (in_array($name, ['public/index.php', 'config/version.php'], true)) $hasRootVersionFile = true;
                if (preg_match('#\A(.+/)(?:public/index\.php|config/version\.php)\z#', $name, $match)) $prefixCandidates[$match[1]] = true;
                $names[$i] = ['name' => $name, 'directory' => false];
            }
            if ($hasRootVersionFile && $prefixCandidates) throw new \RuntimeException('升级包中包含多个项目根目录');
            if (!$hasRootVersionFile) {
                if (count($prefixCandidates) !== 1) throw new \RuntimeException('升级包结构无效：未找到唯一的入口或版本文件');
                $prefix = (string) array_key_first($prefixCandidates);
                foreach ($names as $item) {
                    if (!$item['directory'] && !str_starts_with($item['name'], $prefix) && !str_starts_with($item['name'], '__MACOSX/')) {
                        throw new \RuntimeException('升级包根目录结构不一致');
                    }
                }
            }

            for ($i = 0; $i < $zip->numFiles; $i++) {
                $item = $names[$i];
                if ($item['directory'] || str_starts_with($item['name'], '__MACOSX/')) continue;
                $relative = $prefix === '' ? $item['name'] : substr($item['name'], strlen($prefix));
                if ($relative === '' || $relative === '.DS_Store') continue;
                $this->assertRelativePath($relative);
                if (strtolower($relative) === 'upgrade.sql' && $relative !== 'upgrade.sql') {
                    throw new \RuntimeException('升级 SQL 文件名必须为 upgrade.sql，大小写需完全一致');
                }
                if (in_array(strtolower($relative), ['public/index.php', 'config/version.php'], true)
                    && !in_array($relative, ['public/index.php', 'config/version.php'], true)) {
                    throw new \RuntimeException('升级包中的入口和版本文件路径大小写必须完全一致');
                }
                $stat = $zip->statIndex($i);
                $unpacked += (int) ($stat['size'] ?? 0);
                if ($unpacked > self::MAX_UNPACKED_BYTES) throw new \RuntimeException('升级包解压后超过 1GB 限制');
                $opsys = 0;
                $attributes = 0;
                if ($zip->getExternalAttributesIndex($i, $opsys, $attributes)
                    && $opsys === \ZipArchive::OPSYS_UNIX
                    && ((($attributes >> 16) & 0170000) === 0120000)) {
                    throw new \RuntimeException('升级包不能包含符号链接');
                }
                // 已取消 PHP 迁移约定，旧迁移目录中的文件不再部署或执行。
                if (str_starts_with(strtolower($relative), 'upgrade/migrations/')) continue;
                if ($relative === 'upgrade.sql' && (int) ($stat['size'] ?? 0) > self::MAX_SQL_BYTES) {
                    throw new \RuntimeException('upgrade.sql 超过 16MB，已停止升级');
                }
                if (strcasecmp($relative, '_miucms_delete.json') === 0) {
                    if ($deleteJson !== null) throw new \RuntimeException('升级包包含重复删除清单');
                    $deleteJson = $zip->getFromIndex($i);
                    if (!is_string($deleteJson) || strlen($deleteJson) > 1048576) throw new \RuntimeException('升级包删除清单无效');
                    continue;
                }
                if ($this->isProtectedPath($relative)) continue;
                $key = strtolower($relative);
                if (isset($seen[$key])) throw new \RuntimeException('升级包存在重复文件路径：' . $relative);
                $seen[$key] = true;
                $target = $stage . DIRECTORY_SEPARATOR . str_replace('/', DIRECTORY_SEPARATOR, $relative);
                if (!is_dir(dirname($target)) && !@mkdir(dirname($target), 0755, true) && !is_dir(dirname($target))) {
                    throw new \RuntimeException('无法创建暂存目录：' . dirname($relative));
                }
                $input = $zip->getStream($item['name']);
                $output = @fopen($target, 'xb');
                if (!is_resource($input) || !is_resource($output)) {
                    if (is_resource($input)) fclose($input);
                    if (is_resource($output)) fclose($output);
                    throw new \RuntimeException('无法读取升级包文件：' . $relative);
                }
                $copied = 0;
                try {
                    while (!feof($input)) {
                        $chunk = fread($input, 1024 * 1024);
                        if ($chunk === false) throw new \RuntimeException('读取压缩文件失败：' . $relative);
                        if ($chunk === '') continue;
                        $copied += strlen($chunk);
                        if ($copied > self::MAX_UNPACKED_BYTES) throw new \RuntimeException('压缩文件解压大小异常：' . $relative);
                        $offset = 0;
                        while ($offset < strlen($chunk)) {
                            $written = fwrite($output, substr($chunk, $offset));
                            if ($written === false || $written === 0) throw new \RuntimeException('写入暂存文件失败：' . $relative);
                            $offset += $written;
                        }
                    }
                } finally {
                    fclose($input);
                    fclose($output);
                }
                if ($copied !== (int) ($stat['size'] ?? -1)) throw new \RuntimeException('升级包文件长度校验失败：' . $relative);
                if ($relative === 'upgrade.sql') {
                    $sqlFile = $target;
                } else {
                    $operations[$key] = ['path' => $relative, 'type' => 'write', 'stage' => $target];
                }
            }
        } finally {
            $zip->close();
        }

        $indexKey = strtolower('public/index.php');
        $versionKey = 'config/version.php';
        $packageVersion = '';
        // 新版从 config/version.php 返回版本号；只读取字面量，验包阶段不执行 PHP 文件。
        if (isset($operations[$versionKey])) {
            $versionSource = @file_get_contents($operations[$versionKey]['stage'], false, null, 0, 65536);
            if (!is_string($versionSource)) throw new \RuntimeException('无法读取升级包版本文件');
            $tokens = array_values(array_filter(token_get_all($versionSource), static function ($token): bool {
                return !is_array($token) || !in_array($token[0], [T_OPEN_TAG, T_CLOSE_TAG, T_WHITESPACE, T_COMMENT, T_DOC_COMMENT], true);
            }));
            if (count($tokens) !== 3 || !is_array($tokens[0]) || $tokens[0][0] !== T_RETURN
                || !is_array($tokens[1]) || $tokens[1][0] !== T_CONSTANT_ENCAPSED_STRING || $tokens[2] !== ';') {
                throw new \RuntimeException('升级包 config/version.php 必须直接返回版本号字符串');
            }
            $packageVersion = substr($tokens[1][1], 1, -1);
        }
        if (isset($operations[$indexKey])) {
            $indexSource = @file_get_contents($operations[$indexKey]['stage'], false, null, 0, 2097152);
            if (!is_string($indexSource)) throw new \RuntimeException('无法读取升级包入口文件');
            if (preg_match('/define\s*\(\s*[\'\"]CMS_VERSION[\'\"]\s*,\s*[\'\"]([^\'\"]+)[\'\"]\s*\)/', $indexSource, $match)) {
                if ($match[1] !== $release['version']) throw new \RuntimeException('升级包入口中的 CMS_VERSION 与签名清单版本不一致');
                if ($packageVersion === '') $packageVersion = $match[1];
            }
        }
        if ($packageVersion !== $release['version']) {
            throw new \RuntimeException('升级包版本与签名清单版本不一致');
        }

        if ($deleteJson !== null) {
            $deletes = json_decode($deleteJson, true, 512, JSON_THROW_ON_ERROR);
            if (!is_array($deletes) || !$this->isList($deletes) || count($deletes) > self::MAX_ARCHIVE_FILES) {
                throw new \RuntimeException('_miucms_delete.json 必须是文件相对路径数组');
            }
            foreach ($deletes as $relative) {
                if (!is_string($relative)) throw new \RuntimeException('删除清单路径必须为文本');
                $this->assertRelativePath($relative);
                if (strtolower($relative) === 'upgrade.sql') throw new \RuntimeException('删除清单不能操作升级控制文件 upgrade.sql');
                if (in_array(strtolower($relative), [$indexKey, $versionKey], true)) throw new \RuntimeException('升级包不能删除入口或版本文件');
                if ($this->isProtectedPath($relative)) continue;
                $key = strtolower($relative);
                if (isset($seen[$key])) throw new \RuntimeException('升级包重复操作同一路径：' . $relative);
                $seen[$key] = true;
                $operations[$key] = ['path' => $relative, 'type' => 'delete', 'stage' => ''];
            }
        }

        $operations = array_values($operations);
        usort($operations, static function (array $a, array $b): int {
            $last = ['public/index.php' => 1, 'config/version.php' => 2];
            $priority = ($last[$a['path']] ?? 0) <=> ($last[$b['path']] ?? 0);
            if ($priority !== 0) return $priority;
            if ($a['type'] !== $b['type']) return $a['type'] === 'write' ? -1 : 1;
            return strcmp($a['path'], $b['path']);
        });
        $skipped = [];
        foreach ($names as $item) {
            if ($item['directory'] || str_starts_with($item['name'], '__MACOSX/')) continue;
            $relative = $prefix === '' ? $item['name'] : substr($item['name'], strlen($prefix));
            if ($relative !== '' && $this->isProtectedPath($relative)) $skipped[] = $relative;
        }
        return ['items' => $operations, 'sql_file' => $sqlFile, 'skipped_protected' => array_values(array_unique($skipped))];
    }

    private function preflightOperations(array $package): void
    {
        $root = $this->projectRoot();
        foreach ($package['items'] as $operation) {
            $this->assertRelativePath($operation['path']);
            if ($this->isProtectedPath($operation['path'])) throw new \RuntimeException('升级包试图覆盖受保护文件：' . $operation['path']);
            $target = $root . DIRECTORY_SEPARATOR . str_replace('/', DIRECTORY_SEPARATOR, $operation['path']);
            $this->assertNoSymlinkParents($target, $root);
            if (file_exists($target) && (!is_file($target) || is_link($target))) {
                throw new \RuntimeException('升级目标不是普通文件：' . $operation['path']);
            }
            $parent = dirname($target);
            while (!is_dir($parent) && $parent !== $root) $parent = dirname($parent);
            if (!is_dir($parent) || !is_writable($parent)) throw new \RuntimeException('升级目录不可写：' . $operation['path']);
        }
    }

    private function backupDatabase(string $backupDirectory): array
    {
        $path = $backupDirectory . DIRECTORY_SEPARATOR . 'database';
        if (!is_dir($path) && !@mkdir($path, 0755, true) && !is_dir($path)) throw new \RuntimeException('无法创建数据库备份目录');
        $stamp = date('Ymd-His');
        $backupTime = strtotime(date('Y-m-d H:i:s')) ?: time();
        $backup = new Backup(['path' => rtrim($path, '\\/') . DIRECTORY_SEPARATOR, 'compress' => 0, 'part' => 52428800]);
        $backup->setFile(['name' => $stamp, 'part' => 1]);
        if ($backup->Backup_Init() === false) throw new \RuntimeException('数据库备份初始化失败');
        $tables = $backup->dataList();
        if (!$tables) throw new \RuntimeException('未读取到数据库表，已停止升级');
        foreach ($tables as $table) {
            $name = (string) ($table['name'] ?? '');
            if ($name === '' || $backup->backup($name, 0) === false) throw new \RuntimeException('数据库备份失败：' . ($name ?: '未知表'));
        }
        $files = glob(rtrim($path, '\\/') . DIRECTORY_SEPARATOR . $stamp . '-*.sql') ?: [];
        if (!$files) throw new \RuntimeException('数据库备份文件未生成');
        return ['time' => $backupTime, 'name' => $stamp, 'tables' => array_column($tables, 'name')];
    }

    private function backupChangedFiles(array $package, string $backupDirectory): array
    {
        $root = $this->projectRoot();
        $fileBackupRoot = $backupDirectory . DIRECTORY_SEPARATOR . 'files';
        $journal = [];
        foreach ($package['items'] as $operation) {
            $relative = $operation['path'];
            $target = $root . DIRECTORY_SEPARATOR . str_replace('/', DIRECTORY_SEPARATOR, $relative);
            $existed = is_file($target);
            if ($existed) {
                $backup = $fileBackupRoot . DIRECTORY_SEPARATOR . str_replace('/', DIRECTORY_SEPARATOR, $relative);
                if (!is_dir(dirname($backup)) && !@mkdir(dirname($backup), 0755, true) && !is_dir(dirname($backup))) {
                    throw new \RuntimeException('无法保存文件回滚副本：' . $relative);
                }
                if (!@copy($target, $backup)) throw new \RuntimeException('无法备份待更新文件：' . $relative);
                @chmod($backup, fileperms($target) & 0777);
            }
            $journal[] = ['path' => $relative, 'existed' => $existed];
        }
        return $journal;
    }

    private function applyOperations(array $package): void
    {
        $root = $this->projectRoot();
        foreach ($package['items'] as $operation) {
            $target = $root . DIRECTORY_SEPARATOR . str_replace('/', DIRECTORY_SEPARATOR, $operation['path']);
            if ($operation['type'] === 'delete') {
                if (is_file($target) && !@unlink($target)) throw new \RuntimeException('无法删除旧文件：' . $operation['path']);
                continue;
            }
            $this->ensureTargetDirectory(dirname($target), $root);
            $this->replaceFile($operation['stage'], $target);
        }
    }

    /** 只接受本次验签升级包暂存区中的文件，不读取站点根目录的旧 upgrade.sql。 */
    private function runUpgradeSql(?string $sqlFile, array &$journal): void
    {
        if ($sqlFile === null) return;
        if (!is_file($sqlFile) || is_link($sqlFile)) throw new \RuntimeException('升级包内 upgrade.sql 文件无效');
        $sql = @file_get_contents($sqlFile, false, null, 0, self::MAX_SQL_BYTES + 1);
        if (!is_string($sql) || strlen($sql) > self::MAX_SQL_BYTES) throw new \RuntimeException('无法读取 upgrade.sql，或文件超过 16MB');
        $connection = Db::connect();
        $prefix = (string) $connection->getConfig('prefix');
        if (!preg_match('/\A[A-Za-z0-9_$]*\z/D', $prefix)) throw new \RuntimeException('数据库表前缀格式不受支持');
        $sql = str_replace('__PREFIX__', $prefix, $sql);

        try {
            $state = $connection->query('SELECT DATABASE() AS db_name, @@SESSION.sql_mode AS sql_mode', [], true)[0];
            $database = $state['db_name'];
            $sqlMode = (string) $state['sql_mode'];
            foreach (UpgradeSql::statements($sql, $sqlMode) as $index => $statement) {
                if (!$journal['database_changed']) {
                    // 在第一条 SQL 前持久化标记，中断后也能恢复数据库。
                    $journal['database_changed'] = true;
                    $this->writeJson($this->journalPath(), $journal);
                }
                try {
                    $command = $connection->getPDOStatement($statement['sql'], [], true);
                    $command->closeCursor();
                    $state = $connection->query('SELECT DATABASE() AS db_name, @@SESSION.sql_mode AS sql_mode', [], true)[0];
                    if ($state['db_name'] !== $database) throw new \RuntimeException('升级 SQL 不能切换数据库');
                    $sqlMode = (string) $state['sql_mode'];
                } catch (\Throwable $e) {
                    $journal['sql_error'] = ['number' => $index + 1, 'line' => $statement['line'], 'sql' => $statement['sql']];
                    throw new \RuntimeException('upgrade.sql 第 ' . ($index + 1) . ' 条语句（第 ' . $statement['line'] . ' 行）执行失败：' . $e->getMessage(), 0, $e);
                }
            }
            if ($journal['database_changed']) $connection->execute('COMMIT');
        } catch (\Throwable $e) {
            try { $connection->execute('ROLLBACK'); } catch (\Throwable $ignored) {}
            throw $e;
        } finally {
            // 清除 SQL_MODE、USE、FOREIGN_KEY_CHECKS 等会话变化，让回滚使用原始连接配置。
            $connection->close();
        }
    }

    private function recoverInterruptedLocked(): ?string
    {
        $journal = $this->readJson($this->journalPath());
        if (!is_file(self::maintenancePath()) && !is_array($journal)) return null;
        if (!is_array($journal)) return '检测到未完成升级维护标记，但回滚记录缺失；请保留 runtime/upgrade 目录并联系维护者';
        if (($journal['status'] ?? '') === 'complete') {
            @unlink(self::maintenancePath());
            @unlink($this->journalPath());
            return null;
        }
        if (($journal['status'] ?? '') === 'preparing') {
            @unlink(self::maintenancePath());
            @unlink($this->journalPath());
            $backupRelative = (string) ($journal['backup_dir'] ?? '');
            if (str_starts_with($backupRelative, 'runtime/upgrade/backups/')) $this->removeTree($this->projectPath($backupRelative));
            return null;
        }
        try {
            $this->rollback($journal);
            @unlink(self::maintenancePath());
            @unlink($this->journalPath());
            return null;
        } catch (\Throwable $e) {
            $this->recordFailure($e, $journal);
            return '发现未完成升级，自动恢复失败：' . $e->getMessage() . '；请保留 runtime/upgrade/backups 中的备份';
        }
    }

    private function rollback(array $journal): void
    {
        $backupRelative = (string) ($journal['backup_dir'] ?? '');
        if (!str_starts_with($backupRelative, 'runtime/upgrade/backups/')) throw new \RuntimeException('回滚目录记录无效');
        $backupDirectory = $this->projectPath($backupRelative);
        $root = $this->projectRoot();
        $errors = [];

        try {
            $databaseTime = (int) ($journal['database_time'] ?? 0);
            if ($databaseTime > 0 && ($journal['database_changed'] ?? true)) {
                $originalTables = is_array($journal['database_tables_before'] ?? null) ? $journal['database_tables_before'] : null;
                $this->restoreDatabase($backupDirectory, $databaseTime, $originalTables);
            }
        } catch (\Throwable $e) {
            $errors[] = '数据库：' . $e->getMessage();
        }

        $files = is_array($journal['files'] ?? null) ? array_reverse($journal['files']) : [];
        foreach ($files as $record) {
            if (!is_array($record) || !is_string($record['path'] ?? null)) {
                $errors[] = '文件回滚记录无效';
                continue;
            }
            try {
                $relative = $record['path'];
                $this->assertRelativePath($relative);
                if ($this->isProtectedPath($relative)) throw new \RuntimeException('回滚记录指向受保护路径');
                $target = $root . DIRECTORY_SEPARATOR . str_replace('/', DIRECTORY_SEPARATOR, $relative);
                $this->assertNoSymlinkParents($target, $root);
                if (!empty($record['existed'])) {
                    $saved = $backupDirectory . DIRECTORY_SEPARATOR . 'files' . DIRECTORY_SEPARATOR . str_replace('/', DIRECTORY_SEPARATOR, $relative);
                    if (!is_file($saved)) throw new \RuntimeException('缺少文件备份：' . $relative);
                    $this->ensureTargetDirectory(dirname($target), $root);
                    $this->replaceFile($saved, $target);
                } elseif (is_file($target) && !@unlink($target)) {
                    throw new \RuntimeException('无法移除新文件：' . $relative);
                }
            } catch (\Throwable $e) {
                $errors[] = $e->getMessage();
            }
        }

        $directories = is_array($journal['created_dirs'] ?? null) ? array_reverse($journal['created_dirs']) : [];
        foreach ($directories as $directory) {
            if (!is_string($directory) || !str_starts_with(strtolower($directory), strtolower($root . DIRECTORY_SEPARATOR))) continue;
            if (is_dir($directory) && !is_link($directory)) @rmdir($directory);
        }
        if ($errors) throw new \RuntimeException(implode('；', $errors));
        if (function_exists('opcache_reset')) @opcache_reset();
    }

    private function restoreDatabase(string $backupDirectory, int $time, ?array $originalTables = null): void
    {
        $path = $backupDirectory . DIRECTORY_SEPARATOR . 'database';
        $backup = new Backup(['path' => rtrim($path, '\\/') . DIRECTORY_SEPARATOR, 'compress' => 0]);
        $parts = $backup->getFile('timeverif', $time);
        if (!is_array($parts) || !$parts) throw new \RuntimeException('找不到完整数据库备份');
        try {
            Db::execute('SET FOREIGN_KEY_CHECKS = 0');
            if ($originalTables !== null) {
                $before = array_fill_keys($originalTables, true);
                // 备份只包含升级前的表；额外移除本次新增的表/视图，处理 DDL 隐式提交。
                foreach (Db::connect()->query('SHOW FULL TABLES', [], true) as $row) {
                    $values = array_values($row);
                    $table = (string) $values[0];
                    if (!isset($before[$table])) {
                        $kind = strtoupper((string) ($values[1] ?? '')) === 'VIEW' ? 'VIEW' : 'TABLE';
                        Db::execute('DROP ' . $kind . ' IF EXISTS `' . str_replace('`', '``', $table) . '`');
                    }
                }
            }
            foreach ($parts as $partInfo) {
                $part = (int) ($partInfo[0] ?? 0);
                if ($part < 1) throw new \RuntimeException('数据库备份分卷编号无效');
                $offset = 0;
                for ($count = 0; $count < 500000; $count++) {
                    $result = $backup->import($offset, $time, $part);
                    if ($result === false) throw new \RuntimeException('数据库备份恢复执行失败');
                    if ($result === 0) break;
                    if (!is_array($result) || !isset($result[0])) throw new \RuntimeException('数据库备份恢复进度无效');
                    $offset = (int) $result[0];
                    if ($count === 499999) throw new \RuntimeException('数据库备份分卷过大，恢复未完成');
                }
            }
        } finally {
            try { Db::execute('SET FOREIGN_KEY_CHECKS = 1'); } catch (\Throwable $ignored) {}
        }
    }

    private function replaceFile(string $source, string $target): void
    {
        $directory = dirname($target);
        $temp = $directory . DIRECTORY_SEPARATOR . '.miucms-update-' . bin2hex(random_bytes(8)) . '.tmp';
        try {
            error_clear_last();
            if (!@copy($source, $temp)) {
                throw new \RuntimeException('无法暂存升级文件 ' . $target . '：' . (error_get_last()['message'] ?? '未知文件系统错误'));
            }
            $mode = fileperms(is_file($target) ? $target : $source) & 0777;
            @chmod($temp, $mode ?: 0644);
            error_clear_last();
            if (@rename($temp, $target)) return;
            $failure = error_get_last()['message'] ?? '未知文件系统错误';
            if (!is_file($target)) throw new \RuntimeException('无法替换文件 ' . $target . '：' . $failure);

            // Windows 下不能先删除正在使用的旧文件；先保留到非公开目录，再放入新文件。
            $previous = $this->runtimeDir() . DIRECTORY_SEPARATOR . 'preserved-' . bin2hex(random_bytes(12)) . '.bak';
            error_clear_last();
            if (!@rename($target, $previous)) {
                throw new \RuntimeException('无法保留旧文件 ' . $target . '：' . (error_get_last()['message'] ?? $failure));
            }
            error_clear_last();
            if (@rename($temp, $target)) {
                @unlink($previous);
                return;
            }
            $failure = error_get_last()['message'] ?? $failure;
            if (!@rename($previous, $target)) {
                throw new \RuntimeException('无法替换文件 ' . $target . '：' . $failure . '；原文件保留在 ' . $previous);
            }
            throw new \RuntimeException('无法替换文件 ' . $target . '，已保留原文件：' . $failure);
        } finally {
            if (is_file($temp)) @unlink($temp);
        }
    }

    private function ensureTargetDirectory(string $directory, string $root): void
    {
        $missing = [];
        $current = $directory;
        while ($current !== $root && !is_dir($current)) {
            if (is_link($current)) throw new \RuntimeException('升级目录包含符号链接');
            $missing[] = $current;
            $current = dirname($current);
        }
        if ($current !== $root && !str_starts_with(strtolower($current), strtolower($root . DIRECTORY_SEPARATOR))) {
            throw new \RuntimeException('升级目录越出项目根目录');
        }
        foreach (array_reverse($missing) as $path) {
            if (!@mkdir($path, 0755) && !is_dir($path)) throw new \RuntimeException('无法创建升级目录：' . basename($path));
        }
        $real = realpath($directory);
        if ($real === false || !str_starts_with(strtolower($real . DIRECTORY_SEPARATOR), strtolower($root . DIRECTORY_SEPARATOR))) {
            throw new \RuntimeException('升级目录不在项目根目录内');
        }
    }

    private function assertNoSymlinkParents(string $target, string $root): void
    {
        $relative = substr($target, strlen($root) + 1);
        $parts = explode(DIRECTORY_SEPARATOR, $relative);
        $current = $root;
        foreach ($parts as $index => $part) {
            $current .= DIRECTORY_SEPARATOR . $part;
            if (is_link($current)) throw new \RuntimeException('升级目标路径包含符号链接');
            if ($index < count($parts) - 1 && file_exists($current) && !is_dir($current)) {
                throw new \RuntimeException('升级目标路径中间存在非目录：' . $part);
            }
        }
    }

    private function assertRelativePath(string $path): void
    {
        if ($path === '' || str_contains($path, '\\') || str_contains($path, "\0") || str_starts_with($path, '/') || preg_match('/\A[A-Za-z]:/', $path)) {
            throw new \RuntimeException('升级包包含非法相对路径');
        }
        foreach (explode('/', $path) as $part) {
            if ($part === '' || $part === '.' || $part === '..' || str_contains($part, ':')) {
                throw new \RuntimeException('升级包路径包含非法目录段');
            }
            if (preg_match('/[. ]\z/', $part) || preg_match('/\A(?:con|prn|aux|nul|com[1-9]|lpt[1-9])(?:\..*)?\z/i', $part)) {
                throw new \RuntimeException('升级包路径包含 Windows 保留名称');
            }
        }
    }

    private function isProtectedPath(string $path): bool
    {
        $path = strtolower(str_replace('\\', '/', $path));
        if ($path === '.env' || str_starts_with($path, '.env.') || $path === 'config/install.lock') return true;
        foreach (['runtime', 'storage', 'backup', 'templates', 'plugins', 'config/private', 'public/storage', 'public/skin', 'public/upgrade'] as $prefix) {
            if ($path === $prefix || str_starts_with($path, $prefix . '/')) return true;
        }
        return in_array($path, ['public/robots.txt', 'public/sitemap.xml', 'public/llms.txt', 'public/favicon.ico'], true);
    }

    private function findCreatedDirectories(array $package): array
    {
        $root = $this->projectRoot();
        $missing = [];
        foreach ($package['items'] as $operation) {
            $parent = dirname($root . DIRECTORY_SEPARATOR . str_replace('/', DIRECTORY_SEPARATOR, $operation['path']));
            while ($parent !== $root && !is_dir($parent)) {
                if (is_link($parent)) throw new \RuntimeException('升级目录路径包含符号链接');
                $missing[strtolower($parent)] = $parent;
                $parent = dirname($parent);
            }
        }
        return array_values($missing);
    }

    private function acquireLock()
    {
        $runtime = $this->runtimeDir();
        if (!is_dir($runtime) && !@mkdir($runtime, 0755, true) && !is_dir($runtime)) return false;
        $handle = @fopen($this->lockPath(), 'c+');
        if (!is_resource($handle)) return false;
        if (!flock($handle, LOCK_EX | LOCK_NB)) {
            fclose($handle);
            return false;
        }
        return $handle;
    }

    private function releaseLock($handle): void
    {
        if (is_resource($handle)) {
            flock($handle, LOCK_UN);
            fclose($handle);
        }
    }

    private function writeJson(string $path, array $data): void
    {
        $directory = dirname($path);
        if (!is_dir($directory) && !@mkdir($directory, 0755, true) && !is_dir($directory)) throw new \RuntimeException('无法创建升级状态目录');
        $json = json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR) . "\n";
        $temp = tempnam($directory, '.miu-update-');
        if ($temp === false) throw new \RuntimeException('无法写入升级状态文件');
        try {
            if (@file_put_contents($temp, $json, LOCK_EX) !== strlen($json)) throw new \RuntimeException('写入升级状态文件失败');
            @chmod($temp, 0600);
            $this->replaceFile($temp, $path);
        } finally {
            if (is_file($temp)) @unlink($temp);
        }
    }

    private function readJson(string $path): ?array
    {
        if (!is_file($path) || !is_readable($path) || filesize($path) > 2097152) return null;
        try {
            $data = json_decode((string) file_get_contents($path), true, 512, JSON_THROW_ON_ERROR);
            return is_array($data) ? $data : null;
        } catch (\Throwable $e) {
            return null;
        }
    }

    private function recordFailure(\Throwable $error, array $journal): void
    {
        try {
            $this->writeJson($this->runtimeDir() . DIRECTORY_SEPARATOR . 'last-error.json', [
                'failed_at' => date(DATE_ATOM),
                'error' => $error->getMessage(),
                'original_error' => $error->getPrevious() ? $error->getPrevious()->getMessage() : '',
                'backup_dir' => $journal['backup_dir'] ?? '',
                'from_version' => $journal['from_version'] ?? '',
                'version' => $journal['version'] ?? '',
                'sql_error' => $journal['sql_error'] ?? null,
            ]);
        } catch (\Throwable $ignored) {
            // 错误日志写入失败时仍返回原始异常，保留已有回滚记录。
        }
    }

    private function removeTree(string $path): void
    {
        $base = $this->runtimeDir();
        $real = realpath($path);
        if ($real === false || !str_starts_with(strtolower($real . DIRECTORY_SEPARATOR), strtolower($base . DIRECTORY_SEPARATOR))) return;
        $iterator = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($real, \FilesystemIterator::SKIP_DOTS),
            \RecursiveIteratorIterator::CHILD_FIRST
        );
        foreach ($iterator as $item) {
            $item->isDir() && !$item->isLink() ? @rmdir($item->getPathname()) : @unlink($item->getPathname());
        }
        @rmdir($real);
    }

    private function projectRoot(): string
    {
        $root = realpath(root_path());
        if ($root === false) throw new \RuntimeException('无法定位项目根目录');
        return rtrim($root, '\\/');
    }

    private function projectPath(string $relative): string
    {
        $this->assertRelativePath($relative);
        return $this->projectRoot() . DIRECTORY_SEPARATOR . str_replace('/', DIRECTORY_SEPARATOR, $relative);
    }

    private function runtimeDir(): string
    {
        return $this->projectRoot() . DIRECTORY_SEPARATOR . 'runtime' . DIRECTORY_SEPARATOR . 'upgrade';
    }

    private function statePath(): string { return $this->runtimeDir() . DIRECTORY_SEPARATOR . 'manifest-state.json'; }
    private function journalPath(): string { return $this->runtimeDir() . DIRECTORY_SEPARATOR . 'apply-journal.json'; }
    private function lockPath(): string { return $this->runtimeDir() . DIRECTORY_SEPARATOR . 'apply.lock'; }

    private function isList(array $value): bool
    {
        return $value === [] || array_keys($value) === range(0, count($value) - 1);
    }
}
