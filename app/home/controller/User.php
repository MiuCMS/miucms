<?php
/**
 * @copyright Copyright (c) 2026 米柚工作室
 * @license https://opensource.org/licenses/Apache-2.0
 * @link https://www.miucms.com
 */

declare (strict_types = 1);

namespace app\home\controller;

use app\home\BaseController;
use think\facade\Cache;
use think\facade\Db;
use think\facade\View;

class User extends BaseController
{
    public function index()
    {
        $uid = get_login_user('id');
        $userInfo = miu_cached_home_user((int) $uid);
        $userInfo['showname'] = empty($userInfo['nickname']) ? $userInfo['username'] : $userInfo['nickname'];
        $userInfo['level_title'] = miu_cached_home_user_level_title((int) ($userInfo['level'] ?? 0));
        $userInfo['sex'] = match ((int) ($userInfo['sex'] ?? 0)) {
            1 => '女',
            2 => '男',
            default => '未设置',
        };
        add_user_log('view', '个人中心');
        View::assign('userInfo', $userInfo);
        return view(app_path('view') . 'user_index.html');
    }

    public function info_edit()
    {
        $uid = get_login_user('id');
        $userInfo = miu_cached_home_user((int) $uid);
		$userInfo['birthday'] = $userInfo['birthday']==0 ? '' : date('Y-m-d', $userInfo['birthday']);
        add_user_log('view', '个人信息');
        View::assign('userInfo', $userInfo);
        return view(app_path('view') . 'user_info_edit.html');
    }

    public function password_edit()
    {
        $uid = get_login_user('id');
        $userInfo = miu_cached_home_user((int) $uid);
        $userInfo['showname'] = empty($userInfo['nickname']) ? $userInfo['username'] : $userInfo['nickname'];
        add_user_log('view', '修改密码');
        View::assign('userInfo', $userInfo);
        return view(app_path('view') . 'user_password_edit.html');
    }

    public function history()
    {
        $uid = (int) get_login_user('id');
        $pageParam = request()->param('page', 1);
        $page = is_scalar($pageParam) ? max(1, min(10000, (int) $pageParam)) : 1;
        add_user_log('view', '登录记录');
        $metaKey = 'homeUserLoginHistoryMeta_' . $uid;
        $meta = Cache::get($metaKey);
        if (!is_array($meta)) {
            $total = (int) Db::name('UserLog')->where(['uid' => $uid, 'type' => 'login'])->count();
            $meta = ['total' => $total, 'lastPage' => max(1, (int) ceil($total / 10))];
            Cache::tag('homeUserLog')->set($metaKey, $meta, 60);
        }
        $total = (int) $meta['total'];
        $lastPage = (int) $meta['lastPage'];
        $page = min($page, $lastPage);
        $cacheKey = 'homeUserLoginHistory_' . $uid . '_' . $page;
        $result = Cache::get($cacheKey);
        if (!is_array($result)) {
            $logs = Db::name('UserLog')->where(['uid' => $uid, 'type' => 'login'])
                ->field('id,ip,browser,os,create_time')
                ->order('id desc')
                ->page($page, 10)
                ->select()
                ->toArray();
            foreach ($logs as &$log) {
                $log['time_text'] = empty($log['create_time']) ? '—' : date('Y-m-d H:i:s', (int) $log['create_time']);
            }
            unset($log);
            $result = ['logs' => $logs, 'total' => $total, 'page' => $page, 'lastPage' => $lastPage];
            Cache::tag('homeUserLog')->set('homeUserLoginHistory_' . $uid . '_' . $page, $result, 60);
        }

        $userInfo = miu_cached_home_user($uid);
        $userInfo['showname'] = empty($userInfo['nickname']) ? $userInfo['username'] : $userInfo['nickname'];
        $userInfo['level_title'] = miu_cached_home_user_level_title((int) ($userInfo['level'] ?? 0));
        View::assign('userInfo', $userInfo);
        View::assign('loginLogs', $result['logs']);
        View::assign('total', $result['total']);
        View::assign('page', $result['page']);
        View::assign('lastPage', $result['lastPage']);
        View::assign('prevPage', max(1, (int) $result['page'] - 1));
        View::assign('nextPage', min((int) $result['lastPage'], (int) $result['page'] + 1));
        return view(app_path('view') . 'user_history.html');
    }

    public function collect_list()
    {
        $uid = (int) get_login_user('id');
        $rows = miu_cached_user_collect_docs($uid);
        $total = count($rows);
        $pageParam = request()->param('page', 1);
        $page = is_scalar($pageParam) ? max(1, min(10000, (int) $pageParam)) : 1;
        $lastPage = max(1, (int) ceil($total / 10));
        $page = min($page, $lastPage);
        $collectDocs = array_slice($rows, ($page - 1) * 10, 10);
        foreach ($collectDocs as &$doc) {
            $doc = miu_doc_row($doc, nav_by_id((int) $doc['nav_id']));
            $doc['collect_time_text'] = empty($doc['collect_time']) ? '—' : date('Y-m-d H:i', (int) $doc['collect_time']);
        }
        unset($doc);

        $userInfo = miu_cached_home_user($uid);
        $userInfo['showname'] = empty($userInfo['nickname']) ? $userInfo['username'] : $userInfo['nickname'];
        $userInfo['level_title'] = miu_cached_home_user_level_title((int) ($userInfo['level'] ?? 0));
        add_user_log('view', '我的收藏');
        View::assign('userInfo', $userInfo);
        View::assign('collectDocs', $collectDocs);
        View::assign('total', $total);
        View::assign('page', $page);
        View::assign('lastPage', $lastPage);
        View::assign('prevPage', max(1, $page - 1));
        View::assign('nextPage', min($lastPage, $page + 1));
        return view(app_path('view') . 'user_collect_list.html');
    }

    public function invite()
    {
        $uid = (int) get_login_user('id');
        $pageParam = request()->param('page', 1);
        $page = is_scalar($pageParam) ? max(1, min(10000, (int) $pageParam)) : 1;
        $totalKey = 'homeUserInviteTotal_' . $uid;
        $total = Cache::get($totalKey);
        if (!is_numeric($total)) {
            $total = (int) Db::name('User')->where('puid', $uid)->where('status', '<>', -1)->count();
            Cache::tag('homeUserInvite')->set($totalKey, $total, 60);
        }
        $total = (int) $total;
        $lastPage = max(1, (int) ceil($total / 10));
        $page = min($page, $lastPage);
        $cacheKey = 'homeUserInviteList_' . $uid . '_' . $page;
        $invitees = Cache::get($cacheKey);
        if (!is_array($invitees)) {
            $invitees = Db::name('User')->where('puid', $uid)->where('status', '<>', -1)
                ->field('id,nickname,username,email,register_time')
                ->order('id desc')->page($page, 10)->select()->toArray();
            foreach ($invitees as &$invitee) {
                $invitee['showname'] = trim((string) ($invitee['nickname'] ?: $invitee['username']));
                $invitee['register_time_text'] = empty($invitee['register_time']) ? '—' : date('Y-m-d H:i', (int) $invitee['register_time']);
            }
            unset($invitee);
            Cache::tag('homeUserInvite')->set($cacheKey, $invitees, 60);
        }

        $userInfo = miu_cached_home_user($uid);
        $userInfo['showname'] = empty($userInfo['nickname']) ? $userInfo['username'] : $userInfo['nickname'];
        $userInfo['level_title'] = miu_cached_home_user_level_title((int) ($userInfo['level'] ?? 0));
        add_user_log('view', '邀请注册');
        View::assign('userInfo', $userInfo);
        View::assign('invitees', $invitees);
        View::assign('inviteLink', url_entry() . '/reg?invite_id=' . $uid);
        View::assign('total', $total);
        View::assign('page', $page);
        View::assign('lastPage', $lastPage);
        View::assign('prevPage', max(1, $page - 1));
        View::assign('nextPage', min($lastPage, $page + 1));
        return view(app_path('view') . 'user_invite.html');
    }

    public function collect_state()
    {
        $docIdParam = request()->param('doc_id');
        if (!is_scalar($docIdParam) || (int) $docIdParam < 1) {
            return to_assign(1, '文档参数不正确');
        }
        $docId = (int) $docIdParam;
        $doc = miu_cached_document($docId);
        if (!$doc || (int) ($doc['status'] ?? 0) !== 1 || (int) ($doc['arcrank'] ?? 0) < 0) {
            return to_assign(1, '内容不存在或暂不可收藏');
        }

        $uid = (int) get_login_user('id');
        $rows = miu_cached_user_collect_docs($uid);
        $collected = false;
        foreach ($rows as $row) {
            if ((int) $row['id'] === $docId) {
                $collected = true;
                break;
            }
        }
        return to_assign(0, '获取成功', ['collected' => $collected, 'count' => (int) ($doc['collection'] ?? 0)]);
    }

    public function collect_toggle()
    {
        if (!request()->isPost()) {
            return to_assign(1, '错误的请求方式');
        }
        $docIdParam = get_params('doc_id');
        if (!is_scalar($docIdParam) || (int) $docIdParam < 1) {
            return to_assign(1, '文档参数不正确');
        }

        $uid = (int) get_login_user('id');
        $docId = (int) $docIdParam;
        try {
            $result = Db::transaction(function () use ($uid, $docId) {
                if (!Db::name('User')->where('id', $uid)->where('status', 1)->lock(true)->field('id')->find()) {
                    return ['error' => '请重新登录'];
                }
                if (!Db::name('Document')->where('id', $docId)->where('is_del', 0)->where('status', 1)->where('arcrank', '>=', 0)->lock(true)->field('id')->find()) {
                    return ['error' => '内容不存在或暂不可收藏'];
                }

                $collect = Db::name('UserCollect')->where(['uid' => $uid, 'doc_id' => $docId])->field('id')->find();
                if ($collect) {
                    Db::name('UserCollect')->where('id', $collect['id'])->delete();
                    Db::name('Document')->where('id', $docId)->where('collection', '>', 0)->dec('collection')->update();
                    $collected = false;
                } else {
                    Db::name('UserCollect')->insert(['uid' => $uid, 'doc_id' => $docId, 'create_time' => time()]);
                    Db::name('Document')->where('id', $docId)->inc('collection')->update();
                    $collected = true;
                }

                return [
                    'collected' => $collected,
                    'count' => (int) Db::name('Document')->where('id', $docId)->value('collection'),
                ];
            });
        } catch (\Throwable $e) {
            return to_assign(1, '收藏操作失败，请稍后重试');
        }
        if (isset($result['error'])) {
            return to_assign(1, $result['error']);
        }

        clear_cache('homeUserCollect');
        clear_cache('Document');
        add_user_log('edit', $result['collected'] ? '收藏文档' : '取消收藏', $docId);
        return to_assign(0, $result['collected'] ? '收藏成功' : '已取消收藏', $result);
    }

    public function password_submit()
    {
        if (!request()->isPost()) {
            return to_assign(1, '错误的请求方式');
        }

        $param = get_params();
        foreach (['old_password', 'new_password', 'new_password_confirm'] as $field) {
            if (!isset($param[$field]) || !is_scalar($param[$field])) {
                return to_assign(1, '请完整填写密码信息');
            }
        }

        $oldPassword = (string) $param['old_password'];
        $newPassword = (string) $param['new_password'];
        if (mb_strlen($newPassword, 'UTF-8') < 6 || mb_strlen($newPassword, 'UTF-8') > 128) {
            return to_assign(1, '新密码长度须为 6 至 128 位');
        }
        if ($newPassword !== (string) $param['new_password_confirm']) {
            return to_assign(1, '两次输入的新密码不一致');
        }

        $uid = (int) get_login_user('id');
        try {
            $result = Db::transaction(function () use ($uid, $oldPassword, $newPassword) {
                $user = Db::name('User')->field('id,password,salt')->where('id', $uid)->lock(true)->find();
                if (empty($user) || !verify_user_password($oldPassword, $user['password'], (string) $user['salt'])) {
                    return false;
                }
                $updated = Db::name('User')->where('id', $uid)->update([
                    'password'    => hash_user_password($newPassword),
                    'salt'        => '',
                    'update_time' => time(),
                ]);
                if ($updated === false) {
                    throw new \RuntimeException('密码写入失败');
                }
                Db::name('UserPasswordReset')->where('uid', $uid)->delete();
                return true;
            });
        } catch (\Throwable $e) {
            return to_assign(1, '密码修改失败，请重试');
        }
        if (!$result) {
            return to_assign(1, '当前密码不正确');
        }
        clear_cache('homeUser');
        add_user_log('edit', '修改密码', $uid);
        return to_assign(0, '密码修改成功');
    }

    public function edit_submit()
    {
		if (!request()->isPost()) {
			return to_assign(1, '错误的请求方式');
		}
        $param = get_params();
		$uid = get_login_user('id');

		//只接收个人资料字段，避免会员通过伪造请求修改等级、状态或登录信息。
		$allowed = ['nickname', 'name', 'mobile', 'sex', 'desc', 'birthday', 'country', 'province', 'city', 'address'];
		$param = array_intersect_key($param, array_flip($allowed));
		foreach ($param as $field => $value) {
			if (!is_scalar($value)) {
				return to_assign(1, '个人资料格式不正确');
			}
			$param[$field] = trim((string) $value);
		}
		$param['birthday'] = ($param['birthday'] ?? '') === '' ? 0 : strtotime($param['birthday']);
		if ($param['birthday'] === false) {
			return to_assign(1, '生日格式不正确');
		}
		$sex = (int) ($param['sex'] ?? 0);
		if (!in_array($sex, [0, 1, 2], true)) {
			return to_assign(1, '性别选项不正确');
		}
		$param['sex'] = $sex;
		$maxLengths = ['nickname' => 255, 'name' => 100, 'mobile' => 20, 'desc' => 1000, 'country' => 20, 'province' => 20, 'city' => 20, 'address' => 100];
		foreach ($maxLengths as $field => $maxLength) {
			if (mb_strlen((string) ($param[$field] ?? ''), 'UTF-8') > $maxLength) {
				return to_assign(1, '个人资料超出长度限制：' . $field);
			}
		}
		$param['update_time'] = time();

        //插件钩子：用户资料修改（插件可修改 $param）
        \app\services\PluginService::userUpdate($param);
        unset($param['email']);

        $res = Db::name('User')->where(['id' => $uid])->strict(false)->field(true)->update($param);
        if ($res !== false) {
            clear_cache('homeUser');
            clear_cache('homeUserInvite');
            add_user_log('edit', '个人信息', $uid, $param);
            return to_assign(0, '操作成功');
        } else {
            return to_assign(1, '操作失败');
        }
    }

}
