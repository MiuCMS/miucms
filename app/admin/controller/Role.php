<?php
/**
 * @copyright Copyright (c) 2026 米柚工作室
 * @license https://opensource.org/licenses/Apache-2.0
 * @link https://www.miucms.com
 */

declare (strict_types = 1);

namespace app\admin\controller;

use app\admin\BaseController;
use app\admin\model\AdminGroup;
use app\admin\validate\GroupCheck;
use think\exception\ValidateException;
use think\facade\Db;
use think\facade\View;

class Role extends BaseController
{
    public function index()
    {
        if (request()->isAjax()) {
            $param = get_params();
            $where = array();
            if (!empty($param['keywords'])) {
                $where[] = ['id|title|desc', 'like', '%' . $param['keywords'] . '%'];
            }
            $rows = empty($param['limit']) ? get_config('app.page_size') : $param['limit'];
            $group = AdminGroup::where($where)
                ->order('create_time asc')
                ->paginate(['list_rows'=> $rows]);
            return table_assign(0, '', $group);
        } else {
            return view();
        }
    }

    //添加&编辑
    public function add()
    {
        $param = get_params();
        if (request()->isAjax()) {
            $ruleData = isset($param['rule']) ? $param['rule'] : 0;
            $param['rules'] = implode(',', $ruleData);
            if (!empty($param['id']) && $param['id'] > 0) {
                try {
                    validate(GroupCheck::class)->scene('edit')->check($param);
                } catch (ValidateException $e) {
                    // 验证失败 输出错误信息
                    return to_assign(1, $e->getError());
                }
                //为了系统安全id为1的系统所有者管理组不允许修改
                if ($param['id'] == 1) {
                    return to_assign(1, '为了系统安全,该管理组不允许修改');
                }
                Db::name('AdminGroup')->where(['id' => $param['id']])->strict(false)->field(true)->update($param);
                safe_add_log('edit', $param['id'], $param);
            } else {
                try {
                    validate(GroupCheck::class)->scene('add')->check($param);
                } catch (ValidateException $e) {
                    // 验证失败 输出错误信息
                    return to_assign(1, $e->getError());
                }
                $gid = Db::name('AdminGroup')->strict(false)->field(true)->insertGetId($param);
                safe_add_log('add', $gid, $param);
            }
            //组的权限变了，鉴权（adminRules）和左侧菜单（adminMenu）两份缓存都要清：
            //adminRules 里的 RulesSrc{uid} 是「按用户所属组的 rules 算出来的有权限 src 列表」，
            //改了组的 rules 不清它，被收回去的权限最长要 10 小时才生效
            clear_cache('adminMenu');
            clear_cache('adminRules');
            return to_assign();
        } else {
            $id = isset($param['id']) ? $param['id'] : 0;
            $rule = get_admin_rule();
            if ($id > 0) {
                $rules = get_admin_group_info($id);
                $role_rule = create_tree_list(0, $rule, $rules);
                $role = Db::name('AdminGroup')->where(['id' => $id])->find();
                View::assign('role', $role);
            } else {
                $role_rule = create_tree_list(0, $rule, []);
            }
            View::assign('role_rule', $role_rule);
            View::assign('id', $id);
            return view();
        }
    }

    //删除
    public function delete()
    {
        if (!request()->isDelete() || !valid_admin_csrf()) return to_assign(1, '请求无效，请刷新页面后重试');
        $id = get_params("id");
        if (in_array(1, array_map('intval', (array) $id), true)) {
            return to_assign(1, "该组是系统所有者，无法删除");
        }
        if (Db::name('AdminGroup')->delete($id) !== false) {
            //组删了，这个组里的人的权限和左侧菜单都会变，两份缓存都要清
            clear_cache('adminMenu');
            clear_cache('adminRules');
            safe_add_log('delete', $id, []);
            return to_assign(0, "删除角色成功");
        } else {
            return to_assign(1, "删除失败");
        }
    }
}
