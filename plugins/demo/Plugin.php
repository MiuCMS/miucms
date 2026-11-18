<?php
/**
 * 演示插件
 *
 * 这是一个可安装、可运行的插件开发示例。建议按这个顺序阅读：
 *   1. 本文件：插件信息、配置字段、生命周期和自动发现的钩子
 *   2. hooks.php：用配置文件把钩子映射到自定义方法
 *   3. admin/：后台菜单、控制器、首页与自定义配置表单
 *   4. route.php、controller/、view/：前台路由和页面
 *   5. taglib/：在前台模板里使用的模板标签
 *   6. install.sql、uninstall.sql：插件自己的数据表
 */

declare(strict_types=1);

namespace plugins\demo;

use app\extend\plugin\PluginBase;
use think\facade\Db;

class Plugin extends PluginBase
{
    protected string $name        = 'demo';
    protected string $title       = '演示插件';
    protected string $version     = '1.0.0';
    // 插件最低兼容的 MiuCMS 版本；留空表示不限制，例如 '1.0.10'。
    protected string $minCmsVersion = '';
    protected string $author      = '米柚';
    protected string $authorUrl   = 'https://www.miucms.com';
    protected string $description = '插件开发完整示例：配置、后台页面、钩子、前台路由、模板标签和数据表';
    protected string $icon        = 'layui-icon-star';

    /**
     * 插件配置项定义。
     * 系统插件配置页会根据这里的 type 自动渲染表单；自定义配置页见 admin/view/config.html。
     * 支持 type：text / textarea / switch / number / radio / select / password / help_area。
     * 常见字段：name 是配置键，title 是显示名，default 是默认值，placeholder 和 tips 是输入提示，
     * options 定义 radio/select 选项，depends_on 定义依赖显示关系；password 可用 preserve_empty 保留空提交的旧值。
     */
    public function getConfigFields(): array
    {
        $help = <<<'MD'
## 演示插件开发指南

这个插件把常见开发入口放在一起，适合安装后逐个页面查看。配置不会自动生效，插件代码需要通过 `getConfig()` 读取它。

### 从哪里开始

1. `Plugin.php`：填写插件标识、名称、版本、作者和描述；在 `getConfigFields()` 声明设置项。
2. `admin/menu.php`：声明插件后台菜单。插件安装后，到插件管理点击“添加到主菜单”才会写入后台菜单。
3. `admin/controller/Index.php`：继承 `PluginAdminBase`，读取配置或数据库，再调用 `$this->render()` 显示插件自己的视图。
4. `admin/view/`：后台首页和自定义配置表单。配置表单只提交已在 `getConfigFields()` 声明的字段。
5. `hooks.php`、`Plugin.php`：演示两种钩子注册方式。不要对同一个钩子同时映射和自动声明，否则回调会执行两次。
6. `route.php`、`controller/`、`view/`：前台路由、控制器和模板。
7. `taglib/Demo.php`：模板标签定义；启用插件后可在前台模板写 `{demo:hello name='访客' /}`。
8. `install.sql`、`uninstall.sql`：仅管理插件自己的表，表名使用 `__PREFIX__`。

### 配置字段类型示例

本插件示范 `text`、`textarea`、`switch`、`number`、`radio`、`select`、`password` 和 `help_area`。`depends_on` 可按另一个字段的值显示相关设置。

密码字段开启 `preserve_empty` 后不会回显保存值，空着保存表示保留原值；不要把密钥输出到页面或日志。

### 插件页面地址

- 后台首页：插件菜单 → 插件首页。
- 自定义配置演示：插件菜单 → 插件配置。
- 前台页面：安装并启用后访问 `/demo/hello?name=访客`。

### 钩子说明

- `documentAdded()` 由方法名自动对应 `document_added`，演示发布文档后写入插件数据表。
- `templateParse()` 演示按配置修改最终 HTML。
- `userLogin()` 演示引用参数；对 `$user` 的修改只影响当前钩子调用链，不代表写入数据库。
- `hooks.php` 把 `search_before` 映射到 `onSearchBefore()`，演示自定义方法名的注册方式。
- 完整钩子清单和参数以后台“插件 → 钩子管理”及 `PluginBase` 为准。
MD;

        return [
            // 帮助区域只展示 Markdown，不作为普通设置提交保存。
            [
                'name'    => 'help_area', // 固定字段名：系统据此识别插件配置帮助区。
                'title'   => '开发说明', // 帮助区标题。
                'type'    => 'help_area', // Markdown 展示区，不会作为普通输入项保存。
                'default' => $help, // 本插件的目录说明、功能入口和安全提示。
            ],
            [
                'name'        => 'site_name', // 配置键名；插件代码通过 getConfig('site_name') 读取。
                'title'       => '演示站点名称', // 系统配置表单中显示的名称。
                'type'        => 'text', // 单行文本输入框。
                'default'     => '我的演示站点', // 尚未设置时的默认值。
                'placeholder' => '例如：米柚演示站', // 输入框为空时的提示。
                'tips'        => '会显示在前台演示页，也可从后台控制器读取。', // 帮助提示。
            ],
            [
                'name'        => 'welcome_msg', // 配置键名。
                'title'       => '欢迎语', // 配置项标题。
                'type'        => 'textarea', // 多行文本输入框。
                'default'     => '欢迎访问插件前台演示页。', // 默认欢迎语。
                'placeholder' => '填写一段前台欢迎语', // 空值提示。
                'tips'        => '演示 textarea 类型；模板输出时应使用自动转义，避免把输入内容当 HTML 执行。', // 安全说明。
            ],
            [
                'name'    => 'greeting_style', // 配置键名。
                'title'   => '欢迎语风格', // 配置项标题。
                'type'    => 'select', // 下拉单选框。
                'default' => 'friendly', // 默认选项值。
                'options' => [ // value 保存到配置，label 展示给管理员。
                    ['value' => 'friendly', 'label' => '友好亲切'],
                    ['value' => 'brief', 'label' => '简洁直接'],
                    ['value' => 'professional', 'label' => '专业正式'],
                ],
                'tips'    => '前台控制器读取选项值，并映射为对应的展示文案。', // 用法说明。
            ],
            [
                'name'    => 'enable_footer', // 配置键名。
                'title'   => '启用页脚注入', // 配置项标题。
                'type'    => 'switch', // 开关控件，保存为 1 或 0。
                'default' => 0, // 默认关闭，避免安装后自动改动所有页面。
                'tips'    => '开启后 templateParse() 会在前台 HTML 的 body 结束标签前插入一行文字。', // 说明对应钩子行为。
            ],
            [
                'name'        => 'footer_text', // 配置键名。
                'title'       => '页脚文字', // 配置项标题。
                'type'        => 'text', // 单行文本输入框。
                'default'     => '本页由演示插件提供', // 默认页脚文字。
                'placeholder' => '请输入页脚内容', // 空值提示。
                'depends_on'  => ['field' => 'enable_footer', 'value' => '1'], // 仅启用页脚注入时显示。
                'tips'        => '输出前会进行 HTML 转义，不会把这里的文本当成 HTML 执行。', // 安全说明。
            ],
            [
                'name'       => 'footer_align', // 配置键名。
                'title'      => '页脚对齐方式', // 配置项标题。
                'type'       => 'radio', // 单选按钮组。
                'default'    => 'center', // 默认居中。
                'depends_on' => ['field' => 'enable_footer', 'value' => '1'], // 仅启用页脚注入时显示。
                'options'    => [ // 可选值白名单也会在插件代码中校验。
                    ['value' => 'left', 'label' => '左对齐'],
                    ['value' => 'center', 'label' => '居中'],
                    ['value' => 'right', 'label' => '右对齐'],
                ],
                'tips'       => '演示 radio 类型；使用配置值前仍应按允许值校验。', // 用法说明。
            ],
            [
                'name'        => 'record_limit', // 配置键名。
                'title'       => '首页记录数量', // 配置项标题。
                'type'        => 'number', // 数字输入框。
                'default'     => 10, // 默认显示最近 10 条记录。
                'placeholder' => '1 到 100', // 输入提示。
                'tips'        => '控制后台首页显示多少条文档记录；控制器会将范围限制在 1 到 100。', // 说明边界处理。
            ],
            [
                'name'        => 'example_text', // 配置键名；与自定义配置演示页中的字段对应。
                'title'       => '自定义配置示例', // 配置项标题。
                'type'        => 'text', // 单行文本输入框。
                'default'     => '这是一个插件配置示例', // 默认示例内容。
                'placeholder' => '输入任意示例文字', // 空值提示。
                'tips'        => '用于演示插件自带配置页如何读取和保存字段；也可在代码中用 getConfig() 读取。', // 用法说明。
            ],
            [
                'name'                  => 'demo_api_key', // 配置键名；演示密钥字段，不连接真实服务。
                'title'                 => '示例 API Key', // 配置项标题。
                'type'                  => 'password', // 密码输入框，后台不回显已保存内容。
                'default'               => '', // 默认没有密钥。
                'preserve_empty'        => true, // 空提交保留旧值，输入新值才替换。
                'configured_placeholder' => '已配置；留空表示不修改', // 已配置时显示，不显示密钥本身。
                'placeholder'           => '仅用于演示，可留空', // 未配置时的提示。
                'tips'                  => '此字段仅演示密码配置方式，不会调用外部服务。真实项目不要在页面或日志中输出 API Key。', // 安全说明。
            ],
            [
                'name'    => 'enable_search_trim', // 配置键名。
                'title'   => '演示搜索词清理', // 配置项标题。
                'type'    => 'switch', // 开关控件。
                'default' => 0, // 默认关闭，不改变站内搜索行为。
                'tips'    => '开启后 hooks.php 注册的 search_before 示例会去掉搜索词首尾空格。', // 说明映射钩子的实际效果。
            ],
        ];
    }

    // ==================== 插件生命周期 ====================

    /** 安装时执行；install.sql 已负责建表，不在此处重复建表。 */
    public function install(): bool|string
    {
        trace('演示插件：安装完成', 'info');
        return true;
    }

    /** 卸载时执行；uninstall.sql 已负责删除本插件的数据表。 */
    public function uninstall(): bool|string
    {
        trace('演示插件：已卸载', 'info');
        return true;
    }

    /** 插件启用回调；返回字符串可以作为失败原因。 */
    public function enable(): bool|string
    {
        trace('演示插件：已启用', 'info');
        return true;
    }

    /** 插件禁用回调。 */
    public function disable(): bool|string
    {
        trace('演示插件：已禁用', 'info');
        return true;
    }

    // ==================== 自动发现的钩子 ====================

    /**
     * 模板解析完成、页面输出前执行。方法名 templateParse 对应 template_parse。
     * 修改整页内容时尽量限定范围、转义用户配置，并避免重复插入。
     */
    public function templateParse(string &$content): void
    {
        if (!$this->getConfig('enable_footer') || strpos($content, 'plugin-demo-footer') !== false) {
            return;
        }

        $text = trim((string) $this->getConfig('footer_text', ''));
        if ($text === '') {
            return;
        }

        $align = (string) $this->getConfig('footer_align', 'center');
        if (!in_array($align, ['left', 'center', 'right'], true)) {
            $align = 'center';
        }
        $safeText = htmlspecialchars($text, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
        $html = '<div class="plugin-demo-footer" style="text-align:' . $align . ';padding:10px;color:#999;font-size:12px;">'
            . $safeText . '</div>';

        if (stripos($content, '</body>') !== false) {
            $content = str_ireplace('</body>', $html . '</body>', $content);
        }
    }

    /**
     * 文档发布后执行。方法名 documentAdded 对应 document_added。
     * 这里把数据写入插件自己的表；数据库错误只记录日志，不影响文档发布流程。
     */
    public function documentAdded(int $id, array $data): void
    {
        try {
            Db::name('demo_record')->insert([
                'doc_id'     => $id,
                'doc_title'  => (string) ($data['title'] ?? ''),
                'created_at' => time(),
            ]);
        } catch (\Throwable $e) {
            trace('演示插件：记录文档失败 ' . $e->getMessage(), 'error');
        }
    }

    /**
     * 用户登录后执行。user 参数按引用传入；本示例只给当前调用链增加展示字段，未写数据库。
     */
    public function userLogin(array &$user): void
    {
        $user['last_login_plugin'] = date('Y-m-d H:i:s');
    }

    // hooks.php 将 search_before 映射到这个自定义方法名。
    public function onSearchBefore(string &$keywords): void
    {
        if ($this->getConfig('enable_search_trim')) {
            $keywords = trim($keywords);
        }
    }
}
