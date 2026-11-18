<?php
/**
 * @copyright Copyright (c) 2026 米柚工作室
 * @license https://opensource.org/licenses/Apache-2.0
 * @link https://www.miucms.com
 */

declare (strict_types = 1);

namespace app\home\taglib\traits;

/**
 * {miu:pagelist} 分页标签（自闭合 {miu:pagelist ... /}）
 *
 * ⚠ 需要分页上下文，栏目、搜索和标签列表页均可用。
 *   栏目页由 miu_render_nav_list() 写入，搜索及标签页通过 base_url 等信息提供上下文；
 *   首页、详情页没有这份数据时，标签会输出一条 HTML 注释
 *   说明原因，页面上看不到东西，也不会报错。
 *
 * 用法：
 *   {miu:pagelist /}
 *   {miu:pagelist type='mini' /}
 *   {miu:pagelist type='full' listitem='index,pre,pageno,next,end,info' /}
 *   {miu:pagelist theme='dark' /}
 *   {miu:pagelist listitem='pre,pageno,next' currentstyle='on'
 *                 indextext='第一页' pretext='上一页' nexttext='下一页' endtext='最后一页' /}
 *
 * 参数（都有默认值，填错不报错、直接在页面上输出原因）：
 *   type          分页显示类型，full / mini，默认 full
 *                   · full：按 listitem 显示全部
 *                   · mini：只显示「上一页」「下一页」（此时 listitem 忽略）
 *   currentstyle  当前页的 class 名，默认 active（会加在 li 上，和 miu-page 并列）
 *   listitem      分页结构，逗号隔开；default 主题默认 index,pre,pageno,next,end,info，dark/light 默认 pre,pageno,next,info
 *                   · index  首页        · pre   上一页
 *                   · pageno 页码（当前页前后各 2 页，最多 5 个）
 *                   · next   下一页      · end   末页
 *                   · info   共 N 页 N 条
 *   indextext     首页文字，默认 首页
 *   pretext       上一页文字，默认 上一页
 *   nexttext      下一页文字，默认 下一页
 *   endtext       末页文字，默认 末页
 *   theme         分页样式，default（默认，保留原样式）/ dark / light
 *
 * default 主题输出原有的 <ul class="miu-pagination">；dark/light 主题输出独立的 .pager-scope 样式区，
 * 当前主题边界按钮会以 aria-disabled="true" 标记且不带链接。
 *
 * default 主题输出的结构（每个按钮都是 <li class="miu-page">）：
 *   <ul class="miu-pagination">
 *     <li class="miu-page"><a href="/bokezixun/p2.html">1</a></li>
 *     <li class="miu-page active"><a href="...">2</a></li>            ← 当前页
 *     <li class="miu-page"><a href="/bokezixun/list_3.html">3</a></li>
 *     <li class="miu-page miu-page-info">共 3 页 12 条</li>
 *   </ul>
 *   每个按钮都带 <a>，不会出现灰色不可点的按钮：
 *     · 第 1 页时不输出「首页」「上一页」
 *     · 最后一页时不输出「下一页」「末页」
 *
 * 样式：输出里自带一段分页 CSS（包在 <style> 里，一个页面只输出一次），
 *       开箱即用；想改外观改 app/home/common.php 里 miu_pagelist() 的 $css。
 *
 * 另外说明：
 *   · 只有一页时不输出分页
 *   · 分页链接按后台「SEO → URL配置」的规则生成，三种模式都自动适配
 *   · 想自己排版就用 {$page_no} {$page_count} {$total} 这几个变量，
 *     单页地址用 {:nav_url($nav, 页码)} 生成
 */
trait PagelistTag
{
    public function tagPagelist(array $tag, string $content): string
    {
        // ---------------- 属性白名单 ----------------
        $allowAttr = ['type', 'currentstyle', 'listitem', 'indextext', 'pretext', 'nexttext', 'endtext', 'theme'];
        $err       = $this->checkAttrs($tag, $allowAttr, 'miu:pagelist', $content);
        if ($err !== '') {
            return $err;
        }

        // ---------------- type ----------------
        $type = strtolower(trim((string) ($tag['type'] ?? '')));
        if ($type === '') {
            $type = 'full';
        }
        if (!in_array($type, ['full', 'mini'], true)) {
            return $this->tagError('miu:pagelist', "type 只能是 full 或 mini，现在写的是：{$type}", $content);
        }

        // ---------------- theme ----------------
        $theme = strtolower(trim((string) ($tag['theme'] ?? '')));
        if ($theme === '') {
            $theme = 'default';
        }
        if (!in_array($theme, ['default', 'dark', 'light'], true)) {
            return $this->tagError('miu:pagelist', "theme 只能是 default、dark 或 light，现在写的是：{$theme}", $content);
        }

        // ---------------- currentstyle ----------------
        $currentstyle = trim((string) ($tag['currentstyle'] ?? ''));
        if ($currentstyle === '') {
            $currentstyle = 'active';
        }
        if (!preg_match('/^[A-Za-z0-9_\-]{1,32}$/', $currentstyle)) {
            return $this->tagError('miu:pagelist', "currentstyle 只能是字母、数字、下划线或短横线，现在写的是：{$currentstyle}", $content);
        }

        // ---------------- listitem ----------------
        $listitem = trim((string) ($tag['listitem'] ?? ''));
        if ($listitem === '') {
            $listitem = $theme === 'default' ? 'index,pre,pageno,next,end,info' : 'pre,pageno,next,info';
        }
        $allowItem = ['index', 'pre', 'pageno', 'next', 'end', 'info'];
        $items     = [];
        $bad       = [];
        foreach (explode(',', $listitem) as $item) {
            $item = trim($item);
            if ($item === '') {
                continue;
            }
            if (!in_array($item, $allowItem, true)) {
                $bad[] = $item;
            } elseif (!in_array($item, $items, true)) {
                $items[] = $item;
            }
        }
        if ($bad) {
            return $this->tagError('miu:pagelist', 'listitem 里有不认识的项：' . implode('、', $bad) . '。可选的有：' . implode('、', $allowItem), $content);
        }
        if (empty($items)) {
            return $this->tagError('miu:pagelist', 'listitem 至少要留一项，可选的有：' . implode('、', $allowItem), $content);
        }

        // ---------------- 按钮文字（写了空的就用默认） ----------------
        $text = function ($key, $default) use ($tag) {
            $val = trim((string) ($tag[$key] ?? ''));
            return $val === '' ? $default : $val;
        };

        $opt = [
            'type'         => $type,
            'theme'        => $theme,
            'currentstyle' => $currentstyle,
            'listitem'     => implode(',', $items),
            'indextext'    => $text('indextext', '首页'),
            'pretext'      => $text('pretext', '上一页'),
            'nexttext'     => $text('nexttext', '下一页'),
            'endtext'      => $text('endtext', '末页'),
        ];

        return '<?php echo miu_pagelist(' . var_export($opt, true) . '); ?>';
    }
}
