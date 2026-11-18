<?php
/**
 * @copyright Copyright (c) 2026 米柚工作室
 * @license https://opensource.org/licenses/Apache-2.0
 * @link https://www.miucms.com
 */

declare (strict_types = 1);

namespace app\admin\controller;

use app\admin\BaseController;
use app\admin\validate\customCheck;
use app\home\service\ContentI18n;
use think\exception\ValidateException;
use think\facade\Db;
use think\facade\View;

/**
 * 自定义变量（cms_custom）
 *
 * 一条记录就是一个「按名字取值」的变量：模板/程序里用 name 取它的值（默认值），
 * type 决定它在后台表单里长什么样（单行文本 / 多行文本 / 上传图片 / 开关 / 多媒体）。
 *
 * type 存的是英文标识，跟 cms_channel_field 的 type 用同一套词，方便两边复用渲染逻辑：
 *   text      单行文本
 *   multitext 多行文本
 *   img       上传图片
 *   switch    开关类型
 *   media     多媒体类型
 *
 * 删除是逻辑删除：把 status 置成 -1（列表里查的是 status >= 0）。
 */
class Custom extends BaseController
{
    /**
     * 变量类型 => 中文名
     */
    public static $typeMap = [
        'text'      => '单行文本',
        'multitext' => '多行文本',
        'img'       => '上传图片',
        'switch'    => '开关类型',
        'media'     => '多媒体类型',
    ];

    /**
     * 文本类变量（只有这两种需要「最大长度」）
     */
    protected static $textTypes = ['text', 'multitext'];

    /**
     * 列表
     */
    public function index()
    {
        if (request()->isAjax()) {
            $param = get_params();
            $where = [];
            if (!empty($param['keywords'])) {
                $where[] = ['name|title', 'like', '%' . $param['keywords'] . '%'];
            }
            //删除是把 status 置成 -1，列表不显示
            $where[] = ['status', '>=', 0];

            $rows = empty($param['limit']) ? get_config('app.page_size') : $param['limit'];
            $list = Db::name('Custom')
                ->where($where)
                ->order('sort asc,id asc')
                ->paginate(['list_rows' => $rows])
                ->each(function ($item) {
                    $item['type_title']    = self::$typeMap[$item['type']] ?? $item['type'];
                    $item['add_time_text'] = $item['add_time'] ? date('Y-m-d H:i:s', $item['add_time']) : '';
                    $actions = [];
                    \app\services\PluginService::customListAction((array) $item, $actions);
                    $actions = array_values(array_filter($actions, static function ($action) {
                        return is_array($action)
                            && preg_match('/^[A-Za-z][A-Za-z0-9_-]{0,39}$/', (string) ($action['event'] ?? ''))
                            && trim((string) ($action['title'] ?? '')) !== '';
                    }));
                    $safeActions = [];
                    foreach ($actions as $action) {
                        $action['title'] = htmlspecialchars((string) $action['title'], ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
                        $class = trim((string) ($action['class'] ?? 'layui-btn-primary'));
                        $action['class'] = preg_match('/^[A-Za-z0-9 _-]{1,100}$/', $class) ? $class : 'layui-btn-primary';
                        $safeActions[] = $action;
                    }
                    $item['list_actions'] = $safeActions;
                    return $item;
                });

            return table_assign(0, '', $list);
        }

        return view();
    }

    /**
     * 添加 / 编辑（同一个方法，带了 id 就是编辑）
     */
    public function add()
    {
        $param = get_params();

        if (request()->isAjax()) {
            $isEdit = !empty($param['id']) && $param['id'] > 0;

            try {
                validate(customCheck::class)->scene($isEdit ? 'edit' : 'add')->check($param);
            } catch (ValidateException $e) {
                return to_assign(1, $e->getError());
            }

            $data = $this->handleParams($param);

            if ($isEdit) {
                $data['id']          = (int) $param['id'];
                $data['update_time'] = time();
                //strict(false) + field(true)：只更新传上来的字段，表里没有的字段忽略
                $res = Db::name('Custom')->strict(false)->field(true)->update($data);
                if ($res !== false) {
                    safe_add_log('edit', $data['id'], $data);
                }
                //前台的自定义变量走了持久缓存，改完必须清一下，否则前台还是旧值
                clear_cache('custom');
                return to_assign();
            }

            $data['add_time']    = time();
            $data['update_time'] = time();
            //define（字段定义）这一版页面没用上，但表里是 NOT NULL 且没有默认值，
            //新增时必须给个值，不然 MySQL 严格模式会报「doesn't have a default value」
            $data['define'] = '';

            $insertId = Db::name('Custom')->strict(false)->field(true)->insertGetId($data);
            if ($insertId) {
                safe_add_log('add', $insertId, $data);
            }
            clear_cache('custom');
            return to_assign();
        }

        $id = (int) ($param['id'] ?? 0);
        if ($id > 0) {
            $custom = Db::name('Custom')->where('id', $id)->find();
            if (!$custom) {
                return $this->error('记录不存在或已删除');
            }
        } else {
            //新增：给一份默认值，模板里的 {$custom.xxx} 和 {if} 判断才不会报未定义
            $custom = [
                'id'            => 0,
                'name'          => '',
                'title'         => '',
                'type'          => 'text',
                'maxlength'     => 250,
                'default_value' => '',
                'tips'          => '',
                'sort'          => 0,
                'status'        => 1,
            ];
        }

        View::assign('id', $id);
        View::assign('custom', $custom);
        View::assign('type_map', self::$typeMap);
        return view();
    }

    /**
     * 删除（逻辑删除：status 置 -1）
     */
    public function delete()
    {
        if (!request()->isDelete()) {
            return to_assign(1, '错误的请求方式');
        }
        $id    = get_params('id');
        $ids = is_array($id) ? $id : explode(',', (string) $id);
        $ids = array_values(array_unique(array_filter(array_map('intval', $ids), static function ($id) {
            return $id > 0;
        })));
        if (!$ids) {
            return to_assign(1, '参数错误');
        }
        $logId = implode(',', $ids);

        $res = Db::transaction(function () use ($ids) {
            $updated = Db::name('Custom')->whereIn('id', $ids)->update(['status' => -1, 'update_time' => time()]);
            if ($updated !== false) {
                ContentI18n::deleteRecords('custom', $ids);
            }
            return $updated;
        });

        if ($res !== false) {
            safe_add_log('delete', $logId);
            //前台的自定义变量走了持久缓存，删完必须清一下，否则前台还是旧数据
            clear_cache('custom');
            return to_assign(0, '删除成功');
        }

        return to_assign(1, '删除失败');
    }
    //修改字段（支持批量，用于审核/置顶/推荐/内联编辑等）
    public function edit_field()
    {
        $param = get_params();
        if (request()->isAjax()) {
            // 字段白名单，防止前端伪造字段名注入任意字段（按需增删）
            $allowFields = [ 'title', 'default_value', 'sort'];
            $field = isset($param['field']) ? $param['field'] : '';
            $value = isset($param['value']) ? $param['value'] : '';
            // 兼容单条与批量：id 可能是数字、字符串或数组
            $ids = isset($param['id']) ? $param['id'] : 0;
            $ids = is_array($ids) ? $ids : explode(',', (string)$ids);
            $ids = array_values(array_filter(array_map('intval', $ids)));

            if (!in_array($field, $allowFields, true) || empty($ids)) {
                return to_assign(1, '参数错误');
            }
            $ids = implode(',', $ids);
            // 链式调用保证 where 生效（模型静态 update 会新建模型丢失条件，且数组主键会触发 INSERT 或报错）
            $res = Db('custom')->where('id', 'in', $ids)->update([$field => $value]);
            if ($res != false) {
                safe_add_log('edit', $ids, [$field => $value]);
                clear_cache('custom');
                return to_assign(0, "修改成功");
            }
            return to_assign(1, '操作失败');
        }
    }
    /**
     * 整理提交上来的参数
     *
     * 页面上的控件是按类型切换的（「默认值」那块会换控件），这里统一收口：
     * 数字字段强转、状态只留 0/1、文本类型必须给最大长度。
     *
     * @param array $param
     * @return array
     */
    protected function handleParams(array $param): array
    {
        $type = (string) ($param['type'] ?? 'text');

        $data = [
            'name'          => trim((string) ($param['name'] ?? '')),
            'title'         => trim((string) ($param['title'] ?? '')),
            'type'          => isset(self::$typeMap[$type]) ? $type : 'text',
            'tips'          => mb_substr(trim((string) ($param['tips'] ?? '')), 0, 256, 'UTF-8'),
            'sort'          => (int) ($param['sort'] ?? 0),
            'status'        => ((int) ($param['status'] ?? 0) === 1) ? 1 : 0,
            'maxlength'     => (int) ($param['maxlength'] ?? 0),
            'default_value' => (string) ($param['default_value'] ?? ''),
        ];

        if (in_array($data['type'], self::$textTypes, true)) {
            //文本类型必须给最大长度（表注释也是这么写的），没填或填 0 就给个默认 250
            if ($data['maxlength'] < 1) {
                $data['maxlength'] = 250;
            }
            if ($data['maxlength'] > 65535) {
                $data['maxlength'] = 65535;
            }
        } else {
            //图片/开关/多媒体用不到最大长度
            $data['maxlength'] = 0;
        }

        //开关类型的默认值只能是 0/1
        if ($data['type'] === 'switch') {
            $data['default_value'] = ($data['default_value'] === '1') ? '1' : '0';
        }

        return $data;
    }
}
