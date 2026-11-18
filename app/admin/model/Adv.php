<?php
/**
 * @copyright Copyright (c) 2026 米柚工作室
 * @license https://opensource.org/licenses/Apache-2.0
 * @link https://www.miucms.com
 */

namespace app\admin\model;

use think\Model;

/**
 * 广告（cms_adv）
 *
 * 表字段：id / title / type / mark / status / content / desc / add_time / update_time
 *
 * type 决定 content 里存的是什么：
 *   1 轮播图片    content 是一段 JSON 数组，每张图一条记录
 *   2 多媒体      content 直接就是那条媒体地址
 *   3 HTML代码    content 就是那段 HTML
 *
 * 图片 JSON 里每条记录的字段（跟表单里 img_* 那组一一对应）：
 *   img_src     图片地址
 *   img_title   图片标题
 *   img_desc    图片注释，支持 HTML 代码（保存时挡掉 <script>、onxxx、javascript: 等）
 *   img_url     点击跳转链接（保存时挡掉 javascript: 这类伪协议）
 *   img_target  是否新窗口打开，'1' / '0'
 *   i18n        按语言代码保存自动翻译后的标题和注释
 */
class Adv extends Model
{
    /** 轮播图片 */
    const TYPE_IMAGE = 1;
    /** 多媒体 */
    const TYPE_MEDIA = 2;
    /** HTML 代码 */
    const TYPE_HTML = 3;

    /**
     * 类型 => 名称（后台列表、提示文案共用一份）
     *
     * @return array
     */
    public static function typeMap(): array
    {
        return [
            self::TYPE_IMAGE => '轮播图片',
            self::TYPE_MEDIA => '多媒体',
            self::TYPE_HTML  => 'HTML代码',
        ];
    }

    /**
     * 类型名
     *
     * @param int $type
     * @return string
     */
    public static function typeName(int $type): string
    {
        return self::typeMap()[$type] ?? '未知';
    }

    /**
     * 类型值是否合法
     *
     * @param int $type
     * @return bool
     */
    public static function isValidType(int $type): bool
    {
        return isset(self::typeMap()[$type]);
    }

    /**
     * 按类型把表单提交的内容组装成要写进 content 的值
     *
     * @param int   $type  广告类型（1/2/3）
     * @param array $param 表单参数
     * @return array [content, error] error 非空表示没通过，直接把 error 返回给前端
     */
    public static function buildContent(int $type, array $param): array
    {
        if ($type === self::TYPE_IMAGE) {
            $srcs    = (array) ($param['img_src'] ?? []);
            $titles  = (array) ($param['img_title'] ?? []);
            $descs   = (array) ($param['img_desc'] ?? []);
            $links   = (array) ($param['img_url'] ?? []);
            $targets = (array) ($param['img_target'] ?? []);
            $translations = (array) ($param['img_i18n'] ?? []);

            $list = [];
            foreach ($srcs as $i => $src) {
                $src = trim((string) $src);
                //空行（表单里删剩下的空壳）跳过
                if ($src === '') {
                    continue;
                }

                //注释是 HTML 片段，只去首尾空白，中间原样保留
                $desc = trim((string) ($descs[$i] ?? ''));
                //注释会当 HTML 输出，保存前先挡掉能执行的东西
                if ($desc !== '') {
                    $bad = self::htmlScriptError($desc);
                    if ($bad !== '') {
                        return ['', '第 ' . (count($list) + 1) . ' 张图片的注释' . $bad];
                    }
                }

                //跳转链接前台放进 <a href>，同样不能是 JS 伪协议
                $link = trim((string) ($links[$i] ?? ''));
                if ($link !== '') {
                    $bad = self::urlScriptError($link);
                    if ($bad !== '') {
                        return ['', '第 ' . (count($list) + 1) . ' 张图片的跳转链接' . $bad];
                    }
                }

                $image = [
                    'img_src'    => $src,
                    'img_title'  => trim((string) ($titles[$i] ?? '')),
                    'img_desc'   => $desc,
                    'img_url'    => $link,
                    'img_target' => ((string) ($targets[$i] ?? '0')) === '1' ? '1' : '0',
                ];
                $imageI18n = self::decodeImageTranslations($translations[$i] ?? []);
                foreach ($imageI18n as $locale => $fields) {
                    $translatedDesc = trim((string) ($fields['img_desc'] ?? ''));
                    if ($translatedDesc !== '') {
                        $bad = self::htmlScriptError($translatedDesc);
                        if ($bad !== '') {
                            return ['', '第 ' . (count($list) + 1) . ' 张图片的 ' . $locale . ' 扩展内容' . $bad];
                        }
                    }
                }
                if ($imageI18n) {
                    $image['i18n'] = $imageI18n;
                }
                $list[] = $image;
            }

            if (!$list) {
                return ['', '轮播图片至少要有一张图片'];
            }

            //不转义斜杠和中文：存进去的地址要能直接读，后台也好看
            return [json_encode($list, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES), ''];
        }

        if ($type === self::TYPE_MEDIA) {
            $url = trim((string) ($param['media_url'] ?? ''));
            if ($url === '') {
                return ['', '请填写或选择多媒体文件'];
            }

            return [$url, ''];
        }

        if ($type === self::TYPE_HTML) {
            $html = (string) ($param['html_content'] ?? '');
            if (trim($html) === '') {
                return ['', '请填写 HTML 代码'];
            }

            return [$html, ''];
        }

        return ['', '请选择广告类型'];
    }

    /** 读取每张图片随表单回传的多语言字段，保留禁用语言的已有译文。 */
    private static function decodeImageTranslations($value): array
    {
        if (is_string($value)) {
            $decoded = json_decode($value, true);
            $value = is_array($decoded) ? $decoded : [];
        }
        if (!is_array($value)) {
            return [];
        }

        $supported = \app\home\service\TemplateI18n::supportedLocales();
        $result = [];
        foreach ($value as $locale => $fields) {
            $locale = \app\home\service\TemplateI18n::normalizeLocale((string) $locale);
            if ($locale === '' || !isset($supported[$locale]) || !is_array($fields)) {
                continue;
            }

            $entry = [];
            foreach (['img_title', 'img_desc'] as $field) {
                if (isset($fields[$field]) && is_scalar($fields[$field])) {
                    $entry[$field] = (string) $fields[$field];
                }
            }
            $hashes = is_array($fields['source_hash'] ?? null) ? $fields['source_hash'] : [];
            foreach (['img_title', 'img_desc'] as $field) {
                $hash = $hashes[$field] ?? '';
                if (is_string($hash) && preg_match('/^[a-f0-9]{64}$/i', $hash)) {
                    $entry['source_hash'][$field] = strtolower($hash);
                }
            }
            if ($entry) {
                $result[$locale] = $entry;
            }
        }

        return $result;
    }

    /**
     * 检查一段 HTML 片段里有没有 JS 代码
     *
     * 图片注释是当 HTML 输出到前台的，允许写排版标签，但不允许塞能执行的东西。
     * 返回空串表示没问题；否则返回错误原因，调用处直接拼进提示里给用户看。
     *
     * @param string $html
     * @return string
     */
    public static function htmlScriptError(string $html): string
    {
        //先把 HTML 注释去掉，免得注释里的说明文字被当成代码
        $s = (string) preg_replace('/<!--.*?-->/s', '', $html);

        if (preg_match('/<\s*script\b/i', $s)) {
            return '里不能写 <script> 代码';
        }

        //标签内的 onxxx 事件属性，如 onclick= / onerror= / onload=
        //（[^>]* 不跨 >，所以只在同一个标签里找）
        if (preg_match('/<\s*[a-z][^>]*\son[a-z]+\s*=/i', $s)) {
            return '里不能写 onclick、onerror 这类 JS 事件属性';
        }

        //伪协议：先把实体编码还原再查，免得用 &#106;avascript: 这种写法绕过
        $decoded = html_entity_decode($s, ENT_QUOTES | ENT_HTML5, 'UTF-8');

        if (preg_match('/(?:javascript|vbscript)\s*:/i', $decoded)) {
            return '里不能写 javascript: 这类脚本链接';
        }

        if (preg_match('/data\s*:\s*text\/html/i', $decoded)) {
            return '里不能写 data:text/html 这类内容';
        }

        return '';
    }

    /**
     * 检查一个链接地址是不是 JS 伪协议
     *
     * 图片的跳转链接前台是放进 <a href="..."> 的，javascript: 这类点一下就会执行。
     * 返回空串表示没问题；否则返回错误原因，调用处直接拼进提示里给用户看。
     *
     * @param string $url
     * @return string
     */
    public static function urlScriptError(string $url): string
    {
        //空白和不可见字符全去掉：'javascript :'、'java\tscript:' 都是想绕过
        $s = (string) preg_replace('/[\s\x00-\x20\x7F]+/', '', $url);
        //实体编码也还原一下，免得用 &#106;avascript: 这种写法绕过
        $s = html_entity_decode($s, ENT_QUOTES | ENT_HTML5, 'UTF-8');

        //只认开头的协议：地址里带 javascript: 字样（如 ?x=javascript:1）不算
        if (preg_match('/^(?:javascript|vbscript)\s*:/i', $s)) {
            return '里不能写 javascript: 这类脚本链接';
        }

        if (preg_match('/^data\s*:\s*text\/html/i', $s)) {
            return '里不能写 data:text/html 这类内容';
        }

        return '';
    }

    /**
     * 取轮播图片列表
     *
     * 解析规则跟前台共用一份（app/common.php 的 adv_decode_images），
     * 这里只是包一层，返回 null 表示「不是图片 JSON」。
     *
     * @param string|null $content
     * @return array|null 不是图片 JSON 时返回 null
     */
    public static function decodeImages(?string $content): ?array
    {
        $content = trim((string) $content);
        //图片 JSON 一定以 [ 开头；先看第一个字符，避免每段 HTML 都去 json_decode
        if ($content === '' || $content[0] !== '[') {
            return null;
        }

        $arr = adv_decode_images($content);

        return $arr ?: null;
    }

    /**
     * 列表里显示的内容摘要
     *
     * @param string|null $content
     * @param int         $type
     * @return array ['text' => 摘要文字, 'thumb' => 缩略图地址（没有就空串）, 'count' => 条数]
     */
    public static function contentSummary(?string $content, int $type): array
    {
        if ($type === self::TYPE_IMAGE) {
            $imgs = self::decodeImages($content) ?: [];

            return [
                'text'  => count($imgs) . ' 张图片',
                //缩略图取图片地址（img_src）；img_url 是点击跳转链接，别取错了
                'thumb' => (string) ($imgs[0]['img_src'] ?? ''),
                //条数：后台「调用标签」弹窗拼 JS 的 row 参数用它（轮播图就是图片张数）
                'count' => count($imgs),
            ];
        }

        if ($type === self::TYPE_MEDIA) {
            return [
                'text'  => (string) $content,
                'thumb' => '',
                'count' => trim((string) $content) === '' ? 0 : 1,
            ];
        }

        if ($type === self::TYPE_HTML) {
            $text = trim((string) preg_replace('/\s+/', ' ', strip_tags((string) $content)));

            return [
                'text'  => $text === '' ? '（HTML 代码）' : mb_substr($text, 0, 60, 'UTF-8'),
                'thumb' => '',
                'count' => trim((string) $content) === '' ? 0 : 1,
            ];
        }

        return ['text' => '（暂无内容）', 'thumb' => '', 'count' => 0];
    }
}
