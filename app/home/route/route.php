<?php
/**
 * @copyright Copyright (c) 2026 米柚工作室
 * @license https://opensource.org/licenses/Apache-2.0
 * @link https://www.miucms.com
 */

use think\facade\Route;

// 登录与会员中心短地址；语言前缀由 UrlRewrite 中间件先剥离，因此 /zh-hk/login、/zh-hk/user/index 同样命中。
Route::get('login$', 'Login/index');
Route::post('login_submit$', 'Login/login_submit');
Route::post('login_out$', 'Login/login_out');
Route::get('reg$', 'Login/reg');
Route::post('reg_submit$', 'Login/reg_submit');
Route::post('send_register_code$', 'Login/send_register_code');
Route::get('forgot$', 'Login/forgot');
Route::post('forgot_submit$', 'Login/forgot_submit');
Route::get('reset$', 'Login/reset');
Route::post('reset_submit$', 'Login/reset_submit');

Route::get('user/index$', 'User/index');
Route::get('user/info_edit$', 'User/info_edit');
Route::get('user/password_edit$', 'User/password_edit');
Route::get('user/history$', 'User/history');
Route::get('user/collect_list$', 'User/collect_list');
Route::get('user/invite$', 'User/invite');
Route::get('user/collect_state$', 'User/collect_state');
Route::post('user/collect_toggle$', 'User/collect_toggle');
Route::post('user/password_submit$', 'User/password_submit');
Route::post('user/edit_submit$', 'User/edit_submit');

Route::get('search$', 'Search/index');

/**
 * 前台（home 应用）路由
 *
 * 多应用模式下路由文件放在「应用目录/route/」下，不是项目根目录的 route/：
 * 框架里 MultiApp::getRoutePath() 会把路由目录指到 app/home/route/，
 * 根目录那个 route/app.php 在多应用模式下不会生效。
 *
 * 标签页的美化地址：
 *   /tag/12   →   home/tag/index?id=12
 * 原来的 /home/tag/index?id=12 照旧能访问，这里只是额外加一个更好看的别名。
 *
 * 注意：这条地址的第一段是 tag，UrlRewrite 中间件里的「功能页」白名单
 * （DYNAMIC_PATHS）也加了 tag，不然 /tag/12 会被伪静态解析当成文档页抢走
 * （url_parse 认「最后一段是数字 = 文档id」）。
 */
Route::get('tag/:id$', 'Tag/index');

/**
 * 前台留言表单：
 *   GET  /form/12      表单页（12 是表单id）
 *   POST /form/submit  提交留言
 * :id 限定成纯数字，这样 /form/submit 不会被 form/:id 抢走。
 */
Route::post('form/submit$', 'Form/submit');
Route::get('form/:id$', 'Form/index')->pattern(['id' => '\d+']);

/**
 * 插件前台路由
 *
 * 已启用插件在 plugins/<name>/route.php 里声明：
 *     return ['demo/hello' => 'plugins\demo\controller\Index@hello'];
 * 这里统一注册成闭包路由 —— 插件的控制器不在 home 应用的命名空间下，
 * 不能走默认的「控制器/方法」解析，只能显式实例化后转发。
 *
 * 插件没装、表不存在、插件报错时都只是「没有插件路由」，不能影响前台本身，
 * 所以整段包在 try 里。
 */
try {
    $__pm = \app\extend\plugin\PluginManager::instance();
    foreach ($__pm->getEnabledPluginNames() as $__plugin) {
        foreach ($__pm->getRoutes($__plugin) as $__rule => $__target) {
            Route::rule($__rule, function (...$__params) use ($__target) {
                $parts  = explode('@', (string) $__target, 2);
                $class  = $parts[0];
                $method = $parts[1] ?? 'index';
                if (!class_exists($class) || !method_exists($class, $method)) {
                    throw new \think\exception\HttpException(404, '插件路由不存在：' . $__target);
                }
                return (new $class())->{$method}(...$__params);
            });
        }
    }
} catch (\Throwable $e) {
    trace('插件前台路由加载失败：' . $e->getMessage(), 'error');
}
