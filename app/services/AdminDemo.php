<?php
declare(strict_types=1);

namespace app\services;

use think\facade\Db;
use think\facade\Session;
use think\Request;
use think\Response;

final class AdminDemo
{
    private const MESSAGE = '当前为只读演示，无法执行此操作';

    public static function isReadOnly(): bool
    {
        if (config('demo.read_only') !== true) {
            return false;
        }

        $admin = Session::get(get_config('app.session_admin'));
        $adminId = is_array($admin) ? (int) ($admin['id'] ?? 0) : 0;
        $groupId = (int) config('demo.group_id');
        if ($adminId <= 1 || $groupId <= 1) {
            return false;
        }

        // 角色停用只影响授权，演示组成员仍受只读限制。
        return Db::name('AdminGroupAccess')
            ->where('uid', $adminId)->where('group_id', $groupId)
            ->field('uid')->find() !== null;
    }

    public static function blocks(Request $request, string $route): bool
    {
        $originalMethod = $request->method(true);
        $method = $request->method();

        // 保留正常退出，原始方法和实际方法都必须为 POST。
        if ($route === 'login/login_out' && $request->layer() === '' && $originalMethod === 'POST' && $method === 'POST') {
            return false;
        }

        return in_array($originalMethod, ['POST', 'DELETE'], true)
            || in_array($method, ['POST', 'DELETE'], true);
    }

    public static function denied(Request $request): Response
    {
        $headers = ['Cache-Control' => 'no-store'];
        if ($request->isAjax() || $request->isJson() || $request->method(true) !== 'GET') {
            return json(['code' => 403, 'msg' => self::MESSAGE, 'url' => '', 'data' => [], 'demo_read_only' => true], 403, $headers);
        }

        return response(
            '<!doctype html><html lang="zh-CN"><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1">'
            . '<title>只读演示</title><body style="margin:0;min-height:100vh;display:grid;place-items:center;font:16px system-ui,sans-serif">'
            . '<p>' . self::MESSAGE . '</p></body></html>',
            403,
            $headers
        );
    }
}
