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
 * 栏目页（文档列表页）
 *
 * 动态URL：/home/nav/index?id=2004&page=2
 * 伪静态、静态模式下由 app/home/middleware/UrlRewrite.php 解析出栏目后转到同一个方法
 *
 * 用哪个模板：
 *   1) 栏目上单独指定的「栏目模板」（cms_nav.temp_list）
 *   2) list_<模型nid>.html，比如文章模型是 list_article.html
 * 都没有就显示提示页，不会 500
 */
class Nav extends BaseController
{
    public function index()
    {
        //栏目id（动态URL参数名是 id）
        $param = get_params();
        //地址上可能没有 id / page（比如第 1 页就不带 page），所以要给默认值，
        //不能直接写 $param['page']，不然缺参数时报「Undefined array key」整页 500
        $id   = (int) ($param['id'] ?? 0);
        $page = (int) ($param['page'] ?? 1);

        add_user_log('view', '栏目列表页', $id, $param);

        return miu_render_nav_list($id, $page);
    }
}
