<?php
/**
 * @copyright Copyright (c) 2026 米柚工作室
 * @link https://www.miucms.com
 */
declare(strict_types=1);

namespace app\admin\controller;

use app\admin\BaseController;
use app\services\TemplateScaffold;
use think\facade\Db;
use think\facade\View;

class Template extends BaseController
{
    /** 模板选择只保存在 config 表的 template 分组，不再写入网站配置。 */
    public function setting()
    {
        // 创建与启用共用模板设置入口，沿用现有后台权限，不另外开放免鉴权的生成接口。
        if (request()->isPost() && request()->post('operation') === 'create') {
            try {
                $result = (new TemplateScaffold())->generate(
                    request()->post(),
                    Db::name('channel')->field('nid')->select()->toArray()
                );
                if ($result['code'] === 0) {
                    safe_add_log('add', 0, ['template' => $result['name']]);
                }
            } catch (\Throwable $e) {
                \think\facade\Log::error('创建模板异常：' . $e);
                return to_assign(1, '创建模板失败，详细原因已写入系统日志');
            }
            return to_assign($result['code'], $result['msg'], $result['files'] ?? []);
        }
        if (request()->isGet() && request()->get('form') === 'create') {
            View::assign([
                'cmsVersion' => (string) CMS_VERSION,
                'occupiedNames' => json_encode((new TemplateScaffold())->occupiedNames(), JSON_UNESCAPED_UNICODE),
            ]);
            return view('template/create');
        }
        $templates = get_templates();
        usort($templates, static function (array $left, array $right): int {
            return ((int) ($left['createdAt'] ?? 0) <=> (int) ($right['createdAt'] ?? 0))
                ?: strcasecmp($left['name'], $right['name']);
        });
        if (request()->isPost()) {
            $name = request()->post('name', '');
            if (!is_string($name) || !preg_match('/^[A-Za-z0-9_]{1,32}$/', $name)) {
                return to_assign(1, '请选择有效的模板');
            }
            $selected = null;
            foreach ($templates as $template) {
                if ($template['name'] === $name) {
                    $selected = $template;
                    break;
                }
            }
            if ($selected === null) {
                return to_assign(1, '模板目录不存在，请刷新页面后重试');
            }
            if ($selected['error'] !== '') {
                return to_assign(1, $selected['error']);
            }

            try {
                //先发布资源，失败时保留原来的模板选择，避免前台切换后缺少样式和脚本。
                $sync = sync_template_assets($name);
                if ($sync['failed'] > 0) {
                    $details = array_map(static fn (array $item): string => $item['path'] . '：' . $item['reason'], $sync['failed_details']);
                    throw new \RuntimeException("模板静态资源同步失败，未切换模板。\n" . implode("\n", $details));
                }
                if (!save_config_group('template', ['current' => $name])) {
                    throw new \RuntimeException('模板配置保存失败，请稍后重试');
                }
                clear_tpl_cache();
                safe_add_log('edit', 0, ['template' => $name]);
            } catch (\Throwable $e) {
                return to_assign(1, '启用模板失败：' . $e->getMessage());
            }
            return to_assign(0, '模板已启用');
        }
        if (!request()->isGet()) {
            return to_assign(1, '请使用 POST 请求启用模板');
        }

        //可用模板 = 没有 error 的模板，用于顶部概览统计
        $available = 0;
        foreach ($templates as $template) {
            if ($template['error'] === '') {
                $available++;
            }
        }

        View::assign([
            'templates' => $templates,
            'current' => get_current_template(),
            'cmsVersion' => (string) CMS_VERSION,
            'stats' => [
                'total' => count($templates),
                'available' => $available,
                'error' => count($templates) - $available,
            ],
        ]);
        return view();
    }
}
