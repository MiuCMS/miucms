<?php
/**
 * @copyright Copyright (c) 2026 米柚工作室
 * @license https://opensource.org/licenses/Apache-2.0
 * @link https://www.miucms.com
 */

declare (strict_types = 1);

namespace app\home\controller;

use think\facade\Cache;
use think\facade\Session;

/**
 * 前台静态页面生成入口
 *
 * 为什么放在前台（home 应用）：
 *   生成要读前台的 URL 规则（nav_url / doc_url）和模板目录（view_path 由 home 的
 *   Template 中间件设置），后台应用和命令行都拿不到，所以统一由 home 执行。
 *
 * 谁调用：后台「SEO → 页面生成」（admin/seo/createhtml）页面上的几个生成按钮。
 *
 * 两步走（前端拿进度条刷）：
 *   start()  按选项算出清单，缓存起来，返回 token 和总数
 *   step()   每次生成一批，返回已生成数量，done=true 表示结束
 *
 * 只允许已登录的后台管理员调用（后台和前台共用同一个 session）。
 */
class Createhtml
{
    /**
     * 每批生成多少个页面
     *
     * 一次请求里渲染太多会超时，太少请求次数又太多；10 个大约是 1~2 秒。
     */
    const BATCH = 10;

    /**
     * 第一步：算清单
     *
     * @return \think\response\Json
     */
    public function start()
    {
        if (!$this->isAdmin()) {
            return json(['code' => 1, 'msg' => '请先登录后台']);
        }

        $param = get_params();
        $type  = (string) ($param['type'] ?? '');

        if (!createhtml_ready() && $type !== 'sitemap') {
            return json(['code' => 1, 'msg' => '当前不是「生成静态模式」，请先到 SEO → URL配置 把「URL模式」切换成「生成静态」']);
        }

        if (!is_writable(public_path())) {
            return json(['code' => 1, 'msg' => '网站根目录（public/）不可写，没法生成静态文件，请检查目录权限']);
        }

        $plan = createhtml_plan($this->options());
        if (!empty($plan['error'])) {
            return json(['code' => 1, 'msg' => $plan['error']]);
        }

        $token = bin2hex(random_bytes(16));
        Cache::set('createhtml_plan_' . $token, $plan['items'], 3600);

        return json([
            'code'  => 0,
            'token' => $token,
            'total' => count($plan['items']),
        ]);
    }

    /**
     * 第二步：生成一批
     *
     * @return \think\response\Json
     */
    public function step()
    {
        if (!$this->isAdmin()) {
            return json(['code' => 1, 'msg' => '请先登录后台']);
        }

        $token  = (string) request()->param('token', '');
        $offset = max(0, (int) request()->param('offset', 0));

        $items = $token !== '' ? Cache::get('createhtml_plan_' . $token) : null;
        if (!is_array($items) || !$items) {
            return json(['code' => 1, 'msg' => '生成任务已失效，请重新点击生成按钮']);
        }

        // sitemap 不受 URL 模式限制，动态/伪静态模式下也能生成
        $hasSitemap = false;
        foreach ($items as $item) {
            if (($item['kind'] ?? '') === 'sitemap') {
                $hasSitemap = true;
                break;
            }
        }
        if (!createhtml_ready() && !$hasSitemap) {
            return json(['code' => 1, 'msg' => '当前不是「生成静态模式」']);
        }

        $total = count($items);
        $batch = array_slice($items, $offset, self::BATCH);

        if (!$batch) {
            Cache::delete('createhtml_plan_' . $token);
            return json([
                'code' => 0, 'generated' => $total, 'total' => $total,
                'done' => true, 'current' => '', 'errors' => [],
            ]);
        }

        //生成期间关掉「访问量 +1」，不然生成一次全站每篇文档的访问量都白涨一次
        miu_building_static(true);

        $errors  = [];
        $current = '';
        foreach ($batch as $item) {
            $current = (string) ($item['label'] ?? '');
            $err     = '';
            if (!createhtml_run_item($item, $err)) {
                $errors[] = $current . '：' . $err;
            }
        }

        miu_building_static(false);

        $generated = min($total, $offset + count($batch));
        $done      = $generated >= $total;
        if ($done) {
            Cache::delete('createhtml_plan_' . $token);
        }

        return json([
            'code'      => 0,
            'generated' => $generated,
            'total'     => $total,
            'done'      => $done,
            'current'   => $current,
            'errors'    => $errors,
        ]);
    }

    /**
     * 整理页面传过来的生成选项
     *
     * @return array
     */
    protected function options(): array
    {
        $param = request()->param();

        return [
            'type'    => (string) ($param['type'] ?? ''),
            'nav_ids' => array_values(array_filter(array_map('intval', (array) ($param['nav_ids'] ?? [])))),
            'scope'   => (string) ($param['scope'] ?? 'all'),
            'start'   => $this->toTimestamp($param['start'] ?? ''),
            'end'     => $this->toTimestamp($param['end'] ?? '', true),
            'sitemap' => array_values(array_filter((array) ($param['sitemap'] ?? []))),
        ];
    }

    /**
     * 日期字符串 → 时间戳
     *
     * 页面上的发布时间只选到「日」（年-月-日），这里一律按整天算：
     *   起始时间 → 当天 0 点 0 分 0 秒
     *   结束时间 → 当天 23 点 59 分 59 秒
     * 不然「截止到今天」会把今天发布的文档漏掉。
     *
     * @param mixed $val
     * @param bool  $endOfDay true = 结束时间（按当天最后一秒算）
     * @return int
     */
    protected function toTimestamp($val, bool $endOfDay = false): int
    {
        $val = trim((string) $val);
        if ($val === '') {
            return 0;
        }

        $ts = strtotime($val);
        if ($ts === false) {
            return 0;
        }

        $day = date('Y-m-d', $ts);

        return (int) strtotime($endOfDay ? $day . ' 23:59:59' : $day . ' 00:00:00');
    }

    /**
     * 是不是已登录的后台管理员
     *
     * 后台登录时写的 session key 是 app.session_admin（默认 miu_admin）
     *
     * @return bool
     */
    protected function isAdmin(): bool
    {
        return !empty(Session::get(get_config('app.session_admin')));
    }
}
