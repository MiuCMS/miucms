<?php
/**
 * @copyright Copyright (c) 2026 米柚工作室
 * @license https://opensource.org/licenses/Apache-2.0
 * @link https://www.miucms.com
 */

declare (strict_types = 1);

namespace app\home\taglib\traits;

/**
 * {miu:positionraw} 面包屑原始数据标签（成对标签）
 *
 * 想要自己排版面包屑（不用 {miu:position} 那套 ul/li）时用它，
 * 数据跟 {miu:position} 是同一份，每一项有：
 *   {$vo.name} 名称（首页 / 栏目名 / 文章标题）
 *   {$vo.url}  地址（默认末级为空，currenturl='1' 时也生成末级地址）
 *   {$vo.full_url} 带网站域名的完整地址
 *
 * 用法：
 *   {miu:positionraw name='vo'}
 *       {if condition="$vo.url"}<a href="{$vo.url}">{$vo.name}</a>{else/}<span>{$vo.name}</span>{/if}
 *       {if condition="$index == 1"}...{/if}
 *   {/miu:positionraw}
 *   要给末级也生成链接时：{miu:positionraw name='vo' currenturl='1'}
 *
 * 参数（都有默认值，填错不报错、直接在页面上输出原因）：
 *   id     取值对象名，默认 vo（也可以写成 name；两个都写时以 id 为准）
 *   key    索引变量名，默认 index；从 1 开始
 *   currenturl 设为 1 时生成末级面包屑的 url 和 full_url，默认 0
 *
 * 另外说明：
 *   · 数据来源跟 {miu:position} 一样，所以也只在列表页、内容页有值；
 *     首页等地方列表为空，标签内部不会输出
 */
trait PositionrawTag
{
    public function tagPositionraw(array $tag, string $content): string
    {
        // ---------------- 取值对象名 / 索引变量名 ----------------
        [$id, $key] = $this->parseLoopVars($tag);

        $err = $this->checkLoopVars($id, $key, 'miu:positionraw', $content);
        if ($err !== '') {
            return $err;
        }

        // ---------------- 属性白名单 ----------------
        $allowAttr = ['id', 'name', 'key', 'currenturl'];
        $err       = $this->checkAttrs($tag, $allowAttr, 'miu:positionraw', $content);
        if ($err !== '') {
            return $err;
        }

        $currentUrl = trim((string) ($tag['currenturl'] ?? '0'));
        if (!in_array($currentUrl, ['0', '1'], true)) {
            return $this->tagError('miu:positionraw', "currenturl 只能是 0 或 1，现在写的是：{$currentUrl}", $content);
        }

        // ---------------- 生成编译代码 ----------------
        $list    = '$__miu_pos_' . (++$this->navSeq);
        $counter = '$__miu_pos_index_' . $this->navSeq;

        $parseStr  = '<?php ' . $list . ' = miu_position(0, ' . ($currentUrl === '1' ? 'true' : 'false') . '); ?>';
        //列表为空（比如首页）就什么都不输出，也不算错
        //注意结尾的括号：loopCatch() 自带 catch 的收尾「}」，这里只需要再补一个「}」关掉 if
        $parseStr .= '<?php if (count(' . $list . ') > 0) { ' . $counter . ' = 0; try { foreach (' . $list . ' as $' . $id . '): $' . $key . ' = ++' . $counter . '; ?>';
        $parseStr .= $content;
        $parseStr .= '<?php endforeach; ' . $this->loopCatch($id, $key, 'miu:positionraw') . ' } ?>';

        return $parseStr;
    }
}
