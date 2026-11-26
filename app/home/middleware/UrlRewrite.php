<?php
/**
 * @copyright Copyright (c) 2026 米柚工作室
 * @license https://opensource.org/licenses/Apache-2.0
 * @link https://www.miucms.com
 */

declare (strict_types = 1);

namespace app\home\middleware;

use app\home\service\TemplateI18n;

/**
 * 前台伪静态 / 静态URL 解析
 *
 * 伪静态模式下浏览器访问的是 /bokezixun/ 、/bokezixun/127.html 这种
 * 「看起来像静态文件、其实不存在」的地址。服务器那边已经用伪静态规则把它们
 * 统一交给 index.php 了，但 ThinkPHP 里没有同名控制器，所以在路由分发之前，
 * 用这个中间件把地址翻译成前台真正的控制器地址：
 *
 *   /bokezixun/          →  nav/index      + id=2004
 *   /bokezixun/127.html  →  detail/index   + id=127
 *
 * 为什么不直接在路由文件里写 Route::miss()：
 *   ThinkPHP 的 miss 路由会把「所有没匹配到路由的地址」都接走，
 *   连 /home/user/index 这种正常控制器地址也会被吃掉。
 *   放在中间件里只改写「能认出来」的地址，其它地址原样放行。
 *
 * ------------------------------------------------------------------
 * 三种 URL 模式的区别（重点在「生成静态」这一种）
 * ------------------------------------------------------------------
 * · 动态 / 伪静态：翻译照常做。URL模式只决定「生成出来的链接长什么样」，
 *   已经存在的伪静态地址在任何模式下都能访问，切换模式不会把老链接打死。
 *
 * · 生成静态：**不做翻译**。静态文件存在时 nginx 直接把文件发出去了，
 *   请求根本走不到 PHP；能走到这里就说明这个地址的静态文件还没生成，
 *   那就该 404 —— 不能悄悄回退成动态渲染，否则「生成静态」等于没生效。
 *   静态模式下不启用栏目、文档的伪静态路由解析。
 *
 *   例外是「功能页」：登录、注册、用户中心、搜索、标签页、页面生成、Sitemap、广告JS接口，
 *   它们本来就没有静态文件，走的是各自的控制器地址，静态模式下照常访问。
 *   见 DYNAMIC_PATHS。
 */
class UrlRewrite
{
    /**
     * 静态模式下仍然放行的「功能页」地址（pathinfo 的第一段）
     *
     * 这些页面不是内容页，页面生成器不会、也不需要给它们出静态文件，
     * 所以静态模式下继续按正常的控制器地址访问。
     * 注意：多应用模式下 /home/xxx/yyy 里的 /home 会被剥掉，这里比对的是剩下的第一段。
     *
     * api 是广告位的 JS 接口（<script src="/home/api/adv?id=7">），
     * 它是动态地址、也必须是动态的（贴到别处引用时不能跟着伪静态规则走）。
     */
    // 短账号路由按首段放行，避免被伪静态内容地址解析拦截。
    const DYNAMIC_PATHS = [
        'login', 'login_submit', 'login_out', 'reg', 'reg_submit', 'send_register_code',
        'forgot', 'forgot_submit', 'reset', 'reset_submit', 'user', 'search', 'tag',
        'form', 'createhtml', 'sitemap', 'api', 'captcha', 'captcha.html',
    ];

    public function handle($request, \Closure $next)
    {
        $path = trim((string) $request->pathinfo(), '/');

        //非默认语言使用 /<locale>/ 前缀。去掉前缀后继续沿用原有路由解析。
        $withoutLocale = TemplateI18n::stripLocalePrefix($path);
        if ($withoutLocale !== $path) {
            $path = $withoutLocale;

            //带语言前缀时 MultiApp 会把语言段当作应用名，按默认应用兜底但不会剥掉 /home。
            //这里补做一次原本由 MultiApp 完成的应用段剥离，兼容 url_entry() 生成的动态链接。
            if ($path === 'home') {
                $path = '';
            } elseif (strpos($path, 'home/') === 0) {
                $path = substr($path, 5);
            }

            $request->setPathinfo($path);
        }

        //生成静态模式：内容页（首页、栏目页、文档页）只认静态文件
        if (url_config()['mode'] === 2) {
            //首页（pathinfo 为空）也在内：能走到 PHP 说明 public/index.html 还没生成
            if ($path === '' || !$this->isDynamicPath($path)) {
                abort(404);
            }
            return $next($request);
        }

        if ($path === '') {
            return $next($request);
        }

        //功能页（登录、用户中心、搜索、标签页、接口…）走各自的路由，不做伪静态翻译。
        //不先放行的话，像 /tag/12 这种「第一段是功能页、后面是数字」的地址会被
        //url_parse 当成文档页抢走（它认「最后一段是数字 = 文档id」），路由就轮不上了。
        if ($this->isDynamicPath($path)) {
            return $next($request);
        }

        $result = url_parse($path);

        if ($result['type'] === 'home') {
            //静态模式下的首页地址（/html/index.html）统一回到 /
            return redirect(home_url());
        }

        if ($result['type'] === 'nav') {
            $navId = (int) $result['nav']['id'];
            //先告诉栏目相关函数「当前栏目是谁」，菜单才能高亮
            miu_current_nav_id($navId);
            $request->setPathinfo('nav/index');
            $request->withGet([
                'id'   => $navId,
                'page' => (int) $result['page'],
            ]);
        } elseif ($result['type'] === 'doc') {
            $navId = (int) ($result['doc']['nav_id'] ?? 0);
            miu_current_nav_id($navId);
            $request->setPathinfo('detail/index');
            $request->withGet(['id' => (int) $result['doc']['id']]);
        }
        //认不出来就原样放行，交给正常的控制器解析，该 404 的还是会 404

        return $next($request);
    }

    /**
     * 这个地址是不是静态模式下要放行的「功能页」
     *
     * @param string $path pathinfo（已经去掉两头的 /）
     * @return bool
     */
    protected function isDynamicPath(string $path): bool
    {
        $first = strtolower(explode('/', $path)[0]);

        return in_array($first, self::DYNAMIC_PATHS, true);
    }
}
