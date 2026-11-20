<?php
/**
 * @copyright Copyright (c) 2026 米柚工作室
 * @license https://opensource.org/licenses/Apache-2.0
 * @link https://www.miucms.com
 */

declare (strict_types = 1);

namespace app\admin\controller;

use app\admin\BaseController;
use think\facade\View;

/**
 * SEO → URL配置
 *
 * 三种 URL 模式（动态URL / 伪静态化 / 静态页面）的开关和细则，分组跟主流 CMS 对齐：
 *   URL模式 / 栏目URL / 文档URL / 栏目标题 / 标签标题 / 文档标题 / 标题间连接符 /
 *   发布编辑文档（清缓存）/ 去除index.php / 静态页面
 *
 * 配置存在 cms_config 表里 name=seo 的那一行（content 是序列化数组），
 * 和「网站配置」（name=global）分开存，互不干扰：
 * 这里只读写自己这一组，整组交给 save_config_group() 处理。
 *
 * 前台读取：app/home/common.php 的 url_config()（URL相关）和 miu_page_title()（标题相关）
 * 前台生成：nav_url() / doc_url()
 * 前台解析：url_parse()
 */
class Seo extends BaseController
{
    /**
     * 本页面负责的配置项
     *
     * 配置项的取值和默认值在此定义，显示/隐藏联动由 app/admin/view/seo/index.html 处理。
     *
     *   type    int=整数单选 / enum=字符串单选 / str=文本 / dir=目录路径
     *   allow   type=int 时的可选值
     *   pattern type=str 时的正则
     *   empty   type=str / dir 时是否允许留空（页面保存目录留空 = 存到根目录）
     *   default 缺省值
     */
    protected $fields = [
        // ---- URL模式：1=动态URL  2=静态页面  3=伪静态化（页面显示顺序 1 / 3 / 2）----
        'url_pseudo'            => ['type' => 'int',  'default' => 3,   'allow' => [1, 2, 3]],

        // ---- 静态页面 ----
        //页面保存目录（留空 = 根目录）
        'url_html_arcdir'       => ['type' => 'dir',  'default' => 'html', 'empty' => true],
        //栏目页面路径：1=顶级目录名称/list_ID.html  2=父级目录名称/子目录名称/  3=子目录名称/
        // 仅允许以下三种内置路径规则，不提供自定义路径。
        'url_html_listname'     => ['type' => 'int',  'default' => 2,   'allow' => [1, 2, 3]],
        //文档页面路径：1=顶级目录名称/ID.html  2=父级目录名称/子目录名称/ID.html  3=子目录名称/ID.html
        'url_html_pagename'     => ['type' => 'int',  'default' => 2,   'allow' => [1, 2, 3]],
        //标签页面路径：1=tag/ID.html  2=tag/ID/（分页文件为 pN.html）
        'url_html_tagname'      => ['type' => 'int',  'default' => 1,   'allow' => [1, 2]],

        // ---- 伪静态化 ----
        //栏目URL：1=目录名称  2=模型标识
        'url_rewrite_format'    => ['type' => 'int',  'default' => 1,   'allow' => [1, 2]],
        //文档URL：1=父目录  3=当前目录  4=父目录/当前目录/
        'url_rewrite_view_format' => ['type' => 'int', 'default' => 3,  'allow' => [1, 3, 4]],

        // ---- 标题规则 ----
        //栏目标题：3=栏目名称  1=栏目名称_网站名称  4=栏目名称_第N页  2=栏目名称_第N页_网站名称
        'url_liststitle_format' => ['type' => 'int',  'default' => 2,   'allow' => [1, 2, 3, 4]],
        //标签标题：3=标签名称  1=标签名称_网站名称  4=标签名称_第N页  2=标签名称_第N页_网站名称
        'url_tagtitle_format'   => ['type' => 'int',  'default' => 2,   'allow' => [1, 2, 3, 4]],
        //文档标题：1=内容标题  2=内容标题_网站名称  3=内容标题_栏目名称_网站名称
        'url_viewtitle_format'  => ['type' => 'int',  'default' => 2,   'allow' => [1, 2, 3]],
        //标题间连接符
        'url_title_symbol'      => ['type' => 'str',  'default' => '_', 'pattern' => '/^[A-Za-z0-9_\-]{1,3}$/', 'msg' => '标题间连接符只能是字母、数字、下划线或短横线，长度 1-3 位'],

        // ---- 静态页面：发布文档后更新哪些页面 ----
        'url_pub_home'          => ['type' => 'int',  'default' => 1,   'allow' => [0, 1]],
        'url_pub_nav'           => ['type' => 'int',  'default' => 1,   'allow' => [0, 1]],
        'url_pub_side'          => ['type' => 'int',  'default' => 1,   'allow' => [0, 1]],

        // ---- 静态页面：编辑文档后更新哪些页面 ----
        'url_edit_home'         => ['type' => 'int',  'default' => 1,   'allow' => [0, 1]],
        'url_edit_nav'          => ['type' => 'int',  'default' => 1,   'allow' => [0, 1]],
        'url_edit_side'         => ['type' => 'int',  'default' => 1,   'allow' => [0, 1]],

    ];

    /**
     * Sitemap 页面的配置项（存在 cms_config 的 sitemap 组，跟 seo 组分开）
     *
     * 地图类型、生成选项及默认值在此定义。
     *
     *   type   int=开关(0/1) / num=数字(带 min/max) / enum=下拉(取值在 allow 里) / str=文本
     */
    protected $sitemapFields = [
        // ---- 地图类型（勾哪个生成哪个） ----
        'sitemap_xml'                   => ['type' => 'int',  'default' => 0,        'allow' => [0, 1]],
        'sitemap_txt'                   => ['type' => 'int',  'default' => 0,        'allow' => [0, 1]],
        'sitemap_html'                  => ['type' => 'int',  'default' => 0,        'allow' => [0, 1]],
        'sitemap_llms'                  => ['type' => 'int',  'default' => 0,        'allow' => [0, 1]],

        // ---- 自动更新：发布/编辑文档时重新生成 ----
        'sitemap_auto'                  => ['type' => 'int',  'default' => 0,        'allow' => [0, 1]],

        // ---- 过滤规则 ----
        'sitemap_not1'                  => ['type' => 'int',  'default' => 0,        'allow' => [0, 1]],  // 过滤隐藏栏目
        'sitemap_not2'                  => ['type' => 'int',  'default' => 0,        'allow' => [0, 1]],  // 过滤外部链接

        // ---- 更新频率（xml 地图用） ----
        'sitemap_changefreq_index'      => ['type' => 'enum', 'default' => 'always', 'allow' => ['always', 'hourly', 'daily', 'weekly', 'monthly', 'yearly', 'never']],
        'sitemap_changefreq_list'       => ['type' => 'enum', 'default' => 'hourly', 'allow' => ['always', 'hourly', 'daily', 'weekly', 'monthly', 'yearly', 'never']],
        'sitemap_changefreq_view'       => ['type' => 'enum', 'default' => 'daily',  'allow' => ['always', 'hourly', 'daily', 'weekly', 'monthly', 'yearly', 'never']],

        // ---- 优先级别（xml 地图用） ----
        'sitemap_priority_index'        => ['type' => 'enum', 'default' => '1.0',    'allow' => ['0.1', '0.2', '0.3', '0.4', '0.5', '0.6', '0.7', '0.8', '0.9', '1.0']],
        'sitemap_priority_list'         => ['type' => 'enum', 'default' => '0.8',    'allow' => ['0.1', '0.2', '0.3', '0.4', '0.5', '0.6', '0.7', '0.8', '0.9', '1.0']],
        'sitemap_priority_view'         => ['type' => 'enum', 'default' => '0.5',    'allow' => ['0.1', '0.2', '0.3', '0.4', '0.5', '0.6', '0.7', '0.8', '0.9', '1.0']],

        // ---- 生成数量 ----
        'sitemap_archives_num'          => ['type' => 'num',  'default' => 100,      'min' => 1, 'max' => 5000],
        'sitemap_tags_num'              => ['type' => 'num',  'default' => 100,      'min' => 0, 'max' => 5000],

        // ---- 百度推送 ----
        'sitemap_baidu_push'           => ['type' => 'int',  'default' => 0,        'allow' => [0, 1]],
        //token 可以留空（留空 = 不做推送）
        'sitemap_zzbaidutoken'          => ['type' => 'str',  'default' => '',       'empty' => true, 'pattern' => '/^[A-Za-z0-9_\-]{0,64}$/', 'msg' => '百度推送 token 只能是字母、数字、下划线或短横线'],
    ];

    public function index()
    {
        $param = get_params();

        if (request()->isAjax()) {
            $this->requireRequestMethod();
            $result = $this->buildConfig(is_array($param) ? $param : []);
            if (isset($result['error'])) {
                return to_assign(1, $result['error']);
            }

            //保存前的 URL模式，用来判断这次是不是「切换」了模式
            $oldPseudo = (int) ($oldConfig['url_pseudo'] ?? 3);

            //整组写进 cms_config 里 name=seo 那一行（不存在会自动新建）
            $res = save_config_group('seo', $result['data']);
            if ($res === false) {
                return to_assign(1, '保存失败，请检查 cms_config 表是否可写');
            }

            $newPseudo = (int) ($result['data']['url_pseudo'] ?? $oldPseudo);
            $extra     = [];

            if ($newPseudo === 1 || $newPseudo === 3) {
                //动态 / 伪静态模式下 public/index.html 不该存在：
                //它是「生成静态」模式留下的首页文件，服务器的 DirectoryIndex 会优先返回它，
                //留着的话 / 会一直显示那份旧的静态首页
                $indexHtml = public_path() . 'index.html';
                if (is_file($indexHtml)) {
                    @unlink($indexHtml);
                }
            } elseif ($newPseudo === 2 && $newPseudo !== $oldPseudo) {
                //刚切到「生成静态」：前台得先生成静态文件才能访问，给前端一句提醒
                $extra['notice'] = '已切换到「生成静态」模式。<br><br>'
                    . '这种模式下前台页面是纯静态 HTML，<b>必须先到「SEO → 页面生成」把页面生成出来，网站才能正常访问</b>'
                    . '（没生成之前首页、栏目页、文档页都会打不开）。<br><br>'
                    . '建议直接点「一键生成」把整站生成一遍。';
            }

            safe_add_log('edit', 0, $result['data'], 'URL配置');

            return to_assign(0, '保存成功', $extra);
        }

        //把还没保存过的项补上默认值，模板里直接 {$config.xxx} 就不会取到空
        $config = get_config_group('seo');
        //旧配置没有标签标题规则时，先沿用栏目标题规则，避免升级后现有标签标题格式突然变化。
        if (!isset($config['url_tagtitle_format']) || $config['url_tagtitle_format'] === '') {
            $legacyTagTitle = (int) ($config['url_liststitle_format'] ?? 2);
            $config['url_tagtitle_format'] = in_array($legacyTagTitle, [1, 2, 3, 4], true)
                ? $legacyTagTitle
                : 2;
        }
        foreach ($this->fields as $key => $rule) {
            $exists = array_key_exists($key, $config);
            //允许留空的项（页面保存目录），空串是有意义的（留空 = 根目录），不能当没填
            if (!empty($rule['empty']) && $exists) {
                continue;
            }
            if (!$exists || $config[$key] === '') {
                $config[$key] = $rule['default'];
            }
        }
        View::assign('config', $config);
        return view();
    }

    /**
     * SEO → Sitemap 设置页
     *
     * 配置存在 cms_config 的 sitemap 组（跟 seo / global 分开存）。
     * 页面上的「手工更新」按钮走前台 /home/sitemap/build（生成要读前台 URL 规则，只能在 home 应用里跑）。
     */
    public function sitemap()
    {
        $param = get_params();

        if (request()->isAjax()) {
            if (!request()->isPost()) {
                return to_assign(1, '请使用 POST 请求');
            }
            $result = $this->buildConfig(is_array($param) ? $param : [], $this->sitemapFields);
            if (isset($result['error'])) {
                return to_assign(1, $result['error']);
            }

            //robots.txt 与本次提交的地图类型保持一致；数据库失败时恢复原文件。
            $previousRobots = [];
            $robotsError = '';
            $sitemapConfig = array_merge(get_config_group('sitemap'), $result['data']);
            if (!$this->syncRobotsSitemap($sitemapConfig, $previousRobots, $robotsError)) {
                return to_assign(1, 'robots.txt 更新失败：' . $robotsError);
            }

            $res = save_config_group('sitemap', $result['data']);
            if ($res === false) {
                $rollbackError = '';
                $rollbackOk = $this->restoreRobotsFile($previousRobots, $rollbackError);
                return to_assign(1, '保存失败，请检查 cms_config 表是否可写'
                    . ($rollbackOk ? '' : '；且恢复 robots.txt 失败：' . $rollbackError));
            }

            safe_add_log('edit', 0, $result['data'], 'Sitemap配置');

            return to_assign(0, '保存成功');
        }

        //把还没保存过的项补上默认值
        $config = get_config_group('sitemap');
        foreach ($this->sitemapFields as $key => $rule) {
            if (!array_key_exists($key, $config) || $config[$key] === '') {
                $config[$key] = $rule['default'];
            }
        }

        View::assign('config', $config);
        //站点根地址，给「访问」链接和前台生成入口用
        View::assign('siteUrl', $this->siteBaseUrl());
        return view();
    }

    /**
     * 站点根地址（带协议、不带结尾斜杠）
     *
     * 优先用「网站配置 → 站点域名」，没填就退回当前请求的域名
     */
    protected function siteBaseUrl(): string
    {
        $domain = trim((string) get_system_config('site_domain'));
        if ($domain !== '' && preg_match('#^https?://#i', $domain)) {
            return rtrim($domain, '/');
        }
        return rtrim((string) request()->domain(), '/');
    }

    /** 按已勾选的地图类型同步 robots.txt 中本项目管理的 Sitemap 行。 */
    protected function syncRobotsSitemap(array $config, array &$previous, string &$error): bool
    {
        $file = public_path() . 'robots.txt';
        $exists = is_file($file);
        $content = $exists ? @file_get_contents($file) : '';
        if ($content === false) {
            $error = '文件不可读：' . $file;
            return false;
        }
        $previous = ['exists' => $exists, 'content' => $content];

        $lines = preg_split('/\r\n|\r|\n/', $content);
        $lines = array_values(array_filter($lines, static function ($line) {
            return !preg_match('~^\s*Sitemap:\s*(?:https?://\S+)?/sitemap\.(?:xml|txt|html)\s*$~i', $line);
        }));
        $updated = rtrim(implode("\n", $lines), "\r\n");

        $siteUrl = $this->siteBaseUrl();
        foreach (['xml', 'txt', 'html'] as $type) {
            if ((int) ($config['sitemap_' . $type] ?? 0) === 1) {
                $updated .= ($updated === '' ? '' : "\n") . 'Sitemap: ' . $siteUrl . '/sitemap.' . $type;
            }
        }
        $updated .= "\n";

        $written = @file_put_contents($file, $updated, LOCK_EX);
        if ($written === false || $written !== strlen($updated)) {
            $error = '文件不可写：' . $file;
            $restoreError = '';
            if (!$this->restoreRobotsFile($previous, $restoreError)) {
                $error .= '；恢复原文件失败：' . $restoreError;
            }
            return false;
        }
        return true;
    }

    /** 数据库保存失败时恢复 robots.txt 的原始状态。 */
    protected function restoreRobotsFile(array $previous, string &$error): bool
    {
        $file = public_path() . 'robots.txt';
        if (!empty($previous['exists'])) {
            $content = (string) ($previous['content'] ?? '');
            $written = @file_put_contents($file, $content, LOCK_EX);
            if ($written === false || $written !== strlen($content)) {
                $error = '文件不可写：' . $file;
                return false;
            }
            return true;
        }

        if (is_file($file) && !@unlink($file)) {
            $error = '新建文件无法删除：' . $file;
            return false;
        }
        return true;
    }

    /**
     * SEO → 页面生成
     *
     * 只有 URL模式 =「生成静态」时才允许生成，否则页面上给个提示、按钮全禁用。
     * 真正的生成动作在前台（/home/createhtml/start|step），因为要读前台的 URL 规则和模板目录。
     *
     * 「选择栏目」的多选下拉用后台现成的栏目树（set_recursion 会按层级加 ├─ 缩进）。
     */
    public function createhtml()
    {
        $config = get_config_group('seo');
        $pseudo = (int) ($config['url_pseudo'] ?? 3);

        //1=动态URL 2=生成静态 3=伪静态化，只有 2 能生成静态页面
        $ready = $pseudo === 2;

        //栏目树（set_recursion 会按层级加 ├─ 缩进），给「选择栏目」的多选下拉用
        $navs = [];
        if ($ready) {
            foreach (set_recursion(get_navs()) as $nav) {
                $navs[] = ['id' => (int) $nav['id'], 'title' => (string) $nav['title']];
            }
        }

        View::assign('ready', $ready);
        View::assign('pseudoText', [1 => '动态URL', 2 => '生成静态', 3 => '伪静态化'][$pseudo] ?? '未知');
        View::assign('navsJson', json_encode($navs, JSON_UNESCAPED_UNICODE));

        //「生成Sitemap」弹窗里默认勾选哪几种地图，跟「SEO → Sitemap」里勾的「地图类型」保持一致
        $smCfg   = get_config_group('sitemap');
        $smTypes = [];
        foreach (['xml' => 'sitemap_xml', 'txt' => 'sitemap_txt', 'html' => 'sitemap_html', 'llms' => 'sitemap_llms'] as $type => $key) {
            if ((int) ($smCfg[$key] ?? 0) === 1) {
                $smTypes[] = $type;
            }
        }
        View::assign('sitemapTypesJson', json_encode($smTypes));

        View::assign('siteUrl', $this->siteBaseUrl());

        return view();
    }

    /**
     * 校验并组装要保存的配置
     *
     * 返回 ['error' => '原因'] 或 ['data' => [键 => 值]]
     * 参数没提交的项直接跳过（保持原值），不会因为页面少渲染一个字段就被清空
     *
     * @param array      $param  表单参数
     * @param array|null $fields 字段定义，不传就用 URL配置的 $this->fields
     * @return array
     */
    protected function buildConfig(array $param, ?array $fields = null): array
    {
        $fields = $fields ?? $this->fields;
        $data   = [];

        foreach ($fields as $key => $rule) {
            if (!array_key_exists($key, $param)) {
                continue;
            }

            $val = is_array($param[$key]) ? '' : trim((string) $param[$key]);

            if ($rule['type'] === 'int') {
                $val = (int) $val;
                if (!in_array($val, $rule['allow'], true)) {
                    return ['error' => $key . ' 的取值不合法，只能是 ' . implode(' / ', $rule['allow'])];
                }
                $data[$key] = $val;
                continue;
            }

            if ($rule['type'] === 'num') {
                //数字项（生成数量这类）：超出范围就夹到边界，别直接报错卡住保存
                $val = (int) $val;
                $min = $rule['min'] ?? 0;
                $max = $rule['max'] ?? PHP_INT_MAX;
                if ($val < $min) {
                    $val = $min;
                }
                if ($val > $max) {
                    $val = $max;
                }
                $data[$key] = $val;
                continue;
            }

            if ($rule['type'] === 'enum') {
                //下拉项：取值必须在白名单里
                if (!in_array($val, $rule['allow'], true)) {
                    return ['error' => $key . ' 的取值不合法'];
                }
                $data[$key] = $val;
                continue;
            }

            if ($rule['type'] === 'dir') {
                //目录路径：允许多级（a/b），去掉首尾斜杠，禁止 .. 和空段
                $val = trim(str_replace('\\', '/', $val), '/');
                if ($val === '') {
                    //留空：允许留空的项存空串（页面保存目录留空 = 存到根目录），否则回退默认值
                    $data[$key] = !empty($rule['empty']) ? '' : $rule['default'];
                    continue;
                }
                if (strpos($val, '..') !== false
                    || !preg_match('#^[A-Za-z0-9_\-/]{1,32}$#', $val)
                    || strpos($val, '//') !== false) {
                    return ['error' => '页面保存目录只能由字母、数字、下划线、短横线和斜杠组成，例如 html 或 static/html（留空表示存到根目录）'];
                }
                $data[$key] = $val;
                continue;
            }

            //str
            if ($val === '') {
                //允许留空的（百度推送 token）就存空串，其余回退默认值
                $data[$key] = !empty($rule['empty']) ? '' : $rule['default'];
                continue;
            }
            if (!empty($rule['pattern']) && !preg_match($rule['pattern'], $val)) {
                return ['error' => $rule['msg']];
            }
            $data[$key] = $val;
        }

        if (empty($data)) {
            return ['error' => '没有收到任何需要保存的配置项'];
        }

        return ['data' => $data];
    }
}
