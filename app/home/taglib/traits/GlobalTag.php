<?php
/**
 * @copyright Copyright (c) 2026 米柚工作室
 * @license https://opensource.org/licenses/Apache-2.0
 * @link https://www.miucms.com
 */

declare (strict_types = 1);

namespace app\home\taglib\traits;

/**
 * {miu:global} 读取网站配置
 *
 * 用法（读取后台「网站配置」里的配置项）：
 *   {miu:global name='site_name'/}                    输出 site_name，默认做 HTML 转义
 *   {miu:global name='site_icp' default='未备案'/}    配置为空（null 或空串）时输出 default
 *   {miu:global name='site_code' raw='1'/}            raw=1 时原样输出站点自定义 HTML 或 JavaScript
 *   {miu:global name='site_name' json='1'/}           输出 JSON 字符串内部转义内容（用于外层已有引号的 JSON 字段）
 *
 * 只能读取下面 $allowKeys 白名单里的配置项。不在白名单里的，页面不会报错，
 * 而是直接输出一句「无效的标签名[xxx]」提示，避免把邮箱密码、微信密钥
 * 这类敏感项误输出到前台页面，同时模板作者一眼能看到是哪个键没放行。
 * 要放行新的配置项：把键名加到 $allowKeys 数组里就行。
 *
 * name 写错（带特殊字符、写了变量、整个 name 都没写）同样不报错，
 * 也是输出「无效的标签名[xxx]」，不会让页面 500。
 */
trait GlobalTag
{
    /**
     * 允许前台模板读取的配置项白名单
     *
     * 键名就是后台「网站配置」里字段的 name（跟 cms_config 里 content 的键一致）。
     * 新增配置项后想让模板能用，按下面的格式加一行就行，注意加英文逗号。
     */
    protected $allowKeys = [
        // ---- 基本配置（后台：网站配置 → 基本配置）----
        'version',            // 网站配置中的版本号，可供模板展示
        'site_name',          // 网站名称
        'site_domain',        // 网站域名
        'site_logo',          // 网站LOGO
        'site_ico',           // 网站图标
        'site_icp',           // ICP备案号
        'site_beian',         // 公安备案号
        'site_code',          // 站点自定义代码
        'site_copyright',     // 版权信息
        'site_title',         // SEO标题
        'site_keywords',      // SEO关键字
        'site_desc',          // SEO描述

        // ---- 要放行别的配置项，按上面格式往下加 ----
        // 'xxx',
    ];

    /**
     * 读取网站配置
     * {miu:global name='site_name'/}
     */
    public function tagGlobal(array $tag, string $content): string
    {
        $name = trim((string) ($tag['name'] ?? ''));

        //name 只能是字母数字下划线。写错了（带特殊字符、写了变量、没写 name）
        //不抛异常，统一输出一句提示，免得整个页面 500
        if (!preg_match('/^[A-Za-z0-9_]+$/', $name)) {
            return $this->invalidTag($name);
        }

        //只允许读白名单里的配置项，防止把邮箱密码、微信密钥这类敏感项误输出到前台
        if (!in_array($name, $this->allowKeys, true)) {
            return $this->invalidTag($name);
        }

        $json = trim((string) ($tag['json'] ?? '0'));
        if (!in_array($json, ['0', '1'], true)) {
            return $this->tagError('miu:global', "json 只能是 0 或 1，现在写的是：{$json}", $content);
        }

        //raw=1 时不做转义
        $raw = isset($tag['raw']) && $tag['raw'] !== '' && $tag['raw'] !== '0' && strtolower((string) $tag['raw']) !== 'false';

        $value = "get_system_config('{$name}')";

        //带 default 时：配置为 null 或空串就用默认值。
        //注意只能用 === '' 判断，不能用 empty()：'0' 是有效值（比如 is_recycle=0），不该被默认值顶掉
        if (array_key_exists('default', $tag)) {
            //var_export 生成安全的 PHP 字符串字面量，默认值里的引号、反斜杠都会正确转义
            $default = var_export((string) $tag['default'], true);
            $value   = '($__miu_global = ' . $value . ") === null || \$__miu_global === '' ? {$default} : \$__miu_global";
        }

        if ($json === '1') {
            //模板值放在 JSON 字符串的引号内部，因此编码后去掉 json_encode 加上的首尾引号。
            return "<?php echo substr(json_encode((string) ({$value}), JSON_UNESCAPED_UNICODE | JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_INVALID_UTF8_SUBSTITUTE), 1, -1); ?>";
        }

        if ($raw) {
            return "<?php echo {$value}; ?>";
        }

        return "<?php echo htmlspecialchars((string) ({$value}), ENT_QUOTES); ?>";
    }

    /**
     * 生成「无效的标签名[xxx]」提示的编译代码
     *
     * name 是模板里写死的字面量，但可能带引号、尖括号等字符，所以：
     *   1) 用 var_export 生成 PHP 字符串字面量 —— 引号、反斜杠都不会拼坏编译后的 PHP
     *   2) 输出时做 HTML 转义 —— 提示内容不会被浏览器当标签解析
     */
    private function invalidTag(string $name): string
    {
        return '<?php echo htmlspecialchars(' . var_export('无效的标签名[' . $name . ']', true) . ', ENT_QUOTES); ?>';
    }
}
