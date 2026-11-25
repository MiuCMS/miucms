<?php
/**
 * @copyright Copyright (c) 2026 米柚工作室
 * @license https://opensource.org/licenses/Apache-2.0
 * @link https://www.miucms.com
 */

declare (strict_types = 1);

namespace app\home\controller;

use app\services\AdminDemo;
use think\facade\Session;

/**
 * 前台 Sitemap 生成入口
 *
 * 为什么放在前台（home 应用）：
 * 生成地图要读前台的 URL 规则（nav_url / doc_url），那些函数在 home 的公共文件里；
 * 后台应用和命令行直接加载它们会跟自己的同名函数冲突（比如两边都有 get_navs），
 * 所以统一由 home 这边执行。
 *
 * 谁调用：
 *   - 后台「SEO → Sitemap」页面的「手工更新」按钮（POST type=xml|txt|html|llms|all）
 *   - 命令行 php think sitemap 用的是同一个 sitemap_build()，不经过这里
 *
 * 只允许已登录的后台管理员调用（后台和前台共用同一个 session），避免被外部随便触发。
 */
class Sitemap
{
    /**
     * 生成地图文件（返回 JSON）
     *
     * @return \think\response\Json
     */
    public function build()
    {
        if (request()->method(true) !== 'POST' || !request()->isPost()) {
            return json(['code' => 1, 'msg' => '请使用 POST 请求'], 405, ['Allow' => 'POST']);
        }
        if (AdminDemo::isReadOnly()) {
            return AdminDemo::denied(request());
        }
        //后台登录时写的 session key 是 app.session_admin（默认 miu_admin）
        $admin = Session::get(get_config('app.session_admin'));
        if (empty($admin)) {
            return json(['code' => 1, 'msg' => '请先登录后台']);
        }

        $type = (string) request()->param('type', 'configured');
        if (!in_array($type, ['configured', 'all', 'xml', 'txt', 'html', 'llms'], true)) {
            $type = 'configured';
        }

        $result = sitemap_build($type);

        if (!empty($result['errors'])) {
            return json(['code' => 1, 'msg' => implode('；', $result['errors'])]);
        }

        return json([
            'code' => 0,
            'msg'  => '生成成功：' . implode('、', array_keys($result['files'])) . '，共 ' . $result['counts']['total'] . ' 条地址',
            'data' => $result,
        ]);
    }
}
