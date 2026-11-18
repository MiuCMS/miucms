<?php
/**
 * @copyright Copyright (c) 2026 米柚工作室
 * @license https://opensource.org/licenses/Apache-2.0
 * @link https://www.miucms.com
 */

declare (strict_types = 1);

namespace app\admin\controller;

use app\admin\BaseController;
use app\admin\model\User as UserList;
use dateset\Dateset;
use think\facade\Db;
use think\facade\View;

class User extends BaseController
{
    public function index()
    {
        if (request()->isAjax()) {
            $param = get_params();
            $where = array();
            if (!empty($param['keywords'])) {
                $where[] = ['nickname|username|name|mobile|province|city', 'like', '%' . $param['keywords'] . '%'];
            }

            //按时间检索
            $start_time = isset($param['start_time']) ? strtotime(urldecode($param['start_time'])) : 0;
            $end_time = isset($param['end_time']) ? strtotime(urldecode($param['end_time'])) : 0;

            if ($start_time > 0 && $end_time > 0) {
                if ($start_time === $end_time) {
                    $where[] = ['register_time', '=', $start_time];
                } else {
                    $where[] = ['register_time', '>=', $start_time];
                    $where[] = ['register_time', '<=', $end_time];
                }
            } elseif ($start_time > 0 && $end_time == 0) {
                $where[] = ['register_time', '>=', $start_time];
            } elseif ($start_time == 0 && $end_time > 0) {
                $where[] = ['register_time', '<=', $end_time];
            }

            $rows = empty($param['limit']) ? get_config('app.page_size') : $param['limit'];
            $content = UserList::field('id,headimgurl,username,level,sex,nickname,name,mobile,email,register_time,status')->where($where)
                ->order('id desc')
                ->paginate(['list_rows'=> $rows])
                ->each(function ($item, $key) {
                    $item->register_time = empty($item->register_time) ? '-' : date('Y-m-d H:i', $item->register_time);
                    $item->level_name = Db::name('UserLevel')->where(['id' => $item->level])->value('title');
                });
            return table_assign(0, '', $content);
        } else {
            return view();
        }
    }

    //编辑
    public function edit()
    {
        if (request()->isAjax()) {
            if (!request()->isPost()) {
                return to_assign(1, '错误的请求方式');
            }

            $param = get_params();
            $id = filter_var($param['id'] ?? null, FILTER_VALIDATE_INT);
            $userRecord = $id === false || $id < 1 ? null : Db::name('User')
                ->field('id,username,nickname,name,mobile,mobile_status,email,headimgurl,sex,desc,birthday,country,province,city,address,level,status')
                ->where(['id' => $id])->find();
            if (empty($userRecord)) {
                return to_assign(1, '用户不存在');
            }

            $fields = ['nickname', 'name', 'mobile', 'mobile_status', 'email', 'headimgurl', 'sex', 'desc', 'birthday', 'country', 'province', 'city', 'address', 'level', 'status'];
            $data = array_intersect_key($param, array_flip($fields));
            foreach ($data as $field => $value) {
                if (!is_scalar($value)) {
                    return to_assign(1, '用户信息格式不正确');
                }
                $data[$field] = trim((string) $value);
            }
            if (isset($data['email'])) {
                $data['email'] = strtolower($data['email']);
            }

            if (($data['nickname'] ?? '') === '') {
                return to_assign(1, '用户昵称不能为空');
            }
            $maxLengths = ['nickname' => 255, 'name' => 100, 'mobile' => 20, 'email' => 50, 'headimgurl' => 255, 'desc' => 1000, 'country' => 20, 'province' => 20, 'city' => 20, 'address' => 100];
            foreach ($maxLengths as $field => $maxLength) {
                if (mb_strlen((string) ($data[$field] ?? ''), 'UTF-8') > $maxLength) {
                    return to_assign(1, '字段长度超出限制：' . $field);
                }
            }
            if (($data['email'] ?? '') !== '' && !filter_var($data['email'], FILTER_VALIDATE_EMAIL)) {
                return to_assign(1, '邮箱格式不正确');
            }
            if (($data['email'] ?? '') !== '' && Db::name('User')->where('email', $data['email'])->where('id', '<>', $id)->where('status', '<>', -1)->count() > 0) {
                return to_assign(1, '该邮箱已绑定其他会员账号');
            }

            $birthday = $data['birthday'] ?? '';
            if ($birthday === '') {
                $data['birthday'] = 0;
            } else {
                $dateParts = explode('-', $birthday);
                if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $birthday) || count($dateParts) !== 3 || !checkdate((int) $dateParts[1], (int) $dateParts[2], (int) $dateParts[0])) {
                    return to_assign(1, '生日格式不正确');
                }
                $data['birthday'] = strtotime($birthday);
            }

            foreach (['sex' => [0, 1, 2], 'mobile_status' => [0, 1], 'status' => [-1, 0, 1]] as $field => $allowedValues) {
                $value = filter_var($data[$field] ?? null, FILTER_VALIDATE_INT);
                if ($value === false || !in_array($value, $allowedValues, true)) {
                    return to_assign(1, '用户状态选项不正确');
                }
                $data[$field] = $value;
            }
            $data['level'] = filter_var($data['level'] ?? null, FILTER_VALIDATE_INT);
            $activeLevel = $data['level'] === false ? null : Db::name('UserLevel')->where(['id' => $data['level'], 'status' => 1])->find();
            if ($data['level'] === false || $data['level'] < 1 || (empty($activeLevel) && $data['level'] !== (int) $userRecord['level'])) {
                return to_assign(1, '请选择有效的用户等级');
            }

            $data['update_time'] = time();
            try {
                $res = Db::name('User')->where(['id' => $id])->strict(false)->field(true)->update($data);
            } catch (\Throwable $e) {
                if (is_duplicate_key_error($e)) {
                    return to_assign(1, '该用户名或邮箱已被其他正常会员使用');
                }
                throw $e;
            }
            if ($res !== false) {
                clear_cache('homeUser');
                clear_cache('homeUserInvite');
                safe_add_log('edit', $id, $data);
                return to_assign();
            }
            return to_assign(1, '提交失败');
        } else {
            $param = get_params();
            $id = isset($param['id']) ? $param['id'] : 0;
            $user = Db::name('User')
                ->where(['id' => $id])->find();
            if (empty($user)) {
                return to_assign(1, '用户不存在');
            }
            $user['birthday'] = empty($user['birthday']) ? '' : date('Y-m-d', (int) $user['birthday']);
            $levels = Db::name('UserLevel')->where(['status' => 1])->select()->toArray();
            if (!in_array((int) $user['level'], array_map('intval', array_column($levels, 'id')), true)) {
                $currentLevel = Db::name('UserLevel')->where(['id' => $user['level']])->find();
                if (!empty($currentLevel)) {
                    $currentLevel['title'] .= '（已停用）';
                    $levels[] = $currentLevel;
                }
            }
            View::assign('user', $user);
            View::assign('levels', $levels);
            return view();
        }
    }

    //禁用/启用
    public function disable()
    {
        if (!request()->isPost()) {
            return to_assign(1, '错误的请求方式');
        }
        $id = get_params('id');
        $status = get_params('status');
        if (!is_scalar($id) || !ctype_digit((string) $id) || (int) $id < 1
            || !is_scalar($status) || !in_array((string) $status, ['0', '1'], true)) {
            return to_assign(1, '会员编号或状态参数无效');
        }
        $id = (int) $id;
        $status = (int) $status;
        $data = ['status' => $status, 'update_time' => time()];
        try {
            $updated = Db::name('User')->where('id', $id)->where('status', 'in', [0, 1])->update($data);
        } catch (\Throwable $e) {
            if (is_duplicate_key_error($e)) {
                return to_assign(1, '该邮箱已被其他正常会员使用，无法启用');
            }
            throw $e;
        }
        if ($updated !== false) {
            clear_cache('homeUser');
            clear_cache('homeUserInvite');
            if ($status === 0) {
                safe_add_log('disable', $id, $data);
            } else {
                safe_add_log('recovery', $id, $data);
            }
            return to_assign();
        } else {
            return to_assign(1, "操作失败");
        }
    }

    //日志
    public function log()
    {
        if (request()->isAjax()) {
            $param = get_params();
            $where = array();
            if (!empty($param['keywords'])) {
                $where[] = ['nickname|content|param_id', 'like', '%' . $param['keywords'] . '%'];
            }
            if (!empty($param['action'])) {
                $where[] = ['title', '=', $param['action']];
            }
            $rows = empty($param['limit']) ? get_config('app.page_size') : $param['limit'];
            $content = DB::name('UserLog')
                ->field("id,uid,nickname,title,content,ip,param_id,param,FROM_UNIXTIME(create_time,'%Y-%m-%d %H:%i:%s') create_time")
                ->order('create_time desc')
                ->where($where)
                ->paginate(['list_rows'=> $rows]);

            $content->toArray();
            foreach ($content as $k => $v) {
                $data = $v;
                $param_array = json_decode($v['param'], true);
                $param_value = '';
                foreach ($param_array as $key => $value) {
                    if (is_array($value)) {
                        $value = array_to_string($value);
                    }
                    $param_value .= $key . ':' . $value . ' | ';
                }
                $data['param'] = trim($param_value, '| ');
                $content->offsetSet($k, $data);
            }
            return table_assign(0, '', $content);
        } else {
            $type_action = get_config('log.user_action');
            View::assign('type_action', $type_action);
            return view();
        }
    }

}
