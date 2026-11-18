<?php
/**
 * @copyright Copyright (c) 2026 米柚工作室
 * @license https://opensource.org/licenses/Apache-2.0
 * @link https://www.miucms.com
 */

declare (strict_types = 1);

namespace app\admin\controller;

use app\admin\BaseController;
use app\admin\validate\NavCheck;
use app\home\service\ContentI18n;
use think\exception\ValidateException;
use think\facade\Db;
use think\facade\View;

class Nav extends BaseController
{

    //管理导航
    public function index()
    {
        $param = get_params();
        if (request()->isAjax()) {
            //回收站里的栏目不在列表里显示
            $where=[['n.is_del', '=', 0]];
            $nav = Db::name('Nav')->alias('n')->join('channel c','n.channel_id=c.id')->leftJoin('form f','n.id=f.nav_id')->where($where)->field('n.*,c.title channel_title,c.nid,f.id form_id')->order('n.sort asc,n.id asc')->select();
			$list = generateTree($nav);
            //dump($list);die;
            return to_assign(0, '', $list);
        } else {
            $navListActions = [];
            \app\services\PluginService::navListAction($navListActions);
            $navListActions = array_values(array_filter($navListActions, static function ($action) {
                return is_array($action)
                    && preg_match('/^[A-Za-z][A-Za-z0-9_-]{0,39}$/', (string) ($action['event'] ?? ''))
                    && trim((string) ($action['title'] ?? '')) !== '';
            }));
            foreach ($navListActions as &$action) {
                $action['title'] = htmlspecialchars((string) $action['title'], ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
                $class = trim((string) ($action['class'] ?? 'layui-btn-primary'));
                $action['class'] = preg_match('/^[A-Za-z0-9 _-]{1,100}$/', $class) ? $class : 'layui-btn-primary';
            }
            unset($action);
            View::assign('navListActions', $navListActions);
            $listType = 'nav';
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

    //添加导航
    public function add()
    {
        $param = get_params();
        if (request()->isAjax()) {
            $translations = is_array($param['translations'] ?? null) ? $param['translations'] : [];
            unset($param['translations']);
            try {
                if (ContentI18n::enabledLocales()) {
                    ContentI18n::ensureSchema();
                }
                $param['add_time'] = time();
                //校验并整理参数（栏目名称、别名、外链、编辑器正文）
                $param = $this->handle_params($param);

                //插件钩子：栏目添加前（插件可修改 $param）
                \app\services\PluginService::navAdd($param);

                try {
                    \app\services\PluginService::autoTranslatePosted('nav', $translations, $param);
                } catch (\Throwable $e) {
                    return to_assign(1, '自动翻译失败，栏目未保存：' . $e->getMessage());
                }

                $navId = Db::transaction(function () use ($param, $translations) {
                    $id = Db::name('Nav')->strict(false)->insertGetId($param);
                    if ($id) {
                        ContentI18n::savePosted('nav', (int) $id, $translations, '', 0, $param);
                    }
                    return $id;
                });

                //插件钩子：栏目添加后
                \app\services\PluginService::navAdded((int) $navId, $param);
            } catch (ValidateException $e) {
                // 验证失败 输出错误信息
                return to_assign(1, $e->getError());
            }
            // 删除导航缓存
            clear_cache('homeNav');
            safe_add_log('add', "", $param);
            static_update_after_nav([(int) $navId], [], [(int) ($param['pid'] ?? 0)]);
            //勾了「Sitemap → 自动更新」就重建地图（失败静默，不影响添加）
            sitemap_auto_update();
            return to_assign();
		} else {
            $id = isset($param['id']) ? $param['pid'] : 0;
			View::assign('id', $id);
            View::assign('navs', set_recursion(get_navs()));
            View::assign('channel', Db::name('Channel')->where('status',1)->order('add_time asc')->select());
            View::assign('channel_id', Db::name('nav')->where('id',$id)->value('channel_id'));
            //各模型可用的栏目模板 / 内容模板（前端按选中的模型切换下拉选项）
            View::assign('tpl_options', $this->getTemplateOptions());
            $this->assignNavI18nFields(0);
			return view();
		}
    }
    //编辑导航
    public function edit()
    {
        $param = get_params();
        if (request()->isAjax()) {
            $translations = is_array($param['translations'] ?? null) ? $param['translations'] : [];
            unset($param['translations']);
            $navId = (int) ($param['id'] ?? 0);
            $snapshot = $navId > 0 ? static_capture_nav_snapshot([$navId]) : [];
            $oldPid = $navId > 0 ? (int) Db::name('Nav')->where('id', $navId)->value('pid') : 0;
            $oldChannelId = $navId > 0 ? (int) Db::name('Nav')->where('id', $navId)->value('channel_id') : 0;
            $param['update_time'] = time();
            //切换模型时清除旧模型模板，由参数处理逻辑写入新模型默认模板。
//            if(isset($param['channel_id'])){
//                unset($param['channel_id']);
//            }
            try {
                if (ContentI18n::enabledLocales()) {
                    ContentI18n::ensureSchema();
                }
                if (isset($param['channel_id']) && (int) $param['channel_id'] !== $oldChannelId) {
                    unset($param['temp_list'], $param['temp_view']);
                }
                //校验并整理参数（栏目名称、别名、外链、编辑器正文）
                $param = $this->handle_params($param);

                //插件钩子：栏目编辑前（插件可修改 $param）
                \app\services\PluginService::navEdit($param);

                try {
                    \app\services\PluginService::autoTranslatePosted('nav', $translations, $param);
                } catch (\Throwable $e) {
                    return to_assign(1, '自动翻译失败，栏目未保存：' . $e->getMessage());
                }

                Db::transaction(function () use ($param, $translations) {
                    Db::name('Nav')->strict(false)->field(true)->update($param);
                    ContentI18n::savePosted('nav', (int) ($param['id'] ?? 0), $translations, '', 0, $param);
                    //插件钩子：栏目编辑后
                    \app\services\PluginService::navEdited((int) ($param['id'] ?? 0), $param);
                });
            } catch (ValidateException $e) {
                // 验证失败 输出错误信息
                return to_assign(1, $e->getError());
            }
            // 删除导航缓存
            clear_cache('homeNav');
            safe_add_log('edit', $param['id'], $param);
            $newPid = (int) Db::name('Nav')->where('id', $navId)->value('pid');
            static_update_after_nav([$navId], $snapshot, [$newPid ?: (int) ($param['pid'] ?? $oldPid)]);
            //勾了「Sitemap → 自动更新」就重建地图（失败静默，不影响保存）
            sitemap_auto_update();
            return to_assign();

		} else {
            $id = isset($param['id']) ? $param['id'] : 0;
            $where['id'] = $id;
            $nav = Db::name('Nav')->where($where)->find();
            View::assign('nav', $nav);
            $pid = $nav['pid'];
            $id = $id;
			View::assign('id', $id);
			View::assign('pid', $pid);
            View::assign('navs', set_recursion(get_navs()));

            View::assign('channel', Db::name('Channel')->where('status',1)->order('add_time asc')->select());
            View::assign('channel_id', Db::name('nav')->where('id',$id)->value('channel_id'));
            //根据当前栏目的模型 nid 初始化模板选项。
            $nid = Db::name('Channel')->where('id', $nav['channel_id'])->value('nid');
            View::assign('nid', $nid);
            View::assign('tpl_options', $this->getTemplateOptions());
            $this->assignNavI18nFields((int) $id);
            return view();
		}
    }
    //编辑导航内容
    public function content()
    {
        $param = get_params();
        if (request()->isAjax()) {
            $docId = filter_var($param['id'] ?? null, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
            if ($docId === false) {
                return to_assign(1, '参数错误');
            }
            $param['id'] = $docId;
            $translations = is_array($param['translations'] ?? null) ? $param['translations'] : [];
            unset($param['translations']);
            $snapshot = static_capture_nav_snapshot([$docId]);
            $param['update_time'] = time();
            //模型保存后不允许更改，直接丢掉这个字段
            if(isset($param['channel_id'])){
                unset($param['channel_id']);
            }
            try {
                if (ContentI18n::enabledLocales()) {
                    ContentI18n::ensureSchema();
                }
                //校验并整理参数（编辑器正文）
                $param = $this->handle_content($param);
                //写入附加表
                $param['doc_id'] = $docId;
                unset($param['id']);
                $channel_id=Db::name('nav')->where('id','=',$docId)->value('channel_id');
                $tableName=Db::name('channel')->where('id','=',$channel_id)->value('table');
                $channelId = (int) $channel_id;
                if ($channelId < 1 || !is_string($tableName) || $tableName === '') {
                    return to_assign(1, '栏目所属内容模型无效，无法保存内容');
                }
                \app\services\PluginService::autoTranslatePosted('nav_model', $translations, $param, $tableName, $channelId);
                Db::transaction(function () use ($tableName, $docId, $param, $translations, $channelId) {
                    $exists = Db::name($tableName)->where('doc_id', $docId)->find();
                    if ($exists) {
                        $res = Db::name($tableName)->strict(false)->field(true)->where('doc_id', $docId)->update($param);
                    } else {
                        $res = Db::name($tableName)->strict(false)->field(true)->insert($param);
                    }
                    if ($res === false) {
                        throw new \Exception('更新失败！检查数据表');
                    }
                    Db::name('Nav')->where('id', $docId)->update(['update_time' => time()]);
                    ContentI18n::savePosted('nav_model', $docId, $translations, (string) $tableName, $channelId, $param);
                });

            } catch (ValidateException $e) {
                // 验证失败 输出错误信息
                return to_assign(1, $e->getError());
            } catch (\Throwable $e) {
                return to_assign(1, '保存失败：' . $e->getMessage());
            }
            // 删除导航缓存
            clear_cache('homeNav');
            safe_add_log('edit', $docId, $param);
            static_update_after_nav([(int) $docId], $snapshot);
            sitemap_auto_update();
            return to_assign();

		} else {
            $id = isset($param['id']) ? $param['id'] : 0;
            $where['id'] = $id;
            $nav = Db::name('Nav')->where($where)->find();
            $nid=Db::name('channel')->where(['id'=>$nav['channel_id']])->value('nid');
            View::assign('nid', $nid);
            //单页、留言模型：回显栏目内容和自定义字段
            if($nid == 'single' || $nid == 'clue'){
                $editor = (string) get_system_config('editor');
                View::assign('editor', $editor === '2' ? '2' : '1');
                View::assign('otherCFG', get_system_config());
                //模型自定义字段
                $customfields=Db::name('channel_field')->where(['channel_id'=>$nav['channel_id'],'status'=>1,'is_system'=>0])->order('sort asc,id asc')->select()->toArray();
                View::assign('customfields', $customfields);
                $channel_id=$nav['channel_id'];
                $tableName=get_content_table($channel_id);
                $fieldStr='doc_id id,content,md_content';
                $field=Db::name('channelField')->where(['channel_id'=>$channel_id,'is_system'=>0,'status'=>1])->order('sort asc,id asc')->column('name');
                if($field){
                    $field = array_map(function($item){
                        return $item;
                    }, $field);
                    $fieldStr=$fieldStr.','.implode(',',$field);
                }
                $nav_single=Db::name($tableName)->where('doc_id','=',$nav['id'])->field($fieldStr)->find();
                if($nav_single){
                    $nav=array_merge($nav,$nav_single);
                }
                View::assign('i18nRows', ContentI18n::formRows((int) $nav['id'], ContentI18n::modelFieldDefinitions('nav_model', $tableName, (int) $channel_id)));
                //旧内容没有 Markdown 源码时，用现有正文初始化编辑器，避免打开后内容为空
                $markdownContent = (string) ($nav['md_content'] ?? '');
                View::assign('markdown_content', $markdownContent !== '' ? $markdownContent : (string) ($nav['content'] ?? ''));
            }

            if ($nid !== 'single' && $nid !== 'clue') {
                View::assign('i18nRows', []);
            }

            View::assign('info', $nav);
            $id = $id;
			View::assign('id', $id);

			return view();
		}
    }


    //删除
    public function delete()
    {
        if (!request()->isDelete()) {
            return to_assign(1, '错误的请求');
        }
        $id = get_params('id');
        $ids = is_array($id) ? $id : explode(',', (string) $id);
        $ids = array_values(array_unique(array_filter(array_map('intval', $ids), static function ($value) { return $value > 0; })));
        if (!$ids) {
            return to_assign(1, '参数错误');
        }
        $snapshot = static_capture_nav_snapshot($ids);
        $navRows = Db::name('Nav')->field('id,channel_id')->whereIn('id', $ids)->select()->toArray();
        //批量删除时 id 是数组，日志表 param_id 是整型，先拼成字符串
        $logId = implode(',', $ids);
        //子栏目还在（回收站里的不算）时不允许删父栏目
        if(Db::name('Nav')->where('pid', 'in', $ids)->where('is_del', 0)->find()){
            return to_assign(1, '该导航下面存在子导航，请先删除子导航');
        }
        //栏目下还有文档时不允许删。回收站里的文档也算，否则删完栏目，
        //那些文档的 nav_id 就指向一个不存在的栏目了
        $docCount = Db::name('Document')->where('nav_id', 'in', $ids)->count();
        if ($docCount > 0) {
            return to_assign(1, '该栏目下还有 ' . $docCount . ' 篇文档，请先删除或移动这些文档');
        }

        //插件钩子：栏目删除前（此时栏目还在，插件能读到）
        \app\services\PluginService::navDelete($id);

        if (is_recycle_on()) {
            //回收站开启 → 逻辑删除，可在栏目回收站里恢复
            $res = Db::name('Nav')->where('id', 'in', $ids)->update(['is_del' => 1, 'delete_time' => time()]);
        } else {
            //回收站关闭 → 直接物理删除
            $res = Db::transaction(function () use ($ids, $navRows) {
                $deleted = Db::name('Nav')->where('id', 'in', $ids)->delete();
                if ($deleted !== false) {
                    $this->deleteNavTranslations($navRows);
                }
                return $deleted;
            });
        }

        if ($res !== false) {
            //清除导航缓存
            clear_cache('homeNav');
            safe_add_log('delete', $logId, []);
            static_update_after_nav($ids, $snapshot);
            //勾了「Sitemap → 自动更新」就重建地图（失败静默，不影响删除）
            sitemap_auto_update();
            return to_assign(0, '删除成功');
        } else {
            return to_assign(1, '删除失败');
        }
    }

    /**
    * 栏目回收站
    */
    public function recycle()
    {
        if (request()->isAjax()) {
            //只查回收站里的栏目（is_del=1）；父级可能也被删了，所以平铺返回、不做成树
            $list = Db::name('Nav')->alias('n')
                ->join('channel c', 'n.channel_id = c.id')
                ->where('n.is_del', 1)
                ->field('n.id,n.pid,n.title,n.title_en,n.delete_time,c.title as channel_title')
                ->order('n.delete_time desc,n.id desc')
                ->select()
                ->toArray();
            return to_assign(0, '', $list);
        }
        return view();
    }

    /**
    * 从回收站恢复栏目（is_del 置回 0）
    */
    public function restore()
    {
        if (!request()->isPost()) {
            return to_assign(1, '错误的请求');
        }
        $id = get_params('id');
        $ids = is_array($id) ? $id : explode(',', (string) $id);
        $ids = array_values(array_unique(array_filter(array_map('intval', $ids), static function ($value) { return $value > 0; })));
        $snapshot = static_capture_nav_snapshot($ids);
        //批量恢复时 id 是数组，日志表 param_id 是整型，先拼成字符串
        $logId = implode(',', $ids);
        if ($ids && Db::name('Nav')->where('id', 'in', $ids)->update(['is_del' => 0, 'delete_time' => 0]) !== false) {
            //清除导航缓存
            clear_cache('homeNav');
            safe_add_log('recovery', $logId, []);
            $parentIds = Db::name('Nav')->whereIn('id', $ids)->column('pid');
            static_update_after_nav($ids, $snapshot, array_map('intval', $parentIds));
            sitemap_auto_update();
            return to_assign(0, '恢复成功');
        } else {
            return to_assign(1, '恢复失败');
        }
    }

    /**
    * 彻底删除栏目（从回收站物理删除，删了找不回来）
    */
    public function purge()
    {
        if (!request()->isPost()) {
            return to_assign(1, '错误的请求');
        }
        $id = get_params('id');
        $ids = is_array($id) ? $id : explode(',', (string) $id);
        $ids = array_values(array_unique(array_filter(array_map('intval', $ids), static function ($value) { return $value > 0; })));
        if (!$ids) {
            return to_assign(1, '参数错误');
        }
        //批量删除时 id 是数组，日志表 param_id 是整型，先拼成字符串
        $logId = implode(',', $ids);
        //下面还有未删除的子栏目时不允许彻底删除，否则子栏目会挂到不存在的父级上
        if (Db::name('Nav')->where('pid', 'in', $ids)->where('is_del', 0)->find()) {
            return to_assign(1, '该栏目下面还有未删除的子栏目，请先处理子栏目');
        }
        //栏目下还有文档时同样不允许，否则文档的 nav_id 会指向一个不存在的栏目
        $docCount = Db::name('Document')->where('nav_id', 'in', $ids)->count();
        if ($docCount > 0) {
            return to_assign(1, '该栏目下还有 ' . $docCount . ' 篇文档，请先删除或移动这些文档');
        }
        $navRows = Db::name('Nav')->field('id,channel_id')->whereIn('id', $ids)->select()->toArray();
        $deleted = Db::transaction(function () use ($ids, $navRows) {
            $result = Db::name('Nav')->whereIn('id', $ids)->delete();
            if ($result !== false) {
                $this->deleteNavTranslations($navRows);
            }
            return $result;
        });
        if ($deleted !== false) {
            //清除导航缓存
            clear_cache('homeNav');
            safe_add_log('delete', $logId, []);
            return to_assign(0, '已彻底删除');
        } else {
            return to_assign(1, '删除失败');
        }
    }

    /**
    * 清空栏目回收站（把回收站里的栏目全部物理删除，不可恢复）
    */
    public function clear()
    {
        if (!request()->isPost()) {
            return to_assign(1, '错误的请求');
        }
        //回收站里的栏目
        $ids = Db::name('Nav')->where('is_del', 1)->column('id');
        if (empty($ids)) {
            return to_assign(1, '回收站已经是空的');
        }
        //下面还有未删除子栏目的先跳过，否则子栏目会挂到不存在的父级上（和 purge 的守卫一致）
        $skipped = array_unique(Db::name('Nav')->where('pid', 'in', $ids)->where('is_del', 0)->column('pid'));
        //栏目下还有文档的也跳过，否则文档的 nav_id 会指向一个不存在的栏目
        $skipped = array_unique(array_merge($skipped, Db::name('Document')->where('nav_id', 'in', $ids)->column('nav_id')));
        $canDelete = array_diff($ids, $skipped);
        if (empty($canDelete)) {
            return to_assign(1, '回收站里的栏目下面还有未删除的子栏目或文档，请先处理');
        }
        $navRows = Db::name('Nav')->field('id,channel_id')->whereIn('id', $canDelete)->select()->toArray();
        Db::transaction(function () use ($canDelete, $navRows) {
            Db::name('Nav')->whereIn('id', $canDelete)->delete();
            $this->deleteNavTranslations($navRows);
        });
        //清除导航缓存
        clear_cache('homeNav');
        safe_add_log('delete', 0, ['count' => count($canDelete)]);
        $msg = '已清空回收站，共删除 ' . count($canDelete) . ' 个栏目';
        if (!empty($skipped)) {
            $msg .= '；另有 ' . count($skipped) . ' 个栏目下面还有未删除的子栏目或文档，已跳过';
        }
        return to_assign(0, $msg);
    }
    //修改字段
    public function edit_field()
    {
        $param = get_params();
        if (request()->isAjax()) {
            $field=$param['field'];
            $value=$param['value'];
            $navId=(int) ($param['id'] ?? 0);
            $snapshot=static_capture_nav_snapshot([$navId]);
            $data[$field]=$value;
            $data['update_time']=time();
            $data['id']=$navId;
            $res=Db::name('Nav')->save($data);
            if ($res) {
                safe_add_log('edit', $param['id'], $data);
                clear_cache('homeNav');
                $newPid=(int) Db::name('Nav')->where('id',$navId)->value('pid');
                static_update_after_nav([$navId],$snapshot,[$newPid]);
                sitemap_auto_update();
                return to_assign(0, "操作成功");
            }else{
                return to_assign(1, "操作失败");
            }
        }
    }

    /** 为栏目编辑表单准备当前模板启用语言的栏目字段。 */
    private function assignNavI18nFields(int $navId): void
    {
        $fields = [
            ['entity_type' => 'nav', 'name' => 'title', 'label' => '栏目标题', 'type' => 'input'],
            ['entity_type' => 'nav', 'name' => 'title_ext_1', 'label' => '扩展标题1', 'type' => 'input'],
            ['entity_type' => 'nav', 'name' => 'title_ext_2', 'label' => '扩展标题2', 'type' => 'input'],
            ['entity_type' => 'nav', 'name' => 'title_ext_3', 'label' => '扩展标题3', 'type' => 'input'],
            ['entity_type' => 'nav', 'name' => 'seo_title', 'label' => 'SEO标题', 'type' => 'input'],
            ['entity_type' => 'nav', 'name' => 'seo_keywords', 'label' => 'SEO关键词', 'type' => 'input'],
            ['entity_type' => 'nav', 'name' => 'seo_description', 'label' => 'SEO描述', 'type' => 'textarea', 'rows' => 4],
        ];
        View::assign('i18nRows', ContentI18n::formRows($navId, $fields));
    }

    /** 栏目物理删除时同步清理栏目和单页正文翻译。 */
    private function deleteNavTranslations(array $navRows): void
    {
        $navIds = [];
        $singleIdsByTable = [];
        foreach ($navRows as $row) {
            $id = (int) ($row['id'] ?? 0);
            $channelId = (int) ($row['channel_id'] ?? 0);
            if ($id < 1) {
                continue;
            }
            $navIds[] = $id;
            $channel = $channelId > 0 ? Db::name('channel')->where('id', $channelId)->find() : null;
            if ($channel && (string) $channel['nid'] === 'single') {
                $table = (string) $channel['table'];
                if ($table !== '') {
                    $singleIdsByTable[$table][] = $id;
                }
            }
        }
        ContentI18n::deleteRecords('nav', $navIds);
        foreach ($singleIdsByTable as $table => $ids) {
            ContentI18n::deleteRecords('nav_model', $ids, $table);
        }
    }

    /**
     * 按模型 nid 收集可用的栏目模板 / 内容模板
     *
     * 栏目模板：list_<nid>.html、list_<nid>_*.html
     * 内容模板：view_<nid>.html、view_<nid>_*.html（单页模型 single 没有内容模板）
     *
     * 只扫描「当前前台模板」目录，模板名严格按命名规则匹配，其它文件不会出现在下拉里。
     *
     * @return array [nid => ['list' => [...], 'view' => [...]]]
     */
    protected function getTemplateOptions(): array
    {
        $dir   = get_templates_path(get_current_template());
        $map   = [];
        $nids  = Db::name('Channel')->where('status', 1)->column('nid');

        foreach ($nids as $nid) {
            $nid = (string) $nid;
            if ($nid === '') {
                continue;
            }
            $map[$nid] = [
                'list' => $this->scanTemplates($dir, 'list_' . $nid),
                //单页模型只有栏目模板，没有内容模板
                'view' => $nid === 'single' ? [] : $this->scanTemplates($dir, 'view_' . $nid),
            ];
        }

        return $map;
    }

    /**
     * 扫描模板目录里符合前缀的模板文件（返回不带 .html 的模板名）
     *
     * 前缀本身（list_article.html）和带下划线后缀的（list_article_自定义.html）都算
     *
     * @param string $dir    模板目录
     * @param string $prefix 如 list_article
     * @return array
     */
    protected function scanTemplates(string $dir, string $prefix): array
    {
        $list = [];
        if (!is_dir($dir)) {
            return $list;
        }

        //注意：$name 已经去掉了 .html 后缀，所以正则里不要再带 .html
        $pattern = '/^' . preg_quote($prefix, '/') . '(_[A-Za-z0-9_\-]+)?$/';
        foreach (glob($dir . '*.html') ?: [] as $file) {
            $name = basename($file, '.html');
            if (preg_match($pattern, $name)) {
                $list[] = $name;
            }
        }
        sort($list);

        return $list;
    }

    /**
     * 校验并整理表单参数（新增和编辑共用）
     *
     * 栏目名称、栏目别名、外链校验这几段两个方法是完全一样的，统一挪到这里；
     * 编辑器正文的「远程图片本地化」「删除非站内链接」也在这里处理。
     * 校验不通过时抛 ValidateException，由调用方捕获后返回给前端。
     *
     * @param  array $param 表单参数
     * @return array 整理后的表单参数
     * @throws ValidateException
     */
    protected function handle_params(array $param)
    {
        //栏目名称必填
        if (!$param['title']) {
            throw new ValidateException('栏目名称不能为空');
        }

        //栏目别名：填了必须是拼音或数字且以字母开头；留空则按栏目名称生成
        $param['title_en'] = (string) ($param['title_en'] ?? '');
        if (mb_strlen($param['title_en']) > 0) {
            if ($this->isLanguageIdentifierSlug($param['title_en'])) {
                throw new ValidateException('栏目目录名称不能使用语言标识，请更换目录名称');
            }
            if (!preg_match('/^[a-z][a-z0-9]{0,64}$/', $param['title_en'])) {
                throw new ValidateException('必须是拼音或数字，以字母开头');
            }
        } else {
            $param['title_en'] = pinyin($param['title']);
            if (preg_match('/^\d/', $param['title'])) {
                $param['title_en'] = set_salt_letter(3) . $param['title_en'];
            }
        }

        //自动生成的拼音也要校验，避免栏目名称本身恰好得到语言代码
        if ($this->isLanguageIdentifierSlug($param['title_en'])) {
            throw new ValidateException('栏目目录名称不能使用语言标识，请更换栏目名称或目录名称');
        }

        //别名最长 64 位
        if (mb_strlen($param['title_en']) > 64) {
            $param['title_en'] = substr($param['title_en'], 0, 64);
        }

        //别名重复时追加随机后缀；编辑时要把自己排除掉
        $query = Db::name('Nav')->where('title_en', $param['title_en']);
        if (!empty($param['id'])) {
            $query->where('id', '<>', $param['id']);
        }
        if ($query->find()) {
            $param['title_en'] = pinyin($param['title'], true);
        }

        //重复别名的自动改写也再次校验，确保任何保存路径都不能绕过语言标识检查
        if ($this->isLanguageIdentifierSlug($param['title_en'])) {
            throw new ValidateException('栏目目录名称不能使用语言标识，请更换栏目名称或目录名称');
        }

        //勾了外链就必须填地址
        if ($param['is_url'] == 1 && $param['url'] == '') {
            throw new ValidateException('请填写外链地址');
        }

        //所属模型 nid：新增时看提交的 channel_id，编辑时 channel_id 不在表单里，取原来那行的
        $channelId = !empty($param['channel_id'])
            ? (int) $param['channel_id']
            : (int) Db::name('Nav')->where('id', (int) ($param['id'] ?? 0))->value('channel_id');
        $nid = $channelId ? (string) Db::name('Channel')->where('id', $channelId)->value('nid') : '';

        //模板名只允许 list_xxx / view_xxx 这种写法，其它值当作没填
        foreach (['temp_list' => 'list', 'temp_view' => 'view'] as $field => $type) {
            $tpl = trim((string) ($param[$field] ?? ''));
            if ($tpl === '' || !preg_match('/^' . $type . '_[A-Za-z0-9_\-]{1,60}$/', $tpl)) {
                $tpl = '';
            }
            $param[$field] = $tpl;
        }

        //没选模板就写默认值进库：栏目模板 list_<模型nid>、内容模板 view_<模型nid>
        //（字段里存的是模板名，不带 .html，跟下拉选项的值一致）
        if ($nid !== '') {
            if ($param['temp_list'] === '') {
                $param['temp_list'] = 'list_' . $nid;
            }
            if ($nid === 'single') {
                //单页模型没有内容模板，清空
                $param['temp_view'] = '';
            } elseif ($param['temp_view'] === '') {
                $param['temp_view'] = 'view_' . $nid;
            }
        }

        return $param;
    }

    /**
     * 判断栏目目录名是否与可能使用的语言标识冲突。
     *
     * 同时读取 ThinkPHP / 应用语言包、所有前台模板语言包、语言配置、后台切换器，
     * 并在 intl 扩展可用时读取 ICU 的 locale 清单。分隔符会忽略，因此 en-US、en_US
     * 对应的 enus 也会保留，避免以后新增语言时与栏目路径冲突。
     */
    protected function isLanguageIdentifierSlug(string $value): bool
    {
        static $reserved = null;
        if ($reserved === null) {
            $reserved = [];
            $addLocale = static function ($locale) use (&$reserved): void {
                if (!is_scalar($locale)) {
                    return;
                }

                $locale = strtolower(trim(str_replace('_', '-', (string) $locale)));
                if (!preg_match('/^[a-z]{2,3}(?:-[a-z0-9]{2,8})*$/', $locale)) {
                    return;
                }

                //完整标识、去分隔符形式和主语言码都保留
                $reserved[$locale] = true;
                $reserved[str_replace('-', '', $locale)] = true;
                $primary = explode('-', $locale, 2)[0];
                $reserved[$primary] = true;
            };

            //项目应用语言包（例如 app/home/lang/en-us.php）
            foreach (glob(root_path() . 'app' . DIRECTORY_SEPARATOR . '*' . DIRECTORY_SEPARATOR . 'lang' . DIRECTORY_SEPARATOR . '*.php') ?: [] as $file) {
                $addLocale(pathinfo($file, PATHINFO_FILENAME));
            }

            //所有模板语言包，包含尚未启用的模板，避免以后切换模板时发生路径冲突
            foreach (glob(get_templates_path() . '*' , GLOB_ONLYDIR) ?: [] as $templateDir) {
                foreach (['json', 'php'] as $extension) {
                    foreach (glob($templateDir . DIRECTORY_SEPARATOR . 'lang' . DIRECTORY_SEPARATOR . '*.' . $extension) ?: [] as $file) {
                        $addLocale(pathinfo($file, PATHINFO_FILENAME));
                    }
                }
            }

            //ThinkPHP 多语言配置中的默认语言、允许语言和 Accept-Language 映射
            $langConfigFile = root_path() . 'config' . DIRECTORY_SEPARATOR . 'lang.php';
            if (is_file($langConfigFile)) {
                try {
                    $langConfig = include $langConfigFile;
                    if (is_array($langConfig)) {
                        $addLocale($langConfig['default_lang'] ?? '');
                        foreach ((array) ($langConfig['allow_lang_list'] ?? []) as $locale) {
                            $addLocale($locale);
                        }
                        foreach ((array) ($langConfig['accept_language'] ?? []) as $source => $target) {
                            $addLocale($source);
                            $addLocale($target);
                        }
                        foreach ((array) ($langConfig['extend_list'] ?? []) as $locale) {
                            $addLocale($locale);
                        }
                    }
                } catch (\Throwable $e) {
                    //语言配置读取失败时继续使用已发现的语言包清单
                }
            }

            //后台语言切换器公开的语言代码也作为保留项
            $adminI18nFile = public_path() . 'static' . DIRECTORY_SEPARATOR . 'admin' . DIRECTORY_SEPARATOR . 'i18n.js';
            if (is_file($adminI18nFile)) {
                $source = @file_get_contents($adminI18nFile);
                if (is_string($source) && preg_match('/\bLOCALES\s*=\s*\[([^\]]*)\]/s', $source, $match)) {
                    if (preg_match_all('/[\'\"]([A-Za-z0-9_-]+)[\'\"]/', $match[1], $localeMatches)) {
                        foreach ($localeMatches[1] as $locale) {
                            $addLocale($locale);
                        }
                    }
                }
            }

            //如果 PHP 安装了 intl，补充 ICU 支持的全部 locale，而不是只保护当前几种语言
            if (class_exists('ResourceBundle')) {
                try {
                    foreach (\ResourceBundle::getLocales('') as $locale) {
                        $addLocale($locale);
                    }
                } catch (\Throwable $e) {
                    //intl 清单不可用时，仍有项目语言包和配置作为依据
                }
            }
        }

        $slug = strtolower(trim($value));
        $compact = str_replace(['-', '_'], '', $slug);
        return isset($reserved[$slug]) || isset($reserved[$compact]);
    }

    /**
     * 校验并整理表单参数（新增和编辑共用）
     *
     * 栏目名称、栏目别名、外链校验这几段两个方法是完全一样的，统一挪到这里；
     * 编辑器正文的「远程图片本地化」「删除非站内链接」也在这里处理。
     * 校验不通过时抛 ValidateException，由调用方捕获后返回给前端。
     *
     * @param  array $param 表单参数
     * @return array 整理后的表单参数
     * @throws ValidateException
     */
    protected function handle_content(array $param)
    {
        //编辑器带出来的临时字段，不入库
        if (isset($param['editor-container'])) {
            unset($param['editor-container']);
        }

        // Markdown 编辑器提交的 HTML 和源码分别保存到正文与 Markdown 字段
        if (isset($param['docContent-html-code'])) {
            $param['content'] = $param['docContent-html-code'];
            $param['md_content'] = $param['docContent-markdown-doc'] ?? '';
            unset($param['docContent-html-code'], $param['docContent-markdown-doc']);
        } elseif (isset($param['content'])) {
            // 切换到普通编辑器后清空旧 Markdown 源码，避免前后台显示不一致
            $param['md_content'] = '';
        }

        //正文按文档编辑相同规则净化，防止单页内容保存不安全 HTML
        $param = \app\admin\model\Document::purifyContent($param);

        //远程图片本地化：页面有提交状态时尊重页面选择；未提交时沿用网站配置。
        $downloadImages = array_key_exists('editor_img_download_to_local', $param)
            ? (string) $param['editor_img_download_to_local'] === '1'
            : (string) get_system_config('editor_img_download_to_local') === '1';
        if ($downloadImages && !empty($param['content'])) {
            $param['content'] = download_web_images($param['content']);
        }

        //删除非站内链接：正文里指向外站的 <a> 标签去掉，只保留文字
        if (isset($param['editor_img_clear_link']) && $param['editor_img_clear_link'] == 1) {
            if (!empty($param['content'])) {
                $param['content'] = clear_outbound_links($param['content']);
            }
        }

        //这两个是编辑器面板上的临时开关，nav 表里没有对应字段，别带进 SQL
        unset($param['editor_img_download_to_local'], $param['editor_img_clear_link']);

        return $param;
    }
}
