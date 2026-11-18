<?php
/**
 * @copyright Copyright (c) 2026 米柚工作室
 * @license https://opensource.org/licenses/Apache-2.0
 * @link https://www.miucms.com
 */

declare (strict_types = 1);

namespace app\home\taglib\traits;

/**
 * 标签公用小工具（所有 {miu:xxx} 标签共用）
 *
 * 这里放的都是原来每个标签里重复写一遍的东西，抽出来只为少写几遍，功能不变：
 *   · tagError()      参数填错时的提示（不抛异常，页面直接显示原因）
 *   · checkAttrs()    检查有没有写不认识的属性（比如把 limit 拼成 limt）
 *   · parseLoopVars() 解析循环变量名 id / 索引变量名 key
 *   · checkLoopVars() 校验这两个变量名
 *   · loopCatch()     生成循环体的 try/catch 收尾代码
 *
 * 这些方法最终都是 Miu 类自己的方法（trait 在编译时被复制进类里），
 * 所以 trait 之间互相调用、访问 Miu 的私有属性都没问题。
 */
trait TagSupport
{
    /**
     * 标签参数填错时的提示（不抛异常，页面直接显示原因）
     *
     * 成对标签的返回值里必须带上 $content（ThinkPHP 的占位符），
     * 否则 ThinkPHP 认为这个标签不需要替换，标签会原样留在页面上。
     * 标签内部内容包进「遍历一个空数组」里，这样即使里面写了 {$vo.xxx}
     * 也不会报未定义变量（那段代码根本不会执行）
     *
     * @param string $tagName 标签名（miu:nav / miu:list …），只用于提示文案
     * @param string $reason  出错原因
     * @param string $content 标签内部内容（成对标签的占位符；自闭合标签传空串）
     * @return string 编译后的 PHP 代码
     */
    private function tagError(string $tagName, string $reason, string $content): string
    {
        $msg  = '<?php echo htmlspecialchars(' . var_export($tagName . ' 标签参数错误：' . $reason, true) . ', ENT_QUOTES); ?>';
        $msg .= '<?php $__miu_tag_empty = []; foreach ($__miu_tag_empty as $__miu_tag_item): ?>';
        $msg .= $content;
        $msg .= '<?php endforeach; ?>';

        return $msg;
    }

    /**
     * 检查有没有写不认识的属性（比如把 limit 拼成 limt）
     *
     * 不检查的话，拼错的属性会被当成「没写」，用默认值跑，出了问题很难查。
     * expression 是 ThinkPHP 内部塞进来的（标签一个属性都没写时），不是用户写的，跳过。
     *
     * @param array  $tag       ThinkPHP 传进来的标签属性
     * @param array  $allowAttr 允许的属性名
     * @param string $tagName   标签名，只用于提示文案
     * @param string $content   标签内部内容
     * @return string 空字符串 = 没问题；非空 = 可以直接 return 的错误提示
     */
    private function checkAttrs(array $tag, array $allowAttr, string $tagName, string $content): string
    {
        foreach (array_keys($tag) as $attrName) {
            if ($attrName === 'expression') {
                continue;
            }
            if (!in_array($attrName, $allowAttr, true)) {
                return $this->tagError($tagName, "不支持的属性 {$attrName}，支持的有：" . implode('、', $allowAttr), $content);
            }
        }

        return '';
    }

    /**
     * 解析「循环变量名 id / 索引变量名 key」
     *
     * id 也可以写成 name，两个都写时以 id 为准；都没写就用默认值 vo / index。
     *
     * @param array $tag ThinkPHP 传进来的标签属性
     * @return array [$id, $key]
     */
    private function parseLoopVars(array $tag): array
    {
        $id = trim((string) ($tag['id'] ?? $tag['name'] ?? ''));
        if ($id === '') {
            $id = 'vo';
        }

        $key = trim((string) ($tag['key'] ?? ''));
        if ($key === '') {
            $key = 'index';
        }

        return [$id, $key];
    }

    /**
     * 校验「循环变量名 id / 索引变量名 key」
     *
     * 只能是以字母或下划线开头的变量名，且不能是 PHP 的超级全局变量
     * （$this、$GLOBALS、$_GET 这些，用了会把模板环境搞坏）；两个也不能同名。
     *
     * @param string $id      循环变量名
     * @param string $key     索引变量名
     * @param string $tagName 标签名，只用于提示文案
     * @param string $content 标签内部内容
     * @return string 空字符串 = 没问题；非空 = 可以直接 return 的错误提示
     */
    private function checkLoopVars(string $id, string $key, string $tagName, string $content): string
    {
        $reserved = ['this', 'GLOBALS', '_GET', '_POST', '_REQUEST', '_SERVER', '_FILES', '_ENV', '_COOKIE', '_SESSION'];

        if (!preg_match('/^[a-zA-Z_][a-zA-Z0-9_]{0,31}$/', $id) || in_array($id, $reserved, true)) {
            return $this->tagError($tagName, "id 只能是以字母或下划线开头的变量名，现在写的是：{$id}", $content);
        }
        if (!preg_match('/^[a-zA-Z_][a-zA-Z0-9_]{0,31}$/', $key) || in_array($key, $reserved, true)) {
            return $this->tagError($tagName, "key 只能是以字母或下划线开头的变量名，现在写的是：{$key}", $content);
        }
        if ($key === $id) {
            return $this->tagError($tagName, "key 不能和 id 用同一个变量名（都是 {$key}），换一个，比如 key='index'", $content);
        }

        return '';
    }

    /**
     * 标签本身写错（漏写/多写结束标签）时的提示
     *
     * 跟 tagError() 的区别：tagError() 是「参数写错」，这个是「标签结构写错」，
     * 而且没有标签内部内容要保留，所以只输出一句提示。
     *
     * 提示文案里故意不写带花括号的原样标签语法（比如 {/miu:nav}）：
     * 万一同一份模板内容被解析两次，免得把提示里的花括号又当成标签替换一遍。
     *
     * @param string $tagName 标签名（miu:nav 这种，带标签库前缀）
     * @param string $reason  出错原因
     * @return string 编译后的 PHP 代码
     */
    private function tagSyntaxError(string $tagName, string $reason): string
    {
        return '<?php echo htmlspecialchars(' . var_export($tagName . ' 标签写错了：' . $reason, true) . ', ENT_QUOTES); ?>';
    }

    /**
     * 生成循环体的 try/catch 收尾代码（注意：返回的是不带 <?php 的代码片段）
     *
     * 模板里变量名/字段名写错（比如 id='vo' 却写成 {$vo1.name}）时，不把整个页面
     * 搞成 500，而是显示一句提示；其它异常照旧抛出去。注意 TP 的错误异常是
     * think\exception\ErrorException（继承自 think\Exception，不是 PHP 原生的
     * \ErrorException），所以这里直接抓 \Throwable，认不出来再抛回去。
     *
     * 用法（拼在 foreach 后面，前面别忘了 endforeach;）：
     *   $parseStr .= '<?php endforeach; ' . $this->loopCatch($id, $key, 'miu:nav') . ' endif; ?>';
     *
     * 临时变量固定叫 $__miu_e / $__miu_hint：每处 catch 都是先赋值再使用，
     * 嵌套的标签之间不会互相干扰。
     *
     * @param string $id      循环变量名
     * @param string $key     索引变量名
     * @param string $tagName 标签名
     * @return string PHP 代码片段（不带 <?php 标签）
     */
    private function loopCatch(string $id, string $key, string $tagName): string
    {
        return '} catch (\Throwable $__miu_e) { '
            . '$__miu_hint = miu_tpl_error($__miu_e->getMessage(), ' . var_export($id, true) . ', ' . var_export($key, true) . ', ' . var_export($tagName, true) . '); '
            . 'if ($__miu_hint === \'\') { throw $__miu_e; } '
            . 'echo htmlspecialchars($__miu_hint, ENT_QUOTES); }';
    }
}
