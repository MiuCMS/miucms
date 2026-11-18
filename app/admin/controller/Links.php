<?php
/**
 * @copyright Copyright (c) 2026 米柚工作室
 * @license https://opensource.org/licenses/Apache-2.0
 * @link https://blog.miucms.com
 */

declare (strict_types = 1);

namespace app\admin\controller;

use app\admin\BaseController;
use app\admin\model\Links as linksList;
use app\admin\validate\linksCheck;
use think\exception\ValidateException;
use think\facade\Db;
use think\facade\View;

class Links extends BaseController
{
    public function index()
    {
        if (request()->isAjax()) {
            $param = get_params();
            $where = array();
            if (!empty($param['keywords'])) {
                $where[] = ['id|name', 'like', '%' . $param['keywords'] . '%'];
            }
            $where[] = ['status', '=', 1];
            $rows = empty($param['limit']) ? get_config('app.page_size') : $param['limit'];
            $links = linksList::where($where)
                ->order('sort desc, id desc')
                ->paginate(['list_rows'=> $rows]);
            return table_assign(0, '', $links);
        } else {
            return view();
        }
    }

    //添加
    public function add()
    {
        $param = get_params();
		if (request()->isAjax()) {
			if (!request()->isPost()) {
				return to_assign(1, '请使用 POST 请求');
			}
			$id = $param['id'] ?? 0;
			if (!is_scalar($id) || ($id !== '' && !ctype_digit((string) $id))) {
				return to_assign(1, '友情链接编号无效');
			}
			$param['id'] = (int) $id;
            if (!empty($param['id']) && $param['id'] > 0) {
                try {
                    validate(linksCheck::class)->scene('edit')->check($param);
                } catch (ValidateException $e) {
                    // 验证失败 输出错误信息
                    return to_assign(1, $e->getError());
                }
                $param['update_time'] = time();
                $res = linksList::where('id', $param['id'])->strict(false)->field(true)->update($param);
                if ($res) {
                    safe_add_log('edit', $param['id'], $param);
                }
                //前台友情链接走了持久缓存，改完必须清一下，否则前台还是旧数据
                clear_cache('homeLink');
                return to_assign();
            } else {
				unset($param['id']);
                try {
                    validate(linksCheck::class)->scene('add')->check($param);
                } catch (ValidateException $e) {
                    // 验证失败 输出错误信息
                    return to_assign(1, $e->getError());
                }
                $param['create_time'] = time();
                $sid = linksList::strict(false)->field(true)->insertGetId($param);
                if ($sid) {
                    safe_add_log('add', $sid, $param);
                }
                //前台友情链接走了持久缓存，新增后必须清一下
                clear_cache('homeLink');
                return to_assign();
            }
        }
		else{
			$id = empty($param['id']) ? 0 : $param['id'];
			if ($id > 0) {
				$links = Db::name('Links')->where(['id' => $id])->find();
				View::assign('links', $links);
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
            return to_assign(1, '友情链接编号无效');
        }
        $id = (int) $id;
        $updated = Db::name('Links')->where('id', $id)->where('status', '<>', -1)
            ->update(['status' => -1, 'update_time' => time()]);
        if ($updated !== false) {
            safe_add_log('delete', $id);
            //前台友情链接走了持久缓存，删完必须清一下
            clear_cache('homeLink');
            return to_assign(0, "删除成功");
        } else {
            return to_assign(1, "删除失败");
        }
    }
}
