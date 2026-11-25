<?php
/**
 * @copyright Copyright (c) 2026 米柚工作室
 * @license https://opensource.org/licenses/Apache-2.0
 * @link https://www.miucms.com
 */

declare (strict_types = 1);

namespace app\home\middleware;

use app\home\service\TemplateI18n;
use app\extend\plugin\PluginManager;
use app\services\PluginService;
use think\facade\Cache;
use think\facade\Db;
use think\facade\View;

/**
 * 前台模板切换 + 插件标签/钩子
 *
 * 普通前台页面使用项目根目录 templates/<模板名>/ 支持主题切换；会员登录和会员中心
 * 页面由控制器指定 app/home/view 下的公共视图，模板内部的 head/header/footer 仍从当前主题解析。
 * 模板放在 docroot 外，浏览器请求不到模板源码；模板自带的静态资源由
 * sync_template_assets() 发布到 public/skin/<模板名>/ 供前台访问。
 *
 * 顺便按当前模板算好静态资源地址（{__CSS__} 这些）：
 * 模板自带 static/ 时指向 /skin/<模板名>/，由 sync_template_assets() 在后台
 * 切换模板或清缓存时把文件发布过去；没自带就沿用 config/view.php 的全局默认。
 *
 * 为什么用中间件而不是控制器基类：前台 Login 控制器没有继承 BaseController，
 * 放在中间件里才能覆盖所有前台请求。
 */
class Template
{
    public function handle($request, \Closure $next)
    {
        //模板语言只由当前模板语言包和 URL 前缀决定，不继承后台语言 Cookie。
        if (TemplateI18n::isDisabledLocalePath((string) $request->pathinfo())) {
            abort(404);
        }
        TemplateI18n::beginRequest((string) $request->pathinfo(), (string) $request->url());
        miu_assign_page_meta();

        // 全站计数只统计前台可展示的有效文档和启用标签，并复用各自的缓存失效标签。
        $documentTotal = Cache::get('publicGlobalDocumentTotal_v1');
        if (!is_numeric($documentTotal)) {
            try {
                $documentTotal = (int) Db::name('document')
                    ->where('is_del', 0)
                    ->where('status', 1)
                    ->where('arcrank', '>=', 0)
                    ->count();
                Cache::tag('Document')->set('publicGlobalDocumentTotal_v1', $documentTotal, 86400);
            } catch (\Throwable $e) {
                trace('前台文档总数读取失败：' . $e->getMessage(), 'error');
                $documentTotal = 0;
            }
        }

        $tagTotal = Cache::get('publicGlobalTagTotal_v1');
        if (!is_numeric($tagTotal)) {
            try {
                $tagTotal = (int) Db::name('keywords')->where('status', 1)->count();
                Cache::tag('homeTag')->set('publicGlobalTagTotal_v1', $tagTotal, 86400);
            } catch (\Throwable $e) {
                trace('前台标签总数读取失败：' . $e->getMessage(), 'error');
                $tagTotal = 0;
            }
        }
        View::assign([
            'document_total' => (int) $documentTotal,
            'tag_total' => (int) $tagTotal,
        ]);

        $tpl  = get_current_template();
        $path = get_templates_path($tpl);

        //目录不存在就不覆盖，沿用 config/view.php 里的默认设置，避免整个前台报错
        if (is_dir($path)) {
            View::config([
                'view_path'          => $path,
                'tpl_replace_string' => template_asset_replace($tpl),
            ]);
        }

        //插件模板标签：把已启用插件的 taglib 追加到预加载列表
        //（标签类写在 plugins/<name>/taglib/Xxx.php，命名空间 plugins\<name>\taglib\Xxx）
        View::config([
            'taglib_pre_load' => implode(',', $this->taglibList()),
        ]);

        $response = $next($request);

        if ($response instanceof \think\Response && !$request->middleware('template_visual_preview', false)) {
            $html = (string) $response->getContent();
            if ($html !== '' && stripos($html, '</body>') !== false) {
                PluginService::templateParse($html);
                $response->content($html);
            }
        }

        return $response;
    }

    /**
     * 预加载的标签库：前台配置中的标签类和已启用插件声明的标签类。
     * @return array<int, string>
     */
    protected function taglibList(): array
    {
        $list = explode(',', (string) config('view.taglib_pre_load', 'app\home\taglib\Miu'));
        try {
            $pm = PluginManager::instance();
            foreach ($pm->getEnabledPluginNames() as $name) {
                foreach ($pm->getTaglibs($name) as $class) {
                    $list[] = $class;
                }
            }
        } catch (\Throwable $e) {
            trace('插件标签加载失败：' . $e->getMessage(), 'error');
        }
        return array_values(array_unique($list));
    }
}
