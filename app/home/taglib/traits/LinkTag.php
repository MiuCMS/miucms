<?php
/**
 * @copyright Copyright (c) 2026 米柚工作室
 * @license https://opensource.org/licenses/Apache-2.0
 * @link https://www.miucms.com
 */

declare (strict_types = 1);

namespace app\home\taglib\traits;

/**
 * {miu:link} 友情链接列表标签（读 cms_links 表）
 *
 * 用法：
 *   {miu:link id='vo'}
 *       <a href="{$vo.url}"{if condition="$vo.target_blank"} target="_blank"{/if}>{$vo.name}</a>
 *   {/miu:link}
 *
 *   {miu:link name='vo' key='idx'}
 *       <li class="item-{$idx}"><a href="{$vo.src}">{$vo.name}</a></li>
 *   {/miu:link}
 *
 *   {miu:link id='vo'}                     带 logo 的写法
 *       {notempty name="vo.logo_url"}<img src="{$vo.logo_url}" alt="{$vo.name}">{/notempty}
 *       <a href="{$vo.url}">{$vo.name}</a>
 *   {/miu:link}
 *
 * 参数（都有默认值，填错不报错、直接在页面上输出原因）：
 *   id     循环变量名，默认 vo
 *   name   跟 id 一样，是它的别名（两个都写时以 id 为准，模板里习惯写哪个都行）
 *   key    索引变量名，默认 index；循环到第几项就存在这个变量里，从 0 开始
 *
 * 每条链接可用的字段（见 app/home/common.php 的 miu_link_list()）：
 *   {$vo.id}           链接id
 *   {$vo.name}         网站名称
 *   {$vo.src} {$vo.url} 链接地址（url 是 src 的别名，两个一样）
 *   {$vo.full_url}     带网站域名的完整链接（外链原样保留）
 *   {$vo.logo}         后台存的 logo 原值（附件id 或图片地址）
 *   {$vo.logo_url}     可以直接放进 <img src=""> 的图片地址（没设 logo 时是空串）
 *   {$vo.target}       后台原值：1=新窗口打开 0=当前窗口
 *   {$vo.target_blank} 同上，但直接就是 1/0，模板里 {if condition="$vo.target_blank"} 比 {eq} 直观
 *   {$vo.sort} {$vo.status} {$vo.create_time} {$vo.update_time}
 *   {$vo.date}         create_time 的 Y-m-d
 *
 * 另外说明：
 *   · 只显示后台「状态 = 可用」的链接（status = 1）
 *   · 排序跟后台友情链接列表一致：sort 倒序，再按 id 倒序
 *   · 列表为空时标签内部什么都不输出，不会报错
 *   · 字段值输出会做 HTML 转义；{$vo.logo_url} 这种图片地址不用 raw
 */
trait LinkTag
{
    public function tagLink(array $tag, string $content): string
    {
        // ---------------- 循环变量名 / 索引变量名 ----------------
        [$id, $key] = $this->parseLoopVars($tag);

        $err = $this->checkLoopVars($id, $key, 'miu:link', $content);
        if ($err !== '') {
            return $err;
        }

        // ---------------- 属性白名单 ----------------
        $allowAttr = ['id', 'name', 'key'];
        $err       = $this->checkAttrs($tag, $allowAttr, 'miu:link', $content);
        if ($err !== '') {
            return $err;
        }

        // ---------------- 生成编译代码 ----------------
        //成对标签：$content 是 ThinkPHP 的占位符，标签内部内容会留在它所在的位置
        $list    = '$__miu_link_' . (++$this->navSeq);
        $counter = '$__miu_link_index_' . $this->navSeq;

        $parseStr  = '<?php ' . $list . ' = miu_link_list(); ?>';
        //列表为空就什么都不输出，也不算错
        //注意结尾的括号：loopCatch() 自带 catch 的收尾「}」，这里只需要再补一个「}」关掉 if
        $parseStr .= '<?php if (count(' . $list . ') > 0) { ' . $counter . ' = -1; try { foreach (' . $list . ' as $' . $id . '): $' . $key . ' = ++' . $counter . '; ?>';
        $parseStr .= $content;
        $parseStr .= '<?php endforeach; ' . $this->loopCatch($id, $key, 'miu:link') . ' } ?>';

        return $parseStr;
    }
}
