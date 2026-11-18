<?php
/**
 * @copyright Copyright (c) 2026 米柚工作室
 * @license https://opensource.org/licenses/Apache-2.0
 * @link https://www.miucms.com
 */

declare (strict_types = 1);

namespace app\admin\controller;

use app\admin\BaseController;
use app\admin\validate\RuleCheck;
use think\exception\ValidateException;
use think\facade\Db;
use think\facade\View;
use think\facade\Cache;

class Rule extends BaseController
{
    public function index()
    {
        if (request()->isAjax()) {
            $rule = Db::name('adminRule')->order('sort asc,id asc')->select();
			$list = generateTree($rule);
            return to_assign(0, '', $list);
        } else {
            return view();
        }
    }

    //添加
    public function add()
    {
        $param = get_params();
        if (request()->isAjax()) {
			$param['src'] = preg_replace('# #','',$param['src']);
            if ($param['id'] > 0) {
                try {
                    validate(RuleCheck::class)->scene('edit')->check($param);
                } catch (ValidateException $e) {
                    // 验证失败 输出错误信息
                    return to_assign(1, $e->getError());
                }
                Db::name('AdminRule')->strict(false)->field(true)->update($param);
                safe_add_log('edit', $param['id'], $param);
            } else {
                try {
                    validate(RuleCheck::class)->scene('add')->check($param);
                } catch (ValidateException $e) {
                    // 验证失败 输出错误信息
                    return to_assign(1, $e->getError());
                }
                $param['create_time'] = time();
                $rid = Db::name('AdminRule')->strict(false)->field(true)->insertGetId($param);
                //自动为系统所有者管理组分配新增的节点
                $group = Db::name('AdminGroup')->find(1);
                if (!empty($group)) {
                    $newGroup['id'] = 1;
                    $newGroup['rules'] = $group['rules'] . ',' . $rid;
                    Db::name('AdminGroup')->strict(false)->field(true)->update($newGroup);
                    safe_add_log('add', $rid, $param);
                }
            }
            //节点变了，鉴权（adminRules）和左侧菜单（adminMenu）两份缓存都要清：
            //adminRules 存的是「该用户有权限的 src 列表」，adminMenu 存的是左侧菜单，
            //两个都是从这个节点表算出来的，只清一个另一个就是旧的
            clear_cache('adminRules');
            clear_cache('adminMenu');
            return to_assign();
        } else {
            $id = isset($param['id']) ? $param['id'] : 0;
            $pid = isset($param['pid']) ? $param['pid'] : 0;
            if($id>0){
                $detail = Db::name('AdminRule')->where('id',$id)->find();
                View::assign('detail', $detail);
            }
            View::assign('id', $id);
            View::assign('pid', $pid);
            return view();
        }
    }
    //删除
    public function delete()
    {
        if (!request()->isDelete() || !valid_admin_csrf()) return to_assign(1, '请求无效，请刷新页面后重试');
        $id = get_params("id");
        $count = Db::name('AdminRule')->whereIn('pid', (array) $id)->count();
        //dump($count);die;
        if ($count > 0) {
            return to_assign(1, "该节点下还有子节点，无法删除");
        }
        if (Db::name('AdminRule')->delete($id) !== false) {
            //节点删了，鉴权和左侧菜单两份缓存都要清
            clear_cache('adminRules');
            clear_cache('adminMenu');
            safe_add_log('delete', $id, []);
            return to_assign(0, "删除节点成功");
        } else {
            return to_assign(1, "删除失败");
        }
    }
    //修改排序
    public function edit_field()
    {
        $param = get_params();
        if (request()->isAjax()) {
            $field=$param['field'];
            $value=$param['value'];
            $data[$field]=$value;
            $data['id']=$param['id'];
            $res=Db::name('AdminRule')->save($data);
            if ($res) {
                safe_add_log('edit', $param['id'], $data);
                clear_cache('adminRules');
                clear_cache('adminMenu');
                return to_assign(0, "操作成功");
            }else{
                return to_assign(1, "操作失败");
            }
        }
    }
}
