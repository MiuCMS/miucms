<?php
/**
 * @copyright Copyright (c) 2026 米柚工作室
 * @license https://opensource.org/licenses/Apache-2.0
 * @link https://www.miucms.com
 */

declare (strict_types = 1);

namespace app\admin\controller;

use app\admin\BaseController;
use app\admin\validate\KeywordsCheck;
use app\home\service\ContentI18n;
use think\exception\ValidateException;
use think\facade\Db;
use think\facade\View;

class Keywords extends BaseController
{
    public function index()
    {
        if (request()->isAjax()) {
            $param = get_params();
            $where = array();
            if (!empty($param['keywords'])) {
                $where[] = ['k.title', 'like', '%' . $param['keywords'] . '%'];
            }
            //按分组筛选：空字符串=全部分组；0=未分组；其它=分组id
            if (isset($param['group_id']) && $param['group_id'] !== '') {
                $where[] = ['k.group_id', '=', (int) $param['group_id']];
            }
            $where[] = ['k.status', '>=', 0];
            $rows = empty($param['limit']) ? get_config('app.page_size') : $param['limit'];

            //排序：字段走白名单（不能拿用户输入直接拼 SQL），默认按发布时间倒序。
            //一律带 id 兜底 —— 标签的 add_time 大量并列（一批批加的），不带兜底的话
            //并列行的顺序由 MySQL 说了算，改任意一行都可能让它们换位置
            $allowFields = [
                'id'       => 'k.id',
                'sort'     => 'k.sort',
                'status'   => 'k.status',
                'add_time' => 'k.add_time',
            ];
            $orderField = $allowFields[(string) ($param['field'] ?? '')] ?? 'k.add_time';
            $orderDir   = strtolower((string) ($param['order'] ?? '')) === 'asc' ? 'asc' : 'desc';
            $orderSql   = $orderField . ' ' . $orderDir;
            if ($orderField !== 'k.id') {
                $orderSql .= ',k.id ' . $orderDir;
            }

            //左连分组表把分组名带出来（没归组的 group_title 是空的，列表里显示「未分组」）
            $content = Db::name('Keywords')
                ->alias('k')
                ->join('KeywordsGroup g', 'k.group_id = g.id', 'left')
                ->field('k.*,g.title AS group_title')
                ->order($orderSql)
                ->where($where)
                ->paginate(['list_rows'=> $rows]);

            //每行带一个「文档数」：当前页这几个标签一次查出来，别一个标签查一次
            $counts = $this->docCountByTag(array_column($content->items(), 'id'));

            $data = $content->toArray();
            foreach ($data['data'] as &$row) {
                $row['doc_count'] = $counts[(int) $row['id']] ?? 0;
            }
            unset($row);

            return table_assign(0, '', $data);
        } else {
            View::assign('groups', $this->groupList());
            $tagListActions = [];
            \app\services\PluginService::tagListAction($tagListActions);
            $tagListActions = array_values(array_filter($tagListActions, static function ($action) {
                return is_array($action)
                    && preg_match('/^[A-Za-z][A-Za-z0-9_-]{0,39}$/', (string) ($action['event'] ?? ''))
                    && trim((string) ($action['title'] ?? '')) !== '';
            }));
            foreach ($tagListActions as &$action) {
                $action['title'] = htmlspecialchars((string) $action['title'], ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
                $class = trim((string) ($action['class'] ?? 'layui-btn-primary'));
                $action['class'] = preg_match('/^[A-Za-z0-9 _-]{1,100}$/', $class) ? $class : 'layui-btn-primary';
            }
            unset($action);
            View::assign('tagListActions', $tagListActions);
            View::assign('keywordsActionWidth', $tagListActions ? 190 : 100);

            $listType = 'tag';
            $listToolbarActions = [];
            \app\services\PluginService::adminListToolbarAction($listType, $listToolbarActions);
            $listToolbarActions = array_values(array_filter($listToolbarActions, static function ($action) {
                return is_array($action)
                    && preg_match('/^[A-Za-z][A-Za-z0-9_-]{0,39}$/', (string) ($action['event'] ?? ''))
                    && trim((string) ($action['title'] ?? '')) !== '';
            }));
            foreach ($listToolbarActions as &$action) {
                $action['title'] = htmlspecialchars((string) $action['title'], ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
                $class = trim((string) ($action['class'] ?? 'layui-btn-primary'));
                $action['class'] = preg_match('/^[A-Za-z0-9 _-]{1,100}$/', $class) ? $class : 'layui-btn-primary';
                $icon = trim((string) ($action['icon'] ?? ''));
                $action['icon'] = preg_match('/^(?:layui-icon-[a-z0-9-]+|miuai-[A-Za-z0-9_-]+)$/', $icon) ? $icon : '';
            }
            unset($action);
            View::assign('listToolbarActions', $listToolbarActions);
            View::assign('listToolbarType', $listType);
            return view();
        }
    }

    //分组下拉用的列表（排序规则和分组列表页保持一致）
    private function groupList()
    {
        return Db::name('KeywordsGroup')->order('sort desc,id asc')->select()->toArray();
    }

    //添加
    public function add()
    {
        $param = get_params();
        if (request()->isAjax()) {
            $this->requireRequestMethod();
            $translations = is_array($param['translations'] ?? null) ? $param['translations'] : [];
            unset($param['translations']);
            //分组下拉里的「未分组」提交上来是空字符串，落库得变成 0，否则 int 字段会报错
            $param['group_id'] = (int) ($param['group_id'] ?? 0);
            if (!empty($param['id']) && $param['id'] > 0) {
                $tagId = (int) $param['id'];
                try {
                    validate(KeywordsCheck::class)->scene('edit')->check($param);
                } catch (ValidateException $e) {
                    // 验证失败 输出错误信息
                    return to_assign(1, $e->getError());
                }
                $param['update_time'] = time();
                if (ContentI18n::enabledLocales()) {
                    ContentI18n::ensureSchema();
                }
                try {
                    \app\services\PluginService::autoTranslatePosted('tag', $translations, $param);
                } catch (\Throwable $e) {
                    return to_assign(1, '自动翻译失败，标签未保存：' . $e->getMessage());
                }
                $res = Db::transaction(function () use ($param, $translations, $tagId) {
                    $updated = Db::name('Keywords')->strict(false)->field(true)->update($param);
                    if ($updated !== false) {
                        ContentI18n::savePosted('tag', $tagId, $translations, '', 0, $param);
                    }
                    return $updated;
                });
                if ($res !== false) {
                    safe_add_log('edit', $param['id'], $param);
                    //前台 {miu:tag} 标签列表缓存（见 app/home/common.php 的 miu_tag_list()）
                    clear_cache('homeTag');
                    $status = (int) Db::name('Keywords')->where('id', $tagId)->value('status');
                    static_update_after_tags([$tagId], $status === 1 ? [] : [$tagId]);
                    sitemap_auto_update();
                }

                return to_assign();
            } else {
                try {
                    validate(KeywordsCheck::class)->scene('add')->check($param);
                } catch (ValidateException $e) {
                    // 验证失败 输出错误信息
                    return to_assign(1, $e->getError());
                }
                $param['add_time'] = time();
                if (ContentI18n::enabledLocales()) {
                    ContentI18n::ensureSchema();
                }
                try {
                    \app\services\PluginService::autoTranslatePosted('tag', $translations, $param);
                } catch (\Throwable $e) {
                    return to_assign(1, '自动翻译失败，标签未保存：' . $e->getMessage());
                }
                $insertId = Db::transaction(function () use ($param, $translations) {
                    $newId = Db::name('Keywords')->strict(false)->field(true)->insertGetId($param);
                    if ($newId) {
                        ContentI18n::savePosted('tag', (int) $newId, $translations, '', 0, $param);
                    }
                    return $newId;
                });
                if ($insertId) {
                    safe_add_log('add', $insertId, $param);
                    //前台 {miu:tag} 标签列表缓存
                    clear_cache('homeTag');
                    static_update_after_tags([(int) $insertId]);
                    sitemap_auto_update();
                }

                return to_assign();
            }
        }
		else{
			$id = isset($param['id']) ? $param['id'] : 0;
			$group_id = 0;
			if ($id > 0) {
				$keywords = Db::name('Keywords')->where(['id' => $id])->find();
				View::assign('keywords', $keywords);
				$group_id = (int) ($keywords['group_id'] ?? 0);
			}
			View::assign('i18nRows', ContentI18n::formRows((int) $id, [
				['entity_type' => 'tag', 'name' => 'title', 'label' => '标签名称', 'type' => 'input'],
			]));
			View::assign('id', $id);
			//所属分组（新增时是 0 = 未分组；下拉里靠它回显选中项）
			View::assign('group_id', $group_id);
			//分组下拉
			View::assign('groups', $this->groupList());
			return view();
		}
    }

    /**
     * 删除（单个 / 批量都走这里）
     *
     * 单个：传 id=1
     * 批量：传 ids=1,2,3（或 ids[]=1&ids[]=2）
     */
    public function delete()
    {
        if (!request()->isDelete()) {
            return to_assign(1, '错误的请求');
        }

        $param = get_params();
        $ids   = $this->paramIds($param['ids'] ?? ($param['id'] ?? ''));
        if (!$ids) {
            return to_assign(1, '参数错误');
        }

        //标签下面还挂着文章的，一个都不删 —— 删了那些文章的 tag_ids 就指向一个不存在的标签了
        $used = array_filter($this->docCountByTag($ids)); //只看有文章的
        if ($used) {
            $names = Db::name('Keywords')->whereIn('id', array_keys($used))->column('title', 'id');
            $tips  = [];
            foreach ($used as $tid => $n) {
                $tips[] = '「' . ($names[$tid] ?? $tid) . '」(' . $n . ' 篇)';
            }
            return to_assign(1, '这些标签下面还有文章，不能删除：' . implode('、', $tips));
        }

        $res = Db::name('Keywords')->whereIn('id', $ids)->update([
            'status'      => -1,
            'update_time' => time(),
        ]);
        if ($res !== false) {
            ContentI18n::deleteRecords('tag', $ids);
            safe_add_log('delete', implode(',', $ids), ['ids' => $ids]);
            //前台 {miu:tag} 标签列表缓存
            clear_cache('homeTag');
            static_update_after_tags($ids, $ids);
            sitemap_auto_update();
            return to_assign(0, '删除成功');
        }
        return to_assign(1, '删除失败');
    }

    /**
     * 批量移动标签到指定分组
     */
    public function move()
    {
        $this->requireRequestMethod();
        if (!request()->isAjax()) {
            return to_assign(1, '错误的请求');
        }

        $param = get_params();
        $ids   = $this->paramIds($param['ids'] ?? ($param['id'] ?? ''));
        if (!$ids) {
            return to_assign(1, '请先选择要移动的标签');
        }

        //0 = 未分组
        $gid = (int) ($param['group_id'] ?? 0);
        if ($gid > 0 && !Db::name('KeywordsGroup')->where('id', $gid)->find()) {
            return to_assign(1, '要移动到的分组不存在');
        }

        $res = Db::name('Keywords')->whereIn('id', $ids)->update([
            'group_id'    => $gid,
            'update_time' => time(),
        ]);
        if ($res !== false) {
            safe_add_log('edit', implode(',', $ids), ['ids' => $ids, 'group_id' => $gid]);
            clear_cache('homeTag');
            static_update_after_tags($ids);
            sitemap_auto_update();
            return to_assign(0, '已移动 ' . count($ids) . ' 个标签');
        }
        return to_assign(1, '移动失败');
    }

    /**
     * 列表里直接改某个字段（参考文档列表的 edit_field()）
     *
     * 列表里「分组」列（下拉）、「排序」列（点格子直接输）、「状态」列（复选框）都走这里。
     * 字段走白名单，防止前端伪造字段名去改别的列。
     */
    public function edit_field()
    {
        $this->requireRequestMethod();
        if (!request()->isAjax()) {
            return to_assign(1, '错误的请求');
        }

        $param = get_params();

        $allowFields = ['group_id', 'sort', 'status'];
        $field       = (string) ($param['field'] ?? '');
        if (!in_array($field, $allowFields, true)) {
            return to_assign(1, '参数错误');
        }

        $ids = $this->paramIds($param['id'] ?? '');
        if (!$ids) {
            return to_assign(1, '参数错误');
        }

        $value = (int) ($param['value'] ?? 0);

        //分组：0 = 未分组；其它值必须是真实存在的分组
        if ($field === 'group_id' && $value > 0 && !Db::name('KeywordsGroup')->where('id', $value)->find()) {
            return to_assign(1, '要移动到的分组不存在');
        }
        //状态只有 正常(1) / 禁用(0) 两种
        if ($field === 'status') {
            $value = $value === 1 ? 1 : 0;
        }

        $res = Db::name('Keywords')->whereIn('id', $ids)->update([
            $field        => $value,
            'update_time' => time(),
        ]);
        if ($res !== false) {
            safe_add_log('edit', implode(',', $ids), [$field => $value]);
            clear_cache('homeTag');
            static_update_after_tags($ids, $field === 'status' && $value !== 1 ? $ids : []);
            sitemap_auto_update();
            return to_assign(0, '操作成功');
        }
        return to_assign(1, '操作失败');
    }

    /**
     * 批量操作传上来的 id：数组（ids[]=1&ids[]=2）和逗号串（1,2）都认
     */
    private function paramIds($val): array
    {
        $arr = is_array($val) ? $val : explode(',', (string) $val);
        $ids = [];
        foreach ($arr as $v) {
            $v = (int) $v;
            if ($v > 0) {
                $ids[] = $v;
            }
        }
        return array_values(array_unique($ids));
    }

    /**
     * 这些标签各挂着多少篇（未删除的）文章：标签id => 文章数
     *
     * 文档和标签的关联是 cms_document.tag_ids（「,1,2,」这种），所以用 FIND_IN_SET，
     * 一次把传进来的标签都数出来。没有文章的标签也会带上，值是 0。
     *
     * @param array $ids
     * @return array
     */
    private function docCountByTag(array $ids): array
    {
        if (!$ids) {
            return [];
        }

        $rows = Db::query(
            'SELECT k.id, COUNT(d.id) AS c'
            . ' FROM ' . Db::name('Keywords')->getTable() . ' k'
            . ' LEFT JOIN ' . Db::name('Document')->getTable() . ' d'
            . ' ON FIND_IN_SET(k.id, d.tag_ids) AND d.is_del = 0'
            . ' WHERE k.id IN (' . implode(',', $ids) . ')'
            . ' GROUP BY k.id'
        );

        $out = [];
        foreach ($rows as $r) {
            $out[(int) $r['id']] = (int) $r['c'];
        }
        return $out;
    }

    /* ==================== 标签分组 ==================== */

    /**
     * 分组列表（「分组管理」按钮弹出来的那一页）
     *
     * 每行多带一个 keywords_count（这个分组下的标签数），列表里显示出来，
     * 也让人一眼看出哪些分组删不掉（分组下还有标签就不让删）。
     */
    public function group()
    {
        if (request()->isAjax()) {
            $param = get_params();
            $where = array();
            if (!empty($param['keywords'])) {
                $where[] = ['title', 'like', '%' . $param['keywords'] . '%'];
            }
            $rows = empty($param['limit']) ? get_config('app.page_size') : $param['limit'];

            $content = Db::name('KeywordsGroup')
                ->where($where)
                ->order('sort desc,id asc')
                ->paginate(['list_rows' => $rows]);

            //当前页这几个分组各有几个标签，一次查出来（别一个分组查一次）
            //status = -1 是已删除的标签，不算在内
            $ids    = array_column($content->items(), 'id');
            $counts = [];
            if ($ids) {
                $cntRows = Db::name('Keywords')
                    ->field('group_id,COUNT(*) AS c')
                    ->where('group_id', 'in', $ids)
                    ->where('status', '>=', 0)
                    ->group('group_id')
                    ->select()
                    ->toArray();
                foreach ($cntRows as $r) {
                    $counts[(int) $r['group_id']] = (int) $r['c'];
                }
            }

            $data = $content->toArray();
            foreach ($data['data'] as &$row) {
                $row['keywords_count'] = $counts[(int) $row['id']] ?? 0;
            }
            unset($row);

            return table_assign(0, '', $data);
        } else {
            return view();
        }
    }

    /**
     * 分组 添加 / 编辑
     */
    public function group_add()
    {
        $param = get_params();
        if (request()->isAjax()) {
            $this->requireRequestMethod();
            $param['title'] = trim((string) ($param['title'] ?? ''));
            if ($param['title'] === '') {
                return to_assign(1, '分组名称不能为空');
            }

            //同名分组只留一个（编辑时把自己排除掉）
            $dup = Db::name('KeywordsGroup')->where('title', $param['title']);
            if (!empty($param['id'])) {
                $dup->where('id', '<>', (int) $param['id']);
            }
            if ($dup->count() > 0) {
                return to_assign(1, '同样的分组名称已经存在');
            }

            if (!empty($param['id']) && $param['id'] > 0) {
                $param['update_time'] = time();
                if (Db::name('KeywordsGroup')->strict(false)->field(true)->update($param) !== false) {
                    safe_add_log('edit', $param['id'], $param);
                    return to_assign();
                }
                return to_assign(1, '操作失败');
            }

            $param['add_time'] = time();
            $gid = Db::name('KeywordsGroup')->strict(false)->field(true)->insertGetId($param);
            if ($gid) {
                safe_add_log('add', $gid, $param);
                return to_assign();
            }
            return to_assign(1, '操作失败');
        } else {
            $id = isset($param['id']) ? (int) $param['id'] : 0;
            if ($id > 0) {
                View::assign('group', Db::name('KeywordsGroup')->where(['id' => $id])->find());
            }
            View::assign('id', $id);
            return view();
        }
    }

    /**
     * 分组 删除
     */
    public function group_delete()
    {
        if (!request()->isDelete()) {
            return to_assign(1, '错误的请求');
        }

        $id = (int) get_params('id');
        if ($id < 1) {
            return to_assign(1, '参数错误');
        }
        if (!Db::name('KeywordsGroup')->where(['id' => $id])->find()) {
            return to_assign(1, '分组不存在');
        }

        //分组下还有标签就不让删，否则那些标签的 group_id 会指向一个不存在的分组
        $count = Db::name('Keywords')->where('group_id', $id)->where('status', '>=', 0)->count();
        if ($count > 0) {
            return to_assign(1, '该分组下还有 ' . $count . ' 个标签，请先把它们移走或删除');
        }

        if (Db::name('KeywordsGroup')->delete($id) !== false) {
            safe_add_log('delete', $id, []);
            return to_assign(0, '删除成功');
        }
        return to_assign(1, '删除失败');
    }
}
