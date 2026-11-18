<?php

declare(strict_types=1);

namespace app\services;

/** 创建模板范例；用户输入仅进入元信息，不参与可执行模板代码的拼接。 */
final class TemplateScaffold
{
    private string $root;

    public function __construct(?string $root = null)
    {
        $this->root = $root ?? get_templates_path();
    }

    /** 包含没有 info.json 的目录、普通文件和链接，避免只检查后台可见的模板卡片。 */
    public function occupiedNames(): array
    {
        $names = is_dir($this->root) ? @scandir($this->root) : false;
        return $names === false ? [] : array_values(array_diff($names, ['.', '..']));
    }

    public function generate(array $input, array $models = []): array
    {
        $info = [];
        $limits = ['name' => 32, 'title' => 80, 'version' => 64, 'minCmsVersion' => 64, 'description' => 500, 'author' => 80, 'authorUrl' => 2048];
        foreach ($limits as $key => $limit) {
            if (isset($input[$key]) && !is_string($input[$key])) {
                return $this->failure('模板信息格式不正确：' . $key);
            }
            $value = trim((string) ($input[$key] ?? ''));
            if ($key === 'description') {
                $value = preg_replace('/\R/u', ' ', $value) ?? '';
            }
            if (!mb_check_encoding($value, 'UTF-8') || mb_strlen($value, 'UTF-8') > $limit || preg_match('/[\x00-\x1f\x7f]/', $value)) {
                return $this->failure('模板信息过长或含有无效字符：' . $key);
            }
            $info[$key] = $value;
        }
        $type = $input['type'] ?? 'corporate';
        if (!is_string($type) || !in_array(trim($type), ['corporate', 'blog', 'image', 'video', 'portal'], true)) {
            return $this->failure('请选择有效的模板类型');
        }
        $info['type'] = trim($type);
        if (!preg_match('/\A[A-Za-z0-9_]{1,32}\z/', $info['name'])) {
            return $this->failure('模板标识只能包含 1 至 32 位字母、数字和下划线');
        }
        if (strcasecmp($info['name'], 'default') === 0) {
            return $this->failure('default 是系统保留的模板标识，请使用其他名称');
        }
        if ($info['title'] === '') {
            return $this->failure('请填写模板名称');
        }
        $info['version'] = $info['version'] ?: '1.0.0';
        foreach (['version', 'minCmsVersion'] as $key) {
            if ($info[$key] !== '' && !preg_match('/\A\d+(?:\.\d+)*(?:[-+][0-9A-Za-z.-]+)?\z/', $info[$key])) {
                return $this->failure('版本号格式不正确：' . $key);
            }
        }
        if ($info['authorUrl'] !== '' && (!filter_var($info['authorUrl'], FILTER_VALIDATE_URL) || !preg_match('#\Ahttps?://#i', $info['authorUrl']))) {
            return $this->failure('作者网址请填写有效的 HTTP 或 HTTPS 地址');
        }
        $root = realpath($this->root);
        if ($root === false || !is_writable($root)) {
            return $this->failure('templates 目录不存在或不可写，请检查目录权限');
        }

        // 保留同一个锁文件，避免删除锁文件后并发请求分别锁住不同文件。
        $lock = @fopen($root . DIRECTORY_SEPARATOR . '.template-scaffold.lock', 'c');
        if ($lock === false) {
            return $this->failure('无法创建模板生成锁，请检查 templates 目录权限');
        }
        $stage = null;
        try {
            if (!flock($lock, LOCK_EX | LOCK_NB)) {
                return $this->failure('正在创建其他模板，请稍后再试');
            }
            if ($this->nameOccupied($info['name'])) {
                return $this->failure('模板标识已被现有目录或文件使用，请更换名称');
            }
            $files = $this->files($models, $info['type']);
            foreach ($files as $relative => &$content) {
                if (str_ends_with($relative, '.html')) {
                    $content = str_replace('<body>', '<body class="theme-' . $info['type'] . '">', $content);
                }
            }
            unset($content);
            $info['createdAt'] = time();
            $metadata = json_encode($info, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
            if (!is_string($metadata)) {
                throw new \RuntimeException('模板元信息无法编码为 JSON');
            }
            $files['info.json'] = $metadata . PHP_EOL;

            // 隐藏的临时目录不参与模板发现，全部写完再发布，失败时不留下半成品卡片。
            $stage = $root . DIRECTORY_SEPARATOR . '.scaffold-' . bin2hex(random_bytes(12));
            if (!@mkdir($stage, 0755)) {
                $stage = null;
                throw new \RuntimeException('无法创建临时模板目录，请检查写入权限');
            }
            foreach ($files as $relative => $content) {
                $path = $stage . DIRECTORY_SEPARATOR . str_replace('/', DIRECTORY_SEPARATOR, $relative);
                if (!is_dir(dirname($path)) && !@mkdir(dirname($path), 0755, true)) {
                    throw new \RuntimeException('无法创建模板子目录：' . dirname($relative));
                }
                if (@file_put_contents($path, $content, LOCK_EX) !== strlen($content)) {
                    throw new \RuntimeException('写入模板文件失败：' . $relative . '，请检查权限和磁盘空间');
                }
            }
            if ($this->nameOccupied($info['name'])) {
                throw new \RuntimeException('模板标识已被使用，未覆盖现有目录');
            }
            if (!@rename($stage, $root . DIRECTORY_SEPARATOR . $info['name'])) {
                throw new \RuntimeException('发布模板目录失败，请检查 templates 目录权限');
            }
            $stage = null;
            return ['code' => 0, 'msg' => '模板已创建，可在列表中点击启用', 'name' => $info['name'], 'files' => array_keys($files)];
        } catch (\Throwable $e) {
            return $this->failure($e->getMessage());
        } finally {
            if ($stage !== null) {
                $this->removeStage($stage, $root);
            }
            flock($lock, LOCK_UN);
            fclose($lock);
        }
    }

    private function nameOccupied(string $name): bool
    {
        foreach ($this->occupiedNames() as $entry) {
            if (strcasecmp($entry, $name) === 0) {
                return true;
            }
        }
        return false;
    }

    private function files(array $models, string $type): array
    {
        $source = dirname(__DIR__) . '/extend/template/stubs';
        $files = [];
        $overrides = [];
        $iterator = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($source, \FilesystemIterator::SKIP_DOTS));
        foreach ($iterator as $file) {
            if ($file->isLink() || !$file->isFile() || !str_ends_with($file->getFilename(), '.stub')) {
                continue;
            }
            $relative = str_replace('\\', '/', substr($file->getPathname(), strlen($source) + 1, -5));
            $content = file_get_contents($file->getPathname());
            if ($content === false) {
                throw new \RuntimeException('无法读取模板范例源文件');
            }
            if (preg_match('#^types/([a-z]+)/(.+)$#', $relative, $matches)) {
                if ($matches[1] === $type) {
                    $overrides[$matches[2]] = $content;
                }
                continue;
            }
            $files[$relative] = $content;
        }
        if (!isset($files['index.html'], $files['list_default.html'], $files['view_default.html'])) {
            throw new \RuntimeException('模板范例源文件缺失，请重新上传程序文件');
        }
        $nids = ['article', 'image', 'product', 'download', 'recruit', 'special', 'single', 'clue', 'video'];
        foreach ($models as $model) {
            $nid = $model['nid'] ?? null;
            if (!is_string($nid) || !preg_match('/\A[A-Za-z0-9_-]{1,59}\z/', $nid)) {
                throw new \RuntimeException('存在无效的模型标识，请先检查后台内容模型设置');
            }
            if (strcasecmp($nid, 'tag') === 0) {
                throw new \RuntimeException('模型标识 tag 与系统标签页模板重名，请先修改该模型标识');
            }
            $nids[] = $nid;
        }
        foreach (array_unique($nids) as $nid) {
            $list = 'list_' . $nid . '.html';
            $view = 'view_' . $nid . '.html';
            if (!isset($files[$list])) {
                $files[$list] = in_array($nid, ['article', 'download', 'recruit'], true)
                    ? str_replace('class="document-grid"', 'class="document-grid document-grid--rows"', $files['list_default.html'])
                    : $files['list_default.html'];
            }
            $files[$view] ??= $files['view_default.html'];
        }
        // 类型主题是主样式的末尾片段，避免每种模板多加载一份 CSS。
        if (isset($overrides['static/css/theme.css'])) {
            $files['static/css/style.css'] .= "\n\n" . $overrides['static/css/theme.css'];
            unset($overrides['static/css/theme.css']);
        }
        foreach ($overrides as $relative => $content) {
            $files[$relative] = $content;
        }
        ksort($files);
        return $files;
    }

    /** 仅回滚本次创建的临时目录；不跟随链接，也不删除目标模板或其他用户目录。 */
    private function removeStage(string $stage, string $root): void
    {
        if (is_link($stage) || dirname($stage) !== $root || !preg_match('/\A\.scaffold-[a-f0-9]{24}\z/', basename($stage)) || !is_dir($stage)) {
            return;
        }
        $iterator = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($stage, \FilesystemIterator::SKIP_DOTS), \RecursiveIteratorIterator::CHILD_FIRST);
        foreach ($iterator as $entry) {
            if ($entry->isDir() && !$entry->isLink()) {
                @rmdir($entry->getPathname());
            } else {
                @unlink($entry->getPathname());
            }
        }
        @rmdir($stage);
    }

    private function failure(string $message): array
    {
        return ['code' => 1, 'msg' => $message];
    }
}
