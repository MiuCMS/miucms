<?php
/**
 * @copyright Copyright (c) 2026 米柚工作室
 * @license https://opensource.org/licenses/GPL-3.0
 * @link https://www.miucms.com
 */

declare (strict_types = 1);

namespace app\admin\controller;

use app\admin\BaseController;
use app\admin\model\File;
use app\services\StorageService;
use think\exception\ValidateException;
use think\facade\Config;
use think\facade\Db;
use think\facade\View;

class Files extends BaseController
{
    public function index()
    {
        if (request()->isAjax()) {
            $param = get_params();
            $where = array();
			// 文件类型过滤配置
			// filter 参数取值说明：
			// - image: jpg,jpeg,png,gif,bmp,webp
			// - video: mp4,avi,mov,wmv,flv,mkv,webm
			// - audio: mp3,wav,wma,flac,midi
			// - doc:   doc,docx,xls,xlsx,ppt,pptx,txt,pdf
			// - zip:   zip,rar,7z
			// - 空值或 all: 不过滤（全部）
			$filterMap = [
				'image' => ['jpg','jpeg','png','gif','bmp','webp'],
				'video' => ['mp4','avi','mov','wmv','flv','mkv','webm'],
				'audio' => ['mp3','wav','wma','flac','midi'],
				'doc'   => ['doc','docx','xls','xlsx','ppt','pptx','txt','pdf','html','css','js'],
				'zip'   => ['zip','rar','7z'],
			];
			// 原 tab 分组的扩展名映射（仅兼容旧调用保留）
			$fileext = ['','jpg,jpeg,png,gif,bmp','mp4','doc,docx,xls,xlsx,ppt,pptx,txt,pdf','zip,rar,7z','html','css','js'];
			if (!empty($param['filter']) && isset($filterMap[$param['filter']])) {
				// URL filter 参数优先：支持 image/video/audio/doc/zip
				$where[] = ['f.fileext','in',$filterMap[$param['filter']]];
			} elseif (!empty($param['tab']) && isset($fileext[$param['tab']]) && $fileext[$param['tab']] !== '') {
				// 兼容原有 tab 分组（字符串转数组），tab=0 表示全部、不追加过滤条件
				$where[] = ['f.fileext','in',explode(',',$fileext[$param['tab']])];
			}
            if (!empty($param['keywords'])) {
                $where[] = ['f.name','like','%'.$param['keywords'].'%'];
            }
            if (isset($param['group_id']) && $param['group_id']!='') {
                $where[] = ['f.group_id','=',$param['group_id']];
            }
            if ((string) ($param['recycle'] ?? '') === '1') {
                $where[] = ['f.delete_time', '>', 0];
            } else {
                $where[] = ['f.delete_time', '=', 0];
            }
            $rows = empty($param['limit']) ? get_config('app.page_size') : $param['limit'];
            $list = DB::name('File')
                ->field("f.*,a.username,g.title as group_title")
				->alias('f')
				->join('Admin a', 'f.admin_id = a.id','left')
				->join('FileGroup g', 'f.group_id = g.id','left')
                ->order('f.create_time desc')
                ->where($where)
                ->paginate(['list_rows'=> $rows])
				->each(function($item, $key){
					$item['create_time'] = date('Y-m-d H:i:s', $item['create_time']);
					$item['delete_time'] = $item['delete_time'] > 0 ? date('Y-m-d H:i:s', $item['delete_time']) : '';
					return $item;
				});
            return table_assign(0, '', $list);
        } else {
            return view();
        }
    }
	//编辑
    public function edit()
    {
        if (request()->isAjax()) {
            if (!request()->isPost()) {
                return to_assign(1, '请使用 POST 请求');
            }
            $param = get_params();
            if ((string) ($param['recycle_action'] ?? '') === 'restore') {
                if (!request()->isPost()) {
                    return to_assign(1, '错误的请求');
                }
                $rawIds = $param['ids'] ?? $param['id'] ?? '';
                $inputIds = is_array($rawIds) ? $rawIds : explode(',', (string) $rawIds);
                $ids = [];
                foreach ($inputIds as $id) {
                    $id = trim((string) $id);
                    if ($id === '' || !ctype_digit($id) || (int) $id < 1) {
                        return to_assign(1, '文件编号无效');
                    }
                    $ids[] = (int) $id;
                }
                $ids = array_values(array_unique($ids));
                if (!$ids || count($ids) > 500) {
                    return to_assign(1, $ids ? '单次最多还原 500 个文件' : '请先选择要还原的文件');
                }
                $res = Db::name('File')->whereIn('id', $ids)->where('delete_time', '>', 0)->update(['delete_time' => 0]);
                if ($res) {
                    safe_add_log('edit', $ids[0], ['recycle_action' => 'restore', 'ids' => $ids]);
                    return to_assign(0, '已还原 ' . $res . ' 个文件');
                }
                return to_assign(1, '回收站中没有找到要还原的文件');
            }
            $id = $param['id'] ?? null;
            $title = $param['title'] ?? null;
            if (!is_scalar($id) || !ctype_digit((string) $id) || (int) $id < 1
                || !is_scalar($title) || trim((string) $title) === ''
                || mb_strlen((string) $title, 'UTF-8') > 255
                || preg_match('/[\\x00-\\x1F\\x7F]/u', (string) $title)) {
                return to_assign(1, '文件名称格式不正确');
            }
            $id = (int) $id;
            if (Db::name('File')->where('id', $id)->where('delete_time', 0)->update(['name' => trim((string) $title)]) !== false) {
                safe_add_log('edit', $id, []);
                return to_assign(0, "操作成功");
            } else {
                return to_assign(1, "操作失败");
            }
        }
    }
	
	//移动
    public function move()
    {
        if (!request()->isDelete()) {
            return to_assign(1, '请使用 DELETE 请求');
        }
        $groupId = get_params('group_id');
        $rawIds = get_params('ids');
        if (!is_scalar($groupId) || !ctype_digit((string) $groupId)
            || !is_scalar($rawIds) || trim((string) $rawIds) === '') {
            return to_assign(1, '分组或文件编号无效');
        }
        $groupId = (int) $groupId;
        if ($groupId > 0 && !Db::name('FileGroup')->where('id', $groupId)->where('delete_time', 0)->field('id')->find()) {
            return to_assign(1, '目标分组不存在');
        }
        $ids = [];
        foreach (explode(',', (string) $rawIds) as $id) {
            $id = trim($id);
            if (!ctype_digit($id) || (int) $id < 1) {
                return to_assign(1, '文件编号无效');
            }
            $ids[] = (int) $id;
        }
        $ids = array_values(array_unique($ids));
        if (!$ids) {
            return to_assign(1, '请先选择文件');
        }
        $updated = Db::name('File')->whereIn('id', $ids)->where('delete_time', 0)
            ->update(['group_id' => $groupId, 'update_time' => time()]);
        return $updated !== false ? to_assign(0, '操作成功') : to_assign(1, '操作失败');
    }
    //删除
    public function delete()
    {
        if (!request()->isDelete()) {
            return to_assign(1, "错误的请求");
        }

        $recycleAction = (string) get_params('recycle_action');
        if ($recycleAction === 'clear') {
            return $this->permanentlyDeleteRecycleFiles();
        }

        $rawIds = get_params('ids');
        $inputIds = is_array($rawIds) ? $rawIds : explode(',', (string) $rawIds);
        $idArray = [];
        foreach ($inputIds as $id) {
            $id = trim((string) $id);
            if ($id === '' || !ctype_digit($id) || (int) $id < 1) {
                return to_assign(1, '文件编号无效');
            }
            $idArray[] = (int) $id;
        }
        $idArray = array_values(array_unique($idArray));
        if (!$idArray) {
            return to_assign(1, '请先选择文件');
        }
        if (count($idArray) > 500) {
            return to_assign(1, '单次最多删除 500 个文件');
        }

        if ($recycleAction === 'delete') {
            return $this->permanentlyDeleteRecycleFiles($idArray);
        }

        $files = Db::name('File')
            ->field('id,filename,filepath')
            ->whereIn('id', $idArray)
            ->where('delete_time', 0)
            ->select()
            ->toArray();
        if (!$files) {
            return to_assign(1, '没有找到要删除的文件');
        }

        $deleteSource = (string) (get_params('delete_source') ?? '0') === '1';
        $pathsToDelete = [];
        $sharedPaths = [];
        $relativePathById = [];
        $retainedIds = [];
        if ($deleteSource) {
            $referencedImagePaths = $this->findReferencedImagePaths($files);
            if ($referencedImagePaths === null) {
                return to_assign(1, '检查文档缩略图或会员头像引用失败，源文件未删除');
            }

            foreach ($files as $file) {
                $storedFilename = (string) ($file['filename'] ?? '');
                $stored = StorageService::parseFilename($storedFilename);
                if ($stored === null) {
                    return to_assign(1, '附件记录中的源文件路径无效，未执行删除');
                }
                $relativePath = $stored['filename'];
                $relativePathById[(int) $file['id']] = $relativePath;

                $filenameVariants = array_values(array_unique([
                    $storedFilename,
                    str_replace('\\', '/', $storedFilename),
                    str_replace('/', '\\', $storedFilename),
                ]));
                $otherReferences = Db::name('File')
                    ->whereIn('filename', $filenameVariants)
                    ->where('delete_time', 0)
                    ->whereNotIn('id', $idArray)
                    ->count();
                if ($otherReferences > 0) {
                    $sharedPaths[$relativePath] = true;
                    $retainedIds[(int) $file['id']] = true;
                    continue;
                }
                if (isset($referencedImagePaths[$relativePath])) {
                    $sharedPaths[$relativePath] = true;
                    $retainedIds[(int) $file['id']] = true;
                    continue;
                }

                if ($stored['provider'] === 'local') {
                    $storageRoot = realpath((string) Config::get('filesystem.disks.public.root', ''));
                    if ($storageRoot === false) {
                        return to_assign(1, '无法定位站内文件存储目录，源文件未删除');
                    }
                    $candidate = $storageRoot . DIRECTORY_SEPARATOR
                        . str_replace('/', DIRECTORY_SEPARATOR, $stored['key']);
                    if (!file_exists($candidate) && !is_link($candidate)) {
                        continue;
                    }
                    $realPath = realpath($candidate);
                    if ($realPath === false || !is_file($realPath) || !$this->isWithinStorageRoot($realPath, $storageRoot)) {
                        return to_assign(1, '源文件不在站内文件存储目录中，未执行删除');
                    }
                }

                $pathsToDelete[$relativePath] = true;
            }

            if ($retainedIds) {
                $res = Db::name('File')->whereIn('id', array_keys($retainedIds))
                    ->where('delete_time', 0)->update(['delete_time' => time()]);
                if ($res === false) {
                    return to_assign(1, '文件仍被引用，但移入回收站失败，未执行源文件删除');
                }
            }
        }

        $deletedSourceCount = 0;
        $failedSourceCount = 0;
        $failedPaths = [];
        if ($deleteSource && $pathsToDelete) {
            foreach (array_keys($pathsToDelete) as $relativePath) {
                try {
                    if (StorageService::delete($relativePath)) {
                        $deletedSourceCount++;
                    } else {
                        $failedSourceCount++;
                        $failedPaths[$relativePath] = true;
                    }
                } catch (\Throwable $error) {
                    $failedSourceCount++;
                    $failedPaths[$relativePath] = true;
                }
            }
        }

        if ($deleteSource) {
            $deletedIds = [];
            foreach ($files as $file) {
                $id = (int) $file['id'];
                $relativePath = $relativePathById[$id] ?? null;
                if (isset($retainedIds[$id]) || ($relativePath !== null && isset($failedPaths[$relativePath]))) {
                    continue;
                }
                $deletedIds[] = $id;
            }
            if ($deletedIds) {
                try {
                    $res = Db::name('File')->whereIn('id', $deletedIds)->delete();
                } catch (\Throwable $error) {
                    return to_assign(1, '数据库记录删除失败，请刷新后重试');
                }
                if ($res === false) {
                    return to_assign(1, '数据库记录删除失败，请刷新后重试');
                }
            }
        } else {
            $deleteRows = [];
            $deleteTime = time();
            foreach ($files as $file) {
                $deleteRows[] = [
                    'id' => (int) $file['id'],
                    'delete_time' => $deleteTime,
                ];
            }
            $res = (new File())->saveAll($deleteRows);
            if ($res === false) {
                return to_assign(1, '删除失败');
            }
            $deletedIds = array_map(static fn($file) => (int) $file['id'], $files);
        }

        $message = '删除成功';
        if ($deleteSource) {
            $message .= '，彻底删除附件记录 ' . count($deletedIds) . ' 条';
            if ($deletedSourceCount > 0) {
                $message .= '，同时删除源文件 ' . $deletedSourceCount . ' 个';
            } elseif (!$sharedPaths && !$failedSourceCount) {
                $message .= '，未找到需要删除的源文件';
            }
            if ($sharedPaths) {
                $message .= '；' . count($retainedIds) . ' 个仍被引用的附件已移入回收站，源文件和数据库记录均保留';
            }
            if ($failedSourceCount > 0) {
                $message .= '；' . $failedSourceCount . ' 个源文件物理删除失败，对应附件记录已保留';
            }
        }

        return to_assign(0, $message, ['deleted_ids' => $deletedIds, 'retained_ids' => array_keys($retainedIds)]);
    }

    /** 永久清理回收站记录及未被正常附件、文档缩略图或会员头像引用的源文件。 */
    private function permanentlyDeleteRecycleFiles(?array $ids = null)
    {
        $query = Db::name('File')->field('id,filename,filepath')->where('delete_time', '>', 0);
        if ($ids !== null) {
            $query->whereIn('id', $ids);
        }
        $files = $query->select()->toArray();
        if (!$files) {
            return to_assign(1, $ids === null ? '回收站已经是空的' : '没有找到要删除的文件');
        }

        $referencedImagePaths = $this->findReferencedImagePaths($files);
        if ($referencedImagePaths === null) {
            return to_assign(1, '检查文档缩略图或会员头像引用失败，未执行彻底删除');
        }

        $idArray = [];
        $relativePathById = [];
        $pathsToDelete = [];
        $sharedPaths = [];
        $protectedIds = [];
        foreach ($files as $file) {
            $id = (int) $file['id'];
            $stored = StorageService::parseFilename((string) ($file['filename'] ?? ''));
            if ($stored === null) {
                return to_assign(1, '附件记录中的源文件路径无效，未执行删除');
            }
            $relativePath = $stored['filename'];
            $idArray[] = $id;
            $relativePathById[$id] = $relativePath;

            $filenameVariants = array_values(array_unique([
                (string) $file['filename'],
                str_replace('\\', '/', (string) $file['filename']),
                str_replace('/', '\\', (string) $file['filename']),
            ]));
            if (Db::name('File')->whereIn('filename', $filenameVariants)->where('delete_time', 0)->count() > 0) {
                $sharedPaths[$relativePath] = true;
                $protectedIds[$id] = true;
                continue;
            }
            if (isset($referencedImagePaths[$relativePath])) {
                $sharedPaths[$relativePath] = true;
                $protectedIds[$id] = true;
                continue;
            }

            if ($stored['provider'] === 'local') {
                $storageRoot = realpath((string) Config::get('filesystem.disks.public.root', ''));
                if ($storageRoot === false) {
                    return to_assign(1, '无法定位站内文件存储目录，源文件未删除');
                }
                $candidate = $storageRoot . DIRECTORY_SEPARATOR
                    . str_replace('/', DIRECTORY_SEPARATOR, $stored['key']);
                if (!file_exists($candidate) && !is_link($candidate)) {
                    continue;
                }
                $realPath = realpath($candidate);
                if ($realPath === false || !is_file($realPath) || !$this->isWithinStorageRoot($realPath, $storageRoot)) {
                    return to_assign(1, '源文件不在站内文件存储目录中，未执行删除');
                }
            }
            $pathsToDelete[$relativePath] = true;
        }

        $failedPaths = [];
        foreach (array_keys($pathsToDelete) as $relativePath) {
            try {
                if (!StorageService::delete($relativePath)) {
                    $failedPaths[$relativePath] = true;
                }
            } catch (\Throwable $error) {
                $failedPaths[$relativePath] = true;
            }
        }

        $deletedIds = [];
        foreach ($idArray as $id) {
            if (!isset($protectedIds[$id]) && !isset($failedPaths[$relativePathById[$id]])) {
                $deletedIds[] = $id;
            }
        }
        if (!$deletedIds) {
            if ($protectedIds) {
                if ($failedPaths) {
                    return to_assign(1, '仍被引用的文件已保留在回收站；另有 ' . count($failedPaths) . ' 个源文件物理删除失败，数据库记录也已保留', ['deleted_ids' => [], 'retained_ids' => array_keys($protectedIds)]);
                }
                return to_assign(0, '选中文件仍被引用，已保留在回收站，源文件和数据库记录均未删除', ['deleted_ids' => [], 'retained_ids' => array_keys($protectedIds)]);
            }
            return to_assign(1, '源文件物理删除失败，附件记录已保留');
        }
        try {
            $deletedRows = Db::name('File')->whereIn('id', $deletedIds)->where('delete_time', '>', 0)->delete();
        } catch (\Throwable $error) {
            return to_assign(1, '数据库记录删除失败，请刷新后重试');
        }
        if ($deletedRows === false) {
            return to_assign(1, '数据库记录删除失败，请刷新后重试');
        }

        $message = '彻底删除 ' . count($deletedIds) . ' 条文件记录';
        $deletedSourceCount = count($pathsToDelete) - count($failedPaths);
        if ($deletedSourceCount > 0) {
            $message .= '，同时删除源文件 ' . $deletedSourceCount . ' 个';
        }
        if ($sharedPaths) {
            $message .= '；' . count($protectedIds) . ' 个仍被引用的附件留在回收站，源文件和数据库记录均保留';
        }
        if ($failedPaths) {
            $message .= '；' . count($failedPaths) . ' 个源文件删除失败，对应记录已保留';
        }
        return to_assign(0, $message, ['deleted_ids' => $deletedIds]);
    }

    /** 查询文件是否仍被文档缩略图或会员头像引用。 */
    private function findReferencedImagePaths(array $files): ?array
    {
        $tokensByPath = [];
        foreach ($files as $file) {
            $relativePath = $this->normalizeStorageRelativePath((string) ($file['filename'] ?? ''));
            if ($relativePath === null) {
                continue;
            }

            $filepath = trim((string) ($file['filepath'] ?? ''));
            $filepathPath = parse_url($filepath, PHP_URL_PATH);
            $publicUrl = (string) Config::get('filesystem.disks.public.url', '');
            $publicPath = parse_url($publicUrl, PHP_URL_PATH);
            $tokens = array_filter([
                $filepath,
                is_string($filepathPath) ? $filepathPath : '',
                $relativePath,
                is_string($publicPath) ? rtrim($publicPath, '/') . '/' . $relativePath : '',
            ], static fn($value) => $value !== '');

            foreach (array_unique($tokens) as $token) {
                $tokensByPath[$relativePath][$token] = true;
            }
        }

        if (!$tokensByPath) {
            return [];
        }

        $tokenPaths = [];
        foreach ($tokensByPath as $relativePath => $tokens) {
            foreach (array_keys($tokens) as $token) {
                $tokenPaths[$token][$relativePath] = true;
            }
        }

        $referencedPaths = [];
        try {
            foreach (array_chunk(array_keys($tokenPaths), 500) as $tokenChunk) {
                foreach ([['Document', 'litpic'], ['User', 'headimgurl']] as [$table, $field]) {
                    $matches = Db::name($table)->whereIn($field, $tokenChunk)->column($field);
                    foreach ($matches as $matchedToken) {
                        foreach (array_keys($tokenPaths[(string) $matchedToken] ?? []) as $relativePath) {
                            $referencedPaths[$relativePath] = true;
                        }
                    }
                }
            }
        } catch (\Throwable $error) {
            return null;
        }

        return $referencedPaths;
    }

    /** 仅接受 public storage 根目录内的相对路径。 */
    private function normalizeStorageRelativePath(string $path): ?string
    {
        $stored = StorageService::parseFilename($path);
        return $stored['filename'] ?? null;
    }

    /** 判断文件真实路径是否仍位于 public storage 根目录下。 */
    private function isWithinStorageRoot(string $path, string $root): bool
    {
        $rootPrefix = rtrim($root, '/\\') . DIRECTORY_SEPARATOR;
        return DIRECTORY_SEPARATOR === '\\'
            ? strncasecmp($path, $rootPrefix, strlen($rootPrefix)) === 0
            : strncmp($path, $rootPrefix, strlen($rootPrefix)) === 0;
    }

    //获取分组
    public function get_group()
    {
		$list = Db::name('FileGroup')->where([['delete_time','=',0]])->select()->toArray();
		return to_assign(0, '',$list);
	}
    //添加&编辑
    public function add_group()
    {
        if (request()->isAjax()) {
			if (!request()->isPost()) {
				return to_assign(1, '请使用 POST 请求');
			}
			$param = get_params();
			$id = $param['id'] ?? 0;
			$title = $param['title'] ?? null;
			if (!is_scalar($id) || ($id !== '' && !ctype_digit((string) $id))
				|| !is_scalar($title) || trim((string) $title) === ''
				|| mb_strlen((string) $title, 'UTF-8') > 100
				|| preg_match('/[\\x00-\\x1F\\x7F]/u', (string) $title)) {
				return to_assign(1, '分组名称格式不正确');
			}
			$id = (int) $id;
			$title = trim((string) $title);
			if($title == '全部' || $title=='未分组'){
				return to_assign(1, '该分组名称已经存在');
			}
			if ($id > 0) {
				$count = Db::name('FileGroup')->where([['id','<>',$id],['delete_time','=',0],['title','=',$title]])->count();
                if ($count > 0) {
                    return to_assign(1, '该分组名称已经存在');
                }
				$res = Db::name('FileGroup')->where(['id' => $id])->where('delete_time', 0)->update(['title' => $title, 'update_time' => time()]);
				if($res!=false){
					safe_add_log('edit', $id, ['title' => $title]);
					return to_assign(0,'编辑成功',$id);
				}else{
					return to_assign(1,'操作失败');
				}
            } else {
				$count = Db::name('FileGroup')->where([['delete_time','=',0],['title','=',$title]])->count();
                if ($count > 0) {
                    return to_assign(1, '该分组名称已经存在');
                }
				$gid = Db::name('FileGroup')->insertGetId(['title' => $title]);
				if($gid!=false){
					safe_add_log('add', $gid, ['title' => $title]);
					return to_assign(0,'添加成功',$gid);
				}else{
					return to_assign(1,'操作失败');
				}                
            }
        }
    }

    //删除
    public function del_group()
    {
        if (request()->isDelete()) {
            $id = get_params('id');
            if (!is_scalar($id) || !ctype_digit((string) $id) || (int) $id < 1) {
                return to_assign(1, '分组编号无效');
            }
            $id = (int) $id;
            $group=Db::name('FileGroup')->where(['id' => $id])->where('delete_time', 0)->find();
            if (!$group) {
                return to_assign(1, '分组不存在');
            }
            if($group['is_system']==1){
                return to_assign(1, "该分组不能删除");
            }
            $count = Db::name('File')->where(["group_id" => $id])->count();
            if ($count > 0) {
                return to_assign(1, "该分组还存在文件，请去除文件或者转移文件后再删除");
            }
            if (Db::name('FileGroup')->where('id', $id)->where('delete_time', 0)->delete() > 0) {
                safe_add_log('delete', $id, []);
                return to_assign(0, "删除成功");
            } else {
                return to_assign(1, "删除失败");
            }
        } else {
            return to_assign(1, "错误的请求");
        }
    }
}
