<?php
/**
 * @copyright Copyright (c) 2026 米柚工作室
 * @license https://opensource.org/licenses/Apache-2.0
 * @link https://www.miucms.com
 */

declare(strict_types=1);

namespace app\admin\controller;

use app\admin\BaseController;
use app\home\service\ContentI18n;
use app\home\service\TemplateI18n;
use think\facade\Db;
use think\facade\View;

/** 前台模板多语言配置。 */
class Multilanguage extends BaseController
{
    public function index()
    {
        $template = get_current_template();
        $languages = TemplateI18n::supportedLocales();
        unset($languages['en-gb'], $languages['en-au'], $languages['en-ca'], $languages['pt-pt'], $languages['es-mx']);

        if ($this->request->isPost()) {
            return $this->saveLocales($template, $languages);
        }

        $config = get_config_group('multilanguage');
        $defaultLocale = TemplateI18n::defaultLocale($template);
        $savedLocales = $config['templates'][$template] ?? null;
        if (!is_array($savedLocales)) {
            //首次配置时沿用旧行为，预选当前模板已有的有效语言文件。
            $savedLocales = TemplateI18n::availableLocales($template);
        }

        $selected = array_fill_keys(array_map('strval', $savedLocales), true);
        foreach (['en-gb' => 'en-us', 'en-au' => 'en-us', 'en-ca' => 'en-us', 'pt-pt' => 'pt-br'] as $legacyLocale => $unifiedLocale) {
            if (isset($selected[$legacyLocale])) {
                $selected[$unifiedLocale] = true;
            }
        }
        $languageRows = [];
        foreach ($languages as $code => $label) {
            $languageRows[] = [
                'code' => $code,
                'label' => $label,
                'enabled' => isset($selected[$code]) || $code === $defaultLocale ? 1 : 0,
                'is_default' => $code === $defaultLocale ? 1 : 0,
            ];
        }

        View::assign('templateName', $template);
        View::assign('languages', $languageRows);
        View::assign('defaultLocale', $defaultLocale);
        return view();
    }

    /** 保存当前模板启用语言，并仅补建缺失的示例 JSON。 */
    private function saveLocales(string $template, array $languages)
    {
        $posted = $this->request->post('locales', []);
        if (!is_array($posted)) {
            $posted = [$posted];
        }

        $requested = [];
        foreach ($posted as $locale) {
            if (!is_scalar($locale)) {
                continue;
            }
            $locale = TemplateI18n::normalizeLocale((string) $locale);
            if ($locale !== '' && isset($languages[$locale])) {
                $requested[$locale] = true;
            }
        }
        $defaultLocale = $this->request->post('default_locale', TemplateI18n::defaultLocale($template));
        $defaultLocale = is_string($defaultLocale) ? TemplateI18n::normalizeLocale($defaultLocale) : '';
        if ($defaultLocale === '' || !isset($languages[$defaultLocale]) || !isset($requested[$defaultLocale])) {
            return to_assign(1, '请选择已勾选的语言作为默认语言');
        }
        $locales = [];
        foreach ($languages as $locale => $_label) {
            if (isset($requested[$locale])) {
                $locales[] = $locale;
            }
        }

        $templateRoot = realpath(get_templates_path($template));
        if ($templateRoot === false || !is_dir($templateRoot)) {
            return to_assign(1, '当前模板目录不存在，无法保存语言配置');
        }

        $languageDirectory = $templateRoot . DIRECTORY_SEPARATOR . 'lang';
        if (!is_dir($languageDirectory) && !@mkdir($languageDirectory, 0755, true) && !is_dir($languageDirectory)) {
            return to_assign(1, '无法创建当前模板的 lang 目录，请检查目录权限');
        }
        $realLanguageDirectory = realpath($languageDirectory);
        if ($realLanguageDirectory === false || !$this->isInside($realLanguageDirectory, $templateRoot)) {
            return to_assign(1, '当前模板的 lang 目录路径无效');
        }

        $example = $this->loadExampleDictionary($realLanguageDirectory);
        $createdFiles = [];
        foreach ($locales as $locale) {
            $file = $realLanguageDirectory . DIRECTORY_SEPARATOR . $locale . '.json';
            if (file_exists($file) || is_link($file)) {
                if (!$this->isValidLanguageFile($file, $realLanguageDirectory)) {
                    $this->removeCreatedFiles($createdFiles);
                    return to_assign(1, $locale . '.json 已存在但不是有效的 JSON 对象；文件未被覆盖，请修复后再启用');
                }
                continue;
            }

            $handle = @fopen($file, 'x');
            if ($handle === false) {
                //若另一个请求刚好创建了文件，只验证并保留它，不覆盖。
                if ((file_exists($file) || is_link($file)) && $this->isValidLanguageFile($file, $realLanguageDirectory)) {
                    continue;
                }
                $this->removeCreatedFiles($createdFiles);
                return to_assign(1, '无法创建 ' . $locale . '.json，请检查模板 lang 目录权限');
            }

            $createdFiles[] = $file;
            $json = json_encode($example, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
            $json = is_string($json) ? $json . PHP_EOL : '';
            $length = strlen($json);
            $written = 0;
            while ($written < $length) {
                $count = fwrite($handle, substr($json, $written));
                if ($count === false || $count === 0) {
                    break;
                }
                $written += $count;
            }
            $flushed = fflush($handle);
            fclose($handle);

            if ($json === '' || $written !== $length || !$flushed) {
                $this->removeCreatedFiles($createdFiles);
                return to_assign(1, $locale . '.json 写入失败，请检查磁盘空间和目录权限');
            }
        }

        try {
            //即使只启用一个外语，也需要读取相对于中文原文的翻译。
            if (array_diff($locales, [TemplateI18n::DEFAULT_LOCALE])) {
                ContentI18n::ensureSchema();
            }
            $config = get_config_group('multilanguage');
            $templates = is_array($config['templates'] ?? null) ? $config['templates'] : [];
            $templates[$template] = $locales;
            $defaults = is_array($config['defaults'] ?? null) ? $config['defaults'] : [];
            $defaults[$template] = $defaultLocale;
            $saved = save_config_group('multilanguage', ['templates' => $templates, 'defaults' => $defaults]);
            if (!$saved) {
                $this->removeCreatedFiles($createdFiles);
                throw new \RuntimeException('保存失败，请检查 config 表是否可写');
            }

            //配置分组首次创建时设置便于后台识别的名称。
            try {
                Db::name('config')->where('name', 'multilanguage')->update(['title' => '前台多语言']);
            } catch (\Throwable $e) {
                //分组名称不影响语言配置生效，不因标题更新失败回滚已保存配置和语言文件。
            }
        } catch (\Throwable $e) {
            $this->removeCreatedFiles($createdFiles);
            return to_assign(1, '保存失败：' . $e->getMessage());
        }

        TemplateI18n::clearLocaleCache();
        //语言前缀变化后清理可能包含旧链接的缓存。
        foreach (['homeNav', 'Document', 'homeTag'] as $tag) {
            clear_cache($tag);
        }
        safe_add_log('edit', 0, ['template' => $template, 'locales' => $locales, 'default_locale' => $defaultLocale], '前台多语言配置');
        return to_assign(0, '保存成功');
    }

    /** 以当前模板简体中文语言包作为示例；缺失或损坏时生成最小示例结构。 */
    private function loadExampleDictionary(string $languageDirectory): array
    {
        $example = null;
        $source = $languageDirectory . DIRECTORY_SEPARATOR . TemplateI18n::DEFAULT_LOCALE . '.json';
        if (is_file($source) && !is_link($source)) {
            $raw = @file_get_contents($source);
            if (is_string($raw)) {
                $raw = preg_replace('/^\xEF\xBB\xBF/', '', $raw);
                $decoded = json_decode($raw, true);
                if (is_array($decoded)) {
                    $example = $decoded;
                }
            }
        }

        if (!is_array($example)) {
            $example = [
                'nav' => [
                    'home' => '首页',
                    'language' => '语言',
                    'no_categories' => '暂无栏目',
                ],
            ];
        }

        //该说明键不参与页面文案查找，方便模板开发者识别新建文件的用途。
        return ['_example' => '这是语言包示例。请保留键名，并将 value 翻译为该语言。'] + $example;
    }

    /** 检查现有文件，只读取、不修改。 */
    private function isValidLanguageFile(string $file, string $languageDirectory): bool
    {
        if (is_link($file) || !is_file($file)) {
            return false;
        }
        $realFile = realpath($file);
        if ($realFile === false || !$this->isInside($realFile, $languageDirectory)) {
            return false;
        }
        $raw = @file_get_contents($realFile);
        if (!is_string($raw)) {
            return false;
        }
        $raw = preg_replace('/^\xEF\xBB\xBF/', '', $raw);
        return is_array(json_decode($raw, true));
    }

    /** 只删除本次保存刚创建的文件。 */
    private function removeCreatedFiles(array $files): void
    {
        foreach ($files as $file) {
            if (is_file($file) && !is_link($file)) {
                @unlink($file);
            }
        }
    }

    private function isInside(string $path, string $directory): bool
    {
        $directory = rtrim($directory, DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR;
        return strncmp($path, $directory, strlen($directory)) === 0;
    }
}
