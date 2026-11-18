<?php
/**
 * @copyright Copyright (c) 2026 米柚工作室
 * @license https://opensource.org/licenses/Apache-2.0
 * @link https://www.miucms.com
 */

declare(strict_types=1);

namespace app\middleware;

use app\extend\plugin\Hook;

/**
 * 插件生命周期中间件（全局，注册在 app/middleware.php 最前面）
 *
 * 负责：
 *   1) 加载已启用插件的钩子
 *   2) 触发 module_init / app_init
 *   3) 控制器执行完后触发 action_end
 *
 * 说明：action_begin 不在这里触发 —— 全局中间件跑在路由匹配之前，
 * 此时拿不到控制器/方法名，所以 action_begin 放在两个 BaseController::initialize() 里。
 */
class PluginInit
{
    public function handle($request, \Closure $next)
    {
        $hook = Hook::instance();
        $hook->init();

        //模块名：home / admin / api …
        $module = app('http')->getName();
        $hook->trigger('module_init', $module);
        $hook->trigger('app_init');

        $originalUrl = (string) $request->url(true);
        $response = $next($request);
        if (app('http')->getName() === 'home' && $response instanceof \think\Response) {
            $hook->trigger('frontend_response', $request, $response, $originalUrl);
        }

        //控制器执行后：此时 request 上的 controller / action 已由路由分发填好
        $controller = (string) $request->controller();
        $action     = (string) $request->action();
        if ($controller !== '') {
            $hook->trigger('action_end', $controller, $action, $response);
        }

        return $response;
    }
}
