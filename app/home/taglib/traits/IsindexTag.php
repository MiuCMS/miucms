<?php
/**
 * @copyright Copyright (c) 2026 米柚工作室
 * @license https://opensource.org/licenses/Apache-2.0
 * @link https://www.miucms.com
 */

declare(strict_types=1);

namespace app\home\taglib\traits;

/**
 * 首页判断标签。
 *
 * 用法：{miu:isindex /} 默认在首页输出 active，其他页面不输出；
 *       {miu:isindex text='on' /} 可自定义首页输出的文本。
 *
 * 当前 URL 由前台中间件记录，静态页生成时由生成器设置；与当前语言首页地址比较，
 * 因此普通请求、静态生成和多语言首页都能判断。text 仅作为文本输出，不执行为代码。
 */
trait IsindexTag
{
    /**
     * 编译首页判断标签。
     *
     * text 未填写时使用 active；最多允许 200 个字符，先移除控制字符，输出时再做
     * HTML 转义，避免参数中的引号、标签或脚本被浏览器当成 HTML 执行。
     */
    public function tagIsindex(array $tag, string $content): string
    {
        $error = $this->checkAttrs($tag, ['text'], 'miu:isindex', $content);
        if ($error !== '') {
            return $error;
        }

        $text = array_key_exists('text', $tag) ? (string) $tag['text'] : 'active';
        $text = preg_replace('/[\x00-\x08\x0B\x0C\x0E-\x1F\x7F]/u', '', $text) ?? $text;
        $length = function_exists('mb_strlen') ? mb_strlen($text, 'UTF-8') : strlen($text);
        if ($length > 200) {
            return $this->tagError('miu:isindex', 'text 不能超过 200 个字符', $content);
        }

        $compiled = <<<'PHP'
<?php
$__miu_isindex_current_url = \app\home\service\TemplateI18n::currentUrl();
$__miu_isindex_current_path = parse_url($__miu_isindex_current_url, PHP_URL_PATH);
$__miu_isindex_home_path = parse_url(home_url(), PHP_URL_PATH);
if (is_string($__miu_isindex_current_path) && $__miu_isindex_current_path !== ''
    && is_string($__miu_isindex_home_path) && $__miu_isindex_home_path !== '') {
    $__miu_isindex_normalize = static function ($__miu_isindex_path) {
        $__miu_isindex_path = '/' . trim(str_replace(chr(92), '/', (string) $__miu_isindex_path), '/');
        if (strtolower(substr($__miu_isindex_path, -10)) === '/index.php') {
            $__miu_isindex_path = substr($__miu_isindex_path, 0, -10);
        }
        $__miu_isindex_path = '/' . trim($__miu_isindex_path, '/');
        return $__miu_isindex_path !== '' ? $__miu_isindex_path : '/';
    };
    if ($__miu_isindex_normalize($__miu_isindex_current_path) === $__miu_isindex_normalize($__miu_isindex_home_path)) {
        echo htmlspecialchars(__MIU_ISINDEX_TEXT__, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
    }
}
?>
PHP;

        return str_replace('__MIU_ISINDEX_TEXT__', var_export($text, true), $compiled);
    }
}
