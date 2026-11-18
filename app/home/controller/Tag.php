<?php
/**
 * @copyright Copyright (c) 2026 米柚工作室
 * @license https://opensource.org/licenses/Apache-2.0
 * @link https://www.miucms.com
 */

declare (strict_types = 1);

namespace app\home\controller;

use app\home\BaseController;
use think\facade\Db;

/**
 * 前台标签页
 *
 * 地址：/home/tag/index?id=标签id&page=2
 * 标签就是后台「基础 → 标签管理」里的关键字（cms_keywords 表），
 * 文档和标签的关联存在 cms_document.tag_ids 里，格式是「,1,2,」（前后都带逗号），
 * 所以「这个标签下的所有文章」= tag_ids LIKE '%,id,%'。
 *
 * 动态 / 伪静态地址为 /tag/{id}；生成静态模式的链接和静态文件路径由 miu_tag_url() 统一计算。
 *
 * 用哪个模板：templates/<当前模板>/list_tag.html
 * 模板变量由 miu_render_tag_list() 统一提供，前台访问和静态生成共用。
 */
class Tag extends BaseController
{
    public function index()
    {
        $param = get_params();
        $id    = (int) ($param['id'] ?? 0);
        $page  = max(1, (int) ($param['page'] ?? 1));

        add_user_log('view', '标签页', $id, $param);

        //标签页不属于任何栏目，不点亮任何菜单
        miu_current_nav_id(0);

        $tag = miu_cached_tag($id);
        if (!$tag) {
            return miu_front_notice(
                '标签不存在',
                '<p>找不到 id 为 <code>' . $id . '</code> 的标签，可能已被删除或禁用。</p>'
                . '<p>去后台「基础 → 标签管理」确认一下这个标签还在不在。</p>'
            );
        }

        //标签页被访问一次，点击数 +1（后台「标签管理」里那一列）
        Db::name('keywords')->where('id', $id)->inc('click')->update();
        $tag['click'] = miu_tag_click_after_increment($id);

        return miu_render_tag_list($id, $page, $tag);
    }
}
