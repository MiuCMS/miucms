<?php
/**
 * @copyright Copyright (c) 2026 米柚工作室
 * @license https://opensource.org/licenses/Apache-2.0
 * @link https://www.miucms.com
 */

declare(strict_types=1);

namespace app\extend\plugin;

use think\helper\Str;

/**
 * 钩子管理器
 *
 * 负责把「已启用插件」的钩子回调注册进来，并在业务代码触发钩子时按顺序调用。
 *
 * 关于引用传参：trigger() 用的是 `mixed &...$args`（引用传参的变长参数），
 * 所以像 document_add / template_parse 这类「允许插件改数据」的钩子，
 * 插件里改了 $data / $content，调用方拿到的就是改后的值。
 * 注意：调用 trigger() 时传的必须是「变量」，不能直接传字面量或表达式。
 */
class Hook
{
    /**
     * 单例
     * @var Hook|null
     */
    private static ?Hook $instance = null;

    /**
     * 已注册的钩子：hookName => [callback, callback, ...]
     * @var array<string, array>
     */
    protected array $hooks = [];

    /**
     * 是否已从数据库加载过
     * @var bool
     */
    protected bool $initialized = false;

    /**
     * 系统内置钩子清单：钩子名 => 中文说明
     * 插件类里只要定义了同名方法（或 hooks.php 里声明了映射），就会被自动注册
     * @var array<string, string>
     */
    public const HOOK_LIST = [
        // ---- 生命周期 ----
        'app_init'        => '应用初始化',
        'module_init'     => '模块加载',
        'action_begin'    => '控制器执行前',
        'action_end'      => '控制器执行后',
        'template_parse'  => '模板解析完成',
        'frontend_response' => '前台响应完成',
        'static_page_ready' => '静态页面写入前',
        'admin_dashboard' => '后台首页卡片',

        // ---- 文档 ----
        'admin_list_toolbar_action' => '后台列表工具栏扩展',
        'document_list_action' => '文档列表操作列扩展',
        'custom_list_action' => '自定义变量列表操作列扩展',
        'tag_list_action' => '标签列表操作列扩展',
        'document_add'     => '文档添加前',
        'document_added'   => '文档添加后',
        'document_edit'    => '文档编辑前',
        'document_edited'  => '文档编辑后',
        'document_delete'  => '文档删除前',
        'document_restore' => '文档恢复（回收站）',
        'document_purge'   => '文档彻底删除前',
        'document_examine' => '文档审核/字段变更',

        // ---- 栏目 ----
        'nav_list_action' => '栏目列表操作列扩展',
        'nav_add'    => '栏目添加前',
        'nav_added'  => '栏目添加后',
        'nav_edit'   => '栏目编辑前',
        'nav_edited' => '栏目编辑后',
        'nav_delete' => '栏目删除前',

        // ---- 会员 ----
        'user_register' => '用户注册',
        'user_registered' => '用户注册成功',
        'user_login'    => '用户登录',
        'user_logout'   => '用户退出',
        'user_update'   => '用户资料修改',

        // ---- 后台管理员 ----
        'admin_login'  => '管理员登录',
        'admin_logout' => '管理员退出',

        // ---- 搜索 ----
        'search_before' => '搜索前',
        'search_after'  => '搜索后',

        // ---- 留言 / 表单 ----
        'form_submit'    => '留言提交前',
        'form_submitted' => '留言提交后',
        'form_examine'   => '留言审核',
        'form_reply'     => '留言回复',
        'form_delete'    => '留言删除前',

        // ---- 文件 ----
        'file_upload' => '文件上传后',
    ];

    /**
     * 获取单例
     * @return Hook
     */
    public static function instance(): Hook
    {
        if (self::$instance === null) {
            self::$instance = new self();
        }
        return self::$instance;
    }

    private function __construct()
    {
    }

    /**
     * 初始化：加载所有已启用插件的钩子
     * @return void
     */
    public function init(): void
    {
        if ($this->initialized) {
            return;
        }

        try {
            foreach (PluginManager::instance()->getEnabledPluginNames() as $name) {
                $this->loadPluginHooks($name);
            }
        } catch (\Throwable $e) {
            //表不存在 / 数据库异常时静默跳过，不能因为插件系统把整站搞挂
            trace('插件钩子初始化失败：' . $e->getMessage(), 'error');
        }

        $this->initialized = true;
    }

    /**
     * 加载单个插件的钩子
     *
     * 两种声明方式都支持，且会合并：
     *   1) hooks.php 返回 ['钩子名' => '回调方法名']
     *   2) 插件类里直接定义与钩子同名的方法（如 documentAdd）
     *
     * @param string $pluginName
     * @return void
     */
    public function loadPluginHooks(string $pluginName): void
    {
        $dir = PluginManager::instance()->getPluginPath() . $pluginName;

        //插件的钩子回调统一挂在「带配置的插件实例」上，这样回调里能用 $this->getConfig()
        $pluginClass = "plugins\\{$pluginName}\\Plugin";
        $instance = null;
        if (class_exists($pluginClass)) {
            try {
                $instance = PluginManager::instance()->makePlugin($pluginName);
            } catch (\Throwable $e) {
                trace("插件 {$pluginName} 实例化失败：" . $e->getMessage(), 'error');
                return;
            }
        }

        //1) hooks.php 配置方式
        $hooksFile = $dir . DIRECTORY_SEPARATOR . 'hooks.php';
        if (is_file($hooksFile)) {
            $map = include $hooksFile;
            if (is_array($map) && $instance) {
                foreach ($map as $hookName => $method) {
                    if (isset(self::HOOK_LIST[$hookName]) && method_exists($instance, $method)) {
                        $this->add($hookName, [$instance, $method]);
                    }
                }
            }
        }

        //2) 类里同名方法方式（钩子名是下划线，类里方法名是驼峰：document_add -> documentAdd）
        if ($instance) {
            $ref = new \ReflectionClass($instance);
            foreach (self::HOOK_LIST as $hookName => $title) {
                $method = Str::camel($hookName);
                if (!$ref->hasMethod($method)) {
                    continue;
                }
                $m = $ref->getMethod($method);
                //只认插件自己重写的方法，跳过基类的空实现
                if ($m->getDeclaringClass()->getName() !== PluginBase::class) {
                    $this->add($hookName, [$instance, $method]);
                }
            }
        }
    }

    /**
     * 注册一个钩子回调
     * @param string $name
     * @param mixed  $callback [实例, 方法名] 或闭包
     * @return void
     */
    public function add(string $name, mixed $callback): void
    {
        $this->hooks[$name] ??= [];
        $this->hooks[$name][] = $callback;
    }

    /**
     * 移除某个钩子回调
     * @param string $name
     * @param mixed  $callback
     * @return void
     */
    public function remove(string $name, mixed $callback): void
    {
        if (isset($this->hooks[$name])) {
            $this->hooks[$name] = array_values(array_filter(
                $this->hooks[$name],
                fn($item) => $item !== $callback
            ));
        }
    }

    /**
     * 触发钩子（按注册顺序依次调用，返回最后一个非 null 的返回值）
     *
     * @param string $name 钩子名
     * @param mixed  ...$args 参数（必须是变量，插件可原地修改）
     * @return mixed
     */
    public function trigger(string $name, mixed &...$args): mixed
    {
        if (!$this->initialized) {
            $this->init();
        }

        $result = null;
        foreach ($this->hooks[$name] ?? [] as $callback) {
            if (!is_callable($callback)) {
                continue;
            }
            try {
                $ret = call_user_func_array($callback, $args);
                if ($ret !== null) {
                    $result = $ret;
                }
            } catch (\Throwable $e) {
                //单个钩子出错不影响主流程
                trace('钩子执行错误 [' . $name . ']: ' . $e->getMessage(), 'error');
            }
        }
        return $result;
    }

    /**
     * 触发钩子并收集所有返回值
     * @param string $name
     * @param mixed  ...$args
     * @return array
     */
    public function triggerAll(string $name, mixed &...$args): array
    {
        if (!$this->initialized) {
            $this->init();
        }

        $results = [];
        foreach ($this->hooks[$name] ?? [] as $callback) {
            if (!is_callable($callback)) {
                continue;
            }
            try {
                $results[] = call_user_func_array($callback, $args);
            } catch (\Throwable $e) {
                trace('钩子执行错误 [' . $name . ']: ' . $e->getMessage(), 'error');
            }
        }
        return $results;
    }

    /**
     * 该钩子是否有插件在监听
     * @param string $name
     * @return bool
     */
    public function exists(string $name): bool
    {
        return !empty($this->hooks[$name]);
    }

    /**
     * 所有已注册的钩子
     * @return array
     */
    public function getHooks(): array
    {
        return $this->hooks;
    }

    /**
     * 清空（切换插件状态后重新 init 用）
     * @return void
     */
    public function clear(): void
    {
        $this->hooks = [];
        $this->initialized = false;
    }

    /**
     * 移除某插件的所有钩子回调
     * @param string $pluginName
     * @return void
     */
    public function removePluginHooks(string $pluginName): void
    {
        $class = "plugins\\{$pluginName}\\Plugin";
        foreach ($this->hooks as $name => $callbacks) {
            $this->hooks[$name] = array_values(array_filter($callbacks, function ($cb) use ($class) {
                if (is_array($cb) && isset($cb[0])) {
                    $cbClass = is_object($cb[0]) ? get_class($cb[0]) : $cb[0];
                    return $cbClass !== $class;
                }
                return true;
            }));
        }
    }
}
