<?php
/**
 * @copyright Copyright (c) 2026 米柚工作室
 * @license https://opensource.org/licenses/Apache-2.0
 * @link https://blog.miucms.com
 */

declare (strict_types = 1);

namespace app\admin\controller;

use app\admin\BaseController;
use app\admin\model\AdminLog;
use app\admin\validate\AdminCheck;
use app\services\StorageService;
use app\services\UploadPolicy;
use think\exception\ValidateException;
use think\facade\Db;
use think\facade\Session;
use think\Image;

class Api extends BaseController
{
    //上传文件
    public function upload()
    {
        if (!request()->isPost()) {
            return to_assign(1, '仅支持 POST 请求');
        }
        $param = get_params();
		//var_dump($param);exit;
		$sourse = 'file';
		if(isset($param['sourse'])){
			$sourse = $param['sourse'];
		}
		if($sourse == 'file' || $sourse == 'tinymce'){
			if(request()->file('file')){
				$file = request()->file('file');
			}
			else{
				return to_assign(1, '没有选择上传文件');
			}
		}
		else{
			if (request()->file('editormd-image-file')) {
				$file = request()->file('editormd-image-file');
			} else {
				return to_assign(1, '没有选择上传文件');
			}
		}
		$fileExt = implode(',', UploadPolicy::extensions());
		//1M=1024*1024=1048576字节
		$file_size = get_system_config('upload_max_filesize');
		$fileSize = max(1, (int) ($file_size ?: 50)) * 1024 * 1024;
		if (isset($param['type']) && $param['type']) {
			if (!is_scalar($param['type'])) {
				return to_assign(1, '上传类型参数无效');
			}
			$typeExt = UploadPolicy::extensions((string) $param['type']);
			if (!$typeExt) {
				return to_assign(1, '不支持的附件类型');
			}
			$fileExt = implode(',', $typeExt);
		}
		if (array_key_exists('size', $param) && $param['size'] !== '') {
			if (!is_scalar($param['size']) || !ctype_digit((string) $param['size']) || (int) $param['size'] < 1) {
				return to_assign(1, '上传大小参数无效');
			}
			$fileSize = min($fileSize, (int) $param['size']);
		}
		// SVG/HTML/CSS/JS 会被浏览器主动解析，只允许超级管理员上传，避免低权限账号植入同源脚本。
		if ((int) get_login_admin('id') !== 1) {
			$fileExt = implode(',', array_values(array_diff(explode(',', $fileExt), UploadPolicy::extensions('source'))));
			if ($fileExt === '') {
				return to_assign(1, '当前账号无权上传此类文件');
			}
		}
		$validate = \think\facade\Validate::rule([
			'image' => 'require|fileSize:' . $fileSize . '|fileExt:' . $fileExt,
		]);
        $file_check['image'] = $file;
        if (!$validate->check($file_check)) {
            return to_assign(1, $validate->getError());
        }
        // 校验通过后再计算哈希，避免无效大文件触发额外读取。
        $sha1 = $file->hash('sha1');
        $md5 = $file->hash('md5');
        // 日期前綴
        $dataPath = date('Ym');
        $objectName = bin2hex(random_bytes(16));
        $use = 'thumb';
		try {
			$extension = strtolower((string) $file->extension());
			$mimeType = (string) $file->getOriginalMime();
			$storedSize = (int) $file->getSize();
			if (in_array($extension, UploadPolicy::extensions('image'), true)) {
				$actualMime = (new \finfo(FILEINFO_MIME_TYPE))->file($file->getPathname()) ?: '';
				if (!UploadPolicy::imageMimeMatches($actualMime, $extension)) {
					throw new \RuntimeException('图片文件内容与扩展名不匹配');
				}
				$mimeType = $actualMime;
			}
			if (isset($param['thumb'])) {
				$tempFile = tempnam(sys_get_temp_dir(), 'miucms_upload_thumb_');
				if ($tempFile === false) {
					throw new \RuntimeException('无法创建缩略图临时文件');
				}
				$thumbPath = $tempFile . '.' . $extension;
				@unlink($tempFile);
				try {
					Image::open($file->getPathname())->thumb(500, 500, Image::THUMB_CENTER)->save($thumbPath);
					$thumbContents = file_get_contents($thumbPath);
					if (!is_string($thumbContents)) {
						throw new \RuntimeException('读取缩略图失败');
					}
					$storedSize = strlen($thumbContents);
					$mimeType = (string) (new \finfo(FILEINFO_MIME_TYPE))->buffer($thumbContents);
					$stored = StorageService::putContents($dataPath . '/' . $objectName . '_thumb.' . $extension, $thumbContents, $mimeType);
				} finally {
					if (is_file($thumbPath)) @unlink($thumbPath);
				}
			} else {
				$stored = StorageService::uploadFile($dataPath . '/' . $objectName . '.' . $extension, $file, $mimeType);
			}
		} catch (\Throwable $error) {
			return to_assign(1, '上传失败：' . $error->getMessage());
		}
		if (!empty($stored['filename'])) {
			$filepath = $stored['filepath'];
			
            //写入到附件表
            $data = [];
            $data['filepath'] = $filepath;
            $data['name'] = $file->getOriginalName();
            $data['mimetype'] = $mimeType;
			$data['fileext'] = $extension;
			$data['filesize'] = $storedSize;
			$data['filename'] = $stored['filename'];
            $data['sha1'] = $sha1;
            $data['md5'] = $md5;
            $data['module'] = app('http')->getName();
            $data['action'] = app('request')->action();
            $data['uploadip'] = app('request')->ip();
            $data['create_time'] = time();
            $data['user_id'] = get_login_admin('id') ? get_login_admin('id') : 0;
			if ($data['module'] === 'admin') {
                //通过后台上传的文件直接审核通过
                $data['status'] = 1;
                $data['admin_id'] = $data['user_id'];
                $data['audit_time'] = time();
            }
            $data['use'] = request()->has('use') ? request()->param('use') : $use; //附件用处
            // 前端传入的分组 ID
            if (isset($param['group_id']) && $param['group_id'] !== '') {
                $data['group_id'] = (int) $param['group_id'];
            }
            try {
                $res['id'] = Db::name('file')->insertGetId($data);
            } catch (\Throwable $error) {
                try {
                    StorageService::delete($stored['filename']);
                } catch (\Throwable $cleanupError) {
                    trace('上传失败后清理存储文件失败：' . $cleanupError->getMessage(), 'error');
                }
                trace('上传文件记录保存失败：' . $error->getMessage(), 'error');
                return to_assign(1, '上传失败，请重试');
            }
            $res['filepath'] = $data['filepath'];
            $res['name'] = $data['name'];
            $res['filename'] = $data['filename'];

            //插件钩子：文件上传后（含 id/filepath/name/fileext 等）
            $fileInfo = $data;
            $fileInfo['id'] = (int) $res['id'];
            \app\services\PluginService::fileUpload($fileInfo);

            safe_add_log('upload', $data['user_id'], $data,'文件');
			if($sourse == 'editormd'){
				//editormd编辑器上传返回
				return json(['success'=>1,'message'=>'上传成功','url'=>$data['filepath']]);
			}
			else if($sourse == 'tinymce'){
				//tinymce编辑器上传返回
				return json(['success'=>1,'message'=>'上传成功','location'=>$data['filepath']]);
			}
			else{
				//普通上传返回
				return to_assign(0, '上传成功', $res);
			}            
        } else {
            return to_assign(1, '上传失败，请重试');
        }
    }
	
    //获取权限树所需的节点列表
    public function get_rule()
    {
        $rule = get_admin_rule();
        $group = [];
        if (!empty(get_params('id'))) {
            $group = get_admin_group_info(get_params('id'))['rules'];
        }
        $list = create_tree_list(0, $rule, $group);
        return to_assign(0, '', $list);
    }

    //获取关键字
    public function get_keyword_cate()
    {
        $keyword = get_keywords();
        return to_assign(0, '', $keyword);
    }

    //获取话题
    public function get_topics_cate()
    {
        $topic = get_topics();
        return to_assign(0, '', $topic);
    }

    //清空缓存
    public function cache_clear()
    {
		if (!request()->isPost()) {
			return to_assign(1, '请使用 POST 请求');
		}
        \think\facade\Cache::clear();
        //顺便把当前模板自带的静态资源重新发布一次，并清掉模板编译缓存
        $assetSync = sync_template_assets(get_current_template());
        clear_tpl_cache();
        //再扫一遍「已过期」的文件缓存：Cache::clear() 只删缓存前缀那个目录，
        //万一有历史遗留（换过 prefix、上次没删干净）也一并收掉
        $expired = clear_expired_cache();
        $msg     = '系统缓存已清空';
        if ($expired['files'] > 0) {
            $msg .= '，另外清理了 ' . $expired['files'] . ' 个过期缓存文件';
        }
        if ($assetSync['failed'] > 0) {
            $msg .= '；模板静态资源有 ' . $assetSync['failed'] . ' 项同步失败';
            foreach ($assetSync['failed_details'] as $failure) {
                $path = htmlspecialchars($failure['path'], ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
                $reason = htmlspecialchars($failure['reason'], ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
                $msg .= '；' . $path . '：' . $reason;
            }
            $msg .= '。请检查对应路径的权限和磁盘空间';
            return to_assign(1, $msg);
        }
        return to_assign(0, $msg);
    }

    //发送测试邮件
    public function email_to($email)
    {
        if (!request()->isPost() || !valid_admin_csrf()) return to_assign(1, '请求无效，请刷新页面后重试');
        $name = empty(get_config('webconfig.admin_title')) ? '系统' : get_config('webconfig.admin_title');
        if (send_email($email, "一封来自{$name}的测试邮件。")) {
            return to_assign(0, '发送成功，请注意查收');
        }
        return to_assign(1, '发送失败');
    }

    //验证当前管理员的密码（锁屏解锁用）
    public function check_password()
    {
        $pwd = (string) get_params('pwd');
        if ($pwd === '') {
            return to_assign(1, '请输入管理员密码');
        }
        $admin = get_login_admin();
        if (!verify_user_password($pwd, $admin['pwd'], $admin['salt'])) {
            return to_assign(1, '密码不正确');
        }
        return to_assign(0, '解锁成功');
    }

    // 测试邮件发送
    public function email_test()
    {
        if (!request()->isPost()) {
            return to_assign(1, '请使用 POST 请求');
        }

        $senderParam = get_params('email');
        if (!is_scalar($senderParam)) {
            return to_assign(1, '请填写有效的收件邮箱');
        }
        $sender = trim((string) $senderParam);
        $validate = \think\facade\Validate::rule(['email' => 'require|email']);
        if (!$validate->check(['email' => $sender])) {
            return to_assign(1, $validate->getError());
        }

        // 邮件设置保存在 global 配置组，不再读取旧的 email 配置行。
        $config = get_system_config();
        if (empty($config['smtp']) || empty($config['smtp_port']) || empty($config['smtp_user'])
            || empty($config['smtp_pwd']) || empty($config['email']) || empty($config['from'])) {
            return to_assign(1, '请完善邮件配置信息！');
        }

        $content = (string) ($config['email_template'] ?? '');
        if (trim($content) === '') {
            $siteName = htmlspecialchars((string) ($config['site_name'] ?? 'MiuCMS'), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
            $content = '<p>这是一封来自 ' . $siteName . ' 的测试邮件。</p>';
        }

        try {
            $send = send_email($sender, '测试邮件', $content);
        } catch (\Throwable $e) {
            \think\facade\Log::error('测试邮件发送异常：' . $e->getMessage());
            return to_assign(1, '邮件发送失败，请检查 SMTP 配置或查看系统日志');
        }
        if ($send) {
            return to_assign(0, '邮件发送成功！');
        } else {
            return to_assign(1, '邮件发送失败！');
        }
    }

    //首页获取
    public function get_admin_list()
    {
        $content = Db::name('Admin')
            ->field('id,username,nickname,thumb,last_login_time,login_num')
            ->where(['status' => 1])
            ->order('id desc')
            ->limit(10)
            ->select()->toArray();
        $res['data'] = $content;
        return table_assign(0, '', $res);
    }

    //首页获取最新10位用户
    public function get_user_list()
    {
        $list = Db::name('User')
            ->field('id,username,nickname,email,headimgurl,register_time,last_login_time,login_num')
            ->where(['status' => 1])
            ->order('id desc')
            ->limit(10)
            ->select()->toArray();
        foreach ($list as $key => $val) {
            $list[$key]['last_login_time'] = !empty($val['last_login_time']) ? date('Y-m-d H:i:s', (int) $val['last_login_time']) : '';
        }
        $res['data'] = $list;
        return table_assign(0, '', $res);
    }

    //首页文章
    public function get_article_list()
    {
        $list = Db::name('document')
            ->field('a.id,a.title,a.click,a.status,a.add_time,c.title as cate_title')
            ->alias('a')
            ->join('nav c', 'a.nav_id = c.id')
            ->where(['a.delete_time' => 0])
            ->order('a.add_time desc')
            ->limit(10)
            ->select()->toArray();
        foreach ($list as $key => $val) {
            $list[$key]['add_time'] = date('Y-m-d H:i:s', $val['add_time']);
        }
        $res['data'] = $list;
        return table_assign(0, '', $res);
    }

    //系统操作日志
    public function log_list()
    {
        return view('admin/log_list');
    }

    //获取系统操作日志
    public function get_log_list()
    {
        $param = get_params();
        $log = new AdminLog();
        $content = $log->get_log_list($param);
        return table_assign(0, '', $content);
    }
	
    //保存作者/文章来源预设值（配置文件存储，不写数据库）
    public function save_preset()
    {
		if (!request()->isPost()) {
			return to_assign(1, '请使用 POST 请求');
		}
        $param = get_params();
        $type = isset($param['type']) ? $param['type'] : '';
        $values = isset($param['values']) ? $param['values'] : '';
        if (!in_array($type, ['author', 'from'], true) || !is_scalar($values)) {
            return to_assign(1, '参数错误');
        }
        // 每行一个，去空、去重、过滤安全隐患
        $lines = array_values(array_unique(array_filter(array_map(function($line) {
            $line = trim($line);
            // 去除 HTML/JS 标签，防止注入
            $line = strip_tags($line);
            // 限制单条长度，防止超长内容
            $line = mb_substr($line, 0, 30, 'UTF-8');
            return $line;
        }, explode("\n", (string)$values)))));
        // 限制总条数，防止文件过大
        $lines = array_slice($lines, 0, 50);
        $file = root_path() . 'config/doc_preset.json';
        $preset = ['author' => [], 'from' => []];
        if (is_file($file)) {
            $preset = json_decode(file_get_contents($file), true) ?: $preset;
        }
        $preset[$type] = $lines;
        $dir = dirname($file);
        if (!is_dir($dir)) {
            mkdir($dir, 0755, true);
        }
        $res = file_put_contents($file, json_encode($preset, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT));
        if ($res !== false) {
            return to_assign(0, '保存成功');
        }
        return to_assign(1, '保存失败');
    }

}
