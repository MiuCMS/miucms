<?php
/**
 * @copyright Copyright (c) 2026 米柚工作室
 * @license https://opensource.org/licenses/Apache-2.0
 * @link https://www.miucms.com
 */

declare (strict_types = 1);

namespace app\home\taglib\traits;

/**
 * {miu:position} 面包屑标签（自闭合 {miu:position ... /}）
 *
 * 文章列表页、文章内容页、搜索页、标签页都能用，数据来自当前页面：
 *   列表页：首页 > 一级栏目 > …… > 当前栏目
 *   内容页：首页 > 一级栏目 > …… > 所属栏目 > 文章标题
 *   搜索页：首页 >（限定了栏目时带上栏目链）> 搜索「关键词」
 *   标签页：首页 > 标签：xxx
 *   最后一项（当前所在）不带链接，方便 CSS 区分
 *
 * 用法：
 *   {miu:position /}
 *   {miu:position typeid='2004' delimiter='/' /}
 *   {miu:position class='breadcrumb' delimiter='&gt;' /}
 *
 * 参数（都有默认值，填错不报错、直接在页面上输出原因）：
 *   typeid     栏目id 或 文章id，默认 0（用当前页面的栏目/文章）。
 *              ⚠ 两个 id 都是纯数字，会先当栏目id找，找不到再当文章id找。
 *              ⚠ 传了栏目id → 面包屑到该栏目为止；传了文章id → 面包屑到该文章（含所属栏目）。
 *   class      <ul> 的样式类名，默认 breadcrumb
 *   delimiter  间隔符，默认 >。写什么就输出什么（会做 HTML 转义），
 *              输出时两边会自动各补一个 &nbsp; 占位，所以写 '/' 和写 ' / ' 效果一样
 *
 * 输出的结构：
 *   <ul class="breadcrumb">
 *     <li><a href="/">首页</a><span class="delimiter">&nbsp;&gt;&nbsp;</span></li>
 *     <li><a href="/bokezixun/">博客资讯</a><span class="delimiter">&nbsp;&gt;&nbsp;</span></li>
 *     <li class="breadcrumb-item text-white active">文章标题</li>
 *   </ul>
 *   间隔符放在每一项的 <span class="delimiter"> 里，最后一项后面没有；
 *   间隔符两边是 &nbsp; 占位（普通空格会被 HTML 折叠，间距出不来）。
 *   最后一项（当前所在）会带 class="breadcrumb-item text-white active"，
 *   其它项只输出 <li>、不带 class。
 *   首页前面的房子图标没做进标签里，样式上建议用 CSS 加，例如：
 *     .breadcrumb li:first-child a::before { content: "\f015"; font-family: "FontAwesome"; }
 *
 * 另外说明：
 *   · 首页调用只会得到「首页」一项，这时直接不输出（首页不需要面包屑）
 *   · 栏目或文章不存在时也不输出，不会报错
 */
trait PositionTag
{
    public function tagPosition(array $tag, string $content): string
    {
        // ---------------- 属性白名单 ----------------
        $allowAttr = ['typeid', 'class', 'delimiter'];
        $err       = $this->checkAttrs($tag, $allowAttr, 'miu:position', $content);
        if ($err !== '') {
            return $err;
        }

        // ---------------- typeid ----------------
        $typeid = trim((string) ($tag['typeid'] ?? ''));
        if ($typeid === '') {
            $typeid = '0';
        }
        if (!preg_match('/^\d+$/', $typeid)) {
            return $this->tagError('miu:position', "typeid 只能是栏目id 或 文章id（纯数字），现在写的是：{$typeid}", $content);
        }

        // ---------------- class ----------------
        $class = trim((string) ($tag['class'] ?? ''));
        if ($class === '') {
            $class = 'breadcrumb';
        }
        if (!preg_match('/^[A-Za-z0-9_\- ]{1,64}$/', $class)) {
            return $this->tagError('miu:position', "class 只能是字母、数字、下划线、短横线或空格，现在写的是：{$class}", $content);
        }

        // ---------------- delimiter ----------------
        //先去掉首尾空白；输出时 miu_position_html() 会在两边各补一个 &nbsp; 占位
        $delimiter = trim((string) ($tag['delimiter'] ?? ''));
        if ($delimiter === '') {
            $delimiter = '>';
        }
        if (mb_strlen($delimiter, 'UTF-8') > 32) {
            return $this->tagError('miu:position', 'delimiter 最多 32 个字符，现在写的是：' . $delimiter, $content);
        }

        $opt = [
            'typeid'    => (int) $typeid,
            'class'     => $class,
            'delimiter' => $delimiter,
        ];

        return '<?php echo miu_position_html(' . var_export($opt, true) . '); ?>';
    }
}
