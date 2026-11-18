<?php
/**
 * @copyright Copyright (c) 2026 米柚工作室
 * @license https://opensource.org/licenses/Apache-2.0
 * @link https://www.miucms.com
 */

declare (strict_types = 1);

namespace app\home\taglib\traits;

/**
 * {miu:adv} 广告 / 轮播（成对标签）
 *
 * 后台「基础 → 广告/轮播」里每条广告都有一个「标识」(mark)，模板里用它调。
 * 列表内容由广告类型决定，标签只管按条循环，取什么字段看类型：
 *
 *   · 轮播图片
 *     {miu:adv mark='INDEX_SLIDE'}
 *         <a href="{$vo.url}"{$vo.target}>
 *             <img src="{$vo.src}" alt="{$vo.title}">
 *         </a>
 *     {/miu:adv}
 *     每条可用：{$vo.src} 图片地址、{$vo.title} 图片标题、
 *               {$vo.desc} 图片注释（HTML，用 {$vo.desc|raw} 原样输出）、
 *               {$vo.url} 跳转链接、{$vo.full_url} 带网站域名的链接、{$vo.target} 新窗口属性片段（带前后空格的 target="_blank" 或空字符串）
 *     可加 active='active'，仅第一条循环的 {$vo.active} 输出 active，其余条为空
 *     （数据库和后台表单里存的仍然是 img_src / img_title / img_desc / img_url / img_target，
 *      前台输出时统一换成上面这些短名，旧名也还留着）
 *
 *   · 多媒体（只有一条）
 *     {miu:adv mark='INDEX_VIDEO'}
 *         <video src="{$vo.media_url}" controls preload="metadata"></video>
 *     {/miu:adv}
 *
 *   · HTML代码（只有一条，代码原样输出）
 *     {miu:adv mark='INDEX_HTML'}{$vo.html|raw}{/miu:adv}
 *
 * ------------------------------------------------------------------
 * 参数说明（都有默认值，填错不报错、直接在页面上输出原因）
 * ------------------------------------------------------------------
 * mark     广告标识（后台设的「标识」），必填。只能字母、数字、下划线、短横线
 * id       取值对象名，默认 vo（也可以写成 name；两个都写时以 id 为准）
 * key      索引变量名，默认 index；从 1 开始，按 1、2、3……递增
 * active   第一条循环中 {$vo.active} 输出的类名，默认 active；其他条为空
 * empty    没有广告 / 广告被禁用 / 广告没内容时输出的内容，默认空
 *
 * ------------------------------------------------------------------
 * 额外说明
 * ------------------------------------------------------------------
 * · 取的是「状态 = 正常」的广告；被禁用的广告这里直接当没有，什么都不输出。
 * · 广告是持久缓存的（标记 homeAdv），后台改完广告会自动清，前台不用手动处理。
 * · 后台广告变更会清理广告缓存；静态页需另行生成。
 *   标签库本身的改动要清模板编译缓存，普通模板文件修改由模板引擎检测。
 */
trait AdvTag
{
    public function tagAdv(array $tag, string $content): string
    {
        // ---------------- 取值对象名 / 索引变量名 ----------------
        [$id, $key] = $this->parseLoopVars($tag);

        $err = $this->checkLoopVars($id, $key, 'miu:adv', $content);
        if ($err !== '') {
            return $err;
        }

        // ---------------- 属性白名单 ----------------
        $allowAttr = ['id', 'name', 'key', 'mark', 'active', 'empty'];
        $err       = $this->checkAttrs($tag, $allowAttr, 'miu:adv', $content);
        if ($err !== '') {
            return $err;
        }

        // ---------------- mark ----------------
        $mark = trim((string) ($tag['mark'] ?? ''));
        if ($mark === '') {
            return $this->tagError('miu:adv', '缺少 mark 参数（广告标识，在后台「基础 → 广告/轮播」里设置）', $content);
        }
        if (!preg_match('/^[A-Za-z0-9_\-]{1,64}$/', $mark)) {
            return $this->tagError('miu:adv', "mark 只能是字母、数字、下划线或短横线，现在写的是：{$mark}", $content);
        }

        //active 会被模板直接输出到页面，只允许安全的 CSS 类名字符，避免注入 HTML 属性或脚本。
        $active = trim((string) ($tag['active'] ?? 'active'));
        if ($active !== '' && !preg_match('/^[A-Za-z0-9_\-]{1,64}$/', $active)) {
            return $this->tagError('miu:adv', 'active 只能是 64 个以内的字母、数字、下划线或短横线', $content);
        }

        // ---------------- empty ----------------
        $empty = (string) ($tag['empty'] ?? '');

        // ---------------- 生成编译代码 ----------------
        $list    = '$__miu_adv_' . (++$this->navSeq);
        $counter = '$__miu_adv_index_' . $this->navSeq;

        $parseStr  = '<?php ' . $list . ' = miu_adv(' . var_export($mark, true) . '); ?>';
        $parseStr .= '<?php if (count(' . $list . ') == 0) : echo ' . var_export($empty, true) . ' ; ?>';
        $parseStr .= '<?php else: ' . $counter . ' = 0; try { foreach (' . $list . ' as $' . $id . '): $' . $key . ' = ++' . $counter . '; $' . $id . '[\'active\'] = ($' . $key . ' === 1) ? ' . var_export($active, true) . " : ''; ?>";
        $parseStr .= $content;
        $parseStr .= '<?php endforeach; ' . $this->loopCatch($id, $key, 'miu:adv') . ' endif; ?>';

        return $parseStr;
    }
}
