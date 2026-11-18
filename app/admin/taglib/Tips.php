<?php
/**
 * @copyright Copyright (c) 2026 米柚工作室
 * @license https://opensource.org/licenses/Apache-2.0
 * @link https://www.miucms.com
 */

declare(strict_types=1);

namespace app\admin\taglib;

use think\template\TagLib;

/** 后台提示图标标签。 */
class Tips extends TagLib
{
    protected $tags = [
        'tips' => ['attr' => 'tips,color,style', 'expression' => true, 'close' => 0],
    ];

    public function tagTips(array $tag, string $content): string
    {
        // 保留模板内同名的 JavaScript 对象属性写法，例如 {tips: 1}。
        if (!array_key_exists('tips', $tag)) {
            return '{tips' . ($tag['expression'] ?? '') . '}';
        }

        $colors = ['red', 'blue', 'green', 'orange', 'black', 'gray','purple','white'];
        $color = strtolower((string) ($tag['color'] ?? 'blue'));
        if (!in_array($color, $colors, true)) {
            $color = 'blue';
        }

        $tips = (string) ($tag['tips'] ?? '');
        if (str_starts_with($tips, '$')) {
            $tipsExpression = $this->autoBuildVar($tips);
        } else {
            $tipsExpression = 'html_entity_decode(' . var_export($tips, true) . ', ENT_QUOTES | ENT_HTML5, \'UTF-8\')';
        }

        // 仅保留旧提示图标正在使用的两种行内样式。
        $styles = ['margin-left:5px;flex-shrink:0;', 'vertical-align: middle;'];
        $style = (string) ($tag['style'] ?? '');
        $styleAttribute = in_array($style, $styles, true)
            ? ' style="' . htmlspecialchars($style, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') . '"'
            : '';

        $prefix = '<i class="layui-icon layui-icon-question layui-font-20 layui-font-' . $color . ' right-tips" lay-on="right-tips" data-tips="';
        $suffix = '"' . $styleAttribute . '></i>';

        return '<?php echo ' . var_export($prefix, true)
            . ' . htmlspecialchars((string) (' . $tipsExpression . '), ENT_QUOTES | ENT_SUBSTITUTE, \'UTF-8\')'
            . ' . ' . var_export($suffix, true) . '; ?>';
    }
}
