<?php
/**
 * @copyright Copyright (c) 2026 米柚工作室
 * @license https://opensource.org/licenses/Apache-2.0
 * @link https://www.miucms.com
 */

declare (strict_types = 1);

namespace app\admin\controller;

use app\admin\BaseController;
use app\admin\model\Admin as AdminList;
use app\admin\validate\AdminCheck;
use think\exception\ValidateException;
use think\facade\Db;
use think\facade\View;

class Admin extends BaseController
{
    public function index()
    {
        if (request()->isAjax()) {
            $param = get_params();
            $where = array();
            if (!empty($param['keywords'])) {
                $where[] = ['id|username|nickname|desc|mobile', 'like', '%' . $param['keywords'] . '%'];
            }
            $where[] = ['status','>=',0];
            $rows = empty($param['limit']) ? get_config('app.page_size') : $param['limit'];
            $admin = AdminList::field('id,username,nickname,thumb,theme,mobile,email,desc,create_time,update_time,last_login_time,last_login_ip,login_num,status')->where($where)
                ->order('create_time asc')
                ->paginate(['list_rows'=> $rows])
                ->each(function ($item, $key) {
                    $groupId = Db::name('AdminGroupAccess')->where(['uid' => $item->id])->column('group_id');
                    $groupName = Db::name('AdminGroup')->where('id', 'in', $groupId)->column('title');
                    $item->groupName = implode(',', $groupName);
                    $item->last_login_time = empty($item->last_login_time) ? '-' : date('Y-m-d H:i', $item->last_login_time);
                });
            return table_assign(0, '', $admin);
        } else {
            return view();
        }
    }

    //添加
    public function add()
    {	
		if (request()->isAjax()) {
			if (!request()->isPost()) {
				return to_assign(1, '请使用 POST 请求');
			}
			$param = get_params();
			$idValue = $param['id'] ?? 0;
			if (!is_scalar($idValue) || ($idValue !== '' && !ctype_digit((string) $idValue))) {
				return to_assign(1, '管理员编号无效');
			}
			$param['id'] = (int) $idValue;
			if (!is_array($param['group_id'] ?? null) || !$param['group_id']) {
				return to_assign(1, '至少要选择一个用户角色');
			}
			$groupIds = [];
			foreach ($param['group_id'] as $groupId) {
				if (!is_scalar($groupId) || !ctype_digit((string) $groupId) || (int) $groupId < 1) {
					return to_assign(1, '用户角色参数无效');
				}
				$groupIds[] = (int) $groupId;
			}
			$groupIds = array_values(array_unique($groupIds));
			if (in_array(1, $groupIds, true) && (int) get_login_admin('id') !== 1) {
				return to_assign(1, '你没有权限创建系统所有者');
			}
			$existingGroupIds = array_map('intval', Db::name('AdminGroup')->whereIn('id', $groupIds)->column('id'));
			if (count($existingGroupIds) !== count($groupIds)) {
				return to_assign(1, '所选用户角色不存在');
			}
			$param['group_id'] = $groupIds;
            if (!empty($param['id']) && $param['id'] > 0) {
                try {
                    validate(AdminCheck::class)->scene('edit')->check($param);
                } catch (ValidateException $e) {
                    // 验证失败 输出错误信息
                    return to_assign(1, $e->getError());
                }
                if (!empty($param['edit_pwd'])) {
                    //重置密码
                    if (empty($param['edit_pwd_confirm']) or $param['edit_pwd_confirm'] !== $param['edit_pwd']) {
                        return to_assign(1, '两次密码不一致');
                    }
                    $param['salt'] = '';
                    $param['pwd'] = hash_user_password($param['edit_pwd']);
                }

                // 启动事务
                Db::startTrans();
                try {
                    Db::name('Admin')->where(['id' => $param['id']])->strict(false)->field(true)->update($param);
                    Db::name('AdminGroupAccess')->where(['uid' => $param['id']])->delete();
                    foreach ($param['group_id'] as $k => $v) {
                        //为了系统安全，只有系统所有者才可创建id为1的管理员分组
                        $data[$k] = [
                            'uid' => $param['id'],
                            'group_id' => $v,
                        ];
                    }
                    Db::name('AdminGroupAccess')->strict(false)->field(true)->insertAll($data);
                    safe_add_log('edit', $param['id'], $param);
                    //清除菜单\权限缓存
                    clear_cache('adminMenu');
                    clear_cache('adminRules');
                    // 提交事务
                    Db::commit();
                } catch (\Exception $e) {
                    // 回滚事务
                    Db::rollback();
                    return to_assign(1, '提交失败:' . $e->getMessage());
                }
            } else {
                unset($param['id']);
                try {
                    validate(AdminCheck::class)->scene('add')->check($param);
                } catch (ValidateException $e) {
                    // 验证失败 输出错误信息
                    return to_assign(1, $e->getError());
                }
                $param['salt'] = '';
                $param['pwd'] = hash_user_password($param['pwd']);
                // 启动事务
                Db::startTrans();
                try {
                    $uid = Db::name('Admin')->strict(false)->field(true)->insertGetId($param);
                    foreach ($param['group_id'] as $k => $v) {
                        //为了系统安全，只有系统所有者才可创建id为1的管理员分组
                        $data[$k] = [
                            'uid' => $uid,
                            'group_id' => $v,
                        ];
                    }
                    Db::name('AdminGroupAccess')->strict(false)->field(true)->insertAll($data);
                    safe_add_log('add', $uid, $param);
                    // 提交事务
                    Db::commit();
                } catch (\Exception $e) {
                    // 回滚事务
                    Db::rollback();
                    return to_assign(1, '提交失败:' . $e->getMessage());
                }
            }
            return to_assign();
		}
		else{
			$id = empty(get_params('id')) ? 0 : get_params('id');
			if ($id > 0) {
				$admin = get_admin(get_params('id'));
				unset($admin['pwd'], $admin['salt']);
				View::assign('admin', $admin);
			}
			View::assign('id', $id);
			return view();
		}
    }

    //删除
    public function delete()
    {
        if (!request()->isDelete()) {
            return to_assign(1, '请使用 DELETE 请求');
        }
        $id = get_params('id');
        if (!is_scalar($id) || !ctype_digit((string) $id) || (int) $id < 1) {
            return to_assign(1, '管理员编号无效');
        }
        $id = (int) $id;
        if ($id === 1) {
            return to_assign(0, "超级管理员，不能删除");
        }
        $data = ['status' => -1, 'update_time' => time()];
        if (Db::name('Admin')->where('id', $id)->where('status', '>=', 0)->update($data) > 0) {
            clear_cache('adminMenu');
            clear_cache('adminRules');
            safe_add_log('delete', $id);
            return to_assign(0, "删除管理员成功");
        } else {
            return to_assign(1, "删除失败");
        }
    }

    //管理员操作日志
    public function log()
    {
        if (request()->isAjax()) {
            $param = get_params();
            $where = array();
            if (!empty($param['keywords'])) {
                $where[] = ['nickname|rule_menu|param_id', 'like', '%' . $param['keywords'] . '%'];
            }
            if (!empty($param['title_cate'])) {
                $where['title'] = $param['title_cate'];
            }
            if (!empty($param['rule_menu'])) {
                $where['rule_menu'] = $param['rule_menu'];
            }
            $rows = empty($param['limit']) ? get_config('app.page_size') : $param['limit'];
            $content = DB::name('AdminLog')
                ->field("id,uid,nickname,title,content,rule_menu,ip,param_id,param,FROM_UNIXTIME(create_time,'%Y-%m-%d %H:%i:%s') create_time")
                ->order('create_time desc')
                ->where($where)
                ->paginate(['list_rows'=> $rows]);
            $content->toArray();
            foreach ($content as $k => $v) {
                $data = $v;
                $param_array = json_decode($v['param'], true);
				if(is_array($param_array)){
					$param_value = '';
					foreach ($param_array as $key => $value) {
						if (is_array($value)) {
							$value = implode(',', $value);
						}
						$param_value .= $key . ':' . $value . '&nbsp;&nbsp;|&nbsp;&nbsp;';
					}
					$data['param'] = $param_value;
				}
				else{
					$data['param'] = $param_array;
				}
                $content->offsetSet($k, $data);
            }
            return table_assign(0, '', $content);
        } else {
            return view();
        }
    }
}
