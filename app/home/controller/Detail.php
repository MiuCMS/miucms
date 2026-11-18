<?php
/**
 * @copyright Copyright (c) 2026 米柚工作室
 * @license https://opensource.org/licenses/Apache-2.0
 * @link https://www.miucms.com
 */

declare (strict_types = 1);

namespace app\home\controller;

use app\home\BaseController;

/**
 * 文档详情页
 *
 * 动态URL：/home/detail/index?id=127
 * 伪静态、静态模式下由 app/home/controller/Url.php 解析出文档后调用同一套渲染逻辑
 *
 * 用哪个模板：
 *   1) 栏目上单独指定的「详情模板」（cms_nav.temp_view）
 *   2) view_<模型nid>.html，比如文章模型是 view_article.html
 *   3) view_default.html（通用兜底）
 * 都没有就显示提示页，不会 500
 */
class Detail extends BaseController
{
    public function index()
    {
        //文档id（动态URL参数名是 id）
        $param = get_params();
        //地址上可能没有 id，要给默认值，不然缺参数时报「Undefined array key」整页 500
        $id = (int) ($param['id'] ?? 0);

        add_user_log('view', '文档内容页', $id, $param);

        return miu_render_doc_detail($id);
    }
}
