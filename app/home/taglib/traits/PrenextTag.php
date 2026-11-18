<?php
/**
 * @copyright Copyright (c) 2026 米柚工作室
 * @license https://opensource.org/licenses/Apache-2.0
 * @link https://www.miucms.com
 */

declare (strict_types = 1);

namespace app\home\taglib\traits;

/**
 * {miu:prenext} 上一篇 / 下一篇（成对标签，只在内容页有意义）
 *
 * 用法：
 *   {miu:prenext name='vo' type='pre'}
 *       <a href="{$vo.link}" class="{$vo.disclass}" title="{$vo.title_full}">上一篇：{$vo.title ?? "没有了"}</a>
 *   {/miu:prenext}
 *   {miu:prenext name='vo' type='next'}
 *       <a href="{$vo.link}" class="{$vo.disclass}" title="{$vo.title_full}">下一篇：{$vo.title ?? "没有了"}</a>
 *   {/miu:prenext}
 *
 * 标签内部只输出一次（不是循环），取值都在 {$vo} 里：
 *   {$vo.title}      标题（按 titlelen 截断过；没有上下篇时是 null，配 ?? 用）
 *   {$vo.title_full} 完整标题（不截断，一般放 title 属性里）
 *   {$vo.link}       详情页地址（没有上下篇时是 javascript:;）
 *   {$vo.arcurl}     {$vo.link} 的别名，写法习惯用哪个都行
 *   {$vo.full_url}   带网站域名的完整详情页地址（没有上下篇时是 javascript:;）
 *   {$vo.disclass}   禁用样式 class（没有上下篇时是 disclass 参数的值，有上下篇时是空串）
 *
 * ------------------------------------------------------------------
 * 参数说明（都有默认值，填错不报错、直接在页面上输出原因）
 * ------------------------------------------------------------------
 * id        取值对象名，默认 vo（也可以写成 name；两个都写时以 id 为准）
 * key       遍历索引名，默认 index。标签只输出一次，所以它恒为 0；
 *           留着是为了跟别的标签写法一致，模板里写 {$index} 也不会报错
 * type      pre=上一篇  next=下一篇，默认 next
 * titlelen  标题截断长度，默认 30（按字符数算，中文算 1 个，截断不加省略号）
 *           填 0 = 不截断
 * disclass  没有上下篇时给 {$vo.disclass} 的 class，默认 disabled
 *
 * ------------------------------------------------------------------
 * 额外说明
 * ------------------------------------------------------------------
 * · 只在「内容页（详情页）」有值：取值来自当前正在渲染的那篇文档。
 *   首页、栏目列表页上没有当前文档，一律按「没有上下篇」处理
 *   （{$vo.title} 是 null、{$vo.disclass} 是禁用 class），不会报错。
 * · 「上一篇」= 同栏目里 id 比它小的最大那篇，「下一篇」= id 比它大的最小那篇，
 *   只看已发布的文档。跟详情页模板里的 {$prev} / {$next} 是同一份数据（同一个函数），
 *   静态生成时的「更新上下篇」也用它，三处排序规则永远一致。
 * · 没有上下篇时 {$vo.link} 是 javascript:; —— 直接拿它当 href 就行，
 *   点了不跳走，也不会把页面刷新一遍。
 * · 后台改了栏目标题、文档、URL配置后，记得去后台点一下「清缓存」；
 *   标签库本身的改动也要清缓存才会重新编译模板。
 */
trait PrenextTag
{
    public function tagPrenext(array $tag, string $content): string
    {
        // ---------------- 取值对象名 / 索引变量名 ----------------
        [$id, $key] = $this->parseLoopVars($tag);

        $err = $this->checkLoopVars($id, $key, 'miu:prenext', $content);
        if ($err !== '') {
            return $err;
        }

        // ---------------- 属性白名单 ----------------
        $allowAttr = ['id', 'name', 'key', 'type', 'titlelen', 'disclass'];
        $err       = $this->checkAttrs($tag, $allowAttr, 'miu:prenext', $content);
        if ($err !== '') {
            return $err;
        }

        // ---------------- type ----------------
        $type = strtolower(trim((string) ($tag['type'] ?? '')));
        if ($type === '') {
            $type = 'next';
        }
        if (!in_array($type, ['pre', 'next'], true)) {
            return $this->tagError('miu:prenext', "type 只能是 pre（上一篇）或 next（下一篇），现在写的是：{$type}", $content);
        }

        // ---------------- titlelen ----------------
        $titlelen = trim((string) ($tag['titlelen'] ?? ''));
        if ($titlelen === '') {
            $titlelen = '30';
        }
        if (!preg_match('/^\d+$/', $titlelen)) {
            return $this->tagError('miu:prenext', "titlelen 只能填数字（按字符数算，中文算 1 个），现在写的是：{$titlelen}", $content);
        }

        // ---------------- disclass ----------------
        //没写就用默认值；写了空的（disclass=''）表示「不要禁用样式」，所以只 trim、不兜底
        $disclass = array_key_exists('disclass', $tag) ? trim((string) $tag['disclass']) : 'disabled';

        // ---------------- 生成编译代码 ----------------
        //只输出一次：把取值对象和索引赋好，标签内部内容原样输出
        $var = '$__miu_prenext_' . (++$this->navSeq);

        $parseStr  = '<?php ' . $var . ' = miu_prenext(' . var_export($type, true) . ', ' . (int) $titlelen . ', ' . var_export($disclass, true) . '); ?>';
        $parseStr .= '<?php $' . $id . ' = ' . $var . '; $' . $key . ' = 0; try { ?>';
        $parseStr .= $content;
        $parseStr .= '<?php ' . $this->loopCatch($id, $key, 'miu:prenext') . ' ?>';

        return $parseStr;
    }
}
