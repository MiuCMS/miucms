<?php
/**
 * @copyright Copyright (c) 2026 米柚工作室
 * @license https://opensource.org/licenses/Apache-2.0
 * @link https://www.miucms.com
 */

declare (strict_types = 1);

namespace app\home\taglib\traits;

/**
 * {miu:custom} 读取后台「自定义变量」（cms_custom）
 *
 * 用法（跟 {miu:global} 一个套路）：
 *   {miu:custom name='kefu_qq'/}                      输出变量值，默认做 HTML 转义
 *   {miu:custom name='kefu_qq' default='未设置'/}      值为空（null 或空串）时输出 default
 *   {miu:custom name='site_code2' raw='1'/}           raw=1 时原样输出（变量里存的是 HTML/JS 时用）
 *
 * 参数（填错不报错、直接在页面上输出原因）：
 *   name     变量标识（后台新增变量时填的那个「字段标识」）
 *   default  值为空时的默认值，不写就输出空
 *   raw      raw=1 不转义，其它值都按转义处理
 *
 * ⚠ 变量「不存在」或「已禁用」（后台状态=停用）时，页面不会报错，
 *   而是直接输出一句提示告诉你去后台哪里改：
 *     自定义变量[xxx]不存在，去后台「基础 → 自定义变量」加一个
 *     自定义变量[xxx]已禁用，去后台「基础 → 自定义变量」把它启用
 *   （这时候 default 不生效 —— 先让站长看见配置有问题，比默默输出空更有用）
 *
 * 另外说明：
 *   · 变量值在「渲染时」现取，所以后台改了变量值刷新页面就生效，不用清缓存
 *   · 数据按语言持久缓存 24 小时，并复用请求内缓存；后台修改时清理 custom 缓存标签
 *   · 本标签只输出值；图片值放入 img 的 src，可信 HTML 才启用 raw，不提供 {$vo} 循环变量
 */
trait CustomTag
{
    public function tagCustom(array $tag, string $content): string
    {
        // ---------------- 属性白名单 ----------------
        $allowAttr = ['name', 'default', 'raw'];
        $err       = $this->checkAttrs($tag, $allowAttr, 'miu:custom', $content);
        if ($err !== '') {
            return $err;
        }

        // ---------------- name ----------------
        //只能是字母数字下划线短横线（跟后台新增变量时的规则一致），
        //写错了不抛异常，输出一句提示
        $name = trim((string) ($tag['name'] ?? ''));
        if (!preg_match('/^[A-Za-z0-9_\-]{1,32}$/', $name)) {
            return $this->customHint('无效的变量名[' . $name . ']，name 只能填字母、数字、下划线或短横线');
        }

        // ---------------- raw ----------------
        //raw=1 时不做转义（跟 {miu:global} 一样：'' / '0' / 'false' 都算没开）
        $raw = isset($tag['raw']) && $tag['raw'] !== '' && $tag['raw'] !== '0' && strtolower((string) $tag['raw']) !== 'false';

        // ---------------- default ----------------
        //只有模板里写了 default 才传（没写传 null，表示「值空就输出空」）
        $default = array_key_exists('default', $tag) ? var_export((string) $tag['default'], true) : 'null';

        // ---------------- 生成编译代码 ----------------
        //注意：这里只把「变量名」编译进模板，变量值在渲染时现取
        //（编译时查库的话，站长改了值就得重新编译模板才生效）
        return '<?php echo miu_custom_output('
            . var_export($name, true) . ', '
            . $default . ', '
            . ($raw ? 'true' : 'false')
            . '); ?>';
    }

    /**
     * 变量名写错时的提示的编译代码
     *
     * 跟 GlobalTag::invalidTag() 一个套路：用 var_export 生成安全的 PHP 字面量，
     * 输出时做 HTML 转义，提示内容不会被浏览器当标签解析
     */
    private function customHint(string $message): string
    {
        return '<?php echo htmlspecialchars(' . var_export($message, true) . ', ENT_QUOTES); ?>';
    }
}
