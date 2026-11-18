<?php
/**
 * @copyright Copyright (c) 2026 米柚工作室
 * @license https://opensource.org/licenses/Apache-2.0
 * @link https://www.miucms.com
 */

declare(strict_types=1);

namespace app\home\taglib\traits;

/** 前台模板语言包标签：文案由当前模板的 lang/*.json 提供。 */
trait LangTag
{
    /**
     * {miu:lang key='nav.home' default='首页'/}
     */
    public function tagLang(array $tag, string $content): string
    {
        $error = $this->checkAttrs($tag, ['key', 'default', 'raw'], 'miu:lang', $content);
        if ($error !== '') {
            return $error;
        }

        $key = trim((string) ($tag['key'] ?? ''));
        if (!preg_match('/^[A-Za-z0-9_.-]{1,128}$/', $key)) {
            return $this->tagError('miu:lang', 'key 只能由字母、数字、点、下划线和短横线组成', $content);
        }

        $default = array_key_exists('default', $tag)
            ? var_export((string) $tag['default'], true)
            : 'null';
        $value = '\\app\\home\\service\\TemplateI18n::translate(' . var_export($key, true) . ', ' . $default . ')';
        $raw = isset($tag['raw']) && $tag['raw'] !== '' && $tag['raw'] !== '0'
            && strtolower((string) $tag['raw']) !== 'false';

        return $raw
            ? '<?php echo ' . $value . '; ?>'
            : '<?php echo htmlspecialchars(' . $value . ', ENT_QUOTES | ENT_SUBSTITUTE, \'UTF-8\'); ?>';
    }

    /**
     * {miu:locale name='current'/}，输出标准大小写语言代码，例如 zh-CN。
     */
    public function tagLocale(array $tag, string $content): string
    {
        $error = $this->checkAttrs($tag, ['name'], 'miu:locale', $content);
        if ($error !== '') {
            return $error;
        }

        $name = trim((string) ($tag['name'] ?? 'current'));
        if ($name !== '' && $name !== 'current') {
            return $this->tagError('miu:locale', "name 只支持 current，现在写的是：{$name}", $content);
        }

        return '<?php echo htmlspecialchars(\\app\\home\\service\\TemplateI18n::htmlLocale(), ENT_QUOTES | ENT_SUBSTITUTE, \'UTF-8\'); ?>';
    }

    /**
     * {miu:langurl locale='en'/}，生成当前页面对应语言的地址。
     */
    public function tagLangurl(array $tag, string $content): string
    {
        $error = $this->checkAttrs($tag, ['locale'], 'miu:langurl', $content);
        if ($error !== '') {
            return $error;
        }

        $locale = trim((string) ($tag['locale'] ?? ''));
        if ($locale === '') {
            return $this->tagError('miu:langurl', '缺少 locale，例如 locale=\'en-us\'', $content);
        }

        $value = '\\app\\home\\service\\TemplateI18n::localizedUrl(' . var_export($locale, true) . ')';
        return '<?php echo htmlspecialchars(' . $value . ', ENT_QUOTES | ENT_SUBSTITUTE, \'UTF-8\'); ?>';
    }
}
