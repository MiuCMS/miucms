<?php
/**
 * @copyright Copyright (c) 2026 米柚工作室
 * @license https://opensource.org/licenses/Apache-2.0
 * @link https://www.miucms.com
 */

declare (strict_types = 1);

namespace app\admin\controller;

use app\admin\BaseController;
use backup\Backup;
use think\facade\Session;
use think\facade\Db;
use think\facade\View;

class Database extends BaseController
{
	private function csrfToken(): string
	{
		$token = (string) Session::get('database_csrf_token', '');
		if (!preg_match('/^[a-f0-9]{64}$/', $token)) {
			$token = bin2hex(random_bytes(32));
			Session::set('database_csrf_token', $token);
		}
		return $token;
	}

	private function validMutationRequest(): bool
	{
		$token = get_params('_token');
		return request()->isPost() && is_string($token)
			&& hash_equals($this->csrfToken(), $token);
	}

	//数据表列表
	public function database()
    {
		if (request()->isAjax()) {
			// 数据信息
			$db = new Backup();
			$list = $db->dataList();
			// 计算总大小
			$total = 0;
			foreach ($list as $k => $v) {
				$total += $v['data_length'];
				$list[$k]['data_size'] = $v['data_length'];
				$list[$k]['data_length'] = format_bytes($v['data_length']);
			}
			// 提示信息
			$dataTips = '数据库中共有<strong> ' . count($list) . '</strong> 张表，共计 <strong>' . format_bytes($total) . '</strong>大小。';
			$data['data'] = $list;
			return table_assign(0, $dataTips, $data);
		}
		return view('', ['database_csrf' => $this->csrfToken()]);
    }	
	
	//备份数据
	public function backup()
	{
		if (!$this->validMutationRequest()) {
			return to_assign(1, '请求无效，请刷新页面后重试');
		}
		$db= new Backup();
		if (is_array(get_params('tables'))) {
			$tables=get_params('tables');
			if (!is_array($tables)) {
				return to_assign(1, '请选择要备份的数据表');
			}
			$tables = $this->filterTables($tables);
			if (!$tables) {
				return to_assign(1, '请选择有效的数据表');
			}
			$fileinfo  =$db->getFile();
           // dump($fileinfo);    die;
			//检查是否有正在执行的任务
			$lock = "{$fileinfo['filepath']}backup.lock";
			if(is_file($lock)){
			    return to_assign(2, '检测到有一个备份任务未完成');
            }
            // 检查备份目录是否可写
            if(!is_writeable($fileinfo['filepath'])){
				return to_assign(1, '备份目录不存在或不可写，请检查后重试！');
            }
			if (@file_put_contents($lock, time(), LOCK_EX) === false) {
				return to_assign(1, '无法创建备份任务锁，请检查目录权限');
			}
 
			//缓存锁文件
			Session::set('lock', $lock);
			//缓存备份文件信息
			Session::set('backup_file', $fileinfo['file']);
			//缓存要备份的表
			Session::set('backup_tables', $tables);
			//创建备份文件
			if(false !== $db->Backup_Init()){
				return to_assign(0, '初始化成功，开始备份...',['tab'=>['id' => 0, 'start' => 0,'table'=>$tables[0]]]);
			}else{
				@unlink($lock);
				Session::delete('lock');
				Session::delete('backup_tables');
				Session::delete('backup_file');
				return to_assign(1, '初始化失败，备份文件创建失败');
			}
		}else{
			$tables = Session::get('backup_tables');
			$file=Session::get('backup_file');
			$id=get_params('id'); 
			$start=get_params('start');
			if (!is_array($tables) || !$tables || !is_array($file)
				|| !is_scalar($id) || !ctype_digit((string) $id)
				|| !is_scalar($start) || !ctype_digit((string) $start)
				|| !isset($tables[(int) $id])) {
				return to_assign(1, '备份任务或进度参数已失效，请重新开始');
			}
			$id = (int) $id;
			$start = (int) $start;
			$start= $db->setFile($file)->backup($tables[$id], $start);
			if(false === $start){
				return to_assign(1, '备份出错');
			}else if(0 === $start){
				if(isset($tables[++$id])){
				   return to_assign(0, '备份完成',['tab'=>['id' => $id, 'start' => 0,'table'=>$tables[$id-1]]]);
				} else { //备份完成，清空缓存
				   @unlink((string) Session::get('lock', ''));
				   Session::delete('lock');
                   Session::delete('backup_tables');
                   Session::delete('backup_file');
				   safe_add_log('bak');
				   return to_assign(0, '备份完成',['tab'=>['start' => 'ok','table'=>$tables[$id-1]]]);
               }
           }
			return to_assign(0, '正在备份', ['tab' => ['id' => $id, 'start' => $start, 'table' => $tables[$id]]]);
		}
	} 

	/**
	 * 过滤要操作的数据表名
	 * 1、框架默认 action_bind_param=get，控制器方法的参数只绑定 GET 和路由参数，
	 *    前端 POST 提交的 tables 绑定不到（$tables 恒为 null），必须显式用 get_params 再取一次；
	 * 2、表名会被直接拼进 SQL，这里只放行字母、数字、下划线，避免注入
	 * @param  mixed $tables
	 * @return array
	 */
	private function filterTables($tables)
	{
		$available = array_column((new Backup())->dataList(), 'name');
		$list = [];
		foreach ((array) $tables as $table) {
			if (!is_scalar($table)) {
				continue;
			}
			$table = trim((string) $table);
			if ($table !== '' && preg_match('/^[A-Za-z0-9_]+$/', $table) && in_array($table, $available, true)) {
				$list[] = $table;
			}
		}
		return array_values(array_unique($list));
	}

	//优化表
	public function optimize($tables= null)
	{
		if (!$this->validMutationRequest() || !is_array(get_params('tables'))) {
			return to_assign(1, '请求无效或没有选择数据表，请刷新页面后重试');
		}
		$tables = $this->filterTables($tables ?: get_params('tables'));
		if (empty($tables)) {
			return to_assign(1, '请先选择要优化的数据表');
		}
        $db= new Backup();
        if($db->optimize($tables)){
			safe_add_log('optimize');
			return to_assign(0, '数据表优化完成');
        }else{
			return to_assign(1, '数据表优化出错请重试');
        }
	}
	
	//修复表
	public function repair($tables= null)
	{
		if (!$this->validMutationRequest() || !is_array(get_params('tables'))) {
			return to_assign(1, '请求无效或没有选择数据表，请刷新页面后重试');
		}
		$tables = $this->filterTables($tables ?: get_params('tables'));
		if (empty($tables)) {
			return to_assign(1, '请先选择要修复的数据表');
		}
		$db= new Backup();
		$result = $db->repair($tables);
		if (!$result) {
			return to_assign(1, '数据表修复出错请重试');
		}
		// 如实反馈：REPAIR TABLE 有返回行不代表真的修好了。
		// 只有 Msg_type=status 才是修复成功；InnoDB 会返回 note，表示该引擎不支持修复
		$done = $skipped = $failed = [];
		foreach ($result as $row) {
			$table = (string) ($row['Table'] ?? '');
			if (false !== ($pos = strrpos($table, '.'))) {
				$table = substr($table, $pos + 1);
			}
			$type = strtolower((string) ($row['Msg_type'] ?? ''));
			if ('status' === $type) {
				$done[] = $table;
			} elseif ('note' === $type) {
				$skipped[] = $table;
			} else {
				$failed[] = $table;
			}
		}
		if ($failed) {
			return to_assign(1, '数据表修复失败：' . implode('、', $failed));
		}
		if ($done) {
			safe_add_log('repair');
			$msg = '数据表修复完成：' . implode('、', $done);
			if ($skipped) {
				$msg .= '；' . implode('、', $skipped) . ' 的存储引擎不支持修复（已跳过）';
			}
			return to_assign(0, $msg);
		}
		return to_assign(1, implode('、', $skipped) . ' 的存储引擎不支持修复，无需修复');
	}
	
	
	//备份文件列表
	public function backuplist()
	{
		$db= new Backup();
		$list = $db->fileList();
		$fileinfo  =$db->getFile();
		$lock = "{$fileinfo['filepath']}backup.lock";
		$lock_time = 0;	
		if(is_file($lock)){
			$lock_time = file_get_contents($lock);
		}
		$listNew = [];
		$indx = 0;
        foreach ($list as $k => $v) {
            $listNew[$indx]['time'] = $k;
            $listNew[$indx]['timespan'] = $v['time'];
            $listNew[$indx]['data'] = $v;
            $indx++;
        }
        $list = $listNew;
        array_multisort(array_column($list, 'time'), SORT_DESC, $list);
		return view('', ['list' => $list, 'lock_time' => $lock_time, 'database_csrf' => $this->csrfToken()]);
	}
	
	//数据还原
	public function import($time = 0, $part = null, $start = null)
	{
		if (!$this->validMutationRequest()) {
			return to_assign(1, '请求无效，请刷新页面后重试');
		}
		$param = get_params();
		$time = $param['time'] ?? $time;
		$part = $param['part'] ?? $part;
		$start = $param['start'] ?? $start;
		if (!is_scalar($time) || !ctype_digit((string) $time) || (int) $time < 1) {
			return to_assign(1, '备份时间参数无效');
		}
		$time = (int) $time;
		$db= new Backup();
		if(is_null($part) && is_null($start)){
			try {
				$list  = $db->getFile('timeverif',$time);
			} catch (\Throwable $e) {
				return to_assign(1, '备份文件可能已经损坏，请检查');
			}
			if(is_array($list)){
				Session::set('backup_list', $list);
				Session::set('backup_import_time', $time);
				return to_assign(0, '初始化完成，开始还原...',array('part' => 1, 'start' => 0,'time' => $time));
			}else{
				return to_assign(1, '备份文件可能已经损坏,请检查');
			}
		}else if(is_scalar($part) && ctype_digit((string) $part) && is_scalar($start) && ctype_digit((string) $start)){
            $list=Session::get('backup_list');
			$part =(int)$part;
			$start =(int)$start;
			if (!is_array($list) || (int) Session::get('backup_import_time', 0) !== $time
				|| $part < 1 || $part > count($list) || $start < 0) {
				return to_assign(1, '还原任务或进度参数已失效，请重新开始');
			}
			$start= $db->import($start,$time,$part);
            if(false===$start){
				return to_assign(1, '还原数据出错,请重试');
            }elseif(0 === $start){
                if(isset($list[++$part])){
                    $data = array('part' => $part, 'start' => 0,'time' => $time);
					return to_assign(0, "正在还原...卷{$part}，请勿关闭当前页面",$data);
                } else {
                    Session::delete('backup_list');
					Session::delete('backup_import_time');
					return to_assign(0, '还原数据成功');
                }
            }else{
                $data = array('part' => $part, 'start' => $start[0],'time' => $time);
                if($start[1]){
                    $rate = floor(100 * ($start[0] / $start[1]));
					return to_assign(0, "正在还原...卷{$part} ({$rate}%)，请勿关闭当前页面",$data);
                } else {
                    $data['gz'] = 1;
                    return to_assign(0, "正在还原...卷{$part}，请勿关闭当前页面",$data);
                }
            }             
		}else{
		   return to_assign(1, "参数错误");
		}       
	}
	/**
    * 删除备份文件
    */
	public function del($time = 0,$lock=0){
		if (!$this->validMutationRequest()) {
			return to_assign(1, '请求无效，请刷新页面后重试');
		}
		$lockParam = get_params('lock', $lock);
		$lock = is_scalar($lockParam) && (string) $lockParam === '1';
		$timeParam = get_params('time', $time);
		$removedLock = false;
		$db= new Backup();
		if($lock){
			$fileinfo  =$db->getFile();
			$lockPath = "{$fileinfo['filepath']}backup.lock";
			if (is_file($lockPath)) {
				$lockTime = trim((string) @file_get_contents($lockPath));
				$removedLock = @unlink($lockPath);
				if (!$removedLock) {
					return to_assign(1, '无法清除未完成的备份任务锁，请检查目录权限');
				}
				if (ctype_digit($lockTime) && (int) $lockTime > 0) {
					$timeParam = $lockTime;
				}
			}
		}	
		if (!is_scalar($timeParam) || !ctype_digit((string) $timeParam) || (int) $timeParam < 1) {
			if ($lock && $removedLock) {
				return to_assign(0, '已清除未完成的备份任务');
			}
			return to_assign(1, '备份时间参数无效');
		}
		try {
			$deleted = $db->delFile((int) $timeParam);
		} catch (\Throwable $e) {
			$deleted = false;
		}
		if($deleted || ($lock && $removedLock)){
			safe_add_log('delete');
			return to_assign(0, '删除成功');
		}else{
			return to_assign(1, '删除失败，请检查权限');
		}
	}
    /**
    * 下载备份文件
    */
	public function downfile($time = 0,$part=0){
		$time = get_params('time', $time);
		$part = get_params('part', $part);
		if (!is_scalar($time) || !ctype_digit((string) $time) || (int) $time < 1
			|| !is_scalar($part) || !ctype_digit((string) $part) || (int) $part < 1) {
			abort(404);
		}
		$db= new Backup();
		$files = $db->getFile('time', (int) $time);
		if (!isset($files[(int) $part - 1])) {
			abort(404);
		}
		safe_add_log('down');
		$db->downloadFile((int) $time, (int) $part - 1);
	}
	
}
