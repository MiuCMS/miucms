<?php
/**
 * @copyright Copyright (c) 2026 米柚工作室
 * @license https://opensource.org/licenses/Apache-2.0
 * @link https://www.miucms.com
 */

declare(strict_types=1);

namespace app\home\taglib\traits;

/**
 * {miu:langlist} 当前模板已启用语言列表（成对循环标签）。
 *
 * 用法：
 *   {miu:langlist id='vo' key='i' empty='暂无可切换语言'}
 *       <a href="{$vo.url}" class="language-item">{$vo.name}</a>
 *   {/miu:langlist}
 *
 * 循环变量（默认 vo，也可以用 id 或 name 改名）包含：
 *   {$vo.code}    语言代码，例如 en
 *   {$vo.name}    语言显示名称，例如 English
 *   {$vo.url}     当前页面切换到该语言后的地址
 *   {$vo.full_url} 带网站域名的完整地址
 *   {$vo.current} 当前语言标记，1 表示当前项，0 表示其他语言
 *
 * 参数：
 *   id/name  循环变量名，两个都写时以 id 为准
 *   key      索引变量名，从 0 开始，默认 index
 *   empty    语言列表为空时输出的内容，默认不输出
 *
 * 标签只提供循环数据，不生成任何 HTML；列表结构和 CSS 样式由模板自行编写。
 */
trait LangListTag
{
    public function tagLanglist(array $tag, string $content): string
    {
        //和 LinkTag 保持一致：先解析并校验循环变量名，避免生成非法 PHP。
        [$id, $key] = $this->parseLoopVars($tag);
        $error = $this->checkLoopVars($id, $key, 'miu:langlist', $content);
        if ($error !== '') {
            return $error;
        }

        //只允许控制循环变量和空列表文案的属性，HTML 结构交给模板维护。
        $error = $this->checkAttrs($tag, ['id', 'name', 'key', 'empty'], 'miu:langlist', $content);
        if ($error !== '') {
            return $error;
        }

        $empty = (string) ($tag['empty'] ?? '');

        //每个标签实例使用独立的列表和计数器，防止同页重复或嵌套时变量相互覆盖。
        $list = '$__miu_langlist_' . (++$this->navSeq);
        $counter = '$__miu_langlist_index_' . $this->navSeq;

        //运行时准备语言数组，然后像 LinkTag 一样遍历数组并渲染标签体。
        $parseStr  = '<?php ' . $list . ' = \\app\\home\\service\\TemplateI18n::languageList(); ?>';
        $parseStr .= '<?php if (count(' . $list . ') === 0) : echo ' . var_export($empty, true) . '; ?>';
        $parseStr .= '<?php else: ' . $counter . ' = -1; try { foreach (' . $list . ' as $' . $id . '): $' . $key . ' = ++' . $counter . '; ?>';
        $parseStr .= $content;
        $parseStr .= '<?php endforeach; ' . $this->loopCatch($id, $key, 'miu:langlist') . ' endif; ?>';

        return $parseStr;
    }
}
