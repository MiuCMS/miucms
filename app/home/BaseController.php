<?php
/**
 * @copyright Copyright (c) 2026 米柚工作室
 * @license https://opensource.org/licenses/Apache-2.0
 * @link https://www.miucms.com
 */

declare (strict_types = 1);

namespace app\home;

use think\App;
use think\facade\View;
use think\exception\HttpResponseException;

/**
 * 控制器基础类
 */
abstract class BaseController
{
    /**
     * Request实例
     * @var \think\Request
     */
    protected $request;

    /**
     * 应用实例
     * @var \think\App
     */
    protected $app;

    /**
     * 是否批量验证
     * @var bool
     */
    protected $batchValidate = false;

    /**
     * 控制器中间件
     * @var array
     */
    protected $middleware = [];

    /**
     * 构造方法
     * @access public
     * @param  App  $app  应用对象
     */
    public function __construct(App $app)
    {
        $this->app = $app;
        $this->request = $this->app->request;
        // 控制器初始化
        $this->initialize();
    }

    // 初始化
    protected function initialize()
    {
        $user = ['isLogin' => 0];
        $params = [
            'module' => app('http')->getName(),
            'controller' => app('request')->controller(),
            'action' => app('request')->action(),
            'isLogin' => 0,
            'uid' => 0,
            'nickname' => '',
            'mobile_status' => 0,
            'version' => get_config('webconfig.version'),
        ];
		// 加载控制器语言包
		$lang = cookie('think_lang');
		if(empty($lang)){
			$lang = 'zh-cn';
		}
		\think\facade\Lang::setLangSet($lang);
        $langset = $this->app->lang->getLangSet();
        $this->app->lang->load([
            app_path() . 'lang/' . $langset . '.php'
        ]);

        $login_top = '';
        $info = $this->checkLogin();
        if ($info) {
            $user = [
                'isLogin'    => 1,
                'uid'        => (int) ($info['id'] ?? 0),
                'nickname'   => (string) ($info['nickname'] ?? ''),
                'username'   => (string) ($info['username'] ?? ''),
                'headimgurl' => (string) ($info['headimgurl'] ?? ''),
            ];
            $login_top = '<a class="nav-img" href="' . url_entry() . '/user/index"><img src="' . $user['headimgurl'] . '" alt="' . $user['username'] . '" />' . $user['username'] . '</a>';
            $params['isLogin'] = 1;
            $params['uid'] = $user['uid'];
            $params['nickname'] = $user['nickname'];
            $params['username'] = $user['username'];
        }
        $COMMON_NAV = get_navs();
        View::assign('COMMON_NAV', $COMMON_NAV);
        View::assign('webconfig', get_config('webconfig'));
        View::assign('user', $user);
        View::assign('params', $params);
        View::assign('login_top', $login_top);

        //插件钩子：控制器执行前（action_end 在 PluginInit 中间件里触发）
        if (!$this->request->middleware('template_visual_preview', false)) {
            \app\services\PluginService::actionBegin(
                (string) app('request')->controller(),
                (string) app('request')->action()
            );
        }
    }
    // 检测用户登录状态
    protected function checkLogin()
    {
        $session_user = get_config('app.session_user');
        $login_user = get_login_user();
        if ($login_user && is_array($login_user)) {
            return $login_user;
        } else {
            return false;
        }
    }
	//页面跳转方法
	public function redirectTo(...$args)
    {
        throw new HttpResponseException(redirect(...$args));
    }
}
