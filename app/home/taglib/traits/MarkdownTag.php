<?php
/**
 * @copyright Copyright (c) 2026 米柚工作室
 * @license https://opensource.org/licenses/Apache-2.0
 * @link https://www.miucms.com
 */

declare (strict_types = 1);

namespace app\home\taglib\traits;

/**
 * {miu:markdown} 将模板变量中的 Markdown 转成安全 HTML。
 * 用法：{miu:markdown value="$doc.md_content"/}
 */
trait MarkdownTag
{
    public function tagMarkdown(array $tag, string $content): string
    {
        $err = $this->checkAttrs($tag, ['value'], 'miu:markdown', $content);
        if ($err !== '') {
            return $err;
        }

        $value = trim((string) ($tag['value'] ?? ''));
        if (!preg_match('/^\$[A-Za-z_][A-Za-z0-9_]*(?:\.[A-Za-z_][A-Za-z0-9_]*)*$/', $value)) {
            return $this->tagError('miu:markdown', 'value 请填写模板变量，例如 $doc.md_content', $content);
        }

        $expression = $this->autoBuildVar($value);

        return '<?php echo miu_markdown_to_html((string) (' . $expression . ')); ?>';
    }
}
