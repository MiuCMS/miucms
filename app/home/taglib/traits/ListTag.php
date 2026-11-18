<?php
/**
 * @copyright Copyright (c) 2026 米柚工作室
 * @license https://opensource.org/licenses/Apache-2.0
 * @link https://www.miucms.com
 */

declare (strict_types = 1);

namespace app\home\taglib\traits;

/**
 * {miu:list} 文档列表标签（读 cms_document 主表）
 *
 * 用法：
 *   {miu:list name='vo' order='add_time' sort='desc' limit='10'}
 *       <li><a href="{$vo.url}">{$vo.title}</a><span>{$vo.date}</span></li>
 *   {/miu:list}
 *
 *   {miu:list id='vo' key='idx' navid='2004,2005' flag='h,r' titlelen='12'}
 *       <li class="item-{$idx}"><a href="{$vo.url}">{$vo.title}</a></li>
 *   {/miu:list}
 *   {miu:list name='vo' navid='$doc.top_navid,$doc.other_navid' limit='5' flag='r'}...{/miu:list}
 *
 *   {miu:list name='vo' pagesize='9' order='add_time' sort='desc'}
 *       ... 列表页模板（list_xxx.html）里这么写：自动分页，
 *           后面配一个 {miu:pagelist /} 就把分页条输出来了
 *   {/miu:list}
 *
 * ------------------------------------------------------------------
 * 参数说明（每个都有默认值，填错不报错、直接在页面上输出原因）
 * ------------------------------------------------------------------
 * id           循环变量名，默认 vo（也可以写成 name；两个都写时以 id 为准）
 * key          索引变量名，默认 index；循环到第几项就存在这个变量里，从 0 开始
 * navid        栏目id或模板变量，多个用英文逗号隔开（例如 2004,$doc.top_navid,$doc.other_navid）。默认不填。
 *              不填时：有当前栏目（栏目页/详情页）就取当前栏目的文档；
 *                      没有当前栏目（首页）就取所有栏目的文档。
 *              ⚠ 指定栏目时会连同它的子孙栏目一起取 —— 文档一般发在叶子栏目上，
 *                不带子栏目的话一级栏目一篇都取不到（后台文档列表也是这个逻辑）。
 *              显式传入时优先按指定栏目取数，不受当前栏目影响；搜索页仍使用搜索条件。
 * mid          模型id（后台「内容模型」里的ID，纯数字，就是 cms_channel 表的 id）。
 *              不存在的 id 会给出提示，不会让页面 500。
 *              ⚠ navid 和 mid 只能选一个，同时写会提示；
 *              显式传入时优先按该模型取数，不受当前栏目影响。
 * nid          内容模型标识（cms_channel.nid），例如 article、product；不依赖模型数字ID。
 *              nid、navid、mid 只能选一个；显式传入时优先按该模型取数，不受当前栏目影响。
 * order        排序字段，默认 sort；普通字段按白名单校验，也可填 rand 随机排序（此时 sort 参数忽略）
 *              普通字段名会拼进 SQL，因此必须通过白名单校验，避免注入。
 * sort         排序方式，asc / desc，默认 desc；按 sort 排序时，相同值再按 release_time 降序
 * limit        取数条数，默认 10。写法 '5'（每页 5 条）或 '0,5'（从第 0 条起、每页 5 条）。
 *              列表页填写时优先于 pagesize；limit='0' 表示空列表。
 *              非列表页仍按偏移量和条数取数。
 * pagesize     每页显示条数，未填时沿用分页上下文，缺失时回退 10。**只在有分页上下文且未填写 limit 时生效**：
 *              列表页会按它取「当前页」那一页的数据（自动算好分页偏移），
 *              同时把总条数、总页数记下来，模板里再写 {miu:pagelist /} 就能输出分页条。
 *              ⚠ 非列表页（首页、详情页）没有分页上下文，写它不生效，条数按 limit 取。
 * titlelen     文档标题截断长度，默认 0（不截断）。按字符数算（中文算 1 个），
 *              只截 {$vo.title}，完整标题在 {$vo.title_full} 里；
 *              截断不加省略号，要省略号自己在模板里加
 * flag         文章属性，多个用英文逗号隔开，**多个之间是「或」**
 *              （满足任意一个就列出来）。可选值：
 *                h = 头条 is_head    r = 推荐 is_recommend
 *                s = 特荐 is_special b = 加粗 is_b
 *                t = 置顶 is_top     j = 跳转 is_jump
 *                l = 有图 is_litpic  o = 原创 is_original
 *              例：flag='h,r' 取「头条或推荐」的文章
 * empty        列表为空时输出的内容，默认空
 * emptykey     列表为空时从当前模板语言包读取的键；设置后优先于 empty
 *
 * ------------------------------------------------------------------
 * 额外说明
 * ------------------------------------------------------------------
 * · 只取已发布的文档：没进回收站（is_del=0）且不是未审核（arcrank >= 0）
 * · 本标签会带上模型的**自定义字段**（附加表 cms_article / cms_product 等里、
 *   cms_channel_field 登记为自定义字段的那些），模板里直接 {$vo.字段标识} 就能用；
 *   但不带正文 content / md_content（列表用不上，省性能），要正文用详情页的 {$doc.content}
 * · 在「文章列表页」上会自动分页：填写 limit 时按 limit 取每页条数，否则按 pagesize 取当前页的数据，
 *   并把总条数/总页数记给 {miu:pagelist} 标签用（模板里写 {miu:pagelist /} 即可）。
 *   栏目页模板里也可以不用本标签，直接用控制器给的 {$list} / {$page_html}。
 *   注意：本标签在列表页分页后，{miu:pagelist} 用的是本标签的每页条数，
 *   而控制器给的 {$page_html} 仍按后台「每页条数」算，两个混用时以 {miu:pagelist} 为准。
 * · 在「搜索页」上，本标签始终出搜索条件对应的结果（关键词 + 栏目 + 模型），所以搜索页模板直接写
 *     {miu:list name='vo' pagesize='10'}...{/miu:list}  +  {miu:pagelist /}
 *   就能出结果和分页（也可以不用本标签，直接用控制器给的 {$list}）。
 *   ⚠ 搜索页上「所有」{miu:list} 都会变成搜索结果；「最新文章」侧栏需由后端另行提供数据，
 *     控制器给的 {$list} 仍然是当前搜索结果，不能作为独立侧栏的数据源。
 * · 每篇文档除了表里的字段，还额外补了：
 *   {$vo.url}        详情页地址（跟着后台 URL配置 走）
 *   {$vo.full_url}   带网站域名的完整详情页地址（网站配置的 site_domain）
 *   {$vo.tag_list}   当前这篇文档关联的标签数组，每项含 id/title/url/full_url/click；可用 volist 循环
 *     {volist name="vo.tag_list" id="tag"}<a href="{$tag.url}">{$tag.title}</a>{/volist}
 *   {$vo.title_full} 没截断过的完整标题
 *   {$vo.date}       add_time 格式化日期（Y-m-d），不是 release_time
 *   {$vo.add_time_text} add_time 格式化时间（Y-m-d H:i:s）
 *   {$vo.nav_name} {$vo.nav_url} 所属栏目名称 / 栏目地址
 *   {$vo.nid}        所属内容模型的标识（cms_channel.nid）
 *   {$vo.title_style} 标题样式（后台设了标题颜色/加粗时才有值）
 * · 后台改了栏目标题、文档、URL配置后，记得去后台点一下「清缓存」，
 *   标签库的改动也要清缓存才会重新编译模板
 */
trait ListTag
{
    public function tagList(array $tag, string $content): string
    {
        // ---------------- 循环变量名 / 索引变量名 ----------------
        [$id, $key] = $this->parseLoopVars($tag);

        $err = $this->checkLoopVars($id, $key, 'miu:list', $content);
        if ($err !== '') {
            return $err;
        }

        // ---------------- 属性白名单 ----------------
        $allowAttr = ['id', 'name', 'key', 'navid', 'mid', 'nid', 'order', 'sort', 'limit', 'pagesize', 'titlelen', 'flag', 'empty', 'emptykey'];
        $err       = $this->checkAttrs($tag, $allowAttr, 'miu:list', $content);
        if ($err !== '') {
            return $err;
        }

        // ---------------- navid / mid ----------------
        $navid = trim((string) ($tag['navid'] ?? ''));
        $navidVarExpressions = [];
        $staticNavids = [];
        foreach ($navid === '' ? [] : explode(',', $navid) as $navidPart) {
            $navidPart = trim($navidPart);
            if (preg_match('/^\$[A-Za-z_][A-Za-z0-9_]*(?:\.[A-Za-z_][A-Za-z0-9_]*)*$/', $navidPart)) {
                $navidVarExpressions[] = $this->autoBuildVar($navidPart);
            } elseif (preg_match('/^\d+$/', $navidPart)) {
                $staticNavids[] = $navidPart;
            } else {
                return $this->listError('navid 只能填栏目id或模板变量，多个值用英文逗号隔开（例如 2004,$doc.top_navid,$doc.other_navid），现在写的是：' . $navid, $content);
            }
        }

        $mid = trim((string) ($tag['mid'] ?? ''));
        if ($mid !== '' && !preg_match('/^\d+$/', $mid)) {
            return $this->listError("mid 只能填纯数字（后台「内容模型」里的ID），现在写的是：{$mid}", $content);
        }
        if ($mid !== '' && (int) $mid > 0 && !get_channel((int) $mid)) {
            return $this->listError('mid 填的模型id ' . (int) $mid . ' 不存在，去后台「内容模型」看一下模型的ID', $content);
        }
        if ($navid !== '' && $mid !== '') {
            return $this->listError('navid（按栏目取）和 mid（按模型取）只能选一个，两个都写就不知道按哪个取了', $content);
        }

        $nid = trim((string) ($tag['nid'] ?? ''));
        if ($nid !== '' && !preg_match('/^[A-Za-z][A-Za-z0-9_]{0,63}$/', $nid)) {
            return $this->listError('nid 只能填写内容模型标识，例如 article、product、video', $content);
        }
        if ($nid !== '' && ($navid !== '' || $mid !== '')) {
            return $this->listError('nid（按模型标识取）、navid（按栏目取）和 mid（按模型ID取）只能填写一个', $content);
        }

        // ---------------- order ----------------
        $order = trim((string) ($tag['order'] ?? ''));
        if ($order === '') {
            $order = 'sort';
        }
        $orderFields = miu_doc_order_fields();
        if (strtolower($order) !== 'rand' && !in_array(strtolower($order), $orderFields, true)) {
            return $this->listError("order 填的字段 {$order} 不在文档表里，也不是 rand；可用字段有：" . implode('、', array_slice($orderFields, 0, 15)) . ' 等', $content);
        }
        $order = strtolower($order);

        // ---------------- sort ----------------
        $sort = strtolower(trim((string) ($tag['sort'] ?? '')));
        if ($sort === '') {
            $sort = 'desc';
        }
        if (strtolower($order) !== 'rand' && !in_array($sort, ['asc', 'desc'], true)) {
            return $this->listError("sort 只能是 asc 或 desc，现在写的是：{$sort}", $content);
        }

        // ---------------- limit ----------------
        //不填就取 10 条，所以「默认不限」是不成立的
        //（文章列表页上这个参数会被忽略，见 miu_list()）
        $limit = trim((string) ($tag['limit'] ?? ''));
        if ($limit !== '' && !preg_match('/^\d+(,\d+)?$/', $limit)) {
            return $this->listError("limit 只能是 5 或 0,5 这样的数字，现在写的是：{$limit}", $content);
        }

        // ---------------- pagesize ----------------
        //每页显示条数只在有分页上下文时生效；未填沿用上下文条数，缺失时回退 10。
        $pagesize = trim((string) ($tag['pagesize'] ?? ''));
        if ($pagesize !== '' && !preg_match('/^\d+$/', $pagesize)) {
            return $this->listError("pagesize 只能填数字（每页显示条数），现在写的是：{$pagesize}", $content);
        }

        // ---------------- titlelen ----------------
        $titlelen = trim((string) ($tag['titlelen'] ?? ''));
        if ($titlelen === '') {
            $titlelen = '0';
        }
        if (!preg_match('/^\d+$/', $titlelen)) {
            return $this->listError("titlelen 只能填数字（按字符数算，中文算 1 个），现在写的是：{$titlelen}", $content);
        }

        // ---------------- flag ----------------
        $flag = trim((string) ($tag['flag'] ?? ''));
        if ($flag !== '') {
            if (!preg_match('/^[a-zA-Z]+(,[a-zA-Z]+)*$/', $flag)) {
                return $this->listError("flag 只能填属性字母，多个用英文逗号隔开（例如 h,r），现在写的是：{$flag}", $content);
            }
            $map   = miu_doc_flag_map();
            $bad   = [];
            $seen  = [];
            foreach (explode(',', strtolower($flag)) as $letter) {
                $letter = trim($letter);
                if ($letter === '') {
                    continue;
                }
                if (!isset($map[$letter])) {
                    $bad[] = $letter;
                } else {
                    $seen[$letter] = true;
                }
            }
            if ($bad) {
                return $this->listError('flag 里有不认识的字母：' . implode('、', $bad)
                    . '。可选的有：h=头条 r=推荐 s=特荐 b=加粗 t=置顶 j=跳转 l=有图 o=原创', $content);
            }
            //去重，避免 flag='h,h' 生成重复条件
            $flag = implode(',', array_keys($seen));
        }

        // ---------------- empty ----------------
        $empty = (string) ($tag['empty'] ?? '');
        $emptyKey = trim((string) ($tag['emptykey'] ?? ''));
        if ($emptyKey !== '' && !preg_match('/^[A-Za-z0-9_.-]{1,128}$/', $emptyKey)) {
            return $this->listError('emptykey 只能由字母、数字、点、下划线和短横线组成', $content);
        }
        $emptyCode = var_export($empty, true);
        if ($emptyKey !== '') {
            $emptyCode = 'htmlspecialchars(\\app\\home\\service\\TemplateI18n::translate('
                . var_export($emptyKey, true) . ', ' . var_export($empty, true)
                . '), ENT_QUOTES | ENT_SUBSTITUTE, \'UTF-8\')';
        }

        // ---------------- 生成编译代码 ----------------
        //成对标签：$content 是 ThinkPHP 的占位符，标签内部内容会留在它所在的位置
        $list    = '$__miu_list_' . (++$this->navSeq);
        $counter = '$__miu_list_index_' . $this->navSeq;

        $opt = [
            'navid'    => $navidVarExpressions === [] ? $navid : '',
            'mid'      => (int) $mid,
            'nid'      => $nid,
            'order'    => $order,
            'sort'     => $sort,
            'limit'    => $limit,
            'pagesize' => (int) $pagesize,
            'titlelen' => (int) $titlelen,
            'flag'     => $flag,
        ];

        if ($navidVarExpressions !== []) {
            $navidListCode = '$__miu_list_navids_' . $this->navSeq;
            $navidInvalidCode = '$__miu_list_navid_invalid_' . $this->navSeq;
            $optCode = '$__miu_list_opt_' . $this->navSeq;
            $errorMessage = var_export('miu:list 标签参数错误：navid 变量必须是栏目ID或逗号分隔的栏目ID', true);
            $parseStr = '<?php ' . $navidListCode . ' = ' . var_export($staticNavids, true) . '; '
                . $navidInvalidCode . ' = false; ';
            foreach ($navidVarExpressions as $index => $navidVarExpression) {
                $navidRawCode = '$__miu_list_navid_raw_' . $this->navSeq . '_' . $index;
                $navidCode = '$__miu_list_navid_' . $this->navSeq . '_' . $index;
                $parseStr .= $navidRawCode . ' = ' . $navidVarExpression . '; '
                    . $navidCode . ' = is_scalar(' . $navidRawCode . ') ? trim((string) ' . $navidRawCode . ') : ""; '
                    . 'if (preg_match(' . var_export('/^\d+(,\d+)*$/D', true) . ', ' . $navidCode . ')) { '
                    . $navidListCode . ' = array_merge(' . $navidListCode . ', explode(",", ' . $navidCode . ')); '
                    . '} else { ' . $navidInvalidCode . ' = true; } ';
            }
            $parseStr .= 'if (!' . $navidInvalidCode . ' && ' . $navidListCode . ') { '
                . $optCode . ' = ' . var_export($opt, true) . '; '
                . $optCode . '[\'navid\'] = implode(\',\', array_unique(array_map(\'intval\', ' . $navidListCode . '))); '
                . $list . ' = miu_list(' . $optCode . '); '
                . '} else { echo htmlspecialchars(' . $errorMessage . ', ENT_QUOTES | ENT_SUBSTITUTE, \'UTF-8\'); '
                . $list . ' = []; } ?>';
        } else {
            $parseStr = '<?php ' . $list . ' = miu_list(' . var_export($opt, true) . '); ?>';
        }
        $parseStr .= '<?php if (count(' . $list . ') == 0) : echo ' . $emptyCode . ' ; ?>';
        $parseStr .= '<?php else: ' . $counter . ' = -1; try { foreach (' . $list . ' as $' . $id . '): $' . $key . ' = ++' . $counter . '; ?>';
        $parseStr .= $content;
        $parseStr .= '<?php endforeach; ' . $this->loopCatch($id, $key, 'miu:list') . ' endif; ?>';

        return $parseStr;
    }

    /**
     * list 标签参数填错时的提示
     */
    private function listError(string $reason, string $content): string
    {
        return $this->tagError('miu:list', $reason, $content);
    }
}
