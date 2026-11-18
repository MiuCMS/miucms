<?php
/**
 * @copyright Copyright (c) 2026 米柚工作室
 * @license https://opensource.org/licenses/Apache-2.0
 * @link https://www.miucms.com
 */

declare (strict_types = 1);

namespace app\home\taglib;

use think\template\TagLib;
use app\home\taglib\traits\TagSupport;
use app\home\taglib\traits\GlobalTag;
use app\home\taglib\traits\CustomTag;
use app\home\taglib\traits\NavTag;
use app\home\taglib\traits\ListTag;
use app\home\taglib\traits\LinkTag;
use app\home\taglib\traits\PagelistTag;
use app\home\taglib\traits\PositionTag;
use app\home\taglib\traits\PositionrawTag;
use app\home\taglib\traits\PrenextTag;
use app\home\taglib\traits\AdvTag;
use app\home\taglib\traits\TagTag;
use app\home\taglib\traits\ClueTag;
use app\home\taglib\traits\MarkdownTag;
use app\home\taglib\traits\LangTag;
use app\home\taglib\traits\LangListTag;
use app\home\taglib\traits\IsindexTag;

/**
 * 前台模板自定义标签库（模板里用 {miu:xxx}）
 *
 * 标签库在 app/home/config/view.php 里用 taglib_pre_load 预加载，
 * 所以模板里不用写 {taglib name="miu"/}，直接用就行。
 * 标签前缀 miu 取自这个类名（ThinkPHP 用类名小写当标签库名），改名会让所有模板失效。
 *
 * 每个标签的实现都单独放在 traits/ 目录下的一个文件里，这里只负责「拼装」：
 *
 *   traits/TagSupport.php       公用小工具：参数错误提示、属性/变量名校验、循环体编译
 *   traits/GlobalTag.php        {miu:global}      读取网站配置（带白名单）
 *   traits/CustomTag.php        {miu:custom}      读取后台「自定义变量」
 *   traits/NavTag.php           {miu:nav}         栏目列表
 *   traits/ListTag.php          {miu:list}        文档列表（文章列表页上自动分页）
 *   traits/LinkTag.php          {miu:link}        友情链接列表
 *   traits/PagelistTag.php      {miu:pagelist}    分页条
 *   traits/PositionTag.php      {miu:position}    面包屑
 *   traits/PositionrawTag.php   {miu:positionraw} 面包屑原始数据
 *   traits/PrenextTag.php       {miu:prenext}     上一篇 / 下一篇（内容页用）
 *   traits/AdvTag.php           {miu:adv}         广告 / 轮播
 *   traits/TagTag.php           {miu:tag}         标签列表（当前文档的 / 全部）
 *   traits/ClueTag.php          {miu:clue}        留言表单变量（不循环，控件由模板排版）
 *   traits/LangTag.php          {miu:lang}        模板文案翻译
 *   traits/LangListTag.php      {miu:langlist}    已启用语言的可自定义循环列表
 *
 * 注意：标签库改了以后模板编译缓存不会自动失效（ThinkPHP 只比对模板文件的修改时间），
 * 要去后台点一下「清缓存」才会重新编译。
 *
 * 模板里标签写错（漏写结束标签之类）不会报莫名的错，会在页面上输出一句人话，
 * 见下面的 parseTag()。
 */
class Miu extends TagLib
{
    //公用小工具 + 各标签实现（每个标签一个文件）
    use TagSupport;
    use GlobalTag;
    use CustomTag;
    use NavTag;
    use ListTag;
    use LinkTag;
    use PagelistTag;
    use PositionTag;
    use PositionrawTag;
    use PrenextTag;
    use AdvTag;
    use TagTag;
    use ClueTag;
    use MarkdownTag;
    use LangTag;
    use LangListTag;
    use IsindexTag;

    /**
     * 标签定义：attr 属性列表，close 是否闭合（0 自闭合，1 成对标签）
     *
     * 这里故意不写 must，并加上 expression：这样 name 没写、或者只写了表达式时，
     * ThinkPHP 不会抛「tag error:xxx」，而是交给对应的 tagXxx 方法输出提示
     */
    protected $tags = [
        'global' => ['attr' => 'name,default,raw', 'expression' => true, 'close' => 0],
        //自定义变量：自闭合 {miu:custom name='kefu_qq'/}
        'custom' => ['attr' => 'name,default,raw', 'expression' => true, 'close' => 0],
        //栏目列表：成对标签 {miu:nav ...}...{/miu:nav}
        'nav'    => ['attr' => 'id,name,key,pid,limit,order,sort,currentstyle,empty,titlelen,notnavid,navid,includehidden', 'expression' => true, 'close' => 1],
        //文档列表：成对标签 {miu:list ...}...{/miu:list}
        'list'   => ['attr' => 'id,name,key,navid,mid,order,sort,limit,pagesize,titlelen,flag,empty', 'expression' => true, 'close' => 1],
        //友情链接列表：成对标签 {miu:link ...}...{/miu:link}
        'link'   => ['attr' => 'id,name,key', 'expression' => true, 'close' => 1],
        //分页：栏目、搜索和标签列表页的分页上下文，自闭合 {miu:pagelist ... /}
        'pagelist'    => ['attr' => 'type,currentstyle,listitem,indextext,pretext,nexttext,endtext,theme', 'expression' => true, 'close' => 0],
        //面包屑：列表页和内容页可用，自闭合 {miu:position ... /}
        'position'    => ['attr' => 'typeid,class,delimiter', 'expression' => true, 'close' => 0],
        //面包屑原始数据：自己排版用，成对标签 {miu:positionraw ...}...{/miu:positionraw}
        'positionraw' => ['attr' => 'id,name,key,currenturl', 'expression' => true, 'close' => 1],
        //上一篇/下一篇：只在内容页有意义，成对标签 {miu:prenext ...}...{/miu:prenext}
        'prenext'     => ['attr' => 'id,name,key,type,titlelen,disclass', 'expression' => true, 'close' => 1],
        //广告/轮播：按后台设的「标识」调，成对标签 {miu:adv ...}...{/miu:adv}
        'adv'         => ['attr' => 'id,name,key,mark,active,empty', 'expression' => true, 'close' => 1],
        //标签列表：内容页取当前文档的标签、也可以取全部，成对标签 {miu:tag ...}...{/miu:tag}
        'tag'         => ['attr' => 'id,name,key,empty,gettype,num,sort', 'expression' => true, 'close' => 1],
        //留言表单：不循环，把字段变量准备好给模板自己排版，成对标签 {miu:clue ...}...{/miu:clue}
        'clue'        => ['attr' => 'id,name,formid', 'expression' => true, 'close' => 1],
        //Markdown 转 HTML：自闭合 {miu:markdown value="$doc.md_content"/}
        'markdown'    => ['attr' => 'value', 'expression' => true, 'close' => 0],
        //模板语言包：{miu:lang key='nav.home' default='首页'/}
        'lang'        => ['attr' => 'key,default,raw', 'expression' => true, 'close' => 0],
        //当前语言代码：{miu:locale name='current'/}
        'locale'      => ['attr' => 'name', 'expression' => true, 'close' => 0],
        //当前页面切换到另一种模板语言：{miu:langurl locale='en'/}
        'langurl'     => ['attr' => 'locale', 'expression' => true, 'close' => 0],
        //已启用语言列表：成对标签，内部 HTML 和样式由模板自行编写
        'langlist'    => ['attr' => 'id,name,key,empty', 'expression' => true, 'close' => 1],
        //首页判断：自闭合标签，仅首页输出 text，默认输出 active
        'isindex'     => ['attr' => 'text', 'expression' => true, 'close' => 0],
    ];

    /**
     * 标签编译时用来生成不重名变量的序号
     *
     * 让每个标签用各自的结果变量，嵌套调用时内外层互不干扰
     */
    private $navSeq = 0;

    /**
     * 解析标签：在 ThinkPHP 的原逻辑之后，再兜一层「标签写错」的提示
     *
     * ThinkPHP 只替换「成对的」标签：{miu:nav ...} 必须能找到它对应的结束标签。
     * 漏写结束标签时它既不报错也不替换，会把 {miu:nav ...} 原样留在页面上，
     * 里面的 {$vo.xxx} 又会因为变量不存在而报一堆莫名的错，很难看出是标签没闭合。
     * 这里在父类处理完之后扫一遍，把没被替换掉的标签换成一句人话。
     *
     * 只会扫到「没配成对」的标签：配好对的已经被父类替换成 PHP 代码了。
     * 模板里的 {literal}...{/literal} 在这一步已经换成占位符，所以写在里面的
     * 标签语法不会被误伤。
     *
     * @param string $content 模板内容（引用，就地替换）
     * @param string $lib     标签库名（ThinkPHP 传进来的是类名 Miu）
     */
    public function parseTag(string &$content, string $lib = ''): void
    {
        parent::parseTag($content, $lib);

        //只检查成对标签：自闭合标签本来就没有结束标签
        $lib   = $lib ? strtolower($lib) . ':' : '';
        $names = [];
        foreach ($this->tags as $name => $val) {
            if (!isset($val['close']) || $val['close']) {
                $names[] = $lib . $name;
            }
        }
        if (!$names) {
            return;
        }

        //一次扫完两种情况：
        //  · 匹配到开始标签 → 漏了结束标签
        //  · 匹配到结束标签 → 前面没有对应的开始标签
        //$m[1] 是开始标签名，$m[2] 是结束标签名，两个里必有一个为空（没参与匹配的那组）
        $replaced = preg_replace_callback($this->getRegex($names, true), function ($m) {
            $open  = $m[1] ?? '';
            $close = $m[2] ?? '';

            if ($open !== '') {
                return $this->tagSyntaxError($open, '缺少结束标签，标签没有被解析。请补上它的结束标签');
            }

            return $this->tagSyntaxError($close, '这个结束标签前面没有对应的开始标签，标签没有被解析');
        }, $content);

        //替换出错（正则异常）时不要动原内容，免得把模板搞坏
        if ($replaced !== null) {
            $content = $replaced;
        }
    }
}
