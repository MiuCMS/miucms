<?php
/**
 * @copyright Copyright (c) 2026 米柚工作室
 * @license https://opensource.org/licenses/Apache-2.0
 * @link https://www.miucms.com
 */

declare (strict_types = 1);

namespace app\admin\controller;

use app\admin\BaseController;
use app\admin\model\Channel as ChannelModel;
use app\admin\model\ChannelField;
use think\facade\Db;
use think\facade\View;

class Channel extends BaseController
{
    public function index()
    {
        if (request()->isAjax()) {
            //$param = get_params();
            $where = ['is_delete' => 0];
            //统计模型下的字段数量：channel_field.channel_id = channel.id
            $fieldCount = '(SELECT COUNT(id) FROM `' . env('mysql.prefix') . 'channel_field` WHERE `channel_id` = `' . env('mysql.prefix') . 'channel`.`id`) AS field_count';
            $channel = ChannelModel::where($where)->field('*, ' . $fieldCount)->order('add_time asc')->select();
            return to_assign(0, '', $channel);
        } else {
            return view();
        }
    }

    //添加
    public function add()
    {
        $param = get_params();
        //dump($param);die;
        if (request()->isAjax()) {
            if (!request()->isPost()) {
                return to_assign(1, '仅支持 POST 请求');
            }
            if (!empty($param['id']) && $param['id'] > 0) {

                $param['update_time'] = time();
                $check = $this->check_nid($param);
                if ($check !== true) {
                    return $check;
                }
                $param['table']=$param['nid'];
                //创建附加表
                $result = $this->create_table($param,'update');
                if ($result === false) {
                    return to_assign(1, '模型数据表标识无效');
                }
                if ($result) {
                    safe_add_log('edit', $param['id'], $param);
                }

                clear_cache('homeChannel');
                //模型/字段一变，前台 {miu:list} 的文档列表缓存也一并清掉（宁可多清，不可漏清）
                clear_cache('Document');
                return to_assign();
            } else {
                $check = $this->check_nid($param);
                if ($check !== true) {
                    return $check;
                }
                unset($param['id']);
                $param['nid']=strtolower($param['nid']);
                $param['add_time'] = time();
                $param['ntitle'] = $param['title'];
                $param['table'] = $param['nid'];
                $param['ctl_name'] = ucfirst($param['nid']);
                //创建附加表
                $result = $this->create_table($param,'create');
                if ($result === false) {
                    return to_assign(1, '模型数据表标识无效');
                }
                if ($result) {
                    safe_add_log('add', $result, $param);
                }

                // 删除banner缓存
                clear_cache('homeChannel');
                //模型/字段一变，前台 {miu:list} 的文档列表缓存也一并清掉（宁可多清，不可漏清）
                clear_cache('Document');
                return to_assign();
            }
        }
		else{
			$id = isset($param['id']) ? $param['id'] : 0;
			if ($id > 0) {
				$Channel = Db::name('Channel')->where(['id' => $id])->find();
				View::assign('channel', $Channel);
			}
			View::assign('id', $id);
			return view();
		}
    }

    //删除
    public function delete()
    {
        if (!request()->isDelete()) {
            return to_assign(1, '仅支持 DELETE 请求');
        }
        $id = get_params("id");
        if (!is_scalar($id) || !ctype_digit((string) $id) || (int) $id < 1) {
            return to_assign(1, '参数错误');
        }
        $channel=Db::name('channel')->find($id);
        if (!$channel) {
            return to_assign(1, '模型不存在');
        }
        if($channel['is_system']==1 || in_array($channel['table'],['article','image','product','download','recruit','special','single'])){
            return to_assign(1, "系统模型不允许删除");
        }
        //附加表名取自 channel.table 字段，表名不合法则置空跳过，避免误删其他表
        $prefix = (string) env('mysql.prefix');
        $tableName = preg_match('/\A[a-z][a-z0-9_]{2,15}\z/', (string) $channel['table'])
            && preg_match('/\A[A-Za-z0-9_]*\z/', $prefix) ? $prefix . $channel['table'] : '';
        //删除事务处理，同时删除模型和模型字段表数据
        $result=Db::transaction(function () use($id,$tableName) {
            //有字段数据才删除，避免无数据时执行空删除
            if(Db::name('channelField')->where('channel_id',$id)->count() > 0){
                Db::name('channelField')->where('channel_id',$id)->delete();
            }
            $res=Db::name('channel')->where('id',$id)->delete();
            //同时删除该模型的附加表
            if($tableName){
                Db::execute("DROP TABLE IF EXISTS `{$tableName}`");
            }
            return $res;
        });

        if ($result) {
            safe_add_log('delete', $id);
            clear_cache('homeChannel');
            //删模型会连带删掉该模型的附加表，前台 {miu:list} 的文档列表缓存一并清掉
            clear_cache('Document');
            return to_assign(0, "删除成功");
        } else {
            return to_assign(1, "删除失败");
        }
    }

    //管理幻灯片
    public function field()
    {
        $param = get_params();
        $id = isset($param['id']) ? $param['id'] : 0;
        if (request()->isAjax()) {
            $where = array();
            $where[] = ['f.channel_id', '=', $param['channel_id']];
            $where[] = ['f.is_system', '<>', 1];#不显示系统字段
            $ChannelFieldList = ChannelField::where($where)
                ->alias('f')
                ->field('f.*,ft.title ftitle')
                ->join('field_type ft', 'ft.name = f.type')
                //->whereNot('f.is_system','!=', 1)
                ->order('f.sort asc, f.id asc')
                ->select();
            return to_assign(0, '', $ChannelFieldList);
        } else {
            return view('', [
                'channel_id' => $param['channel_id'],
                'id' => $id,
                'channel_name' => ChannelModel::where(['id' => $param['channel_id']])->value('title'),
            ]);
        }
    }

    //添加幻灯片
    public function field_add()
    {
        $param = get_params();
        if (request()->isAjax()) {
			if (!request()->isPost()) {
				return to_assign(1, '仅支持 POST 请求');
			}
			if (isset($param['id']) && !is_scalar($param['id'])) {
				return to_assign(1, '字段 ID 格式不正确');
			}
			foreach (['name', 'title', 'type', 'channel_id'] as $key) {
				if (!isset($param[$key]) || !is_scalar($param[$key])) {
					return to_assign(1, '字段信息不完整');
				}
			}
			$param['name'] = trim((string) $param['name']);
			$param['title'] = trim((string) $param['title']);
			$param['type'] = trim((string) $param['type']);
			$param['channel_id'] = (int) $param['channel_id'];
			if ($param['channel_id'] < 1 || $param['title'] === '' || mb_strlen($param['title'], 'UTF-8') > 100 || !is_scalar($param['default_value'] ?? '')) {
				return to_assign(1, '字段名称或默认值格式不正确');
			}
            if (!empty($param['id']) && $param['id'] > 0) {
				$fieldId = (int) $param['id'];
				$field = ChannelField::where('id', $fieldId)->find();
				if (!$field) {
					return to_assign(1, '字段不存在');
				}
				// 字段所属模型以数据库记录为准，不能由请求参数改绑到其他模型。
				$param['id'] = $fieldId;
				$param['channel_id'] = (int) $field['channel_id'];
				$check = $this->check_field_name($param);
				if ($check !== true) {
					return $check;
				}
                if(Db::name('FieldType')->where('name',$param['type'])->value('is_require_value')==1 && !$param['default_value']){
                    return to_assign(1, "字段类型为下拉框、单选项、多选项时，默认值为必填项");
                }
                $param['update_time'] = time();
				$typeInfo = Db::name('FieldType')->where(['name'=>$param['type']])->find();
				if (!$typeInfo || !preg_match('/\A(?:varchar\(\d+\)|tinyint\(\d+\)|int\(\d+\)|float\(\d+,\d+\)|text|mediumtext|longtext)\z/i', (string) $typeInfo['define'])) {
					return to_assign(1, '字段类型无效');
				}
				$param['define'] = $typeInfo['define'];
				$channel = Db::name('channel')->where(['id'=>$param['channel_id']])->find();
				$prefix = (string) env('mysql.prefix');
				$tableName = $prefix . (string) ($channel['table'] ?? '');
				if (!$channel || !preg_match('/\A[A-Za-z0-9_]*\z/', $prefix)
					|| !preg_match('/\A[a-z][a-z0-9_]{2,15}\z/', (string) $channel['table'])
					|| !preg_match('/\A[a-z][a-z0-9_]{2,15}\z/', (string) $field['name'])) {
					return to_assign(1, '模型或字段标识无效');
				}

				$result=Db::transaction(function () use($param, $field, $tableName){
                    // 获取旧字段名
					$oldName = (string) $field['name'];
					$res = ChannelField::where(['id' => $param['id']])->strict(false)->field(true)->update($param);
                    // 检查旧字段是否存在于数据表，存在才 CHANGE
                    if($this->field_exists($tableName,$oldName)){
                        $default = "";
                        $define = $param['define'];  // 默认使用字段类型定义

                        if($param['type'] == 'float' || $param['type'] == 'decimal'){
                            $default = "DEFAULT 0.00";
                        }
                        if($param['type'] == 'int'){
                            $default = "DEFAULT 0";
                        }
                        //修改附加表字段
						$title = Db::connect()->getPdo()->quote($param['title']);
						$sql="ALTER TABLE `{$tableName}` CHANGE COLUMN `{$oldName}` `{$param['name']}` {$define} {$default} COMMENT {$title}";
                        Db::execute($sql);
                    }
                    return $res;
                });

                if ($result) {
                    safe_add_log('edit', $param['id'], $param);
                }
                clear_cache('homeChannel');
                //模型/字段一变，前台 {miu:list} 的文档列表缓存也一并清掉（宁可多清，不可漏清）
                clear_cache('Document');
                return to_assign();
            } else {
                unset($param['id']);

                // 同模型下检查标题重复
                if(Db::name('ChannelField')->where('title',$param['title'])->where('channel_id',$param['channel_id'])->find()){
                    return to_assign(1, "字段名称已存在");
                }
                if(Db::name('FieldType')->where('name',$param['type'])->value('is_require_value')==1 && !$param['default_value']){
                    return to_assign(1, "字段类型为下拉框、单选项、多选项时，默认值为必填项");
                }
				$typeInfo = Db::name('FieldType')->where(['name'=>$param['type']])->find();
				if (!$typeInfo || !preg_match('/\A(?:varchar\(\d+\)|tinyint\(\d+\)|int\(\d+\)|float\(\d+,\d+\)|text|mediumtext|longtext)\z/i', (string) $typeInfo['define'])) {
					return to_assign(1, '字段类型无效');
				}
				$param['define'] = $typeInfo['define'];
				$channel = Db::name('channel')->where(['id' => $param['channel_id']])->find();
				$prefix = (string) env('mysql.prefix');
				$tableName = $prefix . (string) ($channel['table'] ?? '');
				if (!$channel || !preg_match('/\A[A-Za-z0-9_]*\z/', $prefix)
					|| !preg_match('/\A[a-z][a-z0-9_]{2,15}\z/', (string) $channel['table'])) {
					return to_assign(1, '模型不存在或数据表标识无效');
				}

                $check = $this->check_field_name($param);
                if ($check !== true) {
                    return $check;
                }

                $param['add_time'] = time();
                $param['maxlength'] = 250;
				$result=Db::transaction(function () use($param, $tableName){
                    $res = ChannelField::strict(false)->field(true)->insertGetId($param);

                    // 字段不存在才 ADD
                    if($this->field_exists($tableName,$param['name'])===false){
                        $default="";
                        $define=$param['define'];   // ← 修复：不再为空

                        if($param['type'] == 'float' || $param['type'] == 'decimal'){
                            $default = "DEFAULT 0.00";
                        }
                        if($param['type'] == 'int'){
                            $default = "DEFAULT 0";
                        }
                        $title = Db::connect()->getPdo()->quote($param['title']);
                        $sql="ALTER TABLE `{$tableName}` ADD COLUMN `{$param['name']}` {$define} {$default} COMMENT {$title}";
                        Db::execute($sql);
                    }
                    return $res;
                });

                if ($result) {
                    safe_add_log('add', $result, $param);
                }
                clear_cache('homeChannel');
                //模型/字段一变，前台 {miu:list} 的文档列表缓存也一并清掉（宁可多清，不可漏清）
                clear_cache('Document');
                return to_assign();
            }
        }
		else{
			$id = isset($param['id']) ? $param['id'] : 0;
            $channel_id = isset($param['channel_id']) ? $param['channel_id'] : 0;
			if ($id > 0) {
				$field = Db::name('ChannelField')->where(['id' => $id])->find();
				View::assign('field', $field);
                $channel_id = $field['channel_id'];
			}

			View::assign('id', $id);
			View::assign('channel_id', $channel_id);
			View::assign('field_types', get_field_type());
			return view();
		}
    }

    //删除幻灯片
    public function field_delete()
    {
        if (!request()->isDelete()) {
            return to_assign(1, '仅支持 DELETE 请求');
        }
        $id = get_params("id");
        if (!is_scalar($id) || !ctype_digit((string) $id) || (int) $id < 1) {
            return to_assign(1, "参数错误");
        }
        $field = ChannelField::where(['id' => $id])->find();
        if (!$field) {
            return to_assign(1, '字段不存在');
        }
        if($field['is_system']==1){
            return to_assign(1, "系统字段不允许删除！");
        }
        if($field['is_important']==1){
            return to_assign(1, "系统字段不允许删除！");
        }

		$channel = ChannelModel::where('id', (int) $field['channel_id'])->find();
		$prefix = (string) env('mysql.prefix');
		$fieldName = (string) $field['name'];
		$tableName = $prefix . (string) ($channel['table'] ?? '');
		if (!$channel || !preg_match('/\A[A-Za-z0-9_]*\z/', $prefix)
			|| !preg_match('/\A[a-z][a-z0-9_]{2,15}\z/', (string) $channel['table'])
			|| !preg_match('/\A[a-z][a-z0-9_]{2,15}\z/', $fieldName)) {
			return to_assign(1, '模型或字段标识无效');
		}

		$result=Db::transaction(function () use($id,$field,$tableName,$fieldName){
            $res = ChannelField::where(['id' => $id])->delete();
            //删除附加表的对应字段，附加表是$tableName
			if($this->field_exists($tableName,$fieldName)===true) {
				$sql = "ALTER TABLE `{$tableName}` DROP COLUMN `{$fieldName}`;";
                Db::execute($sql);
            }
            return $res;
        });
        if ($result) {
            safe_add_log('delete', $id);
            clear_cache('homeChannel');
            //删字段会改动附加表结构，前台 {miu:list} 的文档列表缓存一并清掉
            clear_cache('Document');
            return to_assign(0, "删除成功");
        } else {
            return to_assign(1, "删除失败");
        }
    }
    //修改字段
    public function edit_field()
    {
        if (!request()->isPost()) {
            return to_assign(1, '仅支持 POST 请求');
        }
        $param = get_params();
        if (request()->isAjax()) {
			$field = $param['field'] ?? '';
			$value = $param['value'] ?? null;
			$id = $param['id'] ?? null;
			if (!in_array($field, ['sort', 'status'], true) || !is_scalar($value)
				|| !is_scalar($id) || !ctype_digit((string) $id) || (int) $id < 1
				|| ($field === 'status' && !in_array((string) $value, ['0', '1'], true))
				|| ($field === 'sort' && !preg_match('/^-?\d{1,6}$/', (string) $value))) {
				return to_assign(1, '参数错误');
			}
            $data[$field]=$value;
			$data['id']=(int) $id;
            $res=Db::name('Channel')->update($data);
            if ($res) {
                safe_add_log('edit', $param['id'], $data);
                clear_cache('homeChannel');
                //模型/字段一变，前台 {miu:list} 的文档列表缓存也一并清掉（宁可多清，不可漏清）
                clear_cache('Document');
                return to_assign(0, "操作成功");
            }else{
                return to_assign(1, "操作失败");
            }
        }
    }

    private function check_nid($param)
    {
        $nid = $param['nid'] ?? null;
        $pattern = '/\A[a-z][a-z0-9_]{2,15}\z/';
        if (!is_string($nid) || !preg_match($pattern, $nid)) {
            return to_assign(1, "标识不可用：字母或数字，以字母开头，且长度为3到16位");
        }
        $notAllowField = ['article', 'product', 'images', 'download', 'single', 'special', 'guestbook', 'recruit', 'admin', 'modle', 'mysql', 'php', 'redis'];
        if (in_array($nid, $notAllowField) && empty($param['id'])) {
            //return to_assign(1, "标识不可用：系统保留字段");
        }
        $res = ChannelModel::where(['nid' => $nid, 'status' => 1])->where('id', '<>', (int) ($param['id'] ?? 0))->find();
        if ($res) {
            return to_assign(1, "标识不可用：已存在");
        } else {
            return true;
        }
    }
    private function check_field_name($param){
        $pattern = '/\A[a-z][a-z0-9_]{2,15}\z/';
        if(!is_string($param['name'] ?? null) || !preg_match($pattern, $param['name'])) {
            return to_assign(1, "标识不可用：字母或数字，以字母开头，且长度为3到16位");
        }
        $notAllowField=['article','product','images','download','single','special','guestbook','recruit','id','nav_id','stype_id','channel_id','title','short_title','litpic','desc','title_color','is_original','is_head','is_special','is_b','is_top','is_recommend','is_jump','jumplinks','is_litpic','from','from_url','author','click','arcrank','seo_title','seo_keywords','seo_description','status','sort','lang','admin_id','arc_level_id','restric_type','is_del','appraise','collection','tag_ids','add_time','update_time','delete_time','release_time','content','doc_id','md_content'];
        if(in_array($param['name'],$notAllowField) && $param['name']){
            return to_assign(1, "标识不可用：系统保留字段");
        }
        $res=ChannelField::where(['name'=> $param['name'],'channel_id'=> (int) $param['channel_id']])
            ->where('id', '<>', (int) ($param['id'] ?? 0))->find();
        if($res){
            return to_assign(1, "标识不可用：已存在");
        }else{
            return true;
        }

    }
    //创建数据表
    private function create_table($param,$type='create'){
        $prefix = (string) env('mysql.prefix');
        $table = $param['table'] ?? null;
        if (!is_string($table) || !preg_match('/\A[a-z][a-z0-9_]{2,15}\z/', $table)
            || !preg_match('/\A[A-Za-z0-9_]*\z/', $prefix)) {
            return false;
        }
        $ftableName = $prefix . $table;

        return Db::transaction(function () use($param,$type,$prefix,$table,$ftableName){
            try {
                $sql="";
                if($type=='create'){
                    $res = ChannelModel::strict(false)->field(true)->insertGetId($param);
                    //模型添加成功，自动创建附加表
                    $sql .= "CREATE TABLE IF NOT EXISTS `".$ftableName."` (";
                    $sql .= "`id` int(11) unsigned NOT NULL AUTO_INCREMENT,";
                    $sql .= "`doc_id` int(11) NOT NULL DEFAULT '0' COMMENT '所属文档id',";
                    $sql .= "`content` text COMMENT '内容',";
                    $sql .= "`md_content` text COMMENT 'markdown编辑器内容',";
                    $sql .= "`add_time` int(11) unsigned DEFAULT '0' COMMENT '创建时间',";
                    $sql .= "`update_time` int(11) unsigned DEFAULT '0' COMMENT '更新时间',";
                    $sql .= "`delete_time` int(11) unsigned DEFAULT '0' COMMENT '删除时间',";
                    $sql .= "PRIMARY KEY (`id`),";
                    $sql .= "KEY `doc_id` (`doc_id`) USING BTREE";
                    $sql .= ") ENGINE=InnoDB AUTO_INCREMENT=6 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='{$table}模型附加表,cms创建';";
                }else{
                    $oldTable = (string) ChannelModel::where('id', (int) ($param['id'] ?? 0))->value('table');
                    if (!preg_match('/\A[a-z][a-z0-9_]{2,15}\z/', $oldTable)) {
                        return false;
                    }
                    $res = ChannelModel::where('id', $param['id'])->strict(false)->field(true)->update($param);
                    //如果修改了表名，需要重命名附加表
                    if ($oldTable !== $table) {
                        $oldTableName = $prefix . $oldTable;
                        $sql .= "RENAME TABLE `{$oldTableName}` TO `{$ftableName}`;";
                    }
                }

                if(!empty($sql)){
                    Db::execute($sql);
                }
                return $res;
            } catch (\Exception $e) {
                throw new \Exception($e->getMessage());
            }
        });
    }
    //检查数据表字段是否存在
    private function field_exists(string $table, string $field): bool
    {
		if (!preg_match('/\A[A-Za-z0-9_]+\z/', $table) || !preg_match('/\A[A-Za-z0-9_]+\z/', $field)) {
			return false;
		}
        $ftableName = $table;
        $list = Db::query("SHOW COLUMNS FROM `{$ftableName}`");
        return in_array($field, array_column($list, 'Field'));
    }
}
