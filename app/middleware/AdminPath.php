<?php
/**
 * @copyright Copyright (c) 2026 米柚工作室
 * @license https://opensource.org/licenses/Apache-2.0
 * @link https://www.miucms.com
 */

declare (strict_types = 1);

namespace app\middleware;

use think\facade\Config;

/**
 * 后台入口路径动态化
 *
 * 从后台配置（网站配置 → 后台管理路径 admin_path）读取入口名，在 MultiApp 解析应用之前
 * 覆盖 app.app_map，从而实现在后台就能改后台访问路径。
 *
 * 时序说明：Http::runWithRequest() 先执行 loadMiddleware()（加载本文件所在的
 * app/middleware.php），之后才触发 HttpRun 事件把 MultiApp 追加到中间件队尾，
 * 所以本中间件一定在 MultiApp 之前执行，覆盖 app_map 能生效。
 *
 * 采用「合并」而非「替换」：config/app.php 里原有的静态 app_map 会保留，
 * 这样改了入口后旧地址依然可用，不会把自己锁在后台外面。
 * 想彻底禁用旧地址，把 config/app.php 里的静态 app_map 删空即可。
 */
class AdminPath
{
    /**
     * 不允许用作后台入口的名字
     * 前几个是 app 下的应用目录名，后几个是 public 下的真实路径（静态资源会冲突）
     */
    protected static $reserved = [
         'api', 'crud', 'exception', 'home', 'install', 'public', 'static', 'storage',
    ];

    public function handle($request, \Closure $next)
    {
        $path = $this->getAdminPath();

        if ($path !== '') {
            //动态配置放在最前：url() 内部用 array_search 反查应用名，取的是第一个匹配的键，
            //放最前才能保证页面里生成的链接都指向新入口
            $map = [$path => 'admin'] + (array) Config::get('app.app_map', []);
            Config::set(['app_map' => $map], 'app');
        }

        return $next($request);
    }

    /**
     * 校验后台入口名
     * 保存配置时（Conf 控制器）和运行时解析（本中间件）共用这一套规则，避免两处不一致
     *
     * @param string $path 待校验的入口名
     * @return true|string true 表示合法；字符串为错误提示
     */
    public static function validate(string $path)
    {
        $path = trim($path);

        if ($path === '') {
            return '不能为空';
        }

        //只允许字母、数字、下划线，长度 2-32，避免路径穿越和非法 URL
        if (!preg_match('/^[A-Za-z0-9_]{2,32}$/', $path)) {
            return '只能使用字母、数字、下划线，长度 2-32 位';
        }

        if (in_array(strtolower($path), static::$reserved, true)) {
            return '不能使用系统保留名：' . implode('、', static::$reserved);
        }

        return true;
    }

    /**
     * 读取后台入口名，校验不通过时返回空字符串
     * 此时不覆盖 app_map，沿用 config/app.php 里的静态值
     */
    protected function getAdminPath(): string
    {
        try {
            //取整组配置：get_system_config 按 key 取时，键不存在会抛 Undefined array key
            $other  = get_system_config() ?: [];
            $path = trim((string) ($other['admin_path'] ?? ''));
        } catch (\Throwable $e) {
            //数据库异常时不阻断请求，回退到配置文件里的静态 app_map
            return '';
        }

        return static::validate($path) === true ? $path : '';
    }
}
