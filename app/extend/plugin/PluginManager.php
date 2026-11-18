<?php
/**
 * @copyright Copyright (c) 2026 米柚工作室
 * @license https://opensource.org/licenses/Apache-2.0
 * @link https://www.miucms.com
 */

declare(strict_types=1);

namespace app\extend\plugin;

use think\facade\Cache;
use think\facade\Db;
use think\facade\Session;
use think\facade\View;

/**
 * 插件管理器
 *
 * 负责插件的扫描、安装、卸载、启用、禁用、配置、后台菜单注册、zip 安装等。
 *
 * 目录约定（plugins/<name>/）：
 *   Plugin.php          插件主类（必须，继承 PluginBase）
 *   config.php          默认配置（可选）
 *   install.sql         安装时执行的 SQL，表前缀写 __PREFIX__（可选）
 *   uninstall.sql       卸载时执行的 SQL（可选）
 *   hooks.php           钩子映射 ['钩子名' => '方法名']（可选）
 *   admin/menu.php      后台菜单定义（可选）
 *   admin/route.php     后台路由 ['规则' => '类@方法']（可选）
 *   admin/controller/   后台控制器（可选）
 *   admin/view/         后台视图
 *   route.php           前台路由 ['规则' => '类@方法']（可选）
 *   controller/         前台控制器（可选）
 *   view/               前台视图
 *   taglib/             模板标签类（可选）
 */
class PluginManager
{
    /**
     * 单例
     * @var PluginManager|null
     */
    private static ?PluginManager $instance = null;

    /**
     * 插件目录（带尾部分隔符）
     * @var string
     */
    protected string $pluginPath;

    /**
     * 缓存标签名（插件相关缓存都打这个 tag，改插件状态时整块清掉）
     */
    public const CACHE_TAG = 'plugin';

    /**
     * 已启用插件名列表的缓存键
     */
    public const CACHE_KEY_ENABLED = 'plugin_enabled_names';

    /**
     * 插件标识允许的字符
     */
    private const NAME_PATTERN = '/^[A-Za-z0-9_]+$/';

    /**
     * PHP 关键字 / 保留字
     *
     * 插件标识会同时当「PHP 命名空间段」和「类名前缀」用（plugins\<标识>\Plugin、类名 ucfirst(标识)），
     * 下面这些词放进去会直接语法报错（实测 60 多个，比如 list、class、array、match……），
     * 所以创建插件和装 zip 包时都要拦掉。
     */
    public const PHP_KEYWORDS = [
        'abstract', 'and', 'array', 'as', 'break', 'callable', 'case', 'catch', 'class', 'clone', 'const',
        'continue', 'declare', 'default', 'die', 'do', 'echo', 'else', 'elseif', 'empty', 'enddeclare',
        'endfor', 'endforeach', 'endif', 'endswitch', 'endwhile', 'enum', 'eval', 'exit', 'extends',
        'final', 'finally', 'fn', 'for', 'foreach', 'function', 'global', 'goto', 'if', 'implements',
        'include', 'include_once', 'instanceof', 'insteadof', 'interface', 'isset', 'list', 'match',
        'namespace', 'new', 'or', 'print', 'private', 'protected', 'public', 'readonly', 'require',
        'require_once', 'return', 'static', 'switch', 'throw', 'trait', 'try', 'unset', 'use', 'var',
        'while', 'xor', 'yield',
        // 类型名 / 特殊值
        'int', 'integer', 'float', 'double', 'bool', 'boolean', 'string', 'true', 'false', 'null', 'void',
        'iterable', 'object', 'mixed', 'never', 'resource', 'numeric',
        // 魔术常量
        '__class__', '__dir__', '__file__', '__function__', '__line__', '__method__', '__namespace__', '__trait__',
        // 类上下文里的特殊名
        'this', 'self', 'parent',
    ];

    /**
     * 插件标识保留词（除 PHP 关键字外，容易撞上的词）
     *
     * 判断时忽略大小写。分四类：
     *   1) 框架/系统目录与命名空间名
     *   2) 插件系统自身的目录与关键词
     *   3) 应用名 / 后台模块名
     *   4) 超全局、HTTP 常见名、项目自身关键词
     */
    public const RESERVED_NAMES = [
        // 1) 框架 / 系统目录、命名空间
        'app', 'think', 'vendor', 'config', 'route', 'public', 'runtime', 'extend', 'data', 'uploads',
        'upload', 'static', 'assets', 'asset', 'storage', 'cache', 'log', 'logs', 'temp', 'backup',
        'database', 'db', 'system', 'sys', 'core', 'kernel', 'console', 'bootstrap', 'helper', 'helpers',
        'facade', 'exception', 'middleware', 'provider', 'event', 'command', 'model', 'controller',
        'view', 'validate', 'taglib', 'traits', 'services', 'service', 'common', 'custom', 'templates',
        'template', 'html', 'install',
        // 2) 插件系统自身的目录 / 关键词
        'plugins', 'plugin', 'hooks', 'hook', 'menu', 'scaffold', 'manager', 'plugincase', 'pluginbase',
        'uninstall', 'enable', 'disable', 'zip',
        // 3) 应用名 / 后台模块名
        'admin', 'admin123', 'home', 'api', 'index', 'login', 'logout', 'main', 'conf', 'seo', 'nav',
        'document', 'channel', 'keywords', 'links', 'link', 'level', 'role', 'rule', 'group', 'user',
        'files', 'file', 'analysis', 'adv', 'form', 'search', 'clue', 'tag', 'tags', 'department',
        // 4) 超全局 / HTTP 常见名 / 项目自身
        'globals', 'server', 'request', 'response', 'session', 'cookie', 'env', 'get', 'post', 'put',
        'patch', 'delete', 'options', 'trace', 'connect', 'cms', 'miu', 'miucms', 'layui', 'jquery',
        'ajax', 'json', 'xml', 'css', 'js',
    ];


    /**
     * 插件包解压后的体积上限（防 zip 炸弹：20M 的压缩包不该解出这么多）
     */
    private const MAX_UNZIP_BYTES = 200 * 1024 * 1024;

    /**
     * 插件包里的文件数上限
     */
    private const MAX_UNZIP_FILES = 5000;

    /** 插件安装/卸载 SQL 文件上限，避免解析超大脚本耗尽内存。 */
    private const MAX_PLUGIN_SQL_BYTES = 2 * 1024 * 1024;

    /** 单个插件 SQL 文件最多执行的语句数。 */
    private const MAX_PLUGIN_SQL_STATEMENTS = 1000;

    public static function instance(): PluginManager
    {
        if (self::$instance === null) {
            self::$instance = new self();
        }
        return self::$instance;
    }

    private function __construct()
    {
        $this->pluginPath = root_path() . 'plugins' . DIRECTORY_SEPARATOR;
    }

    /**
     * 插件根目录
     * @return string
     */
    public function getPluginPath(): string
    {
        return $this->pluginPath;
    }

    // ==================== 表 / 扫描 ====================

    /**
     * 插件表不存在时自动建表
     *
     * 新装站点由 app/install/data/miucms.sql 建好；这里兜的是「老站点」——
     * 装 CMS 的时候还没有插件系统，库里没有 cms_plugin 表。
     * 建表语句和 miucms.sql 里那份保持一致，改的时候两边一起改。
     * @return void
     */
    public function ensureTable(): void
    {
        $prefix = config('database.connections.mysql.prefix');
        $table  = $prefix . 'plugin';
        try {
            $exists = Db::query("SHOW TABLES LIKE '{$table}'");
            if (!empty($exists)) {
                return;
            }

            $sql = <<<SQL
CREATE TABLE IF NOT EXISTS `{$table}` (
  `id` int(11) UNSIGNED NOT NULL AUTO_INCREMENT COMMENT 'ID',
  `name` varchar(50) NOT NULL DEFAULT '' COMMENT '插件标识（目录名）',
  `title` varchar(100) NOT NULL DEFAULT '' COMMENT '插件名称',
  `version` varchar(20) NOT NULL DEFAULT '1.0.0' COMMENT '版本号',
  `author` varchar(50) NOT NULL DEFAULT '' COMMENT '作者',
  `description` text COMMENT '插件描述',
  `config` text COMMENT '插件配置(JSON)',
  `rule_ids` varchar(1000) NOT NULL DEFAULT '' COMMENT '插件注册的后台菜单节点ID(逗号分隔)',
  `installed` tinyint(1) NOT NULL DEFAULT 0 COMMENT '是否已安装',
  `status` tinyint(1) NOT NULL DEFAULT 0 COMMENT '是否启用',
  `install_time` int(11) UNSIGNED DEFAULT 0 COMMENT '安装时间',
  `update_time` int(11) UNSIGNED DEFAULT 0 COMMENT '更新时间',
  PRIMARY KEY (`id`),
  UNIQUE KEY `uk_name` (`name`),
  KEY `idx_status` (`status`),
  KEY `idx_installed` (`installed`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COMMENT='插件表';
SQL;
            Db::execute($sql);
        } catch (\Throwable $e) {
            trace('插件表初始化失败：' . $e->getMessage(), 'error');
        }
    }

    /**
     * 扫描本地插件（读文件系统，不需要数据库）
     * @return array<string, array>
     */
    public function getLocalPlugins(): array
    {
        $plugins = [];
        if (!is_dir($this->pluginPath)) {
            return $plugins;
        }
        foreach (scandir($this->pluginPath) as $dir) {
            if ($dir === '.' || $dir === '..') {
                continue;
            }
            if (!is_dir($this->pluginPath . $dir)) {
                continue;
            }
            if (!is_file($this->pluginPath . $dir . DIRECTORY_SEPARATOR . 'Plugin.php')) {
                continue;
            }
            $info = $this->getPluginInfoFromFile($dir);
            if ($info) {
                $plugins[$dir] = $info;
            }
        }
        return $plugins;
    }

    /**
     * 读取单个插件文件信息
     * @param string $name
     * @return array
     */
    public function getPluginInfoFromFile(string $name): array
    {
        if (!$this->isValidName($name)) {
            return [];
        }
        $dir = $this->pluginPath . $name;
        if (!is_file($dir . DIRECTORY_SEPARATOR . 'Plugin.php')) {
            return [];
        }

        $class = "plugins\\{$name}\\Plugin";
        if (!class_exists($class)) {
            return [];
        }

        try {
            /** @var PluginBase $plugin */
            $plugin = new $class();
        } catch (\Throwable $e) {
            return [];
        }

        $info = $plugin->getInfo();
        $info['name']            = $name;
        $info['config_fields']   = $plugin->getConfigFields();
        $info['has_admin']       = is_dir($dir . DIRECTORY_SEPARATOR . 'admin');
        $info['has_route']       = is_file($dir . DIRECTORY_SEPARATOR . 'route.php');
        $info['has_admin_route'] = is_file($dir . DIRECTORY_SEPARATOR . 'admin' . DIRECTORY_SEPARATOR . 'route.php');
        $info['has_taglib']      = is_dir($dir . DIRECTORY_SEPARATOR . 'taglib');
        $info['has_install_sql'] = is_file($dir . DIRECTORY_SEPARATOR . 'install.sql');
        return $info;
    }

    /**
     * 已安装插件（数据库）
     * @return array
     */
    public function getInstalledPlugins(): array
    {
        try {
            $query = Db::name('plugin');
            $callerPlugin = $this->pluginCallerName();
            if ($callerPlugin !== '') {
                $query->where('name', $callerPlugin);
            }
            return $query->order('id', 'asc')->select()->toArray();
        } catch (\Throwable $e) {
            return [];
        }
    }

    /**
     * 合并「本地文件 + 数据库」的单个插件信息
     * @param string $name
     * @return array|null
     */
    public function getPlugin(string $name): ?array
    {
        $this->assertPluginRowReadAccess($name);
        $local = $this->getPluginInfoFromFile($name);
        if (empty($local)) {
            return null;
        }

        $local['installed'] = 0;
        $local['status']    = 0;
        $local['config']    = [];
        $local['rule_ids']  = [];

        try {
            $db = Db::name('plugin')->where('name', $name)->find();
            if ($db) {
                $local['id']           = $db['id'];
                $local['installed']    = (int) $db['installed'];
                $local['status']       = (int) $db['status'];
                $local['config']       = $this->decodeConfig($db['config']);
                $local['rule_ids']     = $this->decodeRuleIds($db['rule_ids'] ?? '');
                $local['install_time'] = $db['install_time'];
                $local['update_time']  = $db['update_time'];
            }
        } catch (\Throwable $e) {
            //表不存在时按「未安装」处理
        }

        return $local;
    }

    /**
     * 实例化插件并注入已保存的配置（钩子回调 / 业务调用用）
     * @param string $name
     * @return PluginBase|null
     */
    public function makePlugin(string $name): ?PluginBase
    {
        if (!$this->isValidName($name)) {
            return null;
        }
        $class = "plugins\\{$name}\\Plugin";
        if (!class_exists($class)) {
            return null;
        }
        /** @var PluginBase $plugin */
        $plugin = new $class();
        $plugin->setConfig($this->getPluginConfig($name));
        return $plugin;
    }

    // ==================== 启用列表（带缓存）====================

    /**
     * 已启用插件名列表（带持久缓存；装/卸/启用/禁用后由 clearCache 清掉）
     * @return array<int, string>
     */
    public function getEnabledPluginNames(): array
    {
        //用 Cache 门面直接读，避免依赖 app/common.php 的全局 helper
        //（路由文件加载时全局 helper 可能还没就绪）
        $cached = Cache::get(self::CACHE_KEY_ENABLED);
        if (is_array($cached)) {
            return $cached;
        }

        $names = [];
        try {
            $names = Db::name('plugin')
                ->where('installed', 1)
                ->where('status', 1)
                ->column('name');
        } catch (\Throwable $e) {
            //表不存在
        }
        $names = array_values($names);

        //必须用 Cache::tag() 存，clearCache() 才能按 tag 清掉
        Cache::tag(self::CACHE_TAG)->set(self::CACHE_KEY_ENABLED, $names, 86400);
        return $names;
    }

    /**
     * 清插件相关缓存（同时把已加载的钩子清空，下一次触发会重新加载）
     * @return void
     */
    public function clearCache(): void
    {
        try {
            Cache::tag(self::CACHE_TAG)->clear();
        } catch (\Throwable $e) {
        }
        Hook::instance()->clear();
    }

    /**
     * 清后台的「菜单缓存」和「鉴权缓存」
     *
     * 插件菜单节点一变（加菜单 / 删菜单 / 启用禁用切换菜单显示），这两份缓存就过期了：
     *   adminMenu  —— 左侧菜单树，由 app\admin\controller\Index::index() 写入
     *   adminRules —— 每个用户有权限的 src 列表，由 app\admin\middleware\Auth 写入
     * 不清的话左侧菜单和权限判断还是旧的，得等缓存过期才更新。
     * @return void
     */
    public function clearMenuCache(): void
    {
        try {
            Cache::tag('adminMenu')->clear();
            Cache::tag('adminRules')->clear();
        } catch (\Throwable $e) {
        }
    }

    /** 执行已校验的插件版本迁移 SQL；迁移文件只能操作当前插件自己的表。 */
    public function applyUpdateMigrations(string $name, array $relativeFiles): void
    {
        if (!$this->isValidName($name) || !$relativeFiles) {
            return;
        }

        $statements = [];
        foreach ($relativeFiles as $relativeFile) {
            if (!is_string($relativeFile) || !preg_match('#\Amigrations/[A-Za-z0-9.+_-]+_to_[A-Za-z0-9.+_-]+\.sql\z#D', $relativeFile)) {
                throw new \RuntimeException('插件数据库迁移文件路径无效');
            }
            $sqlFile = $this->pluginPath . $name . DIRECTORY_SEPARATOR . str_replace('/', DIRECTORY_SEPARATOR, $relativeFile);
            $statements = array_merge($statements, $this->readPluginSqlFile($sqlFile, $name));
        }

        Db::startTrans();
        try {
            foreach ($statements as $statement) {
                Db::execute($statement);
            }
            Db::commit();
        } catch (\Throwable $e) {
            Db::rollback();
            throw $e;
        }
    }

    // ==================== 安装 / 卸载 / 启用 / 禁用 ====================

    /** 返回插件的 CMS 版本不兼容原因；未声明最低版本时视为兼容。 */
    public function getCmsCompatibilityError(array $plugin): ?string
    {
        $requiredVersion = trim((string) ($plugin['min_cms_version'] ?? ''));
        if ($requiredVersion === '') {
            return null;
        }

        $versionPattern = '/\A\d+(?:\.\d+)*(?:[-+][0-9A-Za-z.-]+)?\z/';
        if (!preg_match($versionPattern, $requiredVersion)) {
            return '插件声明的最低 CMS 版本格式无效，无法安装';
        }

        $currentVersion = defined('CMS_VERSION') ? (string) CMS_VERSION : '';
        if (!preg_match($versionPattern, $currentVersion)) {
            return '当前 MiuCMS 版本号无效，无法判断插件兼容性';
        }

        if (version_compare($currentVersion, $requiredVersion, '<')) {
            return "当前 MiuCMS 版本（{$currentVersion}）低于插件要求的最低版本（{$requiredVersion}），请升级后再安装。";
        }

        return null;
    }

    /**
     * 安装插件
     * @param string $name
     * @return array ['code'=>0成功/1失败, 'msg'=>消息]
     */
    public function install(string $name): array
    {
        if (!$this->isValidName($name) || !preg_match('/^[A-Za-z]/', $name)) {
            return ['code' => 1, 'msg' => '插件标识不合法'];
        }

        $local = $this->getPluginInfoFromFile($name);
        if (empty($local)) {
            return ['code' => 1, 'msg' => '插件文件不存在或 Plugin.php 无法加载'];
        }

        $exist = $this->getPlugin($name);
        if ($exist && $exist['installed']) {
            return ['code' => 1, 'msg' => '插件已安装'];
        }

        $compatibilityError = $this->getCmsCompatibilityError($local);
        if ($compatibilityError !== null) {
            return ['code' => 1, 'msg' => $compatibilityError];
        }

        $this->ensureTable();

        try {
            $sqlStatements = [];
            $sqlFile = $this->pluginPath . $name . DIRECTORY_SEPARATOR . 'install.sql';
            if (is_file($sqlFile)) {
                $sqlStatements = $this->readPluginSqlFile($sqlFile, $name);
            }

            Db::startTrans();

            //1) 执行已校验的 install.sql
            foreach ($sqlStatements as $statement) {
                Db::execute($statement);
            }

            //2) 调用插件 install()
            $plugin = $this->makePlugin($name);
            if ($plugin) {
                $ret = $plugin->install();
                if (is_string($ret)) {
                    Db::rollback();
                    return ['code' => 1, 'msg' => $ret];
                }
            }

            //3) 写库
            $data = [
                'name'         => $name,
                'title'        => $local['title'] ?: $name,
                'version'      => $local['version'] ?: '1.0.0',
                'author'       => $local['author'] ?? '',
                'description'  => $local['description'] ?? '',
                'config'       => json_encode($this->defaultConfig($local), JSON_UNESCAPED_UNICODE),
                'rule_ids'     => implode(',', Db::name('admin_rule')->where('module', 'plugin')->where('crud', $name)->column('id')),
                'installed'    => 1,
                'status'       => 1,
                'install_time' => time(),
                'update_time'  => time(),
            ];
            $old = Db::name('plugin')->where('name', $name)->find();
            if ($old) {
                Db::name('plugin')->where('name', $name)->update($data);
            } else {
                Db::name('plugin')->insert($data);
            }

            // 未由安装回调注册菜单的插件，仍可在列表中手动添加后台菜单。

            Db::commit();

            $this->clearCache();
            Hook::instance()->loadPluginHooks($name);

            return ['code' => 0, 'msg' => '安装成功'];
        } catch (\Throwable $e) {
            Db::rollback();
            return ['code' => 1, 'msg' => '安装失败：' . $e->getMessage()];
        }
    }

    /**
     * 卸载插件
     * @param string $name
     * @param bool   $force 为 true 时不执行 uninstall.sql（保留数据表）
     * @return array
     */
    public function uninstall(string $name, bool $force = false): array
    {
        if (!$this->isValidName($name) || !preg_match('/^[A-Za-z]/', $name)) {
            return ['code' => 1, 'msg' => '插件标识不合法'];
        }

        $plugin = $this->getPlugin($name);

        //插件目录已经没了（列表里显示「文件丢失」）：getPlugin() 返回 null，
        //拿不到插件实例、也没法执行 uninstall.sql，只能把残留的记录和菜单节点清掉
        if (!$plugin) {
            return $this->removeRecord($name);
        }

        if (!$plugin['installed']) {
            return ['code' => 1, 'msg' => '插件未安装'];
        }

        try {
            $sqlStatements = [];
            $sqlFile = $this->pluginPath . $name . DIRECTORY_SEPARATOR . 'uninstall.sql';
            if (!$force && is_file($sqlFile)) {
                $sqlStatements = $this->readPluginSqlFile($sqlFile, $name);
            }

            Db::startTrans();

            //1) 调用插件 uninstall()
            $instance = $this->makePlugin($name);
            if ($instance) {
                $ret = $instance->uninstall();
                if (is_string($ret)) {
                    Db::rollback();
                    return ['code' => 1, 'msg' => $ret];
                }
            }

            //2) 执行 uninstall.sql（强制卸载时跳过，保留数据）
            if (!$force) {
                $sqlFile = $this->pluginPath . $name . DIRECTORY_SEPARATOR . 'uninstall.sql';
                if (is_file($sqlFile)) {
                    foreach ($sqlStatements as $statement) {
                        Db::execute($statement);
                    }
                }
            }

            //3) 删除后台菜单
            $this->removeMenus($name);

            //4) 删除记录
            Db::name('plugin')->where('name', $name)->delete();

            Db::commit();

            $this->clearCache();
            Hook::instance()->removePluginHooks($name);

            return ['code' => 0, 'msg' => '卸载成功'];
        } catch (\Throwable $e) {
            Db::rollback();
            return ['code' => 1, 'msg' => '卸载失败：' . $e->getMessage()];
        }
    }

    /**
     * 清除「文件已丢失」插件的残留记录
     *
     * 插件目录被删掉后 uninstall() 走不通（getPlugin() 拿不到插件信息，因为它是读文件的），
     * 但 cms_plugin 的记录和它注册的后台菜单节点还留着，列表里会一直显示「文件丢失」。
     * 这里只清这些残留，不碰插件文件（文件本来就不在了）。
     *
     * @param string $name
     * @return array ['code'=>0成功/1失败, 'msg'=>消息]
     */
    public function removeRecord(string $name): array
    {
        if (!$this->isValidName($name) || !preg_match('/^[A-Za-z]/', $name)) {
            return ['code' => 1, 'msg' => '插件标识不合法'];
        }

        $row = Db::name('plugin')->where('name', $name)->find();
        if (!$row) {
            return ['code' => 1, 'msg' => '没有找到该插件的记录'];
        }

        //文件还在的话说明不是「文件丢失」，别让人从这里绕过正常的卸载流程
        if (is_dir($this->pluginPath . $name)) {
            return ['code' => 1, 'msg' => '插件文件还在，请用「卸载」'];
        }

        try {
            Db::startTrans();

            //1) 清掉它注册的后台菜单节点（连同各角色权限里的引用）
            $this->removeMenus($name);

            //2) 删掉 cms_plugin 的记录
            Db::name('plugin')->where('name', $name)->delete();

            Db::commit();

            $this->clearCache();
            Hook::instance()->removePluginHooks($name);

            return ['code' => 0, 'msg' => '记录已清除'];
        } catch (\Throwable $e) {
            Db::rollback();
            return ['code' => 1, 'msg' => '清除失败：' . $e->getMessage()];
        }
    }

    /**
     * 启用插件
     * @param string $name
     * @return array
     */
    public function enable(string $name): array
    {
        $plugin = $this->getPlugin($name);
        if (!$plugin || !$plugin['installed']) {
            return ['code' => 1, 'msg' => '插件未安装'];
        }
        if ($plugin['status']) {
            return ['code' => 1, 'msg' => '插件已启用'];
        }

        try {
            $instance = $this->makePlugin($name);
            if ($instance) {
                $ret = $instance->enable();
                if (is_string($ret)) {
                    return ['code' => 1, 'msg' => $ret];
                }
            }
            Db::name('plugin')->where('name', $name)->update(['status' => 1, 'update_time' => time()]);
            $this->setMenusStatus($name, 1);

            $this->clearCache();
            Hook::instance()->loadPluginHooks($name);
            return ['code' => 0, 'msg' => '启用成功'];
        } catch (\Throwable $e) {
            return ['code' => 1, 'msg' => '启用失败：' . $e->getMessage()];
        }
    }

    /**
     * 禁用插件
     * @param string $name
     * @return array
     */
    public function disable(string $name): array
    {
        $plugin = $this->getPlugin($name);
        if (!$plugin || !$plugin['installed']) {
            return ['code' => 1, 'msg' => '插件未安装'];
        }
        if (!$plugin['status']) {
            return ['code' => 1, 'msg' => '插件已禁用'];
        }

        try {
            $instance = $this->makePlugin($name);
            if ($instance) {
                $ret = $instance->disable();
                if (is_string($ret)) {
                    return ['code' => 1, 'msg' => $ret];
                }
            }
            Db::name('plugin')->where('name', $name)->update(['status' => 0, 'update_time' => time()]);
            $this->setMenusStatus($name, 0);

            $this->clearCache();
            Hook::instance()->removePluginHooks($name);
            return ['code' => 0, 'msg' => '禁用成功'];
        } catch (\Throwable $e) {
            return ['code' => 1, 'msg' => '禁用失败：' . $e->getMessage()];
        }
    }

    // ==================== 配置 ====================

    /**
     * 保存插件配置
     * @param string $name
     * @param array  $config
     * @return array
     */
    public function saveConfig(string $name, array $config): array
    {
        $plugin = $this->getPlugin($name);
        if (!$plugin || !$plugin['installed']) {
            return ['code' => 1, 'msg' => '插件未安装'];
        }
        try {
            Db::name('plugin')->where('name', $name)->update([
                'config'      => json_encode($config, JSON_UNESCAPED_UNICODE),
                'update_time' => time(),
            ]);
            $this->clearCache();
            return ['code' => 0, 'msg' => '保存成功'];
        } catch (\Throwable $e) {
            return ['code' => 1, 'msg' => '保存失败：' . $e->getMessage()];
        }
    }

    /**
     * 读取插件配置（未保存过时回退到配置字段的默认值）
     * @param string $name
     * @return array
     */
    public function getPluginConfig(string $name): array
    {
        $this->assertPluginRowReadAccess($name);
        $cacheKey = 'plugin_config_' . $name;
        $cached = Cache::get($cacheKey);
        if (is_array($cached)) {
            return $cached;
        }

        try {
            $row = Db::name('plugin')->where('name', $name)->find();
            if ($row) {
                $config = $this->decodeConfig($row['config']);
                Cache::tag(self::CACHE_TAG)->set($cacheKey, $config, 86400);
                return $config;
            }
        } catch (\Throwable $e) {
        }
        //没装/没保存过：用文件里定义的默认值
        $local = $this->getPluginInfoFromFile($name);
        $config = $this->defaultConfig($local);
        Cache::tag(self::CACHE_TAG)->set($cacheKey, $config, 86400);
        return $config;
    }

    // ==================== zip 安装 ====================

    /**
     * 判断是不是真正的 zip 文件（只看文件头 "PK"）
     *
     * 光看扩展名挡不住「把别的文件改个 .zip 后缀」这种，所以再验一下文件头：
     * zip 的签名是 PK\x03\x04（空压缩包是 PK\x05\x06），前两字节都是 "PK"。
     *
     * @param string $path
     * @return bool
     */
    public function isZipFile(string $path): bool
    {
        if (!is_file($path)) {
            return false;
        }
        //读不出来（权限/文件异常）也当作不是 zip
        return @file_get_contents($path, false, null, 0, 2) === 'PK';
    }

    /**
     * 从 zip 包安装插件
     *
     * zip 里应当是「一个插件目录」，例如 demo/Plugin.php；也兼容 zip 根目录直接就是插件文件。
     *
     * @param string $zipFile 上传的临时文件路径
     * @param bool   $autoInstall 解压后是否直接安装
     * @return array
     */
    public function installFromZip(string $zipFile, bool $autoInstall = true): array
    {
        if (!class_exists('\ZipArchive')) {
            return ['code' => 1, 'msg' => '当前 PHP 未安装 zip 扩展，无法解压'];
        }
        if (!is_file($zipFile)) {
            return ['code' => 1, 'msg' => '上传文件不存在'];
        }

        $zip = new \ZipArchive();
        if ($zip->open($zipFile) !== true) {
            return ['code' => 1, 'msg' => '无法打开 zip 包'];
        }

        //防 zip 炸弹：先看中央目录里记的「未压缩大小」和文件数，超限直接拒绝。
        //不拦的话，一个 20M 的全零 zip 能解出几十 GB 把磁盘写满。
        if ($zip->numFiles > self::MAX_UNZIP_FILES) {
            $zip->close();
            return ['code' => 1, 'msg' => '插件包里的文件数超过上限（最多 ' . self::MAX_UNZIP_FILES . ' 个）'];
        }
        $declaredSize = 0;
        for ($i = 0; $i < $zip->numFiles; $i++) {
            $stat = $zip->statIndex($i);
            $declaredSize += (int) ($stat['size'] ?? 0);
        }
        if ($declaredSize > self::MAX_UNZIP_BYTES) {
            $zip->close();
            return ['code' => 1, 'msg' => '插件包解压后约 ' . format_bytes($declaredSize) . '，超过上限 ' . format_bytes(self::MAX_UNZIP_BYTES)];
        }

        //先拒绝路径穿越、Windows 绝对路径及符号链接条目，避免压缩包写出临时目录。
        for ($i = 0; $i < $zip->numFiles; $i++) {
            $stat = $zip->statIndex($i);
            $entry = (string) ($stat['name'] ?? '');
            $normalizedEntry = str_replace('\\', '/', $entry);
            $segments = explode('/', rtrim($normalizedEntry, '/'));
            if (
                $entry === ''
                || str_contains($entry, "\0")
                || str_starts_with($normalizedEntry, '/')
                || preg_match('/^[A-Za-z]:/', $normalizedEntry)
                || preg_match('/[\x00-\x1F]/', $normalizedEntry)
                || in_array('..', $segments, true)
                || in_array('.', $segments, true)
                || str_contains($normalizedEntry, ':')
            ) {
                $zip->close();
                return ['code' => 1, 'msg' => '插件包包含不安全的文件路径'];
            }

            if (method_exists($zip, 'getExternalAttributesIndex')) {
                $opsys = 0;
                $attributes = 0;
                if ($zip->getExternalAttributesIndex($i, $opsys, $attributes)) {
                    $fileType = ($attributes >> 16) & 0170000;
                    if ($fileType === 0120000) {
                        $zip->close();
                        return ['code' => 1, 'msg' => '插件包不允许包含符号链接'];
                    }
                }
            }
        }

        //解压到临时目录
        try {
            $tmp = runtime_path() . 'plugin_upload_' . bin2hex(random_bytes(16)) . DIRECTORY_SEPARATOR;
        } catch (\Throwable $e) {
            $zip->close();
            return ['code' => 1, 'msg' => '无法创建安全的临时目录'];
        }
        if (!@mkdir($tmp, 0700, true)) {
            $zip->close();
            return ['code' => 1, 'msg' => '创建临时目录失败'];
        }
        if (!$zip->extractTo($tmp)) {
            $zip->close();
            $this->removeDir($tmp);
            return ['code' => 1, 'msg' => '解压插件包失败'];
        }
        $zip->close();

        //中央目录里记的大小是可以伪造的，解压完再按实际写出的体积核一遍
        $realSize = $this->dirSize($tmp);
        if ($realSize > self::MAX_UNZIP_BYTES) {
            $this->removeDir($tmp);
            return ['code' => 1, 'msg' => '插件包解压后实际 ' . format_bytes($realSize) . '，超过上限，已清理'];
        }

        //找到包含 Plugin.php 的目录
        $srcDir = null;
        foreach (scandir($tmp) as $item) {
            if ($item === '.' || $item === '..') {
                continue;
            }
            $path = $tmp . $item;
            if (is_dir($path) && is_file($path . DIRECTORY_SEPARATOR . 'Plugin.php')) {
                $srcDir = $path;
                $name   = $item;
                break;
            }
            if (is_file($path) && $item === 'Plugin.php') {
                //zip 根目录直接是插件文件
                $srcDir = $tmp;
                $name   = basename($tmp);
                break;
            }
        }

        if ($srcDir === null) {
            $this->removeDir($tmp);
            return ['code' => 1, 'msg' => 'zip 包结构不正确：找不到包含 Plugin.php 的插件目录'];
        }
        $nameError = $this->checkName($name);
        if ($nameError !== '') {
            $this->removeDir($tmp);
            return ['code' => 1, 'msg' => $nameError];
        }

        $target = $this->pluginPath . $name;
        if (is_dir($target)) {
            $this->removeDir($tmp);
            return ['code' => 1, 'msg' => "插件目录 {$name} 已存在，请先删除后再安装"];
        }

        //移动到位
        if (!rename($srcDir, $target)) {
            $this->removeDir($tmp);
            return ['code' => 1, 'msg' => '移动插件目录失败，请检查 plugins 目录权限'];
        }
        $this->removeDir($tmp);

        if (!$autoInstall) {
            return ['code' => 0, 'msg' => "插件 {$name} 已上传，请点击安装"];
        }

        return $this->install($name);
    }

    // ==================== 后台菜单 ====================

    /**
     * 读取插件的后台菜单定义（admin/menu.php）
     * @param string $name
     * @return array
     */
    public function getAdminMenus(string $name): array
    {
        if (!$this->isValidName($name)) {
            return [];
        }
        $file = $this->pluginPath . $name . DIRECTORY_SEPARATOR . 'admin' . DIRECTORY_SEPARATOR . 'menu.php';
        if (!is_file($file)) {
            return [];
        }
        $menus = include $file;
        return is_array($menus) ? $menus : [];
    }

    /**
     * 插件声明的后台菜单图标（插件类里的 $icon；没写或格式不对时回退 layui-icon-star）
     * @param string $name
     * @return string
     */
    public function getPluginIcon(string $name): string
    {
        try {
            $plugin = $this->makePlugin($name);
            if ($plugin) {
                return $plugin->getIcon();
            }
        } catch (\Throwable $e) {
            //读不到就用默认图标
        }
        return 'layui-icon-star';
    }

    /**
     * 把插件的后台菜单写入 cms_admin_rule，并授权给超级管理员组
     * @param string $name
     * @return array 新增的节点ID
     */
    public function registerMenus(string $name): array
    {
        $menus = $this->getAdminMenus($name);
        if (empty($menus)) {
            return [];
        }

        $ids = [];
        $this->insertMenuTree($menus, 0, $name, $ids, $this->getPluginIcon($name));

        //授权给超级管理员组（id=1）
        if ($ids) {
            $this->grantToGroup(1, $ids);
        }
        //菜单节点变了，后台的菜单/鉴权缓存要清
        $this->clearMenuCache();
        return $ids;
    }

    /**
     * 插件是否已经加过后台菜单
     * @param string $name
     * @return bool
     */
    public function hasMenus(string $name): bool
    {
        try {
            return Db::name('admin_rule')
                ->where('module', 'plugin')
                ->where('crud', $name)
                ->count() > 0;
        } catch (\Throwable $e) {
            return false;
        }
    }

    /**
     * 已加过后台菜单的插件标识（列表页一次性查出来，避免每个插件查一次库）
     * @return array<int, string>
     */
    public function getMenuPluginNames(): array
    {
        try {
            return array_values(array_unique(
                Db::name('admin_rule')->where('module', 'plugin')->column('crud')
            ));
        } catch (\Throwable $e) {
            return [];
        }
    }

    /**
     * 当前登录管理员有没有该插件后台的访问权限
     *
     * 背景：插件后台页面统一走 admin/plugin/run?name=xxx&c=xxx&a=xxx 分发，
     * 而鉴权中间件是拿「控制器/方法」比的（不含查询串），插件菜单节点的 src
     * 带查询串永远匹配不上，于是只靠 admin/plugin/run 这一个节点兜底——
     * 结果拿到这个节点的角色能访问任意插件的后台。这里按插件粒度补一层校验：
     * 插件菜单节点（module=plugin, crud=插件名）至少有一个在当前管理员的角色里才放行。
     *
     * 超级管理员（id=1）直接放行；插件还没加过后台菜单（没有节点）时不做限制，
     * 由 admin/plugin/run 节点本身兜底。
     * @param string $name
     * @return bool
     */
    public function adminHasPluginMenu(string $name): bool
    {
        //读当前登录管理员ID。这里不依赖 admin 应用的 get_login_admin()，
        //因为本类也可能在非 admin 上下文被调用；直接用 Session 门面（逻辑同 get_login_admin）。
        $uid = 0;
        try {
            $sessionKey = (string) config('app.session_admin');
            if ($sessionKey !== '' && Session::has($sessionKey)) {
                $admin = Session::get($sessionKey);
                $uid   = (int) (is_array($admin) ? ($admin['id'] ?? 0) : 0);
            }
        } catch (\Throwable $e) {
            $uid = 0;
        }
        //超级管理员不受限
        if ($uid === 1) {
            return true;
        }
        if ($uid <= 0 || !$this->isValidName($name)) {
            return false;
        }

        //插件自己的后台菜单节点
        try {
            $nodeIds = Db::name('admin_rule')
                ->where('module', 'plugin')
                ->where('crud', $name)
                ->column('id');
        } catch (\Throwable $e) {
            return false;
        }
        //插件没有声明后台菜单 → 没有可细分的权限，交给 admin/plugin/run 节点兜底
        if (empty($nodeIds)) {
            return true;
        }
        $nodeIds = array_map('intval', $nodeIds);

        //当前管理员所属（启用状态的）角色拥有的节点ID
        try {
            $groupIds = Db::name('AdminGroupAccess')->where('uid', $uid)->column('group_id');
            if (empty($groupIds)) {
                return false;
            }
            $rules = Db::name('AdminGroup')
                ->whereIn('id', $groupIds)
                ->where('status', 1)
                ->column('rules');
        } catch (\Throwable $e) {
            return false;
        }

        $ruleIds = [];
        foreach ($rules as $rule) {
            foreach (explode(',', trim((string) $rule, ',')) as $rid) {
                if ($rid !== '') {
                    $ruleIds[] = (int) $rid;
                }
            }
        }
        return !empty(array_intersect($nodeIds, $ruleIds));
    }

    /**
     * 给插件添加后台菜单（插件列表页的「添加后台菜单」按钮调这个）
     *
     * 安装插件时不再自动加菜单，改成装完由用户手动加；重复调用会提示已加过。
     * @param string $name
     * @return array ['code'=>0成功/1失败, 'msg'=>消息]
     */
    public function addMenus(string $name): array
    {
        $plugin = $this->getPlugin($name);
        if (!$plugin || empty($plugin['installed'])) {
            return ['code' => 1, 'msg' => '插件未安装'];
        }

        $menus = $this->getAdminMenus($name);
        $hasSubMenus = false;
        foreach ($menus as $menu) {
            if (!is_array($menu) || trim((string) ($menu['title'] ?? '')) === '') {
                continue;
            }
            $children = $menu['children'] ?? [];
            if (!is_array($children)) {
                continue;
            }
            foreach ($children as $child) {
                if (is_array($child) && trim((string) ($child['title'] ?? '')) !== '') {
                    $hasSubMenus = true;
                    break 2;
                }
            }
        }
        if (!$hasSubMenus) {
            return ['code' => 1, 'msg' => '该插件没有配置菜单'];
        }

        if ($this->hasMenus($name)) {
            return ['code' => 1, 'msg' => '该插件已经加过后台菜单了'];
        }

        try {
            $ids = $this->registerMenus($name);
            if (empty($ids)) {
                return ['code' => 1, 'msg' => '没有可添加的菜单节点'];
            }
            Db::name('plugin')->where('name', $name)->update([
                'rule_ids'    => implode(',', $ids),
                'update_time' => time(),
            ]);
            $this->clearCache();
            return ['code' => 0, 'msg' => '后台菜单已添加，刷新页面就能看到'];
        } catch (\Throwable $e) {
            return ['code' => 1, 'msg' => '添加失败：' . $e->getMessage()];
        }
    }

    /**
     * 删除插件的后台菜单（列表页的「删除后台菜单」按钮调这个）
     *
     * 只删菜单节点（连同各角色权限里的引用），不影响插件本身和它的数据。
     * @param string $name
     * @return array ['code'=>0成功/1失败, 'msg'=>消息]
     */
    public function deleteMenus(string $name): array
    {
        $plugin = $this->getPlugin($name);
        if (!$plugin || empty($plugin['installed'])) {
            return ['code' => 1, 'msg' => '插件未安装'];
        }

        if (!$this->hasMenus($name)) {
            return ['code' => 1, 'msg' => '该插件还没有加过后台菜单'];
        }

        try {
            $this->removeMenus($name);
            Db::name('plugin')->where('name', $name)->update([
                'rule_ids'    => '',
                'update_time' => time(),
            ]);
            $this->clearCache();
            return ['code' => 0, 'msg' => '后台菜单已删除'];
        } catch (\Throwable $e) {
            return ['code' => 1, 'msg' => '删除失败：' . $e->getMessage()];
        }
    }

    /**
     * 递归写入菜单节点
     *
     * 排序固定写 100：插件菜单统一排在系统菜单后面，插件之间再按 id 排。
     * 图标用插件类里声明的 $icon（见 getPluginIcon()）。
     * @param array  $menus
     * @param int    $pid
     * @param string $plugin
     * @param array  $ids  出参：写入的节点ID
     * @param string $icon 菜单图标
     * @return void
     */
    protected function insertMenuTree(array $menus, int $pid, string $plugin, array &$ids, string $icon): void
    {
        foreach ($menus as $item) {
            if (empty($item['title'])) {
                continue;
            }
            $id = Db::name('admin_rule')->insertGetId([
                'pid'         => $pid,
                'src'         => $item['url'] ?? '',
                'title'       => $item['title'],
                'name'        => $item['title'],
                'icon'        => $icon,
                'menu'        => 1,
                'sort'        => 100,
                'status'      => 1,
                'module'      => 'plugin',
                'crud'        => $plugin,
                'create_time' => time(),
                'update_time' => 0,
            ]);
            $ids[] = $id;

            if (!empty($item['children']) && is_array($item['children'])) {
                $this->insertMenuTree($item['children'], $id, $plugin, $ids, $icon);
            }
        }
    }

    /**
     * 删除插件的后台菜单，并从各组权限里摘掉
     * @param string $name
     * @return void
     */
    public function removeMenus(string $name): void
    {
        $ids = Db::name('admin_rule')
            ->where('module', 'plugin')
            ->where('crud', $name)
            ->column('id');
        if (empty($ids)) {
            return;
        }
        Db::name('admin_rule')->whereIn('id', $ids)->delete();

        //从所有管理员组的 rules 里移除
        $groups = Db::name('admin_group')->select()->toArray();
        foreach ($groups as $group) {
            $rules = array_filter(explode(',', (string) $group['rules']));
            $new   = array_diff($rules, $ids);
            if (count($new) !== count($rules)) {
                Db::name('admin_group')->where('id', $group['id'])->update([
                    'rules'       => implode(',', $new),
                    'update_time' => time(),
                ]);
            }
        }

        //菜单节点删了、组权限也变了，后台的菜单/鉴权缓存要清
        $this->clearMenuCache();
    }

    /**
     * 切换插件菜单的显示状态（禁用插件时把菜单藏起来）
     * @param string $name
     * @param int    $status
     * @return void
     */
    public function setMenusStatus(string $name, int $status): void
    {
        Db::name('admin_rule')
            ->where('module', 'plugin')
            ->where('crud', $name)
            ->update(['status' => $status, 'update_time' => time()]);

        //菜单显示状态变了，后台的菜单/鉴权缓存要清
        $this->clearMenuCache();
    }

    /**
     * 把节点ID追加到指定管理员组的权限里
     * @param int   $groupId
     * @param array $ids
     * @return void
     */
    protected function grantToGroup(int $groupId, array $ids): void
    {
        $group = Db::name('admin_group')->where('id', $groupId)->find();
        if (!$group) {
            return;
        }
        $rules = array_filter(explode(',', (string) $group['rules']));
        $rules = array_values(array_unique(array_merge($rules, $ids)));
        Db::name('admin_group')->where('id', $groupId)->update([
            'rules'       => implode(',', $rules),
            'update_time' => time(),
        ]);
    }

    // ==================== 路由 / 标签 ====================

    /**
     * 读取某插件某端的路由定义
     * @param string $name
     * @param string $area 'admin' 或 ''(前台)
     * @return array ['规则' => '类@方法']
     */
    public function getRoutes(string $name, string $area = ''): array
    {
        if (!$this->isValidName($name)) {
            return [];
        }
        $file = $area === 'admin'
            ? $this->pluginPath . $name . DIRECTORY_SEPARATOR . 'admin' . DIRECTORY_SEPARATOR . 'route.php'
            : $this->pluginPath . $name . DIRECTORY_SEPARATOR . 'route.php';
        if (!is_file($file)) {
            return [];
        }
        $routes = include $file;
        return is_array($routes) ? $routes : [];
    }

    /**
     * 某插件声明的模板标签类（taglib/*.php 里的类）
     * @param string $name
     * @return array<int, string> 完整类名
     */
    public function getTaglibs(string $name): array
    {
        if (!$this->isValidName($name)) {
            return [];
        }
        $dir = $this->pluginPath . $name . DIRECTORY_SEPARATOR . 'taglib';
        if (!is_dir($dir)) {
            return [];
        }
        $classes = [];
        foreach (glob($dir . DIRECTORY_SEPARATOR . '*.php') as $file) {
            $base = basename($file, '.php');
            $class = "plugins\\{$name}\\taglib\\{$base}";
            if (class_exists($class)) {
                $classes[] = $class;
            }
        }
        return $classes;
    }

    /**
     * 渲染插件自己的视图
     * @param string $name     插件标识
     * @param string $template 模板名（不含后缀），如 index
     * @param array  $vars     模板变量
     * @param string $area     'admin' 用 admin/view，其它用 view
     * @return string
     */
    public function renderView(string $name, string $template, array $vars = [], string $area = ''): string
    {
        $sub = $area === 'admin' ? 'admin' . DIRECTORY_SEPARATOR . 'view' : 'view';
        View::config([
            'view_path' => $this->pluginPath . $name . DIRECTORY_SEPARATOR . $sub . DIRECTORY_SEPARATOR,
        ]);

        //模板名里的 .. 直接去掉：防止跳出 view_path 去读别的 .html
        //（模板名正常来自插件代码、不是外部输入，这里只是多一道保险）
        $template = str_replace('..', '', (string) $template);

        //模板名必须显式以 / 开头，表示「相对插件自己的 view_path」。
        //否则框架会按「当前控制器」自动加前缀（think\view\driver\Think::parseTemplate）：
        //后台插件页地址是 admin/plugin/run，控制器是 plugin，
        //传 'index' 会被拼成 plugin/index.html，报「模板文件不存在 .../admin/view/plugin/index.html」
        return View::fetch('/' . ltrim($template, '/'), $vars);
    }

    // ==================== 工具方法 ====================

    /**
     * 插件代码通过管理器读取插件记录时，只能指定自己的插件标识。
     * 核心后台调用没有插件调用栈，因此仍可按管理功能读取任意插件记录。
     */
    private function assertPluginRowReadAccess(string $name): void
    {
        $callerPlugin = $this->pluginCallerName();
        if ($callerPlugin !== '' && strcasecmp(trim($name), $callerPlugin) !== 0) {
            throw new \RuntimeException('插件只能读取自身在 cms_plugin 中的配置记录');
        }
    }

    /** 从调用栈识别当前插件，兼容插件主类、控制器、钩子和模板回调。 */
    private function pluginCallerName(): string
    {
        $pluginRoot = realpath($this->pluginPath);
        $pluginRoot = $pluginRoot === false
            ? ''
            : rtrim(str_replace('\\', '/', strtolower($pluginRoot)), '/') . '/';

        foreach (debug_backtrace(DEBUG_BACKTRACE_IGNORE_ARGS, 32) as $frame) {
            $class = strtolower((string) ($frame['class'] ?? ''));
            if (str_starts_with($class, 'plugins\\')) {
                $parts = explode('\\', $class);
                if (!empty($parts[1]) && $this->isValidName($parts[1])) {
                    return $parts[1];
                }
            }

            if ($pluginRoot === '' || empty($frame['file'])) {
                continue;
            }
            $file = realpath((string) $frame['file']);
            if ($file === false) {
                continue;
            }
            $file = strtolower(str_replace('\\', '/', $file));
            if (!str_starts_with($file, $pluginRoot)) {
                continue;
            }
            $relative = substr($file, strlen($pluginRoot));
            $name = strtok($relative, '/');
            if (is_string($name) && $this->isValidName($name)) {
                return $name;
            }
        }

        return '';
    }

    /**
     * 插件标识是否合法
     * @param string $name
     * @return bool
     */
    public function isValidName(string $name): bool
    {
        return $name !== '' && preg_match(self::NAME_PATTERN, $name) === 1;
    }

    /**
     * 是不是 PHP 关键字（当命名空间段/类名会直接语法报错的那种）
     * @param string $name
     * @return bool
     */
    public function isPhpKeyword(string $name): bool
    {
        return in_array(strtolower(trim($name)), self::PHP_KEYWORDS, true);
    }

    /**
     * 是不是保留词（PHP 关键字 + 框架目录 + 插件系统关键词 + 应用名）
     *
     * 忽略大小写（PHP 类名和 Windows 目录名都不区分大小写）。
     * 应用名是实时读 app/ 目录的，以后加了新应用这里自动跟着拦。
     *
     * @param string $name
     * @return bool
     */
    public function isReservedName(string $name): bool
    {
        $key = strtolower(trim($name));
        if ($key === '') {
            return true;
        }
        if (in_array($key, self::PHP_KEYWORDS, true) || in_array($key, self::RESERVED_NAMES, true)) {
            return true;
        }
        foreach (scandir(app_path()) ?: [] as $dir) {
            if ($dir !== '.' && $dir !== '..' && is_dir(app_path() . $dir) && strtolower($dir) === $key) {
                return true;
            }
        }
        return false;
    }

    /**
     * 创建插件前校验标识：格式 + 保留词 + 是否已被占用
     *
     * 插件标识会同时当目录名（plugins/<标识>/）和 PHP 命名空间段（plugins\<标识>\）用，
     * 所以除了字符格式，凡是可能跟系统撞上的名字都不允许用。
     *
     * @param string $name
     * @return string '' 表示通过；否则返回不能用的原因（可直接提示给用户）
     */
    public function checkName(string $name): string
    {
        $name = trim($name);
        if ($name === '') {
            return '请填写插件标识';
        }
        if (!$this->isValidName($name) || !preg_match('/^[A-Za-z]/', $name)) {
            return '插件标识只能以字母开头，且只含字母、数字、下划线';
        }
        if ($this->isReservedName($name)) {
            return "「{$name}」是系统保留词，不能用（会和 PHP 关键字 / 框架目录 / 插件系统关键词 / 应用名撞）";
        }

        //已经存在的插件目录（忽略大小写：Windows 下 plugins/Abc 和 plugins/abc 是同一个目录）
        $lower = strtolower($name);
        foreach (array_keys($this->getLocalPlugins()) as $dir) {
            if (strtolower($dir) === $lower) {
                return "插件标识「{$name}」已被 plugins/{$dir} 占用，换一个";
            }
        }
        //插件表里已有记录（目录被删但记录还在的情况）
        if ($this->getPlugin($name)) {
            return "插件标识「{$name}」已存在（插件列表里有记录），换一个";
        }

        return '';
    }


    /**
     * 配置字段定义里的默认值
     * @param array $local getPluginInfoFromFile 的结果
     * @return array
     */
    protected function defaultConfig(array $local): array
    {
        $config = [];
        foreach (($local['config_fields'] ?? []) as $field) {
            if (!empty($field['name'])) {
                $config[$field['name']] = $field['default'] ?? '';
            }
        }
        return $config;
    }

    /**
     * 解析配置 JSON
     * @param mixed $raw
     * @return array
     */
    protected function decodeConfig(mixed $raw): array
    {
        if (is_array($raw)) {
            return $raw;
        }
        $arr = json_decode((string) $raw, true);
        return is_array($arr) ? $arr : [];
    }

    /**
     * 解析菜单节点ID串
     * @param string $raw
     * @return array<int, int>
     */
    protected function decodeRuleIds(string $raw): array
    {
        if ($raw === '') {
            return [];
        }
        return array_values(array_filter(array_map('intval', explode(',', $raw))));
    }

    /**
     * 读取并校验插件 SQL。SQL 文件只允许访问当前插件自己的数据表。
     * @param string $sqlFile
     * @param string $pluginName
     * @return array<int, string>
     */
    protected function readPluginSqlFile(string $sqlFile, string $pluginName): array
    {
        $pluginDir = realpath($this->pluginPath . $pluginName);
        $realFile = realpath($sqlFile);
        if (
            $pluginDir === false
            || $realFile === false
            || is_link($this->pluginPath . $pluginName)
            || is_link($sqlFile)
            || !str_starts_with(
                DIRECTORY_SEPARATOR === '\\' ? strtolower($realFile) : $realFile,
                rtrim(DIRECTORY_SEPARATOR === '\\' ? strtolower($pluginDir) : $pluginDir, '\\/') . DIRECTORY_SEPARATOR
            )
            || !is_file($realFile)
        ) {
            throw new \RuntimeException('插件 SQL 文件无效或不在插件目录内');
        }

        $sqlSize = filesize($realFile);
        if ($sqlSize === false || $sqlSize > self::MAX_PLUGIN_SQL_BYTES) {
            throw new \RuntimeException('插件 SQL 文件超过 2 MB 上限');
        }

        $sql = file_get_contents($realFile);
        if ($sql === false) {
            throw new \RuntimeException('无法读取插件 SQL 文件');
        }

        $dbPrefix = (string) config('database.connections.mysql.prefix', '');
        if (!preg_match('/^[A-Za-z0-9_$]*$/', $dbPrefix)) {
            throw new \RuntimeException('数据库表前缀格式不受支持');
        }
        $requiredPrefix = $dbPrefix . $pluginName . '_';
        $sql = str_replace('__PREFIX__', $dbPrefix, $sql);
        $statements = $this->parseSql($sql);
        if (count($statements) > self::MAX_PLUGIN_SQL_STATEMENTS) {
            throw new \RuntimeException('插件 SQL 语句数量超过 1000 条');
        }

        //整个文件先校验完，再执行任何一条语句，避免前面的 DDL 已落库后才发现越权语句。
        foreach ($statements as $statement) {
            $this->assertPluginSql($statement, $requiredPrefix);
        }
        return $statements;
    }

    /**
     * 在字符串、标识符及注释之外按分号切分 SQL。
     * @param string $sql
     * @return array<int, string>
     */
    protected function parseSql(string $sql): array
    {
        $statements = [];
        $buffer = '';
        $quote = '';
        $lineComment = false;
        $blockComment = false;
        $length = strlen($sql);

        for ($i = 0; $i < $length; $i++) {
            $char = $sql[$i];
            $next = $i + 1 < $length ? $sql[$i + 1] : '';

            if ($lineComment) {
                if ($char === "\n" || $char === "\r") {
                    $lineComment = false;
                    $buffer .= $char;
                }
                continue;
            }
            if ($blockComment) {
                if ($char === '*' && $next === '/') {
                    $blockComment = false;
                    $buffer .= ' ';
                    $i++;
                }
                continue;
            }
            if ($quote !== '') {
                if ($char === '\\') {
                    throw new \RuntimeException('插件 SQL 字符串和标识符不允许使用反斜杠转义');
                }
                $buffer .= $char;
                if ($char === $quote) {
                    if ($next === $quote) {
                        $buffer .= $next;
                        $i++;
                    } else {
                        $quote = '';
                    }
                }
                continue;
            }

            if ($char === '/' && $next === '*') {
                $commentType = $i + 2 < $length ? $sql[$i + 2] : '';
                if ($commentType === '!' || $commentType === '+') {
                    throw new \RuntimeException('插件 SQL 不允许使用 MySQL 可执行注释或优化器提示');
                }
                $blockComment = true;
                $i++;
                continue;
            }
            if ($char === '#' || ($char === '-' && $next === '-' && ($i + 2 >= $length || ord($sql[$i + 2]) <= 32))) {
                $lineComment = true;
                if ($char === '-') {
                    $i++;
                }
                continue;
            }
            if ($char === "'" || $char === '"' || $char === '`') {
                $quote = $char;
                $buffer .= $char;
                continue;
            }
            if ($char === ';') {
                $statement = trim($buffer);
                if ($statement !== '') {
                    $statements[] = $statement;
                }
                $buffer = '';
                continue;
            }
            $buffer .= $char;
        }

        if ($quote !== '' || $blockComment) {
            throw new \RuntimeException('插件 SQL 文件包含未闭合的字符串、标识符或注释');
        }
        $statement = trim($buffer);
        if ($statement !== '') {
            $statements[] = $statement;
        }
        return $statements;
    }

    /**
     * 只放行单表 DDL/DML，并检查语句中出现的每个表名都属于当前插件。
     */
    protected function assertPluginSql(string $statement, string $requiredPrefix): void
    {
        $tokens = $this->tokenizeSql($statement);
        if (!$tokens || $tokens[0]['type'] !== 'word') {
            throw new \RuntimeException('插件 SQL 语句格式不受支持');
        }

        $command = strtolower($tokens[0]['value']);
        $targetIndex = null;
        $hasSafeEngine = false;
        if (in_array($command, ['create', 'alter', 'drop'], true)) {
            if (!isset($tokens[1]) || $tokens[1]['type'] !== 'word' || strtolower($tokens[1]['value']) !== 'table') {
                throw new \RuntimeException('插件 SQL 只允许创建、修改或删除数据表');
            }
            $targetIndex = 2;
            if ($command === 'drop' && isset($tokens[$targetIndex]) && strtolower($tokens[$targetIndex]['value']) === 'if') {
                $targetIndex += 2; // IF EXISTS
            } elseif ($command === 'create' && isset($tokens[$targetIndex]) && strtolower($tokens[$targetIndex]['value']) === 'if') {
                $targetIndex += 3; // IF NOT EXISTS
            }
        } elseif ($command === 'truncate') {
            $targetIndex = isset($tokens[1]) && strtolower($tokens[1]['value']) === 'table' ? 2 : 1;
        } elseif (in_array($command, ['insert', 'replace'], true)) {
            $intoIndex = 1;
            if (isset($tokens[$intoIndex]) && in_array(strtolower($tokens[$intoIndex]['value']), ['ignore', 'low_priority', 'delayed'], true)) {
                $intoIndex++;
            }
            if (!isset($tokens[$intoIndex]) || strtolower($tokens[$intoIndex]['value']) !== 'into') {
                throw new \RuntimeException('插件 SQL 的 INSERT/REPLACE 语句格式不受支持');
            }
            $targetIndex = $intoIndex + 1;
        } elseif ($command === 'update') {
            $targetIndex = 1;
        } elseif ($command === 'delete') {
            if (isset($tokens[1]) && strtolower($tokens[1]['value']) === 'from') {
                $targetIndex = 2;
            }
        } else {
            throw new \RuntimeException('插件 SQL 不允许执行 ' . strtoupper($command) . ' 语句');
        }

        if ($targetIndex !== null) {
            $this->assertPluginTableToken($tokens, $targetIndex, $requiredPrefix);
        }

        $primaryIntoIndex = in_array($command, ['insert', 'replace'], true) && $targetIndex !== null
            ? $targetIndex - 1
            : -1;
        foreach ($tokens as $index => $token) {
            if ($token['type'] !== 'word') {
                continue;
            }
            $keyword = strtolower($token['value']);
            if (in_array($keyword, ['select', 'union'], true)) {
                throw new \RuntimeException('插件 SQL 不允许子查询、跨表查询或 UNION');
            }
            if ($keyword === 'engine' && !in_array(strtolower((string) ($tokens[$index + 2]['value'] ?? '')), ['innodb', 'myisam'], true)) {
                throw new \RuntimeException('插件 SQL 只允许使用 InnoDB 或 MyISAM 存储引擎');
            }
            if ($keyword === 'engine') {
                $hasSafeEngine = true;
            }
        }
        if ($command === 'create' && !$hasSafeEngine) {
            throw new \RuntimeException('CREATE TABLE 必须明确指定 ENGINE=InnoDB 或 ENGINE=MyISAM');
        }

        if ($command === 'update' && $this->hasTopLevelCommaBeforeKeyword($tokens, $targetIndex ?? 1, 'set')) {
            throw new \RuntimeException('插件 SQL 不允许多表 UPDATE');
        }
        if ($command === 'delete' && ($this->hasTopLevelComma($tokens, 1) || $this->containsSqlWord($tokens, 'join') || $this->containsSqlWord($tokens, 'using'))) {
            throw new \RuntimeException('插件 SQL 只允许单表 DELETE');
        }

        //拒绝多表 DROP 和 ALTER RENAME，防止通过表名改写把系统表纳入操作范围。
        if ($command === 'drop' && $this->hasTopLevelComma($tokens, $targetIndex ?? 0)) {
            throw new \RuntimeException('插件 SQL 一次只能删除一张插件数据表');
        }
        foreach ($tokens as $token) {
            if ($token['type'] === 'word' && strtolower($token['value']) === 'rename') {
                throw new \RuntimeException('插件 SQL 不允许重命名数据表或字段');
            }
        }

        //检查子查询、JOIN、外键、INSERT ... SELECT 等语法里的所有表引用。
        foreach ($tokens as $index => $token) {
            if ($token['type'] !== 'word') {
                continue;
            }
            $keyword = strtolower($token['value']);
            if (in_array($keyword, ['from', 'join', 'references', 'like'], true)) {
                $this->assertPluginTableToken($tokens, $index + 1, $requiredPrefix);
            } elseif ($keyword === 'into' && $index !== $primaryIntoIndex) {
                $this->assertPluginTableToken($tokens, $index + 1, $requiredPrefix);
            } elseif ($keyword === 'table' && $index !== 1) {
                //例如 ALTER TABLE ... EXCHANGE PARTITION ... WITH TABLE other_table。
                $this->assertPluginTableToken($tokens, $index + 1, $requiredPrefix);
            }
        }
    }

    /**
     * 读取一个 SQL 表标识符并验证插件前缀；拒绝库名限定和动态表达式。
     */
    protected function assertPluginTableToken(array $tokens, int $index, string $requiredPrefix): void
    {
        if (!isset($tokens[$index]) || !in_array($tokens[$index]['type'], ['word', 'identifier'], true)) {
            throw new \RuntimeException('插件 SQL 包含无法识别的数据表引用');
        }
        $table = (string) $tokens[$index]['value'];
        if (
            $table === ''
            || str_contains($table, '.')
            || strlen($table) <= strlen($requiredPrefix)
            || !str_starts_with($table, $requiredPrefix)
        ) {
            throw new \RuntimeException('插件 SQL 只允许以「' . $requiredPrefix . '」开头的数据表');
        }
        if (isset($tokens[$index + 1]) && $tokens[$index + 1]['value'] === '.') {
            throw new \RuntimeException('插件 SQL 不允许跨数据库访问数据表');
        }
    }

    /** SQL 词法切分：区分字符串、反引号标识符、关键字和符号。 */
    protected function tokenizeSql(string $sql): array
    {
        $tokens = [];
        $length = strlen($sql);
        for ($i = 0; $i < $length;) {
            $char = $sql[$i];
            if (ord($char) <= 32) {
                $i++;
                continue;
            }
            if ($char === "'" || $char === '"' || $char === '`') {
                $quote = $char;
                $value = '';
                $i++;
                $closed = false;
                while ($i < $length) {
                    $current = $sql[$i];
                    $next = $i + 1 < $length ? $sql[$i + 1] : '';
                    if ($current === '\\' && $quote !== '`' && $next !== '') {
                        $value .= $next;
                        $i += 2;
                        continue;
                    }
                    if ($current === $quote) {
                        if ($next === $quote) {
                            $value .= $quote;
                            $i += 2;
                            continue;
                        }
                        $closed = true;
                        $i++;
                        break;
                    }
                    $value .= $current;
                    $i++;
                }
                if (!$closed) {
                    throw new \RuntimeException('插件 SQL 字符串或标识符未闭合');
                }
                $tokens[] = ['type' => $quote === '`' ? 'identifier' : 'string', 'value' => $value];
                continue;
            }
            if (preg_match('/[A-Za-z0-9_$]/', $char)) {
                $start = $i;
                while ($i < $length && preg_match('/[A-Za-z0-9_$]/', $sql[$i])) {
                    $i++;
                }
                $tokens[] = ['type' => 'word', 'value' => substr($sql, $start, $i - $start)];
                continue;
            }
            $tokens[] = ['type' => 'symbol', 'value' => $char];
            $i++;
        }
        return $tokens;
    }

    /** 检查 DROP TABLE 是否包含逗号分隔的多表目标。 */
    protected function hasTopLevelComma(array $tokens, int $start): bool
    {
        $depth = 0;
        for ($i = $start; $i < count($tokens); $i++) {
            if ($tokens[$i]['value'] === '(') {
                $depth++;
            } elseif ($tokens[$i]['value'] === ')') {
                $depth--;
            } elseif ($tokens[$i]['value'] === ',' && $depth === 0) {
                return true;
            }
        }
        return false;
    }

    /** 在指定关键字前检查是否出现顶层逗号。 */
    protected function hasTopLevelCommaBeforeKeyword(array $tokens, int $start, string $keyword): bool
    {
        $depth = 0;
        for ($i = $start; $i < count($tokens); $i++) {
            if ($tokens[$i]['type'] === 'word' && strtolower($tokens[$i]['value']) === $keyword && $depth === 0) {
                return false;
            }
            if ($tokens[$i]['value'] === '(') {
                $depth++;
            } elseif ($tokens[$i]['value'] === ')') {
                $depth--;
            } elseif ($tokens[$i]['value'] === ',' && $depth === 0) {
                return true;
            }
        }
        return true;
    }

    /** 判断词法 token 中是否出现指定 SQL 关键字。 */
    protected function containsSqlWord(array $tokens, string $word): bool
    {
        foreach ($tokens as $token) {
            if ($token['type'] === 'word' && strtolower($token['value']) === strtolower($word)) {
                return true;
            }
        }
        return false;
    }

    /**
     * 递归删除目录
     *
     * 软链接只删链接本身、不跟进目标目录：
     * is_dir() 对「指向目录的软链接」返回 true，不特判的话递归进去会把目标目录的内容也删掉。
     * @param string $dir
     * @return void
     */
    protected function removeDir(string $dir): void
    {
        //自己就是软链接：只删链接
        if (is_link($dir)) {
            @unlink($dir);
            return;
        }
        if (!is_dir($dir)) {
            return;
        }
        foreach (scandir($dir) as $item) {
            if ($item === '.' || $item === '..') {
                continue;
            }
            $path = $dir . DIRECTORY_SEPARATOR . $item;
            if (is_link($path)) {
                //软链接只删链接，不递归进去
                @unlink($path);
                continue;
            }
            is_dir($path) ? $this->removeDir($path) : @unlink($path);
        }
        @rmdir($dir);
    }

    /**
     * 递归统计目录里所有文件的总大小（校验解压后的实际体积用）
     * @param string $dir
     * @return int 字节数
     */
    protected function dirSize(string $dir): int
    {
        if (!is_dir($dir)) {
            return 0;
        }
        $size = 0;
        foreach (scandir($dir) as $item) {
            if ($item === '.' || $item === '..') {
                continue;
            }
            $path = $dir . DIRECTORY_SEPARATOR . $item;
            if (is_link($path)) {
                //软链接不计入，也不跟进
                continue;
            }
            $size += is_dir($path) ? $this->dirSize($path) : (int) @filesize($path);
        }
        return $size;
    }
}
