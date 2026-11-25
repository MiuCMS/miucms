<?php
/**
 * @copyright Copyright (c) 2026 米柚工作室
 * @license https://opensource.org/licenses/Apache-2.0
 * @link https://www.miucms.com
 */

declare (strict_types = 1);

namespace app\admin\middleware;

use app\services\AdminDemo;
use think\facade\Cache;
use think\facade\Db;
use think\facade\Session;

class Auth
{
    public function handle($request, \Closure $next)
    {
        if (AdminDemo::isReadOnly()) {
            // 路由解析后检查实际执行的操作，兼容后台别名及动态地址。
            app()->middleware->unshift(function ($request, \Closure $dispatch) {
                $route = $request->controller(true) . '/' . $request->action(true);
                if (AdminDemo::blocks($request, $route)) {
                    return AdminDemo::denied($request);
                }
                if ($route === 'think/captcha/captchacontroller/index') {
                    return $dispatch($request);
                }
                return $this->authorize($request, $dispatch, $route);
            }, 'route');

            return $next($request);
        }

        return $this->authorize($request, $next);
    }

    private function authorize($request, \Closure $next, ?string $demoRoute = null)
    {
        //获取模块名称
        $controller = app('http')->getName();
        $pathInfo = $demoRoute ?? str_replace('.' . $request->ext(), '', $request->pathInfo());
        $action = explode('/', $pathInfo)[0];
        //dump($controller);exit;
        if ($pathInfo == '' || $action == '') {
            redirect((string) url('admin/index/index'))->send();
            exit;
        }
        //验证用户登录
        if ($action !== 'login') {
            $session_admin = get_config('app.session_admin');
            $sessionAdmin = Session::get($session_admin);
            $uid = is_array($sessionAdmin) ? (int) ($sessionAdmin['id'] ?? 0) : 0;
            if ($uid < 1 || !Db::name('Admin')->where('id', $uid)->where('status', 1)->field('id')->find()) {
                Session::delete($session_admin);
                if ($request->isAjax()) {
                    return to_assign(404, '请先登录');
                } else {
                    //未登录 → 跳登录页（这里不能跳首页，否则会无限重定向）
                    redirect((string) url('admin/login/index'))->send();
                    exit;
                }
            }
            // 公共 API 仍按所读取/修改的后台功能鉴权，不能因控制器名而绕过角色权限。
            if (strtolower($action) === 'api' && $uid !== 1) {
                $method = strtolower(explode('/', $pathInfo)[1] ?? '');
                $permissions = [
                    'get_user_list' => ['user/index'],
                    'get_admin_list' => ['admin/index'],
                    'get_log_list' => ['log/index'], 'log_list' => ['log/index'],
                    'email_test' => ['conf/index'], 'email_to' => ['conf/index'],
                    'save_preset' => ['document/add', 'document/edit'],
                    'get_rule' => ['role/index', 'rule/index'],
                ];
                if (isset($permissions[$method])) {
                    $allowed = false;
                    foreach ($permissions[$method] as $permission) {
                        if ($this->checkAuth($controller, $permission, explode('/', $permission)[0], $uid)) { $allowed = true; break; }
                    }
                    if (!$allowed) return to_assign(202, '你没有权限,请联系超级管理员！');
                }
            }
            // 验证用户访问权限
            if ($action !== 'index' && $action !== 'api' && $action !== 'miuupload') {
                if (!$this->checkAuth($controller, $pathInfo, $action, $uid)) {
                    if ($request->isAjax()) {
                        return to_assign(202, '你没有权限,请联系超级管理员！');
                    } else {
                        echo '<div style="text-align:center;color:red;margin-top:20%;">您没有权限,请联系超级管理员</div>';exit;
                    }
                }
            }
        }
        return $next($request);
    }

    /**
     * 验证用户访问权限
     * @DateTime 2020-12-21
     * @param    string $controller 当前访问控制器
     * @param    string $action 当前访问方法
     * @param    string $uid 当前用户id
     * @return   [type]
     */
    protected function checkAuth($controller, $pathInfo, $action, $uid)
    {
        $auth_list = self::allowedRoutes((int) $uid);
        $pathUrl = strtolower($controller . '/' . $pathInfo);
        if (in_array($pathUrl, $auth_list, true)) return true;
        if ($pathUrl !== 'admin/plugin/run') return false;

        // 角色获授某个插件菜单时，只放行该菜单的地址。
        $plugin = (string) input('name/s', '');
        $pluginController = (string) input('c/s', 'Index');
        $pluginAction = (string) input('a/s', 'index');
        $query = http_build_query(['name' => $plugin, 'c' => $pluginController, 'a' => $pluginAction]);
        return in_array($pathUrl . '?' . strtolower($query), $auth_list, true);
    }

    public static function allowedRoutes(int $uid): array
    {
        $cacheKey = 'RulesSrc' . $uid;
        $routes = Cache::get($cacheKey);
        if (is_array($routes)) return $routes;

        $groups = Db::name('AdminGroupAccess')->alias('a')
            ->join('AdminGroup g', 'a.group_id = g.id')
            ->where('a.uid', $uid)->where('g.status', 1)->column('g.rules');
        $ids = [];
        foreach ($groups as $rules) {
            $ids = array_merge($ids, explode(',', (string) $rules));
        }
        $ids = array_unique(array_filter(array_map('intval', $ids)));
        $routes = $ids ? Db::name('AdminRule')->whereIn('id', $ids)->column('src') : [];
        $routes = array_map(static fn($route): string => strtolower((string) $route), $routes);
        Cache::tag('adminRules')->set($cacheKey, $routes, 36000);
        return $routes;
    }
}
