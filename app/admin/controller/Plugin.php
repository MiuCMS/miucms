<?php
/**
 * @copyright Copyright (c) 2026 米柚工作室
 * @license https://opensource.org/licenses/Apache-2.0
 * @link https://www.miucms.com
 */

declare(strict_types=1);

namespace app\admin\controller;

use app\admin\BaseController;
use app\extend\plugin\Hook;
use app\extend\plugin\PluginManager;
use app\extend\plugin\PluginScaffold;
use app\services\PluginUpdateManager;
use app\services\PluginMarket;
use think\facade\Db;
use think\facade\View;

/**
 * 后台 - 插件管理
 *
 * 列表 / 安装 / 卸载 / 启用 / 禁用 / 配置 / zip 上传 / 钩子列表，
 * 以及插件自带后台页面的分发入口 run()。
 */
class Plugin extends BaseController
{
    /**
     * 插件管理器
     * @return PluginManager
     */
    protected function pm(): PluginManager
    {
        return PluginManager::instance();
    }

    /**
     * 确保插件导出动作已登记，并授予系统所有者组。
     * 老站点不会重新导入安装 SQL，因此在插件列表页幂等补齐权限节点。
     */
    protected function ensureExportPermission(): void
    {
        try {
            $src = 'admin/plugin/export';
            $rule = Db::name('admin_rule')->where('src', $src)->find();
            $changed = false;

            if (empty($rule)) {
                $parent = Db::name('admin_rule')->where('src', 'admin/plugin/index')->find();
                if (empty($parent)) {
                    return;
                }

                $ruleId = Db::name('admin_rule')->insertGetId([
                    'pid'         => (int) $parent['id'],
                    'src'         => $src,
                    'title'       => '导出插件',
                    'name'        => '插件',
                    'icon'        => '',
                    'menu'        => 2,
                    'sort'        => 0,
                    'status'      => 1,
                    'module'      => '',
                    'crud'        => '',
                    'create_time' => time(),
                    'update_time' => 0,
                ]);
                $rule = ['id' => $ruleId];
                $changed = true;
            }

            $group = Db::name('admin_group')->where('id', 1)->find();
            if (!empty($group)) {
                $rules = array_filter(explode(',', trim((string) ($group['rules'] ?? ''), ',')), static fn($id) => $id !== '');
                if (!in_array((string) $rule['id'], $rules, true)) {
                    $rules[] = (string) $rule['id'];
                    Db::name('admin_group')->where('id', 1)->update(['rules' => implode(',', $rules)]);
                    $changed = true;
                }
            }

            if ($changed) {
                clear_cache('adminRules');
            }
        } catch (\Throwable $e) {
            trace('插件导出权限初始化失败：' . $e->getMessage(), 'error');
        }
        $this->ensurePluginUpdatePermissions();
    }

    /** 老站点访问插件列表时补齐更新接口权限，只授予超级管理员组。 */
    protected function ensurePluginUpdatePermissions(): void
    {
        try {
            $parent = Db::name('admin_rule')->where('src', 'admin/plugin/index')->find();
            if (empty($parent)) return;

            $marketPermissions = [
                'admin/plugin/market' => '在线插件市场',
                'admin/plugin/market_login' => '插件市场登录',
                'admin/plugin/market_logout' => '插件市场退出',
                'admin/plugin/market_list' => '插件市场列表',
                'admin/plugin/market_download' => '下载市场插件',
            ];
            $permissionNodes = $marketPermissions + [
                'admin/plugin/check_updates' => '检查插件更新',
                'admin/plugin/update_plugin' => '更新插件',
            ];
            $nodeIds = [];
            $marketNodeIds = [];
            $nodesChanged = false;
            foreach ($permissionNodes as $src => $title) {
                $rule = Db::name('admin_rule')->where('src', $src)->find();
                if (empty($rule)) {
                    $ruleId = Db::name('admin_rule')->insertGetId([
                        'pid' => (int) $parent['id'],
                        'src' => $src,
                        'title' => $title,
                        'name' => '插件',
                        'icon' => '',
                        'menu' => 2,
                        'sort' => 110,
                        'status' => 1,
                        'module' => '',
                        'crud' => '',
                        'create_time' => time(),
                        'update_time' => 0,
                    ]);
                    $rule = ['id' => $ruleId];
                    $nodesChanged = true;
                }
                $nodeIds[] = (string) $rule['id'];
                if (isset($marketPermissions[$src])) $marketNodeIds[] = (string) $rule['id'];
            }

            $groupsChanged = false;
            foreach (Db::name('admin_group')->select()->toArray() as $group) {
                $rules = array_values(array_filter(explode(',', trim((string) ($group['rules'] ?? ''), ',')), static fn($id) => $id !== ''));
                $isSuperAdmin = (int) $group['id'] === 1;
                if (!$isSuperAdmin && !in_array((string) $parent['id'], $rules, true)) continue;
                $updatedRules = array_values(array_unique(array_merge($rules, $isSuperAdmin ? $nodeIds : $marketNodeIds)));
                if ($updatedRules !== $rules) {
                    Db::name('admin_group')->where('id', (int) $group['id'])->update(['rules' => implode(',', $updatedRules)]);
                    $groupsChanged = true;
                }
            }
            if ($nodesChanged || $groupsChanged) {
                clear_cache('adminRules');
            }
        } catch (\Throwable $e) {
            trace('插件更新权限初始化失败：' . $e->getMessage(), 'error');
        }
    }

    /**
     * 将插件 menu.php 的定义转换为后台左侧二级菜单使用的数据结构。
     * 菜单 URL 通过 url() 生成，兼容后台入口改名和项目 URL 配置。
     */
    protected function buildPluginSubNavMenus(array $menus): array
    {
        $result = [];
        foreach ($menus as $menu) {
            if (!is_array($menu)) {
                continue;
            }
            $title = trim((string) ($menu['title'] ?? ''));
            if ($title === '') {
                continue;
            }

            $rawUrl = trim((string) ($menu['url'] ?? $menu['src'] ?? ''));
            $src = '';
            if ($rawUrl !== '') {
                try {
                    $src = (string) url($rawUrl);
                } catch (\Throwable $e) {
                    $src = '';
                }
            }

            $children = $menu['children'] ?? $menu['list'] ?? [];
            $item = [
                'title' => $title,
                'src'   => $src,
            ];
            if (is_array($children) && $children) {
                $item['list'] = $this->buildPluginSubNavMenus($children);
            }
            $result[] = $item;
        }
        return $result;
    }

    /**
     * 插件列表
     */
    public function index()
    {
        $pm = $this->pm();
        //首次进入自动建表（表不存在时），省得用户手动导 SQL
        $pm->ensureTable();
        $this->ensureExportPermission();

        $local     = $pm->getLocalPlugins();
        $installed = $pm->getInstalledPlugins();
        $map       = array_column($installed, null, 'name');
        //哪些插件已经加过后台菜单（一次性查出来）
        $menuNames = $pm->getMenuPluginNames();
        $list = [];
        foreach ($local as $name => $item) {
            $item['cms_compatibility_error'] = $pm->getCmsCompatibilityError($item);
            $item['cms_compatible'] = $item['cms_compatibility_error'] === null;
            if (isset($map[$name])) {
                $item['id']           = $map[$name]['id'];
                $item['installed']    = (int) $map[$name]['installed'];
                $item['status']       = (int) $map[$name]['status'];
                $item['install_time'] = $map[$name]['install_time'];
            } else {
                $item['id']        = 0;
                $item['installed'] = 0;
                $item['status']    = 0;
            }
            //是否已加过后台菜单（列表里决定要不要显示「添加后台菜单」按钮）
            $item['has_menu'] = in_array($name, $menuNames, true);
            //插件首页的二级菜单始终读取插件目录里的 admin/menu.php，不依赖系统菜单登记状态。
            $item['admin_menu'] = [];
            if (!empty($item['installed']) && !empty($item['has_admin'])) {
                try {
                    $item['admin_menu'] = $this->buildPluginSubNavMenus($pm->getAdminMenus($name));
                } catch (\Throwable $e) {
                    trace('读取插件菜单失败：' . $name . '：' . $e->getMessage(), 'error');
                }
            }
            //插件创建时间取本地插件目录的创建时间；取不到时回退到安装时间。
            $createdAt = @filectime($pm->getPluginPath() . $name);
            $item['_created_at'] = $createdAt !== false && $createdAt > 0
                ? (int) $createdAt
                : (int) ($item['install_time'] ?? 0);
            $list[] = $item;
        }

        //数据库里有记录、但目录已被删掉的「孤儿插件」，也列出来方便卸载清理
        foreach ($installed as $row) {
            if (!isset($local[$row['name']])) {
                $list[] = [
                    'name'         => $row['name'],
                    'title'        => $row['title'] . '（文件已丢失）',
                    'version'      => $row['version'],
                    'author'       => $row['author'],
                    'description'  => $row['description'],
                    'id'           => $row['id'],
                    'installed'    => (int) $row['installed'],
                    'status'       => (int) $row['status'],
                    '_created_at'  => (int) ($row['install_time'] ?? 0),
                    'config_fields'=> [],
                    'missing'      => 1,
                ];
            }
        }

        //较新的插件排在前面；时间相同时按插件标识稳定排序。
        usort($list, static function (array $a, array $b): int {
            $timeOrder = (int) ($b['_created_at'] ?? 0) <=> (int) ($a['_created_at'] ?? 0);
            return $timeOrder !== 0 ? $timeOrder : strcmp((string) $a['name'], (string) $b['name']);
        });
        foreach ($list as &$item) {
            unset($item['_created_at']);
        }
        unset($item);

        View::assign('list', $list);
        View::assign('total', count($list));
        View::assign('installedCount', count(array_filter($list, fn($p) => !empty($p['installed']))));
        View::assign('enabledCount', count(array_filter($list, fn($p) => !empty($p['installed']) && !empty($p['status']))));
        return View::fetch();
    }

    /** 后台打开插件列表后异步检查官网更新。 */
    public function check_updates()
    {
        if (!request()->isGet()) {
            return to_assign(1, '请使用 GET 请求检查插件更新');
        }
        $result = (new PluginUpdateManager())->checkUpdates();
        return to_assign($result['code'], $result['msg'], $result['data'] ?? []);
    }

    /** 下载并安全替换指定的官方插件。 */
    public function update_plugin()
    {
        if (!request()->isPost()) {
            return to_assign(1, '请使用 POST 提交');
        }
        $name = trim((string) input('name/s', ''));
        $result = (new PluginUpdateManager())->updatePlugin($name);
        if ((int) $result['code'] === 0) {
            safe_add_log('edit', 0, ['plugin' => $name, 'version' => $result['version'] ?? ''], '插件更新');
        }
        return to_assign($result['code'], $result['msg']);
    }

    public function market()
    {
        View::assign('loggedIn', (new PluginMarket())->isLoggedIn());
        return View::fetch();
    }

    public function market_login()
    {
        if (!request()->isPost()) return to_assign(1, '请使用 POST 提交');
        $username = trim((string) input('username/s', ''));
        $password = (string) input('password/s', '');
        if ($username === '' || $password === '') return to_assign(1, '请输入官网账号和密码');
        $result = (new PluginMarket())->login($username, $password);
        return to_assign($result['code'], $result['msg']);
    }

    public function market_logout()
    {
        if (!request()->isPost()) return to_assign(1, '请使用 POST 提交');
        (new PluginMarket())->logout();
        return to_assign(0, '已退出官网账号');
    }

    public function market_list()
    {
        if (!request()->isGet()) return to_assign(1, '请使用 GET 请求');
        $page = max(1, (int) input('page/d', 1));
        $perPage = min(100, max(1, (int) input('per_page/d', 20)));
        $keyword = mb_substr(trim((string) input('keyword/s', '')), 0, 100);
        $price = (string) input('price/s', 'all');
        $source = (string) input('source/s', 'all');
        $categoryId = max(0, (int) input('category_id/d', 0));
        $purchaseStatus = (string) input('purchase_status/s', 'all');
        if (!in_array($price, ['all', 'free', 'paid'], true) || !in_array($source, ['all', 'official', 'third'], true)
            || !in_array($purchaseStatus, ['all', 'purchased', 'not_purchased'], true)) {
            return json(['code' => 1, 'msg' => '筛选参数无效']);
        }
        $result = (new PluginMarket())->plugins($page, $perPage, $keyword, $price, $source, $categoryId, $purchaseStatus);
        if ((int) ($result['code'] ?? 1) !== 0) {
            return json(['code' => 1, 'msg' => (string) ($result['message'] ?? '插件市场查询失败')]);
        }
        $items = $result['data']['items'] ?? [];
        if (is_array($items)) {
            $local = $this->pm()->getLocalPlugins();
            foreach ($items as &$item) {
                $item['downloaded'] = isset($local[$item['id'] ?? '']);
            }
            unset($item);
        }
        return json(['code' => 0, 'msg' => '成功', 'data' => ['items' => $items, 'filters' => $result['data']['filters'] ?? [], 'pagination' => $result['data']['pagination'] ?? []]]);
    }

    public function market_download()
    {
        if (!request()->isPost()) return to_assign(1, '请使用 POST 提交');
        $name = trim((string) input('name/s', ''));
        if ($name === '') return to_assign(1, '参数错误');
        $result = (new PluginMarket())->download($name);
        if ((int) $result['code'] === 0) safe_add_log('add', 0, ['plugin' => $name], '插件市场下载');
        return to_assign($result['code'], $result['msg']);
    }

    /**
     * 安装
     */
    public function install()
    {
        if (!request()->isPost()) {
            return to_assign(1, '请使用 POST 提交');
        }
        $name = (string) input('name/s', '');
        if ($name === '') {
            return to_assign(1, '参数错误');
        }
        $res = $this->pm()->install($name);
        safe_add_log('add', 0, ['plugin' => $name], '插件');
        return to_assign($res['code'], $res['msg']);
    }

    /**
     * 给插件添加后台菜单
     *
     * 安装插件时不再自动加菜单，改成装完由用户点列表里的按钮手动加。
     */
    public function add_menu()
    {
        if (!request()->isPost()) {
            return to_assign(1, '请使用 POST 提交');
        }
        $name = (string) input('name/s', '');
        if ($name === '') {
            return to_assign(1, '参数错误');
        }
        $res = $this->pm()->addMenus($name);
        if ($res['code'] === 0) {
            safe_add_log('add', 0, ['plugin_menu' => $name], '插件');
        }
        return to_assign($res['code'], $res['msg']);
    }

    /**
     * 删除插件后台菜单（只删菜单节点，不动插件和数据）
     */
    public function remove_menu()
    {
        if (!request()->isPost()) {
            return to_assign(1, '请使用 POST 提交');
        }
        $name = (string) input('name/s', '');
        if ($name === '') {
            return to_assign(1, '参数错误');
        }
        $res = $this->pm()->deleteMenus($name);
        if ($res['code'] === 0) {
            safe_add_log('delete', 0, ['plugin_menu' => $name], '插件');
        }
        return to_assign($res['code'], $res['msg']);
    }

    /**
     * 卸载
     */
    public function uninstall()
    {
        if (!request()->isPost()) {
            return to_assign(1, '请使用 POST 提交');
        }
        $name  = (string) input('name/s', '');
        $force = (int) input('force/d', 0);
        if ($name === '') {
            return to_assign(1, '参数错误');
        }
        $res = $this->pm()->uninstall($name, $force === 1);
        safe_add_log('delete', 0, ['plugin' => $name], '插件');
        return to_assign($res['code'], $res['msg']);
    }

    /**
     * 启用
     */
    public function enable()
    {
        if (!request()->isPost()) {
            return to_assign(1, '请使用 POST 提交');
        }
        $name = (string) input('name/s', '');
        if ($name === '') {
            return to_assign(1, '参数错误');
        }
        $res = $this->pm()->enable($name);
        return to_assign($res['code'], $res['msg']);
    }

    /**
     * 禁用
     */
    public function disable()
    {
        if (!request()->isPost()) {
            return to_assign(1, '请使用 POST 提交');
        }
        $name = (string) input('name/s', '');
        if ($name === '') {
            return to_assign(1, '参数错误');
        }
        $res = $this->pm()->disable($name);
        return to_assign($res['code'], $res['msg']);
    }

    /**
     * 插件配置页
     */
    public function config()
    {
        $name   = (string) input('name/s', '');
        $plugin = $this->pm()->getPlugin($name);
        if (!$plugin) {
            return $this->error('插件不存在');
        }
        if (empty($plugin['installed'])) {
            return $this->error('插件未安装');
        }

        //配置字段定义 + 当前值（没保存过的用默认值）
        $fields = $plugin['config_fields'] ?? [];
        $config = $this->pm()->getPluginConfig($name);
        foreach ($fields as &$field) {
            if (!empty($field['fallback_invalid_option']) && ($field['type'] ?? '') === 'select') {
                $fieldName = (string) ($field['name'] ?? '');
                $savedValue = $config[$fieldName] ?? null;
                if ($fieldName !== '' && $savedValue !== null) {
                    $allowedValues = array_map(
                        static fn (array $option): string => (string) ($option['value'] ?? ''),
                        is_array($field['options'] ?? null) ? $field['options'] : []
                    );
                    if (!in_array((string) $savedValue, $allowedValues, true)) {
                        $config[$fieldName] = $field['default'] ?? '';
                    }
                }
            }

            if (($field['type'] ?? '') !== 'help_area') {
                continue;
            }

            $markdown = (string) ($field['default'] ?? '');
            try {
                $converter = new \League\CommonMark\GithubFlavoredMarkdownConverter([
                    'html_input'         => 'escape',
                    'allow_unsafe_links' => false,
                    'max_nesting_level'  => 20,
                ]);
                $field['rendered'] = $converter->convert($markdown)->getContent();
            } catch (\Throwable $e) {
                $field['rendered'] = '<p>' . nl2br(htmlspecialchars($markdown, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8')) . '</p>';
            }
        }
        unset($field);

        View::assign('plugin', $plugin);
        View::assign('fields', $fields);
        View::assign('config', $config);
        return View::fetch();
    }

    /**
     * 保存插件配置
     */
    public function save_config()
    {
        if (!request()->isPost()) {
            return to_assign(1, '请使用 POST 提交');
        }
        $name = (string) input('name/s', '');
        if ($name === '') {
            return to_assign(1, '参数错误');
        }
        $plugin = $this->pm()->getPlugin($name);
        if (!$plugin || empty($plugin['installed'])) {
            return to_assign(1, '插件未安装');
        }

        //只收集「配置字段定义里声明过」的项，防止前端塞别的键
        $config = [];
        $currentConfig = null;
        foreach (($plugin['config_fields'] ?? []) as $field) {
            $key = $field['name'] ?? '';
            if ($key === '') {
                continue;
            }
            $type = $field['type'] ?? 'text';
            if ($type === 'help_area') {
                //帮助说明属于插件代码元数据，不作为用户配置写入数据库。
                continue;
            }
            $val  = input($key, null);
            if ($type === 'password' && !empty($field['preserve_empty'])) {
                if ($val !== null && !is_scalar($val)) {
                    return to_assign(1, '密码配置格式错误');
                }
                if ($currentConfig === null) {
                    $currentConfig = $this->pm()->getPluginConfig($name);
                }
                //密码字段不回显；空提交保留已有密钥，只有输入新值时才替换。
                $config[$key] = ($val === null || trim((string) $val) === '')
                    ? ($currentConfig[$key] ?? ($field['default'] ?? ''))
                    : (string) $val;
            } elseif ($type === 'switch') {
                $config[$key] = (int) ($val ? 1 : 0);
            } elseif ($type === 'number') {
                $config[$key] = $val === null ? ($field['default'] ?? 0) : $val + 0;
            } else {
                $config[$key] = $val === null ? ($field['default'] ?? '') : $val;
            }
        }

        $res = $this->pm()->saveConfig($name, $config);
        return to_assign($res['code'], $res['msg']);
    }

    /**
     * 上传 zip 安装
     */
    public function upload()
    {
        if (!request()->isPost()) {
            return to_assign(1, '请使用 POST 提交');
        }
        $file = request()->file('file');
        if (!$file) {
            return to_assign(1, '请选择要上传的 zip 包');
        }
        //只允许 zip：先看扩展名
        $ext = strtolower($file->getOriginalExtension());
        if ($ext !== 'zip') {
            return to_assign(1, '只允许上传 .zip 插件包');
        }
        //再看文件头，防止把别的文件改个 .zip 后缀传上来
        if (!$this->pm()->isZipFile($file->getPathname())) {
            return to_assign(1, '这不是有效的 zip 文件（只允许 .zip 格式）');
        }
        //限制大小 20M
        if ($file->getSize() > 20 * 1024 * 1024) {
            return to_assign(1, '插件包不能超过 20M');
        }

        try {
            $res = $this->pm()->installFromZip($file->getPathname());
        } catch (\Throwable $e) {
            return to_assign(1, '安装失败：' . $e->getMessage());
        }
        safe_add_log('add', 0, ['plugin_zip' => $file->getOriginalName()], '插件');
        return to_assign($res['code'], $res['msg']);
    }

    /**
     * 导出插件目录为可重新上传安装的 ZIP 包。
     */
    public function export()
    {
        if (!request()->isGet()) {
            return to_assign(1, '请使用 GET 请求导出插件');
        }

        $name = trim((string) input('name/s', ''));
        $pm = $this->pm();
        if (!$pm->isValidName($name) || $pm->isPhpKeyword($name)) {
            return to_assign(1, '插件标识不合法');
        }
        if (!class_exists(\ZipArchive::class)) {
            return to_assign(1, '当前 PHP 未安装 zip 扩展，无法导出');
        }

        $pluginRoot = realpath($pm->getPluginPath());
        $pluginDir = realpath($pm->getPluginPath() . $name);
        $pluginFile = $pluginDir ? $pluginDir . DIRECTORY_SEPARATOR . 'Plugin.php' : '';
        $rootPath = $pluginRoot === false ? '' : rtrim(str_replace('\\', '/', $pluginRoot), '/');
        $parentPath = $pluginDir === false ? '' : rtrim(str_replace('\\', '/', dirname($pluginDir)), '/');
        if (
            $pluginRoot === false
            || $pluginDir === false
            || strcasecmp($rootPath, $parentPath) !== 0
            || !is_dir($pluginDir)
            || !is_file($pluginFile)
            || is_link($pluginFile)
        ) {
            return to_assign(1, '插件目录不存在或缺少 Plugin.php');
        }

        $archivePath = tempnam(runtime_path(), 'plugin_export_');
        if ($archivePath === false) {
            return to_assign(1, '创建插件压缩包失败，请检查 runtime 目录权限');
        }

        $zip = new \ZipArchive();
        if ($zip->open($archivePath, \ZipArchive::CREATE | \ZipArchive::OVERWRITE) !== true) {
            @unlink($archivePath);
            return to_assign(1, '无法创建插件压缩包');
        }

        $success = $zip->addEmptyDir($name);
        try {
            $directory = new \RecursiveDirectoryIterator($pluginDir, \FilesystemIterator::SKIP_DOTS);
            $iterator = new \RecursiveIteratorIterator($directory, \RecursiveIteratorIterator::SELF_FIRST);
            foreach ($iterator as $file) {
                if ($file->isLink()) {
                    continue;
                }

                $relativePath = str_replace('\\', '/', substr($file->getPathname(), strlen($pluginDir) + 1));
                if ($relativePath === '' || $relativePath === '..' || str_starts_with($relativePath, '../')) {
                    continue;
                }
                if ($name === 'miu_ai_assistant' && str_starts_with($relativePath, 'storage/memory/')
                    && !in_array($relativePath, ['storage/memory/.gitignore', 'storage/memory/.htaccess'], true)) {
                    continue;
                }
                $entryName = $name . '/' . $relativePath;
                if ($file->isDir()) {
                    $success = $zip->addEmptyDir(rtrim($entryName, '/')) && $success;
                } elseif ($file->isFile()) {
                    $success = $zip->addFile($file->getPathname(), $entryName) && $success;
                }

                if (!$success) {
                    break;
                }
            }
        } catch (\Throwable $e) {
            $success = false;
            trace('打包插件失败：' . $e->getMessage(), 'error');
        }

        $closed = $zip->close();
        if (!$success || !$closed || !is_file($archivePath) || filesize($archivePath) === 0) {
            @unlink($archivePath);
            return to_assign(1, '插件压缩包生成失败，请检查插件目录文件权限');
        }

        //下载响应发送完成后清理临时压缩包，避免 runtime 目录长期堆积。
        register_shutdown_function(static function () use ($archivePath): void {
            if (is_file($archivePath)) {
                @unlink($archivePath);
            }
        });

        return download($archivePath, $name . '.zip')->mimeType('application/zip')->expire(0);
    }

    /**
     * 插件开发页（生成骨架 + 开发文档）
     */
    public function dev()
    {
        //钩子清单：钩子名(下划线) + 说明 + 插件类里对应的方法名(驼峰)
        $hooks = [];
        foreach (Hook::HOOK_LIST as $name => $title) {
            $hooks[] = [
                'name'   => $name,
                'title'  => $title,
                'method' => \think\helper\Str::camel($name),
            ];
        }
        View::assign('hooks', $hooks);
        View::assign('pluginPath', $this->pm()->getPluginPath());
        return View::fetch();
    }

    /**
     * 生成插件骨架
     */
    public function make()
    {
        if (!request()->isPost()) {
            return to_assign(1, '请使用 POST 提交');
        }

        $helpArea = (string) input('help_area/s', '');
        if (strlen($helpArea) > 50000) {
            return to_assign(1, '插件帮助说明不能超过 50000 字节');
        }

        $opt = [
            'name'        => (string) input('name/s', ''),
            'title'       => (string) input('title/s', ''),
            'version'     => (string) input('version/s', '1.0.0'),
            'author'      => (string) input('author/s', ''),
            'author_url'  => trim((string) input('author_url/s', '')),
            'description' => (string) input('description/s', ''),
            'help_area'   => str_replace("\0", '', $helpArea),
            'icon'        => (string) input('icon/s', 'layui-icon-star'),
            'with_admin'  => (int) input('with_admin/d', 1),
            'with_front'  => (int) input('with_front/d', 1),
            'with_taglib' => (int) input('with_taglib/d', 0),
            'with_sql'    => (int) input('with_sql/d', 0),
            'with_hooks'  => (int) input('with_hooks/d', 1),
        ];

        $res = (new PluginScaffold())->generate($opt);
        if ($res['code'] === 0) {
            safe_add_log('add', 0, ['plugin_scaffold' => $opt['name']], '插件');
        }
        return to_assign($res['code'], $res['msg'], $res['files'] ?? []);
    }

    /**
     * 钩子列表页
     */
    public function hooks()
    {
        $hook = Hook::instance();
        $hook->init();

        //每个钩子上挂了哪些插件
        $registered = [];
        foreach ($hook->getHooks() as $hookName => $callbacks) {
            foreach ($callbacks as $cb) {
                if (is_array($cb) && isset($cb[0])) {
                    $class = is_object($cb[0]) ? get_class($cb[0]) : $cb[0];
                    if (preg_match('/^plugins\\\\([^\\\\]+)\\\\/', $class, $m)) {
                        $registered[$hookName][] = $m[1];
                    } else {
                        $registered[$hookName][] = $class;
                    }
                }
            }
            $registered[$hookName] = array_values(array_unique($registered[$hookName] ?? []));
        }

        View::assign('hookList', Hook::HOOK_LIST);
        View::assign('registered', $registered);
        View::assign('hookRoutes', [
            'app_init'        => 'app/PluginInit/handle',
            'module_init'     => 'app/PluginInit/handle',
            'action_begin'    => 'admin|home/{控制器}/{方法}',
            'action_end'      => 'admin|home/{控制器}/{方法}',
            'template_parse'  => 'home/Template/handle',
            'custom_list_action' => 'admin/custom/index',
            'document_add'    => 'admin/document/add',
            'document_added'  => 'admin/document/add',
            'document_edit'   => 'admin/document/edit',
            'document_edited' => 'admin/document/edit',
            'document_delete' => 'admin/document/delete',
            'document_restore'=> 'admin/document/restore',
            'document_purge'  => 'admin/document/purge',
            'document_examine'=> 'admin/document/edit_field',
            'nav_add'         => 'admin/nav/add',
            'nav_added'       => 'admin/nav/add',
            'nav_edit'        => 'admin/nav/edit',
            'nav_edited'      => 'admin/nav/edit',
            'nav_delete'      => 'admin/nav/delete',
            'user_register'   => 'home/login/reg_submit',
            'user_login'      => 'home/login/login_submit',
            'user_logout'     => 'home/login/login_out',
            'user_update'     => 'home/user/edit_submit',
            'admin_login'     => 'admin/login/login_submit',
            'admin_logout'    => 'admin/login/login_out',
            'search_before'   => 'home/search/index',
            'search_after'    => 'home/search/index',
            'form_submit'     => 'home/form/submit',
            'form_submitted'  => 'home/form/submit',
            'form_examine'    => 'admin/form/message_examine',
            'form_reply'      => 'admin/form/message_reply',
            'form_delete'     => 'admin/form/message_delete',
            'file_upload'     => 'admin/api/upload',
        ]);
        return View::fetch();
    }

    /**
     * 插件自带后台页面的分发入口
     *
     * 地址约定：admin/plugin/run?name=<插件>&c=<控制器>&a=<方法>
     * 控制器文件：plugins/<name>/admin/controller/<C>.php（类名首字母大写）
     * 这样插件不用自己写路由，复用后台现成的控制器分发即可。
     */
    public function run()
    {
        $name       = (string) input('name/s', '');
        $controller = (string) input('c/s', 'Index');
        $action     = (string) input('a/s', 'index');

        if (!$this->pm()->isValidName($name)
            || !preg_match('/^[A-Za-z0-9_]+$/', $controller)
            || !preg_match('/^[A-Za-z0-9_]+$/', $action)) {
            return $this->error('参数错误');
        }

        $plugin = $this->pm()->getPlugin($name);
        if (!$plugin || empty($plugin['installed'])) {
            return $this->error('插件未安装', 'admin/plugin/index');
        }
        if (empty($plugin['status'])) {
            return $this->error('插件已禁用');
        }
        //按插件粒度鉴权：插件菜单节点至少有一个在当前管理员的角色里才放行
        //（admin/plugin/run 这个节点只能管到「能不能用插件分发入口」，管不到「用哪个插件」）
        if (!$this->pm()->adminHasPluginMenu($name)) {
            return $this->error('你没有权限访问这个插件的后台页面');
        }


        $class = "plugins\\{$name}\\admin\\controller\\" . ucfirst($controller);
        if (!class_exists($class)) {
            return $this->error('插件控制器不存在：' . $controller);
        }
        if (!method_exists($class, $action)) {
            return $this->error('插件方法不存在：' . $action);
        }

        //用容器实例化，插件控制器若继承 PluginAdminBase（含 App 依赖）也能正常构造
        $instance = app($class);
        return $instance->{$action}();
    }
}
