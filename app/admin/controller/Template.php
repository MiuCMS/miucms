<?php
/**
 * @copyright Copyright (c) 2026 米柚工作室
 * @link https://www.miucms.com
 */
declare(strict_types=1);

namespace app\admin\controller;

use app\admin\BaseController;
use app\services\TemplateScaffold;
use app\services\TemplateVisualEditor as VisualEditor;
use RuntimeException;
use think\facade\Db;
use think\facade\View;

class Template extends BaseController
{
    /** 模板选择只保存在 config 表的 template 分组，不再写入网站配置。 */
    public function setting()
    {
        $visual = $this->request->get('visual/s', '');
        if ($visual !== '') {
            return $this->visual($visual);
        }
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
            'visualEntries' => VisualEditor::entryKeys($templates),
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

    private function visual(string $operation)
    {
        $request = $this->request;
        $this->requireRequestMethod(in_array($operation, ['save', 'undo', 'restore'], true) ? 'POST' : 'GET');
        if ($operation === 'open') {
            $ticket = VisualEditor::open($request->get('name/s', ''), $request->get('key/s', ''));
            return redirect((string) url('admin/template/setting') . '?visual=editor&ticket=' . $ticket);
        }
        $ticket = $request->get('ticket/s', '');
        $entry = VisualEditor::session($ticket);
        $theme = $entry['theme'];
        $headers = ['Cache-Control' => 'private, no-store', 'Referrer-Policy' => 'no-referrer'];
        if ($operation === 'editor') {
            $pages = VisualEditor::describe($theme);
            return view('template/visual', [
                'theme' => $theme,
                'visualConfig' => json_encode([
                    'ticket' => $ticket,
                    'pages' => $pages,
                    'static' => (int) url_config()['mode'] === 2 && $theme === get_current_template(),
                ], JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_INVALID_UTF8_SUBSTITUTE | JSON_THROW_ON_ERROR),
            ])->header($headers);
        }
        if ($operation === 'files' && !$request->isAjax()) {
            return view('files/index', [
                'visualFilePickerUrl' => (string) url('admin/template/setting') . '?visual=files&ticket=' . $ticket,
            ])->header($headers);
        }
        try {
            if ($operation === 'files') {
                if ($request->get('groups/d', 0) === 1) {
                    $data = Db::name('FileGroup')->where('delete_time', 0)->field('id,title')->select()->toArray();
                } else {
                    $images = VisualEditor::media($request->get('page/d', 1), $request->get('keywords/s', ''), $request->get('group_id/s', ''));
                    return json(['code' => 0, 'count' => $images['total'], 'data' => $images['rows']], 200, $headers);
                }
            } elseif ($operation === 'history') {
                $data = VisualEditor::historyFiles($theme);
            } elseif ($operation === 'lookup') {
                $keyword = $request->get('keyword/s', '');
                $page = $request->get('page/d', 1);
                $data = match ($request->get('type/s', '')) {
                    'document' => VisualEditor::documents($request->get('nav/d', 0), $page, $keyword),
                    'tag' => VisualEditor::tags($page, $keyword),
                    'image' => VisualEditor::media($page, $keyword),
                    default => throw new RuntimeException('不支持的数据类型'),
                };
            } elseif ($operation === 'state' || in_array($operation, ['save', 'undo', 'restore'], true)) {
                $file = VisualEditor::file($theme, $request->get('file/s', ''));
                if ($operation === 'state') {
                    $data = VisualEditor::state($file);
                } else {
                    if (!valid_admin_csrf()) {
                        return json(['code' => 1, 'msg' => '页面令牌已失效，请刷新后再试'], 403, $headers);
                    }
                    $data = VisualEditor::change($file, $request->post('revision/s', ''), $operation, $request->post(false), $entry['admin']);
                    if ($theme === get_current_template()) {
                        clear_tpl_cache();
                    }
                }
            } else {
                throw new RuntimeException('不支持的模板编辑操作');
            }
            return json(['code' => 0, 'data' => $data], 200, $headers);
        } catch (\Throwable $error) {
            if (!$error instanceof RuntimeException) {
                trace('可视化模板编辑失败：' . $error->getMessage(), 'error');
            }
            return json(['code' => 1, 'msg' => $error instanceof RuntimeException ? $error->getMessage() : '模板操作失败，请稍后重试'], 200, $headers);
        }
    }
}
