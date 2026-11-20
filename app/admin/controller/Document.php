<?php
/**
 * @copyright Copyright (c) 2026 米柚工作室
 * @license https://opensource.org/licenses/Apache-2.0
 * @link https://www.miucms.com
 */
 
declare (strict_types = 1);

namespace app\admin\controller;

use think\App;
use app\admin\BaseController;
use app\admin\model\Document as DocumentModel;
use app\admin\validate\DocumentValidate;
use app\home\service\ContentI18n;
use think\exception\ValidateException;
use HTMLPurifier;
use HTMLPurifier_Config;
use think\facade\Db;
use think\facade\View;
use think\facade\Cache;

class Document extends BaseController{
	/**
     * 构造函数
     */
	protected $uid;
	protected $model;
    public function __construct(App $app)
    {
        parent::__construct($app);
        $this->model = new DocumentModel();
		$this->uid = get_login_admin('id');

    }
    /**
     * 取某个栏目及其所有子孙栏目的 id（递归）
     * 列表按栏目筛选时用它把子栏目的文档一起带出来
     * @param  int $navId
     * @return array
     */
    protected function getNavIdsWithChildren($navId)
    {
        $navId    = (int) $navId;
        $navIds   = array_column(get_data_node(get_navs()->toArray(), $navId), 'id');
        $navIds[] = $navId;
        return $navIds;
    }

    /**
    * 数据列表
    */
    public function index()
    {
        $param = get_params();
        if (request()->isAjax()) {
			$where = [];
			if (!empty($param['keywords'])) {
                $where[] = ['a.id|a.title|a.desc|c.name', 'like', '%' . $param['keywords'] . '%'];
            }
            if (!empty($param['nav_id'])) {
                //选中栏目时，连同它下面所有子栏目的文档一起查出来
                $where[] = ['a.nav_id', 'in', $this->getNavIdsWithChildren($param['nav_id'])];
            }
            // 属性筛选（is_head / is_recommend / is_special / is_jump）
            if (!empty($param['flag'])) {
                $flagFields = ['is_head', 'is_recommend', 'is_special', 'is_jump'];
                if (in_array($param['flag'], $flagFields)) {
                    $where[] = ['a.' . $param['flag'], '=', 1];
                }
            }
            // 创建时间范围
            if (!empty($param['add_time_start'])) {
                $startTime = strtotime($param['add_time_start']);
                if ($startTime) {
                    $where[] = ['a.add_time', '>=', $startTime];
                }
            }
            if (!empty($param['add_time_end'])) {
                $endTime = strtotime($param['add_time_end']);
                if ($endTime) {
                    $where[] = ['a.add_time', '<=', $endTime];
                }
            }
            $where[] = ['a.status', '=', 1];
            $where[] = ['a.is_del', '=', 0];
            $list = $this->model->getDocumentList($where, $param);
            //给每行补一个「前台详情页地址」（操作栏的「浏览」按钮用）。
            //规则和前台 doc_url() 是同一份，跟着后台「SEO → URL配置」的 URL 模式走
            $rows = $list->toArray();
            foreach ($rows['data'] as &$row) {
                $row['url'] = doc_url($row);
            }
            unset($row);
            return table_assign(0, '', $rows);
        }
        else{
            if(isset($param['nav_id']) && !empty($param['nav_id']) && !is_numeric($param['nav_id'])){
                throw new \think\exception\HttpException(403, '参数错误');
            }
            $user_level = Cache::remember('user_level', function () {
                return Db::name('user_level')
                    ->where(['status' => 1])
                    ->order('id asc')
                    ->select()
                    ->toArray();
            }, 3600 * 24);
            View::assign('user_level', $user_level);
            //带出模型 nid：左侧栏目树里要判断单页栏目（nid=single）
            $navs_for_add=generateTree(get_navs_without(0, true));
            View::assign('navs_for_add', $navs_for_add);
            View::assign('hasAddNavs', !empty($navs_for_add));
            $navs=generateTree(get_navs(0,true));
            View::assign('navs', $navs);
            $navsSearch=set_recursion(get_navs_without(0, true));
            View::assign('navsSearch', $navsSearch);
            $documentListActions = [];
            \app\services\PluginService::documentListAction($documentListActions);
            $documentListActions = array_values(array_filter($documentListActions, static function ($action) {
                return is_array($action)
                    && preg_match('/^[A-Za-z][A-Za-z0-9_-]{0,39}$/', (string) ($action['event'] ?? ''))
                    && trim((string) ($action['title'] ?? '')) !== '';
            }));
            View::assign('documentListActions', $documentListActions);
            View::assign('documentActionWidth', $documentListActions ? 200 : 120);
            $listType = 'document';
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
    /**
    * 选择节点文档
    */
    public function index_lite()
    {
        $param = get_params();
        if (request()->isAjax()) {
			$where[] = ['a.channel_id','<>',6];
			if (!empty($param['keywords'])) {
                $where[] = ['a.id|a.title|a.seo_description|c.name', 'like', '%' . $param['keywords'] . '%'];
            }
            if (!empty($param['nav_id'])) {
                //选中栏目时，连同它下面所有子栏目的文档一起查出来
                $where[] = ['a.nav_id', 'in', $this->getNavIdsWithChildren($param['nav_id'])];
            }
            // 属性筛选（is_head / is_recommend / is_special / is_jump）
            if (!empty($param['flag'])) {
                $flagFields = ['is_head', 'is_recommend', 'is_special', 'is_jump'];
                if (in_array($param['flag'], $flagFields)) {
                    $where[] = ['a.' . $param['flag'], '=', 1];
                }
            }
            // 创建时间范围
            if (!empty($param['add_time_start'])) {
                $startTime = strtotime($param['add_time_start']);
                if ($startTime) {
                    $where[] = ['a.add_time', '>=', $startTime];
                }
            }
            if (!empty($param['add_time_end'])) {
                $endTime = strtotime($param['add_time_end']);
                if ($endTime) {
                    $where[] = ['a.add_time', '<=', $endTime];
                }
            }
            $where[] = ['a.is_del', '=', 0];
            $list = $this->model->getDocumentList($where, $param);
            return table_assign(0, '', $list);
        }
        else{
            if(isset($param['nav_id']) && !empty($param['nav_id']) && !is_numeric($param['nav_id'])){
                throw new \think\exception\HttpException(403, '参数错误');
            }
            $user_level = Cache::remember('user_level', function () {
                return Db::name('user_level')
                    ->where(['status' => 1])
                    ->order('id asc')
                    ->select()
                    ->toArray();
            }, 3600 * 24);
            View::assign('user_level', $user_level);
            $navs=generateTree(get_navs());
            View::assign('navs', $navs);
            $navsSearch=set_recursion(get_navs());
            View::assign('navsSearch', $navsSearch);
            return view();
        }
    }
    /**
    * 文档回收站
    */
    public function recycle()
    {
        $param = get_params();
        if (request()->isAjax()) {
            $where = [];
            //回收站只看已删除的
            $where[] = ['a.is_del', '=', 1];
            if (!empty($param['keywords'])) {
                $where[] = ['a.id|a.title|a.seo_description|c.name', 'like', '%' . $param['keywords'] . '%'];
            }
            if (!empty($param['nav_id'])) {
                //选中栏目时，连同它下面所有子栏目的文档一起查出来
                $where[] = ['a.nav_id', 'in', $this->getNavIdsWithChildren($param['nav_id'])];
            }
            // 属性筛选（is_head / is_recommend / is_special / is_jump）
            if (!empty($param['flag'])) {
                $flagFields = ['is_head', 'is_recommend', 'is_special', 'is_jump'];
                if (in_array($param['flag'], $flagFields)) {
                    $where[] = ['a.' . $param['flag'], '=', 1];
                }
            }
            // 创建时间范围
            if (!empty($param['add_time_start'])) {
                $startTime = strtotime($param['add_time_start']);
                if ($startTime) {
                    $where[] = ['a.add_time', '>=', $startTime];
                }
            }
            if (!empty($param['add_time_end'])) {
                $endTime = strtotime($param['add_time_end']);
                if ($endTime) {
                    $where[] = ['a.add_time', '<=', $endTime];
                }
            }
            //第 3 个参数传 false：回收站列表要能看到 delete_time 不为 0 的行
            $list = $this->model->getDocumentList($where, $param);
            return table_assign(0, '', $list);
        }

        $navs=generateTree(get_navs());
        View::assign('navs', $navs);
        $navsSearch=set_recursion(get_navs());
        View::assign('navsSearch', $navsSearch);
        return view();
    }

    /**
    * 草稿箱（status=0 的未发布文档，可发布或彻底删除）
    */
    public function draft()
    {
        $param = get_params();
        if (request()->isAjax()) {
            $where = [
                ['a.status', '=', 0],
                ['a.is_del', '=', 0],
            ];
            if (!empty($param['keywords'])) {
                $where[] = ['a.id|a.title|a.seo_description|c.name', 'like', '%' . $param['keywords'] . '%'];
            }
            if (!empty($param['nav_id'])) {
                $where[] = ['a.nav_id', 'in', $this->getNavIdsWithChildren($param['nav_id'])];
            }
            if (!empty($param['flag'])) {
                $flagFields = ['is_head', 'is_recommend', 'is_special', 'is_jump'];
                if (in_array($param['flag'], $flagFields)) {
                    $where[] = ['a.' . $param['flag'], '=', 1];
                }
            }
            if (!empty($param['add_time_start'])) {
                $startTime = strtotime($param['add_time_start']);
                if ($startTime) {
                    $where[] = ['a.add_time', '>=', $startTime];
                }
            }
            if (!empty($param['add_time_end'])) {
                $endTime = strtotime($param['add_time_end']);
                if ($endTime) {
                    $where[] = ['a.add_time', '<=', $endTime];
                }
            }
            $list = $this->model->getDocumentList($where, $param);
            return table_assign(0, '', $list);
        }

        $navsSearch=set_recursion(get_navs());
        View::assign('navsSearch', $navsSearch);
        return view();
    }
    public function add()
    {
        $param = get_params();
        if (request()->isAjax()) {
            $this->requireRequestMethod();
			$param['admin_id'] = $this->uid;
			$param['channel_id'] = Db::name('nav')->where('id','=',$param['nav_id'])->value('channel_id');
            // 检验完整性
            try {
                validate(DocumentValidate::class)->check($param);
            } catch (ValidateException $e) {
                // 验证失败 输出错误信息
                return to_assign(1, $e->getError());
            }

            $insertId = $this->model->addDocument($param);
            if ($insertId) {
                //文档本身、模型附加字段、标签文档数及各类文档列表共用 Document 标记，发布后统一失效。
                clear_cache('Document');
                //按后台「发布/编辑文档」的勾选清对应缓存（首页 / 相关栏目 / 上下篇）
                clear_doc_list_cache([
                    'nav_id'     => $param['nav_id'] ?? 0,
                    'channel_id' => $param['channel_id'] ?? 0,
                ]);
                //开了「Sitemap → 自动更新」就起个后台进程重建地图（失败静默，不影响发布）
                sitemap_auto_update();
                //开启了百度自动推送时，在独立进程推送本篇文档，避免百度接口延迟阻塞发布
                sitemap_auto_push_document((int) $insertId);
                //生成静态模式下，按勾选重新生成受影响的静态页面（失败静默，不影响发布）
                $savedDoc = Db::name('document')->field('id,nav_id,tag_ids')->where('id', $insertId)->find() ?: [];
                static_update_after_doc($savedDoc, 'pub');
                return to_assign(0,'发布成功',['id'=>$insertId]);
            }else{
                return to_assign(1,'发布失败');
            }
        }else{
            $user_level = Cache::remember('user_level', function () {
                return Db::name('user_level')->where(['status' => 1])->order('id asc')->select()->toArray();
            }, 3600 * 24);
			View::assign('user_level', $user_level);
            if(!isset($param['nav_id']) || empty($param['nav_id'])){
                return $this->error('参数错误');
            }
            $nav = Db::name('nav')->where('id','=',$param['nav_id'])->find();
            $channel_id=$nav['channel_id'];
            $this->assignI18nFields(0, (int) $channel_id);
            View::assign('navs', set_recursion(get_navs(0, true)));
            View::assign('channel_id_curr', (int) $channel_id);
            View::assign('nav_id_curr', $param['nav_id']);
			$editor = (string) get_system_config('editor');
			View::assign('editor', $editor === '2' ? '2' : '1');
			$admin = get_login_admin();
			View::assign('admin_nickname', $admin['nickname'] ?? '');
			$presetFile = root_path() . 'config/doc_preset.json';
			$preset = ['author' => [], 'from' => []];
			if (is_file($presetFile)) {
				$preset = json_decode(file_get_contents($presetFile), true) ?: $preset;
			}
			View::assign('doc_preset', $preset);
            $otherCFG = get_system_config();
            $otherCFG['editor_img_download_to_local'] = (string) ($otherCFG['editor_img_download_to_local'] ?? '') === '1' ? 1 : 0;
            $otherCFG['editor_img_clear_link'] = (string) ($otherCFG['editor_img_clear_link'] ?? '') === '1' ? 1 : 0;
            View::assign('otherCFG', $otherCFG);
            //模型自定义字段
            $customfields=Db::name('channel_field')->where(['channel_id'=>$channel_id,'status'=>1,'is_system'=>0])->order('sort asc,id asc')->select()->toArray();
            View::assign('customfields', $customfields);
            View::assign('playerOptions', config('player.players', []));
            View::assign('playerDefault', config('player.default', 'html5'));
            $nid=Db::name('channel')->where(['id'=>$channel_id])->value('nid');
            View::assign('nid', $nid);
			return view();
		}
    }
	

    /**
    * 编辑
    */
    public function edit()
    {
		$param = get_params();
		
        if (request()->isAjax()) {
            $this->requireRequestMethod();
            try {
                validate(DocumentValidate::class)->check($param);
            } catch (ValidateException $e) {
                // 验证失败 输出错误信息
                return to_assign(1, $e->getError());
            }

            //编辑可能把文档换到别的栏目，先记下原来的栏目，等会儿一起清缓存
            $oldDoc = Db::name('document')->field('id,nav_id,tag_ids')->where('id', $param['id'] ?? 0)->find() ?: [];
            $oldNavId = (int) ($oldDoc['nav_id'] ?? 0);
            $oldNav = $oldNavId > 0 ? (nav_flat_map()[$oldNavId] ?? null) : null;
            $oldDocUrl = $oldDoc ? doc_url($oldDoc, $oldNav) : '';

            $docId = $this->model->editDocument($param);

            if ($docId) {
                //编辑会影响文档详情、模型字段、标签统计和按标签/条件查询的结果，统一清理文档缓存。
                clear_cache('Document');
                //按后台「发布/编辑文档」的勾选清对应缓存（首页 / 相关栏目 / 上下篇）
                clear_doc_list_cache(
                    [
                        'nav_id'     => $param['nav_id'] ?? 0,
                        'channel_id' => Db::name('nav')->where('id', $param['nav_id'] ?? 0)->value('channel_id'),
                    ],
                    $oldNavId > 0 ? [$oldNavId] : []
                );
                //开了「Sitemap → 自动更新」就起个后台进程重建地图（失败静默，不影响保存）
                sitemap_auto_update();
                //开启了百度自动推送时，在独立进程推送本篇文档，避免百度接口延迟阻塞保存
                sitemap_auto_push_document((int) $docId);
                //生成静态模式下，按勾选重新生成受影响的静态页面（失败静默，不影响保存）
                $savedDoc = Db::name('document')->field('id,nav_id,tag_ids')->where('id', $docId)->find() ?: [];
                static_update_after_doc($savedDoc, 'edit', $oldNavId, (array) ($oldDoc['tag_ids'] ?? []), $oldDocUrl);
                return to_assign(0,'修改成功',['id'=>$docId]);
            }else{
                return to_assign(1,'修改失败');
            }
        }else{
			$id = isset($param['id']) ? $param['id'] : 0;

            if(!isset($id) || empty($id) || !is_numeric($id) || $id < 1){
                throw new \think\exception\HttpException(406, '找不到页面');
            }
			$info = $this->model->getDocumentById($id);
            $this->assignI18nFields((int) $id, (int) ($info['channel_id'] ?? 0));
			$markdownContent = (string) ($info['md_content'] ?? '');
			if ($markdownContent === '') {
				//旧文章没有 Markdown 源文时，用现有 HTML 内容填充，避免切换编辑器后正文变空
				$markdownContent = (string) ($info['content'] ?? '');
			}
			View::assign('markdown_content', $markdownContent);
            $keyword_array = Db::name('keywords')->order('add_time asc')->whereIn('id',$info['tag_ids'])->select()->toArray();
            $info['keyword_names'] = implode(',', array_column($keyword_array, 'title'));
            $info['keyword_array'] = $keyword_array;

            $user_level = Cache::remember('user_level', function () {
                return Db::name('user_level')->where(['status' => 1]) ->order('id asc')->select()->toArray();
            }, 3600 * 24);
            View::assign('user_level', $user_level);
            View::assign('navs', set_recursion(get_navs(0, true)));
            View::assign('channel_id_curr', (int) $info['channel_id']);
            View::assign('nav_id_curr', $info['nav_id']);
			$editor = (string) get_system_config('editor');
			View::assign('editor', $editor === '2' ? '2' : '1');
            View::assign('info', $info);
			$admin = get_login_admin();
			View::assign('admin_nickname', $admin['nickname'] ?? '');
			$presetFile = root_path() . 'config/doc_preset.json';
			$preset = ['author' => [], 'from' => []];
			if (is_file($presetFile)) {
				$preset = json_decode(file_get_contents($presetFile), true) ?: $preset;
			}
			View::assign('doc_preset', $preset);
            $otherCFG = get_system_config();
            $otherCFG['editor_img_download_to_local'] = (string) ($otherCFG['editor_img_download_to_local'] ?? '') === '1' ? 1 : 0;
            $otherCFG['editor_img_clear_link'] = (string) ($otherCFG['editor_img_clear_link'] ?? '') === '1' ? 1 : 0;
            View::assign('otherCFG', $otherCFG);
            //模型自定义字段
            $customfields=Db::name('channel_field')->where(['channel_id'=>$info['channel_id'],'status'=>1,'is_system'=>0])->order('sort asc,id asc')->select()->toArray();
            View::assign('customfields', $customfields);
            View::assign('playerOptions', config('player.players', []));
            View::assign('playerDefault', config('player.default', 'html5'));
            $nid=Db::name('channel')->where(['id'=>$info['channel_id']])->value('nid');
            View::assign('nid', $nid);
            //专题模型：回显节点及节点文档列表
            if($nid == 'special'){
                $specialNodes=Db::name(get_content_table($info['channel_id']))->where('doc_id',$id)->value('special_nodes');
                View::assign('special_nodes', $specialNodes ? (json_decode($specialNodes, true) ?: []) : []);
            }
            return view();
		}
    }

    /** 为发布/编辑文档页准备当前模板已启用语言的翻译字段。 */
    private function assignI18nFields(int $docId, int $channelId): void
    {
        $fields = [
            ['entity_type' => 'document', 'name' => 'title', 'label' => '标题', 'type' => 'input'],
            ['entity_type' => 'document', 'name' => 'short_title', 'label' => '短标题', 'type' => 'input'],
            ['entity_type' => 'document', 'name' => 'title_ext_1', 'label' => '扩展标题1', 'type' => 'input'],
            ['entity_type' => 'document', 'name' => 'title_ext_2', 'label' => '扩展标题2', 'type' => 'input'],
            ['entity_type' => 'document', 'name' => 'title_ext_3', 'label' => '扩展标题3', 'type' => 'input'],
            ['entity_type' => 'document', 'name' => 'seo_title', 'label' => 'SEO标题', 'type' => 'input'],
            ['entity_type' => 'document', 'name' => 'seo_keywords', 'label' => 'SEO关键词', 'type' => 'input'],
            ['entity_type' => 'document', 'name' => 'seo_description', 'label' => 'SEO描述', 'type' => 'textarea', 'rows' => 4],
        ];
        $table = $channelId > 0 ? (string) Db::name('channel')->where('id', $channelId)->value('table') : '';
        if ($table !== '') {
            $fields = array_merge($fields, ContentI18n::modelFieldDefinitions('doc_model', $table, $channelId));
        }
        View::assign('i18nRows', ContentI18n::formRows($docId, $fields));
    }

    /**
    * 删除
    * 回收站开启时走逻辑删除（is_del=1 + delete_time，可在回收站里恢复）；
    * 关闭时直接物理删除，删了找不回来
    */
    public function delete()
    {
        if (!request()->isDelete()) {
            return to_assign(1, '错误的请求');
        }
        $param = get_params();
		$id = isset($param['id']) ? $param['id'] : 0;

        return $this->model->delDocumentById($id, is_recycle_on() ? 0 : 1);
   }

    /**
    * 从回收站恢复文档（is_del 置回 0）
    */
    public function restore()
    {
        if (!request()->isPost()) {
            return to_assign(1, '错误的请求');
        }
        $param = get_params();
		$id = isset($param['id']) ? $param['id'] : 0;
		$ids = is_array($id) ? $id : explode(',', (string) $id);
		$docs = Db::name('document')->field('id,nav_id,tag_ids')->whereIn('id', $ids)->select()->toArray();

        //插件钩子：文档从回收站恢复
        \app\services\PluginService::documentRestore($id);

        $result = $this->model->restoreDocumentById($id);
        foreach ($docs as $doc) {
            if ((int) Db::name('document')->where('id', $doc['id'])->value('is_del') === 0) {
                static_update_after_doc($doc, 'pub');
            }
        }
        sitemap_auto_update();
        return $result;
   }

    /**
    * 彻底删除文档（从回收站物理删除，删了找不回来）
    */
    public function purge()
    {
        if (!request()->isPost()) {
            return to_assign(1, '错误的请求');
        }
        $param = get_params();
		$id = isset($param['id']) ? $param['id'] : 0;

        //插件钩子：文档彻底删除前（此时数据还在，插件能读到）
        \app\services\PluginService::documentPurge($id);

        //$type 传 1 走物理删除
        return $this->model->delDocumentById($id, 1);
   }

    /**
    * 清空文档回收站（把回收站里的文档全部物理删除，不可恢复）
    */
    public function clear()
    {
        if (!request()->isPost()) {
            return to_assign(1, '错误的请求');
        }
        $num = $this->model->clearRecycleDocument();
        if ($num < 1) {
            return to_assign(1, '回收站已经是空的');
        }
        //回收站里的文档被彻底删掉了，前台列表缓存也要清（这类文档前台本来就看不到，清一下更稳妥）
        clear_cache('Document');
        safe_add_log('delete', 0, ['count' => $num]);
        return to_assign(0, "已清空回收站，共删除 {$num} 篇文档");
    }

    //修改字段（支持批量，用于审核/置顶/推荐/内联编辑等）
    public function edit_field()
    {
        $this->requireRequestMethod();
        $param = get_params();
        if (request()->isAjax()) {
            // 字段白名单，防止前端伪造字段名注入任意字段（按需增删）
            $allowFields = ['arcrank', 'is_head', 'is_recommend', 'is_special', 'click', 'sort', 'status'];
            $field = isset($param['field']) ? $param['field'] : '';
            $value = isset($param['value']) ? $param['value'] : '';
            // 兼容单条与批量：id 可能是数字、字符串或数组
            $ids = isset($param['id']) ? $param['id'] : 0;
            $ids = is_array($ids) ? $ids : explode(',', (string)$ids);
            $ids = array_values(array_filter(array_map('intval', $ids)));

            if (!in_array($field, $allowFields, true) || empty($ids)) {
                return to_assign(1, '参数错误');
            }

            //插件钩子：文档审核/字段变更（审核、置顶、推荐、内联编辑都走这里）
            \app\services\PluginService::documentExamine($ids, $field, $value);

            $ids = implode(',', $ids);
            $before = Db::name('document')->field('id,nav_id,tag_ids')->whereIn('id', explode(',', $ids))->select()->toArray();
            //

            // 链式调用保证 where 生效（模型静态 update 会新建模型丢失条件，且数组主键会触发 INSERT 或报错）
            $res = $this->model->where('id', 'in', $ids)->update([$field => $value]);
            //dump($res);die;
            if ($res != false) {
                safe_add_log('edit', $ids, [$field => $value]);
                clear_cache('Document');
                //审核/发布状态会改变文档是否参与上一篇/下一篇查询。
                if ($field === 'arcrank' || $field === 'status') {
                    clear_cache('docSide');
                }
                foreach ($before as $doc) {
                    $current = Db::name('document')->field('id,nav_id,tag_ids,is_del,arcrank')->where('id', $doc['id'])->find();
                    if (!$current) {
                        continue;
                    }
                    $oldNav = nav_flat_map()[(int) $doc['nav_id']] ?? null;
                    $oldUrl = doc_url($doc, $oldNav);
                    if ((int) ($current['is_del'] ?? 0) !== 0 || (int) ($current['arcrank'] ?? -1) < 0) {
                        static_delete_after_doc([array_merge($doc, ['old_url' => $oldUrl])]);
                    } else {
                        static_update_after_doc($current, 'edit', (int) $doc['nav_id'], static_tag_id_list($doc['tag_ids'] ?? []), $oldUrl);
                    }
                }
                sitemap_auto_update();
                return to_assign(0, "操作成功");
            }
            return to_assign(1, '操作失败');
        }
    }
}
