<?php
declare(strict_types=1);

namespace app\services;

use think\view\driver\Think;

/** 从所选页面的渲染结果中单独预览公共片段。 */
final class TemplateVisualView extends Think
{
    public static string $fragment = '';
    public static string $marker = '';
    private bool $rendering = false;

    public static function fragmentBoundaries(string $marker): array
    {
        return ['<!--miu-visual-fragment:' . $marker . '-->', '<!--miu-visual-fragment-end:' . $marker . '-->'];
    }

    public function fetch(string $template, array $data = []): void
    {
        if (self::$fragment === '' || $this->rendering) {
            parent::fetch($template, $data);
            return;
        }

        $this->rendering = true;
        try {
            ob_start();
            try {
                parent::fetch($template, $data);
                $html = (string) ob_get_contents();
            } finally {
                ob_end_clean();
            }
            preg_match('~<head(?=[\s>])[^>]*>.*?</head\s*>~is', $html, $head, PREG_OFFSET_CAPTURE);
            preg_match('~<html(?=[\s>])[^>]*>~i', $html, $htmlTag);
            $headEnd = isset($head[0]) ? $head[0][1] + strlen($head[0][0]) : 0;
            preg_match('~<body(?=[\s>])[^>]*>~i', $html, $body, PREG_OFFSET_CAPTURE, $headEnd);
            echo '<!doctype html>' . ($htmlTag[0] ?? '<html>') . ($head[0][0] ?? '<head><meta charset="utf-8"></head>')
                . ($body[0][0] ?? '<body>');

            [$opening, $closing] = self::fragmentBoundaries(self::$marker);
            $start = strpos($html, $opening);
            $end = $start === false ? false : strpos($html, $closing, $start + strlen($opening));
            if ($end === false) {
                parent::fetch(self::$fragment, $data);
            } else {
                // 旧模板的页头、页脚可能同时负责打开或关闭页面外壳。
                $bodyStart = isset($body[0]) ? $body[0][1] + strlen($body[0][0]) : $headEnd;
                $bodyEnd = strripos($html, '</body>');
                $start = max($start + strlen($opening), $bodyStart);
                $end = $bodyEnd === false ? $end : min($end, $bodyEnd);
                if ($end > $start) {
                    echo substr($html, $start, $end - $start);
                }
            }
            echo '</body></html>';
        } finally {
            $this->rendering = false;
        }
    }
}
