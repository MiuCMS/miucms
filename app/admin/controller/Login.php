<?php
/**
 * @copyright Copyright (c) 2024 米柚
 * @license https://opensource.org/licenses/Apache-2.0
 * @link https://www.miucms.com
 */

declare (strict_types = 1);

namespace app\admin\controller;

use app\admin\validate\UserCheck;
use think\exception\ValidateException;
use think\facade\Cache;
use think\facade\Db;
use think\facade\Session;

class Login
{
    //登录
    public function index()
    {
        return View();
    }
	
    //提交登录
    public function login_submit()
    {
        if (!request()->isPost()) {
            return to_assign(1, '请使用 POST 请求');
        }
        $param = get_params();
        foreach (['username', 'password', 'captcha'] as $key) {
            if (!isset($param[$key]) || !is_scalar($param[$key])) {
                return to_assign(1, '登录信息格式不正确');
            }
        }
        try {
            validate(UserCheck::class)->check($param);
        } catch (ValidateException $e) {
            // 验证失败 输出错误信息
            return to_assign(1, $e->getError());
        }
		$loginRateKey = 'adminLoginFailures_' . hash('sha256', (string) request()->ip());
		if ((int) Cache::get($loginRateKey, 0) >= 10) {
			return to_assign(1, '登录失败次数过多，请 5 分钟后再试');
		}

        $admin = Db::name('Admin')->where(['username' => $param['username']])->find();
        if (empty($admin)) {
			Cache::set($loginRateKey, (int) Cache::get($loginRateKey, 0) + 1, 300);
            return to_assign(1, '用户名或密码错误');
        }
        if (!verify_user_password($param['password'], $admin['pwd'], $admin['salt'])) {
			Cache::set($loginRateKey, (int) Cache::get($loginRateKey, 0) + 1, 300);
            return to_assign(1, '用户名或密码错误');
        }
		if ((int) ($admin['status'] ?? 0) !== 1) {
			return to_assign(1, '该用户禁止登录,请于系统所有者联系');
		}
		Cache::delete($loginRateKey);
        if (user_password_needs_rehash($admin['pwd'])) {
            $admin['pwd'] = hash_user_password($param['password']);
            $admin['salt'] = '';
            Db::name('Admin')->where('id', (int) $admin['id'])->update(['pwd' => $admin['pwd'], 'salt' => '']);
        }
        $data = [
            'last_login_time' => time(),
            'last_login_ip' => request()->ip(),
            'login_num' => $admin['login_num'] + 1,
        ];
        Db::name('admin')->where(['id' => $admin['id']])->update($data);
        $session_admin = get_config('app.session_admin');
        $adminInfo = $admin;
        unset($adminInfo['pwd'], $adminInfo['salt']);
        Session::regenerate(true);
        Session::set($session_admin, $adminInfo);
        $token = make_token();
        $adminCache = $adminInfo;
        $adminCache['token'] = $token;
        set_cache($token, $adminCache, 7200);
        $admin['token'] = $token;
        //插件钩子：管理员登录
        //注意：$admin 是 cms_admin 的整行，含 pwd（密码哈希）和 salt，不能原样给插件
        $hookAdmin = $admin;
        unset($hookAdmin['pwd'], $hookAdmin['salt']);
        \app\services\PluginService::adminLogin($hookAdmin);
        safe_add_log('login', $admin['id'], $data);
        return to_assign(0, '登录成功', ['uid' => $admin['id']]);
    }

    //退出登录
    public function login_out()
    {
        if (!request()->isPost()) {
            return to_assign(1, '请使用 POST 请求');
        }
        $session_admin = get_config('app.session_admin');
        $admin = Session::get($session_admin);
        if (is_array($admin)) {
            //插件钩子：管理员退出（同样去掉 pwd/salt 再给插件）
            $hookAdmin = $admin;
            unset($hookAdmin['pwd'], $hookAdmin['salt']);
            \app\services\PluginService::adminLogout($hookAdmin);
        }
        Session::delete($session_admin);
        //redirect('/')->send();
        return to_assign(0, "退出成功");
    }
}
