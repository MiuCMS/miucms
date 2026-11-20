<?php
declare(strict_types=1);

namespace app\home\taglib;

use think\template\TagLib;

/**
 * 插件卸载后仍识别 {comment:widget /}，实际渲染交给评论插件。
 */
class Comment extends TagLib
{
    protected $tags = ['widget' => ['attr' => '', 'close' => 0]];

    public function tagWidget(array $tag, string $content): string
    {
        return <<<'PHP'
<?php
if (class_exists(\plugins\miu_comment\taglib\Comment::class)) {
    if (method_exists(\plugins\miu_comment\taglib\Comment::class, 'renderPage')) {
        echo \plugins\miu_comment\taglib\Comment::renderPage((array) ($doc ?? []), (array) ($nav ?? []), (string) ($nid ?? ''), (string) ($page_og_type ?? ''));
    } else {
        echo \plugins\miu_comment\taglib\Comment::render((int) ($doc['id'] ?? 0));
    }
}
?>
PHP;
    }
}
