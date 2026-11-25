<?php
/**
 * @copyright Copyright (c) 2026 米柚工作室
 * @license https://opensource.org/licenses/Apache-2.0
 * @link https://www.miucms.com
 */

declare (strict_types = 1);

namespace app\home\controller;

use app\home\validate\UserCheck;
use think\exception\ValidateException;
use think\facade\Cache;
use think\facade\Db;
use think\facade\Session;
use think\facade\View;

class Login
{
    //登录
    public function index()
    {
        if (!empty(get_login_user('id'))) {
            redirect(url_entry() . '/user/index')->send();
        }
        add_user_log('view', '登录页面');
        return view(app_path('view') . 'login_index.html');
    }

    public function forgot()
    {
        if (!empty(get_login_user('id'))) {
            redirect(url_entry() . '/user/index')->send();
        }
        add_user_log('view', '找回密码页面');
        return view(app_path('view') . 'login_forgot.html');
    }

    public function forgot_submit()
    {
        if (!request()->isPost()) {
            return to_assign(1, '错误的请求方式');
        }
        $param = get_params();
        foreach (['email', 'captcha'] as $field) {
            if (!isset($param[$field]) || !is_scalar($param[$field])) {
                return to_assign(1, '请填写邮箱和图形验证码');
            }
        }
        try {
            validate(UserCheck::class)->scene('forgot')->check($param);
        } catch (ValidateException $e) {
            return to_assign(1, $e->getError());
        }

        $email = strtolower(trim((string) $param['email']));
        $config = get_system_config() ?: [];
        $siteDomain = rtrim(trim((string) ($config['site_domain'] ?? '')), '/');
        $domainParts = $siteDomain === '' ? false : parse_url($siteDomain);
        if (!filter_var($siteDomain, FILTER_VALIDATE_URL)
            || !is_array($domainParts)
            || !in_array(($domainParts['scheme'] ?? ''), ['http', 'https'], true)
            || empty($domainParts['host'])) {
            return to_assign(1, '请先在网站配置中填写正确的网站域名');
        }

        $ipKey = 'homePasswordResetIp_' . hash('sha256', (string) request()->ip());
        $emailKey = 'homePasswordResetEmail_' . hash('sha256', $email);
        if (Cache::has($ipKey) || Cache::has($emailKey)) {
            return to_assign(1, '请求太频繁，请稍后再试');
        }
        Cache::set($ipKey, 1, 60);
        Cache::set($emailKey, 1, 300);

        $user = Db::name('User')->where('email', $email)->where('status', 1)->field('id,username,email')->find();
        if ($user) {
            $token = bin2hex(random_bytes(32));
            $tokenHash = hash('sha256', $token);
            $expireTime = time() + 1800;
            try {
                Db::name('UserPasswordReset')->insert([
                    'uid' => (int) $user['id'],
                    'email' => (string) $user['email'],
                    'token_hash' => $tokenHash,
                    'expire_time' => $expireTime,
                    'create_time' => time(),
                ]);
                $entry = url_entry();
                $resetUrl = $siteDomain . $entry . '/reset?token=' . rawurlencode($token);
                $siteName = trim((string) ($config['site_name'] ?? '')) ?: '会员中心';
                $template = trim((string) ($config['password_reset_template'] ?? '')) ?: default_password_reset_email_template();
                //兼容后台已保存的旧模板，确保邮件里始终带有可复制的重置链接原文。
                if (!str_contains($template, '%reset_url_text%')) {
                    $template .= '<p>如果按钮无法打开，请复制下面的链接到浏览器中：</p><p style="word-break:break-all;color:#596159">%reset_url_text%</p>';
                }
                $escape = static fn($value) => htmlspecialchars((string) $value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
                $content = strtr($template, [
                    '%site_name%' => $escape($siteName),
                    '%username%' => $escape($user['username']),
                    '%email%' => $escape($user['email']),
                    '%reset_url%' => $escape($resetUrl),
                    '%reset_url_text%' => $escape($resetUrl),
                    '%expire_minutes%' => '30',
                ]);

                if (send_email($email, '找回密码 - ' . $siteName, $content)) {
                    Db::name('UserPasswordReset')->where('uid', (int) $user['id'])->where('token_hash', '<>', $tokenHash)->delete();
                } else {
                    Db::name('UserPasswordReset')->where('token_hash', $tokenHash)->delete();
                    \think\facade\Log::error('找回密码邮件发送失败，会员ID：' . (int) $user['id']);
                }
            } catch (\Throwable $e) {
                Db::name('UserPasswordReset')->where('token_hash', $tokenHash)->delete();
                \think\facade\Log::error('找回密码处理失败：' . $e->getMessage());
            }
        }

        return to_assign(0, '如果该邮箱已绑定会员账号，重置邮件已发送，请查收');
    }

    public function reset()
    {
        $tokenParam = request()->param('token', '');
        $token = is_scalar($tokenParam) ? trim((string) $tokenParam) : '';
        $resetError = '重置链接无效或已过期，请重新申请。';
        if (preg_match('/^[a-f0-9]{64}$/i', $token)) {
            $reset = Db::name('UserPasswordReset')
                ->where('token_hash', hash('sha256', $token))
                ->where('expire_time', '>', time())
                ->find();
            if ($reset) {
                $user = Db::name('User')->where('id', (int) $reset['uid'])->field('email,status')->find();
                if ($user && (int) $user['status'] === 1
                    && hash_equals(strtolower((string) $user['email']), strtolower((string) $reset['email']))) {
                    $resetError = '';
                }
            }
        } else {
            $token = '';
        }
        add_user_log('view', '重置密码页面');
        View::assign('resetToken', $resetError === '' ? $token : '');
        View::assign('resetError', $resetError);
        return view(app_path('view') . 'login_reset.html');
    }

    public function reset_submit()
    {
        if (!request()->isPost()) {
            return to_assign(1, '错误的请求方式');
        }
        $param = get_params();
        foreach (['token', 'new_password', 'new_password_confirm'] as $field) {
            if (!isset($param[$field]) || !is_scalar($param[$field])) {
                return to_assign(1, '请完整填写重置密码信息');
            }
        }
        $token = trim((string) $param['token']);
        $newPassword = (string) $param['new_password'];
        if (!preg_match('/^[a-f0-9]{64}$/i', $token)) {
            return to_assign(1, '重置链接无效或已过期，请重新申请');
        }
        if (mb_strlen($newPassword, 'UTF-8') < 6 || mb_strlen($newPassword, 'UTF-8') > 128) {
            return to_assign(1, '新密码长度须为 6 至 128 位');
        }
        if ($newPassword !== (string) $param['new_password_confirm']) {
            return to_assign(1, '两次输入的新密码不一致');
        }

        try {
            $result = Db::transaction(function () use ($token, $newPassword) {
                $reset = Db::name('UserPasswordReset')
                    ->where('token_hash', hash('sha256', $token))
                    ->where('expire_time', '>', time())
                    ->lock(true)
                    ->find();
                if (!$reset) {
                    return ['error' => '重置链接无效或已过期，请重新申请'];
                }
                $user = Db::name('User')->where('id', (int) $reset['uid'])->where('status', 1)->lock(true)->find();
                if (!$user || !hash_equals(strtolower((string) $user['email']), strtolower((string) $reset['email']))) {
                    return ['error' => '重置链接无效或已过期，请重新申请'];
                }

                $updated = Db::name('User')->where('id', (int) $user['id'])->update([
                    'password' => hash_user_password($newPassword),
                    'salt' => '',
                    'update_time' => time(),
                ]);
                if ($updated === false) {
                    throw new \RuntimeException('密码写入失败');
                }
                Db::name('UserPasswordReset')->where('uid', (int) $user['id'])->delete();
                return ['success' => true];
            });
        } catch (\Throwable $e) {
            return to_assign(1, '密码重置失败，请稍后重试');
        }
        if (isset($result['error'])) {
            return to_assign(1, $result['error']);
        }
        clear_cache('homeUser');
        return to_assign(0, '密码重置成功，请使用新密码登录');
    }

    //提交登录
    public function login_submit()
    {
        if (!request()->isPost()) {
            return to_assign(1, '错误的请求方式');
        }
        $param = get_params();
        foreach (['name', 'password', 'captcha'] as $field) {
            if (!isset($param[$field]) || !is_scalar($param[$field])) {
                return to_assign(1, '登录信息格式不正确');
            }
        }
        try {
            validate(UserCheck::class)->scene('login')->check($param);
        } catch (ValidateException $e) {
            // 验证失败 输出错误信息
            return to_assign(1, $e->getError());
        }

        $account = (string) $param['name'];
        $email = strtolower(trim($account));
		$loginRateKey = 'homeLoginFailures_' . hash('sha256', (string) request()->ip() . '|' . strtolower(trim($account)));
		if ((int) Cache::get($loginRateKey, 0) >= 10) {
			return to_assign(1, '登录失败次数过多，请 5 分钟后再试');
		}
        $user = filter_var($email, FILTER_VALIDATE_EMAIL)
            ? Db::name('User')->where('email', $email)->where('status', '<>', -1)->find()
            : null;
        if (empty($user)) {
            $user = Db::name('User')->where('username', $account)->where('status', '<>', -1)->find();
        }
        if (empty($user)) {
			Cache::set($loginRateKey, (int) Cache::get($loginRateKey, 0) + 1, 300);
            return to_assign(1, '用户名或密码错误');
        }
        if (!verify_user_password($param['password'], $user['password'], $user['salt'])) {
			Cache::set($loginRateKey, (int) Cache::get($loginRateKey, 0) + 1, 300);
            return to_assign(1, '用户名或密码错误');
        }
        if ((int) ($user['status'] ?? 0) !== 1) {
            return to_assign(1, '该用户禁止登录,请于平台联系');
        }
		Cache::delete($loginRateKey);
        if (user_password_needs_rehash($user['password'])) {
            $user['password'] = hash_user_password($param['password']);
            $user['salt'] = '';
            Db::name('User')->where('id', (int) $user['id'])->update([
                'password' => $user['password'],
                'salt' => '',
            ]);
        }
        $data = [
            'last_login_time' => time(),
            'last_login_ip' => request()->ip(),
            'login_num' => $user['login_num'] + 1,
        ];
        Db::name('user')->where(['id' => $user['id']])->update($data);
        clear_cache('homeUser');
        $userInfo = [
            'id' => $user['id'],
            'username' => $user['username'],
            'nickname' => $user['nickname'],
            'headimgurl' => $user['headimgurl'],
            'credential' => hash('sha256', (string) $user['password']),
        ];
        $session_user = get_config('app.session_user');
        Session::regenerate(true);
        Session::set($session_user, $userInfo);
        $token = make_token();
        set_cache($token, $userInfo, 7200);
        $userInfo['token'] = $token;
        //插件钩子：用户登录（插件可修改 $userInfo）
        \app\services\PluginService::userLogin($userInfo);
        add_user_log('login', '', $user['id']);
        return to_assign(0, '登录成功', $userInfo);
    }

    //退出登录
    public function login_out()
    {
        if (!request()->isPost()) {
            return to_assign(1, '错误的请求方式');
        }
        $session_user = get_config('app.session_user');
        $user = Session::get($session_user);
        if (is_array($user)) {
            //插件钩子：用户退出
            \app\services\PluginService::userLogout($user);
        }
        Session::delete($session_user);
        //redirect(url_entry() . '/login')->send();
        return to_assign(0, "退出成功");
    }
    
    //注册
    public function reg()
    {
        if (!empty(get_login_user('id'))) {
            redirect(url_entry() . '/user/index')->send();
        }
        $hasInviteParam = request()->has('invite_id', 'get');
        $inviteId = (int) Session::get('homeRegisterInviteId', 0);
        if ($hasInviteParam) {
            // 带了新的邀请参数时才重置邀请人；不带参数重开注册页时沿用已记录的邀请关系。
            Session::delete('homeRegisterInviteId');
            $inviteParam = request()->get('invite_id', '');
            if (!is_scalar($inviteParam) || !ctype_digit((string) $inviteParam) || (int) $inviteParam < 1) {
                return miu_front_notice('参数无效', '<p>邀请链接中的 invite_id 无效，请检查链接后重试。</p>', 400);
            }
            $inviteId = (int) $inviteParam;
        }
        $inviterName = '';
        if ($inviteId > 0) {
            $inviter = Db::name('User')->where(['id' => $inviteId, 'status' => 1])->field('id,nickname,username')->find();
            if ($inviter) {
                Session::set('homeRegisterInviteId', (int) $inviter['id']);
                $inviterName = trim((string) ($inviter['nickname'] ?: $inviter['username']));
            } else {
                Session::delete('homeRegisterInviteId');
                if ($hasInviteParam) {
                    return miu_front_notice('参数无效', '<p>邀请链接对应的用户不存在或已停用，请联系邀请人获取正确链接。</p>', 400);
                }
            }
        }
        $config = get_system_config() ?: [];
        View::assign('registerEmailVerify', (int) ($config['register_email_verify'] ?? 0) === 1 ? 1 : 0);
        View::assign('registerInviterName', $inviterName);
        add_user_log('view', '注册页面');
        return view(app_path('view') . 'login_reg.html');
    }

    public function send_register_code()
    {
        if (!request()->isPost()) {
            return to_assign(1, '错误的请求方式');
        }
        $config = get_system_config() ?: [];
        if ((int) ($config['register_email_verify'] ?? 0) !== 1) {
            return to_assign(1, '注册邮箱验证未开启');
        }
        $param = get_params();
        foreach (['email', 'captcha'] as $field) {
            if (!isset($param[$field]) || !is_scalar($param[$field])) {
                return to_assign(1, '请填写邮箱和图形验证码');
            }
        }
        try {
            validate(UserCheck::class)->scene('registerEmailCode')->check($param);
        } catch (ValidateException $e) {
            return to_assign(1, $e->getError());
        }

        $email = strtolower(trim((string) $param['email']));
        if (Db::name('User')->where('email', $email)->where('status', '<>', -1)->count() > 0) {
            return to_assign(0, '如果该邮箱可用于注册，验证码会发送，请查收');
        }
        $emailHash = hash('sha256', $email);
        $ipHash = hash('sha256', (string) request()->ip());
        $emailRateKey = 'homeRegisterEmailRate_' . $emailHash;
        $ipRateKey = 'homeRegisterEmailIpRate_' . $ipHash;
        if (Cache::has($emailRateKey) || Cache::has($ipRateKey)) {
            return to_assign(1, '验证码发送过于频繁，请稍后再试');
        }
        Cache::set($emailRateKey, 1, 60);
        Cache::set($ipRateKey, 1, 60);

        $code = (string) random_int(100000, 999999);
        $siteName = trim((string) ($config['site_name'] ?? '')) ?: '会员中心';
        $template = trim((string) ($config['email_verify_template'] ?? $config['register_email_template'] ?? '')) ?: default_email_verify_template();
        $escape = static fn($value) => htmlspecialchars((string) $value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
        $content = strtr($template, [
            '%site_name%' => $escape($siteName),
            '%email%' => $escape($email),
            '%code%' => $escape($code),
            '%expire_minutes%' => '10',
        ]);
        try {
            if (!send_email($email, '注册邮箱验证码 - ' . $siteName, $content)) {
                throw new \RuntimeException('邮件发送失败');
            }
        } catch (\Throwable $e) {
            \think\facade\Log::error('注册邮箱验证码发送失败：' . $e->getMessage());
            return to_assign(1, '验证码邮件发送失败，请检查邮箱配置后重试');
        }

        Cache::set('homeRegisterEmailCode_' . $emailHash, [
            'hash' => password_hash($code, PASSWORD_DEFAULT),
            'expires_at' => time() + 600,
            'attempts' => 0,
        ], 600);
        return to_assign(0, '验证码已发送，请查收邮箱');
    }

    //提交注册
    public function reg_submit()
    {
        if (!request()->isPost()) {
            return to_assign(1, '错误的请求方式');
        }
        $requestParam = get_params();
        $param = array_intersect_key($requestParam, array_flip(['username', 'email', 'pwd', 'pwd_confirm', 'captcha', 'email_code']));
        foreach (array_keys($param) as $field) {
            if (isset($param[$field]) && !is_scalar($param[$field])) {
                return to_assign(1, '注册信息格式不正确');
            }
        }
        try {
            validate(UserCheck::class)->scene('reg')->check($param);
        } catch (ValidateException $e) {
            // 验证失败 输出错误信息
            return to_assign(1, $e->getError());
        }

        $user = Db::name('User')->where(['username' => $param['username']])->find();
        if (!empty($user)) {
            return to_assign(1, '该账户已经存在');
        }

        $email = strtolower(trim((string) $param['email']));
        if (Db::name('User')->where('email', $email)->where('status', '<>', -1)->count() > 0) {
            return to_assign(1, '注册信息无法使用，请检查填写内容或尝试登录');
        }
        $config = get_system_config() ?: [];
        $verifyEmail = (int) ($config['register_email_verify'] ?? 0) === 1;
        $codeKey = 'homeRegisterEmailCode_' . hash('sha256', $email);
        if ($verifyEmail) {
            $emailCode = $param['email_code'] ?? null;
            if (!is_scalar($emailCode) || !preg_match('/^\d{6}$/', (string) $emailCode)) {
                return to_assign(1, '请输入 6 位邮箱验证码');
            }
            $record = Cache::get($codeKey);
            if (!is_array($record) || (int) ($record['expires_at'] ?? 0) <= time()) {
                Cache::delete($codeKey);
                return to_assign(1, '邮箱验证码已过期，请重新获取');
            }
            if (!password_verify((string) $emailCode, (string) ($record['hash'] ?? ''))) {
                $attempts = (int) ($record['attempts'] ?? 0) + 1;
                if ($attempts >= 5) {
                    Cache::delete($codeKey);
                    return to_assign(1, '验证码错误次数过多，请重新获取');
                }
                $record['attempts'] = $attempts;
                Cache::set($codeKey, $record, max(1, (int) $record['expires_at'] - time()));
                return to_assign(1, '邮箱验证码不正确');
            }
        }

        $registerTime = time();
        $registerIp = request()->ip();
        $username = trim((string) $param['username']);
        $password = (string) $param['pwd'];

        $inviteId = (int) Session::get('homeRegisterInviteId', 0);
        if ($inviteId > 0 && !Db::name('User')->where(['id' => $inviteId, 'status' => 1])->field('id')->find()) {
            $inviteId = 0;
        }

        //插件钩子只接收服务端整理过的注册资料，不暴露密码确认、验证码或用户伪造字段。
        $hookData = [
            'username' => $username,
            'email' => $email,
            'register_time' => $registerTime,
            'register_ip' => $registerIp,
            'puid' => $inviteId,
        ];
        \app\services\PluginService::userRegister($hookData);
        $userData = array_merge($hookData, [
            'username' => $username,
            'email' => $email,
            'password' => hash_user_password($password),
            'salt' => '',
            'register_time' => $registerTime,
            'register_ip' => $registerIp,
            'puid' => $inviteId,
        ]);
        try {
            $uid = Db::name('User')->strict(false)->field(true)->insertGetId($userData);
        } catch (\Throwable $e) {
            if (is_duplicate_key_error($e)) {
                return to_assign(1, '账号或邮箱已被使用，请检查后重试');
            }
            throw $e;
        }
        \app\services\PluginService::userRegistered((int) $uid);
        Session::delete('homeRegisterInviteId');
        if ($inviteId > 0) {
            clear_cache('homeUserInvite');
        }
        if ($verifyEmail) {
            Cache::delete($codeKey);
        }
        add_user_log('reg', '', $uid);
        return to_assign(0, '注册成功，请登录', $uid);
    }

}
