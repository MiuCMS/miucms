<?php
declare(strict_types=1);

namespace app\home\service;

/** 仅为缺少实际内容的可视化预览提供模板变量。 */
final class TemplateVisualSample
{
    public static ?array $documents = null;

    public static function context(string $kind, string $template, string $source, string $image, array $nav): array
    {
        $nid = preg_replace('/^(?:view|list)_/', '', $template);
        $channel = get_channel((int) ($nav['channel_id'] ?? 0));
        $channel += ['id' => 0, 'nid' => $nid, 'title' => '示例模型', 'table' => ''];
        $shared = [
            'id' => 0, 'channel_id' => $channel['id'], 'url' => '#', 'full_url' => '#', 'src' => '#',
            'title_ext_1' => '这段文字用于查看模板布局。', 'title_ext_2' => '', 'title_ext_3' => '',
            'seo_title' => '', 'seo_keywords' => '', 'seo_description' => '可视化编辑示例', 'litpic' => $image,
            'content' => '<p>这段示例正文用于查看文章排版。</p>', 'md_content' => '这段示例正文用于查看文章排版。',
        ];
        $nav += ['title' => $kind === 'tag' ? '示例标签' : '示例栏目', 'pid' => 0, 'top_navid' => 0, 'is_url' => 0] + $shared;
        $nav += self::fields($source, 'nav', '');
        $doc = [
            'title' => '示例文档', 'short_title' => '示例文档', 'title_full' => '示例文档',
            'nav_id' => (int) $nav['id'], 'top_navid' => (int) $nav['top_navid'], 'nav_name' => $nav['title'], 'nav_url' => '#',
            'nid' => $channel['nid'], 'date' => date('Y-m-d'), 'add_time' => time(), 'release_time' => time(),
            'update_time' => time(), 'click' => 0, 'collection' => 0, 'title_style' => '', 'title_color' => '', 'is_b' => 0,
            'author' => '示例作者', 'source' => '', 'description' => $shared['seo_description'], 'tag_ids' => '', 'tag_list' => [],
            'imgs' => $image === '' ? [] : [['src' => $image, 'name' => '示例图片']],
            'videos' => [], 'downloads' => [], 'download_count' => 0, 'has_dplayer' => false,
        ] + $shared + self::fields($source, 'doc|info|detail|vo', '示例内容');

        $isList = $kind === 'tag' || ($kind === 'list' && !in_array($channel['nid'], ['single', 'clue'], true));
        $list = $isList ? array_map(static function (int $number) use ($doc): array {
            $title = '示例文档 ' . $number;
            return ['title' => $title, 'title_full' => $title, 'short_title' => $title] + $doc;
        }, [1, 2, 3]) : [];
        self::$documents = $list;
        $tag = $kind === 'tag' ? ['id' => 0, 'title' => '示例标签', 'click' => 0, 'url' => '#', 'full_url' => '#'] : [];
        miu_current_nav_id((int) $nav['id']);
        miu_current_doc($kind === 'view' ? $doc : []);
        miu_current_tag($tag);
        $title = $kind === 'view' ? $doc['title'] : $nav['title'];
        miu_assign_page_meta(['seo_title' => $title, 'seo_description' => $shared['seo_description']], home_url(), $kind === 'view' ? 'article' : 'website');

        return [
            'nav' => $nav, 'navs' => [], 'channel' => $channel, 'nid' => $channel['nid'],
            'doc' => $doc, 'info' => $doc, 'detail' => $doc, 'prev' => [], 'next' => [],
            'tag' => $tag, 'tag_url' => '#', 'list' => $list, 'total' => count($list),
            'page' => 1, 'page_no' => 1, 'page_count' => 1, 'page_size' => 10, 'page_html' => '',
            'page_title' => $title, 'editor' => (string) get_system_config('editor') === '2' ? '2' : '1',
        ];
    }

    private static function fields(string $source, string $roots, string $default): array
    {
        $pattern = '~\$(?:' . $roots . ')(?:\.([A-Za-z_]\w*)|\[[\x27\x22]([A-Za-z_]\w*)[\x27\x22]\])([.\[])?~';
        preg_match_all($pattern, $source, $matches, PREG_SET_ORDER | PREG_UNMATCHED_AS_NULL);
        $values = [];
        foreach ($matches as $match) {
            $key = $match[2] ?? $match[1];
            if ($match[3] !== null) {
                $values[$key] = [];
            } elseif (!array_key_exists($key, $values)) {
                // 避免把占位文字当成文件地址、标识或开关。
                $values[$key] = preg_match('~(?:^id$|_id$|^is_|^has_|^url$|_url$|^path$|_path$|^src$|_src$)~i', $key) ? '' : $default;
            }
        }
        return $values;
    }
}
