<?php
/**
 * @copyright Copyright (c) 2026 米柚工作室
 * @license https://opensource.org/licenses/Apache-2.0
 * @link https://www.miucms.com
 */

declare(strict_types=1);

namespace app\extend\plugin;

/**
 * 插件骨架生成器
 *
 * 按后台「插件开发」页填的信息，在 plugins/<标识>/ 下生成一套可直接用的插件骨架，
 * 生成完去「插件管理」点安装即可。
 *
 * 生成的文件全部用 nowdoc 模板 + 占位符替换，避免模板里的 $ 和 \ 被 PHP 解析。
 */
class PluginScaffold
{
    /**
     * 生成插件骨架
     *
     * @param array $opt 选项：
     *   name        插件标识（必须，字母开头，字母/数字/下划线）
     *   title       插件名称
     *   version     版本号
     *   author      作者
     *   author_url  作者官网（可选，仅接受 HTTP/HTTPS URL）
     *   description 描述
     *   help_area   插件配置页帮助说明（Markdown）
     *   with_admin  是否生成后台菜单+控制器+视图
     *   with_front  是否生成前台路由+控制器+视图
     *   with_taglib 是否生成模板标签
     *   with_sql    是否生成 install.sql / uninstall.sql
     *   with_hooks  是否生成钩子回调示例
     * @return array ['code'=>0成功/1失败, 'msg'=>消息, 'files'=>生成的文件列表]
     */
    public function generate(array $opt): array
    {
        $name = trim((string) ($opt['name'] ?? ''));
        //标识校验交给 PluginManager：字符格式 + 不能是系统保留词 + 不能被已有插件占用
        $nameErr = PluginManager::instance()->checkName($name);
        if ($nameErr !== '') {
            return ['code' => 1, 'msg' => $nameErr];
        }

        $title = trim((string) ($opt['title'] ?? ''));
        if ($title === '') {
            return ['code' => 1, 'msg' => '请填写插件名称'];
        }

        $authorUrl = trim((string) ($opt['author_url'] ?? ''));
        if ($authorUrl !== '') {
            $authorUrlParts = parse_url($authorUrl);
            if (
                strlen($authorUrl) > 2048
                || filter_var($authorUrl, FILTER_VALIDATE_URL) === false
                || !is_array($authorUrlParts)
                || !in_array(strtolower((string) ($authorUrlParts['scheme'] ?? '')), ['http', 'https'], true)
                || empty($authorUrlParts['host'])
            ) {
                return ['code' => 1, 'msg' => '作者官网请填写有效的 HTTP 或 HTTPS 网址'];
            }
        }

        $pm   = PluginManager::instance();
        $root = $pm->getPluginPath() . $name;
        if (is_dir($root)) {
            return ['code' => 1, 'msg' => "插件目录 plugins/{$name} 已存在，换个标识或先删掉它"];
        }

        //单引号字符串里用，先把单引号和反斜杠转义掉
        $esc = static fn(string $s): string => str_replace(['\\', "'"], ['\\\\', "\\'"], $s);
        $version = trim((string) ($opt['version'] ?? '')) ?: '1.0.0';
        $author = trim((string) ($opt['author'] ?? '')) ?: '未知';
        $description = trim((string) ($opt['description'] ?? '')) ?: $title;

        //兼容 Layui 图标和当前图标库的 miuai- 自定义图标；类名只允许字母、数字、下划线和连字符。
        $icon = trim((string) ($opt['icon'] ?? ''));
        if (!$icon || $icon === '') {
            $icon = 'layui-icon-star';
        }

        $map = [
            '{{NAME}}'    => $name,
            //PHP 单引号字符串里用（$esc 只转义 \ 和 '，够用）
            '{{TITLE}}'   => $esc($title),
            '{{CLASS}}'   => ucfirst($name),
            '{{VERSION}}' => $esc($version),
            '{{AUTHOR}}'  => $esc($author),
            '{{AUTHOR_URL}}' => $esc($authorUrl),
            '{{DESC}}'    => $esc($description),
            '{{ICON}}'    => $esc($icon),
            //HTML 上下文用（生成视图里的 <title> / <h1> 等）：不做转义就能塞 <script>
            '{{HTML_TITLE}}'  => htmlspecialchars($title, ENT_QUOTES, 'UTF-8'),
            //注释 / SQL 上下文用：去掉能破坏 PHP 注释块（*/ 会提前结束注释、后面就成代码了）
            //或 SQL 语句（引号/分号）的字符
            '{{PLAIN_TITLE}}' => str_replace(["*/", "/*", "'", '"', '`', '\\', ';', '--', "\r", "\n"], '', $title),
            //最后替换，避免帮助正文中出现的占位符被二次展开。
            '{{HELP_AREA}}'   => $esc((string) ($opt['help_area'] ?? '')),
        ];

        //README 元信息单独替换且放在最后，避免用户填写的标题/简介被解释成骨架占位符。
        $readmeText = static fn(string $value): string => htmlspecialchars(str_replace(["\r", "\n"], ' ', $value), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
        $readmeMetadata = implode("\n", [
            '# ' . $readmeText($title),
            '',
            '- 插件标识：`' . $name . '`',
            '- 版本：' . $readmeText($version),
            '- 作者：' . $readmeText($author),
            '- 作者官网：' . ($authorUrl !== '' ? '<' . $readmeText($authorUrl) . '>' : '未填写'),
            '- 简介：' . $readmeText(str_replace(["\r", "\n"], ' ', $description)),
        ]) . "\n";

        //要生成哪些文件
        $files = [];
        $files['Plugin.php'] = $this->render($this->tplPlugin($opt), $map);
        $files['README.md'] = $this->render($this->tplReadme(), [
            '{{NAME}}'            => $name,
            '{{CLASS}}'           => ucfirst($name),
            '{{README_METADATA}}' => $readmeMetadata,
        ]);

        if (!empty($opt['with_admin'])) {
            $files['admin/menu.php']              = $this->render($this->tplAdminMenu(), $map);
            $files['admin/controller/Index.php']  = $this->render($this->tplAdminController(), $map);
            $files['admin/view/index.html']       = $this->render($this->tplAdminView(), $map);
            $files['admin/view/config.html']      = $this->render($this->tplAdminConfigView(), $map);
        }
        if (!empty($opt['with_front'])) {
            $files['route.php']             = $this->render($this->tplRoute(), $map);
            $files['controller/Index.php']  = $this->render($this->tplFrontController(), $map);
            $files['view/index.html']       = $this->render($this->tplFrontView(), $map);
        }
        if (!empty($opt['with_taglib'])) {
            $files['taglib/{{CLASS}}.php'] = $this->render($this->tplTaglib(), $map);
        }
        if (!empty($opt['with_sql'])) {
            $files['install.sql']   = $this->render($this->tplInstallSql(), $map);
            $files['uninstall.sql'] = $this->render($this->tplUninstallSql(), $map);
        }

        //落盘
        try {
            if (!is_dir($root) && !mkdir($root, 0755, true)) {
                return ['code' => 1, 'msg' => '创建插件目录失败，请检查 plugins 目录权限'];
            }
            $written = [];
            foreach ($files as $rel => $content) {
                //文件名里也可能有占位符（比如 taglib/{{CLASS}}.php），一并替换
                $rel  = $this->render($rel, $map);
                $full = $root . DIRECTORY_SEPARATOR . str_replace('/', DIRECTORY_SEPARATOR, $rel);
                $dir  = dirname($full);
                if (!is_dir($dir) && !mkdir($dir, 0755, true)) {
                    return ['code' => 1, 'msg' => "创建目录失败：{$dir}"];
                }
                if (file_put_contents($full, $content) === false) {
                    return ['code' => 1, 'msg' => "写入文件失败：{$rel}"];
                }
                $written[] = $rel;
            }
        } catch (\Throwable $e) {
            return ['code' => 1, 'msg' => '生成失败：' . $e->getMessage()];
        }

        return [
            'code'  => 0,
            'msg'   => "插件 {$name} 骨架已生成，去列表里点「安装」即可",
            'files' => $written,
        ];
    }

    /**
     * 占位符替换
     * @param string $tpl
     * @param array  $map
     * @return string
     */
    protected function render(string $tpl, array $map): string
    {
        return str_replace(array_keys($map), array_values($map), $tpl);
    }

    // ==================== 各文件模板 ====================

    protected function tplPlugin(array $opt): string
    {
        $hooks = '';
        if (!empty($opt['with_hooks'])) {
            $hooks = <<<'PHP'

    // ==================== 钩子回调（按需保留/删除）====================
    // 方法名 = 钩子名的驼峰形式；完整钩子清单见 后台 → 插件 → 钩子管理

    /**
     * 文档发布后
     */
    public function documentAdded(int $id, array $data): void
    {
        // $data['title'] 是新文档标题，$id 是文档ID
    }

    /**
     * 用户登录后（$user 是引用，改了会带到调用方）
     */
    public function userLogin(array &$user): void
    {
        // $user['xxx'] = 'yyy';
    }

    /**
     * 模板解析完成、输出前（$content 是整页 HTML，可插入内容）
     */
    public function templateParse(string &$content): void
    {
        // $content = str_replace('</body>', '<div>自定义内容</div></body>', $content);
    }
PHP;
        }

        return <<<'PHP'
<?php
/**
 * {{PLAIN_TITLE}}
 *
 * 插件主类。基本信息 + 配置项 + 生命周期 + 钩子回调都在这里。
 */

declare(strict_types=1);

namespace plugins\{{NAME}};

use app\extend\plugin\PluginBase;

class Plugin extends PluginBase
{
    protected string $name        = '{{NAME}}';
    protected string $title       = '{{TITLE}}';
    protected string $version     = '{{VERSION}}';
    // 插件最低兼容的 MiuCMS 版本；留空表示不限制，例如 '1.0.10'。开发者需自行测试好支持的最低版本，并填写该值
    protected string $minCmsVersion = '';
    protected string $author      = '{{AUTHOR}}';
    protected string $authorUrl   = '{{AUTHOR_URL}}'; //作者官网，只填写完整的 HTTP/HTTPS 网址；可留空。
    protected string $description = '{{DESC}}';
    protected string $icon        = '{{ICON}}';

    /**
     * 配置项定义（后台「插件管理 → 配置」按这个渲染）
     * 支持 type：text / textarea / switch / number / radio / select / password / help_area
     *
     * 每个配置字段的常用键：
     * name        配置键名，也是 getConfig() 读取时使用的键
     * title       配置页显示名称
     * type        表单控件类型；支持 text / textarea / switch / number / radio / select / password / help_area
     * default     首次未保存时使用的默认值
     * depends_on  可选；依赖字段及其匹配值，条件不满足时隐藏该项
     * options     可选；radio/select 的选项，每项用 value 保存、label 显示
     * tips        可选；配置项下方的说明文字
     */
    public function getConfigFields(): array
    {
        return [
            // 插件配置页里的帮助说明区域，填写 Markdown 原文；只展示，不作为普通设置保存。
            [
                'name'    => 'help_area', // 配置键名；系统用它识别只读帮助区域。
                'title'   => '插件帮助说明', // 帮助区域的标题。
                'type'    => 'help_area', // 固定使用 help_area 类型；后台会将 Markdown 安全渲染后展示。
                'default' => '{{HELP_AREA}}', // 创建插件时填写的 Markdown 帮助正文。
            ],
            // 示例配置字段。新增字段时，按上面的说明填写并为每个键保留解释注释。
            [
                'name'    => 'example_text', // 配置键名；在插件代码中用 $this->getConfig('example_text') 读取。
                'title'   => '示例配置',     // 配置页展示给管理员看的字段名称。
                'type'    => 'text',         // 表单控件类型；这里使用单行文本输入框。
                'default' => '',             // 尚未保存配置时使用的初始值。
                'tips'    => '改成你自己的配置项；用 $this->getConfig(\'example_text\') 取', // 显示在输入项下方的填写说明。
            ],
        ];
    }

    // ==================== 生命周期（按需重写）====================

    // public function install(): bool|string   { return true; }   // 返回字符串表示失败原因
    // public function uninstall(): bool|string { return true; }
    // public function enable(): bool|string    { return true; }
    // public function disable(): bool|string   { return true; }
PHP
            . $hooks
            . <<<'PHP'

}
PHP;
    }

    protected function tplAdminMenu(): string
    {
        return <<<'PHP'
<?php
/**
 * {{PLAIN_TITLE}} - 后台菜单
 *
 * 安装插件时**不会**自动加菜单，装完去「插件管理」列表点「添加后台菜单」按钮才会写入
 * cms_admin_rule 并授权给超级管理员组；禁用插件菜单自动隐藏，卸载自动删除。
 *
 * 菜单图标用插件类里声明的 $icon（见 Plugin.php，创建插件时选的）；这里写 icon 不生效。排序固定 100。
 *
 * url 指向插件自己的后台页面时用约定地址：
 *   admin/plugin/run?name={{NAME}}&c=<控制器>&a=<方法>   （c 默认 Index，a 默认 index）
 */

return [
    [
        'title'    => '{{TITLE}}',
        'url'      => '',
        'children' => [
            [
                'title' => '{{TITLE}}首页',
                'url'   => 'admin/plugin/run?name={{NAME}}&a=index',
            ],
            [
                'title' => '{{TITLE}}插件配置',
                'url'   => 'admin/plugin/run?name={{NAME}}&a=config',
            ],
        ],
    ],
];
PHP;
    }

    protected function tplAdminController(): string
    {
        return <<<'PHP'
<?php
/**
 * {{PLAIN_TITLE}} - 后台控制器
 *
 * 访问地址：admin/plugin/run?name={{NAME}}&a=index
 * 继承 PluginAdminBase：自带后台登录校验，并能用 $this->render() 渲染插件自己的视图
 */

declare(strict_types=1);

namespace plugins\{{NAME}}\admin\controller;

use app\extend\plugin\PluginAdminBase;
use app\extend\plugin\PluginManager;

class Index extends PluginAdminBase
{
    /**
     * 插件标识
     * @var string
     */
    protected string $plugin = '{{NAME}}';

    /**
     * 插件后台首页
     */
    public function index()
    {
        $config = PluginManager::instance()->getPluginConfig('{{NAME}}');
        return $this->render('index', [
            'config' => $config,
        ]);
    }

    /**
     * 插件配置演示页
     */
    public function config()
    {
        $config = PluginManager::instance()->getPluginConfig('{{NAME}}');
        return $this->render('config', [
            'config' => $config,
        ]);
    }
}
PHP;
    }

    protected function tplAdminView(): string
    {
        return <<<'HTML'
<!DOCTYPE html>
<html>
<head>
	<meta charset="utf-8">
	<meta name="renderer" content="webkit">
	<meta name="viewport" content="width=device-width, initial-scale=1, maximum-scale=1">
	<title>{{HTML_TITLE}}</title>
	<link rel="stylesheet" href="{__ADMIN__}/css/miu.css">
	<script src="{__ADMIN__}/layui/layui.js"></script>
</head>
<body>
<div class="p-3">
	<div class="layui-card">
		<div class="layui-card-header">{{HTML_TITLE}} - 插件首页</div>
		<div class="layui-card-body">
			<blockquote class="layui-elem-quote">
				这是插件自己的后台页面，视图在 <code>plugins/{{NAME}}/admin/view/index.html</code>，
				控制器在 <code>plugins/{{NAME}}/admin/controller/Index.php</code>。
			</blockquote>

			<h3 style="margin:15px 0 10px;">当前配置</h3>
			<table class="layui-table">
				<tbody>
					{volist name="config" id="vo"}
					<tr><th style="width:200px;">{$key}</th><td>{$vo}</td></tr>
					{/volist}
				</tbody>
			</table>
		</div>
	</div>
</div>
</body>
</html>
HTML;
    }

    protected function tplAdminConfigView(): string
    {
        return <<<'HTML'
<!DOCTYPE html>
<html>
<head>
	<meta charset="utf-8">
	<meta name="renderer" content="webkit">
	<meta name="viewport" content="width=device-width, initial-scale=1, maximum-scale=1">
	<title>{{HTML_TITLE}} - 插件配置</title>
	<link rel="stylesheet" href="{__ADMIN__}/css/miu.css">
	<script src="{__ADMIN__}/layui/layui.js"></script>
</head>
<body>
<div class="p-3">
	<div class="layui-card">
		<div class="layui-card-header">{{HTML_TITLE}} - 插件配置演示</div>
		<div class="layui-card-body">
			<blockquote class="layui-elem-quote">
				这是插件自带配置表单的演示页面。表单示例会读取并保存 <code>example_text</code>；其他配置项仍可点击「插件管理」列表中的「配置」按钮设置，系统会根据 <code>Plugin.php</code> 的 <code>getConfigFields()</code> 自动生成配置表单。
			</blockquote>
			<form class="layui-form" lay-filter="pluginConfigDemoForm">
				<input type="hidden" name="name" value="{{NAME}}">
				<div class="layui-form-item">
					<label class="layui-form-label">示例配置</label>
					<div class="layui-input-block">
						<input type="text" name="example_text" value="{$config.example_text|default=''}" placeholder="请输入示例配置内容" class="layui-input">
					</div>
				</div>
				<div class="layui-form-item">
					<div class="layui-input-block">
						<button type="button" class="layui-btn" lay-submit lay-filter="savePluginConfigDemo">保存示例配置</button>
						<span class="layui-font-gray plugin-config-demo-current" style="margin-left:8px;">当前值：{$config.example_text|default='尚未设置'}</span>
					</div>
				</div>
			</form>
			<script>
			layui.use(['form', 'layer'], function () {
				var form = layui.form;
				var layer = layui.layer;
				var $ = layui.$;
				form.on('submit(savePluginConfigDemo)', function (data) {
					$.post("{:url('admin/plugin/save_config')}", data.field, function (res) {
						layer.msg(res.msg, { icon: res.code === 0 ? 1 : 2 });
						if (res.code === 0) {
							var value = data.field.example_text || '尚未设置';
							$('.plugin-config-demo-current').text('当前值：' + value);
						}
					});
					return false;
				});
				form.render();
			});
			</script>
			<p class="layui-font-gray" style="margin-top:12px;">
				代码示例：<code>$this->getConfig('example_text', '默认值')</code>。密码类型配置请勿在自定义页面直接输出明文。
			</p>
		</div>
	</div>
</div>
</body>
</html>
HTML;
    }

    protected function tplRoute(): string
    {
        return <<<'PHP'
<?php
/**
 * {{PLAIN_TITLE}} - 前台路由
 *
 * 装好并启用插件后，访问 /{{NAME}}/index 打开前台页面。
 * 格式：'路由规则' => '完整控制器类@方法'
 */

return [
    '{{NAME}}/index' => 'plugins\{{NAME}}\controller\Index@index',
];
PHP;
    }

    protected function tplFrontController(): string
    {
        return <<<'PHP'
<?php
/**
 * {{PLAIN_TITLE}} - 前台控制器
 *
 * 由 plugins/{{NAME}}/route.php 里的路由指向，访问 /{{NAME}}/index
 */

declare(strict_types=1);

namespace plugins\{{NAME}}\controller;

use app\extend\plugin\PluginManager;
use think\facade\View;

class Index
{
    /**
     * 前台页面
     */
    public function index()
    {
        $pm     = PluginManager::instance();
        $config = $pm->getPluginConfig('{{NAME}}');

        //渲染插件自己的前台视图：plugins/{{NAME}}/view/index.html
        View::config([
            'view_path' => $pm->getPluginPath() . '{{NAME}}' . DIRECTORY_SEPARATOR . 'view' . DIRECTORY_SEPARATOR,
        ]);

        //模板名以 / 开头 = 相对上面设的 view_path；
        //不加 / 的话框架会按「当前控制器」自动加前缀，容易拼出找不到的路径
        return View::fetch('/index', [
            'config' => $config,
        ]);
    }
}
PHP;
    }

    protected function tplFrontView(): string
    {
        return <<<'HTML'
<!DOCTYPE html>
<html>
<head>
	<meta charset="utf-8">
	<meta name="viewport" content="width=device-width, initial-scale=1">
	<title>{{HTML_TITLE}}</title>
	<style>
		body { font-family: -apple-system, "Microsoft YaHei", sans-serif; max-width: 720px; margin: 40px auto; padding: 0 16px; color: #333; line-height: 1.8; }
		.card { border: 1px solid #eee; border-radius: 6px; padding: 20px 24px; }
		code { background: #f5f5f5; padding: 1px 5px; border-radius: 3px; }
	</style>
</head>
<body>
	<div class="card">
		<h1>{{HTML_TITLE}}</h1>
		<p>这是插件的前台页面，视图在 <code>plugins/{{NAME}}/view/index.html</code>。</p>
	</div>
</body>
</html>
HTML;
    }

    protected function tplTaglib(): string
    {
        return <<<'PHP'
<?php
/**
 * {{PLAIN_TITLE}} - 模板标签
 *
 * 标签库名取「类名的小写」，本类叫 {{CLASS}}，所以模板里写 {{{NAME}}:hello /}
 * 用法：{{{NAME}}:hello name='访客' /}
 */

declare(strict_types=1);

namespace plugins\{{NAME}}\taglib;

use think\template\TagLib;

class {{CLASS}} extends TagLib
{
    /**
     * 标签定义：attr 属性列表，close 是否闭合（0 自闭合）
     * @var array
     */
    protected $tags = [
        'hello' => ['attr' => 'name', 'close' => 0],
    ];

    /**
     * 编译标签：返回一段 PHP 代码
     */
    public function tagHello(array $tag, string $content): string
    {
        $name = (string) ($tag['name'] ?? '世界');
        return '<?php echo \plugins\{{NAME}}\taglib\{{CLASS}}::render(' . var_export($name, true) . '); ?>';
    }

    /**
     * 实际输出
     */
    public static function render(string $name = '世界'): string
    {
        return '你好，' . htmlspecialchars($name, ENT_QUOTES) . '！';
    }
}
PHP;
    }

    protected function tplInstallSql(): string
    {
        return <<<'SQL'
-- {{PLAIN_TITLE}} - 安装时执行
-- __PREFIX__ 会被替换成项目实际表前缀；表名建议带插件标识前缀，避免和主表冲突
CREATE TABLE IF NOT EXISTS `__PREFIX__{{NAME}}_demo` (
  `id` int(11) UNSIGNED NOT NULL AUTO_INCREMENT COMMENT 'ID',
  `created_at` int(11) UNSIGNED NOT NULL DEFAULT 0 COMMENT '创建时间',
  PRIMARY KEY (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COMMENT='{{PLAIN_TITLE}}演示表';
SQL;
    }

    protected function tplUninstallSql(): string
    {
        return <<<'SQL'
-- {{PLAIN_TITLE}} - 卸载时执行
DROP TABLE IF EXISTS `__PREFIX__{{NAME}}_demo`;
SQL;
    }

    /**
     * 新插件随附的开发指南。
     */
    protected function tplReadme(): string
    {
        return <<<'MD'
{{README_METADATA}}

这是由后台「插件开发」生成的插件骨架。本文说明目录作用、开发顺序、安装和发布方式；示例文件仅在创建时勾选了对应模块后才会生成。

## 一、建议按这个顺序开发

1. **先确认插件标识。** 插件标识是目录名和 PHP 命名空间的一部分，只能使用字母、数字和下划线，并且不能与已有目录、系统保留名称或 PHP 关键字冲突。项目创建后不要随意改目录名；如需改名，要同步修改命名空间、主类和路由。
2. **完善插件信息。** 编辑 `Plugin.php` 中的名称、版本、作者、官网、简介和图标。图标只填写图标类名，不要写 `<i>` 标签。
3. **定义配置项。** 在 `Plugin.php::getConfigFields()` 声明后台设置项，再通过 `$this->getConfig('配置键', 默认值)` 读取。不要把 API 密钥写死在源码或 README 中。
4. **实现核心功能。** 按需扩展后台页面、前台路由、模板标签、钩子和数据库表。只保留需要的生成示例，删除其余演示代码。
5. **本地安装和验证。** 后台打开「插件管理」，找到本插件并点「安装」。安装成功后插件默认启用；再点「添加」把插件菜单加入左侧菜单，然后检查配置页、后台页面、前台路由和钩子。
6. **发布前检查。** 更新版本号，确认安装/卸载 SQL 和数据保留策略，删除调试代码及密钥，再将插件目录打成 ZIP。ZIP 顶层应为插件目录，目录内直接包含 `Plugin.php`，例如 `{{NAME}}/Plugin.php`。

## 二、目录结构

| 文件或目录 | 是否生成 | 用途 |
| --- | --- | --- |
| `Plugin.php` | 总是 | 插件元信息、后台配置字段、安装/卸载/启用/禁用回调以及可选钩子示例。 |
| `README.md` | 总是 | 本开发说明；请随插件持续更新。 |
| `admin/menu.php` | 勾选后台管理 | 定义插件菜单及子页面。安装插件后还要在插件列表点「添加」才能注册到左侧菜单。 |
| `admin/controller/Index.php` | 勾选后台管理 | 插件后台控制器示例，继承 `PluginAdminBase` 并调用 `$this->render()`。 |
| `admin/view/index.html` | 勾选后台管理 | 插件后台首页模板。 |
| `admin/view/config.html` | 勾选后台管理 | 插件自定义配置页示例；系统配置表单仍由 `getConfigFields()` 自动生成。 |
| `route.php` | 勾选前台页面 | 声明前台 URL 到控制器方法的映射。 |
| `controller/Index.php` | 勾选前台页面 | 前台控制器示例。 |
| `view/index.html` | 勾选前台页面 | 前台模板示例。 |
| `taglib/{{CLASS}}.php` | 勾选模板标签 | ThinkPHP 模板标签示例；标签类名取插件标识首字母大写后的类名。 |
| `install.sql`、`uninstall.sql` | 勾选数据库示例 | 插件专属数据表的创建和删除示例。 |

未勾选的可选目录不会自动创建；后续需要时可以按这里的路径自行添加。

## 三、插件安装、启用和菜单

1. 创建插件只会在 `plugins/{{NAME}}/` 写入文件，不会自动安装或创建数据库记录。
2. 在「插件管理」点击「安装」后，系统会执行 `install.sql`（如果存在）、调用 `Plugin.php::install()`，并保存插件元信息和默认配置。`install()` 返回 `true` 表示成功，返回字符串会作为失败原因。
3. 安装后插件默认启用。插件停用时，它声明的前台路由、模板标签和钩子不会参与当前请求；重新启用后恢复。
4. 如果生成了 `admin/menu.php`，在插件列表点击「添加」后，菜单才会注册到左侧导航。菜单树和链接由该文件定义；插件后台页面还要求插件已安装、已启用且当前管理员有此插件菜单权限。
5. 卸载可能执行 `uninstall.sql` 和 `Plugin.php::uninstall()`。检查卸载脚本是否会删除用户数据，发布前备份重要数据，并在卸载回调中保留用户明确需要保留的内容。

生成的后台页面访问形式：

```text
/admin/plugin/run?name={{NAME}}&c=Index&a=index
/admin/plugin/run?name={{NAME}}&c=Index&a=config
```

`c` 对应 `admin/controller/` 下的控制器类名，`a` 对应公开方法名。新增后台菜单时，将 URL 写成 `admin/plugin/run?name={{NAME}}&c=控制器名&a=方法名`。插件后台控制器建议继承 `PluginAdminBase`，不要自行绕开后台登录校验。

## 四、插件配置

### 系统自动配置表单

在 `Plugin.php` 中声明配置字段，系统会在插件列表的「配置」按钮中自动生成表单。字段常用结构如下：

```php
[
    'name'        => 'service_url', // 保存和读取时使用的配置键
    'title'       => '服务地址',     // 表单显示名称
    'type'        => 'text',        // 控件类型
    'default'     => '',            // 尚未保存时使用的默认值
    'placeholder' => 'https://api.example.com',
    'tips'        => '填写服务端提供的地址',
],
```

支持的控件类型：`text`、`textarea`、`switch`、`number`、`radio`、`select`、`password`、`help_area`。

- `radio`、`select` 使用 `options`，格式为 `[['value' => 'a', 'label' => '选项 A']]`。
- `depends_on` 可设置联动显示条件，例如 `['field' => 'provider', 'value' => 'mimo']`。
- `password` 配合 `preserve_empty => true` 时，密钥不回显，留空保存会保留原值；可用 `configured_placeholder` 提示密钥已配置。
- `help_area` 用于显示插件内的 Markdown 帮助说明，是插件代码元数据，不是普通设置值。
- 保存后系统把配置放在插件记录中；插件类和控制器可以调用 `$this->getConfig('service_url', '')` 或 `PluginManager::instance()->getPluginConfig('{{NAME}}')` 读取。
- 从插件代码调用 `PluginManager` 的记录读取方法时，只允许读取当前插件自己的配置；`getInstalledPlugins()` 也只返回当前插件记录。插件代码运行在 CMS 的 PHP 进程内，这项限制适用于插件系统提供的读取接口，不是 PHP 执行沙箱。

### 插件自定义配置页

勾选后台管理后会生成 `admin/view/config.html` 示例。自定义表单仍提交到 `admin/plugin/save_config`，提交字段必须先在 `getConfigFields()` 声明；系统只接受已声明的配置键。示例控制器通过 `PluginManager::instance()->getPluginConfig('{{NAME}}')` 读取配置。

不要在页面、日志、响应或 README 中输出密码字段。读取密钥只用于服务端请求；前端需要提示状态时，只显示“已配置/未配置”。

## 五、插件生命周期

`Plugin.php` 可按需重写以下方法：

```php
public function install(): bool|string
{
    // 初始化插件需要的数据；返回 true 表示成功，返回字符串表示失败。
    return true;
}

public function enable(): bool|string  { return true; }
public function disable(): bool|string { return true; }
public function uninstall(): bool|string { return true; }
```

生命周期适合做必要的初始化和清理。避免每次请求都重复执行建表、远程 API 调用或耗时任务；数据库结构优先放在 `install.sql` / `uninstall.sql`。

## 六、钩子开发

勾选「生成钩子示例」后，`Plugin.php` 会包含几个可删除的示例方法。钩子名使用下划线，插件类回调方法使用驼峰形式，例如 `document_added` 对应 `documentAdded()`。插件启用时系统会自动发现插件类里重写的公开同名方法。

也可以自行创建 `hooks.php`，显式映射钩子名和公开方法名：

```php
<?php
return [
    'search_before' => 'normalizeSearch',
];
```

同一个钩子只选一种注册方式。类方法自动发现和 `hooks.php` 映射会合并；两处都声明会导致回调执行两次。

常见回调示例：

```php
// 文档发布后；用于读取文档信息或记录插件自己的业务数据。
public function documentAdded(int $id, array $data): void
{
}

// 搜索前；引用参数允许修改本次搜索词。
public function searchBefore(string &$keywords): void
{
}

// 模板输出前；引用参数是整页 HTML，谨慎修改。
public function templateParse(string &$content): void
{
}
```

引用参数可修改当前业务调用链中的数据，但不等于修改已经保存到数据库的数据。钩子执行异常会记录日志并跳过该回调；插件仍应捕获自身可预期的异常，避免影响主业务。

### 内置钩子清单

完整参数以项目当前源码和后台「插件 → 钩子管理」为准。当前内置钩子如下：

| 类别 | 钩子 |
| --- | --- |
| 生命周期 | `app_init`、`module_init`、`action_begin`、`action_end`、`template_parse` |
| 文档 | `document_add`、`document_added`、`document_edit`、`document_edited`、`document_delete`、`document_restore`、`document_purge`、`document_examine` |
| 栏目 | `nav_add`、`nav_added`、`nav_edit`、`nav_edited`、`nav_delete` |
| 会员 | `user_register`、`user_login`、`user_logout`、`user_update` |
| 管理员 | `admin_login`、`admin_logout` |
| 搜索 | `search_before`、`search_after` |
| 留言和表单 | `form_submit`、`form_submitted`、`form_examine`、`form_reply`、`form_delete` |
| 文件 | `file_upload` |

## 七、前台路由和模板

勾选前台页面后会生成 `route.php`、控制器和模板。默认路由是 `{{NAME}}/index`，启用插件后访问：

```text
/{{NAME}}/index
```

路由定义格式为 `'路由规则' => '完整 PHP 类名@方法名'`。控制器中通过 `PluginManager::instance()->getPluginConfig('{{NAME}}')` 读取配置，再渲染插件自己的模板。插件视图必须使用插件目录内的文件，不要假设主站模板变量会自动注入。

规划路由时检查是否与现有路由冲突；输出用户内容前进行 HTML 转义，URL 和文件路径也要校验。

## 八、模板标签

勾选模板标签后会生成 `taglib/{{CLASS}}.php`，示例调用形式为：

```html
{{NAME}}:hello name='访客' /
```

标签声明在 `$tags` 中，`attr` 列出允许的属性，`close` 为 `0` 表示自闭合。`tagHello()` 负责编译标签并返回 PHP 代码；标签输出用户可控文本时必须做 HTML 转义。标签类文件名和类名要保持一致，标签前缀取类名小写。

## 九、数据库与插件数据

勾选 SQL 示例会生成 `install.sql` 和 `uninstall.sql`。安装器会把 `__PREFIX__` 替换为当前数据库表前缀，并强制要求 SQL 只能访问「数据库表前缀 + 插件标识 + 下划线」开头的数据表，例如 `__PREFIX__{{NAME}}_record`。SQL 仅支持单表建表、改表、删表、清空、插入、更新和删除；跨表查询、多表写入、视图、触发器、存储过程和跨数据库访问都会被拒绝。请不要修改或复用 CMS 主表。

- 数据查询使用参数绑定或 ThinkPHP 查询构造器，避免拼接用户输入。
- 为数据表和字段设置清楚的注释，必要时增加索引和唯一约束。
- 卸载脚本可能永久删除插件自己的表；系统会校验表名前缀，但 MySQL DDL 不保证能随事务回滚。
- `Plugin.php::install()` / `uninstall()` 是在 CMS PHP 进程和数据库账号权限下执行的插件代码，不受 SQL 文件的表名前缀校验保护。只安装可信插件；回调代码必须自行遵守只访问本插件表的约定。
- 如果用户数据需要跨卸载保留，应明确设计备份/保留策略，不能只依赖默认卸载脚本。

## 十、调试、打包和发布

1. 查看后台「插件管理」中的安装状态；插件回调和路由只对已启用插件生效。
2. 打开插件菜单前，确认已安装、已启用，并已将插件菜单添加到左侧导航。
3. 钩子没有触发时，核对钩子名称、驼峰方法名、方法是否为 `public`、插件是否启用，以及是否重复声明。
4. 前台页面 404 时，核对 `route.php` 的规则、控制器完整命名空间、方法名、插件启用状态和 URL 是否与现有路由冲突。
5. 配置没有保存时，核对 `name` 是否在 `getConfigFields()` 声明；密码字段是否启用了留空保留行为。
6. 发布前更新 `Plugin.php` 中的版本号和 README，移除测试数据、调试输出、临时文件和任何真实密钥。
7. ZIP 中保留插件目录作为顶层目录，确保顶层目录内有 `Plugin.php`。后台插件列表的「导出」会打包插件文件，不会导出插件在数据库中产生的数据；要迁移数据，请另行设计并授权安全的数据迁移流程。

## 十一、安全和兼容性检查

- 将插件代码视为服务器端程序，只安装可信来源的插件包。
- 插件 SQL 文件受专属表名前缀校验；插件 PHP 文件和生命周期回调不是沙箱，仍拥有 CMS 进程权限。
- 对所有请求参数、上传文件、URL 和数据库查询做校验；不要仅依赖浏览器表单校验。
- 用 `htmlspecialchars()` 输出 HTML 文本；用 `htmlspecialchars()`/属性安全处理输出属性值。
- API Key 等敏感值只保存在服务端配置中，不放进模板、JavaScript、README、异常提示或日志。
- 插件表、缓存键、路由和模板标签都使用独立命名空间或插件前缀，避免与其他插件及系统冲突。
- 慢任务不要放在每次页面渲染或同步钩子里；做好超时、失败处理和重复执行保护。
- 升级插件时保持配置键和数据库变更向后兼容；卸载时谨慎处理用户数据。

## 十二、项目内可查资料

- 后台插件列表：查看安装状态、配置、插件首页、菜单管理和 ZIP 导出。
- 后台「插件 → 钩子管理」：查看钩子说明及其触发位置。
- `app/extend/plugin/PluginBase.php`：查看配置、生命周期和钩子方法定义。
- `app/extend/plugin/PluginAdminBase.php`：查看插件后台视图渲染接口。
- `app/extend/plugin/PluginManager.php`：查看插件安装、路由、标签和菜单的实际加载规则。
- `plugins/demo/`：查看更完整的配置、路由、标签、钩子和数据库示例。
MD;
    }
}
