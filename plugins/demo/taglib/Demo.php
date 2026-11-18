<?php
/**
 * 演示插件 - 模板标签
 *
 * 标签库名取「类名的小写」，本类叫 Demo，所以模板里写 {demo:xxx}。
 * 类放在 plugins/demo/taglib/ 下，插件启用后会被自动预加载，模板里不用再写 {taglib}。
 *
 * 用法：{demo:hello name='访客' /}
 * 标签参数是用户可控文本时，最终输出前要做 HTML 转义。
 */

declare(strict_types=1);

namespace plugins\demo\taglib;

use think\template\TagLib;

class Demo extends TagLib
{
    /**
     * 标签定义：attr 属性列表，close 是否闭合（0 自闭合）
     * @var array
     */
    protected $tags = [
        'hello' => ['attr' => 'name', 'close' => 0],
    ];

    /**
     * 编译 {demo:hello /}
     *
     * 模板标签的编译结果是「一段 PHP 代码」，不是直接输出内容，
     * 所以这里把参数安全地拼进 PHP 字面量，交给静态方法 render() 去输出。
     */
    public function tagHello(array $tag, string $content): string
    {
        $name = (string) ($tag['name'] ?? '世界');
        //var_export 生成安全的 PHP 字符串字面量（引号、反斜杠都会正确转义）
        return '<?php echo \plugins\demo\taglib\Demo::render(' . var_export($name, true) . '); ?>';
    }

    /**
     * 实际输出
     * @param string $name
     * @return string
     */
    public static function render(string $name = '世界'): string
    {
        return '你好，' . htmlspecialchars($name, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') . '！';
    }
}
