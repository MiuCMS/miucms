<?php
declare(strict_types=1);

namespace app\middleware;

use app\services\UpgradeManager;

/** 升级文件替换期间暂时拦截业务请求，避免读取到一半更新的程序。 */
class UpgradeMaintenance
{
    public function handle($request, \Closure $next)
    {
        if (!is_file(UpgradeManager::maintenancePath())) return $next($request);

        $app = app('http')->getName();
        $path = preg_replace('/\.' . preg_quote($request->ext(), '/') . '$/i', '', $request->pathInfo());
        $segments = array_map('strtolower', explode('/', trim((string) $path, '/')));
        $isRecoveryPage = $app === 'admin'
            && ($segments[0] ?? '') === 'index'
            && in_array($segments[1] ?? '', ['upgrade', 'upgrade_check', 'upgrade_apply'], true);
        if ($isRecoveryPage) return $next($request);

        $upgradeUrl = htmlspecialchars((string) url('admin/index/upgrade'), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
        return response(
            '<!doctype html><html lang="zh-CN"><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1">'
            . '<title>系统升级中</title><body style="margin:0;min-height:100vh;display:grid;place-items:center;background:#f5f7fa;color:#263445;font:16px/1.8 system-ui,Microsoft YaHei,sans-serif">'
            . '<p>系统正在升级，请稍后刷新页面。若升级中断，请管理员<a href="' . $upgradeUrl . '">打开升级恢复页面</a>。</p></body></html>',
            503,
            ['Content-Type' => 'text/html; charset=utf-8', 'Retry-After' => '30']
        );
    }
}
