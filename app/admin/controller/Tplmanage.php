<?php
/**
 * @copyright Copyright (c) 2026 米柚工作室
 * @license https://opensource.org/licenses/Apache-2.0
 * @link https://www.miucms.com
 */

declare(strict_types=1);

namespace app\admin\controller;

use app\admin\BaseController;
use think\facade\View;

/**
 * 后台 - 模板管理：文件列表与 CodeMirror 弹窗编辑器
 *
 * 入口：工具 → 模板管理（admin/tplmanage/index）
 *   index()  文件列表页（根目录列出所有模板，进模板后列出文件/目录）
 *   api()    编辑器与文件操作接口，do = file_tree | get_content | save | newfile | newdir | rename | delete
 *
 * 路径约定：activepath 是「相对 templates 根目录」的路径，可写成 /default/static 或 :default:static，
 * 第一段是模板名，后面是模板内相对路径；activepath 为空表示 templates 根目录。
 *
 * 安全约束：
 *   1. 所有路径 realpath() 后必须落在 templates/<模板>/ 内（防 .. 穿越、防软链接跳出去）；
 *   2. 扩展名白名单（不含 php/phtml/phar），防止往模板目录写可执行脚本；
 *   3. 写操作强制 POST（防 CSRF）；
 *   4. 误操作保护：保存前自动备份原文件；删除移进回收站（可恢复）；
 *   5. 每个动作都要权限节点，写操作日志。
 *
 * 模板改完不用手动清缓存：模板编译缓存按「模板文件 mtime」判断，文件一改就重编译。
 */
class Tplmanage extends BaseController
{
    /** 允许读写的文件扩展名（不含 php 等可执行脚本） */
    private const ALLOW_EXT = ['html', 'htm', 'css', 'js', 'txt', 'json', 'ini', 'xml', 'svg', 'md', 'map'];

    /** 模板名（目录名）合法性 */
    private const TPL_PATTERN = '/^[A-Za-z0-9_]{1,32}$/';

    /** 路径段：排除控制字符和 Windows 非法字符 \ / : * ? " < > | */
    private const SEG_PATTERN = '/^[^\x00-\x1f\/\\\\:*?"<>|]{1,128}$/u';

    /** 单个文件最大可编辑大小（字节） */
    private const MAX_FILE_BYTES = 2 * 1024 * 1024;

    private const BACKUP_DIR  = 'template_backup';
    private const TRASH_DIR   = 'template_trash';
    private const BACKUP_KEEP = 10;

    /**
     * 文件列表页
     */
    public function index()
    {
        $activepath = $this->normActive((string) input('activepath/s', ''));

        $res   = $this->scanActiveDir($activepath, false);
        $items = $res['items'];
        $err   = $res['ok'] ? '' : $res['msg'];

        // 列表交给 layui 表格渲染：显示用的文本在后端格式化好，前端不用再实现一遍 format_bytes/日期
        $dirCount  = 0;   // 目录数
        $fileCount = 0;   // 文件数
        foreach ($items as &$it) {
            if (($it['type'] ?? '') === 'dir') {
                $dirCount++;
            } else {
                $fileCount++;
            }
            // 文件一律显示大小（0 字节也显示 0B，不留空）；目录没有大小，留空
            $it['sizeText']  = ($it['type'] === 'file') ? format_bytes($it['size']) : '';
            $it['mtimeText'] = $it['mtime'] > 0 ? date('Y-m-d H:i:s', $it['mtime']) : '';
        }
        unset($it);

        // 面包屑（根节点「模板根目录」由模板里的固定链接渲染，这里只拼模板名及以下层级）
        $crumb = [];
        if ($activepath !== '') {
            $segs = explode('/', $activepath);
            $acc  = '';
            foreach ($segs as $i => $seg) {
                $acc .= ($acc === '' ? '' : '/') . $seg;
                $crumb[] = ['name' => $seg, 'path' => $acc];
            }
        }

        View::assign([
            'list'        => $items,
            'listJson'    => json_encode($items, JSON_UNESCAPED_UNICODE),
            'fileCount'   => $fileCount,
            'dirCount'    => $dirCount,
            'err'         => $err,
            'activepath'  => $activepath,
            'active_url'  => '/' . $activepath,
            'crumb'       => $crumb,
            'minPath'     => '',
            'current'     => get_current_template(),
            'allow_ext'   => implode(',', self::ALLOW_EXT),
        ]);
        return view();
    }

    /**
     * 接口：do = file_tree | get_content | save | newfile | newdir | rename | delete
     *
     * 返回格式：成功 code=1，失败 code=0，与 EditorApp 的状态判断保持一致。
     */
    public function api()
    {
        $do = (string) input('do/s', '');

        $writeOps = ['save', 'newfile', 'newdir', 'rename', 'delete'];
        if (in_array($do, $writeOps, true) && !$this->request->isPost()) {
            return json(['code' => 0, 'msg' => '非法请求']);
        }

        switch ($do) {
            case 'file_tree':
                return $this->apiFileTree();
            case 'get_content':
                return $this->apiGetContent();
            case 'save':
                return $this->apiSave();
            case 'newfile':
                return $this->apiCreate('file');
            case 'newdir':
                return $this->apiCreate('dir');
            case 'rename':
                return $this->apiRename();
            case 'delete':
                return $this->apiDelete();
            default:
                return json(['code' => 0, 'msg' => '未知操作']);
        }
    }

    // ==================== 接口实现 ====================

    /**
     * 文件树（懒加载：只返回某目录的直接子项，可编辑文件才列出来）
     */
    private function apiFileTree()
    {
        $activepath = $this->normActive((string) input('activepath/s', ''));
        $res = $this->scanActiveDir($activepath, true);
        if (!$res['ok']) {
            return json(['code' => 0, 'msg' => $res['msg']]);
        }

        $parent = '/' . $activepath;
        $data = [];
        foreach ($res['items'] as $it) {
            $data[] = [
                'name' => $it['name'],
                'type' => $it['type'],
                // 目录：给出自身路径；文件：给出所在目录（EditorApp 打开文件时用的是「目录 + 文件名」）
                'path' => $it['type'] === 'dir' ? ('/' . $it['childPath']) : $parent,
            ];
        }
        return json(['code' => 1, 'data' => $data]);
    }

    /**
     * 读取文件内容
     */
    private function apiGetContent()
    {
        $activepath = $this->normActive((string) input('activepath/s', ''));
        $filename   = trim((string) input('filename/s', ''));

        $abs = $this->activeFileAbs($activepath, $filename, true);
        if ($abs === '' || !is_file($abs)) {
            return json(['code' => 0, 'msg' => '文件不存在']);
        }
        if (!$this->extOk($abs)) {
            return json(['code' => 0, 'msg' => '该类型文件不允许在线编辑']);
        }
        if ((int) @filesize($abs) > self::MAX_FILE_BYTES) {
            return json(['code' => 0, 'msg' => '文件超过 2M，不适合在线编辑']);
        }

        $content = @file_get_contents($abs);
        if ($content === false) {
            return json(['code' => 0, 'msg' => '读取失败']);
        }

        $ext  = strtolower(pathinfo($filename, PATHINFO_EXTENSION));
        $mode = [
            'htm' => 'text/html', 'html' => 'text/html',
            'css' => 'text/css', 'js' => 'text/javascript',
            'json' => 'application/json', 'xml' => 'xml', 'svg' => 'xml',
        ][$ext] ?? 'text/plain';

        return json([
            'code' => 1,
            'data' => ['content' => $content, 'extension' => $ext, 'mode' => $mode],
        ]);
    }

    /**
     * 保存文件（保存前自动备份原文件）
     */
    private function apiSave()
    {
        $activepath = $this->normActive((string) input('activepath/s', ''));
        $filename   = trim((string) input('filename/s', ''));
        $content    = (string) get_params('content'); // 文件内容不做过滤

        $abs = $this->activeFileAbs($activepath, $filename, true);
        if ($abs === '' || !is_file($abs)) {
            return json(['code' => 0, 'msg' => '文件不存在（新建文件请用「新建文件」）']);
        }
        if (!$this->extOk($abs)) {
            return json(['code' => 0, 'msg' => '该类型文件不允许在线编辑']);
        }
        if (strlen($content) > self::MAX_FILE_BYTES) {
            return json(['code' => 0, 'msg' => '内容超过 2M']);
        }

        [$tpl, $rel] = $this->parseActive($activepath);
        $relFile = ($rel === '' ? '' : $rel . '/') . $filename;

        $this->backup($tpl, $relFile, $abs);

        if (@file_put_contents($abs, $content) === false) {
            return json(['code' => 0, 'msg' => '写入失败，请检查文件权限']);
        }
        @touch($abs, time());

        safe_add_log('edit', 0, ['template' => $tpl . '/' . $relFile], '模板管理');
        return json(['code' => 1, 'msg' => '保存成功']);
    }

    /**
     * 新建文件 / 目录
     */
    private function apiCreate(string $type)
    {
        $activepath = $this->normActive((string) input('activepath/s', ''));
        $name       = trim((string) input('name/s', ''));

        $dirAbs = $this->activeDirAbs($activepath);
        if ($dirAbs === '') {
            return json(['code' => 0, 'msg' => '目录不存在']);
        }
        if ($name === '' || !$this->validSeg($name)) {
            return json(['code' => 0, 'msg' => '名称不合法']);
        }

        $abs = $dirAbs . DIRECTORY_SEPARATOR . $name;
        if (file_exists($abs)) {
            return json(['code' => 0, 'msg' => '同名文件或目录已存在']);
        }

        [$tpl, $rel] = $this->parseActive($activepath);
        $relNew = ($rel === '' ? '' : $rel . '/') . $name;

        if ($type === 'dir') {
            if (!@mkdir($abs, 0755, true)) {
                return json(['code' => 0, 'msg' => '创建目录失败，请检查权限']);
            }
            safe_add_log('add', 0, ['template' => $tpl . '/' . $relNew . '/'], '模板管理');
            return json(['code' => 1, 'msg' => '目录已创建']);
        }

        if (!$this->extOk($abs)) {
            return json(['code' => 0, 'msg' => '只允许新建这些类型：' . implode('、', self::ALLOW_EXT)]);
        }
        if (@file_put_contents($abs, '') === false) {
            return json(['code' => 0, 'msg' => '创建文件失败，请检查权限']);
        }
        safe_add_log('add', 0, ['template' => $tpl . '/' . $relNew], '模板管理');
        return json(['code' => 1, 'msg' => '文件已创建']);
    }

    /**
     * 重命名（同级重命名）
     */
    private function apiRename()
    {
        $activepath = $this->normActive((string) input('activepath/s', ''));
        $oldName    = trim((string) input('filename/s', ''));
        $newName    = trim((string) input('newname/s', ''));

        $abs = $this->activeFileAbs($activepath, $oldName, true);
        if ($abs === '' || !file_exists($abs)) {
            return json(['code' => 0, 'msg' => '文件或目录不存在']);
        }
        if ($newName === '' || $newName === '.' || $newName === '..' || !$this->validSeg($newName)) {
            return json(['code' => 0, 'msg' => '新名称不合法']);
        }

        $target = dirname($abs) . DIRECTORY_SEPARATOR . $newName;
        if (file_exists($target)) {
            return json(['code' => 0, 'msg' => '同名文件或目录已存在']);
        }
        if (is_file($abs) && !$this->extOk($target)) {
            return json(['code' => 0, 'msg' => '新名称的类型不在允许列表里']);
        }
        if (!@rename($abs, $target)) {
            return json(['code' => 0, 'msg' => '重命名失败']);
        }

        [$tpl, $rel] = $this->parseActive($activepath);
        safe_add_log('edit', 0, ['template' => $tpl . '/' . ($rel === '' ? '' : $rel . '/') . $oldName . ' → ' . $newName], '模板管理');
        return json(['code' => 1, 'msg' => '已重命名']);
    }

    /**
     * 删除（移进回收站，可恢复）
     */
    private function apiDelete()
    {
        $activepath = $this->normActive((string) input('activepath/s', ''));
        $filename   = trim((string) input('filename/s', ''));

        $abs = $this->activeFileAbs($activepath, $filename, true);
        if ($abs === '' || !file_exists($abs)) {
            return json(['code' => 0, 'msg' => '文件或目录不存在']);
        }
        if (is_dir($abs) && dirname($abs) === $abs) {
            return json(['code' => 0, 'msg' => '路径不合法']);
        }

        [$tpl, $rel] = $this->parseActive($activepath);
        $relFile = ($rel === '' ? '' : $rel . '/') . $filename;

        if (!$this->moveToTrash($tpl, $relFile, $abs)) {
            return json(['code' => 0, 'msg' => '删除失败（移入回收站失败），请检查 runtime 权限']);
        }

        safe_add_log('delete', 0, ['template' => $tpl . '/' . $relFile], '模板管理');
        return json(['code' => 1, 'msg' => '已删除（原文件已放入回收站，可手动恢复）']);
    }

    // ==================== 路径 / 安全 ====================

    /**
     * 归一化 activepath：: 和 \ 都转成 /，合并多余斜杠，去掉首尾斜杠。'' = templates 根目录
     */
    private function normActive(string $activepath): string
    {
        $p = str_replace([':', '\\'], '/', trim($activepath));
        $p = preg_replace('#/{2,}#', '/', (string) $p);
        return trim((string) $p, '/');
    }

    /**
     * 拆出 [模板名, 模板内相对路径]；根目录返回 ['', '']
     */
    private function parseActive(string $activepath): array
    {
        if ($activepath === '') {
            return ['', ''];
        }
        $segs = explode('/', $activepath);
        $tpl  = array_shift($segs);
        if (!preg_match(self::TPL_PATTERN, (string) $tpl)) {
            return ['', ''];
        }
        return [$tpl, implode('/', $segs)];
    }

    /**
     * 模板目录的绝对路径（realpath 后）；非法或不存在返回 ''
     */
    private function templateBase(string $tpl): string
    {
        $tpl = trim($tpl);
        if (!preg_match(self::TPL_PATTERN, $tpl)) {
            return '';
        }
        $real = realpath(get_templates_path($tpl));
        if ($real === false || !is_dir($real)) {
            return '';
        }
        return rtrim($real, DIRECTORY_SEPARATOR);
    }

    /**
     * 某个目录（activepath）的绝对路径，必须是已存在的目录
     */
    private function activeDirAbs(string $activepath): string
    {
        if ($activepath === '') {
            return rtrim((string) realpath(get_templates_path()), DIRECTORY_SEPARATOR);
        }
        [$tpl, $rel] = $this->parseActive($activepath);
        $base = $tpl !== '' ? $this->templateBase($tpl) : '';
        if ($base === '') {
            return '';
        }
        $abs = $this->resolve($base, $rel, true);
        return ($abs !== '' && is_dir($abs)) ? $abs : '';
    }

    /**
     * 「目录 + 文件名」的绝对路径（并校验落在模板目录内）
     */
    private function activeFileAbs(string $activepath, string $filename, bool $mustExist): string
    {
        if ($filename === '' || !$this->validSeg($filename)) {
            return '';
        }
        [$tpl, $rel] = $this->parseActive($activepath);
        $base = $tpl !== '' ? $this->templateBase($tpl) : '';
        if ($base === '') {
            return '';
        }
        $relFile = ($rel === '' ? '' : $rel . '/') . $filename;
        return $this->resolve($base, $relFile, $mustExist);
    }

    /**
     * 单个路径段是否合法（不含分隔符、非法字符，且不是 . / ..）
     */
    private function validSeg(string $seg): bool
    {
        return $seg !== '' && $seg !== '.' && $seg !== '..'
            && strpos($seg, '/') === false && strpos($seg, '\\') === false
            && (bool) preg_match(self::SEG_PATTERN, $seg);
    }

    /**
     * 归一化相对路径：去掉 ./、空段；含 .. 或非法字符返回 ''
     */
    private function cleanRel(string $rel): string
    {
        $rel = str_replace('\\', '/', trim($rel));
        $rel = trim($rel, '/');
        if ($rel === '') {
            return '';
        }
        $segs = [];
        foreach (explode('/', $rel) as $seg) {
            if ($seg === '' || $seg === '.') {
                continue;
            }
            if ($seg === '..' || !$this->validSeg($seg)) {
                return '';
            }
            $segs[] = $seg;
        }
        return implode('/', $segs);
    }

    /**
     * 相对路径 → 绝对路径，且必须落在 $base 内
     */
    private function resolve(string $base, string $rel, bool $mustExist): string
    {
        // 先看原始 rel：空 = 目录本身；非空但归一化后为空 = 非法（含 .. 或非法字符）→ 拒绝
        $raw = trim(str_replace('\\', '/', $rel), '/');
        if ($raw === '') {
            return $mustExist ? $base : '';
        }
        $rel = $this->cleanRel($raw);
        if ($rel === '') {
            return '';
        }
        $full = $base . DIRECTORY_SEPARATOR . str_replace('/', DIRECTORY_SEPARATOR, $rel);

        if ($mustExist) {
            $real = realpath($full);
            if ($real === false) {
                return '';
            }
            if ($real !== $base && strpos($real, $base . DIRECTORY_SEPARATOR) !== 0) {
                return '';
            }
            return $real;
        }

        $parent = realpath(dirname($full));
        if ($parent === false) {
            return '';
        }
        if ($parent !== $base && strpos($parent, $base . DIRECTORY_SEPARATOR) !== 0) {
            return '';
        }
        return $parent . DIRECTORY_SEPARATOR . basename($full);
    }

    /**
     * 扩展名是否在白名单里
     */
    private function extOk(string $path): bool
    {
        $ext = strtolower(pathinfo($path, PATHINFO_EXTENSION));
        return in_array($ext, self::ALLOW_EXT, true);
    }

    /**
     * 扫描某个目录的直接子项
     *
     * @param string $activepath
     * @param bool   $editableOnly true=只列可编辑文件（文件树用）；false=所有文件（列表用）
     * @return array ['ok'=>bool, 'msg'=>string, 'items'=>array]
     */
    private function scanActiveDir(string $activepath, bool $editableOnly): array
    {
        // 根目录：列出所有模板
        if ($activepath === '') {
            $items = [];
            foreach (get_templates() as $t) {
                $items[] = [
                    'name'      => (string) $t['name'],
                    'title'     => (string) ($t['title'] ?? $t['name']),
                    'type'      => 'dir',
                    'childPath' => (string) $t['name'],
                    'size'      => 0,
                    'mtime'     => 0,
                    'editable'  => false,
                ];
            }
            return ['ok' => true, 'msg' => '', 'items' => $items];
        }

        [$tpl, $rel] = $this->parseActive($activepath);
        $base = $tpl !== '' ? $this->templateBase($tpl) : '';
        if ($base === '') {
            return ['ok' => false, 'msg' => '模板不存在', 'items' => []];
        }
        $abs = $this->resolve($base, $rel, true);
        if ($abs === '' || !is_dir($abs)) {
            return ['ok' => false, 'msg' => '目录不存在', 'items' => []];
        }

        $items = [];
        foreach ((array) @scandir($abs) as $name) {
            if ($name === '.' || $name === '..' || $name[0] === '.') {
                continue;
            }
            $full  = $abs . DIRECTORY_SEPARATOR . $name;
            // childPath 是「相对 templates 根目录」的路径，必须以模板名打头（否则点进去会被当成另一个模板）
            $child = $tpl . '/' . ($rel === '' ? $name : $rel . '/' . $name);

            if (is_dir($full)) {
                $items[] = [
                    'name' => $name, 'title' => $name, 'type' => 'dir', 'childPath' => $child,
                    'size' => 0, 'mtime' => (int) @filemtime($full), 'editable' => false,
                ];
                continue;
            }
            if (!is_file($full)) {
                continue;
            }

            $ext = strtolower(pathinfo($name, PATHINFO_EXTENSION));
            $editable = in_array($ext, self::ALLOW_EXT, true);
            if ($editableOnly && !$editable) {
                continue;
            }
            $items[] = [
                'name' => $name, 'title' => $name, 'type' => 'file', 'childPath' => $child,
                'size' => (int) @filesize($full), 'mtime' => (int) @filemtime($full), 'editable' => $editable,
            ];
        }

        // 目录在前，各自按名称排序
        usort($items, static function ($a, $b) {
            if ($a['type'] !== $b['type']) {
                return $a['type'] === 'dir' ? -1 : 1;
            }
            return strcasecmp($a['name'], $b['name']);
        });

        return ['ok' => true, 'msg' => '', 'items' => $items];
    }

    /**
     * 项目根 runtime 目录（带结尾分隔符）
     *
     * 注意：多应用模式下 runtime_path() 会变成 runtime/<应用名>（后台就是 runtime/admin），
     * 而回收站/备份按约定放在项目根的 runtime 下，所以这里显式用 root_path() 拼，别用 runtime_path()。
     */
    private function runtimeRoot(): string
    {
        return rtrim(root_path(), '\\/') . DIRECTORY_SEPARATOR . 'runtime' . DIRECTORY_SEPARATOR;
    }

    /**
     * 保存前把原文件备份到 runtime/template_backup/<模板>/<相对路径>.<时间>.bak（每个文件最多 BACKUP_KEEP 份）
     */
    private function backup(string $tpl, string $rel, string $abs): void
    {
        if (!is_file($abs) || $tpl === '') {
            return;
        }
        $rel = ltrim(str_replace('\\', '/', $this->cleanRel($rel)), '/');
        if ($rel === '') {
            return;
        }
        $dir  = $this->runtimeRoot() . self::BACKUP_DIR . DIRECTORY_SEPARATOR . $tpl . DIRECTORY_SEPARATOR . str_replace('/', DIRECTORY_SEPARATOR, dirname($rel));
        $name = basename($rel);
        if (!is_dir($dir)) {
            @mkdir($dir, 0755, true);
        }
        @copy($abs, $dir . DIRECTORY_SEPARATOR . $name . '.' . date('YmdHis') . '.bak');

        $list = [];
        foreach ((array) @scandir($dir) as $f) {
            if ($f !== '.' && $f !== '..' && str_starts_with($f, $name . '.') && str_ends_with($f, '.bak')) {
                $list[] = $f;
            }
        }
        sort($list);
        $over = count($list) - self::BACKUP_KEEP;
        for ($i = 0; $i < $over; $i++) {
            @unlink($dir . DIRECTORY_SEPARATOR . $list[$i]);
        }
    }

    /**
     * 删除时把文件/目录移进 runtime/template_trash/<模板>/<时间>/<相对路径>
     */
    private function moveToTrash(string $tpl, string $rel, string $abs): bool
    {
        $rel = ltrim(str_replace('\\', '/', $this->cleanRel($rel)), '/');
        if ($rel === '' || $tpl === '') {
            return false;
        }
        $target = $this->runtimeRoot() . self::TRASH_DIR . DIRECTORY_SEPARATOR . $tpl . DIRECTORY_SEPARATOR
            . date('YmdHis') . DIRECTORY_SEPARATOR . str_replace('/', DIRECTORY_SEPARATOR, $rel);
        $dir = dirname($target);
        if (!is_dir($dir) && !@mkdir($dir, 0755, true)) {
            return false;
        }
        return @rename($abs, $target);
    }
}
