<?php
/**
 * @copyright Copyright (c) 2026 米柚工作室
 * @license https://opensource.org/licenses/Apache-2.0
 * @link https://www.miucms.com
 */

declare (strict_types = 1);

namespace app\admin\middleware;

use think\facade\Cache;
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
        //Cache::delete('RulesSrc' . $uid);
        if (!Cache::get('RulesSrc' . $uid) || !Cache::get('RulesSrc0')) {
            //用户所在权限组及所拥有的权限
            // 执行查询
            $user_groups = Db::name('AdminGroupAccess')
                ->alias('a')
                ->join("AdminGroup g", "a.group_id=g.id", 'LEFT')
                ->where("a.uid='{$uid}' and g.status='1'")
                ->select()
                ->toArray();
            $groups = $user_groups ?: [];
            $ids = []; //保存用户所属用户组设置的所有权限规则id
            foreach ($groups as $g) {
                $ids = array_merge($ids, explode(',', trim($g['rules'], ',')));
            }
            $ids = array_unique($ids);
            //读取所有权限规则
            $rules_all = Db::name('AdminRule')->field('src')->select();
            //读取用户组所有权限规则
            $rules = Db::name('AdminRule')->where('id', 'in', $ids)->field('src')->select();
            //循环规则，判断结果。
            $auth_list_all = [];
            $auth_list = [];
            foreach ($rules_all as $rule_all) {
                $auth_list_all[] = strtolower($rule_all['src']);
            }
            foreach ($rules as $rule) {
                $auth_list[] = strtolower($rule['src']);
            }
            //规则列表结果保存到Cache
            Cache::tag('adminRules')->set('RulesSrc0', $auth_list_all, 36000);
            Cache::tag('adminRules')->set('RulesSrc' . $uid, $auth_list, 36000);
        } else {
            $auth_list_all = Cache::get('RulesSrc0');
            $auth_list = Cache::get('RulesSrc' . $uid);
        }

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
}
