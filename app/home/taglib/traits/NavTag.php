<?php
/**
 * @copyright Copyright (c) 2026 米柚工作室
 * @license https://opensource.org/licenses/Apache-2.0
 * @link https://www.miucms.com
 */

declare (strict_types = 1);

namespace app\home\taglib\traits;

/**
 * {miu:nav} 栏目列表标签
 *
 * 用法：
 *   {miu:nav}...{$vo.title}...{/miu:nav}
 *   {miu:nav id='vo' limit='0,5' order='sort' sort='asc' currentstyle='active' empty='暂无栏目'}
 *       <li class="{$vo.currentstyle}"><a href="{$vo.url}">{$vo.title}</a>
 *           {miu:nav id='sub' pid='$vo.id'}{$sub.title}{/miu:nav}
 *       </li>
 *   {/miu:nav}
 *   {miu:nav id='vo' key='idx'}<li class="item-{$idx}">{$vo.title}</li>{/miu:nav}
 *   {miu:nav id='vo' titlelen='8' notnavid='2004,2005'}...{/miu:nav}
 *   {miu:nav id='vo' navid='2004,2005'}...{/miu:nav}
 *   {miu:nav id='vo' navid='$doc.top_navid'}...{/miu:nav}
 *   {miu:nav id='vo' navid='$nav.top_navid,$doc.other_navid'}...{/miu:nav}
 *   {miu:nav id='vo' nid='article'}...{/miu:nav}
 *   {miu:nav id='sub' pid='$vo.id' includehidden='1'}...{/miu:nav}
 *
 * 每个属性都有默认值，填错不报错、直接输出原因：
 *   id           循环变量名，默认 vo（也可以写成 name,id和name同时存在id生效）
 *   key          索引变量名，默认 index；循环到第几项就存在这个变量里，从 0 开始
 *   pid          父栏目id，默认 0（一级栏目）；支持 $vo.id 这样嵌套调用子级栏目
 *   limit        取几条，默认不限；写法 5 或 0,5
 *   order        排序字段，默认 sort；支持 sort/id/add_time/update_time
 *   sort         排序方向，默认 asc；支持 asc/desc
 *   currentstyle 当前栏目的类名，默认 active（本栏目或其上级是当前栏目时才有值）
 *   empty        列表为空时输出的内容，默认空
 *   emptykey     列表为空时从当前模板语言包读取的键；设置后优先于 empty
 *   titlelen     限制标题长度，默认不限制。按字符数算（中文算 1 个），只截 {$vo.title}，
 *                {$vo.title_full} 还是完整标题；截断不加省略号，要省略号自己在模板里加
 *   notnavid     排除指定的栏目id，多个用英文逗号隔开，例如 2004,2005；默认不排除
 *   navid        只显示指定的栏目id，多个用英文逗号隔开；支持多个模板变量，例如 $nav.top_navid,$doc.other_navid；未传 order 和 sort 时按填写顺序输出
 *                ⚠ 写了 navid 就只看这几个栏目，不管它在第几层（子级栏目也能直接写，
 *                  这时候 pid 不生效）；想「只取某个栏目下的子栏目」用 pid 就行
 *                ⚠ 写了 navid 时，隐藏栏目（后台「隐藏栏目」选了「是」）也能取到；
 *                  不写 navid 时隐藏栏目照旧不显示
 *                ⚠ 和 notnavid 是反过来的，两个不能同时用
 *   includehidden 是否把后台设为「隐藏」的栏目也纳入结果，默认 0；填 1 表示包含，填 0 表示不包含
 *   nid          只显示指定内容模型的栏目，例如 article、product；不能和 navid、notnavid 同时用
 *                ⚠ 只影响按 pid 取栏目时的可见性；如果写了 navid，仍按 navid 指定栏目并忽略 pid
 *
 * 栏目里可用的字段：见 miu_nav_items()，常用的是
 *   {$vo.id} {$vo.title} {$vo.title_en} {$vo.url} {$vo.litpic} {$vo.target} {$vo.nid}
 *   {$vo.full_url}（内部栏目完整链接，域名来自 site_domain；外链原样返回）
 *   {$vo.currentstyle} {$vo.current} {$vo.active} {$vo.level} {$vo.has_children}
 *   {$vo.children}（子栏目数组，可以 {volist name="vo.children" id="sub"} 遍历）
 */
trait NavTag
{
    public function tagNav(array $tag, string $content): string
    {
        // ---------------- 属性校验（全部有默认值，填错就输出原因） ----------------

        //循环变量名 id（兼容写成 name）和索引变量名 key，默认 vo / index
        [$id, $key] = $this->parseLoopVars($tag);

        //变量名写错就直接提示（必须是合法变量名，不能是超级全局变量，两个也不能同名）
        $err = $this->checkLoopVars($id, $key, 'miu:nav', $content);
        if ($err !== '') {
            return $err;
        }

        //不认识的属性直接提示，避免把 limt 这种拼错当成没写
        $allowAttr = ['id', 'name', 'key', 'pid', 'limit', 'order', 'sort', 'currentstyle', 'empty', 'emptykey', 'titlelen', 'notnavid', 'navid', 'nid', 'includehidden'];
        $err       = $this->checkAttrs($tag, $allowAttr, 'miu:nav', $content);
        if ($err !== '') {
            return $err;
        }

        //pid：数字，或者 $vo.id 这样的变量
        $pid = trim((string) ($tag['pid'] ?? ''));
        if ($pid === '') {
            $pid = '0';
        }
        if (preg_match('/^\d+$/', $pid)) {
            $pidCode = $pid;
        } elseif (strpos($pid, '$') === 0) {
            $pidCode = $this->autoBuildVar($pid);
        } else {
            return $this->navError("pid 只能是数字（0 表示一级栏目），或者 \$vo.id 这样的变量，现在写的是：{$pid}", $content);
        }

        //limit：5 或 0,5
        $limit = trim((string) ($tag['limit'] ?? ''));
        if ($limit !== '' && !preg_match('/^\d+(,\d+)?$/', $limit)) {
            return $this->navError("limit 只能是 5 或 0,5 这样的数字，现在写的是：{$limit}", $content);
        }

        //order：白名单，避免把奇怪的东西当字段名
        $orderList = ['sort', 'id', 'add_time', 'update_time'];
        $order     = strtolower(trim((string) ($tag['order'] ?? '')));
        if ($order === '') {
            $order = 'sort';
        }
        if (!in_array($order, $orderList, true)) {
            return $this->navError('order 只支持 ' . implode('、', $orderList) . "，现在写的是：{$order}", $content);
        }

        //sort：asc / desc
        $sort = strtolower(trim((string) ($tag['sort'] ?? '')));
        if ($sort === '') {
            $sort = 'asc';
        }
        if (!in_array($sort, ['asc', 'desc'], true)) {
            return $this->navError("sort 只能是 asc 或 desc，现在写的是：{$sort}", $content);
        }

        //currentstyle / empty
        $currentstyle = (string) ($tag['currentstyle'] ?? '');
        if ($currentstyle === '') {
            $currentstyle = 'active';
        }
        $empty = (string) ($tag['empty'] ?? '');
        $emptyKey = trim((string) ($tag['emptykey'] ?? ''));
        if ($emptyKey !== '' && !preg_match('/^[A-Za-z0-9_.-]{1,128}$/', $emptyKey)) {
            return $this->navError('emptykey 只能由字母、数字、点、下划线和短横线组成', $content);
        }
        $emptyCode = var_export($empty, true);
        if ($emptyKey !== '') {
            $emptyCode = 'htmlspecialchars(\\app\\home\\service\\TemplateI18n::translate('
                . var_export($emptyKey, true) . ', ' . var_export($empty, true)
                . '), ENT_QUOTES | ENT_SUBSTITUTE, \'UTF-8\')';
        }

        //titlelen：限制标题长度，默认 0（不限制）
        $titlelen = trim((string) ($tag['titlelen'] ?? ''));
        if ($titlelen === '') {
            $titlelen = '0';
        }
        if (!preg_match('/^\d+$/', $titlelen)) {
            return $this->navError("titlelen 只能填数字（按字符数算，中文算 1 个），现在写的是：{$titlelen}", $content);
        }
        $titlelen = (int) $titlelen;

        //notnavid：排除指定栏目id，多个用英文逗号隔开
        $notnavid = trim((string) ($tag['notnavid'] ?? ''));
        if ($notnavid !== '' && !preg_match('/^\d+(,\d+)*$/', $notnavid)) {
            return $this->navError("notnavid 只能填栏目id，多个用英文逗号隔开（例如 2004,2005），现在写的是：{$notnavid}", $content);
        }

        //navid：只显示指定栏目id，支持固定ID和模板变量混用
        $navid = trim((string) ($tag['navid'] ?? ''));
        $preserveNavidOrder = $navid !== ''
            && trim((string) ($tag['order'] ?? '')) === ''
            && trim((string) ($tag['sort'] ?? '')) === '';
        $navidParts = [];
        $hasNavidVar = false;
        foreach ($navid === '' ? [] : explode(',', $navid) as $navidPart) {
            $navidPart = trim($navidPart);
            if (preg_match('/^\$[A-Za-z_][A-Za-z0-9_]*(?:\.[A-Za-z_][A-Za-z0-9_]*)*$/', $navidPart)) {
                $navidParts[] = ['type' => 'variable', 'value' => $this->autoBuildVar($navidPart)];
                $hasNavidVar = true;
            } elseif (preg_match('/^\d+$/', $navidPart)) {
                $navidParts[] = ['type' => 'static', 'value' => $navidPart];
            } else {
                return $this->navError('navid 只能填栏目id或模板变量，多个值用英文逗号隔开（例如 2004,$nav.top_navid,$doc.other_navid），现在写的是：' . $navid, $content);
            }
        }
        //一个是要这些、一个是不想要这些，同时写没有意义，直接提示
        if ($navid !== '' && $notnavid !== '') {
            return $this->navError('navid（只显示指定栏目）和 notnavid（排除指定栏目）是反过来的两个参数，不能同时用，留一个就行', $content);
        }

        $modelNid = trim((string) ($tag['nid'] ?? ''));
        if ($modelNid !== '' && !preg_match('/^[A-Za-z][A-Za-z0-9_]{0,63}$/', $modelNid)) {
            return $this->navError('nid 只能填写内容模型标识，例如 article、product、video', $content);
        }
        if ($modelNid !== '' && ($navid !== '' || $notnavid !== '')) {
            return $this->navError('nid（按模型筛选）不能和 navid、notnavid 同时使用', $content);
        }

        //includehidden：默认不包含隐藏栏目；只接受 0 / 1，避免拼错后静默改变栏目范围
        $includeHidden = trim((string) ($tag['includehidden'] ?? '0'));
        if (!in_array($includeHidden, ['0', '1'], true)) {
            return $this->navError("includehidden 只能填 0 或 1（1 表示包含隐藏栏目），现在写的是：{$includeHidden}", $content);
        }

        // ---------------- 生成编译代码 ----------------
        //成对标签：$content 是 ThinkPHP 的占位符，标签内部内容会留在它所在的位置
        //每个标签用各自的变量名，嵌套时内外层不会互相覆盖
        $list    = '$__miu_nav_list_' . (++$this->navSeq);
        //索引计数器单独放一个变量，这样用户自己改 key 名字也不会互相干扰
        $counter = '$__miu_nav_index_' . $this->navSeq;

        $navidCode = !$hasNavidVar ? var_export($navid, true) : '$__miu_nav_navid_' . $this->navSeq;
        $parseStr = '';
        if ($hasNavidVar) {
            $navidListCode = '$__miu_nav_navids_' . $this->navSeq;
            $navidInvalidCode = '$__miu_nav_navid_invalid_' . $this->navSeq;
            $parseStr = '<?php ' . $navidListCode . ' = []; '
                . $navidInvalidCode . ' = false; ';
            foreach ($navidParts as $index => $navidPart) {
                if ($navidPart['type'] === 'static') {
                    $parseStr .= $navidListCode . '[] = ' . var_export($navidPart['value'], true) . '; ';
                    continue;
                }

                $navidRawCode = '$__miu_nav_navid_raw_' . $this->navSeq . '_' . $index;
                $navidValueCode = '$__miu_nav_navid_value_' . $this->navSeq . '_' . $index;
                $parseStr .= $navidRawCode . ' = ' . $navidPart['value'] . '; '
                    . $navidValueCode . ' = is_scalar(' . $navidRawCode . ') ? trim((string) ' . $navidRawCode . ') : ""; '
                    . 'if (preg_match(' . var_export('/^\d+(,\d+)*$/D', true) . ', ' . $navidValueCode . ')) { '
                    . $navidListCode . ' = array_merge(' . $navidListCode . ', explode(",", ' . $navidValueCode . ')); '
                    . '} else { ' . $navidInvalidCode . ' = true; } ';
            }
            $parseStr .= 'if (!' . $navidInvalidCode . ' && ' . $navidListCode . ') { '
                . $navidCode . ' = implode(\',\', array_unique(array_map(\'intval\', ' . $navidListCode . '))); ?>';
        }

        $navListCall = $list . ' = miu_nav_list(' . $pidCode . ', ' . var_export($order, true) . ', ' . var_export($sort, true) . ', ' . var_export($limit, true) . ', ' . $titlelen . ', ' . var_export($notnavid, true) . ', ' . $navidCode . ', ' . ($includeHidden === '1' ? 'true' : 'false') . ', ' . ($preserveNavidOrder ? 'true' : 'false') . ', ' . var_export($modelNid, true) . ');';
        if ($hasNavidVar) {
            $errorMessage = var_export('miu:nav 标签参数错误：navid 变量必须是栏目ID或逗号分隔的栏目ID', true);
            $parseStr .= '<?php ' . $navListCall . ' } else { echo htmlspecialchars(' . $errorMessage . ', ENT_QUOTES | ENT_SUBSTITUTE, \'UTF-8\'); '
                . $list . ' = []; } ?>';
        } else {
            $parseStr .= '<?php ' . $navListCall . ' ?>';
        }
        $parseStr .= '<?php if (count(' . $list . ') == 0) : echo ' . $emptyCode . ' ; ?>';
        //$counter 从 -1 开始自增，循环里的 $key 就是 0、1、2……（从 0 开始的索引）
        $parseStr .= '<?php else: ' . $counter . ' = -1; try { foreach (' . $list . ' as $' . $id . '): $' . $key . ' = ++' . $counter . '; ?>';
        //当前栏目/其上级加高亮类名
        $parseStr .= '<?php $' . $id . '[\'currentstyle\'] = !empty($' . $id . '[\'active\']) ? ' . var_export($currentstyle, true) . " : ''; ?>";
        $parseStr .= $content;
        $parseStr .= '<?php endforeach; ' . $this->loopCatch($id, $key, 'miu:nav') . ' endif; ?>';

        return $parseStr;
    }

    /**
     * nav 标签参数填错时的提示
     */
    private function navError(string $reason, string $content): string
    {
        return $this->tagError('miu:nav', $reason, $content);
    }
}
