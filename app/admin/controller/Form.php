<?php
/**
 * @copyright Copyright (c) 2026 米柚工作室
 * @license https://opensource.org/licenses/Apache-2.0
 * @link https://www.miucms.com
 */

declare (strict_types = 1);

namespace app\admin\controller;

use app\admin\BaseController;
use app\admin\validate\FormCheck;
use app\admin\validate\FormFieldCheck;
use think\exception\ValidateException;
use think\facade\Db;
use think\facade\View;

/**
 * 后台「留言」模块
 *
 * 自由表单与留言管理使用以下数据表：
 *   cms_form              表单
 *   cms_form_field        表单字段
 *   cms_form_message      留言主表
 *   cms_form_message_attr 留言的字段值（一条留言的每个字段一行）
 *
 * 菜单对应关系（后台「留言」下）：
 *   表单管理     admin/form/index         表单列表 + 增删改
 *   表单字段列表 admin/form/field         某个表单的字段（增删改）
 *   留言列表     admin/form/message       收到的留言（layui 表格）
 *
 * 另外几个动作是留言列表里用的，不在菜单上：
 *   message_read / message_delete / message_star / message_reply / message_examine
 */
class Form extends BaseController
{
    /**
     * 表单管理（列表）
     *
     * @return \think\Response|\think\response\View
     */
    public function index()
    {
        if (request()->isAjax()) {
            $param = get_params();
            $where = [];
            if (!empty($param['keywords'])) {
                $where[] = ['form_name', 'like', '%' . $param['keywords'] . '%'];
            }
            $rows = empty($param['limit']) ? get_config('app.page_size') : $param['limit'];

            $list = Db::name('form')->where($where)->order('id asc')->paginate(['list_rows' => $rows]);

            //字段数 / 留言数 / 未读数：一次分组查出来，别在 each 里逐条查库
            $ids = array_column($list->toArray()['data'], 'id');
            $fieldCount   = $this->countBy('form_field', $ids, ['is_del' => 0]);
            $messageCount = $this->countBy('form_message', $ids);
            $unreadCount  = $this->countBy('form_message', $ids, ['is_read' => 0]);

            //绑定的栏目名（留言模型 clue 下的栏目）
            $navNames = [];
            foreach ($this->clueNavMap() as $nid => $n) {
                $navNames[$nid] = (string) $n['title'];
            }

            $list->each(function ($item) use ($fieldCount, $messageCount, $unreadCount, $navNames) {
                $id = (int) $item['id'];
                $item['field_count']   = (int) ($fieldCount[$id] ?? 0);
                $item['message_count'] = (int) ($messageCount[$id] ?? 0);
                $item['unread_count']  = (int) ($unreadCount[$id] ?? 0);
                $item['nav_name']      = $navNames[(int) $item['nav_id']] ?? '';
                return $item;
            });

            return table_assign(0, '', $list);
        }

        return view();
    }

    /**
     * 新增 / 编辑表单（带了 id 就是编辑）
     *
     * @return \think\Response|\think\response\View
     */
    public function add()
    {
        $param = get_params();

        if (request()->isAjax()) {
            $isEdit = !empty($param['id']) && $param['id'] > 0;

            try {
                validate(FormCheck::class)->scene($isEdit ? 'edit' : 'add')->check($param);
            } catch (ValidateException $e) {
                return to_assign(1, $e->getError());
            }

            $navId = (int) ($param['nav_id'] ?? 0);
            if ($navId > 0 && !isset($this->clueNavMap()[$navId])) {
                return to_assign(1, '「关联栏目」只能选「留言模型」下面的栏目');
            }

            $data = [
                'nav_id'       => $navId,
                'form_name'    => trim((string) $param['form_name']),
                'intro'        => trim((string) ($param['intro'] ?? '')),
                'status'       => (int) ($param['status'] ?? 1),
                'attr_auto'    => (int) ($param['attr_auto'] ?? 0),
                'open_reply'   => (int) ($param['open_reply'] ?? 0),
                'open_examine' => (int) ($param['open_examine'] ?? 0),
                'update_time'  => time(),
            ];

            if ($isEdit) {
                $id  = (int) $param['id'];
                $res = Db::name('form')->where('id', $id)->update($data);
                if ($res !== false) {
                    safe_add_log('edit', $id, $param);
                }
            } else {
                $data['lang']     = 'cn';
                $data['add_time'] = time();
                $id = (int) Db::name('form')->insertGetId($data);
                if ($id) {
                    safe_add_log('add', $id, $param);
                }
            }

            clear_cache('formMessage');
            clear_cache('homeForm');
            return to_assign();
        }

        $id   = (int) ($param['id'] ?? 0);
        $info = [];
        if ($id > 0) {
            $info = Db::name('form')->where('id', $id)->find();
            if (!$info) {
                return to_assign(1, '表单不存在');
            }
        }

        View::assign('info', $info);
        View::assign('id', $id);
        //「关联栏目」下拉：只列留言模型（clue）下的栏目
        View::assign('navs', array_values($this->clueNavMap()));
        View::assign('nav_id_cur', (int) ($info['nav_id'] ?? 0));
        return view();
    }

    /**
     * 删除表单（连同它的字段、留言、留言字段值一起删）
     *
     * @return \think\Response
     */
    public function delete()
    {
        //破坏性操作只接受 DELETE（前端 tool.delete 发的就是 DELETE），
        //否则顶层 GET 跳转也能带着登录态触发（CSRF）
        if (!request()->isDelete()) {
            return to_assign(1, '非法请求');
        }

        $id = (int) get_params('id');
        if ($id <= 0) {
            return to_assign(1, '缺少要删除的表单');
        }

        $aidList = Db::name('form_message')->where('form_id', $id)->column('id');

        Db::transaction(function () use ($id, $aidList) {
            Db::name('form')->where('id', $id)->delete();
            Db::name('form_field')->where('form_id', $id)->delete();
            Db::name('form_message')->where('form_id', $id)->delete();
            if ($aidList) {
                Db::name('form_message_attr')->whereIn('aid', $aidList)->delete();
            }
        });

        safe_add_log('delete', $id);
        clear_cache('formMessage');
        clear_cache('homeForm');
        return to_assign(0, '删除成功');
    }

    /**
     * 表单字段列表
     *
     * @return \think\Response|\think\response\View
     */
    public function field()
    {
        $param  = get_params();
        $formId = (int) ($param['form_id'] ?? 0);

        if (request()->isAjax()) {
            $where = [['is_del', '=', 0]];
            if ($formId > 0) {
                $where[] = ['form_id', '=', $formId];
            }
            $rows = empty($param['limit']) ? get_config('app.page_size') : $param['limit'];

            $list = Db::name('form_field')
                ->where($where)
                ->order('sort_order desc, id asc')
                ->paginate(['list_rows' => $rows]);

            $types = form_input_types();
            $list->each(function ($item) use ($types) {
                $type = (int) $item['attr_input_type'];
                $item['type_name'] = $types[$type] ?? ('未知类型' . $type);
                return $item;
            });

            return table_assign(0, '', $list);
        }

        //页面上有个「选择表单」的下拉，默认选第一个表单
        $forms = Db::name('form')->order('id asc')->select()->toArray();
        if ($formId <= 0 && $forms) {
            $formId = (int) $forms[0]['id'];
        }

        View::assign('forms', $forms);
        View::assign('form_id', $formId);
        View::assign('types', form_input_types());
        return view();
    }

    /**
     * 新增 / 编辑字段（带了 id 就是编辑）
     *
     * @return \think\Response|\think\response\View
     */
    public function field_add()
    {
        $param = get_params();

        if (request()->isAjax()) {
            $isEdit = !empty($param['id']) && $param['id'] > 0;

            try {
                validate(FormFieldCheck::class)->scene($isEdit ? 'edit' : 'add')->check($param);
            } catch (ValidateException $e) {
                return to_assign(1, $e->getError());
            }

            $type = (int) $param['attr_input_type'];
            if (!isset(form_input_types()[$type])) {
                return to_assign(1, '请选择字段类型');
            }

            //单行/多行文本不需要可选值；下拉、单选、多选必须有
            $values = trim((string) ($param['attr_values'] ?? ''));
            if (in_array($type, [1, 3, 4], true) && $values === '') {
                return to_assign(1, '下拉框 / 单选框 / 多选框 必须填「可选值列表」，一行一个');
            }
            if (!in_array($type, [1, 3, 4], true)) {
                $values = '';
            }

            $data = [
                'attr_name'       => trim((string) $param['attr_name']),
                'attr_input_type' => $type,
                'attr_values'     => $values,
                'required'        => (int) ($param['required'] ?? 0),
                'validate_type'   => (int) ($param['validate_type'] ?? 0),
                'is_showlist'     => (int) ($param['is_showlist'] ?? 0),
                'sort_order'      => (int) ($param['sort_order'] ?? 0),
                'update_time'     => time(),
            ];

            if ($isEdit) {
                $id  = (int) $param['id'];
                $res = Db::name('form_field')->where('id', $id)->update($data);
                if ($res !== false) {
                    safe_add_log('edit', $id, $param);
                }
            } else {
                $data['form_id']   = (int) $param['form_id'];
                $data['is_del']    = 0;
                $data['add_time']  = time();
                $id = (int) Db::name('form_field')->insertGetId($data);
                if ($id) {
                    safe_add_log('add', $id, $param);
                }
            }

            clear_cache('homeForm');
            return to_assign();
        }

        $id     = (int) ($param['id'] ?? 0);
        $formId = (int) ($param['form_id'] ?? 0);
        $info   = [];

        if ($id > 0) {
            $info = Db::name('form_field')->where('id', $id)->find();
            if (!$info) {
                return to_assign(1, '字段不存在');
            }
            $formId = (int) $info['form_id'];
        }

        $form = Db::name('form')->where('id', $formId)->find();
        if (!$form) {
            return to_assign(1, '所属表单不存在，请先在「表单管理」里建一个表单');
        }

        View::assign('info', $info);
        View::assign('id', $id);
        View::assign('form', $form);
        View::assign('form_id', $formId);
        View::assign('types', form_input_types());
        return view();
    }

    /**
     * 删除字段（软删：is_del=1，保留历史留言中的字段值）
     *
     * @return \think\Response
     */
    public function field_delete()
    {
        //破坏性操作只接受 DELETE（前端 tool.delete 发的就是 DELETE），防 GET 跳转 CSRF
        if (!request()->isDelete()) {
            return to_assign(1, '非法请求');
        }

        $id = (int) get_params('id');
        if ($id <= 0) {
            return to_assign(1, '缺少要删除的字段');
        }

        Db::name('form_field')->where('id', $id)->update([
            'is_del'      => 1,
            'update_time' => time(),
        ]);

        safe_add_log('delete', $id);
        clear_cache('homeForm');
        return to_assign(0, '删除成功');
    }

    /**
     * 留言列表 / 留言详情
     *
     *   GET（普通请求）             → 列表页
     *   GET（普通请求）带 id=N      → 详情页
     *   AJAX                        → 列表数据
     *
     * @return \think\Response|\think\response\View
     */
    public function message()
    {
        $param = get_params();

        //详情：AJAX 请求返回 JSON（前端自己拼弹层，好做自适应高度）；
        //普通请求返回一个独立的详情页
        if (!empty($param['id'])) {
            $id  = (int) $param['id'];
            $row = Db::name('form_message')->where('id', $id)->find();
            if (!$row) {
                if (request()->isAjax()) {
                    return json(['code' => 1, 'msg' => '留言不存在']);
                }
                return to_assign(1, '留言不存在');
            }

            //打开详情就算已读
            if ((int) $row['is_read'] === 0) {
                Db::name('form_message')->where('id', $id)->update(['is_read' => 1, 'update_time' => time()]);
                $row['is_read'] = 1;
                clear_cache('formMessage');
            }

            $form   = Db::name('form')->where('id', (int) $row['form_id'])->find();
            $fields = $this->messageFields($id);
            $unread = (int) Db::name('form_message')->where('is_read', 0)->count();

            if (request()->isAjax()) {
                return json([
                    'code'   => 0,
                    'msg'    => 'ok',
                    'unread' => $unread,
                    'data' => [
                        'info'   => $row,
                        'form'   => $form ?: [],
                        'fields' => $fields,
                    ],
                ]);
            }

            View::assign('info', $row);
            View::assign('form', $form ?: []);
            View::assign('fields', $fields);
            return view('message_detail');
        }

        if (request()->isAjax()) {
            $where = [];

            $formId = (int) ($param['form_id'] ?? 0);
            if ($formId > 0) {
                $where[] = ['m.form_id', '=', $formId];
            }

            //状态筛选：unread=未读 read=已读 star=星标
            $countType = trim((string) ($param['count_type'] ?? ''));
            if ($countType === 'unread') {
                $where[] = ['m.is_read', '=', 0];
            } elseif ($countType === 'read') {
                $where[] = ['m.is_read', '=', 1];
            } elseif ($countType === 'star') {
                $where[] = ['m.is_star', '=', 1];
            }

            // 关键词先匹配字段值，再反查对应的留言ID。
            $keywords = trim((string) ($param['keywords'] ?? ''));
            if ($keywords !== '') {
                $aids = Db::name('form_message_attr')
                    ->where('attr_value', 'like', '%' . $keywords . '%')
                    ->group('aid')
                    ->column('aid');
                $aids[] = 0; //一个都没命中时让 IN 有值，避免查出全部
                $where[] = ['m.id', 'in', $aids];
            }

            $rows = empty($param['limit']) ? get_config('app.page_size') : $param['limit'];

            $list = Db::name('form_message')->alias('m')
                ->field('m.*')
                ->where($where)
                ->order('m.is_read asc, m.id desc')
                ->paginate(['list_rows' => $rows]);

            $items = $list->toArray()['data'];
            $aids  = array_column($items, 'id');

            //表单名 + 每个留言的字段值（一次查完，别在 each 里查库）
            $formNames = Db::name('form')->whereIn('id', array_unique(array_column($items, 'form_id')))->column('form_name', 'id');
            $valueMap  = $this->messageValueMap($aids);

            foreach ($items as &$item) {
                $item['form_name'] = $formNames[(int) $item['form_id']] ?? '（表单已删除）';
                $item['fields']    = $valueMap[(int) $item['id']] ?? [];
                $item['date']      = $item['add_time'] > 0 ? date('Y-m-d H:i:s', (int) $item['add_time']) : '';
            }
            unset($item);

            //把加工后的数据塞回去，table_assign 直接吃
            $data = $list->toArray();
            $data['data'] = $items;
            return table_assign(0, '', $data);
        }

        //列表页顶部：表单下拉 + 三个状态的数量
        $formId = (int) ($param['form_id'] ?? 0);
        View::assign('forms', Db::name('form')->order('id asc')->select()->toArray());
        View::assign('form_id', $formId);
        View::assign('count_all', Db::name('form_message')->count());
        View::assign('count_unread', Db::name('form_message')->where('is_read', 0)->count());
        View::assign('count_star', Db::name('form_message')->where('is_star', 1)->count());
        return view();
    }

    /**
     * 标记已读 / 未读
     *
     * @return \think\Response
     */
    public function message_read()
    {
        $param = get_params();
        $ids   = $this->toIds($param['ids'] ?? ($param['id'] ?? 0));
        if (!$ids) {
            return to_assign(1, '请选择要操作的留言');
        }

        $read = (int) ($param['is_read'] ?? 1) === 1 ? 1 : 0;
        Db::name('form_message')->whereIn('id', $ids)->update([
            'is_read'     => $read,
            'update_time' => time(),
        ]);

        safe_add_log('edit', $ids[0], $param);
        clear_cache('formMessage');
        $unread = (int) Db::name('form_message')->where('is_read', 0)->count();
        return to_assign(0, $read ? '已标记为已读' : '已标记为未读', ['unread' => $unread]);
    }

    /**
     * 标记星标
     *
     * @return \think\Response
     */
    public function message_star()
    {
        $param = get_params();
        $id    = (int) ($param['id'] ?? 0);
        if ($id <= 0) {
            return to_assign(1, '缺少留言id');
        }

        $star = (int) ($param['is_star'] ?? 1) === 1 ? 1 : 0;
        Db::name('form_message')->where('id', $id)->update([
            'is_star'     => $star,
            'update_time' => time(),
        ]);

        safe_add_log('edit', $id, $param);
        return to_assign(0, $star ? '已加星标' : '已取消星标');
    }

    /**
     * 回复留言
     *
     * @return \think\Response
     */
    public function message_reply()
    {
        $param = get_params();
        $id    = (int) ($param['id'] ?? 0);
        $reply = trim((string) ($param['reply'] ?? ''));

        if ($id <= 0) {
            return to_assign(1, '缺少留言id');
        }
        if ($reply === '') {
            return to_assign(1, '回复内容不能为空');
        }
        if (mb_strlen($reply, 'UTF-8') > 1000) {
            return to_assign(1, '回复内容最多 1000 个字');
        }

        Db::name('form_message')->where('id', $id)->update([
            'reply'       => $reply,
            'admin_id'    => (int) get_login_admin('id'),
            'reply_time'  => time(),
            'is_read'     => 1,
            'update_time' => time(),
        ]);

        //插件钩子：留言回复
        \app\services\PluginService::formReply($id, $reply);

        safe_add_log('edit', $id, $param);
        clear_cache('formMessage');
        $unread = (int) Db::name('form_message')->where('is_read', 0)->count();
        return to_assign(0, '回复成功', ['unread' => $unread]);
    }

    /**
     * 审核留言
     *
     * @return \think\Response
     */
    public function message_examine()
    {
        $param   = get_params();
        $ids     = $this->toIds($param['ids'] ?? ($param['id'] ?? 0));
        $examine = (int) ($param['examine'] ?? 1);

        if (!$ids) {
            return to_assign(1, '请选择要审核的留言');
        }
        if (!in_array($examine, [1, 2], true)) {
            return to_assign(1, '审核结果只能是 1=通过 或 2=不通过');
        }

        Db::name('form_message')->whereIn('id', $ids)->update([
            'examine'     => $examine,
            'update_time' => time(),
        ]);

        //插件钩子：留言审核（参数：留言ID数组、审核结果）
        \app\services\PluginService::formExamine($ids, $examine);

        safe_add_log('edit', $ids[0], $param);
        return to_assign(0, $examine === 1 ? '已审核通过' : '已设为不通过');
    }

    /**
     * 删除留言（连同它的字段值）
     *
     * @return \think\Response
     */
    public function message_delete()
    {
        //破坏性操作只接受 DELETE（前端 tool.delete 发的就是 DELETE），防 GET 跳转 CSRF
        if (!request()->isDelete()) {
            return to_assign(1, '非法请求');
        }

        $param = get_params();
        $ids   = $this->toIds($param['ids'] ?? ($param['id'] ?? 0));
        if (!$ids) {
            return to_assign(1, '请选择要删除的留言');
        }

        //插件钩子：留言删除前（此时数据还在，插件能读到）
        \app\services\PluginService::formDelete($ids);

        Db::transaction(function () use ($ids) {
            Db::name('form_message')->whereIn('id', $ids)->delete();
            Db::name('form_message_attr')->whereIn('aid', $ids)->delete();
        });

        safe_add_log('delete', $ids[0], $param);
        clear_cache('formMessage');
        $unread = (int) Db::name('form_message')->where('is_read', 0)->count();
        return to_assign(0, '删除成功', ['unread' => $unread]);
    }

    /**
     * 一条留言的字段值（详情页用，全部字段）
     *
     * @param int $aid 留言id
     * @return array [['name'=>字段名, 'value'=>值], ...]
     */
    protected function messageFields(int $aid): array
    {
        $rows = Db::name('form_message_attr')->alias('a')
            ->field('a.attr_value, f.attr_name, f.attr_input_type')
            ->join('form_field f', 'f.id = a.attr_id', 'LEFT')
            ->where('a.aid', $aid)
            ->where('f.is_showlist', 1)
            ->order('f.sort_order desc, a.id asc')
            ->select()
            ->toArray();

        $list = [];
        foreach ($rows as $row) {
            $list[] = [
                'name'  => (string) ($row['attr_name'] ?? '（字段已删除）'),
                'value' => $this->displayValue((string) ($row['attr_value'] ?? ''), (int) ($row['attr_input_type'] ?? 0)),
            ];
        }

        return $list;
    }

    /**
     * 一批留言的字段值，按留言id分组（列表页用）
     *
     * @param array $aids 留言id
     * @return array [留言id => [['name'=>, 'value'=>], ...]]
     */
    protected function messageValueMap(array $aids): array
    {
        if (!$aids) {
            return [];
        }

        $rows = Db::name('form_message_attr')->alias('a')
            ->field('a.aid, a.attr_value, f.attr_name, f.attr_input_type')
            ->join('form_field f', 'f.id = a.attr_id', 'LEFT')
            ->whereIn('a.aid', $aids)
            ->where('f.is_showlist', 1)
            ->order('f.sort_order desc, a.id asc')
            ->select()
            ->toArray();

        $map = [];
        foreach ($rows as $row) {
            $map[(int) $row['aid']][] = [
                'name'  => (string) ($row['attr_name'] ?? '（字段已删除）'),
                'value' => $this->displayValue((string) ($row['attr_value'] ?? ''), (int) ($row['attr_input_type'] ?? 0)),
            ];
        }

        return $map;
    }

    /**
     * 字段值的显示形式
     *
     * 多选框存的是「值1|值2」，显示时换成「值1 / 值2」；时间类型存的是时间戳，转成日期时间。
     *
     * @param string $value
     * @param int    $type
     * @return string
     */
    protected function displayValue(string $value, int $type): string
    {
        if ($value === '') {
            return '';
        }

        if ($type === 4) {
            return implode(' / ', array_filter(explode('|', $value), function ($v) {
                return $v !== '';
            }));
        }
        if ($type === 10 && is_numeric($value)) {
            return date('Y-m-d H:i:s', (int) $value);
        }

        return $value;
    }

    /**
     * 把传进来的 id 统一成整型数组（支持 1 / '1,2,3' / [1,2,3]）
     *
     * @param mixed $ids
     * @return array
     */
    protected function toIds($ids): array
    {
        if (is_array($ids)) {
            $arr = $ids;
        } else {
            $arr = explode(',', (string) $ids);
        }

        $arr = array_filter(array_map('intval', $arr), function ($v) {
            return $v > 0;
        });

        return array_values(array_unique($arr));
    }

    /**
     * 「关联栏目」可选项：留言模型（clue）下的所有栏目
     *
     * 留言表单关联栏目时，只允许选择留言模型下的栏目。
     * 文章 / 产品那种是内容栏目，跟留言表单没关系。
     * 返回 [栏目id => 栏目行]，方便校验和取名字。
     *
     * @return array
     */
    protected function clueNavMap(): array
    {
        try {
            $channelId = (int) Db::name('channel')->where('nid', 'clue')->value('id');
            if ($channelId <= 0) {
                return [];
            }

            $rows = Db::name('nav')
                ->where('channel_id', $channelId)
                ->where('is_del', 0)
                ->order('sort asc, id asc')
                ->select()
                ->toArray();
        } catch (\Throwable $e) {
            //模型/栏目表异常时不要拦着表单页面打开，当成「没有可选项」
            return [];
        }

        $map = [];
        foreach ($rows as $row) {
            $map[(int) $row['id']] = $row;
        }

        return $map;
    }

    /**
     * 按 form_id 分组统计某张表的条数
     *
     * @param string $table
     * @param array  $ids
     * @param array  $extra 额外的 where 条件
     * @return array [form_id => 条数]
     */
    protected function countBy(string $table, array $ids, array $extra = []): array
    {
        if (!$ids) {
            return [];
        }

        $query = Db::name($table)->field('form_id, COUNT(*) AS c')->whereIn('form_id', $ids);
        foreach ($extra as $k => $v) {
            $query->where($k, $v);
        }

        $map = [];
        foreach ($query->group('form_id')->select()->toArray() as $row) {
            $map[(int) $row['form_id']] = (int) $row['c'];
        }

        return $map;
    }
}
