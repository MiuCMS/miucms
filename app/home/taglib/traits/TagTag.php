<?php
/**
 * @copyright Copyright (c) 2026 米柚工作室
 * @license https://opensource.org/licenses/Apache-2.0
 * @link https://www.miucms.com
 */

declare (strict_types = 1);

namespace app\home\taglib\traits;

/**
 * {miu:tag} 标签列表标签（读 cms_keywords 表）
 *
 * 用法：
 *   {miu:tag name='vo'}
 *       <a href="{$vo.url}">{$vo.title}</a><span>{$vo.count}</span>
 *   {/miu:tag}
 *
 *   {miu:tag id='vo' key='idx' gettype='1' num='10' sort='hot'}
 *       <li class="item-{$idx}"><a href="{$vo.url}">{$vo.title}（{$vo.click}）</a></li>
 *   {/miu:tag}
 *
 * ------------------------------------------------------------------
 * 参数说明（每个都有默认值，填错不报错、直接在页面上输出原因）
 * ------------------------------------------------------------------
 * id       取值对象名，默认 vo（也可以写成 name；两个都写时以 id 为准）
 * name     同上，是 id 的别名
 * key      遍历索引名，默认 index；循环到第几项就存在这个变量里，从 0 开始
 * empty    没有数据时显示的文案，默认空
 * gettype  获取类型：0 = 当前内容页的 TAG 标记（默认），1 = 获取全部 TAG 标记
 *          ⚠ 内容页才用 0；首页、列表页这类没有「当前文档」的页面写 0 会得到空列表
 * num      标签数量，默认 0 = 全部
 * typeid   标签分类（后台「标签管理 → 分组管理」）的 id，默认空 = 全部分类的标签
 * sort     排序方式，默认 hot：
 *            new   按最新（add_time 倒序）
 *            rand  随机
 *            week  按周统计（近 7 天发布的、带该标签的文档数，倒序）
 *            month 按月统计（近 30 天发布的、带该标签的文档数，倒序）
 *            hot   按点击数（click 倒序）
 *            total 按文档数（带该标签的文档总数，倒序）
 *
 * ------------------------------------------------------------------
 * 每个标签可用的字段（见 app/home/common.php 的 miu_tag_list()）
 * ------------------------------------------------------------------
 *   {$vo.id}           标签id
 *   {$vo.title}        标签名
 *   {$vo.group_id}     标签分类的 id（对应后台「标签管理 → 分组管理」）
 *   {$vo.click}        点击数（访问标签页会 +1，见 app/home/controller/Tag.php）
 *   {$vo.url}          由 miu_tag_url() 生成的标签页地址，适配语言和静态规则
 *   {$vo.full_url}     带网站域名的完整标签页地址
 *   {$vo.count}        带这个标签的文档总数
 *   {$vo.week_count}   近 7 天发布的、带这个标签的文档数
 *   {$vo.month_count}  近 30 天发布的、带这个标签的文档数
 *   {$vo.sort} {$vo.status} {$vo.add_time} {$vo.update_time}
 *   {$vo.date}         add_time 的 Y-m-d
 *
 * 另外说明：
 *   · 只显示后台「状态 = 启用」的标签（status = 1）
 *   · 列表为空时标签内部什么都不输出，可以用 empty='暂无标签' 给个兜底文案
 *   · 「近 7 天 / 近 30 天」按文档 add_time 算，不是访问时间，也不是 release_time
 *   · 标签和文档变更会清理对应的 homeTag / Document 缓存，静态页仍需重新生成
 */
trait TagTag
{
    public function tagTag(array $tag, string $content): string
    {
        // ---------------- 循环变量名 / 索引变量名 ----------------
        [$id, $key] = $this->parseLoopVars($tag);

        $err = $this->checkLoopVars($id, $key, 'miu:tag', $content);
        if ($err !== '') {
            return $err;
        }

        // ---------------- 属性白名单 ----------------
        $allowAttr = ['id', 'name', 'key', 'empty', 'gettype', 'num', 'typeid', 'sort'];
        $err       = $this->checkAttrs($tag, $allowAttr, 'miu:tag', $content);
        if ($err !== '') {
            return $err;
        }

        // ---------------- gettype ----------------
        $gettype = trim((string) ($tag['gettype'] ?? ''));
        if ($gettype === '') {
            $gettype = '0';
        }
        if (!in_array($gettype, ['0', '1'], true)) {
            return $this->tagError('miu:tag', "gettype 只能是 0（当前内容页的标签）或 1（全部标签），现在写的是：{$gettype}", $content);
        }

        // ---------------- num ----------------
        $num = trim((string) ($tag['num'] ?? ''));
        if ($num === '') {
            $num = '0';
        }
        if (!preg_match('/^\d+$/', $num)) {
            return $this->tagError('miu:tag', "num 只能填数字（取几个标签，0 = 全部），现在写的是：{$num}", $content);
        }

        // ---------------- typeid ----------------
        //标签分类的 id，留空 = 全部分类的标签
        $typeid = trim((string) ($tag['typeid'] ?? ''));
        if ($typeid !== '' && !preg_match('/^\d+$/', $typeid)) {
            return $this->tagError('miu:tag', "typeid 只能填数字（标签分类的 id，留空 = 全部分类），现在写的是：{$typeid}", $content);
        }

        // ---------------- sort ----------------
        $sort = strtolower(trim((string) ($tag['sort'] ?? '')));
        if ($sort === '') {
            $sort = 'hot';
        }
        if (!in_array($sort, ['new', 'rand', 'week', 'month', 'hot', 'total'], true)) {
            return $this->tagError(
                'miu:tag',
                "sort 只能是 new（最新）/ rand（随机）/ week（按周统计）/ month（按月统计）/ hot（按点击数）/ total（按文档数），现在写的是：{$sort}",
                $content
            );
        }

        // ---------------- empty ----------------
        $empty = (string) ($tag['empty'] ?? '');

        // ---------------- 生成编译代码 ----------------
        //成对标签：$content 是 ThinkPHP 的占位符，标签内部内容会留在它所在的位置
        $list    = '$__miu_tag_' . (++$this->navSeq);
        $counter = '$__miu_tag_index_' . $this->navSeq;

        $opt = [
            'gettype' => (int) $gettype,
            'num'     => (int) $num,
            'typeid'  => (int) $typeid,
            'sort'    => $sort,
        ];

        $parseStr  = '<?php ' . $list . ' = miu_tag_list(' . var_export($opt, true) . '); ?>';
        $parseStr .= '<?php if (count(' . $list . ') == 0) : echo ' . var_export($empty, true) . ' ; ?>';
        $parseStr .= '<?php else: ' . $counter . ' = -1; try { foreach (' . $list . ' as $' . $id . '): $' . $key . ' = ++' . $counter . '; ?>';
        $parseStr .= $content;
        $parseStr .= '<?php endforeach; ' . $this->loopCatch($id, $key, 'miu:tag') . ' endif; ?>';

        return $parseStr;
    }
}
