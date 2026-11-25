<?php
declare(strict_types=1);

namespace app\services;

/** 用源码偏移记录固定内容。预览标记只写入临时模板。 */
final class TemplateVisualSource
{
    public static function inspect(string $source): array
    {
        // 内嵌 PHP 可能控制后续整段输出，无法证明其来源时保持只读。
        if (!preg_match('//u', $source) || preg_match('~<\?(?!xml\b)|\{(?:php|\/php)\s*\}~i', $source)) {
            return [];
        }

        $pattern = <<<'REGEX'
~<!--.*?(?:-->|\z)|\{\*.*?(?:\*\}|\z)|\{/\*.*?(?:\*/\}|\z)|<(script|style|textarea|title|template|noscript|iframe|svg|math)(?=[\s/>])(?:"[^"]*"|'[^']*'|[^'">]++)*>(?:.*?</\1\s*>|\z)|\{(?:"(?:\\.|[^"\\]++)*"|'(?:\\.|[^'\\]++)*'|[^'"}]++)*\}|<(?=[A-Z!/?])(?:"[^"]*"|'[^']*'|[^'">]++)*>|[^<{]+|[<{]~is
REGEX;
        if (!preg_match_all($pattern, $source, $tokens, PREG_OFFSET_CAPTURE)) {
            return [];
        }

        $result = [];
        $responsiveImages = [];
        $dynamic = [];
        $parents = [];
        $void = ['area', 'base', 'br', 'col', 'embed', 'hr', 'img', 'input', 'link', 'meta', 'param', 'source', 'track', 'wbr'];
        foreach ($tokens[0] as [$value, $offset]) {
            if ($value[0] === '{') {
                if (preg_match('~^\{(/?)([a-z][\w:-]*)(?=[\s}/])~i', $value, $tag)) {
                    $name = strtolower($tag[2]);
                    if (in_array($name, ['block', 'extend', 'include', 'layout', 'taglib', 'else', 'elseif', 'case', 'default'], true)) {
                        continue;
                    }
                    if ($tag[1] === '/' && ($key = array_search($name, array_reverse($dynamic, true), true)) !== false) {
                        $dynamic = array_slice($dynamic, 0, $key);
                    } elseif ($tag[1] === '' && !preg_match('~/\s*\}$~', $value)) {
                        $dynamic[] = $name;
                    }
                }
                continue;
            }
            if ($value[0] === '<') {
                if (!preg_match('~^<(/?)([a-z][a-z0-9:-]*)(?=[\s/>])~i', $value, $tag)) {
                    continue;
                }
                $name = strtolower($tag[2]);
                if ($tag[1] === '/') {
                    $key = array_search($name, array_reverse($parents, true), true);
                    if ($key !== false) {
                        $parents = array_slice($parents, 0, $key);
                    }
                    continue;
                }
                if (in_array($name, ['script', 'style', 'textarea', 'title', 'template', 'noscript', 'iframe', 'svg', 'math'], true)) {
                    continue;
                }
                if ($name === 'img' && !$dynamic && !in_array('picture', $parents, true)) {
                    $image = self::image($value, $offset);
                    if (isset($image['srcset'])) {
                        $responsiveImages[] = $image;
                    } elseif ($image !== null) {
                        $result[] = $image;
                    }
                }
                if (!in_array($name, $void, true) && !str_ends_with($value, '/>')) {
                    $parents[] = $name;
                }
                continue;
            }
            if ($dynamic || array_intersect($parents, ['head', 'option', 'select', 'table', 'thead', 'tbody', 'tfoot', 'tr'])
                && !array_intersect($parents, ['td', 'th', 'caption'])) {
                continue;
            }
            $text = trim($value);
            if ($text !== '' && !self::includePlaceholder($text) && trim(html_entity_decode($text, ENT_QUOTES | ENT_HTML5, 'UTF-8')) !== '') {
                $start = $offset + strlen($value) - strlen(ltrim($value));
                $result[] = ['kind' => 'text', 'start' => $start, 'length' => strlen($text), 'value' => $text, 'marker' => $start];
            }
        }
        // 新识别的图片排在原有元素之后，兼容已经打开的预览中的元素编号。
        return array_merge($result, $responsiveImages);
    }

    private static function image(string $tag, int $offset): ?array
    {
        // 属性按引号解析，避免把 alt 文本或 data-src 里的 src 当作真正的 src。
        $pattern = <<<'REGEX'
~\G\s+([a-z_:][\w:.-]*)(?:\s*=\s*(?:"([^"]*)"|'([^']*)'|([^\s"'=<>`]+)))?~i
REGEX;
        $at = 4;
        $sources = [];
        while (preg_match($pattern, $tag, $match, PREG_OFFSET_CAPTURE | PREG_UNMATCHED_AS_NULL, $at)) {
            $at += strlen($match[0][0]);
            $name = strtolower($match[1][0]);
            if ($name === 'data-src' || $name === 'data-srcset') {
                return null;
            }
            if ($name !== 'src' && $name !== 'srcset') {
                continue;
            }
            $value = $match[2][0] !== null ? $match[2] : $match[3];
            if (isset($sources[$name]) || $value[0] === null) {
                return null;
            }
            $literal = $name === 'src'
                ? preg_replace('~^\{__(?:STATIC|ASSETS|IMG)__\}~', '', $value[0])
                : str_replace(['{__STATIC__}', '{__ASSETS__}', '{__IMG__}'], '', $value[0]);
            if (strpbrk($literal, '{}<>') !== false || self::includePlaceholder($literal)) {
                return null;
            }
            $sources[$name] = ['start' => $offset + $value[1], 'length' => strlen($value[0]), 'value' => $value[0]];
        }
        if (empty($sources['src']['length']) || !preg_match('~^\s*/?>$~D', substr($tag, $at))) {
            return null;
        }
        $image = $sources['src'] + ['kind' => 'image', 'marker' => $offset + 4];
        if (isset($sources['srcset'])) {
            $image['srcset'] = $sources['srcset'];
        }
        return $image;
    }

    private static function includePlaceholder(string $text): bool
    {
        // ThinkPHP 的 include 属性可替换片段中的 [name] 占位符。
        return (bool) preg_match('/\[[A-Za-z_][A-Za-z0-9_-]*\]/', $text);
    }

    public static function mark(string $source, array $elements, string $key): string
    {
        uasort($elements, static fn (array $a, array $b): int => $b['marker'] <=> $a['marker']);
        foreach ($elements as $index => $element) {
            $id = $key . ':' . $index;
            if ($element['kind'] === 'image') {
                $source = substr_replace($source, ' data-miu-visual="' . $id . '"', $element['marker'], 0);
            } else {
                $start = $element['start'];
                $source = substr_replace($source, '<!--miu-visual-end:' . $id . '-->', $start + $element['length'], 0);
                $source = substr_replace($source, '<!--miu-visual:' . $id . '-->', $start, 0);
            }
        }
        return $source;
    }

    public static function escape(string $value): string
    {
        // 同时转义模板指令和 include 占位符的定界符，保证保存的值按字面显示。
        return strtr(htmlspecialchars($value, ENT_QUOTES | ENT_SUBSTITUTE | ENT_HTML5, 'UTF-8'),
            ['{' => '&#123;', '}' => '&#125;', '[' => '&#91;', ']' => '&#93;']);
    }

    /** 只有编号仍指向原来的元素时，当前预览才可继续编辑。 */
    public static function preservesElementIds(array $elements, string $source, int $edited, array $spans, string $replacement): bool
    {
        $next = self::inspect($source);
        if (count($elements) !== count($next)) {
            return false;
        }
        $length = strlen($replacement);
        foreach ($elements as $index => $element) {
            $marker = $element['marker'];
            foreach ($spans as $span) {
                if ($span['start'] < $element['marker']) {
                    $marker += $length - $span['length'];
                }
            }
            if ($index === $edited && $element['kind'] === 'text') {
                $marker += $length - strlen(ltrim($replacement));
            }
            if ($next[$index]['kind'] !== $element['kind'] || $next[$index]['marker'] !== $marker) {
                return false;
            }
        }
        return true;
    }
}
