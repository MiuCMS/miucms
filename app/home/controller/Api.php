<?php
/**
 * @copyright Copyright (c) 2026 米柚工作室
 * @license https://opensource.org/licenses/Apache-2.0
 * @link https://www.miucms.com
 */

declare (strict_types = 1);

namespace app\home\controller;

use app\services\StorageService;
use app\services\UploadPolicy;
use think\facade\Cache;
use think\facade\Db;
use think\facade\Session;

/**
 * 前台接口
 *
 * 主要接口：
 *
 * 1）广告位 JS：给后台广告列表「调用标签」弹窗里那段
 *   <script type="text/javascript" src="/home/api/adv?id=7&amp;row=1"></script>
 * 用的。
 *
 * 2）文档下载：页面只请求文档 ID 和下载项编号，由后端读取并返回资源地址或文件。
 *
 * 3）会员头像上传：仅允许登录会员上传正方形的小尺寸图片。
 *
 * 地址都是**动态地址**（跟 nav_url() 动态模式同一套，走 url_entry()，
 * 不随后台的伪静态 / 静态规则变），所以生成静态模式下也照样能访问 ——
 * 见 UrlRewrite 的 DYNAMIC_PATHS 里放行的 api。
 *
 * 不继承 BaseController：这里只吐一小段 JS / JSON，用不上页面那套初始化。
 */
class Api
{
    /**
     * 上传会员头像，只接收 300 KB 以内的正方形 JPG、PNG、GIF、WebP 图片。
     */
    public function avatar_upload()
    {
        if (!request()->isPost()) {
            return to_assign(1, '错误的请求方式');
        }
        $userId = (int) get_login_user('id');
        if ($userId < 1) {
            return to_assign(1, '请先登录后再上传头像');
        }
        if (!Db::name('User')->where('id', $userId)->where('status', 1)->field('id')->find()) {
            Session::delete(get_config('app.session_user'));
            return to_assign(1, '账号不可用，请重新登录');
        }
        $cooldownKey = 'homeUserAvatarUploadCooldown_' . $userId;
        $cooldownUntil = (int) Cache::get($cooldownKey, 0);
        $remaining = $cooldownUntil - time();
        if ($remaining > 0) {
            return to_assign(1, '头像已修改成功，请稍候再试', ['retry_after' => $remaining]);
        }

        $file = request()->file('file');
        if (!$file) {
            return to_assign(1, '请选择头像图片');
        }
        if ($file->getSize() > 300 * 1024) {
            return to_assign(1, '头像图片不能超过 300 KB');
        }

        $image = @getimagesize($file->getPathname());
        $mime = is_array($image) ? (string) ($image['mime'] ?? '') : '';
        $extension = strtolower((string) $file->extension());
        if (!UploadPolicy::imageMimeMatches($mime, $extension, 'avatar')) {
            return to_assign(1, '仅支持 JPG、PNG、GIF、WebP 图片');
        }
        if ((int) $image[0] < 1 || (int) $image[0] !== (int) $image[1]) {
            return to_assign(1, '头像图片必须为正方形');
        }

        $sha1 = $file->hash('sha1');
        $md5 = $file->hash('md5');
        try {
            $stored = StorageService::uploadFile(
                'user/avatar/' . date('Ym') . '/' . bin2hex(random_bytes(16)) . '.' . $extension,
                $file,
                $mime
            );
        } catch (\Throwable $e) {
            return to_assign(1, '头像上传失败，请稍后重试');
        }
        $filepath = $stored['filepath'];
        $uploadIp = (string) request()->ip();
        $fileInfo = [
            'module' => 'home',
            'sha1' => $sha1,
            'md5' => $md5,
            'name' => mb_substr((string) $file->getOriginalName(), 0, 255, 'UTF-8'),
            'filename' => $stored['filename'],
            'filepath' => $filepath,
            'filesize' => (int) $file->getSize(),
            'fileext' => $extension,
            'mimetype' => $mime,
            'group_id' => 2,
            'user_id' => $userId,
            'uploadip' => strlen($uploadIp) <= 15 ? $uploadIp : '',
            'status' => 0,
            'create_time' => time(),
            'admin_id' => 0,
            'action' => 'avatar_upload',
            'use' => '会员头像',
        ];

        try {
            $fileResult = Db::transaction(function () use ($fileInfo, $userId, $filepath) {
                if (!Db::name('User')->where('id', $userId)->where('status', 1)->lock(true)->field('id')->find()) {
                    throw new \RuntimeException('会员账号不可用');
                }
                $lastUploadAt = (int) Db::name('File')
                    ->where('user_id', $userId)
                    ->where('action', 'avatar_upload')
                    ->where('create_time', '>', time() - 60)
                    ->order('id desc')
                    ->value('create_time');
                if ($lastUploadAt > 0) {
                    return ['retry_after' => max(1, 60 - (time() - $lastUploadAt))];
                }
                $fileId = Db::name('File')->insertGetId($fileInfo);
                if (Db::name('User')->where('id', $userId)->update(['headimgurl' => $filepath]) === false) {
                    throw new \RuntimeException('会员头像保存失败');
                }
                return ['file_id' => (int) $fileId];
            });
        } catch (\Throwable $e) {
            try {
                StorageService::delete($stored['filename']);
            } catch (\Throwable $ignored) {
            }
            return to_assign(1, '头像保存失败，请稍后重试');
        }
        if (isset($fileResult['retry_after'])) {
            try {
                StorageService::delete($stored['filename']);
            } catch (\Throwable $ignored) {
            }
            $remaining = (int) $fileResult['retry_after'];
            return to_assign(1, '头像已修改成功，请稍候再试', ['retry_after' => $remaining]);
        }

        clear_cache('homeUser');
        Cache::set($cooldownKey, time() + 60, 60);
        $sessionUser = get_login_user();
        if (is_array($sessionUser)) {
            $sessionUser['headimgurl'] = $filepath;
            Session::set(get_config('app.session_user'), $sessionUser);
        }
        return to_assign(0, '头像已上传并保存', ['filepath' => $filepath, 'file_id' => (int) $fileResult['file_id']]);
    }

    /**
     * 广告位 JS
     *
     *   /home/api/adv?id=7&row=1
     *
     * @return \think\Response
     */
    public function adv()
    {
        $id  = (int) request()->param('id', 0);
        $row = (int) request()->param('row', 0);

        return response(miu_adv_js($id, $row))
            ->contentType('application/javascript');
    }

    /**
     * 由后端处理文档下载，避免把附件地址直接写入页面。
     *
     * @return \think\Response
     */
    public function download()
    {
        $docId = (int) request()->param('id', 0);
        $index = (int) request()->param('index', -1);
        if ($docId < 1 || $index < 0) {
            abort(404);
        }

        $doc = miu_cached_document($docId);
        if (!$doc || (int) ($doc['arcrank'] ?? -1) < 0) {
            abort(404);
        }

        $channelId = (int) ($doc['channel_id'] ?? 0);
        $channel = get_channel($channelId);
        $table = (string) ($channel['table'] ?? '');
        if ($channelId < 1 || $table === '') {
            abort(404);
        }

        $extra = miu_cached_model_row($table, $docId, ['Document']);
        $downloads = miu_doc_download_list($doc + $extra, $channelId);
        $item = $downloads[$index] ?? null;
        if (!is_array($item) || empty($item['url'])) {
            abort(404);
        }

        // 后续在此处接入当前用户的资源下载权限校验。
        if (!empty($item['external'])) {
            return redirect($item['url']);
        }

        $fileRecord = Db::name('File')
            ->field('filename,filepath')
            ->where('filepath', (string) $item['url'])
            ->where('delete_time', 0)
            ->find();
        $storedFile = is_array($fileRecord)
            ? StorageService::parseFilename((string) ($fileRecord['filename'] ?? ''))
            : null;
        if (($storedFile['provider'] ?? '') === 'oss') {
            return redirect(StorageService::urlFor((string) $fileRecord['filename']));
        }

        $publicUrl = (string) config('filesystem.disks.public.url', '/storage');
        $publicUrl = parse_url($publicUrl, PHP_URL_PATH) ?: $publicUrl;
        $publicUrl = '/' . trim(str_replace('\\', '/', $publicUrl), '/');
        $urlPath = (string) (parse_url($item['url'], PHP_URL_PATH) ?? '');
        $prefix = $publicUrl . '/';
        if ($publicUrl === '/' || strpos($urlPath, $prefix) !== 0) {
            abort(404);
        }

        $relativePath = rawurldecode(substr($urlPath, strlen($prefix)));
        $relativePath = str_replace('\\', '/', $relativePath);
        $segments = explode('/', $relativePath);
        if ($relativePath === '' || str_contains($relativePath, "\0")
            || in_array('', $segments, true) || in_array('.', $segments, true) || in_array('..', $segments, true)) {
            abort(404);
        }

        $storageRoot = realpath((string) config('filesystem.disks.public.root', ''));
        if ($storageRoot === false) {
            abort(404);
        }
        $filePath = $storageRoot . DIRECTORY_SEPARATOR . implode(DIRECTORY_SEPARATOR, $segments);
        $realPath = realpath($filePath);
        $rootPrefix = rtrim($storageRoot, '\\/') . DIRECTORY_SEPARATOR;
        if ($realPath === false || !is_file($realPath) || strpos($realPath, $rootPrefix) !== 0) {
            abort(404);
        }

        return download($realPath, basename($realPath));
    }
}
