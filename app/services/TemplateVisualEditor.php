<?php
declare(strict_types=1);

namespace app\services;

use app\admin\middleware\Auth;
use RuntimeException;
use think\facade\Db;
use think\facade\Session;

final class TemplateVisualEditor
{
    public const PERMISSION = 'admin/template/setting';
    public const MAX_SOURCE = 2097152;
    private const IMAGES = ['jpg', 'jpeg', 'png', 'gif', 'bmp', 'webp', 'avif', 'ico'];
    private const SESSION_KEY = 'miu_template_visual';

    /** 仅在已经鉴权的独立前台预览请求中设置。 */
    public static string $previewTheme = '';

    public static function entryKeys(array $templates): array
    {
        $keys = [];
        foreach ($templates as $template) {
            if (self::themeRoot($template['name']) !== '') {
                $keys[$template['name']] = bin2hex(random_bytes(16));
            }
        }
        Session::set(self::SESSION_KEY . '_entries', ['keys' => $keys, 'until' => time() + 1800]);
        return $keys;
    }

    public static function open(string $theme, string $key): string
    {
        $entry = Session::get(self::SESSION_KEY . '_entries');
        $expected = $entry['keys'][$theme] ?? '';
        if ($expected === '' || !hash_equals($expected, $key) || $entry['until'] < time() || self::themeRoot($theme) === '') {
            abort(403, '请从后台模板管理重新进入可视化编辑');
        }
        unset($entry['keys'][$theme]);
        Session::set(self::SESSION_KEY . '_entries', $entry);
        $ticket = bin2hex(random_bytes(24));
        $active = array_filter(Session::get(self::SESSION_KEY, []), static fn (array $item): bool => $item['until'] >= time());
        $active = array_slice($active, -9, null, true);
        $active[$ticket] = ['theme' => $theme, 'admin' => self::adminId(), 'session' => Session::getId(), 'until' => time() + 14400];
        Session::set(self::SESSION_KEY, $active);
        return $ticket;
    }

    public static function session(string $ticket, bool $isFrontend = false): array
    {
        $admin = self::adminId();
        $active = preg_match('/\A[a-f0-9]{48}\z/', $ticket) ? Session::get(self::SESSION_KEY, []) : [];
        $entry = $active[$ticket] ?? [];
        if ($admin < 1 || ($entry['admin'] ?? 0) !== $admin || ($entry['session'] ?? '') !== Session::getId()
            || ($entry['until'] ?? 0) < time()) {
            abort(403, '编辑会话已失效，请从后台模板管理重新进入');
        }
        if ($isFrontend && (!in_array(self::PERMISSION, Auth::allowedRoutes($admin), true)
            || !Db::name('admin')->where('id', $admin)->where('status', 1)->value('id'))) {
            abort(403, '没有编辑模板的权限');
        }
        if (self::themeRoot($entry['theme']) === '') {
            abort(404, '模板目录不存在');
        }
        return $entry;
    }

    private static function adminId(): int
    {
        $admin = Session::get(get_config('app.session_admin'));
        return is_array($admin) ? (int) ($admin['id'] ?? 0) : 0;
    }

    public static function themeRoot(string $theme): string
    {
        if (!preg_match('/\A[A-Za-z0-9_]{1,32}\z/', $theme)) {
            return '';
        }
        $root = realpath(get_templates_path());
        $path = realpath(get_templates_path($theme));
        return $root && $path && is_dir($path) && dirname($path) === $root && basename($path) === $theme ? $path : '';
    }

    public static function file(string $theme, string $relative): string
    {
        if (!preg_match('~\A(?:[\w-]+/)*[\w.-]+\.html\z~i', $relative)
            || preg_match('~\A(?:static|lang)/~i', $relative)) {
            throw new RuntimeException('请选择有效的 HTML 模板文件');
        }
        $root = self::themeRoot($theme);
        $expected = $root . DIRECTORY_SEPARATOR . str_replace('/', DIRECTORY_SEPARATOR, $relative);
        $file = realpath($expected);
        if ($root === '' || $file === false || $file !== $expected || !is_file($file)) {
            throw new RuntimeException('模板文件不存在或不在所选模板目录内');
        }
        return $file;
    }

    public static function files(string $theme): array
    {
        $root = self::themeRoot($theme);
        if ($root === '') {
            return [];
        }
        $dirs = [$root];
        $files = [];
        while ($dirs) {
            foreach (new \FilesystemIterator(array_pop($dirs), \FilesystemIterator::SKIP_DOTS) as $item) {
                $path = $item->getPathname();
                $name = str_replace(DIRECTORY_SEPARATOR, '/', substr($path, strlen($root) + 1));
                if ($item->isLink() || realpath($path) !== $path) {
                    continue;
                }
                if ($item->isDir() && !in_array($name, ['static', 'lang'], true) && preg_match('~\A[\w/-]+\z~', $name)) {
                    $dirs[] = $path;
                }
                if ($item->isFile() && preg_match('~\A(?:[\w-]+/)*[\w.-]+\.html\z~i', $name)) {
                    $files[$name] = $path;
                }
            }
        }
        ksort($files, SORT_NATURAL | SORT_FLAG_CASE);
        return $files;
    }

    /** 从现有栏目和模型推断页面类型，兼容后台手工指定的旧模板文件名。 */
    public static function describe(string $theme): array
    {
        $categories = Db::name('nav')->alias('n')->leftJoin('channel c', 'n.channel_id = c.id')
            ->where('n.status', 1)->where('n.is_del', 0)->where('n.is_url', 0)
            ->field('n.id,n.title,n.temp_list,n.temp_view,n.channel_id,c.nid')->order('n.sort desc,n.id')->select()->toArray();
        $pages = [];
        foreach (array_keys(self::files($theme)) as $file) {
            $pages[$file] = match (true) {
                $file === 'index.html' => ['kind' => 'home', 'label' => '首页', 'nav' => []],
                $file === 'search.html' => ['kind' => 'search', 'label' => '搜索页', 'nav' => []],
                $file === 'list_tag.html' => ['kind' => 'tag', 'label' => '标签页', 'nav' => []],
                default => ['kind' => 'shared', 'label' => '公共模板', 'nav' => []],
            };
            if ($pages[$file]['kind'] !== 'shared' || str_contains($file, '/')) {
                continue;
            }
            $name = pathinfo($file, PATHINFO_FILENAME);
            if (!preg_match('/\A[A-Za-z0-9_-]{1,64}\z/', $name)) {
                continue;
            }
            $kinds = [];
            foreach ($categories as $nav) {
                $model = (string) $nav['nid'];
                foreach (['list', 'view'] as $kind) {
                    if ($kind === 'view' && in_array($model, ['single', 'clue'], true)) {
                        continue;
                    }
                    if ($name === preg_replace('/[^A-Za-z0-9_-]/', '_', (string) $nav['temp_' . $kind])
                        || $name === $kind . '_' . $model || $name === $kind . '_default') {
                        $kinds[$kind][] = ['id' => (int) $nav['id'], 'title' => (string) $nav['title']];
                    }
                }
            }
            if (str_starts_with($name, 'list_') || isset($kinds['list'])) {
                $pages[$file] = ['kind' => 'list', 'label' => '栏目页', 'nav' => $kinds['list'] ?? []];
            } elseif (str_starts_with($name, 'view_') || isset($kinds['view'])) {
                $pages[$file] = ['kind' => 'view', 'label' => '详情页', 'nav' => $kinds['view'] ?? []];
            }
        }
        return $pages;
    }

    public static function media(int $page, string $keyword, string $group = ''): array
    {
        $query = Db::name('file')->where('delete_time', 0)->whereIn('fileext', self::IMAGES);
        if ($keyword !== '') {
            $query->where('name', 'like', '%' . mb_substr($keyword, 0, 80) . '%');
        }
        if ($group !== '' && ctype_digit($group)) {
            $query->where('group_id', (int) $group);
        }
        return self::resultPage($query, $page, 'id,name,filepath,fileext', 'id desc');
    }

    private static function imageUrl(int $id): string
    {
        $row = Db::name('file')->where('id', $id)->where('delete_time', 0)->whereIn('fileext', self::IMAGES)
            ->field('filename,filepath')->find();
        if (!$row) {
            throw new RuntimeException('所选图片不存在，请重新从素材库选择');
        }
        $url = StorageService::parseFilename((string) $row['filename']) === null
            ? (string) $row['filepath'] : StorageService::urlFor((string) $row['filename']);
        if (!preg_match('~\A(?:/(?![/\\\\])|https?://[^/]+/)~i', $url) || preg_match('~[\x00-\x20\\\\]~', $url)) {
            throw new RuntimeException('图片的访问地址无效');
        }
        return $url;
    }

    public static function documents(int $nav, int $page, string $keyword): array
    {
        $query = Db::name('document')->where('nav_id', $nav)->where('is_del', 0)->where('status', 1)->where('arcrank', '>=', 0);
        if ($keyword !== '') {
            $query->where('title', 'like', '%' . mb_substr($keyword, 0, 80) . '%');
        }
        return self::resultPage($query, $page, 'id,title', 'release_time desc,id desc');
    }

    public static function tags(int $page, string $keyword): array
    {
        $query = Db::name('keywords')->where('status', 1);
        if ($keyword !== '') {
            $query->where('title', 'like', '%' . mb_substr($keyword, 0, 80) . '%');
        }
        return self::resultPage($query, $page, 'id,title', 'id desc');
    }

    private static function resultPage($query, int $page, string $fields, string $order): array
    {
        $total = (clone $query)->count();
        $rows = $query->field($fields)->order($order)->page(max(1, min(100000, $page)), 25)->select()->toArray();
        return ['rows' => $rows, 'total' => $total];
    }

    public static function source(string $file): string
    {
        clearstatcache(true, $file);
        $source = @file_get_contents($file, false, null, 0, self::MAX_SOURCE + 1);
        if (!is_string($source) || strlen($source) > self::MAX_SOURCE) {
            throw new RuntimeException('无法读取模板文件，或文件超过 2 MB');
        }
        return $source;
    }

    public static function state(string $file): array
    {
        $source = self::source($file);
        $revision = hash('sha256', $source);
        $dir = self::historyDir($file);
        $records = self::records($dir, $revision);
        $last = reset($records) ?: [];
        $undo = ($last['afterHash'] ?? '') === $revision
            ? ($last['action'] === 'save' ? $last['id'] : $last['parent']) : '';
        return ['revision' => $revision, 'undo' => $undo, 'history' => array_values($records)];
    }

    public static function historyFiles(string $theme): array
    {
        $files = [];
        foreach (self::files($theme) as $name => $file) {
            $dir = self::historyDir($file);
            if (glob($dir . '*.json') || (glob($dir . '*.pending') && self::state($file)['history'])) {
                $files[] = $name;
            }
        }
        return $files;
    }

    public static function change(string $file, string $revision, string $action, array $input, int $admin): array
    {
        $dir = self::historyDir($file);
        if (!is_dir($dir) && !@mkdir($dir, 0700, true) && !is_dir($dir)) {
            throw new RuntimeException('无法创建模板修改记录目录，请检查 runtime 写入权限');
        }
        $lock = @fopen($dir . 'write.lock', 'c');
        if ($lock === false) {
            throw new RuntimeException('无法锁定模板文件');
        }
        try {
            if (!flock($lock, LOCK_EX)) {
                throw new RuntimeException('无法锁定模板文件');
            }
            $before = self::source($file);
            $state = self::state($file);
            if (!hash_equals(hash('sha256', $before), $revision) || $state['revision'] !== $revision) {
                throw new RuntimeException('模板文件已发生变化，请重新载入后再操作');
            }

            $parent = $state['undo'];
            $detail = '';
            $patch = [];
            if ($action === 'save') {
                $index = $input['element'] ?? '';
                $elements = TemplateVisualSource::inspect($before);
                $element = is_string($index) && ctype_digit($index) ? ($elements[(int) $index] ?? null) : null;
                if (!$element || $element['kind'] !== ($input['kind'] ?? '')) {
                    throw new RuntimeException('该元素不能编辑，请重新载入预览');
                }
                $value = $element['kind'] === 'image'
                    ? self::imageUrl((int) ($input['media'] ?? 0)) : ($input['text'] ?? '');
                if (!is_string($value) || strlen($value) > 24000 || !preg_match('//u', $value) || str_contains($value, "\0")) {
                    throw new RuntimeException('请输入有效的文字内容');
                }
                if ($element['kind'] === 'text' && trim($value) === '') {
                    throw new RuntimeException('请输入文字，以便之后仍可在页面上选中该位置');
                }
                $spans = [$element];
                if (isset($element['srcset'])) {
                    // 选中的素材只有一个地址，同步替换响应式候选，避免浏览器继续加载旧图。
                    $spans[] = $element['srcset'];
                    usort($spans, static fn (array $a, array $b): int => $b['start'] <=> $a['start']);
                }
                $escaped = TemplateVisualSource::escape($value);
                $after = $before;
                foreach ($spans as $span) {
                    $after = substr_replace($after, $escaped, $span['start'], $span['length']);
                }
                $patch = ['value' => $value, 'keepEditing' => TemplateVisualSource::preservesElementIds($elements, $after, (int) $index, $spans, $escaped)];
                if ($element['kind'] === 'text') {
                    $patch['value'] = trim($value);
                    $patch['leading'] = substr($value, 0, strlen($value) - strlen(ltrim($value)));
                    $patch['trailing'] = substr($value, strlen(rtrim($value)));
                }
                $label = $element['kind'] === 'image' ? '更换图片' : '修改文字';
                $detail = $element['kind'] === 'image' ? '素材 #' . (int) $input['media']
                    : mb_strimwidth(html_entity_decode($element['value'], ENT_QUOTES | ENT_HTML5, 'UTF-8'), 0, 52, '…', 'UTF-8');
            } else {
                $target = $action === 'undo' ? $state['undo'] : (string) ($input['history'] ?? '');
                $record = in_array($target, array_column($state['history'], 'id'), true) ? self::readRecord($dir, $target) : null;
                if ($record === null) {
                    throw new RuntimeException('没有可恢复的修改记录');
                }
                $after = $record['before'];
                $parent = $action === 'undo' || $record['action'] === 'save' ? $record['parent'] : '';
                $label = $action === 'undo' ? '撤销修改' : '恢复历史版本';
            }
            if ($after === $before) {
                return $state + ($patch ? ['patch' => $patch] : []);
            }
            if (strlen($after) > self::MAX_SOURCE) {
                throw new RuntimeException('修改后的模板文件超过 2 MB');
            }

            $id = sprintf('%016.0f', microtime(true) * 1000000) . '-' . bin2hex(random_bytes(4));
            $account = Session::get(get_config('app.session_admin'));
            $record = ['id' => $id, 'action' => $action, 'label' => $label, 'detail' => $detail, 'admin' => $admin,
                'username' => (string) ($account['username'] ?? ''), 'time' => date('Y-m-d H:i:s'),
                'parent' => $parent, 'beforeHash' => $revision, 'afterHash' => hash('sha256', $after), 'before' => $before];
            $pending = $dir . $id . '.pending';
            self::replace($pending, json_encode($record, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE));
            try {
                if (!hash_equals($revision, hash('sha256', self::source($file)))) {
                    throw new RuntimeException('模板文件已发生变化，请重新载入后再操作');
                }
                self::replace($file, $after);
            } catch (\Throwable $e) {
                @unlink($pending);
                throw $e;
            }
            // 如果进程在替换后退出，下次读取可凭目标哈希识别已生效的记录。
            @rename($pending, $dir . $id . '.json');
            $next = self::state($file);
            if ($patch && hash_equals($record['afterHash'], $next['revision'])) {
                $next['patch'] = $patch;
            }
            return $next;
        } finally {
            fclose($lock);
        }
    }

    private static function historyDir(string $file): string
    {
        return root_path() . 'runtime' . DIRECTORY_SEPARATOR . 'template_visual_history' . DIRECTORY_SEPARATOR
            . hash('sha256', substr($file, strlen(realpath(get_templates_path())) + 1)) . DIRECTORY_SEPARATOR;
    }

    private static function records(string $dir, string $revision): array
    {
        $records = [];
        foreach (array_merge(glob($dir . '*.json') ?: [], glob($dir . '*.pending') ?: []) as $file) {
            $id = pathinfo($file, PATHINFO_FILENAME);
            $record = self::readRecord($dir, $id);
            if ($record === null || (str_ends_with($file, '.pending') && $record['afterHash'] !== $revision)) {
                continue;
            }
            if (str_ends_with($file, '.pending')) {
                @rename($file, $dir . $id . '.json');
            }
            unset($record['before']);
            $records[$id] = $record;
        }
        krsort($records, SORT_STRING);
        return $records;
    }

    private static function readRecord(string $dir, string $id): ?array
    {
        if (!preg_match('/\A\d{16}-[a-f0-9]{8}\z/', $id)) {
            return null;
        }
        $file = $dir . $id . '.json';
        $data = @file_get_contents(is_file($file) ? $file : $dir . $id . '.pending');
        $record = is_string($data) ? json_decode($data, true) : null;
        return is_array($record) && ($record['id'] ?? '') === $id && is_string($record['before'] ?? null) ? $record : null;
    }

    private static function replace(string $file, string $contents): void
    {
        $temp = dirname($file) . DIRECTORY_SEPARATOR . '.miu-visual-' . bin2hex(random_bytes(8));
        try {
            if (@file_put_contents($temp, $contents) !== strlen($contents)) {
                throw new RuntimeException('模板文件或备份无法写入，请检查目录权限');
            }
            $mode = is_file($file) ? @fileperms($file) : false;
            if ($mode !== false) {
                @chmod($temp, $mode & 0777);
            }
            if (!@rename($temp, $file)) {
                throw new RuntimeException('无法替换模板文件，请检查目录权限');
            }
        } finally {
            if (is_file($temp)) {
                @unlink($temp);
            }
        }
    }
}
