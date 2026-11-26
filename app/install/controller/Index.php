<?php
/**
 * @copyright Copyright (c) 2026 米柚工作室
 * @license https://opensource.org/licenses/Apache-2.0
 * @link https://www.miucms.com
 */

declare (strict_types = 1);
namespace app\install\controller;

use app\install\validate\InstallCheck;
use mysqli;
use think\exception\ValidateException;
use think\facade\Cache;
use think\facade\View;

class Index
{
	
    public function __construct()
    {
        // 检测是否安装过
        if (is_installed()) {
            echo '<!DOCTYPE html><html lang="zh-CN"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><title>miucms安装程序</title><style>body{margin:0;padding:60px 20px;background:#f7f8fa;color:#333;font:14px/1.9 -apple-system,BlinkMacSystemFont,"Microsoft YaHei",sans-serif}.box{max-width:760px;margin:0 auto;background:#fff;border:1px solid #eceef0;border-radius:8px;padding:28px 30px}h1{font-size:17px;margin:0 0 14px;color:#e03e2d;font-weight:600}p{margin:8px 0}code{background:#f5f6f8;padding:1px 6px;border-radius:3px;font-family:Consolas,Monaco,monospace;color:#c7254e;word-break:break-all}</style></head><body><div class="box"><h1>你已经安装过米柚cms系统</h1><p>如需重新安装，请删除“<code>/config/install.lock</code>”文件</p></div></body></html>';
            die();
        }
    }

    public function index()
    {
		View::assign('TP_VERSION',\think\facade\App::version());
        return view('step1', ['baseFile' => $this->getDynamicEntry()]);
    }

    /** 返回当前站点的入口脚本路径，安装引导的内部跳转不依赖伪静态规则。 */
    private function getDynamicEntry(): string
    {
        $baseFile = (string) request()->baseFile();
        return $baseFile !== '' ? $baseFile : '/index.php';
    }

    private function getInstallationCheckData(): array
    {
        $uploadMax = (string) ini_get('upload_max_filesize');
        $uploadMaxBytes = $this->iniSizeToBytes($uploadMax);
        $minimumUploadBytes = 2 * 1024 * 1024;
        $fileUploadsEnabled = $this->isIniEnabled('file_uploads');

        $environmentChecks = [
            [
                'name' => 'PHP 版本',
                'minimum' => '≥ 8.0.2',
                'current' => PHP_VERSION,
                'ok' => PHP_VERSION_ID >= 80002,
            ],
            [
                'name' => 'fileinfo 扩展',
                'minimum' => '必须开启',
                'current' => extension_loaded('fileinfo') ? '已开启' : '未开启',
                'ok' => extension_loaded('fileinfo'),
            ],
            [
                'name' => 'mbstring 扩展',
                'minimum' => '必须开启',
                'current' => extension_loaded('mbstring') ? '已开启' : '未开启',
                'ok' => extension_loaded('mbstring'),
            ],
            [
                'name' => 'GD 扩展',
                'minimum' => '必须开启',
                'current' => extension_loaded('gd') ? '已开启' : '未开启',
                'ok' => extension_loaded('gd'),
            ],
            [
                'name' => 'PDO MySQL',
                'minimum' => '必须开启',
                'current' => extension_loaded('pdo_mysql') ? '已开启' : '未开启',
                'ok' => extension_loaded('pdo_mysql'),
            ],
            [
                'name' => 'MySQLi',
                'minimum' => '必须开启',
                'current' => extension_loaded('mysqli') ? '已开启' : '未开启',
                'ok' => extension_loaded('mysqli'),
            ],
            [
                'name' => '文件上传',
                'minimum' => '必须开启',
                'current' => $fileUploadsEnabled ? '已开启' : '未开启',
                'ok' => $fileUploadsEnabled,
            ],
            [
                'name' => '单文件上传限制',
                'minimum' => '≥ 2M',
                'current' => $uploadMax,
                'ok' => $uploadMaxBytes >= $minimumUploadBytes,
            ],
        ];

        $rootPath = rtrim(CMS_ROOT, '\\/');
        $rootWritable = $this->probeWritableDirectory($rootPath);
        $envPath = $rootPath . DIRECTORY_SEPARATOR . '.env';
        $envWritable = $rootWritable;
        $envCurrent = $rootWritable ? '可创建 .env' : '项目根目录不可写';
        if (is_file($envPath)) {
            $envHandle = @fopen($envPath, 'r+');
            $envWritable = $rootWritable && is_readable($envPath) && $envHandle !== false;
            if (is_resource($envHandle)) {
                fclose($envHandle);
            }
            $envCurrent = $envWritable ? '.env 可读写' : '.env 不可读写';
        } elseif (file_exists($envPath)) {
            $envWritable = false;
            $envCurrent = '.env 路径不是普通文件';
        }

        $configuredUploadTemp = trim((string) ini_get('upload_tmp_dir'));
        $uploadTempPath = $configuredUploadTemp !== '' ? $configuredUploadTemp : sys_get_temp_dir();
        $uploadTempWritable = $this->probeWritableDirectory($uploadTempPath);
        $uploadTempCurrent = $uploadTempWritable
            ? $uploadTempPath . '（可写）'
            : $uploadTempPath . '（不可写）';
        if (!$uploadTempWritable && $configuredUploadTemp !== '') {
            $systemTempPath = sys_get_temp_dir();
            if ($systemTempPath !== $uploadTempPath && $this->probeWritableDirectory($systemTempPath)) {
                $uploadTempWritable = true;
                $uploadTempCurrent .= '；系统临时目录可用';
            }
        }

        $checkDirectory = function (string $name, string $path, string $minimum): array {
            if (!is_dir($path)) {
                return [
                    'name' => $name,
                    'minimum' => $minimum,
                    'current' => '目录不存在',
                    'ok' => false,
                ];
            }

            $ok = $this->probeWritableDirectory($path);
            return [
                'name' => $name,
                'minimum' => $minimum,
                'current' => $ok
                    ? '可写（已实际创建、写入并删除检测文件）'
                    : '不可写（检测文件创建、写入或清理失败）',
                'ok' => $ok,
            ];
        };

        $directoryChecks = [
            [
                'name' => '.env（项目根目录）',
                'minimum' => '可读写',
                'current' => $envCurrent,
                'ok' => $envWritable,
            ],
            [
                'name' => 'PHP 上传临时目录',
                'minimum' => '可写',
                'current' => $uploadTempCurrent,
                'ok' => $uploadTempWritable,
            ],
            $checkDirectory('config', CMS_ROOT . 'config', '可写（创建安装锁）'),
            $checkDirectory('public/storage', CMS_ROOT . 'public/storage', '可写（上传文件）'),
            $checkDirectory('runtime', CMS_ROOT . 'runtime', '可写（缓存和日志）'),
        ];

        $canContinue = true;
        foreach (array_merge($environmentChecks, $directoryChecks) as $check) {
            if (!$check['ok']) {
                $canContinue = false;
                break;
            }
        }

        return [
            'environmentChecks' => $environmentChecks,
            'directoryChecks' => $directoryChecks,
            'canContinue' => $canContinue,
        ];
    }

    public function step2()
    {
        $checkData = $this->getInstallationCheckData();
        $checkData['baseFile'] = $this->getDynamicEntry();
        return view('', $checkData);
    }

    /** 实际创建、写入并删除临时文件，检测目录的写入权限。 */
    private function probeWritableDirectory(string $directory): bool
    {
        if (!is_dir($directory)) {
            return false;
        }

        try {
            $probePath = rtrim($directory, '\\/') . DIRECTORY_SEPARATOR . '.miucms_install_check_' . bin2hex(random_bytes(8));
        } catch (\Throwable $e) {
            return false;
        }

        $handle = @fopen($probePath, 'xb');
        if (!is_resource($handle)) {
            return false;
        }

        $probeContent = 'miucms-write-check';
        $written = @fwrite($handle, $probeContent);
        $flushed = @fflush($handle);
        @fclose($handle);
        $removed = !file_exists($probePath) || @unlink($probePath);

        return $written === strlen($probeContent) && $flushed && $removed;
    }

    /** 判断 php.ini 中类似 2M、1G 的上传限制是否达到要求。 */
    private function iniSizeToBytes(string $value): int
    {
        $value = trim($value);
        if ($value === '') {
            return 0;
        }

        $unit = strtolower(substr($value, -1));
        $number = (float) $value;
        $multiplier = match ($unit) {
            'g' => 1024 ** 3,
            'm' => 1024 ** 2,
            'k' => 1024,
            default => 1,
        };

        return $number > 0 ? (int) ($number * $multiplier) : 0;
    }

    /** 判断 php.ini 中的布尔开关是否开启。 */
    private function isIniEnabled(string $name): bool
    {
        return in_array(strtolower(trim((string) ini_get($name))), ['1', 'on', 'yes', 'true'], true);
    }

    public function step3()
    {
        $checkData = $this->getInstallationCheckData();
        if (!$checkData['canContinue']) {
            $checkData['baseFile'] = $this->getDynamicEntry();
            return view('step2', $checkData);
        }
        return view('', ['baseFile' => $this->getDynamicEntry()]);
    }

    /** 测试安装表单中的数据库服务器连接，不创建数据库或数据表。 */
    public function testConnection()
    {
        if (!request()->isPost()) {
            return to_assign(1, '仅支持 POST 请求');
        }

        $data = request()->post();
        $host = trim((string) ($data['DB_HOST'] ?? ''));
        $portValue = trim((string) ($data['DB_PORT'] ?? ''));
        $database = trim((string) ($data['DB_NAME'] ?? ''));
        $username = trim((string) ($data['DB_USER'] ?? ''));
        $password = (string) ($data['DB_PWD'] ?? '');

        if ($host === '') {
            return to_assign(1, '请输入数据库地址');
        }
        if ($portValue === '') {
            return to_assign(1, '请输入数据库端口');
        }
        if ($database === '') {
            return to_assign(1, '请输入数据库名');
        }
        if ($username === '') {
            return to_assign(1, '请输入数据库用户名');
        }
        if ($password === '') {
            return to_assign(1, '请输入数据库密码');
        }
        if (!ctype_digit($portValue) || (int) $portValue < 1 || (int) $portValue > 65535) {
            return to_assign(1, '数据库端口格式不正确');
        }
		$rateKey = 'install_db_test_' . hash('sha256', (string) request()->ip());
		$attempts = (int) Cache::get($rateKey, 0);
		if ($attempts >= 10) {
			return to_assign(1, '连接测试次数过多，请一分钟后再试');
		}
		Cache::set($rateKey, $attempts + 1, 60);

        $link = null;
        try {
            $link = mysqli_init();
            if (!$link) {
                return to_assign(1, '无法初始化 MySQLi 数据库连接');
            }
            $link->options(MYSQLI_OPT_CONNECT_TIMEOUT, 5);

            if (!$link->real_connect($host, $username, $password, '', (int) $portValue)) {
                $error = $link->connect_error ?: '请检查地址、端口、用户名和密码';
                $link->close();
                return to_assign(1, '数据库服务器连接失败：' . $error);
            }

            if (!$link->select_db($database)) {
                $error = $link->error ?: '当前账号无法访问目标数据库';
                $errorCode = $link->errno;
                $link->close();
                if ($errorCode === 1049) {
                    return to_assign(0, '数据库服务器连接成功！但目标数据库尚未创建；安装时会尝试创建，请确认账号有建库权限');
                }
                return to_assign(1, '服务器已连接，但无法选择目标数据库：' . $error);
            }

            $link->close();
            return to_assign(0, '连接成功，目标数据库已存在且可以访问');
        } catch (\think\exception\HttpResponseException $e) {
            throw $e;
        } catch (\Throwable $e) {
            if ($e instanceof \mysqli_sql_exception && (int) $e->getCode() === 1049) {
                if ($link instanceof mysqli) {
                    try {
                        $link->close();
                    } catch (\Throwable $closeError) {
                        // 忽略连接清理错误，保留数据库不存在的检测结果。
                    }
                }
                return to_assign(0, '数据库服务器连接成功！但目标数据库尚未创建；安装时会尝试创建，请确认账号有建库权限');
            }
            if ($link instanceof mysqli) {
                try {
                    $link->close();
                } catch (\Throwable $closeError) {
                    // 忽略连接清理错误，保留原始连接错误提示。
                }
            }
            return to_assign(1, '数据库连接测试失败：' . $e->getMessage());
        }
    }

    public function install()
    {
        $installMutex = @fopen(CMS_ROOT . 'runtime/install-process.lock', 'c');
        if (!is_resource($installMutex)) return to_assign(1, '无法创建安装任务锁，请检查 runtime 目录权限');
        if (!flock($installMutex, LOCK_EX | LOCK_NB)) {
            return to_assign(1, '安装正在进行，请勿重复提交');
        }
        register_shutdown_function(static function () use ($installMutex) { flock($installMutex, LOCK_UN); fclose($installMutex); });
        if (is_file(CMS_ROOT . 'config/install.lock')) return to_assign(1, '系统已完成安装');
        if (!request()->isPost()) {
            return to_assign(1, '仅支持 POST 请求');
        }
        if (!$this->getInstallationCheckData()['canContinue']) {
            return to_assign(1, '安装环境或目录权限检测未通过，请返回第二步处理后再安装');
        }

        $host = (string) request()->server('HTTP_HOST', '');
        $siteDomain = request()->scheme() . '://' . $host;
        if (strpbrk($host, '/\\?#@') !== false || !filter_var($siteDomain, FILTER_VALIDATE_URL)) {
            return to_assign(1, '当前访问地址格式不正确，请使用网站的域名或 IP 地址重新打开安装页');
        }

        $data = get_params();
        if (!is_array($data)) {
            return to_assign(1, '安装参数格式不正确');
        }
        foreach (['DB_TYPE', 'DB_HOST', 'DB_PORT', 'DB_USER', 'DB_PWD', 'DB_NAME', 'DB_PREFIX', 'username', 'password', 'password_confirm'] as $key) {
            if (!isset($data[$key]) || !is_scalar($data[$key])) {
                return to_assign(1, '安装参数不完整或格式不正确：' . $key);
            }
            $data[$key] = (string) $data[$key];
        }
        if (!isset($data['import_demo'])) {
            $data['import_demo'] = '0';
        } elseif (!is_scalar($data['import_demo']) || !in_array((string) $data['import_demo'], ['0', '1'], true)) {
            return to_assign(1, '演示数据导入选项格式不正确');
        } else {
            $data['import_demo'] = (string) $data['import_demo'];
        }
        if (!isset($data['overwrite_existing'])) {
            $data['overwrite_existing'] = '0';
        } elseif (!is_scalar($data['overwrite_existing']) || !in_array((string) $data['overwrite_existing'], ['0', '1'], true)) {
            return to_assign(1, '数据库覆盖选项格式不正确');
        } else {
            $data['overwrite_existing'] = (string) $data['overwrite_existing'];
        }
        foreach (['DB_HOST', 'DB_USER', 'DB_NAME', 'DB_PREFIX', 'username'] as $key) {
            $data[$key] = trim($data[$key]);
        }
        if ($data['DB_TYPE'] !== 'mysql') {
            return to_assign(1, '数据库类型固定为 MySQL/MariaDB');
        }
        if ($data['DB_HOST'] === '' || strlen($data['DB_HOST']) > 255 || preg_match('/[\x00-\x20\x7F]/', $data['DB_HOST'])) {
            return to_assign(1, '数据库地址格式不正确');
        }
        if (!ctype_digit($data['DB_PORT']) || (int) $data['DB_PORT'] < 1 || (int) $data['DB_PORT'] > 65535) {
            return to_assign(1, '数据库端口格式不正确');
        }
        if (!preg_match('/^[A-Za-z0-9_]{1,64}$/', $data['DB_NAME'])) {
            return to_assign(1, '数据库名只能使用字母、数字和下划线，长度不超过 64 位');
        }
        if (!preg_match('/^[A-Za-z0-9_]{1,32}$/', $data['DB_PREFIX'])) {
            return to_assign(1, '数据表前缀只能使用字母、数字和下划线，长度不超过 32 位');
        }
        if ($data['DB_USER'] === '' || strlen($data['DB_USER']) > 128
            || preg_match('/[\x00-\x1F\x7F]/', $data['DB_USER']) || strlen($data['DB_PWD']) > 1024) {
            return to_assign(1, '数据库用户名或密码格式不正确');
        }
        if (!preg_match('/^[A-Za-z][A-Za-z0-9_-]{4,19}$/', $data['username'])) {
            return to_assign(1, '管理员账号必须以字母开头，包含 5 到 20 位字母、数字、下划线或减号');
        }
        if (mb_strlen($data['password'], 'UTF-8') < 6 || mb_strlen($data['password'], 'UTF-8') > 128
            || $data['password'] !== $data['password_confirm']) {
            return to_assign(1, '管理员密码至少 6 位，最多 128 位，且两次输入必须一致');
        }
        try {
            validate(InstallCheck::class)->check($data);
        } catch (ValidateException $e) {
            return to_assign(1, $e->getError());
        }

        $link = null;
        $safeToCleanup = false;
        $envPath = CMS_ROOT . '.env';
        $originalEnv = is_file($envPath) ? file_get_contents($envPath) : null;
        if (is_file($envPath) && !is_string($originalEnv)) {
            return to_assign(1, '无法读取现有 .env 文件');
        }
        $restoreEnv = static function () use ($envPath, $originalEnv): void {
            if ($originalEnv === null) {
                @unlink($envPath);
            } else {
                @file_put_contents($envPath, $originalEnv, LOCK_EX);
            }
        };

        try {
            $link = new mysqli($data['DB_HOST'], $data['DB_USER'], $data['DB_PWD'], '', (int) $data['DB_PORT']);
            $link->set_charset('utf8mb4');
            $schema = $link->prepare('SELECT SCHEMA_NAME FROM INFORMATION_SCHEMA.SCHEMATA WHERE SCHEMA_NAME = ?');
            $schema->bind_param('s', $data['DB_NAME']);
            $schema->execute();
            $schema->store_result();
            $databaseExists = $schema->num_rows > 0;
            $schema->close();
            if (!$databaseExists && !$link->query("CREATE DATABASE `{$data['DB_NAME']}` DEFAULT CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci")) {
                throw new \RuntimeException('无法创建数据库，请检查账号的建库权限');
            }
            if (!$link->select_db($data['DB_NAME'])) {
                throw new \RuntimeException('无法选择数据库，请检查数据库权限');
            }

            $existing = $link->query('SHOW TABLES');
            if ($existing === false) {
                throw new \RuntimeException('无法检查数据库中的数据表：' . $link->error);
            }
            $existingTables = [];
            while ($row = $existing->fetch_row()) {
                $tableName = (string) $row[0];
                if (str_starts_with($tableName, $data['DB_PREFIX'])) {
                    $existingTables[] = $tableName;
                }
            }
            if ($existingTables && $data['overwrite_existing'] !== '1') {
                return to_assign(2, '检测到数据库中已有相同前缀的数据表，确认后将删除这些数据表及其中的数据并重新安装。此操作不可恢复，是否继续？');
            }
            if ($existingTables) {
                if (!$link->query('SET FOREIGN_KEY_CHECKS = 0')) {
                    throw new \RuntimeException('无法准备覆盖数据库：' . $link->error);
                }
                try {
                    foreach ($existingTables as $tableName) {
                        $quotedTable = str_replace('`', '``', $tableName);
                        if (!$link->query("DROP TABLE IF EXISTS `{$quotedTable}`")) {
                            throw new \RuntimeException('删除旧数据表失败（' . $tableName . '）：' . $link->error);
                        }
                    }
                } finally {
                    $link->query('SET FOREIGN_KEY_CHECKS = 1');
                }
            }
            $safeToCleanup = true;

            $sqlFile = file_get_contents(CMS_ROOT . '/app/install/data/miucms.sql');
            if (!is_string($sqlFile) || $sqlFile === '') {
                throw new \RuntimeException('安装数据文件不存在或无法读取');
            }
            if ($data['import_demo'] === '1') {
                $demoSql = file_get_contents(CMS_ROOT . '/app/install/data/demo.sql');
                if (!is_string($demoSql) || trim($demoSql) === '') {
                    throw new \RuntimeException('演示数据文件不存在或无法读取');
                }
                $sqlFile .= "\n" . $demoSql;
            }
            $sqlFile = preg_replace('/`miu_([A-Za-z0-9_]+)`/', '`' . $data['DB_PREFIX'] . '$1`', $sqlFile);
            if (!is_string($sqlFile)) {
                throw new \RuntimeException('安装数据表前缀替换失败');
            }
            $sqlStatements = preg_split('/;[ \t]*(?:\r?\n|$)/', $sqlFile);
            if (!is_array($sqlStatements)) {
                throw new \RuntimeException('安装数据文件解析失败');
            }
            foreach ($sqlStatements as $index => $sql) {
                $statement = trim((string) $sql);
                $withoutLineComments = preg_replace('/^\s*--[^\r\n]*(?:\r?\n|$)/m', '', $statement);
                if ($statement === '' || trim((string) $withoutLineComments) === '') {
                    continue;
                }
                if ($link->query($statement) === false) {
                    throw new \RuntimeException('安装数据导入失败（第 ' . ($index + 1) . ' 条 SQL）：' . $link->error);
                }
            }

            $adminTable = $link->query('SHOW TABLES');
            $adminTableExists = false;
            while ($row = $adminTable->fetch_row()) {
                if ((string) $row[0] === $data['DB_PREFIX'] . 'admin') {
                    $adminTableExists = true;
                    break;
                }
            }
            if (!$adminTableExists) {
                throw new \RuntimeException('安装数据导入后没有找到管理员表');
            }

            $globalTable = '`' . $data['DB_PREFIX'] . 'config`';
            $globalQuery = $link->query("SELECT id, content FROM {$globalTable} WHERE name = 'global' LIMIT 1");
            $globalRow = $globalQuery ? $globalQuery->fetch_assoc() : null;
            $globalConfig = is_array($globalRow) ? @unserialize((string) $globalRow['content'], ['allowed_classes' => false]) : false;
            if (!is_array($globalConfig)) {
                throw new \RuntimeException('网站基础配置导入失败');
            }
            $globalConfig['secrect'] = bin2hex(random_bytes(32));
            $globalConfig['site_domain'] = $siteDomain;
            $globalContent = serialize($globalConfig);
            $updateConfig = $link->prepare("UPDATE {$globalTable} SET content = ? WHERE id = ?");
            $globalId = (int) $globalRow['id'];
            $updateConfig->bind_param('si', $globalContent, $globalId);
            if (!$updateConfig->execute() || $updateConfig->affected_rows !== 1) {
                throw new \RuntimeException('初始化网站配置失败');
            }
            $updateConfig->close();

            $username = $data['username'];
            $password = hash_user_password($data['password']);
            $nickname = '超级管理员';
            $thumb = '/static/admin/images/default_avatar.png';
            $salt = '';
            $now = time();
            $insert = $link->prepare("INSERT INTO `{$data['DB_PREFIX']}admin` (id,username,pwd,nickname,thumb,salt,create_time,update_time) VALUES (1,?,?,?,?,?,?,?)");
            $insert->bind_param('sssssii', $username, $password, $nickname, $thumb, $salt, $now, $now);
            $insert->execute();
            $insert->close();

            if (!$this->writeDatabaseEnv($data)) {
                $this->cleanupInstallTables($link, $data['DB_PREFIX']);
                $safeToCleanup = false;
                $restoreEnv();
                $link->close();
                return to_assign(1, '写入数据库环境配置失败，请检查 .env 文件及目录权限');
            }

            $lockPath = CMS_ROOT . 'config/install.lock';
            $lock = @fopen($lockPath, 'xb');
            if (!is_resource($lock)) {
                $this->cleanupInstallTables($link, $data['DB_PREFIX']);
                $safeToCleanup = false;
                $restoreEnv();
                $link->close();
                return to_assign(1, '创建安装鉴定文件失败，请检查目录权限');
            }
            $lockContent = '米柚CMS安装鉴定文件，请勿删除！此次安装时间为：' . date('Y-m-d H:i:s');
            $lockWritten = fwrite($lock, $lockContent);
            fflush($lock);
            fclose($lock);
            if ($lockWritten !== strlen($lockContent)) {
                @unlink($lockPath);
                $this->cleanupInstallTables($link, $data['DB_PREFIX']);
                $safeToCleanup = false;
                $restoreEnv();
                $link->close();
                return to_assign(1, '创建安装鉴定文件失败，请检查目录权限');
            }

            $safeToCleanup = false;
            $link->close();
            return to_assign();
        } catch (\think\exception\HttpResponseException $e) {
            throw $e;
        } catch (\Throwable $e) {
            if ($link instanceof mysqli) {
                if ($safeToCleanup) {
                    $this->cleanupInstallTables($link, $data['DB_PREFIX']);
                    $restoreEnv();
                }
                try {
                    $link->close();
                } catch (\Throwable $closeError) {
                    // 保留原始安装错误。
                }
            }
            return to_assign(1, '安装失败：' . $e->getMessage());
        }
    }

    /** 安装失败时只清理本次安装使用的前缀表，避免影响其他表。 */
    private function cleanupInstallTables(mysqli $link, string $prefix): void
    {
        if (!preg_match('/^[A-Za-z0-9_]{1,32}$/', $prefix)) {
            return;
        }
        try {
            $link->query('SET FOREIGN_KEY_CHECKS = 0');
            $tables = $link->query('SHOW TABLES');
            while ($row = $tables->fetch_row()) {
                $table = (string) $row[0];
                if (str_starts_with($table, $prefix) && preg_match('/^[A-Za-z0-9_]+$/', $table)) {
                    $link->query("DROP TABLE IF EXISTS `{$table}`");
                }
            }
            $link->query('SET FOREIGN_KEY_CHECKS = 1');
        } catch (\Throwable $e) {
            // 清理失败不覆盖最初的安装错误。
        }
    }
    /** 按 .env.debug 的 [mysql] 格式写入数据库信息，同时保留其他环境配置。 */
    private function writeDatabaseEnv(array $data): bool
    {
        $envPath = CMS_ROOT . '.env';
        $content = is_file($envPath) ? file_get_contents($envPath) : '';
        if (!is_string($content)) {
            return false;
        }

        $lineEnding = strpos($content, "\r\n") !== false ? "\r\n" : "\n";
        $hasTrailingNewline = $content === '' || preg_match('/(?:\r\n|\n|\r)$/', $content) === 1;
        $lines = $content === '' ? [] : preg_split('/\r\n|\n|\r/', $content);
        if (!is_array($lines)) {
            return false;
        }

        $settings = [
            'type'     => 'mysql',
            'hostname' => (string) ($data['DB_HOST'] ?? ''),
            'username' => (string) ($data['DB_USER'] ?? ''),
            'password' => (string) ($data['DB_PWD'] ?? ''),
            'database' => (string) ($data['DB_NAME'] ?? ''),
            'hostport' => (string) ($data['DB_PORT'] ?? '3306'),
            'prefix'   => (string) ($data['DB_PREFIX'] ?? ''),
        ];

        $output = [];
        $written = [];
        $hasMysqlSection = false;
        $inMysqlSection = false;
        $inSection = false;
        $debugWritten = false;

        foreach ($lines as $line) {
            if (preg_match('/^\s*\[([^\]]+)\]\s*(?:[;#].*)?$/', $line, $matches)) {
                if (!$debugWritten) {
                    $output[] = 'APP_DEBUG = false';
                    $debugWritten = true;
                }
                if ($inMysqlSection) {
                    $this->appendMissingDatabaseEnvSettings($output, $written, $settings);
                }
                $inSection = true;
                $inMysqlSection = strtolower(trim($matches[1])) === 'mysql';
                $hasMysqlSection = $hasMysqlSection || $inMysqlSection;
                $output[] = $line;
                continue;
            }

            if (!$inSection && preg_match('/^(\s*)APP_DEBUG\s*=/i', $line, $matches)) {
                $output[] = $matches[1] . 'APP_DEBUG = false';
                $debugWritten = true;
                continue;
            }

            if ($inMysqlSection && preg_match('/^(\s*)(type|hostname|username|password|database|hostport|prefix)\s*=/i', $line, $matches)) {
                $key = strtolower($matches[2]);
                if (!isset($written[$key])) {
                    $output[] = $matches[1] . self::formatDatabaseEnvSetting($key, $settings[$key]);
                    $written[$key] = true;
                }
                continue;
            }

            $output[] = $line;
        }

        if ($inMysqlSection) {
            $this->appendMissingDatabaseEnvSettings($output, $written, $settings);
        }
        if (!$debugWritten) {
            $output[] = 'APP_DEBUG = false';
        }
        if (!$hasMysqlSection) {
            if ($output && end($output) !== '') {
                $output[] = '';
            }
            $output[] = '[mysql]';
            $this->appendMissingDatabaseEnvSettings($output, $written, $settings);
        }

        $newContent = implode($lineEnding, $output);
        if ($hasTrailingNewline && $newContent !== '') {
            $newContent .= $lineEnding;
        }

        return file_put_contents($envPath, $newContent, LOCK_EX) === strlen($newContent);
    }

    /** 将缺少的数据库配置项追加到 [mysql] 段。 */
    private function appendMissingDatabaseEnvSettings(array &$lines, array &$written, array $settings): void
    {
        foreach ($settings as $key => $value) {
            if (isset($written[$key])) {
                continue;
            }
            $lines[] = self::formatDatabaseEnvSetting($key, $value);
            $written[$key] = true;
        }
    }

    /** 双引号包裹并转义 .env 值，避免密码中的特殊字符破坏 INI 格式。 */
    private static function formatDatabaseEnvSetting(string $key, string $value): string
    {
        $escaped = str_replace(
            ["\\", '"', '$', "\r", "\n"],
            ["\\\\", '\\"', '\\$', '\\r', '\\n'],
            $value
        );
        return $key . ' = "' . $escaped . '"';
    }
}
