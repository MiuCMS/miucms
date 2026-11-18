<?php
/**
 * @copyright Copyright (c) 2026 米柚工作室
 * @license https://opensource.org/licenses/Apache-2.0
 * @link https://www.miucms.com
 */

declare (strict_types = 1);

namespace app\home\middleware;

/**
 * 站点状态（关闭网站）
 *
 * 只挂在前台（home）应用上，后台、接口都不受影响。省资源的做法：
 * 1. 只读一次缓存里的配置（get_system_config 内部走缓存），站点是开启的就立刻放行；
 * 2. 放在 home 中间件的最前面，网站关闭时不再往下走 —— 不切模板目录、不加载多语言、
 *    不校验登录、不进控制器、不查内容表、不渲染模板，直接返回一个响应就结束；
 * 3. 关闭页是整段 HTML 直接返回，只有“模板页面”方式才读一次本地文件。
 *
 * 说明：全局中间件里的 SessionInit 在所有应用之前执行，所以这里省不掉 session 初始化，
 * 真正省掉的是它后面的模板切换、多语言、登录校验和控制器逻辑。
 *
 * 配置项（都在 other 分组里）：
 *   site_status      1=开启（默认） 0=关闭
 *   site_status_mode 关闭方式：0=文案显示 1=跳转链接 2=模板页面
 *   site_status_text 文案显示的文字
 *   site_status_url  跳转链接，必须是 http:// 或 https:// 开头
 *   site_status_tpl  模板页面路径，放在 public/ 目录下，默认 public/tpl/close.html
 */
class SiteStatus
{
    public function handle($request, \Closure $next)
    {
        //注意：get_system_config 按 key 取时，值为 0 或 '' 会返回 null，所以取整组再判断
        $other = get_system_config() ?: [];

        //默认开启，只有明确配成关闭才拦截
        if ((int) ($other['site_status'] ?? 1) !== 0) {
            return $next($request);
        }

        $mode = (int) ($other['site_status_mode'] ?? 0);

        //关闭方式一：跳转链接
        if ($mode === 1) {
            $url = trim((string) ($other['site_status_url'] ?? ''));
            if (is_valid_url($url)) {
                return redirect($url);
            }
            //链接非法（或没填）时退回文案显示，避免把访客困在死循环里
            $mode = 0;
        }

        //关闭方式二：模板页面
        if ($mode === 2) {
            //只允许 public/ 下的相对路径，挡掉 ../ 之类的越权读取
            $tpl = str_replace('\\', '/', trim((string) ($other['site_status_tpl'] ?? '')));
            if ($tpl === '' || str_contains($tpl, '..') || !str_starts_with($tpl, 'public/')) {
                $tpl = 'public/tpl/close.html';
            }
            $file = root_path() . $tpl;
            if (is_file($file)) {
                return response(file_get_contents($file), 200, ['Content-Type' => 'text/html; charset=utf-8']);
            }
            //文件不存在也退回文案显示
            $mode = 0;
        }

        //关闭方式零（含上面两种的兜底）：文案显示
        $text = trim((string) ($other['site_status_text'] ?? '')) ?: '网站暂时关闭，维护中！';

        return response(
            '<!doctype html><html lang="zh-CN"><head><meta charset="utf-8">'
            . '<meta name="viewport" content="width=device-width,initial-scale=1">'
            . '<title>网站维护中</title></head>'
            . '<body style="margin:0;min-height:100vh;display:flex;align-items:center;justify-content:center;'
            . 'background:#f7f7f7;color:#333;font:16px/1.8 system-ui,-apple-system,\'Microsoft YaHei\',sans-serif;">'
            . '<div style="padding:0 24px;text-align:center;">'
            . htmlspecialchars($text, ENT_QUOTES, 'UTF-8')
            . '</div></body></html>',
            200,
            ['Content-Type' => 'text/html; charset=utf-8']
        );
    }
}
