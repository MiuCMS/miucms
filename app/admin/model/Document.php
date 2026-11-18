<?php
/**
 * @copyright Copyright (c) 2026 米柚工作室
 * @license https://opensource.org/licenses/Apache-2.0
 * @link https://www.miucms.com
 */
namespace app\admin\model;
use think\Model;
use app\admin\model\Keywords;
use think\facade\Db;
use app\home\service\ContentI18n;
use HTMLPurifier;
use HTMLPurifier_Config;

class Document extends Model
{
    protected static function onBeforeInsert($model)
    {
        $data = $model->getData();
        $data = static::handle_params($data);
        foreach ($data as $key => $value) {
            $model->setData($key, $value);
        }
        static::trimLongFields($model);
    }

    protected static function onBeforeUpdate($model)
    {
        $data = $model->getData();
        $data = static::handle_params($data);
        foreach ($data as $key => $value) {
            $model->setData($key, $value);
        }
        static::trimLongFields($model);
    }

    protected static function trimLongFields($model)
    {
        $fields = ['seo_description'];
        foreach ($fields as $field) {
            $val = $model->getAttr($field);
            if (!empty($val) && mb_strlen($val) > 200) {
                $model->setAttr($field, mb_substr($val, 0, 200));
            }
        }
    }
    //插入关键字
    public function insertKeyword($keywordArray = [], $doc_id = 0)
    {
        $insert = [];
        $time = time();
        foreach ($keywordArray as $key => $value) {
            if (!$value) {
                continue;
            }
            $keywords_id = (new Keywords())->increase($value);
            $insert[] = ['doc_id' => $doc_id,
                'keywords_id' => $keywords_id,
                'add_time' => $time,
            ];
        }
        return $insert;
    }
    /**
    * 获取分页列表
    * @param $where
    * @param $param
    * @param bool $aliveOnly 是否只取未删除的（回收站列表传 false）
    */
    public function getDocumentList($where, $param)
    {
        // 白名单映射：字段名 => 带别名字段名
        $allowFields = [
            'id'            => 'a.id',
            'click'         => 'a.click',
            'sort'          => 'a.sort',
            'add_time'      => 'a.add_time',
            'is_head'       => 'a.is_head',
            'is_recommend'  => 'a.is_recommend',
            'is_special'    => 'a.is_special',
        ];

        // 参数处理
        $field = input('field', '');
        $order = input('order', '');

        if (empty($field) || empty($order)) {
            $field = 'id';
            $order = 'desc';
        }

        // 校验并取带别名的字段
        $orderField = $allowFields[$field] ?? 'a.id';
        $orderDir   = strtolower($order) === 'asc' ? 'asc' : 'desc';

		$rows = empty($param['limit']) ? get_config('app.page_size') : $param['limit'];
        $list = self::where($where)
		->field('a.*,c.id as nav_id,c.title as nav_name,u.nickname as admin_name')
        ->alias('a')
        ->join('nav c', 'a.nav_id = c.id')
        ->join('Admin u', 'a.admin_id = u.id')
		->order($orderField, $orderDir)
		->paginate(['list_rows'=> $rows]);
		return $list;
    }

    /**
    * 添加数据
    * @param $param
    */
    public function addDocument($param)
    {
		$insertId = 0;
        try {
			$translations = is_array($param['translations'] ?? null) ? $param['translations'] : [];
			unset($param['translations']);
			if (ContentI18n::enabledLocales()) {
				ContentI18n::ensureSchema();
			}
			$param['add_time'] = time();
            //处理接收到的参数
            $param = static::handle_params($param);

            //插件钩子：文档添加前（插件可修改 $param）
            \app\services\PluginService::documentAdd($param);

            //钩子在 handle_params() 之后跑，插件改过的正文要再净化一次：
            //附加表是直接 insert 的，不会再经过模型的 onBeforeInsert
            $param = static::purifyContent($param);

            if(!isset($param['release_time']) || $param['release_time'] === ''){
                $param['release_time']=time();
            }
            $channelId = (int) Db::name('nav')->where('id', (int) ($param['nav_id'] ?? 0))->value('channel_id');
            $tableName = (string) Db::name('channel')->where('id', $channelId)->value('table');
            if ($channelId < 1 || $tableName === '') {
                throw new \RuntimeException('栏目所属内容模型无效，无法保存文档');
            }
            \app\services\PluginService::autoTranslatePosted('document', $translations, $param);
            \app\services\PluginService::autoTranslatePosted('doc_model', $translations, $param, $tableName, $channelId);
            //事务写入主表和附加表
            $result = Db::transaction(function () use ($param, $translations, $tableName, $channelId) {
                $doc = new self();
                $doc->setOption('strict', false)->save($param); // 触发 onBeforeInsert
                $insertId = $doc->id;
                //写入附加表
                $param['doc_id'] = $insertId;

                $res=Db::name($tableName)->strict(false)->field(true)->insert($param);
                if (!$res || !$insertId) {
                    throw new \Exception('发布失败！检查数据表');
                }
                ContentI18n::savePosted('document', (int) $insertId, $translations, '', 0, $param);
                ContentI18n::savePosted('doc_model', (int) $insertId, $translations, $tableName, $channelId, $param);
                return $insertId;
            });

			$insertId = $result;
            //插件钩子：文档添加后
            \app\services\PluginService::documentAdded((int) $insertId, $param);
            //关联关键字
            if (isset($param['keyword_names']) && $param['keyword_names']) {
                $keywordArray = explode(',', $param['keyword_names']);
                $res_keyword = $this->insertKeyword($keywordArray,$insertId);
                if(count($res_keyword)>0){
                    $tag_ids='';
                    foreach ($res_keyword as $k=>$v){
                        $tag_ids.=$v['keywords_id'].',';
                    }
                    $tag_ids=','.$tag_ids;
                    $this->where('id','=',$insertId)->update(['tag_ids'=>$tag_ids]);
                }
            }
			safe_add_log('add', $insertId, $param);
        } catch(\Exception $e) {
			return to_assign(1, '操作失败，原因：'.$e->getMessage());
        }
        return $result;

    }

    /**
    * 编辑信息
    * @param $param
    */
    public function editDocument($param)
    {
        //编辑文档时，如果栏目所属模型与原栏目模型不一致，则拦截
        $new_channel_id=Db::name('nav')->where('id','=',$param['nav_id'])->value('channel_id');
        $old_channel_id=Db::name('document')->where('id','=',$param['id'])->value('channel_id');
        if($new_channel_id!==$old_channel_id){
            return to_assign(1, '不能跨模型切换栏目');
        }
        try {
			$translations = is_array($param['translations'] ?? null) ? $param['translations'] : [];
			unset($param['translations']);
			if (ContentI18n::enabledLocales()) {
				ContentI18n::ensureSchema();
			}
            //处理接收到的参数
            $param = static::handle_params($param);

            //插件钩子：文档编辑前（插件可修改 $param）
            \app\services\PluginService::documentEdit($param);

            //钩子在 handle_params() 之后跑，插件改过的正文要再净化一次（同 addDocument）
            $param = static::purifyContent($param);

            if(isset($param['release_time']) && $param['release_time'] !== ''){
                $param['release_time']=strtotime($param['release_time']);
            }
            $channelId = (int) Db::name('nav')->where('id', (int) ($param['nav_id'] ?? 0))->value('channel_id');
            $tableName = (string) Db::name('channel')->where('id', $channelId)->value('table');
            if ($channelId < 1 || $tableName === '') {
                throw new \RuntimeException('栏目所属内容模型无效，无法保存文档');
            }
            \app\services\PluginService::autoTranslatePosted('document', $translations, $param);
            \app\services\PluginService::autoTranslatePosted('doc_model', $translations, $param, $tableName, $channelId);
            //事务写入主表和附加表
            $result = Db::transaction(function () use ($param, $translations, $tableName, $channelId) {
                $doc = $this->find($param['id']);
                $doc->setOption('strict', false)->save($param); // 触发 onBeforeUpdate
                $docId = $doc->id;
                //写入附加表
                $param['doc_id'] = $docId;
                unset($param['id']);

                $exists = Db::name($tableName)->where('doc_id', $docId)->find();
                if ($exists) {
                    $res = Db::name($tableName)->strict(false)->field(true)->where('doc_id', $docId)->update($param);
                } else {
                    $res = Db::name($tableName)->strict(false)->field(true)->insert($param);
                }
                if ($res === false || !$docId) {
                    throw new \Exception('更新失败！检查数据表');
                }
                ContentI18n::savePosted('document', (int) $docId, $translations, '', 0, $param);
                ContentI18n::savePosted('doc_model', (int) $docId, $translations, $tableName, $channelId, $param);
                return $docId;
            });

            $docId = $result;
            //插件钩子：文档编辑后
            \app\services\PluginService::documentEdited((int) $docId, $param);
            //关联关键字
            if (isset($param['keyword_names']) && $param['keyword_names']) {
                $keywordArray = explode(',', $param['keyword_names']);
                $res_keyword = $this->insertKeyword($keywordArray,$docId);
                if(count($res_keyword)>0){
                    $tag_ids='';
                    foreach ($res_keyword as $k=>$v){
                        $tag_ids.=$v['keywords_id'].',';
                    }
                    $tag_ids=','.$tag_ids;
                    $this->where('id','=',$docId)->update(['tag_ids'=>$tag_ids]);
                }
            }
			safe_add_log('edit', $param['id'], $param);
        } catch(\Exception $e) {
			return to_assign(1, '操作失败，原因：'.$e->getMessage());
        }
		return $result;
    }
	

    /**
    * 根据id获取信息
    * @param $id
    */
    public function getDocumentById($id)
    {
        $channel_id=Db::name('document')->where('id','=',$id)->value('channel_id');
        $tableName=get_content_table($channel_id);
        $fieldStr='a.*,b.content,b.md_content';
        $field=Db::name('channelField')->where(['channel_id'=>$channel_id,'is_system'=>0,'status'=>1])->order('sort asc,id asc')->column('name');
        if($field){
            $field = array_map(function($item){
                return 'b.' . $item;
            }, $field);
            $fieldStr=$fieldStr.','.implode(',',$field);
        }
        $info = (new self())->where('a.id', $id)->alias('a')->leftJoin("{$tableName} b",'a.id=b.doc_id')->field($fieldStr)->find();
        $info = $info->toArray();
		return $info;
    }

    /**
    * 删除信息
    * @param $id
    * @return array
    */
    public function delDocumentById($id,$type=0)
    {
        $id = is_array($id) ? $id : explode(',', (string)$id);
        if(is_array($id)){
            $where = [['id', 'in', $id]];   // TP8 写法：二维数组，值直接用数组
        }else{
            $where = [['id', '=', $id]];
        }

        //插件钩子：文档删除前（此时文档还在，插件可以读到原始数据）
        \app\services\PluginService::documentDelete($id);

        //生成静态模式下，删完要清掉这些文档的静态页、并重生成相关栏目页。
        //栏目id得在删除前记下来，删完就查不到了
        $docRows = Db::name('document')->field('id,nav_id,tag_ids,channel_id')->whereIn('id', $id)->select()->toArray();

        if($type==0){
            //逻辑删除（进回收站）：主表标记 is_del=1，主表和附加表都记录删除时间
            //注意：附加表（cms_article 等按模型建的）没有 is_del 字段，只写 delete_time
            try {
                //事务更新主表和附加表
                $id = Db::transaction(function () use ($id,$where) {
                    //更新附加表
                    foreach ($id as $v){
                        $channel_id=Db::name('document')->where('id',$v)->value('channel_id');
                        $tableName=Db::name('channel')->where('id',$channel_id)->value('table');
                        $time=time();
                        $res=Db::name($tableName)->where('doc_id',$v)->update(['delete_time'=>$time]);
                        $res2=$this->where('id',$v)->update(['is_del'=>1,'delete_time'=>$time]);
                        if (!$res || !$res2) {
                            throw new \Exception('删除失败！检查数据表');
                        }
                    }
                    return $id;
                });
                safe_add_log('delete', is_array($id) ? implode(',', $id) : $id);
            } catch(\Exception $e) {
                return to_assign(1, '操作失败，原因：'.$e->getMessage());
            }
        }
        else{
            //物理删除
            try {
                //事务更新主表和附加表
                $id = Db::transaction(function () use ($id,$where) {
                    //更新附加表
                    foreach ($id as $v){

                        $channel_id=Db::name('document')->where('id',$v)->value('channel_id');
                        $tableName=Db::name('channel')->where('id',$channel_id)->value('table');
                        $res=Db::name($tableName)->where('doc_id',$v)->field(true)->delete();
                        $res2=$this->where('id',$v)->delete();
                        if (!$res || !$res2) {
                            throw new \Exception('删除失败！检查数据表');
                        }
                        ContentI18n::deleteRecord('document', (int) $v);
                        ContentI18n::deleteRecord('doc_model', (int) $v, (string) $tableName);
                    }
                    return $id;
                });
                safe_add_log('delete', is_array($id) ? implode(',', $id) : $id);
            } catch(\Exception $e) {
                return to_assign(1, '操作失败，原因：'.$e->getMessage());
            }
        }
        //前台 {miu:list} 的列表是按 Cache::tag('Document') 缓存的，删了要清掉，
        //否则前台要等缓存过期（24小时）才看不到这篇文档
        clear_cache('Document');
        //删除文档会改变同栏目相邻文档的上一篇/下一篇关系。
        clear_cache('docSide');
        //生成静态模式下：删掉这些文档的静态页，并重新生成相关栏目页（失败静默，不影响删除）
        static_delete_after_doc($docRows);
        //勾了「Sitemap → 自动更新」就重建地图（失败静默，不影响删除）
        sitemap_auto_update();
        return to_assign();
    }

    /**
    * 从回收站恢复文档：主表 is_del 置回 0，主表和附加表的 delete_time 清零
    * @param mixed $id 文档id，支持逗号分隔字符串或数组
    * @return array
    */
    public function restoreDocumentById($id)
    {
        $id = is_array($id) ? $id : explode(',', (string)$id);
        try {
            //事务更新主表和附加表
            Db::transaction(function () use ($id) {
                foreach ($id as $v) {
                    $channel_id = Db::name('document')->where('id', $v)->value('channel_id');
                    $tableName  = Db::name('channel')->where('id', $channel_id)->value('table');
                    //附加表没有 is_del，只清 delete_time
                    Db::name($tableName)->where('doc_id', $v)->update(['delete_time' => 0]);
                    $this->where('id', $v)->update(['is_del' => 0, 'delete_time' => 0]);
                }
            });
            safe_add_log('recovery', is_array($id) ? implode(',', $id) : $id);
        } catch (\Exception $e) {
            return to_assign(1, '操作失败，原因：' . $e->getMessage());
        }
        //恢复的文档要重新出现在前台列表里，清掉列表缓存
        clear_cache('Document');
        //恢复文档会改变同栏目相邻文档的上一篇/下一篇关系。
        clear_cache('docSide');
        return to_assign();
    }

    /**
    * 清空文档回收站：把 is_del=1 的文档（主表 + 附加表）全部物理删除
    * @return int 删掉的文档数，0 表示回收站本来就是空的
    */
    public function clearRecycleDocument()
    {
        $ids = Db::name('document')->where('is_del', 1)->column('id');
        if (empty($ids)) {
            return 0;
        }
        //按内容模型分组删，同一个模型共用一张附加表
        $channelIds = Db::name('document')->where('is_del', 1)->group('channel_id')->column('channel_id');
        Db::transaction(function () use ($channelIds) {
            foreach ($channelIds as $channelId) {
                $tableName = Db::name('channel')->where('id', $channelId)->value('table');
                if (!$tableName) {
                    continue;
                }
                $docIds = Db::name('document')->where('is_del', 1)->where('channel_id', $channelId)->column('id');
                if (empty($docIds)) {
                    continue;
                }
                Db::name($tableName)->where('doc_id', 'in', $docIds)->delete();
                Db::name('document')->where('id', 'in', $docIds)->delete();
                ContentI18n::deleteRecords('document', $docIds);
                ContentI18n::deleteRecords('doc_model', $docIds, (string) $tableName);
            }
        });
        return count($ids);
    }
    /*
     * 规范化专题节点数据（special模型的节点及节点文档列表）
     * 结构：[{name, ident, sort, docs:[{id, title, sort}]}]
     * @param string|array $value 前端提交的 JSON 字符串
     * @return string 规范化后的 JSON
     * */
    protected static function normalizeSpecialNodes($value)
    {
        $nodes = is_array($value) ? $value : json_decode((string)$value, true);
        if (!is_array($nodes)) {
            return '[]';
        }
        //只接受标量，避免数组/对象被转成 "Array"
        $text = static function ($val) {
            return is_scalar($val) ? trim((string)$val) : '';
        };
        $result = [];
        foreach ($nodes as $node) {
            if (!is_array($node)) {
                continue;
            }
            $docs = [];
            foreach ((array)($node['docs'] ?? []) as $doc) {
                $docId = is_array($doc) ? (int)($doc['id'] ?? 0) : 0;
                if ($docId < 1) {
                    continue;
                }
                $docs[] = [
                    'id'    => $docId,
                    'title' => $text($doc['title'] ?? ''),
                    'sort'  => (int)($doc['sort'] ?? 0),
                ];
            }
            $result[] = [
                'name'  => $text($node['name'] ?? ''),
                'ident' => $text($node['ident'] ?? ''),
                'sort'  => (int)($node['sort'] ?? 0),
                'docs'  => $docs,
            ];
        }
        return json_encode($result, JSON_UNESCAPED_UNICODE);
    }

    /*
     * 净化正文（content / md_content）
     *
     * 单独抽出来是因为：插件钩子 document_add / document_edit 是在 handle_params() 之后跑的，
     * 插件改过的 content 会被直接写进「内容附加表」（那张表是 Db::name($table)->insert($param)，
     * 不会再经过模型的 onBeforeInsert / onBeforeUpdate），所以钩子之后必须再净化一次，
     * 否则插件能往文档正文里塞 <script>（存储型 XSS —— 前台详情页读的正是附加表的 content）。
     *
     * @param array $param
     * @return array
     * */
    public static function purifyContent(array $param): array
    {
        // 创建HTMLPurifier配置对象 内容过滤
        $config = HTMLPurifier_Config::createDefault();
        $serializerPath = runtime_path() . 'htmlpurifier';
        if (!is_dir($serializerPath) && !@mkdir($serializerPath, 0755, true) && !is_dir($serializerPath)) {
            throw new \RuntimeException('无法创建 HTMLPurifier 缓存目录');
        }
        $config->set('Cache.SerializerPath', $serializerPath);
        $config->set('HTML.DefinitionID', 'html5-definitions');
        $config->set('HTML.DefinitionRev', 1);
        //$config->set('HTML.Allowed', 'p,b,a[href],pre[class],code,blockquote,img[src],table,tr,th,td,ul,li,ol,dl,dt,dd');
        $config->set('HTML.ForbiddenElements', array('script'), true); //设置拒绝使用的tagname
        if ($def = $config->maybeGetRawHTMLDefinition()) {
            $def->addElement('video', 'Block', 'Optional: (source, Flow) | (Flow, source) | Flow', 'Common', [
                'src' => 'URI',
                'type' => 'Text',
                'poster' => 'URI',
                'preload' => 'Enum#auto,metadata,none',
                'controls' => 'Bool',
            ]);
            $def->addElement('source', 'Block', 'Flow', 'Common', [
                'src' => 'URI',
                'type' => 'Text',
            ]);
        }
        // 创建HTMLPurifier对象
        $purifier = new HTMLPurifier($config);
        //防止xss,过滤输入并输出结果
        if (isset($param['content'])) {
            $param['content'] = $purifier->purify($param['content']);
        }
        if (isset($param['md_content'])) {
            $param['md_content'] = $purifier->purify($param['md_content']);
        }
        return $param;
    }

    /*
     * 处理接收到的参数
     * */
    protected static function handle_params($param){
        if(!$param){
            return [];
        }
        //专题节点数据：前端提交的是 JSON，先规范化（需在下面的数组转字符串之前处理）
        if (isset($param['special_nodes'])) {
            $param['special_nodes'] = static::normalizeSpecialNodes($param['special_nodes']);
        }
        //字段如果是数组，以|连接
        foreach ($param as $key=>$item) {
            if(!is_array($item)){
                continue;
            }
            $param[$key]=implode('|',$item);
        }

        //unset($param['editor-container_htmltest']);
        if (isset($param['table-align'])) {
            unset($param['table-align']);
        }

//        dump($param);die;
        //关于markdown编辑器
        if (isset($param['content'])) {
            $param['md_content'] = '';
        }
        if (isset($param['docContent-html-code'])) {
            $param['content'] = $param['docContent-html-code'];
            $param['md_content'] = $param['docContent-markdown-doc'];
            unset($param['docContent-html-code']);
            unset($param['docContent-markdown-doc']);
        }
        //净化正文（content / md_content）
        $param = static::purifyContent($param);
        //如果是外链
        if(isset($param['is_jump']) && $param['is_jump']==1 && !is_valid_url($param['jumplinks'])) {
            throw new \Exception('你勾选了跳转，请填写完整的跳转链接');
        }
        //如果desc、seo_description未填写，提取正文前200个字
//        if(!isset($param['desc']) || empty(trim($param['desc']))) {
//            $param['desc'] = (isset($param['content']) && $param['content'] !== '') ? get_desc($param['content']) : '';
//        }
        if(!isset($param['seo_description']) || empty(trim($param['seo_description']))) {
            $param['seo_description'] = (isset($param['content']) && $param['content'] !== '') ? get_desc($param['content']) : '';
        }
        if(!isset($param['seo_title']) || empty(trim($param['seo_title']))) {
            $param['seo_title'] = $param['title'];
        }
        if(!isset($param['seo_keywords']) || empty(trim($param['seo_keywords']))) {
            $param['seo_keywords'] = isset($param['keyword_names']) ? $param['keyword_names'] : '';
        }
        //如果有/无缩略图，设置is_litpic
        $param['is_litpic'] = 0;
        if(isset($param['litpic']) && $param['litpic']) {
            $param['is_litpic'] = 1;
        }else{
            $param['is_litpic'] = 0;
        }
        //dump($param);
        //这两个选项默认跟随网站配置；页面显式提交 0/1 时，优先采用操作者当前勾选状态。
        $downloadImages = array_key_exists('editor_img_download_to_local', $param)
            ? (string) $param['editor_img_download_to_local'] === '1'
            : (string) get_system_config('editor_img_download_to_local') === '1';
        $clearOutboundLinks = array_key_exists('editor_img_clear_link', $param)
            ? (string) $param['editor_img_clear_link'] === '1'
            : (string) get_system_config('editor_img_clear_link') === '1';
        unset($param['editor_img_download_to_local'], $param['editor_img_clear_link']);

        //远程图片本地化：把正文里指向外站的图片下载到本地，并替换 src。
        if ($downloadImages && !empty($param['content'])) {
            $param['content'] = download_web_images($param['content']);
        }
        //删除非站内链接：只保留外链文字，当前域名及相对链接保留。
        if ($clearOutboundLinks && !empty($param['content'])) {
            $param['content'] = clear_outbound_links(
                $param['content'],
                (string) get_system_config('editor_img_clear_link_whitelist')
            );
        }
        return $param;
    }
}

