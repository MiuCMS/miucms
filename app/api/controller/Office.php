<?php
/**
 * @copyright Copyright (c) 2026 米柚工作室
 * @license https://opensource.org/licenses/Apache-2.0
 * @link https://www.miucms.com
 */

declare (strict_types = 1);

namespace app\api\controller;

use app\services\StorageService;
use think\facade\Config;
use think\facade\Db;
use think\facade\Session;
use think\Response;

/**
 * Office 文档在线预览接口
 */
class Office
{
    /**
     * 通过微软 Office Online Viewer 预览文档。
     */
    public function officeapps(): Response
    {
        $sessionKey = (string) Config::get('app.session_admin', 'miu_admin');
        $admin = Session::get($sessionKey);
        $adminId = is_array($admin) ? (int) ($admin['id'] ?? 0) : 0;
        if ($adminId < 1 || !Db::name('Admin')->where('id', $adminId)->where('status', 1)->field('id')->find()) {
            Session::delete($sessionKey);
            return $this->notice('请先登录后台，再预览 Office 文件。', 401);
        }

        $id = (int) request()->get('id', 0);
        if ($id < 1) {
            return $this->notice('缺少有效的文件编号。', 400);
        }

        $file = Db::name('file')
            ->field('id,name,filepath,filename,filesize,fileext,delete_time')
            ->where('id', $id)
            ->where('delete_time', 0)
            ->find();
        if (empty($file)) {
            return $this->notice('文件不存在或已删除。', 404);
        }

        $allowedExtensions = ['doc', 'docx', 'xls', 'xlsx', 'ppt', 'pptx', 'txt'];
        $extension = strtolower(trim((string) ($file['fileext'] ?? ''), ". \t\n\r\0\x0B"));
        if (!in_array($extension, $allowedExtensions, true)) {
            return $this->notice('该文件格式暂不支持 Office 在线预览。', 415);
        }

        $storedFile = StorageService::parseFilename((string) ($file['filename'] ?? ''));
        $fileUrl = ($storedFile['provider'] ?? '') === 'oss'
            ? StorageService::urlFor((string) $file['filename'])
            : $this->buildPublicFileUrl((string) ($file['filepath'] ?? ''));
        if ($fileUrl === null) {
            return $this->notice('文件存储地址无效，无法预览。');
        }

        // 纯文本在接口内读取并以内嵌文本预览，避免静态服务器将文件作为下载处理。
        if ($extension === 'txt') {
            return $this->previewTextFile(
                (string) ($file['name'] ?? ''),
                (string) ($file['filename'] ?? ''),
                (int) ($file['filesize'] ?? 0),
                $fileUrl
            );
        }

        $origin = parse_url((string) request()->domain());
        $host = strtolower(trim((string) ($origin['host'] ?? ''), '[] .'));
        if ($this->isLocalOrPrivateHost($host)) {
            return $this->notice(
                '当前站点使用 localhost 或局域网地址，微软 Office 在线预览服务无法访问该文件。请改用公网域名访问后台，并确保文件地址可从互联网直接打开。',
                400,
                $fileUrl
            );
        }

        $viewerUrl = 'https://view.officeapps.live.com/op/view.aspx?src=' . rawurlencode($fileUrl);
        return redirect($viewerUrl, 302)->header([
            'Cache-Control' => 'no-store, no-cache, must-revalidate',
            'Referrer-Policy' => 'no-referrer',
        ]);
    }

    /**
     * 将文件库中的路径限制在 public 存储目录下，并拼成当前站点的绝对 URL。
     */
    private function buildPublicFileUrl(string $storedPath): ?string
    {
        $storedPath = trim($storedPath);
        if ($storedPath === '') {
            return null;
        }

        // 文件库中既有相对路径，也有历史记录中的完整 URL；只取路径部分，始终使用当前站点域名。
        $path = parse_url($storedPath, PHP_URL_PATH);
        if (!is_string($path) || $path === '') {
            return null;
        }
        $path = rawurldecode($path);
        if (str_contains($path, "\0") || str_contains($path, '\\')) {
            return null;
        }
        $path = '/' . ltrim($path, '/');

        $storageUrl = (string) Config::get('filesystem.disks.public.url', '/storage');
        $storagePath = parse_url($storageUrl, PHP_URL_PATH);
        if (!is_string($storagePath) || $storagePath === '') {
            $storagePath = $storageUrl;
        }
        $storagePath = '/' . trim(rawurldecode($storagePath), '/');
        if ($storagePath === '/') {
            return null;
        }

        if (!str_starts_with($path, $storagePath . '/')) {
            return null;
        }

        $segments = explode('/', trim($path, '/'));
        foreach ($segments as $segment) {
            if ($segment === '' || $segment === '.' || $segment === '..') {
                return null;
            }
        }

        $encodedPath = '/' . implode('/', array_map('rawurlencode', $segments));
        $origin = parse_url((string) request()->domain());
        $scheme = strtolower((string) ($origin['scheme'] ?? ''));
        $host = (string) ($origin['host'] ?? '');
        if (!in_array($scheme, ['http', 'https'], true) || $host === '') {
            return null;
        }

        $authority = $host;
        if (isset($origin['port'])) {
            $authority .= ':' . (int) $origin['port'];
        }

        return $scheme . '://' . $authority . $encodedPath;
    }

    /** 判断微软在线预览服务无法访问的本机或私有网络主机。 */
    private function isLocalOrPrivateHost(string $host): bool
    {
        if ($host === '' || $host === 'localhost') {
            return true;
        }

        foreach (['.localhost', '.local', '.test', '.internal', '.lan', '.home'] as $suffix) {
            if (str_ends_with($host, $suffix)) {
                return true;
            }
        }

        if (filter_var($host, FILTER_VALIDATE_IP) !== false) {
            return filter_var(
                $host,
                FILTER_VALIDATE_IP,
                FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE
            ) === false;
        }

        return false;
    }

    /** 读取站内 TXT 文件，并以转义后的 HTML 文本显示。 */
    private function previewTextFile(string $fileName, string $storedFilename, int $storedSize, string $fileUrl): Response
    {
        if ($storedSize > 5 * 1024 * 1024) {
            return $this->notice('文本文件超过 5 MB，暂不支持在线预览，请下载后查看。', 413, $fileUrl);
        }

        try {
            $content = StorageService::read($storedFilename);
        } catch (\Throwable $error) {
            $content = null;
        }
        if (!is_string($content)) {
            return $this->notice('读取文本文件失败，请检查文件权限。', 500, $fileUrl);
        }

        if ($content !== '' && function_exists('mb_check_encoding') && !mb_check_encoding($content, 'UTF-8')) {
            $sourceEncoding = function_exists('mb_detect_encoding')
                ? mb_detect_encoding($content, ['UTF-16LE', 'UTF-16BE', 'GB18030', 'BIG5'], true)
                : false;
            $content = mb_convert_encoding($content, 'UTF-8', $sourceEncoding ?: 'GB18030');
        }

        $escapedName = htmlspecialchars($fileName !== '' ? $fileName : '文本文件', ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
        $escapedContent = htmlspecialchars($content !== '' ? $content : '此文本文件内容为空。', ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
        $html = '<!doctype html><html lang="zh-CN"><head><meta charset="utf-8">'
            . '<meta name="viewport" content="width=device-width,initial-scale=1">'
            . '<title>' . $escapedName . '</title><style>'
            . 'html,body{min-height:100%;margin:0;background:#fff;color:#333;}'
            . 'body{font:14px/1.8 Consolas,"Microsoft YaHei",monospace;}'
            . 'pre{box-sizing:border-box;min-height:100vh;margin:0;padding:20px 24px;white-space:pre-wrap;overflow-wrap:anywhere;}'
            . '</style></head><body><pre>' . $escapedContent . '</pre></body></html>';

        return Response::create($html, 'html')->header([
            'Cache-Control' => 'no-store, no-cache, must-revalidate',
            'Content-Security-Policy' => "default-src 'none'; style-src 'unsafe-inline'; base-uri 'none'; form-action 'none'",
            'X-Content-Type-Options' => 'nosniff',
        ]);
    }

    /** 为 iframe 返回简洁的错误说明页。 */
    private function notice(string $message, int $status = 400, string $fileUrl = ''): Response
    {
        $escapedMessage = htmlspecialchars($message, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
        $link = '';
        if ($fileUrl !== '') {
            $escapedUrl = htmlspecialchars($fileUrl, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
            $link = '<p><a href="' . $escapedUrl . '" target="_blank" rel="noopener">在新窗口打开原文件</a></p>';
        }

        $html = '<!doctype html><html lang="zh-CN"><head><meta charset="utf-8">'
            . '<meta name="viewport" content="width=device-width,initial-scale=1">'
            . '<title>Office 文件预览</title><style>'
            . 'body{margin:0;background:#f5f7fa;color:#333;font:14px/1.8 "Microsoft YaHei",sans-serif;}'
            . '.notice{box-sizing:border-box;max-width:680px;margin:12vh auto;padding:28px 32px;background:#fff;border:1px solid #e8eaf0;border-radius:8px;}'
            . '.notice h1{margin:0 0 12px;font-size:18px;font-weight:600;}.notice p{margin:0;}'
            . '.notice a{display:inline-block;margin-top:12px;color:#1e9fff;text-decoration:none;}'
            . '</style></head><body><main class="notice"><h1>无法预览文件</h1><p>'
            . $escapedMessage . '</p>' . $link . '</main></body></html>';

        return Response::create($html, 'html', $status)->header([
            'Cache-Control' => 'no-store, no-cache, must-revalidate',
            'X-Content-Type-Options' => 'nosniff',
        ]);
    }
}
