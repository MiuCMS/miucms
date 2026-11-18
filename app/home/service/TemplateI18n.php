<?php
/**
 * @copyright Copyright (c) 2026 米柚工作室
 * @license https://opensource.org/licenses/Apache-2.0
 * @link https://www.miucms.com
 */

declare(strict_types=1);

namespace app\home\service;

/**
 * 前台模板界面语言包。
 *
 * 每个模板在 templates/<模板名>/lang/ 下用 JSON 文件维护自己的文案，
 * 文件名是语言代码，例如 zh-cn.json、zh-hk.json、en.json。
 * 这里仅翻译模板固定文案，不参与文章、栏目等 CMS 内容的翻译。
 */
class TemplateI18n
{
    //原文及缺失译文的回退语言固定不变，与前台默认访问语言分开。
    public const DEFAULT_LOCALE = 'zh-cn';

    /** @var string|null 当前请求或静态渲染指定的语言 */
    private static $locale = null;

    /** @var string|null 当前页面地址；静态生成时由生成器显式设置 */
    private static $currentUrl = null;

    /** @var array<string, array> 当前请求已读取的语言包 */
    private static $dictionaryCache = [];

    /** @var array<string, string[]> 当前请求已扫描的模板语言列表 */
    private static $localeCache = [];

    /**
     * 读取当前模板可用语言。显式配置后按启用列表和有效 JSON 文件判断，
     * 首次配置前为兼容旧模板，仍按有效 JSON 文件自动发现。
     *
     * @param string|null $template 模板标识，留空读取当前模板
     * @return string[] 小写语言代码
     */
    public static function availableLocales(?string $template = null): array
    {
        $template = $template !== null && $template !== ''
            ? $template
            : (function_exists('get_current_template') ? get_current_template() : 'default');

        if (isset(self::$localeCache[$template])) {
            return self::$localeCache[$template];
        }

        $locales = [self::DEFAULT_LOCALE];
        if (!function_exists('get_templates_path')) {
            return self::$localeCache[$template] = $locales;
        }

        $directory = get_templates_path($template) . 'lang' . DIRECTORY_SEPARATOR;
        $realDirectory = realpath($directory);
        if ($realDirectory === false || !is_dir($realDirectory)) {
            return self::$localeCache[$template] = $locales;
        }

        $fileLocales = [];
        foreach (glob($realDirectory . DIRECTORY_SEPARATOR . '*.json') ?: [] as $file) {
            $filename = pathinfo($file, PATHINFO_FILENAME);
            $locale = self::normalizeLocale($filename);
            $realFile = realpath($file);
            if ($locale === '' || $filename !== $locale || $realFile === false || !self::isInsideDirectory($realFile, $realDirectory)) {
                continue;
            }

            //损坏的语言包不参与路由和静态生成，避免生成空白语言版本。
            if (self::readDictionaryFile($realFile) !== null) {
                $fileLocales[] = $locale;
            }
        }

        //某个模板第一次配置前沿用旧行为：有效 JSON 文件就是可用语言。
        //保存过配置后，以该模板的启用列表为准；取消勾选只隐藏语言入口，不删除文件。
        $configured = self::configuredLocales($template);
        if ($configured === null) {
            $locales = array_merge($locales, $fileLocales);
        } else {
            $locales = [];
            foreach ($configured as $locale) {
                if ($locale === self::DEFAULT_LOCALE || in_array($locale, $fileLocales, true)) {
                    $locales[] = $locale;
                }
            }
        }

        //语言配置或文件全部失效时保留中文兜底，避免整站没有可用语言。
        return self::$localeCache[$template] = $locales ? array_values(array_unique($locales)) : [self::DEFAULT_LOCALE];
    }

    /** 常用语言代码及后台/模板切换菜单显示名。 */
    public static function supportedLocales(): array
    {
        return [
            'zh-cn' => '简体中文',
            'zh-hk' => '繁體中文',
            'en' => 'English',
            'ja' => '日本語',
            'ko' => '한국어',
            'fr' => 'Français',
            'de' => 'Deutsch',
            'es' => 'Español',
            'pt' => 'Português',
            'ru' => 'Русский',
            'it' => 'Italiano',
            'th' => 'ไทย',
            'vi' => 'Tiếng Việt',
            'ar' => 'العربية',
            'hi' => 'हिन्दी',
            'id' => 'Bahasa Indonesia',
            'ms' => 'Bahasa Melayu',
            'nl' => 'Nederlands',
            'tr' => 'Türkçe',
            'pl' => 'Polski',
            'uk' => 'Українська',
            'bn' => 'বাংলা',
            'fa' => 'فارسی',
            'he' => 'עברית',
            'ur' => 'اردو',
            'fil' => 'Filipino',
            'sv' => 'Svenska',
            'da' => 'Dansk',
            'nb' => 'Norsk Bokmål',
            'fi' => 'Suomi',
            'el' => 'Ελληνικά',
            'cs' => 'Čeština',
            'ro' => 'Română',
            'hu' => 'Magyar',
            'sw' => 'Kiswahili',
        ];
    }

    /** 清除当前请求内的语言列表和字典缓存，供后台保存配置后调用。 */
    public static function clearLocaleCache(): void
    {
        self::$localeCache = [];
        self::$dictionaryCache = [];
    }

    /** 当前模板默认访问语言；异常配置回退到已启用语言，旧配置沿用简体中文。 */
    public static function defaultLocale(?string $template = null): string
    {
        $template = $template !== null && $template !== ''
            ? $template
            : (function_exists('get_current_template') ? get_current_template() : 'default');
        $config = function_exists('get_config_group') ? get_config_group('multilanguage') : [];
        $locale = $config['defaults'][$template] ?? self::DEFAULT_LOCALE;
        $locale = is_string($locale) ? self::normalizeLocale($locale) : '';
        $available = self::availableLocales($template);
        return in_array($locale, $available, true) ? $locale : $available[0];
    }

    /**
     * 获取当前模板已启用的语言列表，供 langlist 循环标签使用。
     *
     * 每项提供 code、name、url、full_url 和 current 字段；current 为 1 表示当前语言，0 表示其他语言。
     * 语言范围沿用 availableLocales()，并复用其中按模板缓存的语言清单。
     *
     * @return array<int, array{code:string,name:string,url:string,full_url:string,current:int}>
     */
    public static function languageList(): array
    {
        $names = self::supportedLocales();
        $currentLocale = self::locale();
        $languages = [];

        foreach (self::availableLocales() as $locale) {
            //忽略系统没有显示名称的未知语言，避免模板出现空白语言项。
            if (!isset($names[$locale])) {
                continue;
            }

            $url = self::localizedUrl($locale);
            $languages[] = [
                'code'     => $locale,
                'name'     => $names[$locale],
                'url'      => $url,
                'full_url' => function_exists('miu_full_url') ? miu_full_url($url) : $url,
                'current'  => $locale === $currentLocale ? 1 : 0,
            ];
        }

        return $languages;
    }

    /** 输出当前模板已启用语言的切换链接。 */
    public static function renderLanguageLinks(string $class = 'dropdown-item'): string
    {
        $names = self::supportedLocales();
        $html = '';
        foreach (self::availableLocales() as $locale) {
            if (!isset($names[$locale])) {
                continue;
            }
            $href = self::localizedUrl($locale);
            $html .= '<a class="' . htmlspecialchars($class, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8')
                . '" href="' . htmlspecialchars($href, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') . '">'
                . htmlspecialchars($names[$locale], ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') . '</a>';
        }
        return $html;
    }

    /** 显式配置返回语言数组；尚未为该模板配置时返回 null，沿用文件扫描兼容模式。 */
    private static function configuredLocales(string $template): ?array
    {
        if (!function_exists('get_config_group')) {
            return null;
        }

        try {
            $config = get_config_group('multilanguage');
        } catch (\Throwable $e) {
            return null;
        }

        $templateLocales = $config['templates'][$template] ?? null;
        if (!is_array($templateLocales)) {
            return null;
        }

        $supported = self::supportedLocales();
        $locales = [];
        foreach ($templateLocales as $locale) {
            $locale = self::normalizeLocale((string) $locale);
            if ($locale !== '' && isset($supported[$locale])) {
                $locales[] = $locale;
            }
        }
        return array_values(array_unique($locales));
    }

    /** 停用的语言前缀不得当作普通栏目路径继续解析。 */
    public static function isDisabledLocalePath(string $path): bool
    {
        $segments = self::pathSegments($path);
        $position = isset($segments[0]) && strtolower($segments[0]) === 'index.php' ? 1 : 0;
        $positions = [$position, self::staticLocalePosition($segments)];
        $supported = self::supportedLocales();
        $available = self::availableLocales();
        foreach ($positions as $position) {
            if ($position === null) {
                continue;
            }
            $locale = self::normalizeLocale((string) ($segments[$position] ?? ''));
            if (isset($supported[$locale]) && !in_array($locale, $available, true)) {
                return true;
            }
        }
        return false;
    }

    /**
     * 根据请求路径初始化语言上下文。没有语言前缀时使用当前模板默认语言。
     */
    public static function beginRequest(string $path, string $url = ''): void
    {
        self::$locale = self::localeFromPath($path) ?? self::defaultLocale();
        self::$currentUrl = $url !== '' ? $url : '/' . ltrim($path, '/');
    }

    /**
     * 从 URL 路径首段识别模板语言。
     */
    public static function localeFromPath(string $path): ?string
    {
        $segments = self::pathSegments($path);
        $position = isset($segments[0]) && strtolower($segments[0]) === 'index.php' ? 1 : 0;
        $candidate = self::normalizeLocale((string) ($segments[$position] ?? ''));

        if ($candidate !== '' && in_array($candidate, self::availableLocales(), true)) {
            return $candidate;
        }

        //静态模式下非默认语言位于配置的保存目录内，例如 /html/en/、/html/zh-tw/。
        $localePosition = self::staticLocalePosition($segments);
        $candidate = $localePosition !== null
            ? self::normalizeLocale((string) ($segments[$localePosition] ?? ''))
            : '';

        return $candidate !== '' && in_array($candidate, self::availableLocales(), true)
                ? $candidate
                : null;
    }

    /**
     * 从 pathinfo 中移除语言前缀，交给原有路由和伪静态解析。
     */
    public static function stripLocalePrefix(string $path): string
    {
        $segments = self::pathSegments($path);
        $position = isset($segments[0]) && strtolower($segments[0]) === 'index.php' ? 1 : 0;
        $candidate = self::normalizeLocale((string) ($segments[$position] ?? ''));

        if ($candidate !== '' && in_array($candidate, self::availableLocales(), true)) {
            array_splice($segments, $position, 1);
            return implode('/', $segments);
        }

        $localePosition = self::staticLocalePosition($segments);
        $candidate = $localePosition !== null
            ? self::normalizeLocale((string) ($segments[$localePosition] ?? ''))
            : '';
        if ($candidate !== '' && in_array($candidate, self::availableLocales(), true)) {
            array_splice($segments, $localePosition, 1);
        }

        return implode('/', $segments);
    }

    /**
     * 读取或设置当前语言。设置 null 可清除静态覆盖值。
     *
     * @param string|null $locale
     * @return string|null 设置前的覆盖值
     */
    public static function setLocale(?string $locale): ?string
    {
        $previous = self::$locale;
        if ($locale === null) {
            self::$locale = null;
            return $previous;
        }

        $locale = self::normalizeLocale($locale);
        self::$locale = $locale !== '' && in_array($locale, self::availableLocales(), true)
            ? $locale
            : self::defaultLocale();

        return $previous;
    }

    /** 当前模板文案使用的语言代码（小写） */
    public static function locale(): string
    {
        if (self::$locale !== null) {
            return self::$locale;
        }

        try {
            if (function_exists('request')) {
                $path = (string) request()->pathinfo();
                return self::localeFromPath($path) ?? self::defaultLocale();
            }
        } catch (\Throwable $e) {
            //命令行或应用未完整启动时使用默认语言。
        }

        return self::defaultLocale();
    }

    /** 输出给 HTML lang 属性的标准大小写语言代码。 */
    public static function htmlLocale(): string
    {
        $parts = explode('-', self::locale());
        if (count($parts) > 1) {
            $parts[1] = strtoupper($parts[1]);
        }
        return implode('-', $parts);
    }

    /** 当前语言 URL 前缀；默认语言保持原有 URL 不变。 */
    public static function urlSegment(): string
    {
        $locale = self::locale();
        return $locale === self::defaultLocale() ? '' : '/' . $locale;
    }

    /**
     * 设置静态渲染时的当前页面地址，返回先前值供 finally 恢复。
     */
    public static function setCurrentUrl(?string $url): ?string
    {
        $previous = self::$currentUrl;
        self::$currentUrl = $url;
        return $previous;
    }

    /** 读取当前请求或静态生成正在渲染的页面地址。 */
    public static function currentUrl(): string
    {
        return self::$currentUrl ?? '';
    }

    /**
     * 翻译一个模板文案键。
     * 会员中心等系统共享文案可回退到默认模板语言包，其它文案仍只读取当前模板语言包。
     */
    public static function translate(string $key, ?string $default = null): string
    {
        $key = trim($key);
        if ($key === '') {
            return $default ?? '';
        }

        $locale = self::locale();
        $template = function_exists('get_current_template') ? get_current_template() : 'default';
        $sharedUiKey = preg_match('/^(?:account|member|js|user_js)\./', $key) === 1;
        $value = self::dictionaryValue(self::dictionary($locale), $key);
        if ($value !== null) {
            return $value;
        }

        if ($sharedUiKey && $template !== 'default') {
            $value = self::dictionaryValue(self::dictionary($locale, 'default'), $key);
            if ($value !== null) {
                return $value;
            }
        }

        if ($locale !== self::DEFAULT_LOCALE) {
            $value = self::dictionaryValue(self::dictionary(self::DEFAULT_LOCALE), $key);
            if ($value !== null) {
                return $value;
            }
        }

        if ($sharedUiKey && $template !== 'default' && $locale !== self::DEFAULT_LOCALE) {
            $value = self::dictionaryValue(self::dictionary(self::DEFAULT_LOCALE, 'default'), $key);
            if ($value !== null) {
                return $value;
            }
        }

        return $default !== null ? $default : $key;
    }

    /**
     * 为同一页面生成指定语言的地址，保留查询参数和锚点。
     */
    public static function localizedUrl(string $locale, ?string $url = null): string
    {
        $locale = self::normalizeLocale($locale);
        if ($locale === '' || !in_array($locale, self::availableLocales(), true)) {
            $locale = self::defaultLocale();
        }

        if ($url === null || $url === '') {
            $url = self::$currentUrl;
        }
        if (($url === null || $url === '') && function_exists('request')) {
            try {
                $url = (string) request()->url();
            } catch (\Throwable $e) {
                $url = '';
            }
        }

        $url = (string) $url;
        $parts = parse_url($url);
        if (!is_array($parts)) {
            $parts = ['path' => $url];
        }
        if (!empty($parts['scheme']) || !empty($parts['host'])) {
            return $url;
        }

        $path = (string) ($parts['path'] ?? '/');
        $trailingSlash = $path === '/' || substr($path, -1) === '/';
        $segments = self::pathSegments($path);
        $position = isset($segments[0]) && strtolower($segments[0]) === 'index.php' ? 1 : 0;

        //静态首页有单独的落盘路径：默认语言对应站点根目录，其它语言对应保存目录/语言/index.html。
        //切换首页语言时重建目标首页地址，避免把默认首页错误拼成 /html/。
        $staticConfig = function_exists('url_config') ? url_config() : [];
        if (($staticConfig['mode'] ?? null) === 2) {
            $relativeSegments = array_slice($segments, $position);
            $saveDirSegments = self::pathSegments((string) ($staticConfig['save_dir'] ?? ''));
            $homeSegments = $relativeSegments;
            $leadingLocale = self::normalizeLocale((string) ($homeSegments[0] ?? ''));

            if ($leadingLocale !== '' && in_array($leadingLocale, self::availableLocales(), true)) {
                array_shift($homeSegments);
            }

            $isStaticHome = $homeSegments === []
                || ($saveDirSegments !== [] && $homeSegments === $saveDirSegments);

            if (!$isStaticHome && $saveDirSegments !== []) {
                $hasSaveDirPrefix = array_slice($relativeSegments, 0, count($saveDirSegments)) === $saveDirSegments;
                $localeTail = array_slice($relativeSegments, count($saveDirSegments));
                $isStaticHome = $hasSaveDirPrefix && count($localeTail) === 1
                    && self::hasLocale((string) $localeTail[0]);
            }

            if ($isStaticHome) {
                $targetSegments = $locale === self::defaultLocale()
                    ? []
                    : array_merge($saveDirSegments, [$locale]);
                $newPath = $targetSegments === [] ? '/' : '/' . implode('/', $targetSegments) . '/';
                $result = $newPath;
                if (isset($parts['query']) && $parts['query'] !== '') {
                    $result .= '?' . $parts['query'];
                }
                if (isset($parts['fragment']) && $parts['fragment'] !== '') {
                    $result .= '#' . $parts['fragment'];
                }
                return $result;
            }
        }

        $existing = self::normalizeLocale((string) ($segments[$position] ?? ''));
        if ($existing !== '' && in_array($existing, self::availableLocales(), true)) {
            array_splice($segments, $position, 1);
        } else {
            //同时识别旧版 /en/html/、/zh-tw/html/ 和新版 /html/en/、/html/zh-tw/ 地址。
            $existingPosition = self::staticLocalePosition($segments);
            $existing = $existingPosition !== null
                ? self::normalizeLocale((string) ($segments[$existingPosition] ?? ''))
                : '';
            if ($existing !== '' && in_array($existing, self::availableLocales(), true)) {
                array_splice($segments, $existingPosition, 1);
            }
        }

        if ($locale !== self::defaultLocale()) {
            $localePosition = self::staticLocalePosition($segments);
            array_splice($segments, $localePosition ?? $position, 0, [$locale]);
        }

        $newPath = '/' . implode('/', $segments);
        if ($segments === []) {
            $newPath = '/';
        } elseif ($trailingSlash) {
            $newPath .= '/';
        }

        $result = $newPath;
        if (isset($parts['query']) && $parts['query'] !== '') {
            $result .= '?' . $parts['query'];
        }
        if (isset($parts['fragment']) && $parts['fragment'] !== '') {
            $result .= '#' . $parts['fragment'];
        }

        return $result;
    }

    /** 语言包是否存在；用于模板作者按可用语言控制切换入口。 */
    public static function hasLocale(string $locale): bool
    {
        return in_array(self::normalizeLocale($locale), self::availableLocales(), true);
    }

    /** 语言代码标准化为安全的小写文件名。 */
    public static function normalizeLocale(string $locale): string
    {
        $locale = strtolower(trim(str_replace('_', '-', $locale)));
        return preg_match('/^[a-z]{2,3}(?:-[a-z0-9]{2,8})*$/', $locale) ? $locale : '';
    }

    /**
     * 读语言包；仅解析 JSON，不执行模板目录里的 PHP 文件。
     */
    private static function dictionary(string $locale, ?string $template = null): array
    {
        $template = $template ?? (function_exists('get_current_template') ? get_current_template() : 'default');
        $cacheKey = $template . '|' . $locale;
        if (array_key_exists($cacheKey, self::$dictionaryCache)) {
            return self::$dictionaryCache[$cacheKey];
        }

        if (!function_exists('get_templates_path')) {
            return self::$dictionaryCache[$cacheKey] = [];
        }

        $directory = get_templates_path($template) . 'lang' . DIRECTORY_SEPARATOR;
        $base = realpath($directory);
        $file = realpath($directory . $locale . '.json');
        if ($base === false || $file === false || !self::isInsideDirectory($file, $base)) {
            return self::$dictionaryCache[$cacheKey] = [];
        }

        return self::$dictionaryCache[$cacheKey] = self::readDictionaryFile($file) ?? [];
    }

    /** @return array|null JSON 对象，解析失败返回 null */
    private static function readDictionaryFile(string $file): ?array
    {
        $raw = @file_get_contents($file);
        if (!is_string($raw)) {
            return null;
        }
        $raw = preg_replace('/^\xEF\xBB\xBF/', '', $raw);
        $data = json_decode($raw, true);
        return is_array($data) ? $data : null;
    }

    /** 从点分隔键名中读取字符串或数字翻译。 */
    private static function dictionaryValue(array $dictionary, string $key): ?string
    {
        $value = $dictionary;
        foreach (explode('.', $key) as $part) {
            if (!is_array($value) || !array_key_exists($part, $value)) {
                return null;
            }
            $value = $value[$part];
        }

        return is_string($value) || is_numeric($value) ? (string) $value : null;
    }

    /** @return string[] */
    private static function pathSegments(string $path): array
    {
        $path = trim(str_replace('\\', '/', $path), '/');
        return $path === '' ? [] : explode('/', $path);
    }

    /**
     * 取静态保存目录之后的语言段位置；动态或未配置保存目录时返回 null。
     * 例如保存目录为 html 时，/html/en/news/、/html/zh-tw/news/ 的位置是 1。
     */
    private static function staticLocalePosition(array $segments): ?int
    {
        if (!function_exists('url_config')) {
            return null;
        }

        $config = url_config();
        if (($config['mode'] ?? null) !== 2 || empty($config['save_dir'])) {
            return null;
        }

        $prefix = self::pathSegments((string) $config['save_dir']);
        if (!$prefix) {
            return null;
        }

        $base = isset($segments[0]) && strtolower($segments[0]) === 'index.php' ? 1 : 0;
        foreach ($prefix as $offset => $part) {
            if (strtolower((string) ($segments[$base + $offset] ?? '')) !== strtolower($part)) {
                return null;
            }
        }

        return $base + count($prefix);
    }

    private static function isInsideDirectory(string $file, string $directory): bool
    {
        $directory = rtrim($directory, DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR;
        return strncmp($file, $directory, strlen($directory)) === 0;
    }
}
