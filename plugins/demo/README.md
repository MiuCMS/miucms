# 演示插件

这是 Miucms 插件系统的可运行示例，用来展示插件文件如何协作。建议先在后台安装并启用插件，再到插件管理点“添加到主菜单”。之后可以从左侧菜单打开“插件首页”和“插件配置”。

## 快速体验

1. 在后台“插件管理”中安装并启用 `demo`。
2. 点击“添加到主菜单”，打开“演示插件 → 插件配置”，保存站点名称、欢迎语等设置。
3. 访问 `/demo/hello?name=访客`，查看前台路由、配置读取和模板标签。
4. 在后台发布一篇文档，再回到插件首页查看 `document_added` 钩子保存的记录。
5. 如需清理演示数据，在插件首页点击“清空文档演示记录”。该操作只清空 `demo_record` 表。

## 目录与作用

| 文件 | 用途 |
| --- | --- |
| `Plugin.php` | 插件元信息、配置字段、安装/启用回调和自动发现的钩子 |
| `hooks.php` | 展示将 `search_before` 映射到自定义方法名的写法 |
| `admin/menu.php` | 插件后台菜单定义；安装后还需手动添加到主菜单 |
| `admin/controller/Index.php` | 后台首页、配置页和清理演示记录的控制器方法 |
| `admin/view/index.html` | 展示开发流程、功能入口和文档钩子记录 |
| `admin/view/config.html` | 插件自定义配置表单，演示字段提交与密码留空保护 |
| `route.php` | 前台路由 `demo/hello` |
| `controller/Index.php` | 读取插件设置和 URL 参数，并渲染前台模板 |
| `view/hello.html` | 前台演示页，使用 `{demo:hello name='访客' /}` 标签 |
| `taglib/Demo.php` | 标签定义、编译和安全输出示例 |
| `install.sql` / `uninstall.sql` | 创建和删除演示插件自己的数据表 |

## 配置与安全

- 系统自动配置表单来自 `Plugin.php::getConfigFields()`；插件自定义表单还要在 `admin/view/config.html` 中添加对应输入项。
- 插件类的 `$authorUrl` 属性演示作者官网元信息；创建插件时只接受完整的 HTTP/HTTPS 网址。
- 自定义表单提交到 `admin/plugin/save_config`。系统只保存配置字段定义中声明过的键，因此不要用它提交未声明字段。
- 示例 API Key 字段只用于演示，不连接真实接口。`preserve_empty` 让空密码字段保留已有值，页面不会回显密钥。
- `help_area` 是 Markdown 帮助区，不是普通配置输入项。敏感信息不要写进帮助内容、页面、响应或日志。
- `documentAdded()` 和清理操作只访问插件自己的 `demo_record` 表；插件表名为「项目表前缀 + `demo_`」，安装器也会校验 SQL 只能操作当前插件前缀下的数据表。
- 插件管理器自身需要维护插件注册表中的状态，以及后台菜单/角色权限表中本插件的菜单引用；这些是系统内部记录，不由插件 SQL 操作，卸载时按当前插件标识和菜单节点清理。
- `Plugin.php::install()` / `uninstall()` 是普通 PHP 回调，不是沙箱；它们拥有 CMS 进程和数据库账号的权限。只安装可信插件，并确保回调只访问 `demo_` 前缀数据表。
- 钩子异常应由插件自行捕获，避免插件自身错误影响主业务。修改搜索词、页面 HTML 等引用参数时，尽量只在明确启用相关设置后执行。

## 钩子例子

- `templateParse(string &$content)`：按设置在页面 `</body>` 前插入页脚文字，默认关闭，并对文字做 HTML 转义。
- `documentAdded(int $id, array $data)`：文档发布后写一条记录到插件数据表。
- `userLogin(array &$user)`：演示引用参数；改动当前调用链数据不等于持久化到数据库。
- `hooks.php`：演示钩子名与任意公开方法名的映射。不要同时映射并自动声明同一个钩子，否则会调用两次。

更多钩子名称和参数可查看后台“插件 → 钩子管理”及 `app/extend/plugin/PluginBase.php`。
