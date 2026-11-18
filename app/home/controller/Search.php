<?php
/**
 * @copyright Copyright (c) 2026 米柚工作室
 * @license https://opensource.org/licenses/Apache-2.0
 * @link https://www.miucms.com
 */

declare (strict_types = 1);

namespace app\home\controller;

use app\home\BaseController;
use think\facade\View;
use think\facade\Db;

/**
 * 前台搜索页
 *
 * 地址：/search?keyword=关键词&navid=栏目id&mid=模型id&page=2
 * 搜索页走的是真实控制器地址，三种 URL 模式（动态/伪静态/静态）都能直接访问，
 * 地址不跟后台「SEO → URL配置」变（生成地址用 miu_search_url()）。
 *
 * 用哪个模板：templates/<当前模板>/search.html
 * 模板里可用的变量见 index() 里 View() 的第二个参数。
 */
class Search extends BaseController
{
    public function index()
    {
        $param   = get_params();
        $keyword = trim((string) ($param['keyword'] ?? $param['keywords'] ?? ''));
        $navid   = (int) ($param['navid'] ?? 0);
        $mid     = (int) ($param['mid'] ?? 0);
        $page    = max(1, (int) ($param['page'] ?? 1));

        //关键词太长就截断，避免有人塞一大坨东西进来
        if (mb_strlen($keyword, 'UTF-8') > 50) {
            $keyword = mb_substr($keyword, 0, 50, 'UTF-8');
        }

        //插件钩子：搜索前（插件可修改关键词，比如做同义词扩展）
        \app\services\PluginService::searchBefore($keyword);

        add_user_log('view', '搜索页', 0, $param);

        //搜索限定了栏目就高亮那个栏目，没限定就不高亮任何菜单。
        //要在 miu_nav_items() 第一次被用到之前设（下面的 miu_doc_row() 会用到它）
        miu_current_nav_id($navid > 0 ? $navid : 0);

        //告诉 {miu:list} 标签「这一页是搜索页，条件用这套」，
        //这样模板里就能直接用 {miu:list pagesize='10'} 出搜索结果（会自动分页）
        miu_current_search(['keyword' => $keyword, 'navid' => $navid, 'mid' => $mid]);

        // ---------------- 搜索条件 ----------------
        //navid=栏目id（连同子孙栏目一起搜）、mid=模型id（cms_channel 表的 id）；
        //两个都不传时，条件里就只有关键词
        $hasFilter = ($keyword !== '' || $navid > 0 || $mid > 0);

        $query = Db::name('document')
            ->where('is_del', 0)
            //arcrank = -1 是「未审核」，前台不展示
            ->where('status', 1)->where('arcrank', '>=', 0);

        if ($keyword !== '') {
            //标题 或 SEO描述 里包含关键词。
            //SEO描述没填时后台会自动从正文前 200 字提取，所以这里等于顺带搜了正文，
            //不用再去 join 各模型的内容附加表（cms_article / cms_product …）
            $like = '%' . $keyword . '%';
            $localizedQuery = \app\home\service\ContentI18n::documentSearchQuery($keyword);
            $query->where(function ($q) use ($like, $localizedQuery) {
                $q->whereOr('title', 'like', $like)
                    ->whereOr('seo_description', 'like', $like);
                if ($localizedQuery) {
                    $q->whereOr('id', 'in', $localizedQuery);
                }
            });
        }

        if ($navid > 0) {
            //连同子孙栏目一起搜：文档一般发在叶子栏目上，只按本栏目查会一篇都搜不到
            $query->whereIn('nav_id', miu_nav_ids_with_children($navid));
        }

        if ($mid > 0) {
            $query->where('channel_id', $mid);
        }

        // ---------------- 分页 ----------------
        $pageSize = (int) (get_config('app.page_size') ?: 20);

        $list  = [];
        $total = 0;

        //关键词、栏目、模型一个都没传时不查库：不然等于把整站文档全列出来。
        //模板里判断 {$total} 为 0 时提示「请输入关键词」就行
        if ($hasFilter) {
            $total = (clone $query)->count();

            //列表默认带上模型的自定义字段（附加表，只查 channel_field 里登记的那些）
            $rows = miu_doc_merge_extras(
                $query->order('is_top desc, id desc')->page($page, $pageSize)->select()->toArray(),
                false
            );

            foreach ($rows as $row) {
                //补 url / date / nav_name / title_style 这些模板常用字段
                $list[] = miu_doc_row($row, nav_by_id((int) $row['nav_id']));
            }

            //插件钩子：搜索后（插件可增删改搜索结果）
            $searchResult = [
                'keyword' => $keyword,
                'navid'   => $navid,
                'mid'     => $mid,
                'list'    => $list,
                'total'   => $total,
            ];
            \app\services\PluginService::searchAfter($searchResult);
            $list  = $searchResult['list'];
            $total = $searchResult['total'];
        }

        //总页数至少是 1（一条都没搜到时，模板里显示「共 1 页 0 条」比「共 0 页」顺眼）
        $pageCount = $pageSize > 0 ? max(1, (int) ceil($total / $pageSize)) : 1;

        //把分页信息存一份，{miu:pagelist} 标签要用。
        //搜索页不是栏目页、没有 nav，所以额外给个 base_url，让它按搜索地址拼分页链接
        $searchUrl = miu_search_url(['keyword' => $keyword, 'navid' => $navid, 'mid' => $mid]);
        miu_page_info([
            'nav'      => [],
            'base_url' => $searchUrl,
            'page'     => $page,
            'count'    => $pageCount,
            'total'    => $total,
            'size'     => $pageSize,
        ]);

        $searchTitle = $keyword !== '' ? '搜索「' . $keyword . '」' : '搜索';
        miu_assign_page_meta([
            'seo_keywords'    => $keyword,
            'seo_description' => $searchTitle . '的结果列表',
        ], miu_search_url(['keyword' => $keyword, 'navid' => $navid, 'mid' => $mid], $page));

        return View('/search', [
            //把「搜索」本身也包成一个栏目对象，这样模板里那套 {$nav.title} / {$nav.seo_title}
            //的头部写法可以直接复用，不用为搜索页单独写一份
            'nav'        => [
                'id'              => 0,
                'title'           => $searchTitle,
                'seo_title'       => $searchTitle,
                'seo_keywords'    => $keyword,
                'seo_description' => $searchTitle . '的结果列表',
                'src'             => $searchUrl,
            ],
            //搜索词和筛选项（模板里回显到搜索框、下拉框用）
            'keyword'    => $keyword,
            'navid'      => $navid,
            'mid'        => $mid,
            //搜索结果
            'list'       => $list,
            'total'      => $total,
            //分页
            'page'       => $page,
            'page_html'  => miu_pagelist(),
            'page_no'    => $page,
            'page_size'  => $pageSize,
            'page_count' => $pageCount,
            //当前搜索地址（第 1 页，不带 page 参数），给表单 action 用
            'search_url' => $searchUrl,
            //页面标题：走后台「标题规则」里栏目标题那套（搜索页当成一个虚拟栏目）
            'page_title' => miu_page_title('nav', ['title' => $searchTitle], $page),
        ]);
    }
}
