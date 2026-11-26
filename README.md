# MiuCms

MiuCms 是一套基于 **ThinkPHP 8、Layui 和 MySQL** 的内容管理系统，提供网站后台、内容管理、模板标签和插件扩展能力，适用于企业官网、资讯门户、分类信息、房产、课程教育及其他行业网站，也方便按项目需要二次开发。

> **MiuCms 永久免费，可用于商业项目和二次开发。** 基于 MiuCms 搭建并运营的网站，须在页脚保留“Power by MiuCms”。第三方依赖及插件请遵循各自的授权协议。

官网：[https://www.miucms.com](https://www.miucms.com)

演示站：[https://demo.miucms.com](https://demo.miucms.com)

帮助文档：[https://docs.miucms.com/](https://docs.miucms.com/)

## 项目特点

- **内容模型可扩展**：后台可管理模型和字段，按内容需要维护文档数据。
- **常用内容管理**：支持栏目、文档、标签、单页内容、广告、留言表单和友情链接等。
- **模板标签**：前台模板可使用自定义标签读取栏目、文档、标签、广告等数据，并按需要编写页面样式。
- **多语言**：按前台模板配置启用语言和默认语言，支持模板界面文案与内容翻译；语言勾选和默认语言设置即时保存，AI 翻译由插件提供。
- **插件扩展**：支持插件配置、后台菜单、钩子、路由和模板标签，可按需要扩展功能。
- **SEO 工具**：支持动态、伪静态和生成静态三种 URL 模式，以及 XML、TXT、HTML Sitemap 和面向 AI 的 `llms.txt` 内容索引。
- **AI 能力**：AI 创作、改写和图片生成；支持多语言自动翻译。
- **后台管理**：提供角色权限、管理员操作日志、用户管理、数据统计和数据库维护等功能。
- **会员功能**：支持账号或邮箱登录、邮箱注册验证、邮件找回密码、会员资料与头像上传、浏览记录、收藏和邀请注册。
- **附件存储**：支持本地存储、阿里云 OSS 和腾讯云 COS，统一处理上传及文件管理中的源文件删除。
- **模板管理**：后台通过模板卡片启用前台模板，读取模板元信息并发布静态资源。

## 运行环境

- PHP **8.0.2 或更高版本**，具体依赖要求以 `composer.lock` 和 Composer 平台检查为准。
- MySQL：当前已实测 **5.5.62、5.6.51、8.0.44、8.4.7、9.5.0**；项目开发环境使用 **5.7.26**，详细范围见下文。
- 安装页也提供 MariaDB 选项，MySQL 与 MariaDB 均使用 `mysql` 驱动；MariaDB 尚未单独完成版本兼容实测。
- Web 服务器：Nginx、Apache 或 IIS，并配置 URL 重写。
- 安装程序要求开启 PHP 扩展：`fileinfo`、`mbstring`、`gd`、`pdo_mysql`、`mysqli`。
- PHP 文件上传功能须开启，`upload_max_filesize` 不低于 **2M**。

建议服务器至少使用 **1 核 CPU、2 GB 内存**；访问量较大时建议 2 核 CPU、4 GB 内存或更高配置。AI、邮件等插件调用第三方接口时，还需按插件要求配置网络及接口参数。

### MySQL 兼容范围

截至 2026 年 10 月 31 日，使用独立数据库实例完成以下检查：

| MySQL 版本 | 验证情况 |
| --- | --- |
| 5.5.62 | 通过 |
| 5.6.51 | 通过 |
| 5.7.26 | 通过 |
| 8.0.44 | 通过 |
| 8.4.7 | 通过 |
| 9.5.0 | 通过 |


## 安装

1. 上传项目文件。发布包已包含 `vendor/` 时保留配套依赖；缺少依赖时，在项目根目录执行 `composer install`，按锁定版本安装，不要用 `composer update` 代替部署。
2. 在 Web 服务器中把站点根目录（Document Root）指向项目的 **`public/`**，不要把整个项目根目录暴露为网站目录。配置 URL 重写后，浏览器访问 `https://你的域名/install`。
3. 阅读并接受安装协议。安装程序会真实检查 PHP 环境、上传临时目录，以及 `.env`、`config/`、`public/storage/`、`runtime/` 的读写权限；未通过的项目需先处理再继续。
4. 填写数据库类型、地址、端口、数据库名、用户名、密码和表前缀，先使用“测试连接”确认服务器可连接，再填写超级管理员账号和密码并开始安装。连接测试通过不等于建表、触发器权限也已通过。
5. 安装程序导入数据并创建超级管理员，将数据库连接信息写入根目录 `.env`，同时设置 `APP_DEBUG=false`；完成后生成 `config/install.lock`。

若数据库尚未创建，账号还需有建库权限；没有建库权限时，请先手动创建空数据库。新部署站点不要携带其他站点的 `.env` 或 `config/install.lock`；迁移已有网站时则应保留安装锁config/install.lock，并根据新环境修改数据库配置。

### 安装后的配置

- 网站配置：设置域名、网站名称、SEO、邮件、注册验证和附件存储。
- 模板：在后台模板设置页启用模板；
- 国际化：在“基础 → 国际化”配置当前模板启用的语言和默认语言。
- 静态资源：确保 `public/skin/` 及目标模板的子目录、文件可由 PHP 运行账号写入；后台切换模板和清理缓存会同步模板资源，失败时会提示具体位置。
- 按需确保 `backup/database/`、插件目录和模板 `lang/` 目录具有对应功能所需的写入权限。不要仅为解决权限问题将整个项目统一设置为 `777`。

文档中的 `/admin` 为默认后台入口；自定义后台路径后，请使用实际入口。


## 后台只读演示

在独立演示站中准备演示数据，在后台「权限角色」新建普通权限组，并为演示管理员分配该组。勾选需要展示的文档、栏目、标签和模型查看权限。要打开文档或栏目的新增、编辑表单查看，也需要勾选对应节点。确认演示站使用独立数据库和演示账号后，在该站的 `.env` 增加以下配置。

```ini
[DEMO]
READ_ONLY = true
GROUP_ID = 2
```

将`2` 改为实际演示组 ID。所有后台账号按原有规则登录，并按各自的角色权限查看页面。演示组成员登录后，后台拦截 POST 和 DELETE 请求，退出登录照常使用。演示站可使用普通数据库账号。首次部署后清理后台模板缓存。

## 伪静态配置

请将网站入口指向项目的 `public` 目录，并配置 URL 重写。以下规则把不存在的文件和目录交给 ThinkPHP 前端入口处理。

### Nginx 


```nginx
location / {
    if (!-e $request_filename) {
        rewrite ^(.*)$ /index.php?s=$1 last;
        break;
    }
}
```

### Apache

确保已启用 `mod_rewrite`，并在 `public/.htaccess` 中配置：

```apache
<IfModule mod_rewrite.c>
    Options +FollowSymlinks -Multiviews
    RewriteEngine On
    RewriteCond %{REQUEST_FILENAME} !-d
    RewriteCond %{REQUEST_FILENAME} !-f
    RewriteRule ^(.*)$ index.php?s=$1 [QSA,PT,L]
</IfModule>
```

### IIS

安装并启用 IIS URL Rewrite 模块，在 `public/web.config` 的 `<system.webServer>` 中添加规则；若该文件不存在，请先在 `public/` 下新建：

```xml
<rewrite>
    <rules>
        <rule name="ThinkPHP" stopProcessing="true">
            <match url="^(.*)$" />
            <conditions>
                <add input="{REQUEST_FILENAME}" matchType="IsFile" negate="true" />
                <add input="{REQUEST_FILENAME}" matchType="IsDirectory" negate="true" />
            </conditions>
            <action type="Rewrite" url="index.php?s=/{R:1}" appendQueryString="true" />
        </rule>
    </rules>
</rewrite>
```

## 前台模板与多语言

### 模板选择与资源

`templates/` 下每个子目录是一套模板，通过后台模板设置页启用。系统先把模板 `static/` 资源同步到 `public/skin/<模板名>/`，成功后才切换当前模板；资源同步失败会保留原模板。

模板信息写在 `templates/<模板名>/info.json`，使用 JSON 对象格式：

```json
{
  "name": "default",
  "title": "默认模板",
  "version": "1.0.0",
  "minCmsVersion": "",
  "description": "这是MiuCMS默认模板",
  "author": "米柚",
  "authorUrl": "https://www.miucms.com",
  "type": "corporate"
}
```

`name` 须与模板目录名一致；`minCmsVersion` 留空表示不限制最低 CMS 版本。新建模板使用 `info.json`；读取逻辑仍兼容旧版 `info.txt` 和 `info.ini`，方便升级后保留现有模板。

页面模板使用 `{miu:nav}`、`{miu:list}`、`{miu:pagelist}`、`{miu:adv}`、`{miu:langlist}` 等标签读取数据。会员登录、注册、找回密码和会员中心视图放在 `app/home/view/`，其公共资源位于 `public/static/home/user/`，不放在当前模板目录中。

### 语言启用与默认语言

- 在“基础 → 国际化”勾选或取消语言会立即保存；点击“设置为默认语言”也会立即保存当前页配置，当前默认项显示绿色“已默认”。
- 默认语言必须启用。简体中文非默认时可以停用，但数据库中文原文及 `zh-cn.json` 不会删除，仍用于缺失译文时的回退。
- 默认语言使用不带语言前缀的地址；其他语言使用 `/<语言代码>/`，例如默认英语时 `/news/` 显示英语，启用中文后 `/zh-cn/news/` 显示中文。
- 启用列表和默认语言按模板分别保存。语言包放在 `templates/<模板名>/lang/`，勾选新语言只补建缺失的 JSON 示例文件，不会自动完成译文；取消勾选不会删除文件或翻译记录。
- 模板固定文案使用语言 JSON；栏目、文档等内容的字段翻译保存在 `content_i18n` 表中，自动翻译接口由 AI 翻译插件提供。

修改默认语言后，应重新生成静态页面和 Sitemap。停用语言后，动态入口会拒绝其语言前缀；已经生成的静态文件由 Web 服务器直接返回，需要另行清理，停用开关不会自动删除它们。静态模式下，非默认语言通常位于保存目录的语言子目录，例如 `/html/zh-cn/`，具体地址取决于 URL 配置。

模板语言文件说明见 [`frontend-template-i18n.md`](frontend-template-i18n.md)；当前使用方式以实际后台设置和源码为准。

## 附件与存储

在“网站配置 → 附件配置”中选择本地存储、阿里云 OSS 或腾讯云 COS，并填写所选服务的地域、存储桶、凭据及访问域名等配置。

上传入口通过公共存储服务选择对应驱动，文件记录写入 `file` 表；文件管理器、会员头像、远程图片本地化等功能使用统一的存储处理逻辑。切换存储类型只影响后续上传，不会自动搬迁历史文件。

本地文件备份应包含 `public/storage/`；使用 OSS、COS 时，还需按云服务方案备份对象数据和访问配置。数据库备份只保存文件记录，不包含本地文件或云端对象内容。

## AI 插件

AI 能力由插件提供，按需在后台安装、配置和启用：

- `AItranslation`：配置翻译接口，翻译已启用语言的栏目、文档、标签、自定义文本变量等内容。

插件可声明最低 CMS 版本，版本不满足时不允许安装。接口密钥、模型选择和费用以具体插件及服务提供方配置为准。

使用第三方模型接口可能产生相应的服务费用，请以服务提供方的计费规则为准。

## SEO 与命令行维护

在后台“SEO → Sitemap”中选择地图类型，可生成到 `public/`：

| 类型 | 输出文件 |
| --- | --- |
| XML | `sitemap.xml` |
| TXT | `sitemap.txt` |
| HTML | `sitemap.html` |
| AI 内容索引 | `llms.txt` |

`llms.txt` 使用 Markdown 标题、摘要和分组链接，不是 XML Sitemap 的替代品。地图是生成文件，栏目、文档、过滤规则、域名或默认语言变化后，需要重新生成。

下列命令在项目根目录运行，使用站点实际 PHP 路径，并先配置网站域名：

```bash
# 按后台勾选的类型生成地图
php think sitemap

# 生成全部四种地图
php think sitemap --type=all

# 单独生成 llms.txt
php think sitemap --type=llms

# 生成地图并执行百度推送，需先配置推送 token
php think sitemap --push

# 清理已过期的文件缓存
php think cache-clean

# 静态模式下处理待更新页面队列
php think static-update --worker
```

`cache-clean` 用于清理过期文件缓存，不等于后台“清理缓存”操作。定时任务可使用地图和缓存维护命令；静态更新队列也可通过 `static-update --worker` 处理。

访问来源和爬虫报告由网站统计插件提供。数据库更新、静态日志接入及计划任务见[网站统计插件说明](plugins/access/README.md)。

## 备份与迁移

- 后台数据库维护支持备份、还原、优化、修复；当前备份包含表结构、数据及触发器，并支持普通 SQL 和压缩备份。
- 备份数据库之外，还应保存 `.env`、自定义配置、模板、插件及本地上传文件；云端附件需要单独备份。
- 数据库备份可能含密码哈希、接口凭据等敏感数据，应放在网站公开目录之外并限制访问。
- 迁移站点后核对 `.env`、站点域名、后台入口、存储访问域名和目录权限，再重新生成所需静态页面和地图。

## 目录说明

Web 服务器只应公开 `public/`。项目根目录中的配置、代码、依赖和备份目录不要直接作为网站根目录开放。

### 根目录

| 目录/文件 | 用途 |
| --- | --- |
| `app/` | MiuCms 应用代码，包含后台、前台、API、安装程序及公共服务 |
| `backup/` | 备份文件存放位置；当前包含数据库备份目录 `backup/database/` |
| `config/` | ThinkPHP 与 MiuCms 配置；安装成功后会生成 `config/install.lock`，用于阻止重复安装 |
| `extend/` | 项目扩展类；当前有备份工具和日期工具目录，并由 Composer 自动加载 |
| `plugins/` | 插件代码目录；当前包含 AI 翻译、AI 创作、悬浮客服和示例插件 |
| `public/` | 网站公开入口和静态文件；Web 服务器根目录必须指向这里 |
| `runtime/` | 运行时缓存、编译模板、日志、会话及临时文件，由程序自动生成 |
| `templates/` | 前台页面模板；当前模板位于 `templates/default/` |
| `vendor/` | Composer 安装的 ThinkPHP 和第三方 PHP 依赖，不要直接修改其中代码 |
| `.env` | 当前环境配置，安装程序会把数据库连接信息写在这里；不要提交或公开其中的密码 |
| `.env.debug` | 环境配置格式参考；部署时应核对并替换环境相关参数 |
| `composer.json` / `composer.lock` | PHP 依赖声明及依赖版本锁定文件 |
| `think` | ThinkPHP 命令行入口 ，不可删除 |
| `README.md` | 项目说明、安装步骤和目录说明 |

### `app/` 应用目录

| 目录 | 用途 |
| --- | --- |
| `app/admin/` | 后台：控制器、模型、视图、权限中间件、验证器和后台标签 |
| `app/api/` | 对外 API 控制器及 API 中间件 |
| `app/command/` | 命令行任务，如生成静态页、Sitemap 和缓存维护 |
| `app/event/` | 应用事件处理 |
| `app/exception/` | 应用异常处理 |
| `app/extend/plugin/` | 插件框架、插件管理及钩子支持代码 |
| `app/home/` | 前台：控制器、视图、路由、语言包、中间件、服务、验证器和模板标签 |
| `app/install/` | 安装程序：安装控制器、环境校验、安装视图|
| `app/middleware/` | 全站共用中间件 |
| `app/services/` | 应用级公共服务 |
| `app/common.php` | 全局公共函数，包含配置读取、URL 生成、模板管理等共享逻辑 |

后台和前台的主要子目录如下：

| 路径 | 用途 |
| --- | --- |
| `app/admin/controller/` | 后台请求控制器 |
| `app/admin/middleware/` | 后台登录和权限等中间件 |
| `app/admin/model/` | 后台数据模型 |
| `app/admin/taglib/` | 后台视图标签 |
| `app/admin/validate/` | 后台表单验证规则 |
| `app/admin/view/` | 后台页面模板 |
| `app/home/config/` | 前台应用配置 |
| `app/home/controller/` | 前台页面和业务控制器 |
| `app/home/lang/` | 前台应用语言包 |
| `app/home/middleware/` | 前台中间件 |
| `app/home/route/` | 前台路由定义 |
| `app/home/service/` | 前台业务服务 |
| `app/home/taglib/` | 前台模板标签及其实现 |
| `app/home/validate/` | 前台表单验证规则 |
| `app/home/view/` | 会员登录、注册和会员中心等前台应用视图 |
| `app/home/taglib/traits/` | 按标签拆分的 `{miu:xxx}` 实现，由 `Miu.php` 组合 |
| `app/services/storage/` | OSS、COS 等存储驱动实现；上传策略由公共服务统一管理 |
| `app/api/controller/`、`app/api/middleware/` | API 控制器及中间件 |
| `app/install/controller/`、`app/install/view/` | 安装流程控制器及页面 |
| `app/install/data/`、`app/install/validate/` | 初始数据库 SQL 及安装表单验证 |

### `public/` 网站目录

| 目录/文件 | 用途 |
| --- | --- |
| `public/index.php` | 网站 PHP 入口 |
| `public/static/` | 静态资源目录，包含 `admin/` 后台资源、`home/` 前台资源和 `location/` 地区联动数据；会员资源位于 `home/user/` |
| `public/storage/` | 用户上传文件目录，包含按日期存放的上传文件和 `user/` 用户文件；请纳入备份 |
| `public/skin/<模板名>/` | 当前前台模板使用的 CSS、JavaScript、图片等静态资源 |
| `public/tpl/` | ThinkPHP 错误页和调试页面模板 |
| `public/.htaccess`、`public/nginx.htaccess` | Apache/Nginx 重写规则参考 |
| `public/router.php` | PHP 内置开发服务器的路由入口 |
| `public/robots.txt`、`public/favicon.ico` | 搜索引擎抓取规则和网站图标 |
| `public/sitemap.xml`、`public/sitemap.txt`、`public/sitemap.html`、`public/llms.txt` | 地图与 AI 内容索引的输出位置，文件由对应生成功能创建或更新 |

`public/storage/` 是上传目录；具体日期目录会随上传时间增加。上传文件不要放进 `runtime/`。

### `templates/` 前台模板

每个 `templates/<模板名>/` 子目录是一套可切换的前台模板。页面 HTML 放在模板目录中；`lang/` 存放模板界面文案的多语言文件；模板自带的 CSS、JavaScript 和图片放在 `static/`，系统会将这些资源发布到 `public/skin/<模板名>/` 供浏览器访问。`info.json` 保存模板名称、标题、版本、最低 CMS 版本和说明，格式见上文。

### `plugins/` 插件目录

当前目录包含以下插件：

| 目录 | 用途 |
| --- | --- |
| `plugins/AItranslation/` | AI 多语言翻译插件 |
| `plugins/demo/` | 插件开发示例，包含控制器、后台页面和模板标签示例 |

插件目录中的 `Plugin.php` 保存插件定义和配置；`admin/`、`controller/`、`taglib/`、`assets/`、`view/` 等子目录按插件功能存放后台控制器、前台控制器、模板标签、静态资源和页面模板。并非每个插件都包含全部子目录。

## 使用协议

项目代码在 `composer.json` 中声明使用 Apache-2.0。MiuCms 永久免费，可用于商业项目；使用 MiuCms 搭建并运营的网站，须在页脚保留“Power by MiuCms”。第三方组件、插件及模板按其各自的许可协议使用。
