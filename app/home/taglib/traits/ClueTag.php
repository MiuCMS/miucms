<?php
/**
 * @copyright Copyright (c) 2026 米柚工作室
 * @license https://opensource.org/licenses/Apache-2.0
 * @link https://www.miucms.com
 */

declare (strict_types = 1);

namespace app\home\taglib\traits;

/**
 * {miu:clue} 留言表单标签（读 cms_form + cms_form_field）
 *
 * 这个标签**不循环**：模板里写一次表单骨架，要用哪个字段就自己把那一行写出来，
 * 标签负责把字段的 name / 标题 / 选项这些变量准备好。这样排版完全由模板决定，
 * class 想怎么写就怎么写。
 *
 * 用法：
 *   {miu:clue formid='1'}
 *   <form method="POST" action="{$vo.action}" onsubmit="return miuClueSubmit(this)" autocomplete="off">
 *       <div class="row g-3">
 *           <div class="col-sm-6">
 *               <input type="text" id="attr_1" name="{$vo.attr_1}" placeholder="{$vo.itemname_1}" required>
 *           </div>
 *           <div class="col-12">
 *               <textarea id="attr_15" name="{$vo.attr_15}" placeholder="{$vo.itemname_15}"></textarea>
 *           </div>
 *           <div class="col-12">
 *               <select id="attr_9" name="{$vo.attr_9}">
 *                   <option value="">请选择</option>
 *                   {volist name="vo.options_9" id="opt"}
 *                   <option value="{$opt}">{$opt}</option>
 *                   {/volist}
 *               </select>
 *           </div>
 *           <div class="col-12"><input type="submit" value="提交"></div>
 *       </div>
 *       {$vo.hidden|raw}
 *   </form>
 *   {$vo.script|raw}
 *   {/miu:clue}
 *
 * 里面的 1 / 15 / 9 就是后台「留言 → 表单管理 → 字段管理」里那个字段的 ID。
 *
 * ------------------------------------------------------------------
 * 参数说明（填错不报错、直接在页面上输出原因）
 * ------------------------------------------------------------------
 * id      变量名，默认 vo（也可以写成 name；两个都写时以 id 为准）。
 *         写成 id='field' 之后，下面所有 {$vo.xxx} 就都换成 {$field.xxx}
 * name    同上，是 id 的别名
 * formid  后台「留言 → 表单管理」里的表单ID（纯数字）。
 *         在留言模型的栏目页（list_clue.html）里可以**不写** ——
 *         会按当前栏目自动找「关联栏目 = 当前栏目」的那个表单；
 *         别的地方不写就取不到表单，标签会输出一句
 *         「请在后台留言-表单管理里添加表单并关联栏目」
 *
 * ------------------------------------------------------------------
 * 可用的变量
 * ------------------------------------------------------------------
 * 表单级：
 *   {$vo.form_id}     表单ID
 *   {$vo.form_name}   表单名称
 *   {$vo.intro}       表单说明
 *   {$vo.action}      表单提交地址（放 <form action="">）
 *   {$vo.submit_url}  同上（自己写 ajax 提交时用）
 *   {$vo.full_url}    带网站域名的完整表单提交地址
 *   {$vo.hidden|raw}  隐藏域（form_id），放在 </form> 前。必须带 |raw，否则会被模板引擎转义
 *   {$vo.script|raw}  内置的 AJAX 提交 JS（可选，模板里输出一次即可）。同样必须带 |raw
 *   {$vo.field_count} 字段数量
 *
 * 字段级（N = 字段ID）：
 *   {$vo.attr_N}      表单里的 name（= field_N，提交时后台认这个）
 *   {$vo.itemname_N}  字段标题（比如「家长姓名」）
 *   {$vo.type_N}      控件类型编号（0单行 1下拉 2多行 3单选 4多选 6手机 7邮箱 10时间）
 *   {$vo.typename_N}  同上，中文名
 *   {$vo.required_N}  是否必填（1/0）
 *   {$vo.options_N}   可选值数组（下拉/单选/多选才有），配 {volist} 用
 *
 * ------------------------------------------------------------------
 * 提交的两种写法
 * ------------------------------------------------------------------
 * 1) 用内置 JS（推荐）：表单上写 onsubmit="return miuClueSubmit(this)"，
 *    模板里再输出一次 {$vo.script}。会走 ajax，提交完弹提示、重置表单，页面不刷新。
 * 2) 普通提交：<form method="POST" action="{$vo.action}">，不加 onsubmit。
 *    后台也会正常处理，只是提交完会跳到一个简单的结果页。
 */
trait ClueTag
{
    public function tagClue(array $tag, string $content): string
    {
        // ---------------- 变量名（id / name，默认 vo） ----------------
        $var = trim((string) ($tag['id'] ?? $tag['name'] ?? ''));
        if ($var === '') {
            $var = 'vo';
        }
        //这个标签不循环，所以只有「取值变量名」这一个名字，没有 key。
        //校验规则跟 TagSupport::checkLoopVars() 里那套保持一致
        $reserved = ['this', 'GLOBALS', '_GET', '_POST', '_REQUEST', '_SERVER', '_FILES', '_ENV', '_COOKIE', '_SESSION'];
        if (!preg_match('/^[a-zA-Z_][a-zA-Z0-9_]{0,31}$/', $var) || in_array($var, $reserved, true)) {
            return $this->tagError('miu:clue', "id 只能是以字母或下划线开头的变量名，现在写的是：{$var}", $content);
        }

        // ---------------- 属性白名单 ----------------
        $allowAttr = ['id', 'name', 'formid'];
        $err       = $this->checkAttrs($tag, $allowAttr, 'miu:clue', $content);
        if ($err !== '') {
            return $err;
        }

        // ---------------- formid ----------------
        $formid = trim((string) ($tag['formid'] ?? ''));
        if ($formid !== '' && !preg_match('/^\d+$/', $formid)) {
            return $this->tagError('miu:clue', "formid 只能填数字（后台「留言 → 表单管理」里的表单ID），现在写的是：{$formid}", $content);
        }

        // ---------------- 生成编译代码 ----------------
        //变量表取出来放好，模板里 {$vo.xxx} 直接用；取不到就输出一句提示
        $parseStr  = '<?php $' . $var . ' = miu_clue_vars(' . (int) $formid . '); ?>';
        $parseStr .= '<?php if (empty($' . $var . ')) : echo htmlspecialchars(miu_clue_tip(), ENT_QUOTES); ?>';
        //模板里字段名写错（{$vo.attr_99} 这种）不把整页搞成 500，给一句人话
        $parseStr .= '<?php else: try { ?>';
        $parseStr .= $content;
        $parseStr .= '<?php } catch (\Throwable $__miu_e) { '
            . '$__miu_hint = miu_tpl_error($__miu_e->getMessage(), ' . var_export($var, true) . ', \'index\', \'miu:clue\'); '
            . 'if ($__miu_hint === \'\') { throw $__miu_e; } '
            . 'echo htmlspecialchars($__miu_hint, ENT_QUOTES); } endif; ?>';

        return $parseStr;
    }
}
