<?php
declare(strict_types=1);

namespace app\home\middleware;

use app\home\controller\Search;
use app\home\service\TemplateVisualSample;
use app\services\TemplateVisualEditor as Editor;
use app\services\TemplateVisualSource;
use app\services\TemplateVisualView;
use RuntimeException;
use think\facade\Db;
use think\facade\View;
use think\Response;

final class TemplatePreview
{
    private const PAGE = 'miu-template-preview';
    private const ASSET = 'miu-template-preview-assets';
    private const HEADERS = [
        'Cache-Control' => 'private, no-store',
        'Referrer-Policy' => 'no-referrer',
        'X-Content-Type-Options' => 'nosniff',
        'Content-Security-Policy' => "sandbox allow-same-origin; script-src 'none'; frame-ancestors 'self'; frame-src 'none'; form-action 'none'; object-src 'none'",
    ];

    public function handle($request, \Closure $next)
    {
        $path = trim((string) $request->pathinfo(), '/');
        $asset = str_starts_with($path, self::ASSET . '/');
        if ($path !== self::PAGE && !$asset) {
            return $next($request);
        }
        if ($request->method(true) !== 'GET' || !$request->isGet()) {
            abort(405);
        }

        $request->withMiddleware(['template_visual_preview' => true]);
        $parts = $asset ? explode('/', $path, 3) : [];
        $ticket = $asset ? ($parts[1] ?? '') : $request->get('ticket/s', '');
        $entry = Editor::session($ticket, true);
        $theme = $entry['theme'];
        if ($asset) {
            return $this->asset($theme, $parts[2] ?? '');
        }

        $previousDriver = config('view.type');
        $wasBuilding = miu_building_static();
        $workspace = '';
        $sample = false;
        $copies = $directories = [];
        $bufferLevel = ob_get_level();
        try {
            $file = $request->get('file/s', 'index.html');
            $source = Editor::source(Editor::file($theme, $file));
            $revision = hash('sha256', $source);
            if (!hash_equals($revision, $request->get('revision/s', ''))) {
                return $this->notice('模板文件已发生变化，请重新载入预览', 409);
            }
            $marker = substr(hash_hmac('sha256', $revision . ':' . $file, $ticket), 0, 20);
            $elements = TemplateVisualSource::inspect($source);
            $assets = '/index.php/home/' . self::ASSET . '/' . $ticket;
            $pages = Editor::describe($theme);
            $kind = $pages[$file]['kind'] ?? 'shared';
            $context = $file;
            if ($kind === 'shared') {
                $context = $request->get('context/s', 'index.html');
                if (!isset($pages[$context]) || $pages[$context]['kind'] === 'shared') {
                    $context = '';
                }
            }
            $contextKind = $pages[$context]['kind'] ?? 'fragment';
            $id = $request->get('id/d', 0);
            $nav = $request->get('nav/d', 0);
            $useSample = match ($contextKind) {
                'list' => $nav === 0,
                'view', 'tag' => $id === 0,
                default => false,
            };
            $this->validateContext($pages[$context] ?? [], $contextKind, $id, $nav, $useSample);
            $sample = $useSample;
            $sampleSource = $sample && $context !== $file ? Editor::source(Editor::file($theme, $context)) . "\n" . $source : $source;

            $workspace = runtime_path() . 'template_visual_preview' . DIRECTORY_SEPARATOR . bin2hex(random_bytes(12)) . DIRECTORY_SEPARATOR;
            if (!@mkdir($workspace, 0700, true)) {
                throw new RuntimeException('无法建立临时预览目录，请检查 runtime 写入权限');
            }
            foreach (Editor::files($theme) as $relative => $original) {
                $copy = $workspace . str_replace('/', DIRECTORY_SEPARATOR, $relative);
                $dir = dirname($copy);
                if (!is_dir($dir)) {
                    for ($parent = $dir; !is_dir($parent); $parent = dirname($parent)) {
                        $directories[] = $parent;
                    }
                    if (!@mkdir($dir, 0700, true)) {
                        throw new RuntimeException('无法复制预览目录');
                    }
                }
                $copies[] = $copy;
                if ($relative === $file) {
                    $marked = TemplateVisualSource::mark($source, $elements, $marker);
                    if ($kind === 'shared' && $context !== '') {
                        [$opening, $closing] = TemplateVisualView::fragmentBoundaries($marker);
                        $marked = $opening . $marked . $closing;
                    }
                    $ok = @file_put_contents($copy, $marked) !== false;
                } else {
                    $ok = @copy($original, $copy);
                }
                if (!$ok) {
                    throw new RuntimeException('无法复制模板文件');
                }
            }

            Editor::$previewTheme = $theme;
            miu_building_static(true);
            app()->config->set(['type' => TemplateVisualView::class], 'view');
            return (new Template())->handle($request, function () use ($theme, $file, $revision, $context, $contextKind, $kind, $id, $nav, $marker, $workspace, $assets, $sample, $sampleSource) {
                $replacements = template_asset_replace($theme);
                foreach ($replacements as &$path) {
                    if (str_starts_with($path, '/skin/' . $theme)) {
                        $path = $assets . substr($path, strlen('/skin/' . $theme));
                    }
                }
                unset($path);
                View::config(['view_path' => $workspace, 'tpl_replace_string' => $replacements,
                    'cache_path' => $workspace, 'tpl_cache' => false, 'display_cache' => false]);

                if ($kind === 'shared' && $context !== '') {
                    TemplateVisualView::$fragment = $workspace . str_replace('/', DIRECTORY_SEPARATOR, $file);
                    TemplateVisualView::$marker = $marker;
                }
                $controller = match ($contextKind) {
                    'list' => 'Nav', 'view' => 'Detail', 'tag' => 'Tag', 'search' => 'Search', default => 'Index',
                };
                request()->setController($controller);
                request()->setAction('index');
                $search = new Search(app()); // 沿用前台控制器的公共视图变量。
                $name = pathinfo($context, PATHINFO_FILENAME);
                $rendered = $sample ? View::fetch('/' . $name, TemplateVisualSample::context(
                    $contextKind, $name, $sampleSource, $this->sampleImage($theme, $assets), $nav > 0 ? nav_by_id($nav) : []
                )) : match ($contextKind) {
                    'list' => miu_render_nav_list($nav, 1, $name),
                    'view' => miu_render_doc_detail($id, $name),
                    'tag' => miu_render_tag_list($id),
                    'search' => $search->index(),
                    'home' => View::fetch('/index'),
                    default => View::fetch($workspace . str_replace('/', DIRECTORY_SEPARATOR, $file)),
                };
                if ($rendered instanceof Response && $rendered->getCode() >= 400) {
                    return $this->notice('当前页面数据无法预览，请选择其他栏目或文档', $rendered->getCode());
                }
                $html = $rendered instanceof Response ? (string) $rendered->getContent() : (string) $rendered;
                $html = str_replace('/skin/' . $theme . '/', $assets . '/', $html);
                $meta = $this->markerMeta($marker, $revision);
                if ($sample) {
                    $meta .= '<meta name="miu-visual-sample" content="1">';
                }
                if (!preg_match('~<base\b~i', $html)) {
                    $url = $sample ? home_url() : match ($contextKind) {
                        'list' => nav_url(nav_by_id($nav)),
                        'view' => doc_url(miu_cached_document($id), nav_by_id($nav)),
                        'tag' => miu_tag_url($id),
                        'search' => miu_search_url([]),
                        default => home_url(),
                    };
                    $base = parse_url($url, PHP_URL_PATH) ?: '/';
                    if (str_starts_with($base, '/') && !str_starts_with($base, '//') && !str_contains($base, '\\')) {
                        $meta .= '<base href="' . htmlspecialchars($base, ENT_QUOTES, 'UTF-8') . '">';
                    }
                }
                if (preg_match('~<head\b[^>]*>~i', $html, $head, PREG_OFFSET_CAPTURE)) {
                    $at = $head[0][1] + strlen($head[0][0]);
                    $html = substr_replace($html, $meta, $at, 0);
                } else {
                    $html = $meta . $html;
                }
                return response($html, 200, self::HEADERS);
            });
        } catch (\Throwable $e) {
            while (ob_get_level() > $bufferLevel) {
                ob_end_clean();
            }
            if (!$e instanceof RuntimeException) {
                trace('可视化模板预览失败：' . $e->getMessage(), 'error');
            }
            if ($sample) {
                return $this->fixedElements($theme, $elements, $marker, $revision, $assets);
            }
            return $this->notice($e instanceof RuntimeException ? $e->getMessage() : '无法渲染这个模板，请检查模板代码或选择其他预览数据', 422);
        } finally {
            Editor::$previewTheme = '';
            TemplateVisualSample::$documents = null;
            TemplateVisualView::$fragment = TemplateVisualView::$marker = '';
            miu_building_static($wasBuilding);
            app()->config->set(['type' => $previousDriver], 'view');
            foreach (array_merge($copies, $workspace === '' ? [] : (glob($workspace . '*.php') ?: [])) as $copy) {
                @unlink($copy);
            }
            foreach (array_reverse($directories) as $dir) {
                @rmdir($dir);
            }
            if ($workspace !== '') {
                @rmdir($workspace);
            }
        }
    }

    private function sampleImage(string $theme, string $assets): string
    {
        $directory = Editor::themeRoot($theme) . DIRECTORY_SEPARATOR . 'static' . DIRECTORY_SEPARATOR . 'images' . DIRECTORY_SEPARATOR;
        foreach (['hero.jpg', 'banner.jpg', 'hero.png', 'none.png'] as $name) {
            if (is_file($directory . $name)) {
                return $assets . '/images/' . $name;
            }
        }
        return is_file(public_path() . 'skin/default/images/none.png') ? '/skin/default/images/none.png' : '';
    }

    private function fixedElements(string $theme, array $elements, string $marker, string $revision, string $assets): Response
    {
        $html = '<!doctype html><html lang="zh-CN"><head><meta charset="utf-8"><base href="' . $assets . '/">' . $this->markerMeta($marker, $revision)
            . '<style>body{font:16px system-ui;padding:20px}li{margin:12px 0}img{max-width:240px;max-height:130px}</style></head><body>'
            . '<p>此模板需要更多实际数据才能预览整页，以下固定内容仍可编辑。</p><ul>';
        foreach ($elements as $index => $element) {
            $id = $marker . ':' . $index;
            $visible = html_entity_decode($element['value'], ENT_QUOTES | ENT_HTML5, 'UTF-8');
            if ($element['kind'] === 'image') {
                $url = str_replace('/skin/' . $theme . '/', $assets . '/', strtr($visible, template_asset_replace($theme)));
                $html .= '<li><img alt="模板固定图片" data-miu-visual="' . $id . '" src="' . TemplateVisualSource::escape($url) . '"></li>';
            } else {
                $html .= '<li><!--miu-visual:' . $id . '-->' . TemplateVisualSource::escape($visible) . '<!--miu-visual-end:' . $id . '--></li>';
            }
        }
        return response($html . '</ul></body></html>', 200, self::HEADERS);
    }

    private function markerMeta(string $marker, string $revision): string
    {
        return '<meta name="miu-visual-preview" content="' . $marker . ':' . $revision . '">';
    }

    private function validateContext(array $page, string $kind, int $id, int $nav, bool $sample): void
    {
        if ($kind === 'list' || $kind === 'view') {
            if ((!$sample || $nav !== 0) && !in_array($nav, array_column($page['nav'], 'id'), true)) {
                throw new RuntimeException('请选择适用于此模板的预览栏目');
            }
            if ($kind === 'view' && !$sample && !Db::name('document')->where('id', $id)->where('nav_id', $nav)
                ->where('status', 1)->where('is_del', 0)->where('arcrank', '>=', 0)->value('id')) {
                throw new RuntimeException('请选择此栏目下已发布的文档');
            }
        } elseif ($kind === 'tag' && !$sample && $id < 1) {
            throw new RuntimeException('请选择预览标签');
        }
    }

    private function asset(string $theme, string $relative): Response
    {
        $mime = [
            'css' => 'text/css', 'js' => 'text/javascript', 'mjs' => 'text/javascript', 'json' => 'application/json',
            'svg' => 'image/svg+xml', 'jpg' => 'image/jpeg', 'jpeg' => 'image/jpeg', 'png' => 'image/png',
            'gif' => 'image/gif', 'bmp' => 'image/bmp', 'webp' => 'image/webp', 'avif' => 'image/avif', 'ico' => 'image/x-icon',
            'woff' => 'font/woff', 'woff2' => 'font/woff2', 'ttf' => 'font/ttf', 'otf' => 'font/otf',
            'mp4' => 'video/mp4', 'webm' => 'video/webm', 'mp3' => 'audio/mpeg',
        ];
        $ext = strtolower(pathinfo($relative, PATHINFO_EXTENSION));
        if (!isset($mime[$ext]) || !preg_match('~\A[\w.-]+(?:/[\w.-]+)*\z~', $relative)
            || array_intersect(explode('/', $relative), ['.', '..'])) {
            abort(404);
        }
        $base = Editor::themeRoot($theme) . DIRECTORY_SEPARATOR . 'static' . DIRECTORY_SEPARATOR;
        $expected = $base . str_replace('/', DIRECTORY_SEPARATOR, $relative);
        $file = realpath($expected);
        if ($file === false || $file !== $expected || !is_file($file)) {
            // 旧模板也可能把资源直接放在已发布的皮肤目录中。
            $expected = public_path() . 'skin' . DIRECTORY_SEPARATOR . $theme . DIRECTORY_SEPARATOR . str_replace('/', DIRECTORY_SEPARATOR, $relative);
            $file = realpath($expected);
            if ($file === false || $file !== $expected || !is_file($file)) {
                abort(404);
            }
        }
        $body = @file_get_contents($file);
        if (!is_string($body)) {
            abort(404);
        }
        return response($body, 200, self::HEADERS + ['Content-Type' => $mime[$ext]]);
    }

    private function notice(string $message, int $code): Response
    {
        return response('<!doctype html><html lang="zh-CN"><meta charset="utf-8"><meta name="miu-visual-error" content="'
            . htmlspecialchars($message, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') . '">'
            . '<p style="padding:2rem;font:16px system-ui">' . htmlspecialchars($message, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') . '</p></html>', $code, self::HEADERS);
    }
}
