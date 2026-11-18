<?php
/**
 * @copyright Copyright (c) 2026 米柚工作室
 * @license https://opensource.org/licenses/Apache-2.0
 * @link https://www.miucms.com
 */

declare (strict_types = 1);

namespace app\home\middleware;

use think\facade\Db;
use think\facade\Session;

class Auth
{
    public function handle($request, \Closure $next)
    {
        //获取模块名称
        $controller = app('http')->getName();
        $pathInfo = str_replace('.' . $request->ext(), '', $request->pathInfo());
        $action = explode('/', $pathInfo)[0];
        // var_dump($pathInfo);
        //验证用户登录
        if ($action == 'user') {
            $session_user = get_config('app.session_user');
            $loginUser = get_login_user();
            $userId = is_array($loginUser) ? (int) ($loginUser['id'] ?? 0) : 0;
            if ($userId < 1 || !Db::name('User')->where('id', $userId)->where('status', 1)->field('id')->find()) {
                Session::delete($session_user);
                return $request->isAjax() ? to_assign(404, '请先登录') : redirect(url_entry() . '/login');
            }
        }
        return $next($request);
    }
}
