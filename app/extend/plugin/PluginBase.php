<?php
/**
 * @copyright Copyright (c) 2026 米柚工作室
 * @license https://opensource.org/licenses/Apache-2.0
 * @link https://www.miucms.com
 */

declare(strict_types=1);

namespace app\extend\plugin;

/**
 * 插件基类
 *
 * 所有插件的主类（plugins/<name>/Plugin.php）都必须继承此类。
 * 子类需要声明 $name / $title / $version / $author / $description 等基本信息，
 * 并按需重写 install() / uninstall() / enable() / disable() 以及各钩子回调方法。
 */
abstract class PluginBase
{
    /**
     * 插件标识（目录名），不填则从类名自动推断
     * @var string
     */
    protected string $name = '';

    /**
     * 插件名称（显示用）
     * @var string
     */
    protected string $title = '';

    /**
     * 插件版本
     * @var string
     */
    protected string $version = '1.0.0';

    /** 插件最低兼容的 MiuCMS 版本；留空表示不限制，例如 1.0.10。 */
    protected string $minCmsVersion = '';

    /**
     * 插件作者
     * @var string
     */
    protected string $author = '';

    /** 作者官网（可选，建议使用完整的 HTTP/HTTPS URL）。 */
    protected string $authorUrl = '';

    /**
     * 插件描述
     * @var string
     */
    protected string $description = '';

    /**
     * 插件图标类名，如 layui-icon-star、miu-tongji-o 或 miuai-AI。
     * 加后台菜单时会用这个图标；留空则用默认的 layui-icon-star。
     * @var string
     */
    protected string $icon = 'layui-icon-star';

    /**
     * 当前插件配置（由 PluginManager 注入，键值来自后台「插件配置」保存的内容）
     * @var array
     */
    protected array $config = [];

    /**
     * 构造方法
     */
    public function __construct()
    {
        //插件标识没写就按类名推断：plugins\demo\Plugin -> demo
        if ($this->name === '') {
            $this->name = $this->guessName();
        }
    }

    /**
     * 从类名推断插件标识
     * @return string
     */
    protected function guessName(): string
    {
        if (preg_match('/^plugins\\\\(.+)\\\\Plugin$/', static::class, $m)) {
            return strtolower($m[1]);
        }
        return '';
    }

    /**
     * 插件标识
     * @return string
     */
    public function getName(): string
    {
        return $this->name;
    }

    /**
     * 插件基本信息（后台列表用）
     * @return array
     */
    public function getInfo(): array
    {
        return [
            'name'        => $this->name,
            'title'       => $this->title,
            'version'     => $this->version,
            'min_cms_version' => $this->minCmsVersion,
            'author'      => $this->author,
            'author_url'  => $this->authorUrl,
            'description' => $this->description,
            'icon'        => $this->getIcon(),
        ];
    }

    /**
     * 返回插件图标类名，留空或格式无效时使用默认图标。
     * @return string
     */
    public function getIcon(): string
    {
        $icon = trim($this->icon);
        if (!preg_match('/\A(?:(?:layui-icon|miu)-[a-z0-9-]+|miuai-[A-Za-z0-9_-]+)\z/', $icon)) {
            return 'layui-icon-star';
        }
        return $icon;
    }

    /**
     * 配置项定义（后台「插件配置」表单按这个渲染）
     *
     * 每一项支持：name / title / type(text|textarea|switch|number|select) /
     * default / placeholder / tips / options(select 用，[['value'=>..,'label'=>..]])
     * @return array
     */
    public function getConfigFields(): array
    {
        return [];
    }

    /**
     * 注入已保存的配置（PluginManager 实例化插件后调用）
     * @param array $config
     * @return void
     */
    public function setConfig(array $config): void
    {
        $this->config = $config;
    }

    /**
     * 取某项配置
     * @param string $key
     * @param mixed  $default
     * @return mixed
     */
    public function getConfig(string $key, mixed $default = null): mixed
    {
        return $this->config[$key] ?? $default;
    }

    /**
     * 全部配置
     * @return array
     */
    public function getConfigs(): array
    {
        return $this->config;
    }

    // ========== 生命周期（子类按需重写）==========

    /**
     * 安装：返回 true 表示成功，返回字符串表示失败原因。
     * 此方法是插件 PHP 代码，在 CMS 进程和数据库账号权限下运行；SQL 文件的表前缀校验不会限制此回调。
     * @return bool|string
     */
    public function install(): bool|string
    {
        return true;
    }

    /**
     * 卸载。此方法是插件 PHP 代码，在 CMS 进程和数据库账号权限下运行；SQL 文件的表前缀校验不会限制此回调。
     * @return bool|string
     */
    public function uninstall(): bool|string
    {
        return true;
    }

    /**
     * 启用
     * @return bool|string
     */
    public function enable(): bool|string
    {
        return true;
    }

    /**
     * 禁用
     * @return bool|string
     */
    public function disable(): bool|string
    {
        return true;
    }

    // ========== 钩子回调（子类按需重写；参数带 & 的可以就地改数据）==========
    // 完整清单见 Hook::HOOK_LIST / 后台「插件 → 钩子管理」

    // ---- 生命周期 ----

    /** 应用初始化（每次请求都会触发） */
    public function appInit(): void {}

    /** 模块加载（参数：模块名 home/admin/api…） */
    public function moduleInit(string $module): void {}

    /** 控制器执行前（参数：控制器、方法） */
    public function actionBegin(string $controller, string $action): void {}

    /** 控制器执行后（参数：控制器、方法、响应对象） */
    public function actionEnd(string $controller, string $action, mixed $result): void {}

    /** 模板解析完成、输出前（$content 是整页 HTML，可修改） */
    public function templateParse(string &$content): void {}

    /** 前台 HTTP 响应准备完毕，可读取状态或修改输出。 */
    public function frontendResponse(\think\Request $request, \think\Response $response, string $url): void {}

    /** 静态页面落盘前，可修改生成结果。 */
    public function staticPageReady(string &$html): void {}

    /** 为后台首页追加插件卡片和快捷入口。 */
    public function adminDashboard(string &$html, string &$shortcuts): void {}

    // ---- 文档 ----

    /** 文档添加前（$data 可修改） */
    public function documentAdd(array &$data): void {}

    /** 文档添加后（参数：新文档ID、数据） */
    public function documentAdded(int $id, array $data): void {}

    /** 文档编辑前（$data 可修改） */
    public function documentEdit(array &$data): void {}

    /** 文档编辑后（参数：文档ID、数据） */
    public function documentEdited(int $id, array $data): void {}

    /** 文档列表操作列扩展（$actions 可追加按钮定义） */
    public function documentListAction(array &$actions): void {}

    /** 自定义变量列表操作列扩展（$row 为当前变量，$actions 可追加按钮定义） */
    public function customListAction(array $row, array &$actions): void {}

    /** 标签列表操作列扩展（$actions 可追加按钮定义） */
    public function tagListAction(array &$actions): void {}

    /** 后台列表工具栏扩展（$listType 为列表标识，$actions 可追加按钮定义） */
    public function adminListToolbarAction(string $listType, array &$actions): void {}

    /** 文档删除前（参数：文档ID 或 ID 数组） */
    public function documentDelete(mixed $id): void {}

    /** 文档从回收站恢复（参数：文档ID 或 ID 数组） */
    public function documentRestore(mixed $id): void {}

    /** 文档彻底删除前（回收站里物理删除，参数：文档ID 或 ID 数组） */
    public function documentPurge(mixed $id): void {}

    /** 文档审核/字段变更（参数：文档ID数组、字段名、新值；后台列表的审核、置顶、推荐等） */
    public function documentExamine(array $ids, string $field, mixed $value): void {}

    // ---- 栏目 ----

    /** 栏目列表操作列扩展（$actions 可追加按钮定义） */
    public function navListAction(array &$actions): void {}

    /** 栏目添加前（$data 可修改） */
    public function navAdd(array &$data): void {}

    /** 栏目添加后（参数：新栏目ID、数据） */
    public function navAdded(int $id, array $data): void {}

    /** 栏目编辑前（$data 可修改） */
    public function navEdit(array &$data): void {}

    /** 栏目编辑后（参数：栏目ID、数据） */
    public function navEdited(int $id, array $data): void {}

    /** 栏目删除前（参数：栏目ID 或 ID 数组） */
    public function navDelete(mixed $id): void {}

    // ---- 会员 ----

    /** 用户注册（$data 可修改） */
    public function userRegister(array &$data): void {}

    /** 用户注册成功，参数为已保存的用户 ID。 */
    public function userRegistered(int $id): void {}

    /** 用户登录（$user 可修改） */
    public function userLogin(array &$user): void {}

    /** 用户退出（参数：当前登录用户信息） */
    public function userLogout(array $user): void {}

    /** 用户资料修改（$data 可修改） */
    public function userUpdate(array &$data): void {}

    // ---- 后台管理员 ----

    /** 管理员登录（$admin 可修改） */
    public function adminLogin(array &$admin): void {}

    /** 管理员退出（参数：当前登录管理员信息） */
    public function adminLogout(array $admin): void {}

    // ---- 搜索 ----

    /** 搜索前（$keywords 可修改） */
    public function searchBefore(string &$keywords): void {}

    /** 搜索后（$result 可修改） */
    public function searchAfter(array &$result): void {}

    // ---- 留言 / 表单 ----

    /** 留言提交前（$data 可修改；后台开启审核时数据里带 examine） */
    public function formSubmit(array &$data): void {}

    /** 留言提交后（参数：新留言ID、数据） */
    public function formSubmitted(int $id, array $data): void {}

    /** 留言审核（参数：留言ID数组、审核结果 1 通过 / 0 不通过） */
    public function formExamine(array $ids, int $examine): void {}

    /** 留言回复（参数：留言ID、回复内容） */
    public function formReply(int $id, string $reply): void {}

    /** 留言删除前（参数：留言ID 或 ID 数组） */
    public function formDelete(mixed $ids): void {}

    // ---- 文件 ----

    /** 文件上传后（参数：文件信息数组，含 filepath/name 等） */
    public function fileUpload(array $file): void {}
}
