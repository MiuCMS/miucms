<?php
/**
 * @copyright Copyright (c) 2026 米柚工作室
 * @license https://opensource.org/licenses/Apache-2.0
 * @link https://www.miucms.com
 */

// 这是home公共文件

/**
 * 将 Markdown 转为可安全输出的 HTML，供前台模板标签调用。
 * 原始 HTML 会转义，危险链接会禁用，避免 Markdown 内容直接注入脚本。
 */
function miu_markdown_to_html(string $markdown): string
{
    if ($markdown === '') {
        return '';
    }

    static $converter = null;
    if ($converter === null) {
        $converter = new \League\CommonMark\GithubFlavoredMarkdownConverter([
            'html_input'          => 'escape',
            'allow_unsafe_links'  => false,
            'max_nesting_level'   => 20,
            'max_delimiters_per_line' => 1000,
        ]);
    }

    try {
        return $converter->convert($markdown)->getContent();
    } catch (\Throwable $e) {
        //异常时退回纯文本转义，保证内容可读且不把 Markdown 当 HTML 执行。
        return '<p>' . nl2br(htmlspecialchars($markdown, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8')) . '</p>';
    }
}

//读取导航列表，用于前台
//
//栏目树取数统一在全局 common.php 的 miu_nav_tree()（后台算前台文档地址时也要用同一份），
//这里保留 get_navs() 这个名字，是因为前台到处都在调它。
function get_navs()
{
    return miu_nav_tree();
}

/**
 * 读取导航列表（含隐藏栏目）—— 只给「明确指定了栏目」的场景用
 *
 * 和 get_navs() 的区别：不按 is_show 过滤，隐藏栏目（is_show=1）也在里面。
 * {miu:nav navid='xxx'} 明确点名了要哪个栏目，就该能拿到隐藏栏目。
 * 仍然排除回收站（is_del=1）和禁用（status=0）的栏目。
 *
 * 取数统一在全局 common.php 的 miu_nav_tree_all()（算栏目地址时也要用同一份），
 * 这里保留 get_navs_all() 这个名字，是因为 miu_nav_items() 在调它。
 *
 * @return array
 */
function get_navs_all()
{
    return miu_nav_tree_all();
}

/* ==================== 前台页面标题（后台「标题规则」） ==================== */

/**
 * 按后台「标题规则」生成页面标题
 *
 * 规则（后台 SEO → URL配置）：
 *   栏目标题  1=栏目名称  2=栏目名称_网站名称  3=栏目名称_第N页  4=栏目名称_第N页_网站名称
 *   标签标题  1=标签名称  2=标签名称_网站名称  3=标签名称_第N页  4=标签名称_第N页_网站名称
 *   文档标题  1=内容标题  2=内容标题_网站名称  3=内容标题_栏目名称_网站名称
 * 拼接用「标题间连接符」（默认 -）；空的片段会自动跳过，不会拼出「标题--网站名」这种。
 *
 * 栏目/文档自己在后台手填的「SEO标题」优先：填了就整个用它，不再按规则拼。
 * 首页标题不在这里管，仍然用网站配置里的「SEO标题」(site_title)。
 *
 * 模板里用 {$page_title} 取（渲染函数会 assign）。
 *
 * @param string $type 'nav'=栏目页/搜索页  'tag'=标签页  'doc'=文档详情页
 * @param array  $data 栏目数据或文档数据（文档要能取到 title、nav_name）
 * @param int    $page 当前页码（栏目页用，第1页不带「第N页」）
 * @return string
 */
function miu_page_title(string $type, array $data, int $page = 1): string
{
    $cfg  = url_config();
    $sep  = $cfg['title_sep'];
    $site = trim((string) get_system_config('site_name'));

    //跳过空片段再拼接
    $join = function (array $parts) use ($sep) {
        $parts = array_filter(array_map('trim', $parts), function ($v) {
            return $v !== '';
        });
        return implode($sep, $parts);
    };

    if ($type === 'doc') {
        $base    = trim((string) ($data['title'] ?? ''));
        $navName = trim((string) ($data['nav_name'] ?? ''));

        //后台发布/编辑文档时会把「SEO标题」自动填成内容标题（见 admin/model/document.php
        //的 handle_params()）。这种自动填的值不算「手填」，否则下面的标题规则永远用不上。
        //只有跟内容标题不一样，才当作是运营真手填的，整个用它。
        $custom = trim((string) ($data['seo_title'] ?? ''));
        if ($custom !== '' && $custom !== $base) {
            return $custom;
        }

        if ($cfg['doc_title'] === 2) {
            return $join([$base, $site]);
        }
        if ($cfg['doc_title'] === 3) {
            return $join([$base, $navName, $site]);
        }
        return $base;
    }

    if ($type === 'tag') {
        $base    = trim((string) ($data['title'] ?? ''));
        $pageStr = $page > 1 ? ('第' . $page . '页') : '';

        if ($cfg['tag_title'] === 2) {
            return $join([$base, $site]);
        }
        if ($cfg['tag_title'] === 3) {
            return $join([$base, $pageStr]);
        }
        if ($cfg['tag_title'] === 4) {
            return $join([$base, $pageStr, $site]);
        }
        return $base;
    }

    //栏目页（搜索页也走这套）：手填的 SEO 标题优先
    $custom = trim((string) ($data['seo_title'] ?? ''));
    if ($custom !== '') {
        return $custom;
    }

    $base    = trim((string) ($data['title'] ?? ''));
    $pageStr = $page > 1 ? ('第' . $page . '页') : '';

    if ($cfg['nav_title'] === 2) {
        return $join([$base, $site]);
    }
    if ($cfg['nav_title'] === 3) {
        return $join([$base, $pageStr]);
    }
    if ($cfg['nav_title'] === 4) {
        return $join([$base, $pageStr, $site]);
    }
    return $base;
}

/**
 * 搜索页地址
 *
 * 搜索页走短路由地址（/search），三种 URL 模式（动态/伪静态/静态）
 * 都能直接访问，所以这里不像 nav_url() 那样跟着后台「SEO → URL配置」变。
 *
 * 只保留真正有值的参数（keyword / navid / mid），空的不往地址里塞；
 * 第 1 页不带 page 参数。
 *
 * @param array $params keyword / navid / mid
 * @param int   $page   页码，1 表示第一页（地址里不带 page）
 * @return string
 */
function miu_search_url(array $params = [], int $page = 1): string
{
    $query = [];
    foreach (['keyword', 'navid', 'mid'] as $k) {
        $v = $params[$k] ?? '';
        //空串、0、'0' 都当作「没传」
        if ($v === '' || $v === 0 || $v === '0' || $v === null) {
            continue;
        }
        $query[$k] = $v;
    }

    //搜索页固定走短路由地址（不跟 URL 模式变），同时保留当前语言前缀。
    $url = url_entry() . '/search';
    if ($query) {
        $url .= '?' . http_build_query($query);
    }
    if ($page > 1) {
        $url .= ($query ? '&' : '?') . 'page=' . $page;
    }

    return $url;
}

/**
 * 标签页地址
 *
 * 走 home 应用的路由（app/home/route/route.php 里定义的 tag/:id），
 * 动态 / 伪静态仍使用 /tag/12；生成静态模式按后台路径设置生成
 * 「页面保存目录/tag/12.html」或「页面保存目录/tag/12/」。
 *
 * @param int $id   标签id（cms_keywords 表的 id）
 * @param int $page 页码，第 1 页不带 page 参数
 * @return string
 */
function miu_tag_url(int $id, int $page = 1): string
{
    $cfg = url_config();
    if ($cfg['mode'] === 2) {
        $url = url_static_prefix() . '/tag/' . $id;
        if ($cfg['html_tagname'] === 2) {
            $url .= '/';
            return $page > 1 ? $url . 'p' . $page . '.html' : $url;
        }

        if ($page > 1) {
            $url .= '_p' . $page;
        }
        return $url . '.' . $cfg['suffix'];
    }

    $url = url_entry() . '/tag/' . $id;
    if ($page > 1) {
        $url .= '?page=' . $page;
    }

    return $url;
}

/**
 * 前台留言表单页地址（/form/12）
 *
 * 走 home 应用的路由（app/home/route/route.php 里的 form/:id），
 * 是不跟后台「SEO → URL配置」变的美化地址，但受「去除index.php」开关影响。
 *
 * @param int $id 表单id（cms_form.id）
 * @return string
 */
function miu_form_url(int $id): string
{
    return url_entry() . '/form/' . $id;
}

/**
 * 前台留言表单的提交地址（动态地址，不跟伪静态/静态规则变）
 *
 * @return string
 */
function miu_form_submit_url(): string
{
    return url_entry() . '/form/submit';
}

/* ==================== 前台留言表单（{miu:clue} 标签用） ==================== */

/**
 * 读取前台可用的表单定义，供留言标签和表单页共用。
 * 表单配置只在后台变更时更新，按表单 ID 或绑定栏目 ID 持久缓存。
 */
function miu_cached_form(int $formId = 0, int $navId = 0): array
{
    $formId = max(0, $formId);
    $navId  = max(0, $navId);
    if ($formId > 0) {
        $cacheKey = 'homeForm_' . $formId;
    } elseif ($navId > 0) {
        $cacheKey = 'homeFormNav_' . $navId;
    } else {
        return [];
    }

    $cached = get_cache($cacheKey);
    if (is_array($cached)) {
        return $cached;
    }

    try {
        $query = \think\facade\Db::name('form')->where('status', 1);
        if ($formId > 0) {
            $query->where('id', $formId);
        } else {
            $query->where('nav_id', $navId)->order('id asc');
        }
        $row = $query->find();
        $row = is_array($row) ? $row : (is_object($row) ? $row->toArray() : []);
    } catch (\Throwable $e) {
        return [];
    }

    \think\facade\Cache::tag('homeForm')->set($cacheKey, $row, 86400);
    return $row;
}

/**
 * 读取前台表单字段定义。字段变更时与表单定义一起清理 homeForm 标签。
 */
function miu_cached_form_fields(int $formId): array
{
    $formId = max(0, $formId);
    if ($formId < 1) {
        return [];
    }

    $cacheKey = 'homeFormFields_' . $formId;
    $cached   = get_cache($cacheKey);
    if (is_array($cached)) {
        return $cached;
    }

    try {
        $rows = \think\facade\Db::name('form_field')
            ->where('form_id', $formId)
            ->where('is_del', 0)
            ->order('sort_order desc, id asc')
            ->select()
            ->toArray();
    } catch (\Throwable $e) {
        return [];
    }

    \think\facade\Cache::tag('homeForm')->set($cacheKey, $rows, 86400);
    return $rows;
}

/**
 * 取「要渲染的那个留言表单」
 *
 * 两种找法：
 *   1) 模板里写了 formid → 直接用那个表单
 *   2) 没写 formid（留言模型栏目页 list_clue.html 里就是这么用的）
 *      → 拿当前栏目去反查「关联栏目 = 当前栏目」的那个表单
 * 找不到就返回空数组，标签那边会输出一句提示。
 *
 * 按表单 ID 或当前栏目 ID 做持久缓存；静态生成切换栏目时也会各自读取正确的表单。
 *
 * @param int $formId 模板里写的 formid，0 = 按当前栏目找
 * @return array 表单那一行（cms_form）
 */
function miu_clue_form(int $formId = 0): array
{
    if ($formId > 0) {
        return miu_cached_form($formId);
    }

    //静态生成时单个请求可能连续切换栏目，缓存键必须包含当前栏目 ID。
    return miu_cached_form(0, (int) miu_current_nav_id());
}

/**
 * 标签取不到表单/字段时输出的话
 *
 * @return string
 */
function miu_clue_tip(): string
{
    return '请在后台留言-表单管理里添加表单并关联栏目';
}

/**
 * 留言表单的隐藏域（form_id）
 *
 * {miu:clue} 在循环字段之前先输出它，这样模板不用自己写
 * <input type="hidden" name="form_id" ...>，提交时后台才知道是哪个表单。
 *
 * @param int $formId
 * @return string
 */
function miu_clue_hidden(int $formId): string
{
    return '<input type="hidden" name="form_id" value="' . (int) $formId . '">';
}

/**
 * {miu:clue} 标签用的变量表
 *
 * 标签**不循环**：模板里写一次表单骨架，需要哪个字段就自己取哪个字段的变量。
 * 每个字段按「字段ID」生成对应变量：
 *
 *   {$vo.attr_N}      第 N 个字段在表单里的 name（= field_N，提交时后台认这个）
 *   {$vo.itemname_N}  第 N 个字段的标题（比如「家长姓名」）
 *   {$vo.type_N}      第 N 个字段的控件类型编号（0单行 1下拉 2多行 3单选 4多选 6手机 7邮箱 10时间）
 *   {$vo.typename_N}  同上，中文名
 *   {$vo.required_N}  第 N 个字段是否必填（1/0）
 *   {$vo.options_N}   第 N 个字段的可选值数组（下拉/单选/多选才有），配 {volist} 用
 *
 * 表单级的变量：
 *   {$vo.form_id} {$vo.form_name} {$vo.intro}
 *   {$vo.action}      表单提交地址（放 <form action="">）
 *   {$vo.submit_url}  同上（给 JS / AJAX 用）
 *   {$vo.hidden}      隐藏域（form_id），放在 </form> 前
 *   {$vo.script}      内置的 AJAX 提交 JS（可选，模板里输出一次即可）
 *   {$vo.field_count} 字段数量
 *
 * N 就是后台「留言 → 表单管理 → 字段管理」里那个字段的 ID。
 *
 * @param int $formId 表单id，0 = 按当前栏目找
 * @return array 取不到表单/字段时返回空数组（标签会输出提示文案）
 */
function miu_clue_vars(int $formId = 0): array
{
    $form = miu_clue_form($formId);
    if (!$form) {
        return [];
    }

    try {
        $rows = miu_cached_form_fields((int) $form['id']);
    } catch (\Throwable $e) {
        return [];
    }
    if (!$rows) {
        return [];
    }

    $types  = form_input_types();
    $submit = miu_form_submit_url();

    $vars = [
        'form_id'     => (int) $form['id'],
        'form_name'   => (string) $form['form_name'],
        'intro'       => (string) $form['intro'],
        'action'      => $submit,
        'submit_url'  => $submit,
        'full_url'    => miu_full_url($submit),
        'hidden'      => miu_clue_hidden((int) $form['id']),
        'script'      => miu_clue_script(),
        'field_count' => count($rows),
    ];

    foreach ($rows as $row) {
        $fid  = (int) $row['id'];
        $type = (int) $row['attr_input_type'];

        $vars['attr_' . $fid]     = 'field_' . $fid;
        $vars['itemname_' . $fid] = (string) $row['attr_name'];
        $vars['type_' . $fid]     = $type;
        $vars['typename_' . $fid] = $types[$type] ?? '单行文本';
        $vars['required_' . $fid] = (int) $row['required'] === 1 ? 1 : 0;
        $vars['options_' . $fid]  = form_field_options((string) $row['attr_values']);
    }

    return $vars;
}

/** 前台个人中心使用的用户资料缓存，不缓存密码和盐值。 */
function miu_cached_home_user(int $userId): array
{
    $userId = max(0, $userId);
    if ($userId < 1) {
        return [];
    }
    $cacheKey = 'homeUserProfile_' . $userId;
    $user = get_cache($cacheKey);
    if (!is_array($user)) {
        $user = \think\facade\Db::name('User')->where('id', $userId)->find();
        $user = is_array($user) ? $user : (is_object($user) ? $user->toArray() : []);
        unset($user['password'], $user['salt']);
        \think\facade\Cache::tag('homeUser')->set($cacheKey, $user, 86400);
    }
    return $user;
}

/** 前台个人中心显示的会员等级名称。 */
function miu_cached_home_user_level_title(int $levelId): string
{
    $levelId = max(0, $levelId);
    if ($levelId < 1) {
        return '';
    }
    $cacheKey = 'homeUserLevelTitle_' . $levelId;
    $title = get_cache($cacheKey);
    if (!is_string($title)) {
        $title = (string) \think\facade\Db::name('UserLevel')->where('id', $levelId)->value('title');
        \think\facade\Cache::tag('homeUserLevel')->set($cacheKey, $title, 86400);
    }
    return $title;
}

/**
 * {miu:clue} 内置的 AJAX 提交 JS
 *
 * 模板里在表单附近输出一次 {$vo.script}，然后表单上写
 *   onsubmit="return miuClueSubmit(this)"
 * 就行：函数会 POST 到 form 的 action，带上 X-Requested-With 头（后台按 AJAX 处理），
 * 成功后 alert 提示并重置表单。
 *
 * 提交地址是从 form 的 action 上读的，所以同一个页面有多个表单也只用这一个函数
 * （函数做了防重复定义，模板里输出多次也没事）。
 * 不想用内置 JS 的话可以不输出它，自己写 ajax 提交到 {$vo.submit_url} 即可。
 *
 * @return string
 */
function miu_clue_script(): string
{
    $js = <<<'JS'
(function(){
if(window.miuClueSubmit)return;
window.miuClueSubmit=function(form){
    if(!form)return false;
    if(form.getAttribute('data-miu-busy')==='1')return false;
    if(!form.checkValidity()){form.reportValidity();return false;}
    form.setAttribute('data-miu-busy','1');
    var btn=form.querySelector('[type=submit]'),old=btn?btn.innerHTML:'';
    if(btn){btn.disabled=true;btn.innerHTML='提交中...';}
    var xhr=new XMLHttpRequest();
    xhr.open('POST',form.getAttribute('action'),true);
    xhr.setRequestHeader('X-Requested-With','XMLHttpRequest');
    xhr.onreadystatechange=function(){
        if(xhr.readyState!==4)return;
        form.setAttribute('data-miu-busy','0');
        if(btn){btn.disabled=false;btn.innerHTML=old;}
        var res=null;
        try{res=JSON.parse(xhr.responseText);}catch(e){}
        if(!res){alert('提交失败，请稍后再试');return;}
        alert(res.msg);
        if(res.code===0)form.reset();
    };
    xhr.send(new FormData(form));
    return false;
};
})();
JS;

    return '<script>' . $js . '</script>';
}

/**
 * 按栏目id取栏目（含 title_en 等字段，来自 homeNav 缓存，不额外查库）
 *
 * 用「含隐藏栏目」的那份：隐藏只是不出现在导航/列表里，栏目本身还在，
 * 栏目自身页面及其下文档仍需正常获取栏目信息。
 *
 * @param int $id
 * @return array 找不到返回空数组
 */
function nav_by_id(int $id): array
{
    $items = miu_nav_items(true);
    return $items[$id] ?? [];
}

/**
 * 栏目在前台取不到的原因 + 对应的提示文案
 *
 * 栏目在前台取不到，只有两种可能：在回收站（is_del=1）或者被停用（status=0）。
 * 隐藏栏目（is_show=1）是能取到的 —— 隐藏只是不出现在导航/列表里，页面照常能访问。
 * 这两种栏目其实还在，所以提示不能一律说「栏目不存在」，不然运营会以为栏目丢了。
 *
 * @param int $navId
 * @return array ['nav' => 栏目行, 'title' => 标题后缀, 'why' => 原因短语]；
 *               栏目正常或压根不存在时返回 []
 */
function miu_nav_unavailable(int $navId): array
{
    $navId = (int) $navId;
    if ($navId < 1) {
        return [];
    }

    $cacheKey = 'homeNavUnavailable_' . $navId;
    $nav = get_cache($cacheKey);
    if (!is_array($nav)) {
        $nav = \think\facade\Db::name('Nav')->where('id', $navId)->find();
        $nav = is_array($nav) ? $nav : (is_object($nav) ? $nav->toArray() : []);
        \think\facade\Cache::tag('homeNav')->set($cacheKey, $nav, 86400);
    }
    if (!$nav) {
        return [];
    }

    if ((int) ($nav['is_del'] ?? 0) === 1) {
        $title = '已删除';
        $why   = '在回收站里';
    } elseif ((int) ($nav['status'] ?? 0) !== 1) {
        $title = '已停用';
        $why   = '被停用了';
    } else {
        return [];
    }

    return ['nav' => $nav, 'title' => $title, 'why' => $why];
}

/**
 * 按 URL 里的栏目路径取栏目
 *
 * 两种写法都能认：
 *   完整路径（当前规则生成的标准地址）  chanpin/shouji
 *   只有最后一段（兼容老地址 / 目录形式手写）  shouji
 * 段名由 nav_dir() 保证全局唯一，所以「最后一段」也不会认错栏目。
 *
 * @param string $dir 如 bokezixun / chanpin/shouji
 * @return array 找不到返回空数组
 */
function nav_by_dir(string $dir): array
{
    static $map = null;
    if ($map === null) {
        $map = [];
        foreach (nav_flat_map() as $id => $item) {
            $id = (int) $id;
            if ($id < 1) {
                continue;
            }

            $segs = [];
            foreach (nav_chain($id) as $chainItem) {
                $segs[] = nav_dir($chainItem);
            }

            //完整路径
            $map[implode('/', $segs)] = $item;

            //最后一段（不覆盖已有键，完整路径优先）
            $last = nav_dir($item);
            if (!isset($map[$last])) {
                $map[$last] = $item;
            }
        }
    }

    return $map[trim($dir, '/')] ?? [];
}

/**
 * 按「模型标识/栏目别名」取栏目（如 article/bokezixun）
 *
 * 「模型标识」模式下 nav_url() 生成的就是这种地址，url_parse() 靠它反查栏目。
 * 栏目别名后台本来就要求唯一，同一个模型标识下不会撞；万一撞了以先遍历到的为准。
 *
 * @param string $path 形如 article/bokezixun
 * @return array 找不到返回空数组
 */
function nav_by_nid_path(string $path): array
{
    static $map = null;
    if ($map === null) {
        $map = [];
        foreach (nav_flat_map() as $item) {
            $key = nav_nid_path($item);
            if (!isset($map[$key])) {
                $map[$key] = $item;
            }
        }
    }

    return $map[trim($path, '/')] ?? [];
}

/**
 * 取栏目的上级链（含自己，从一级栏目开始）
 *
 * @param int $id
 * @return array
 */
function nav_ancestors(int $id, bool $withHidden = false): array
{
    $items = miu_nav_items($withHidden);
    $chain = [];
    $cur   = $id;
    //栏目表理论上不会成环，但 pid 被人工改坏时用次数兜底，避免死循环
    for ($i = 0; $cur && isset($items[$cur]) && $i < 32; $i++) {
        array_unshift($chain, $items[$cur]);
        $cur = (int) $items[$cur]['pid'];
    }
    return $chain;
}

/**
 * 按 ID 读取未删除的文档原始行。翻译与模板字段加工在调用方执行。
 */
function miu_cached_document(int $docId): array
{
    $docId = max(0, $docId);
    if ($docId < 1) {
        return [];
    }

    $cacheKey = 'homeDocument_public_v2_' . $docId;
    $cached   = get_cache($cacheKey);
    if (is_array($cached)) {
        return $cached;
    }

    $doc = \think\facade\Db::name('document')
        ->where('id', $docId)
        ->where('is_del', 0)->where('status', 1)->where('arcrank', '>=', 0)
        ->find();
    $doc = is_array($doc) ? $doc : (is_object($doc) ? $doc->toArray() : []);
    \think\facade\Cache::tag('Document')->set($cacheKey, $doc, 86400);
    return $doc;
}

/** 读取会员收藏的有效文档，页面列表和详情页状态共用同一份持久缓存。 */
function miu_cached_user_collect_docs(int $uid): array
{
    $uid = max(0, $uid);
    if ($uid < 1) {
        return [];
    }

    $cacheKey = 'public_v2_homeUserCollectDocs_' . $uid;
    $cached = \think\facade\Cache::get($cacheKey);
    if (is_array($cached)) {
        return $cached;
    }

    $rows = \think\facade\Db::name('UserCollect')->alias('uc')
        ->join('document d', 'd.id = uc.doc_id')
        ->where('uc.uid', $uid)
        ->where('d.is_del', 0)
        ->where('d.status', 1)
        ->where('d.status', 1)->where('d.arcrank', '>=', 0)
        ->field('d.*,uc.create_time as collect_time')
        ->order('uc.create_time desc,uc.id desc')
        ->select()
        ->toArray();

    \think\facade\Cache::tag(['homeUserCollect', 'Document'])->set($cacheKey, $rows, 86400);
    return $rows;
}

/**
 * 读取单页或文档模型附加表的原始行，按表名和 doc_id 缓存。
 */
function miu_cached_model_row(string $table, int $docId, array $tags = ['Document']): array
{
    $table = trim($table);
    $docId = max(0, $docId);
    if ($table === '' || $docId < 1) {
        return [];
    }

    $cacheKey = 'homeModelRow_' . md5($table . '|' . $docId);
    $cached   = get_cache($cacheKey);
    if (is_array($cached)) {
        return $cached;
    }

    try {
        $row = \think\facade\Db::name($table)->where('doc_id', $docId)->find();
        $row = is_array($row) ? $row : (is_object($row) ? $row->toArray() : []);
    } catch (\Throwable $e) {
        return [];
    }

    $tags = array_values(array_unique(array_merge($tags, ['homeChannel'])));
    \think\facade\Cache::tag($tags)->set($cacheKey, $row, 86400);
    return $row;
}

/**
 * 把前台访问路径解析成「首页 / 栏目列表页 / 文档详情页」
 *
 * 用于伪静态、静态模式：这两个模式下浏览器访问的是 /bokezixun/127.html 这种地址，
 * 由 route/app.php 的 miss 路由兜到 app/home/controller/Url.php，再调这里解析。
 *
 * @param string $path 请求路径（不带域名，可带查询串）
 * @return array ['type'=>'home'|'nav'|'doc'|'none', 'nav'=>array, 'doc'=>array, 'page'=>int, 'reason'=>string]
 */
function url_parse(string $path): array
{
    $cfg    = url_config();
    $suffix = $cfg['suffix'];

    //去掉查询串、首尾斜杠，统一成 a/b/c 的形式
    if (($pos = strpos($path, '?')) !== false) {
        $path = substr($path, 0, $pos);
    }
    $path = trim(str_replace('\\', '/', $path), '/');

    //去掉静态模式的保存目录前缀。
    //这里不判断当前是不是静态模式：模式切换后，之前带 /html 前缀的老链接也应该还能访问
    if ($cfg['save_dir'] !== '') {
        if ($path === $cfg['save_dir']) {
            $path = '';
        } elseif (str_starts_with($path, $cfg['save_dir'] . '/')) {
            $path = substr($path, strlen($cfg['save_dir']) + 1);
        }
    }

    //首页
    if ($path === '' || strcasecmp($path, 'index.' . $suffix) === 0 || strcasecmp($path, 'index.html') === 0) {
        return ['type' => 'home', 'nav' => [], 'doc' => [], 'page' => 1, 'reason' => ''];
    }

    //去掉文件后缀，得到「裸路径」
    $bare = preg_replace('/\.' . preg_quote($suffix, '/') . '$/i', '', $path);

    //0)「模型标识」模式的地址（只在后台「栏目URL」选「模型标识」时生效）：
    //     栏目页   article/bokezixun.html
    //     栏目分页 article/bokezixun/list_2004_2.html
    //     文档页   article/bokezixun/128.html
    //   先试这一套；认不出来再往下走老的目录形式，所以老链接不会被打死
    if ($cfg['nav_name'] === 2) {
        //栏目分页
        if (preg_match('#^([A-Za-z0-9_\-]+)/([A-Za-z0-9_\-]+)/list_(\d+)_(\d+)$#', $bare, $m)) {
            $nav = nav_by_nid_path($m[1] . '/' . $m[2]);
            if ($nav) {
                return ['type' => 'nav', 'nav' => $nav, 'doc' => [], 'page' => max(1, (int) $m[4]), 'reason' => ''];
            }
        }

        //文档页
        if (preg_match('#^([A-Za-z0-9_\-]+)/([A-Za-z0-9_\-]+)/(\d+)$#', $bare, $m)) {
            if (nav_by_nid_path($m[1] . '/' . $m[2])) {
                $doc = miu_cached_document((int) $m[3]);
                if ($doc) {
                    return ['type' => 'doc', 'nav' => [], 'doc' => $doc, 'page' => 1, 'reason' => ''];
                }
                return ['type' => 'none', 'nav' => [], 'doc' => [], 'page' => 1, 'reason' => '文档不存在或已删除'];
            }
        }

        //栏目页
        if (preg_match('#^([A-Za-z0-9_\-]+)/([A-Za-z0-9_\-]+)$#', $bare, $m)) {
            $nav = nav_by_nid_path($m[1] . '/' . $m[2]);
            if ($nav) {
                return ['type' => 'nav', 'nav' => $nav, 'doc' => [], 'page' => 1, 'reason' => ''];
            }
        }
    }

    //0.5) 静态模式「栏目页面路径」选 1（顶级目录名称/list_ID.html）时的地址：
    //       {目录}/list_{栏目ID}.html        第1页
    //       {目录}/list_{栏目ID}_{页码}.html  第N页
    //     有栏目ID 就能直接认出栏目，前面那级目录不用管
    if (preg_match('#^(?:.*/)?list_(\d+)(?:_(\d+))?$#', $bare, $m)) {
        $all = nav_flat_map();
        $nid = (int) $m[1];
        if (isset($all[$nid])) {
            return ['type' => 'nav', 'nav' => $all[$nid], 'doc' => [], 'page' => max(1, (int) ($m[2] ?? 1)), 'reason' => ''];
        }
    }

    //1) 栏目分页：dir/p2.html
    //分页前缀是 p，不是 list_ —— list_ 留给静态模式的「栏目页面路径」规则
    //（{栏目目录}/list_{栏目ID}.html），两边不能撞
    if (preg_match('#^(?:(.*)/)?p(\d+)$#i', $bare, $m)) {
        $nav = nav_by_dir((string) ($m[1] ?? ''));
        if ($nav) {
            return ['type' => 'nav', 'nav' => $nav, 'doc' => [], 'page' => max(1, (int) $m[2]), 'reason' => ''];
        }
    }

    //2) 文件形式的分页：dir-2
    if (preg_match('#^(.*)' . preg_quote($cfg['separator'], '#') . '(\d+)$#', $bare, $m)) {
        $nav = nav_by_dir($m[1]);
        if ($nav) {
            return ['type' => 'nav', 'nav' => $nav, 'doc' => [], 'page' => max(1, (int) $m[2]), 'reason' => ''];
        }
    }

    //3) 栏目本身：目录形式 /dir/ 或文件形式 /dir.html，也兼容不带后缀的 /dir
    $nav = nav_by_dir(rtrim($bare, '/'));
    if ($nav) {
        return ['type' => 'nav', 'nav' => $nav, 'doc' => [], 'page' => 1, 'reason' => ''];
    }

    //4) 文档：最后一段是「数字」或「数字-日期」
    $last = basename($bare);
    if (preg_match('/^(\d+)(?:' . preg_quote($cfg['separator'], '/') . '\d+)?$/', $last, $m)) {
        $doc = miu_cached_document((int) $m[1]);
        if ($doc) {
            return ['type' => 'doc', 'nav' => [], 'doc' => $doc, 'page' => 1, 'reason' => ''];
        }
        return ['type' => 'none', 'nav' => [], 'doc' => [], 'page' => 1, 'reason' => '文档不存在或已删除'];
    }

    return ['type' => 'none', 'nav' => [], 'doc' => [], 'page' => 1, 'reason' => '没有匹配到对应的栏目或文档'];
}

/* ==================== 前台栏目（{miu:nav} 标签用） ==================== */

/** 按网站配置给站内相对地址补域名，完整链接和外链原样返回。 */
function miu_full_url(string $url, bool $external = false): string
{
    if ($url === '' || $external || preg_match('/^(?:[a-z][a-z0-9+.-]*:|\/\/)/i', $url)) {
        return $url;
    }

    static $siteDomain = null;
    if ($siteDomain === null) {
        $siteDomain = rtrim(trim((string) get_system_config('site_domain')), '/');
    }
    return $siteDomain === '' ? $url : $siteDomain . '/' . ltrim($url, '/');
}

/** 给所有前台模板设置当前页的 SEO 信息；ogType 仅允许 article，其余按 website 输出。 */
function miu_assign_page_meta(array $meta = [], string $staticUrl = '', string $ogType = 'website'): void
{
    $config = get_system_config();
    $url = $staticUrl !== ''
        ? $staticUrl
        : request()->url();

    //canonical 保留分页、搜索等内容参数，只去掉常见的广告追踪参数。
    $queryStart = strpos($url, '?');
    if ($queryStart !== false) {
        $fragmentStart = strpos($url, '#', $queryStart);
        $query = substr($url, $queryStart + 1, $fragmentStart === false ? null : $fragmentStart - $queryStart - 1);
        $fragment = $fragmentStart === false ? '' : substr($url, $fragmentStart);
        parse_str($query, $params);
        foreach (array_keys($params) as $key) {
            $normalizedKey = strtolower((string) $key);
            if (strpos($normalizedKey, 'utm_') === 0 || in_array($normalizedKey, ['gclid', 'dclid', 'gbraid', 'wbraid', 'fbclid', 'msclkid', 'ttclid', 'yclid'], true)) {
                unset($params[$key]);
            }
        }
        $url = substr($url, 0, $queryStart)
            . ($params ? '?' . http_build_query($params, '', '&', PHP_QUERY_RFC3986) : '')
            . $fragment;
    }

    \think\facade\View::assign([
        'lang'             => \app\home\service\TemplateI18n::htmlLocale(),
        'page_og_type'     => $ogType === 'article' ? 'article' : 'website',
        'page_url'         => miu_full_url($url),
        'page_keywords'    => (string) ($meta['seo_keywords'] ?? $config['site_keywords'] ?? ''),
        'page_description' => (string) ($meta['seo_description'] ?? $config['site_desc'] ?? ''),
        'page_litpic'      => (string) ($meta['litpic'] ?? ''),
    ]);
}

/**
 * 栏目树拍平成 [栏目id => 栏目]，并给每个栏目补上模板常用字段
 *
 * 复用 get_navs() 的 homeNav 缓存，不额外查库；同一个请求里只算一次。
 *
 * 每个栏目会多出这些字段：
 *   title        和 name 一样（兼容用 title 的模板）
 *   nid          当前栏目绑定的内容模型标识（cms_channel.nid）
 *   url          最终链接（见 miu_nav_url）
 *   full_url     带网站域名的完整链接（外链原样保留）
 *   level        层级，一级栏目为 0
 *   children     子栏目数组（list 是同一个东西，兼容 list_to_tree 的写法）
 *   has_children 有没有子栏目，1/0
 *   current      本栏目就是当前栏目，1/0
 *   active       本栏目是当前栏目、或者当前栏目的上级，1/0（菜单高亮用）
 *   currentstyle 由 {miu:nav} 标签按 currentstyle 属性填（当前栏目才非空）
 *
 * @return array
 */
function miu_nav_items(bool $withHidden = false): array
{
    //两种取数：默认只取「显示」的栏目（前台菜单/URL 用）；
    //$withHidden=true 时把隐藏栏目也算进来：供 navid 明确指定栏目，或 includehidden='1' 后再按 pid 筛选。
    //请求内缓存按语言、是否包含隐藏栏目和当前栏目区分。
    //当前栏目变了（比如伪静态URL先解析出栏目、再设进 miu_current_nav_id）就得重算，
    //否则菜单高亮会停在解析前那一次的结果上
    static $cache = [];

    //当前栏目：伪静态/静态URL由控制器或 UrlRewrite 中间件调 miu_current_nav_id() 告知，
    //动态URL没有的话再从 id 参数里取
    $currentId = miu_current_nav_id();
    if (!$currentId) {
        try {
            $currentId = (int) app('request')->param('id', 0);
        } catch (\Throwable $e) {
            $currentId = 0;
        }
    }

    $locale = \app\home\service\ContentI18n::locale();
    $bucket = ($withHidden ? 'all' : 'vis') . ':' . $locale;
    if (isset($cache[$bucket]) && $cache[$bucket]['for'] === $currentId) {
        return $cache[$bucket]['items'];
    }

    $source = $withHidden ? get_navs_all() : get_navs();

    //先找出当前栏目的上级链，这样上级菜单也能高亮
    $activeIds = [];
    if ($currentId) {
        $find = function (array $list, array $parents) use (&$find, &$activeIds, $currentId) {
            foreach ($list as $node) {
                $id   = (int) $node['id'];
                $kids = (isset($node['list']) && is_array($node['list'])) ? $node['list'] : [];
                if ($id === $currentId) {
                    $activeIds = array_merge($parents, [$id]);
                    return true;
                }
                if ($kids && $find($kids, array_merge($parents, [$id]))) {
                    return true;
                }
            }
            return false;
        };
        $find($source, []);
        //普通导航不展示隐藏栏目，但当前页若是隐藏子栏目，仍要沿完整栏目树高亮可见父栏目。
        if (!$activeIds && !$withHidden) {
            $find(get_navs_all(), []);
        }
    }

    $items = [];
    $withFullUrls = function (array $tree) use (&$withFullUrls) {
        foreach ($tree as &$node) {
            $node['url'] = miu_nav_url($node);
            $node['full_url'] = miu_full_url($node['url'], !empty($node['is_url']));
            $children = isset($node['list']) && is_array($node['list']) ? $node['list'] : [];
            if ($children) {
                $node['children'] = $node['list'] = $withFullUrls($children);
            }
        }
        unset($node);
        return $tree;
    };
    $walk  = function (array $list, int $level) use (&$walk, &$withFullUrls, &$items, $currentId, $activeIds) {
        foreach ($list as $node) {
            $kids = (isset($node['list']) && is_array($node['list'])) ? $node['list'] : [];
            $kids = $kids ? $withFullUrls($kids) : [];
            unset($node['list']);

            $id = (int) $node['id'];

            $node['level']        = $level;
            $node['nid']          = (string) (get_channel((int) ($node['channel_id'] ?? 0))['nid'] ?? '');
            $node['children']     = $kids;
            $node['list']         = $kids;                              //兼容 list_to_tree 的写法
            $node['has_children'] = $kids ? 1 : 0;
            $node['url']          = miu_nav_url($node);
            $node['full_url']     = miu_full_url($node['url'], !empty($node['is_url']));
            $node['current']      = ($currentId && $id === $currentId) ? 1 : 0;
            $node['active']       = in_array($id, $activeIds, true) ? 1 : 0;

            $items[$id] = $node;

            if ($kids) {
                $walk($kids, $level + 1);
            }
        }
    };
    $walk($source, 0);

    $cache[$bucket] = ['for' => $currentId, 'items' => $items];
    return $items;
}

/**
 * {miu:nav} / {miu:list} 标签内部报错时的友好提示
 *
 * 主要处理变量名/字段名写错的情况，比如 id='vo' 却写成了 {$vo1.name}。
 * 返回空字符串表示不是这类错误，调用处会把原异常再抛出去（不掩盖别的错）
 *
 * @param string $message 异常信息
 * @param string $id      标签 id 属性指定的循环变量名
 * @param string $key     标签 key 属性指定的索引变量名
 * @param string $tagName 标签名（miu:nav / miu:list），只用于提示文案
 * @return string
 */
function miu_tpl_error(string $message, string $id = 'vo', string $key = 'index', string $tagName = 'miu:nav'): string
{
    $vars   = '本标签的循环变量名是 $' . $id . '（由 id 属性决定），索引变量名是 $' . $key . '（由 key 属性决定）';
    $prefix = $tagName . ' 提示：';

    //变量名写错：{$vo1.name}
    if (preg_match('/Undefined variable \$(\w+)/', $message, $m)) {
        return $prefix . '变量 $' . $m[1] . ' 不存在。' . $vars . '，检查一下是不是写错了';
    }
    //字段名写错：{$vo.nam}
    if (preg_match('/Undefined array key "?([^"\s]+)"?/', $message, $m)) {
        return $prefix . '字段 ' . $m[1] . ' 不存在，检查一下 {$' . $id . '.' . $m[1] . '} 是不是写错了';
    }
    //变量是 null 又去取属性
    if (preg_match('/Attempt to read property "(\w+)" on null/', $message, $m)) {
        return $prefix . $m[1] . ' 读不到，可能是变量名或字段名写错了（' . $vars . '）';
    }

    return '';
}

/**
 * miu_tpl_error() 的兼容别名
 *
 * 标签库改了以后，模板编译缓存不会自动失效（ThinkPHP 只比对模板文件的修改时间），
 * 旧的编译产物里调的还是 miu_nav_error，保留这个别名免得升级后前台直接报函数不存在。
 * 正常情况下去后台点一下「清缓存」就会重新编译，用不到这里。
 */
function miu_nav_error(string $message, string $id = 'vo', string $key = 'index'): string
{
    return miu_tpl_error($message, $id, $key, 'miu:nav');
}

/**
 * 取某一级栏目列表（供 {miu:nav} 标签用）
 *
 * 筛选顺序：
 *   写了 navid  → 只取这几个栏目（不管在第几层，这时候 pid 不生效）
 *   没写 navid  → 取 pid 这一层的栏目；includehidden=true 时这一层也包含隐藏栏目
 *   再按 notnavid 排除 → navid 且未指定排序时按填写顺序，否则按 order/sort 排序 → 按 limit 截取
 *
 * @param int    $pid      父栏目id，0 = 一级栏目（写了 navid 时这个不生效）
 * @param string $order    排序字段（调用前已白名单校验）
 * @param string $sort     asc / desc
 * @param string $limit    '0,5' 或 '5'，空字符串 = 不限
 * @param int    $titlelen 限制标题长度，0 = 不限制（按字符数算，只截 title，name 保持完整）
 * @param string $notnavid 要排除的栏目id，多个用英文逗号隔开，空字符串 = 不排除
 * @param string $navid    只保留的栏目id，多个用英文逗号隔开，空字符串 = 不限制（按 pid 取这一层）
 * @param bool   $includeHidden 是否让隐藏栏目参与按 pid 筛选，默认 false；navid 模式本身仍包含隐藏栏目
 * @param bool   $preserveNavidOrder navid 未指定排序参数时是否按填写顺序输出
 * @return array
 */
function miu_nav_list(int $pid, string $order = 'sort', string $sort = 'asc', string $limit = '', int $titlelen = 0, string $notnavid = '', string $navid = '', bool $includeHidden = false, bool $preserveNavidOrder = false, string $modelNid = ''): array
{
    $modelNids = $modelNid === '' ? [] : array_values(array_unique(array_map('trim', explode(',', $modelNid))));
    //要排除的栏目id
    $notIds = [];
    if ($notnavid !== '') {
        $notIds = array_map('intval', explode(',', $notnavid));
    }

    //只要的栏目id
    $inIds = [];
    if ($navid !== '') {
        $inIds = array_map('intval', explode(',', $navid));
    }

    $list = [];
    //显式 navid 一直允许取隐藏栏目；按 pid 查询时只有 includehidden='1' 才把隐藏栏目纳入候选集。
    foreach (miu_nav_items(!empty($inIds) || $includeHidden) as $item) {
        //写了 navid 就只看这几个栏目 —— 不管它在第几层
        //（栏目可能是子级，这时不用再写 pid，写了也不生效）；
        //没写 navid 才按 pid 取这一层
        if ($inIds) {
            if (!in_array((int) $item['id'], $inIds, true)) {
                continue;
            }
        } elseif ((int) $item['pid'] !== $pid) {
            continue;
        }
        if ($modelNids && !in_array((string) ($item['nid'] ?? ''), $modelNids, true)) {
            continue;
        }

        //排除指定栏目
        if ($notIds && in_array((int) $item['id'], $notIds, true)) {
            continue;
        }

        //限制标题长度：只截 title，{$vo.title_full} 还是完整的，方便模板自己取舍
        $item['title_full'] = (string) ($item['title'] ?? '');
        if ($titlelen > 0) {
            $title = $item['title_full'];
            if (mb_strlen($title, 'UTF-8') > $titlelen) {
                $item['title'] = mb_substr($title, 0, $titlelen, 'UTF-8');
            }
        }

        $list[] = $item;
    }

    //navid 未指定排序参数时，按 navid 中栏目 ID 的填写顺序输出。
    $navidOrder = $preserveNavidOrder ? array_flip($inIds) : [];
    //排序（值相同时按 id 排，保证结果稳定）
    $desc = strtolower($sort) === 'desc';
    usort($list, function ($a, $b) use ($order, $desc, $navidOrder) {
        if ($navidOrder) {
            return ($navidOrder[(int) $a['id']] ?? PHP_INT_MAX) <=> ($navidOrder[(int) $b['id']] ?? PHP_INT_MAX);
        }

        $va = $a[$order] ?? 0;
        $vb = $b[$order] ?? 0;
        if ($va == $vb) {
            return (int) $a['id'] <=> (int) $b['id'];
        }
        $cmp = $va <=> $vb;
        return $desc ? -$cmp : $cmp;
    });

    //截取
    if ($limit !== '') {
        $parts = array_map('intval', explode(',', $limit));
        if (count($parts) > 1) {
            $list = array_slice($list, max(0, $parts[0]), max(0, $parts[1]));
        } else {
            $list = array_slice($list, 0, max(0, $parts[0]));
        }
    }

    return $list;
}

/* ==================== 前台文档列表（{miu:list} 标签用） ==================== */

/**
 * 取某个栏目及其所有子孙栏目的 id（含自己）
 *
 * 文档通常发布在叶子栏目上，所以按栏目取文档时要把子栏目一起带上，
 * 否则一级栏目下面一篇都取不到（后台文档列表也是这个逻辑）。
 *
 * @param int $navId
 * @return array
 */
function miu_nav_ids_with_children(int $navId): array
{
    $items = miu_nav_items(true);
    $ids   = [$navId];

    //栏目表理论上不会成环，但 pid 被人工改坏时用层数兜底，避免死循环
    $walk = function (int $pid, int $depth) use (&$walk, &$ids, $items) {
        if ($depth > 32) {
            return;
        }
        foreach ($items as $item) {
            if ((int) $item['pid'] === $pid) {
                $id = (int) $item['id'];
                if (!in_array($id, $ids, true)) {
                    $ids[] = $id;
                    $walk($id, $depth + 1);
                }
            }
        }
    };
    $walk($navId, 0);

    return $ids;
}

/**
 * 文档列表可以排序的字段
 *
 * order 参数是直接拼进 SQL 的 ORDER BY 的，绝不能拿用户输入直接拼，
 * 所以这里按 cms_document 表的真实字段做白名单校验。
 * 表结构取不到时（比如数据库异常）回退到一份常用字段，保证标签不会整个页面报错。
 *
 * @return array 全部小写的字段名
 */
function miu_doc_order_fields(): array
{
    static $fields = null;
    if ($fields !== null) {
        return $fields;
    }

    $cached = get_cache('homeDocOrderFields');
    if (is_array($cached)) {
        return $fields = $cached;
    }

    $loaded = true;
    try {
        $fields = array_map('strtolower', \think\facade\Db::name('document')->getTableFields());
    } catch (\Throwable $e) {
        $loaded = false;
        $fields = ['id', 'sort', 'add_time', 'update_time', 'click', 'arcrank', 'is_top', 'is_head', 'is_recommend'];
    }

    if ($loaded) {
        \think\facade\Cache::tag('Document')->set('homeDocOrderFields', $fields, 86400);
    }

    return $fields;
}

/**
 * flag 字母 => 文档表字段
 *
 * @return array
 */
function miu_doc_flag_map(): array
{
    return [
        'h' => 'is_head',        // 头条
        'r' => 'is_recommend',   // 推荐
        's' => 'is_special',     // 特荐
        'b' => 'is_b',           // 加粗
        't' => 'is_top',         // 置顶
        'j' => 'is_jump',        // 跳转
        'l' => 'is_litpic',      // 有图
        'o' => 'is_original',    // 原创
    ];
}

/**
 * 把 flag 字符串解析成文档表字段数组
 *
 * 多个字母用英文逗号隔开，字母之间是「或」的关系（满足任意一个就列出来）。
 * 不认识的字母直接忽略（标签那边已经先校验并提示过了）
 *
 * @param string $flag 例如 'h,r'
 * @return array 例如 ['is_head', 'is_recommend']
 */
function miu_doc_flags(string $flag): array
{
    if ($flag === '') {
        return [];
    }

    $map    = miu_doc_flag_map();
    $fields = [];
    foreach (explode(',', strtolower($flag)) as $letter) {
        $letter = trim($letter);
        if ($letter !== '' && isset($map[$letter])) {
            $fields[] = $map[$letter];
        }
    }

    return array_values(array_unique($fields));
}

/**
 * 取文档列表（供 {miu:list} 标签用）
 *
 * 取哪些文档（优先级从高到低）：
 *   1) 当前是「搜索页」→ 用搜索条件（关键词 + 栏目 + 模型）
 *   2) 非搜索页传了 navid / mid / nid → 按指定栏目或模型取文档，不受当前栏目影响
 *   3) 都没传     → 有当前栏目就取当前栏目（含子栏目）的文档；没有（首页）就取所有栏目的文档
 *
 * 只取「已发布」的：is_del = 0（没进回收站）且 arcrank >= 0（arcrank = -1 是未审核，前台不展示）
 *
 * 分页：在「要分页的页面」上（miu_page_info() 有数据 —— 栏目页由 miu_render_nav_list() 填、
 * 搜索页由 Search::index() 填）会自动分页 —— 填了 limit 就用它作为每页条数并优先于 pagesize，
 * 未填 limit 时按 pagesize（不填就用这一页自己的每页条数）取「当前页」，并把总条数/总页数写回 miu_page_info()，这样 {miu:pagelist}
 * 输出的就是本次查询的分页。在首页、详情页等地方没有分页上下文，limit 照常生效（默认 10）。
 *
 * 缓存：查库结果按「影响 SQL 的所有条件」做键，用 Cache::tag('Document') 持久缓存 24 小时
 * （跟后台 clear_cache('Document') 对上）。所以后台文档的增/改/删/审核/回收站操作都要
 * clear_cache('Document')，否则前台要等缓存过期才更新。
 * 例外：**搜索页不缓存** —— 搜索结果是长尾 key（关键词组合没有上限），缓存命中率低还会
 * 让缓存文件无限堆积，所以搜索页每次都直接查库。
 * 缓存里只存数据库原始行（rows）和总数（total）；标题截断、补 url/nav_name 这些依赖栏目
 * 数据的加工放在缓存外面做，栏目改名之类的（clear_cache('homeNav')）也能立刻反映。
 * 另外缓存键里带的是「解析后的栏目id」，后台改了栏目树 id 就变、键跟着变，不用手动清。
 *
 * @param array $opt 标签传过来的参数：
 *   navid    栏目id，多个用英文逗号隔开
 *   mid      模型id（cms_channel 表的 id）
 *   order    排序字段或 rand（rand 表示随机排序，忽略 sort）
 *   sort     asc / desc
 *   limit    取几条，'5'（每页 5 条）或 '0,5'（从第 0 条起、每页 5 条）；不填默认取 10 条。
 *            列表页优先于 pagesize；非列表页仍按偏移量和条数取数
 *   pagesize 每页显示条数，默认 10；列表页未填写 limit 时生效
 *   titlelen 标题截断长度，0 = 不截断
 *   flag     文章属性字母，多个用英文逗号隔开
 *   includehidden  是否包含隐藏栏目下的文档，默认 0
 * @return array
 */
function miu_list(array $opt = []): array
{
    $navid    = trim((string) ($opt['navid'] ?? ''));
    $mid      = (int) ($opt['mid'] ?? 0);
    $modelNid = trim((string) ($opt['nid'] ?? ''));
    $order    = trim((string) ($opt['order'] ?? ''));
    $sort     = strtolower(trim((string) ($opt['sort'] ?? '')));
    $limit    = trim((string) ($opt['limit'] ?? ''));
    $pagesize = (int) ($opt['pagesize'] ?? 0);
    $flag     = trim((string) ($opt['flag'] ?? ''));
    $titlelen = (int) ($opt['titlelen'] ?? 0);
    $includeHidden = (string) ($opt['includehidden'] ?? '0') === '1';

    if ($order === '') {
        $order = 'sort';
    }
    $randomOrder = strtolower($order) === 'rand';
    if (!in_array($sort, ['asc', 'desc'], true)) {
        $sort = 'desc';
    }

    //当前栏目：栏目页/详情页有值，首页是 0
    $curNavId = miu_current_nav_id();

    //搜索页：Search::index() 会把搜索条件存进 miu_current_search()
    $search = miu_current_search();

    //miu_page_info() 只在「要分页的页面」渲染时被填过：
    //栏目页由 miu_render_nav_list() 填（有 nav），搜索页由 Search::index() 填（没有 nav，但有 base_url）；
    //首页、详情页都是空的 —— 用它来判断当前是不是这种页面
    $pageInfo   = miu_page_info();
    $onListPage = !empty($pageInfo) && (!empty($pageInfo['nav']) || !empty($pageInfo['base_url']));

    // ---------------- 取几条 ----------------
    $page   = 1;
    $size   = 10;
    $offset = 0;
    $count  = 10;
    if ($onListPage) {
        //列表页：limit 优先作为每页条数；没写 limit 才用 pagesize 或列表页默认条数。
        if ($limit !== '') {
            $parts  = array_map('intval', explode(',', $limit));
            $offset = count($parts) > 1 ? max(0, $parts[0]) : 0;
            $size   = max(0, (int) end($parts));
        } else {
            $size = $pagesize > 0 ? $pagesize : (int) ($pageInfo['size'] ?? 0);
        }
        if ($size <= 0) {
            if ($limit !== '') {
                //limit=0 沿用“空列表”语义，同时保持分页上下文有效。
                $newInfo = ['nav' => $pageInfo['nav'] ?? nav_by_id($curNavId), 'page' => 1, 'count' => 1, 'total' => 0, 'size' => max(1, (int) ($pageInfo['size'] ?? 10))];
                if (!empty($pageInfo['base_url'])) {
                    $newInfo['base_url'] = $pageInfo['base_url'];
                }
                miu_page_info($newInfo);
                return [];
            }
            $size = 10;
        }
        //当前页码：列表页已经解析好了（动态URL取 page 参数，伪静态/静态由 URL 规则解析）
        $page = max(1, (int) ($pageInfo['page'] ?? 1));
    } else {
        //非列表页（首页、详情页等）：limit 表示偏移量和取数条数
        //不填 limit 就取 10 条；'5' 取前 5 条；'0,5' 从第 0 条起取 5 条
        if ($limit !== '') {
            $parts = array_map('intval', explode(',', $limit));
            if (count($parts) > 1) {
                $offset = max(0, $parts[0]);
                $count  = max(0, $parts[1]);
            } else {
                $offset = 0;
                $count  = max(0, $parts[0]);
            }
        }
        //limit=0 就是取 0 条，直接返回空
        if ($count <= 0) {
            return [];
        }
    }

    // ---------------- 取哪些文档：先把「栏目范围 / 模型 / 关键词」定下来 ----------------
    //这几个值既决定 SQL，也进缓存键。栏目树是从 miu_nav_items()（缓存标记 homeNav）里算出来的，
    //后台改了栏目、清了 homeNav 之后这里算出来的 id 就变，缓存键跟着变，自然读不到旧数据。
    $keyword   = '';    //关键词（搜索页才有）
    $navIds    = null;  //限定的栏目id（含子栏目）；null = 不限栏目
    $channelId = 0;     //限定的模型id；0 = 不限模型
    $channelIds = [];   //nid 显式指定一个或多个内容模型时使用

    if (!empty($search)) {
        //在搜索页上用本标签出「搜索结果」时，条件要跟控制器那次搜索保持一致：
        //关键词 + 栏目（连同子栏目）+ 模型，搜索页优先使用搜索条件。
        $keyword   = trim((string) ($search['keyword'] ?? ''));
        $sNavid    = (int) ($search['navid'] ?? 0);
        $channelId = (int) ($search['mid'] ?? 0);

        //关键词、栏目、模型一个都没有就不查：不然等于把整站文档全列出来
        if ($keyword === '' && $sNavid <= 0 && $channelId <= 0) {
            return [];
        }
        if ($sNavid > 0) {
            $navIds = miu_nav_ids_with_children($sNavid);
        }
    } elseif ($navid !== '') {
        //显式指定栏目时不套用当前栏目，按指定栏目（含子栏目）取数
        $ids = [];
        foreach (array_map('intval', explode(',', $navid)) as $id) {
            if ($id > 0) {
                $ids = array_merge($ids, miu_nav_ids_with_children($id));
            }
        }
        $ids = array_values(array_unique($ids));
        if (empty($ids)) {
            return [];
        }
        $navIds = $ids;
    } elseif ($modelNid !== '') {
        //显式指定一个或多个模型标识时不套用当前栏目
        $modelNids = array_values(array_unique(array_map('trim', explode(',', $modelNid))));
        $channelIds = array_map('intval', \think\facade\Db::name('channel')->whereIn('nid', $modelNids)->column('id'));
        $channelIds = array_values(array_filter($channelIds, static fn($id) => $id > 0));
        sort($channelIds);
        if (!$channelIds) {
            return [];
        }
    } elseif ($mid > 0) {
        //显式指定模型 ID 时不套用当前栏目
        $channelId = $mid;
    } elseif ($curNavId > 0) {
        //当前栏目（含子栏目）
        $navIds = miu_nav_ids_with_children($curNavId);
    }
    //首页不写 navid/mid/nid 时，从全站查询，是否包含隐藏栏目由 includehidden 决定。

    // ---------------- 缓存 ----------------
    //搜索页不缓存：搜索结果是长尾 —— 每个「关键词 + 栏目 + 模型 + 页码」组合都是一个新 key，
    //命中率极低，还会让缓存文件无限堆积（File 驱动只在 key 被再次读到时才删过期文件）。
    //随机排序也不缓存，确保每次请求重新打乱结果；只给首页/栏目聚合这种 key 数量有上限的列表加缓存。
    $noCache = !empty($search) || $randomOrder;

    //影响 SQL 的条件全部进缓存键；titlelen 只影响标题截断，不进键（取出来后再截）
    $cacheKey = 'public_v2_docList_' . md5(json_encode([
        'locale'  => \app\home\service\ContentI18n::locale(),
        'keyword' => $keyword,
        'navids'  => $navIds,
        'includehidden' => $includeHidden,
        'channel' => $channelIds ?: $channelId,
        'order'   => $order,
        'sort'    => $sort,
        'flag'    => $flag,
        'limit'   => $onListPage ? [$offset, $size] : [$offset, $count],
        'page'    => $onListPage ? $page : null,
        'size'    => $onListPage ? $size : null,
    ], JSON_UNESCAPED_UNICODE));

    $cached = $noCache ? null : get_cache($cacheKey);
    if (!is_array($cached) || !array_key_exists('rows', $cached)) {
        $query = \think\facade\Db::name('document')
            ->where('is_del', 0)
            ->where('status', 1)->where('arcrank', '>=', 0);
        if (!$includeHidden) {
            miu_exclude_hidden_nav_documents($query);
        }

        if ($keyword !== '') {
            //标题 或 SEO描述 里包含关键词（跟 Search::index() 里的条件一致）
            $like = '%' . $keyword . '%';
            $localizedQuery = \app\home\service\ContentI18n::documentSearchQuery($keyword);
            $query->where(function ($q) use ($like, $localizedQuery) {
                $q->whereOr('title', 'like', $like)
                    ->whereOr('seo_description', 'like', $like);
                if ($localizedQuery) {
                    $q->whereOr('id', 'in', $localizedQuery);
                }
            });
        }
        if ($navIds !== null) {
            $query->whereIn('nav_id', $navIds);
        }
        if ($channelIds) {
            $query->whereIn('channel_id', $channelIds);
        } elseif ($channelId > 0) {
            $query->where('channel_id', $channelId);
        }

        //文章属性（多个之间是「或」）
        $flags = miu_doc_flags($flag);
        if ($flags) {
            $query->where(function ($q) use ($flags) {
                foreach ($flags as $field) {
                    $q->whereOr($field, 1);
                }
            });
        }

        //排序
        $query->order('is_head', 'desc');
        if ($randomOrder) {
            //随机模式下 sort 无效；保留 is_head 排序，让头条文档仍然置顶。
            $query->orderRand();
        } else {
            $query->order($order, $sort);
            if (strtolower($order) === 'sort') {
                $query->order('release_time', 'desc');
            }
        }

        //取几条：列表页要总数（clone 出来算，别把 limit 带进计数语句），非列表页不用总数
        if ($onListPage) {
            $total = (clone $query)->count();
            $query->limit($offset + (($page - 1) * $size), $size);
        } else {
            $total = 0;
            $query->limit($offset, $count);
        }

        //列表默认带上模型的自定义字段（附加表，只查 channel_field 里登记的那些）
        $cached = [
            'rows'  => miu_doc_merge_extras($query->select()->toArray(), !$noCache),
            'total' => $total,
        ];
        //搜索页（$noCache）不写缓存
        if (!$noCache) {
            //细分标记：除了总标记 Document，再按「这批数据属于哪」打个细标记，
            //这样后台「发布/编辑文档」能只清受影响的那部分（见 clear_doc_list_cache()）。
            //必须用 Cache::tag() 存：后台清的是「标签」（Cache::clear($tag)），
            //不打 tag 存的话 clear_cache('Document') 清不掉
            $cacheTags = ['Document'];
            if (!$includeHidden) {
                $cacheTags[] = 'homeNav';
            }
            if ($navIds !== null) {
                $tagIds = [];
                if ($navid !== '') {
                    foreach (array_map('intval', explode(',', $navid)) as $nid) {
                        if ($nid > 0) {
                            $tagIds[] = $nid;
                        }
                    }
                } elseif ($curNavId > 0) {
                    $tagIds[] = $curNavId;
                }
                foreach ($tagIds as $nid) {
                    $cacheTags[] = 'docNav' . $nid;
                }
            } elseif ($channelIds) {
                foreach ($channelIds as $cacheChannelId) {
                    $cacheTags[] = 'docMid' . $cacheChannelId;
                }
            } elseif ($channelId > 0) {
                $cacheTags[] = 'docMid' . $channelId;
            } else {
                //不限栏目、不限模型 = 首页/全站那种列表
                $cacheTags[] = 'docHome';
            }

            \think\facade\Cache::tag($cacheTags)->set($cacheKey, $cached, 86400);
        }
    }

    $rows  = $cached['rows'];
    $total = (int) ($cached['total'] ?? 0);

    // ---------------- 写回分页信息 ----------------
    if ($onListPage) {
        //把分页信息换成「本标签这次查的」，这样 {miu:pagelist} 输出的就是这套数据。
        //分页链接指向哪个栏目：指定了 navid 就指它（多个栏目时取第一个，多个栏目本来也没法共用一个分页条），
        //否则指当前列表页的栏目
        $pageNav = [];
        if ($navid !== '') {
            $navidArr = array_map('intval', explode(',', $navid));
            $pageNav  = nav_by_id((int) ($navidArr[0] ?? 0));
        }
        if (!$pageNav) {
            $pageNav = $pageInfo['nav'] ?? nav_by_id($curNavId);
        }
        $newInfo = [
            'nav'   => $pageNav,
            'page'  => $page,
            'count' => $size > 0 ? (int) ceil(max(0, $total - $offset) / $size) : 1,
            'total' => max(0, $total - $offset),
            'size'  => $size,
        ];
        //不是栏目页的（搜索页）要把 base_url 带上，否则 {miu:pagelist} 就拼不出分页链接了
        if (!empty($pageInfo['base_url'])) {
            $newInfo['base_url'] = $pageInfo['base_url'];
        }
        miu_page_info($newInfo);
    }

    // ---------------- 补模板常用字段 ----------------
    //栏目信息一次性取出来，避免每条文档都去查一遍
    $navItems = miu_nav_items(true);
    //各文档的标签一次性补齐，避免在循环里逐篇查询关键词表。
    $docTagLists = miu_doc_tag_lists($rows);

    $list = [];
    foreach ($rows as $row) {
        $nav = $navItems[(int) $row['nav_id']] ?? [];

        //复用列表页那套：补 url / date / add_time_text / nav_name / title_style
        $item = miu_doc_row($row, $nav);
        $item['full_url'] = miu_full_url((string) $item['url']);
        $item['nid'] = (string) (get_channel((int) ($row['channel_id'] ?? 0))['nid'] ?? '');

        //标题截断：只截 title，完整标题放在 title_full 里，模板想用哪个都行
        $item['title_full'] = (string) ($row['title'] ?? '');
        if ($titlelen > 0 && mb_strlen($item['title_full'], 'UTF-8') > $titlelen) {
            $item['title'] = mb_substr($item['title_full'], 0, $titlelen, 'UTF-8');
        }
        $item['tag_list'] = $docTagLists[(int) ($row['id'] ?? 0)] ?? [];

        $list[] = $item;
    }

    return $list;
}

/**
 * 批量取文档列表中每篇文档的标签。
 *
 * 只返回 id/title/url/full_url/click；原始标签行按标签 ID 做 homeTag 持久缓存，
 * 点击数复用 60 秒缓存，后台改标签会清 homeTag，文档增删改会清 Document。
 *
 * @param array $docRows miu_list() 已取出的文档行
 * @return array [文档ID => 标签列表]
 */
function miu_doc_tag_lists(array $docRows): array
{
    $tagIdsByDoc = [];
    $allTagIds   = [];

    foreach ($docRows as $docRow) {
        $docId = (int) ($docRow['id'] ?? 0);
        if ($docId < 1) {
            continue;
        }

        $rawIds = trim((string) ($docRow['tag_ids'] ?? ''), ", \t\n\r\0\x0B");
        $ids    = [];
        $seen   = [];
        foreach (explode(',', $rawIds) as $rawId) {
            $rawId = trim($rawId);
            if ($rawId === '' || !ctype_digit($rawId)) {
                continue;
            }
            $tagId = (int) $rawId;
            if ($tagId > 0 && !isset($seen[$tagId])) {
                $seen[$tagId] = true;
                $ids[]        = $tagId;
                $allTagIds[]  = $tagId;
            }
        }
        $tagIdsByDoc[$docId] = $ids;
    }

    $allTagIds = array_values(array_unique($allTagIds));
    if (!$allTagIds) {
        return array_fill_keys(array_keys($tagIdsByDoc), []);
    }

    $tagRowsById = [];
    $missingIds  = [];
    foreach ($allTagIds as $tagId) {
        $cacheKey = 'homeDocTagRow_' . $tagId;
        $tagRow   = get_cache($cacheKey);
        if (!is_array($tagRow)) {
            $missingIds[] = $tagId;
        } elseif ($tagRow) {
            $tagRowsById[$tagId] = $tagRow;
        }
    }

    if ($missingIds) {
        $querySucceeded = false;
        $foundRows      = [];
        try {
            //按当前结果中缺失的标签 ID 一次查询，只取模板要用的 id/title。
            $foundRows = \think\facade\Db::name('keywords')
                ->field('id,title')
                ->where('status', 1)
                ->whereIn('id', $missingIds)
                ->select()
                ->toArray();
            $querySucceeded = true;
        } catch (\Throwable $e) {
            \think\facade\Log::error('读取文档标签失败：' . $e->getMessage());
        }

        if ($querySucceeded) {
            $foundById = [];
            foreach ($foundRows as $tagRow) {
                $tagId = (int) ($tagRow['id'] ?? 0);
                if ($tagId > 0) {
                    $foundById[$tagId] = [
                        'id'    => $tagId,
                        'title' => (string) ($tagRow['title'] ?? ''),
                    ];
                }
            }
            foreach ($missingIds as $tagId) {
                $tagRow  = $foundById[$tagId] ?? [];
                $cacheKey = 'homeDocTagRow_' . $tagId;
                \think\facade\Cache::tag('homeTag')->set($cacheKey, $tagRow, 86400);
                if ($tagRow) {
                    $tagRowsById[$tagId] = $tagRow;
                }
            }
        }
    }

    if (!$tagRowsById) {
        return array_fill_keys(array_keys($tagIdsByDoc), []);
    }

    //标签名称按当前前台语言覆盖；缓存里始终保留数据库原始标题。
    $localizedRows = \app\home\service\ContentI18n::overlayRows('tag', array_values($tagRowsById));
    $clickMap      = miu_tag_click_map();
    $tagsById      = [];
    foreach ($localizedRows as $tagRow) {
        $tagId = (int) ($tagRow['id'] ?? 0);
        if ($tagId < 1) {
            continue;
        }
        $tagUrl = miu_tag_url($tagId);
        $tagsById[$tagId] = [
            'id'       => $tagId,
            'title'    => (string) ($tagRow['title'] ?? ''),
            'url'      => $tagUrl,
            'full_url' => miu_full_url($tagUrl),
            'click'    => (int) ($clickMap[$tagId] ?? 0),
        ];
    }

    $result = [];
    foreach ($tagIdsByDoc as $docId => $tagIds) {
        $result[$docId] = [];
        foreach ($tagIds as $tagId) {
            if (isset($tagsById[$tagId])) {
                $result[$docId][] = $tagsById[$tagId];
            }
        }
    }

    return $result;
}

/* ==================== 前台分页（{miu:pagelist} 标签用） ==================== */

/**
 * 当前页面的分页信息（列表页渲染时存进来，{miu:pagelist} 标签读）
 *
 * 为什么用这种方式而不是直接读模板变量：分页标签只在列表页有意义，
 * 存成一份「当前页面状态」，标签就能判断出「现在不在列表页」并给出提示，
 * 也不用把 $page_no / $page_count 这些变量名写死在标签里。
 *
 * @param array|null $set 传数组表示设置，传 null 表示只读取
 * @return array ['nav'=>栏目, 'page'=>当前页, 'count'=>总页数, 'total'=>总条数, 'size'=>每页条数]
 *               另外可以带一个可选的 'base_url'：不是栏目页的页面（比如搜索页）用，
 *               {miu:pagelist} 按 base_url 是否已有查询参数追加 ?page=N 或 &page=N；
 *               标签页带 tag_id 时优先使用 miu_tag_url 生成地址。
 */
function miu_page_info(?array $set = null): array
{
    static $info = [];
    if ($set !== null) {
        $info = $set;
    }
    return $info;
}

/**
 * 当前正在看的文档（详情页渲染时存进来，面包屑标签用）
 *
 * @param array|null $set 传数组表示设置，传 null 表示只读取
 * @return array
 */
function miu_current_doc(?array $set = null): array
{
    static $doc = [];
    if ($set !== null) {
        $doc = $set;
    }
    return $doc;
}

/**
 * 当前页面的搜索条件（搜索页渲染时存进来）
 *
 * Search::index() 在渲染模板之前调一次存进去，之后：
 *   · {miu:list} 在搜索页上会用这套条件去查（关键词 + 栏目 + 模型），
 *     这样模板里就能直接用标签出搜索结果
 *   · 其它页面没人调它，一直是空的，{miu:list} 就按原来的「当前栏目/指定栏目」逻辑走
 *
 * @param array|null $set 传数组表示设置，传 null 表示只读取
 * @return array ['keyword'=>关键词, 'navid'=>栏目id, 'mid'=>模型id]
 */
function miu_current_search(?array $set = null): array
{
    static $search = [];
    if ($set !== null) {
        $search = $set;
    }
    return $search;
}

/**
 * 当前页面的标签（标签页渲染时存进来）
 *
 * miu_render_tag_list() 在渲染模板之前存入，前台访问和静态生成共用，之后：
 *   · {miu:position} 面包屑会用它在结尾输出「标签：xxx」
 *   · 其它页面没人调它，一直是空的
 *
 * @param array|null $set 传数组表示设置，传 null 表示只读取
 * @return array 标签那一行（cms_keywords 的记录）
 */
function miu_current_tag(?array $set = null): array
{
    static $tag = [];
    if ($set !== null) {
        $tag = $set;
    }
    return $tag;
}

/**
 * 生成分页 HTML（供 {miu:pagelist} 标签用）
 *
 * 默认主题省略不可用的首页/上一页、下一页/末页；dark/light 主题保留对应按钮，
 * 使用无 href 的 span 和 aria-disabled="true" 标记不可点击。
 *
 * 分页链接：分页信息里有 nav（栏目页）就按后台「SEO → URL配置」的规则拼栏目地址；
 * 标签页按 tag_id 调用 miu_tag_url；其余非栏目页按 base_url 追加 ?page=N 或 &page=N。
 *
 * 输出里自带一段分页 CSS（包在 <style> 里），开箱即用不用另配样式；
 * 想自定义外观直接改本函数里的 $css。
 *
 * @param array $opt 标签参数：
 *   type           full（全面显示，按 listitem 来）/ mini（只显示上一页、下一页）
 *   currentstyle   当前页的 class 名，默认 active
 *   listitem       分页结构，逗号隔开，可选 index/pre/pageno/next/end/info
 *   indextext/pretext/nexttext/endtext 各按钮文字
 *   theme          default（原样式）/ dark（深色）/ light（浅色），只影响分页自身
 * @return string 无分页上下文时返回一句 HTML 注释（页面上看不到，看源码能知道原因）；
 *                只有一页时返回空字符串
 */
function miu_pagelist(array $opt = []): string
{
    $info = miu_page_info();
    //nav 只有栏目页才有；搜索页这类页面没有 nav，会自己给一个 base_url
    if (empty($info) || (empty($info['nav']) && empty($info['base_url']))) {
        //不在列表页：输出一句 HTML 注释，页面上看不到，但看源码能知道为什么没有分页
        return '<!-- miu:pagelist：当前页面不是文章列表页，没有分页数据 -->';
    }

    $nav   = $info['nav'];
    $page  = max(1, (int) ($info['page'] ?? 1));
    $count = max(1, (int) ($info['count'] ?? 1));
    $total = (int) ($info['total'] ?? 0);

    //只有一页就不显示分页了
    if ($count <= 1) {
        return '';
    }

    $type         = (string) ($opt['type'] ?? 'full');
    $currentstyle = (string) ($opt['currentstyle'] ?? '') ?: 'active';
    $paginationText = static function (string $option, string $key, string $default) use ($opt): string {
        $custom = trim((string) ($opt[$option] ?? ''));
        if ($custom !== '' && $custom !== $default) {
            return $custom;
        }

        return class_exists(\app\home\service\TemplateI18n::class)
            ? \app\home\service\TemplateI18n::translate('pagination.' . $key, $default)
            : ($custom !== '' ? $custom : $default);
    };
    $texts = [
        'index' => $paginationText('indextext', 'first', '首页'),
        'pre'   => $paginationText('pretext', 'previous', '上一页'),
        'next'  => $paginationText('nexttext', 'next', '下一页'),
        'end'   => $paginationText('endtext', 'last', '末页'),
    ];

    //mini 模式只留上一页、下一页，listitem 参数忽略
    if ($type === 'mini') {
        $items = ['pre', 'next'];
    } else {
        //listitem 不传就显示全套
        $listitem = trim((string) ($opt['listitem'] ?? ''));
        if ($listitem === '') {
            $listitem = 'index,pre,pageno,next,end,info';
        }
        $items = array_filter(array_map('trim', explode(',', $listitem)));
    }

    //链接地址：默认按栏目 URL 规则生成；标签页交给 miu_tag_url 处理，
    //搜索页这类不是栏目页的页面，会在分页信息里给一个 base_url。
    $baseUrl = trim((string) ($info['base_url'] ?? ''));
    $tagId = (int) ($info['tag_id'] ?? 0);
    $url = function (int $n) use ($nav, $baseUrl, $tagId) {
        if ($tagId > 0) {
            return htmlspecialchars(miu_tag_url($tagId, $n), ENT_QUOTES);
        }
        if ($baseUrl !== '') {
            return htmlspecialchars($baseUrl . (strpos($baseUrl, '?') === false ? '?' : '&') . 'page=' . $n, ENT_QUOTES);
        }
        return htmlspecialchars(nav_url($nav, $n), ENT_QUOTES);
    };

    //新主题只作用于分页自身，不修改整站 <html> 的主题属性。
    $theme = (string) ($opt['theme'] ?? 'default');
    if (in_array($theme, ['dark', 'light'], true)) {
        $button = static function (string $label, ?string $href = null, bool $active = false) use ($opt): string {
            $currentStyle = trim((string) ($opt['currentstyle'] ?? 'active')) ?: 'active';
            $class = 'pager__btn' . ($active ? ' active' . ($currentStyle !== 'active' ? ' ' . htmlspecialchars($currentStyle, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') : '') : '');
            $text = htmlspecialchars($label, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
            if ($href === null) {
                return '<span class="' . $class . '" aria-disabled="true">' . $text . '</span>';
            }
            return '<a class="' . $class . '" href="' . $href . '"' . ($active ? ' aria-current="page"' : '') . '>' . $text . '</a>';
        };

        $buttons = '';
        foreach ($items as $item) {
            switch ($item) {
                case 'index':
                    $buttons .= $button($texts['index'], $page > 1 ? $url(1) : null);
                    break;
                case 'pre':
                    $buttons .= $button($texts['pre'], $page > 1 ? $url($page - 1) : null);
                    break;
                case 'pageno':
                    $start = max(1, $page - 2);
                    $end = min($count, $start + 4);
                    $start = max(1, $end - 4);
                    for ($i = $start; $i <= $end; $i++) {
                        $buttons .= $button((string) $i, $url($i), $i === $page);
                    }
                    break;
                case 'next':
                    $buttons .= $button($texts['next'], $page < $count ? $url($page + 1) : null);
                    break;
                case 'end':
                    $buttons .= $button($texts['end'], $page < $count ? $url($count) : null);
                    break;
            }
        }

        $infoText = $type === 'mini' || !in_array('info', $items, true)
            ? ''
            : '<p class="pager__info">第 <span>' . $page . '</span> / <span>' . $count . '</span> 页 · 共 <span>' . $total . '</span> 条记录</p>';
        $css = <<<'CSS'
.pager-scope{display:block;margin-top:30px}
.pager-scope .pager{display:flex;flex-wrap:wrap;align-items:center;justify-content:center;gap:10px;margin-top:0}
.pager-scope .pager__btn{display:inline-flex;align-items:center;justify-content:center;min-width:34px;padding:3px 13px;border-radius:999px;border:1px solid rgba(255,255,255,.18);background:rgba(29,36,49,.82);backdrop-filter:blur(10px);-webkit-backdrop-filter:blur(10px);color:#eef2f8;font-family:inherit;font-size:.88rem;line-height:1.35;font-weight:600;text-decoration:none;cursor:pointer;transition:transform .25s cubic-bezier(.2,.7,.2,1),border-color .25s cubic-bezier(.2,.7,.2,1),box-shadow .25s cubic-bezier(.2,.7,.2,1),color .25s cubic-bezier(.2,.7,.2,1),background .25s cubic-bezier(.2,.7,.2,1)}
.pager-scope .pager__btn:hover:not([disabled]):not([aria-disabled="true"]){transform:translateY(-2px);border-color:rgba(91,174,255,.72);color:#fff;box-shadow:0 8px 24px rgba(30,159,255,.25)}
.pager-scope .pager__btn.active{border-color:transparent;background:linear-gradient(135deg,#1e9fff,#6d28d9);color:#fff;box-shadow:0 8px 24px rgba(30,159,255,.3)}
.pager-scope .pager__btn[disabled],.pager-scope .pager__btn[aria-disabled="true"]{opacity:.42;cursor:not-allowed;pointer-events:none}
.pager-scope .pager__btn:focus-visible{outline:2px solid #1e9fff;outline-offset:3px}
.pager-scope .pager__info{margin:0 0 14px;text-align:center;font-size:.86rem;color:#a9b2c2}
.pager-scope--light .pager__btn{border-color:#dfe3ea;background:rgba(255,255,255,.88);color:#343b46}
.pager-scope--light .pager__btn:hover:not([disabled]):not([aria-disabled="true"]){border-color:rgba(13,111,209,.45);color:#0d6fd1;box-shadow:0 6px 18px rgba(13,111,209,.14)}
.pager-scope--light .pager__btn.active{border-color:rgba(13,111,209,.38);background:linear-gradient(135deg,rgba(13,111,209,.16),rgba(109,40,217,.13) 55%,rgba(8,145,178,.16));color:#0d6fd1;box-shadow:0 6px 18px rgba(13,111,209,.18)}
.pager-scope--light .pager__info{color:#747b86}
CSS;

        $nav = $buttons === '' ? '' : '<nav class="pager" aria-label="分页">' . $buttons . '</nav>';
        return '<style>' . $css . '</style><div class="pager-scope pager-scope--' . $theme . '">' . $infoText . $nav . '</div>';
    }

    //一个分页按钮：class 固定 miu-page，另外可以再挂一个类名（比如当前页的 currentstyle）
    $li = function (string $text, string $href, string $extra = '') {
        $cls = 'miu-page' . ($extra !== '' ? ' ' . $extra : '');
        return '<li class="' . $cls . '"><a href="' . $href . '">' . htmlspecialchars($text, ENT_QUOTES) . '</a></li>';
    };

    $body = '';

    foreach ($items as $item) {
        switch ($item) {
            case 'index':
                //第 1 页没有「首页」可去，直接不显示
                if ($page > 1) {
                    $body .= $li($texts['index'], $url(1));
                }
                break;

            case 'pre':
                //第 1 页没有「上一页」可去，直接不显示
                if ($page > 1) {
                    $body .= $li($texts['pre'], $url($page - 1));
                }
                break;

            case 'pageno':
                //页码窗口：当前页前后各 2 页，最多 5 个
                $start = max(1, $page - 2);
                $end   = min($count, $start + 4);
                $start = max(1, $end - 4);
                for ($i = $start; $i <= $end; $i++) {
                    $body .= $li((string) $i, $url($i), $i === $page ? $currentstyle : '');
                }
                break;

            case 'next':
                //最后一页没有「下一页」可去，直接不显示
                if ($page < $count) {
                    $body .= $li($texts['next'], $url($page + 1));
                }
                break;

            case 'end':
                //最后一页没有「末页」可去，直接不显示
                if ($page < $count) {
                    $body .= $li($texts['end'], $url($count));
                }
                break;

            case 'info':
                $summary = class_exists(\app\home\service\TemplateI18n::class)
                    ? \app\home\service\TemplateI18n::translate('pagination.summary', '共 {pages} 页 {items} 条')
                    : '共 {pages} 页 {items} 条';
                $summary = strtr($summary, ['{pages}' => (string) $count, '{items}' => (string) $total]);
                $body .= '<li class="miu-page miu-page-info">' . htmlspecialchars($summary, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') . '</li>';
                break;
        }
    }

    //一个按钮都没生成（比如 mini 模式正好在第 1 页或最后一页）就不用输出空壳 ul 了
    if ($body === '') {
        return '';
    }

    // ---------------- 分页样式 ----------------
    //跟分页 HTML 一起输出，包在 <style> 里。
    //每次调用都带上：控制器生成 {$page_html} 时已经调过一次本函数，
    //如果这里只输出一次，模板里再用 {miu:pagelist} 的那份就没样式了。
    //（同一个页面里出现两段一样的 <style> 是无害的，浏览器按后一段生效，CSS 是幂等的）
    //想改外观就直接改下面的 CSS；想放到模板的 css 文件里也行，把这里的 <style> 去掉、
    //把这段 CSS 复制过去即可。
    //对应结构：<ul class="miu-pagination"><li class="miu-page[ active]"><a>…</a></li>
    //          <li class="miu-page miu-page-info">共 N 页 M 条</li></ul>
    $css = <<<'CSS'
.miu-pagination{display:flex;flex-wrap:wrap;align-items:center;justify-content:center;gap:8px;margin:24px 0 0;padding:0;list-style:none}
.miu-pagination .miu-page{list-style:none}
.miu-pagination .miu-page a{display:inline-block;min-width:38px;height:38px;line-height:40px;padding:0 12px;text-align:center;font-size:14px;color:#333;background:#fff;border:1px solid #e3e6eb;border-radius:6px;text-decoration:none;transition:color .2s,background-color .2s,border-color .2s}
.miu-pagination .miu-page a:hover{color:#fff;background:#03A9F4;border-color:#03A9F4}
.miu-pagination .miu-page.active a{color:#fff;background:#03A9F4;border-color:#03A9F4;cursor:default}
.miu-pagination .miu-page-info{height:38px;line-height:36px;padding:0 12px;font-size:14px;color:#8a8f99}
CSS;

    return '<style>' . $css . '</style><ul class="miu-pagination">' . $body . '</ul>';
}

/* ==================== 前台面包屑（{miu:position} / {miu:positionraw} 标签用） ==================== */

/**
 * 取面包屑数据
 *
 * 栏目页：首页 > 一级栏目 > …… > 当前栏目
 * 内容页：首页 > 一级栏目 > …… > 所属栏目 > 文章标题
 * 搜索页：首页 >（限定了栏目时带上栏目链）> 搜索「关键词」
 * 默认最后一项（当前所在）没有 url；positionraw 可按参数启用末级链接
 *
 * @param int $typeid 栏目id 或 文章id；传 0 表示用当前页面的栏目/文章
 * @param bool $includeCurrentUrl 是否给末级面包屑生成 url 和 full_url
 * @return array [['name'=>名称, 'url'=>地址, 'full_url'=>完整地址], ...] 没有结果时返回空数组
 */
function miu_position(int $typeid = 0, bool $includeCurrentUrl = false): array
{
    $crumbs = [
        ['name' => '首页', 'url' => home_url()],
    ];

    $navChain = [];   //栏目链（从一级栏目到当前栏目）
    $tailName = '';   //最后一项的名称（文章标题 / 当前栏目名 / 搜索「关键词」）
    $tailUrl  = '';   //最后一项的地址（空 = 不可点）

    if ($typeid > 0) {
        //指定了 id：先当栏目找，找不到再当文章找
        $nav = nav_by_id($typeid);
        if ($nav) {
            $navChain = nav_ancestors((int) $nav['id']);
        } else {
            $doc = miu_cached_document($typeid);
            if ($doc) {
                $docData = is_array($doc) ? $doc : (is_object($doc) ? $doc->toArray() : []);
                $doc = \app\home\service\ContentI18n::overlayRow('document', $docData);
                $docNav   = nav_by_id((int) $doc['nav_id']);
                $navChain = $docNav ? nav_ancestors((int) $docNav['id']) : [];
                $tailName = (string) $doc['title'];
                $tailUrl  = doc_url($doc, $docNav ?: null);
            }
        }
    } else {
        //没指定：看当前页面是内容页、搜索页，还是列表页
        $curDoc   = miu_current_doc();
        $curNavId = miu_current_nav_id();
        $search   = miu_current_search();

        if (!empty($curDoc['nav_id'])) {
            $docNav   = nav_by_id((int) $curDoc['nav_id']);
            $navChain = $docNav ? nav_ancestors((int) $docNav['id']) : [];
            $tailName = (string) ($curDoc['title'] ?? '');
            $tailUrl  = doc_url($curDoc, $docNav ?: null);
        } elseif (!empty($search)) {
            //搜索页：限定了栏目就把栏目链带上（都可点），结尾是「搜索「关键词」」（不可点）
            $navChain = $curNavId ? nav_ancestors($curNavId) : [];
            $keyword  = trim((string) ($search['keyword'] ?? ''));
            $tailName = $keyword !== '' ? '搜索「' . $keyword . '」' : '搜索';
            $tailUrl  = \app\home\service\TemplateI18n::currentUrl() ?: request()->url();
        } elseif (!empty($tag = miu_current_tag())) {
            //标签页：不属于任何栏目，直接 首页 > 标签：xxx
            $tailName = '标签：' . (string) ($tag['title'] ?? '');
            $tailUrl  = \app\home\service\TemplateI18n::currentUrl() ?: request()->url();
        } elseif ($curNavId) {
            $navChain = nav_ancestors($curNavId);
            $tailName = '';   //当前就是栏目，下面会把栏目链最后一项当结尾
        }
    }

    //栏目链：有文章结尾时栏目都可点；没文章结尾时最后一个栏目就是当前项（不可点）
    $lastNavIndex = count($navChain) - 1;
    foreach ($navChain as $i => $item) {
        $isCurrent = ($tailName === '' && $i === $lastNavIndex);
        $crumbs[] = [
            'name' => (string) $item['title'],
            'url'  => $isCurrent && !$includeCurrentUrl ? '' : nav_url($item),
        ];
    }

    //文章标题放在最后，不可点
    if ($tailName !== '') {
        $crumbs[] = ['name' => $tailName, 'url' => $includeCurrentUrl ? $tailUrl : ''];
    }

    //只有「首页」一项时不给面包屑（首页本身不需要）
    if (count($crumbs) <= 1) {
        return [];
    }
    foreach ($crumbs as &$crumb) {
        $crumb['full_url'] = miu_full_url((string) $crumb['url']);
    }
    unset($crumb);
    return $crumbs;
}

/**
 * 生成面包屑 HTML（供 {miu:position} 标签用）
 *
 * 最后一项（当前所在）会带上 class="breadcrumb-item text-white active"，
 * 方便直接套 bootstrap 的 breadcrumb 样式；其它项只输出 <li>，不带 class。
 *
 * 间隔符会先去掉首尾空白，输出时在两边各补一个 &nbsp; 占位
 * （<span class="delimiter">&nbsp;&gt;&nbsp;</span>），
 * 所以 delimiter='/' 和 delimiter=' / ' 出来的效果是一样的。
 * 用 &nbsp; 而不是普通空格：普通空格在 HTML 里会被折叠，间距出不来。
 *
 * @param array $opt 标签参数：
 *   typeid    栏目id 或 文章id，默认当前栏目/文章
 *   class     ul 的样式类名，默认 position
 *   delimiter 间隔符，默认 >（输出时两边自动各加一个 &nbsp;）
 * @return string
 */
function miu_position_html(array $opt = []): string
{
    $crumbs = miu_position((int) ($opt['typeid'] ?? 0));
    if (empty($crumbs)) {
        return '';
    }

    $class     = (string) ($opt['class'] ?? 'position');
    //间隔符先去掉首尾空白，输出时统一在两边各补一个 &nbsp; 占位
    $delimiter = trim((string) ($opt['delimiter'] ?? '>'));

    $html    = '<ul class="' . htmlspecialchars($class, ENT_QUOTES) . '">';
    $lastIdx = count($crumbs) - 1;

    foreach ($crumbs as $i => $item) {
        $name = htmlspecialchars((string) $item['name'], ENT_QUOTES);
        if (!empty($item['full_url'])) {
            $name = '<a href="' . htmlspecialchars((string) $item['full_url'], ENT_QUOTES) . '">' . $name . '</a>';
        }

        //最后一项是「当前所在」，单独加一套 class
        $liClass = ($i === $lastIdx) ? ' class="breadcrumb-item text-white active"' : '';

        $html .= '<li' . $liClass . '>' . $name;
        //最后一项后面不加间隔符；两边各补一个 &nbsp; 占位
        //（用普通空格不行：HTML 里连续空白会被折叠成一个，看着还是挨在一起）
        if ($i < $lastIdx) {
            $html .= '<span class="delimiter">&nbsp;' . htmlspecialchars($delimiter, ENT_QUOTES) . '&nbsp;</span>';
        }
        $html .= '</li>';
    }

    $html .= '</ul>';

    return $html;
}

/* ==================== 前台标签（{miu:tag} 标签用） ==================== */

/**
 * 取标签列表（供 {miu:tag} 标签用）
 *
 * 数据来自 cms_keywords 表（后台「基础 → 标签管理」）。
 *
 * @param array $opt
 *   gettype 0=当前内容页的标签（默认）1=全部标签
 *   num     取几个，默认 0 = 全部
 *   typeid  只要某个标签分类的标签（cms_keywords_group 的 id），默认 0 = 全部分类
 *   sort    new=按最新 / rand=随机 / week=按周统计 / month=按月统计 /
 *           hot=按点击数（默认）/ total=按文档数
 * @return array 标签列表（每个标签带了 url / full_url / count / date 这些模板常用字段）
 */
function miu_tag_list(array $opt = []): array
{
    $gettype = (int) ($opt['gettype'] ?? 0);
    $num     = (int) ($opt['num'] ?? 0);
    $typeid  = (int) ($opt['typeid'] ?? 0);
    $sort    = strtolower(trim((string) ($opt['sort'] ?? '')));
    if ($sort === '') {
        $sort = 'hot';
    }

    //gettype=0 是「当前内容页的标签」：不在内容页就没有，直接空
    $curDoc = miu_current_doc();
    if ($gettype === 0 && empty($curDoc['id'])) {
        return [];
    }

    //全部标签 + 每个标签的文档数（总数 / 近7天 / 近30天），一次查出来缓存住。
    //缓存标记：homeTag（后台改标签会清）+ Document（发布/编辑文档会清，因为文档数会变）
    $all = get_cache('homeTagList');
    //缓存里没有 group_id 说明是「加 typeid 参数之前」存的旧结构，丢掉重建一次
    if (!is_array($all) || (!empty($all) && !array_key_exists('group_id', (array) reset($all)))) {
        $all = miu_tag_list_build();
        \think\facade\Cache::tag(['homeTag', 'Document'])->set('homeTagList', $all, 86400);
    }

    $list = \app\home\service\ContentI18n::overlayRows('tag', $all);

    //typeid：只要指定标签分类下的标签；留空就是全部分类
    if ($typeid > 0) {
        $kept = [];
        foreach ($list as $item) {
            if ((int) ($item['group_id'] ?? 0) === $typeid) {
                $kept[] = $item;
            }
        }
        $list = $kept;
    }

    //只留当前文档带的标签（tag_ids 存的是「,1,2,」）
    if ($gettype === 0) {
        $tagIds = array_filter(array_map('intval', explode(',', (string) ($curDoc['tag_ids'] ?? ''))));
        $kept   = [];
        foreach ($list as $item) {
            if (in_array((int) $item['id'], $tagIds, true)) {
                $kept[] = $item;
            }
        }
        $list = $kept;
    }

    //点击数单独取（短缓存 60 秒）：标签页每被访问一次 click 就 +1，
    //要是跟「文档数」那种一天缓存放一起，{miu:tag} 里显示的点击数会明显滞后，
    //sort='hot' 也就不准了。60 秒够挡掉高频刷新，又能让点击数基本实时。
    $clicks = miu_tag_click_map();
    foreach ($list as &$item) {
        $item['click'] = (int) ($clicks[$item['id']] ?? $item['click']);
    }
    unset($item);

    //排序。PHP 8 的排序是稳定的，基础数据按「sort 倒序、id 升序」排好，
    //所以同分的标签顺序是固定的，不会每次刷新都跳。
    switch ($sort) {
        case 'new':
            usort($list, function ($a, $b) {
                return $b['add_time'] <=> $a['add_time'];
            });
            break;
        case 'rand':
            //随机排序不能靠缓存：缓存里存的是固定顺序，这里读出来再打乱
            shuffle($list);
            break;
        case 'week':
            usort($list, function ($a, $b) {
                return $b['week_count'] <=> $a['week_count'];
            });
            break;
        case 'month':
            usort($list, function ($a, $b) {
                return $b['month_count'] <=> $a['month_count'];
            });
            break;
        case 'total':
            usort($list, function ($a, $b) {
                return $b['count'] <=> $a['count'];
            });
            break;
        case 'hot':
        default:
            usort($list, function ($a, $b) {
                return $b['click'] <=> $a['click'];
            });
            break;
    }

    if ($num > 0) {
        $list = array_slice($list, 0, $num);
    }

    //补模板常用字段（只补在返回的这一份上，缓存里保持数据库原始行）
    foreach ($list as &$item) {
        $item['url']      = miu_tag_url((int) $item['id']);
        $item['full_url'] = miu_full_url($item['url']);
        $item['date']     = !empty($item['add_time']) ? date('Y-m-d', (int) $item['add_time']) : '';
    }
    unset($item);

    return $list;
}

/** 读取已启用标签的点击数；计数单独短缓存，避免标签元数据的长缓存影响热度排序。 */
function miu_tag_click_map(): array
{
    $clicks = get_cache('homeTagClick');
    if (!is_array($clicks)) {
        $clicks = \think\facade\Db::name('keywords')->where('status', 1)->column('click', 'id');
        \think\facade\Cache::tag('homeTag')->set('homeTagClick', $clicks, 60);
    }
    return $clicks;
}

/** 取一个启用标签的原始行，缓存里不放点击数。 */
function miu_cached_tag(int $tagId): array
{
    $tagId = max(0, $tagId);
    if ($tagId < 1) {
        return [];
    }

    $cacheKey = 'homeTagRow_' . $tagId;
    $tag      = get_cache($cacheKey);
    if (!is_array($tag)) {
        $tag = \think\facade\Db::name('keywords')->where('id', $tagId)->where('status', 1)->find();
        $tag = is_array($tag) ? $tag : (is_object($tag) ? $tag->toArray() : []);
        if ($tag) {
            unset($tag['click']);
        }
        \think\facade\Cache::tag('homeTag')->set($cacheKey, $tag, 86400);
    }

    if ($tag) {
        $clicks = miu_tag_click_map();
        $tag['click'] = (int) ($clicks[$tagId] ?? 0);
    }
    return $tag;
}

/** 标签页访问后的显示值；缓存命中时不延长汇总缓存的有效期。 */
function miu_tag_click_after_increment(int $tagId): int
{
    $tagId  = max(0, $tagId);
    $clicks = get_cache('homeTagClick');
    if (!is_array($clicks)) {
        $clicks = \think\facade\Db::name('keywords')->where('status', 1)->column('click', 'id');
        \think\facade\Cache::tag('homeTag')->set('homeTagClick', $clicks, 60);
        return (int) ($clicks[$tagId] ?? 0);
    }

    return (int) ($clicks[$tagId] ?? 0) + 1;
}

/**
 * 查「所有标签 + 各自的文档数」（miu_tag_list() 的缓存来源）
 *
 * 文档和标签的关联是 cms_document.tag_ids（「,1,2,」这种），
 * 所以用 FIND_IN_SET 关联：一次查询就把总数、近7天、近30天都算出来。
 * 注意 FIND_IN_SET 用不上索引，文档量大时这条会慢 —— 所以结果是缓存住的（1 天）。
 *
 * @return array
 */
function miu_tag_list_build(): array
{
    $kwTable  = \think\facade\Db::name('keywords')->getTable();
    $docTable = \think\facade\Db::name('document')->getTable();

    //近 7 天 / 近 30 天（含今天）
    $week  = strtotime(date('Y-m-d', strtotime('-6 day')));
    $month = strtotime(date('Y-m-d', strtotime('-29 day')));

    $rows = \think\facade\Db::query(
        "SELECT k.id, k.title, k.group_id, k.sort, k.click, k.status, k.add_time, k.update_time,
                COUNT(d.id) AS cnt,
                SUM(CASE WHEN d.add_time >= {$week}  THEN 1 ELSE 0 END) AS week_cnt,
                SUM(CASE WHEN d.add_time >= {$month} THEN 1 ELSE 0 END) AS month_cnt
         FROM {$kwTable} k
         LEFT JOIN {$docTable} d
                ON FIND_IN_SET(k.id, d.tag_ids) AND d.is_del = 0 AND d.status = 1 AND d.arcrank >= 0
         WHERE k.status = 1
         GROUP BY k.id, k.title, k.group_id, k.sort, k.click, k.status, k.add_time, k.update_time
         ORDER BY k.sort DESC, k.id ASC"
    );

    $list = [];
    foreach ($rows as $r) {
        $list[] = [
            'id'          => (int) $r['id'],
            'title'       => (string) $r['title'],
            'group_id'    => (int) $r['group_id'],
            'sort'        => (int) $r['sort'],
            'click'       => (int) $r['click'],
            'status'      => (int) $r['status'],
            'add_time'    => (int) $r['add_time'],
            'update_time' => (int) $r['update_time'],
            'count'       => (int) $r['cnt'],
            'week_count'  => (int) $r['week_cnt'],
            'month_count' => (int) $r['month_cnt'],
        ];
    }

    return $list;
}

/* ==================== 前台友情链接（{miu:link} 标签用） ==================== */

/**
 * 取友情链接列表（供 {miu:link} 标签用）
 *
 * 数据来自 cms_links 表（后台「友情链接」）。
 * 只取「可用」的：status = 1（后台删除是逻辑删除，会把 status 置成 -1）。
 * 排序跟后台那个列表保持一致：sort 倒序，再按 id 倒序。
 *
 * 每条除了表里的字段，还额外补了这几个（模板里直接用）：
 *   url          链接地址（= src，跟栏目/文档统一叫 url，模板好写）
 *   full_url     带网站域名的完整链接（外链原样保留）
 *   logo_url     网站 logo 的图片地址（logo 存的是附件id，这里解析成地址；没设就空串）
 *   target_blank target == 1（是否新窗口打开），模板里 {if condition="$vo.target_blank"}target="_blank"{/if} 更直观
 *   date         create_time 的 Y-m-d
 *
 * @return array 数据库异常时返回空数组（标签内部就不输出，不会把页面搞成 500）
 */
function miu_link_list(): array
{
    //持久缓存（标签 homeLink）：后台友情链接增删改完会 clear_cache('homeLink') 清掉。
    //函数内再兜一层 static，同一个页面里用多次只读一次缓存
    static $list = null;
    if ($list === null) {
        $list = get_cache('homeLink');
        if (!is_array($list)) {
            $list = [];
            try {
                $rows = \think\facade\Db::name('links')
                    ->where('status', 1)
                    ->order('sort desc, id desc')
                    ->select()
                    ->toArray();
            } catch (\Throwable $e) {
                //数据库异常时返回空列表，标签内部就不输出，不会把页面搞成 500
                return [];
            }

            foreach ($rows as $row) {
                $row['url']          = (string) ($row['src'] ?? '');
                $row['logo_url']     = miu_link_logo_url($row['logo'] ?? '');
                $row['target_blank'] = ((int) ($row['target'] ?? 0) === 1) ? 1 : 0;
                $row['date']         = !empty($row['create_time']) ? date('Y-m-d', (int) $row['create_time']) : '';

                $list[] = $row;
            }

            //用 tag 存，后台的 clear_cache('homeLink') 才能清掉它
            \think\facade\Cache::tag('homeLink')->set('homeLink', $list, 86400);
        }
    }

    $result = $list;
    foreach ($result as &$row) {
        $row['full_url'] = miu_full_url((string) ($row['url'] ?? ''));
    }
    unset($row);
    return $result;
}

/**
 * 友情链接的 logo 转成图片地址
 *
 * logo 字段兼容两种存法（跟后台 get_file() 一致）：附件表 id，或者直接是图片地址。
 * 没有 logo（0 / 空）、或者解析失败，都返回空字符串，模板里判断一下要不要输出 <img>。
 *
 * @param mixed $logo
 * @return string
 */
function miu_link_logo_url($logo): string
{
    //0 / '0' / '' / null 都是「没设 logo」
    if ($logo === null || $logo === '' || $logo === 0 || $logo === '0') {
        return '';
    }

    try {
        $url = get_file($logo);
        return is_string($url) ? $url : '';
    } catch (\Throwable $e) {
        //附件记录不存在时 get_file() 可能抛错，这里兜住，别影响整个页面
        return '';
    }
}

/* ==================== 前台自定义变量（{miu:custom} 标签用） ==================== */

/**
 * 按标识取一个自定义变量（cms_custom）
 *
 * 缓存：跨请求按语言保存（缓存键为 custom_<语言>，标签为 custom），后台改完变量会
 * clear_cache('custom') 清掉。所以这里改了值刷新前台就生效。
 * 注意必须用 Cache::tag('custom') 存 —— clear_cache() 清的是「标签」，
 * 不带 tag 存的话后台清不掉，前台会一直显示旧值。
 * 另外函数内再兜一层 static，同一个请求里用多次只读一次缓存。
 *
 * status = -1 是后台逻辑删除的，不返回。
 *
 * @param string $name 变量标识
 * @return array ['found'=>找没找到, 'status'=>状态 1启用/0停用, 'value'=>变量值]
 */
function miu_custom(string $name): array
{
    static $map = null;

    if ($map === null) {
        $locale = \app\home\service\ContentI18n::locale();
        $cacheKey = 'custom_' . $locale;
        $map = get_cache($cacheKey);

        //缓存没命中（或不是数组）才查库
        if (!is_array($map)) {
            $map = [];
            try {
                $rows = \think\facade\Db::name('custom')
                    //-1 是后台删除的，不参与
                    ->where('status', '>=', 0)
                    ->select()
                    ->toArray();
                foreach ($rows as $row) {
                    $map[(string) $row['name']] = $row;
                }
            } catch (\Throwable $e) {
                //数据库异常时当作「没有变量」，标签会输出提示，不会把页面搞成 500
                $map = [];
            }

            $rows = \app\home\service\ContentI18n::overlayRows('custom', array_values($map));
            $map = [];
            foreach ($rows as $row) {
                $map[(string) $row['name']] = $row;
            }

            //用 tag 存，后台的 clear_cache('custom') 才能清掉它
            \think\facade\Cache::tag('custom')->set($cacheKey, $map, 86400);
        }
    }

    if (!isset($map[$name])) {
        return ['found' => false, 'status' => 0, 'value' => ''];
    }

    $row = $map[$name];

    return [
        'found'  => true,
        'status' => (int) $row['status'],
        'value'  => (string) ($row['default_value'] ?? ''),
    ];
}

/**
 * {miu:custom} 标签最终要输出的内容（渲染时调用，不是编译时）
 *
 * 为什么放在渲染时：编译时查库的话，站长改了变量值就得重新编译模板才生效。
 * 这里只把「变量名」编译进模板，值每次渲染现取。
 *
 * 返回的就是要输出到页面的字符串：
 *   变量不存在 / 已禁用 → 返回一句提示（提示已经转义好，页面上能看到原因）
 *   变量正常            → 返回值（raw=true 时原样输出，否则做 HTML 转义）
 *
 * @param string      $name    变量标识
 * @param string|null $default 值为空时用它顶替（没写 default 就传 null）
 * @param bool        $raw     true = 不转义
 * @return string
 */
function miu_custom_output(string $name, ?string $default = null, bool $raw = false): string
{
    $var = miu_custom($name);

    if (!$var['found']) {
        return htmlspecialchars('自定义变量[' . $name . ']不存在，去后台「基础 → 自定义变量」加一个', ENT_QUOTES);
    }
    if ($var['status'] !== 1) {
        return htmlspecialchars('自定义变量[' . $name . ']已禁用，去后台「基础 → 自定义变量」把它启用', ENT_QUOTES);
    }

    $value = $var['value'];

    //值为空时才用 default 顶替。只能用 === '' 判断，不能用 empty()：
    //'0' 是有效值（比如开关类型的「关」），不该被默认值顶掉
    if ($value === '' && $default !== null) {
        $value = $default;
    }

    return $raw ? $value : htmlspecialchars($value, ENT_QUOTES);
}

/* ==================== 前台文档列表页 / 详情页渲染 ==================== */

/**
 * 前台提示页
 *
 * 栏目/文档/模板找不到时不抛异常（避免整站 500），而是显示一句人话，
 * 模板作者一眼能看出是哪个文件没放对
 *
 * @param string $title   标题
 * @param string $message 正文（允许 HTML，调用方自行拼好）
 * @param int    $status  HTTP 状态码
 * @return \think\response\View|\think\response\Html
 */
function miu_front_notice(string $title, string $message, int $status = 404)
{
    $html = '<!DOCTYPE html><html lang="zh-CN"><head><meta charset="utf-8">'
        . '<meta name="viewport" content="width=device-width,initial-scale=1">'
        . '<title>' . htmlspecialchars($title, ENT_QUOTES) . '</title>'
        . '<style>body{margin:0;padding:60px 20px;background:#f7f8fa;color:#333;'
        . 'font:14px/1.9 -apple-system,BlinkMacSystemFont,"Microsoft YaHei",sans-serif}'
        . '.box{max-width:760px;margin:0 auto;background:#fff;border:1px solid #eceef0;'
        . 'border-radius:8px;padding:28px 30px}'
        . 'h1{font-size:17px;margin:0 0 14px;color:#e03e2d;font-weight:600}'
        . 'p{margin:8px 0}code{background:#f5f6f8;padding:1px 6px;border-radius:3px;'
        . 'font-family:Consolas,Monaco,monospace;color:#c7254e;word-break:break-all}'
        . '</style></head><body><div class="box"><h1>' . htmlspecialchars($title, ENT_QUOTES) . '</h1>'
        . $message . '</div></body></html>';

    return response($html, $status)->contentType('text/html; charset=utf-8');
}

/**
 * 模板文件名是否合法、并且真的存在
 *
 * 模板名只允许字母数字下划线短横线（view_depr 是下划线，所以模板文件都在模板根目录下），
 * 挡掉 ../ 这类穿越，也挡掉模板里写的奇怪字符
 *
 * @param string $name 模板名（不带 .html）
 * @return bool
 */
function miu_template_exists(string $name): bool
{
    if (!preg_match('/^[A-Za-z0-9_\-]{1,64}$/', $name)) {
        return false;
    }
    $file = get_templates_path(get_current_template()) . $name . '.html';
    return is_file($file);
}

/**
 * 栏目页该用哪个模板（栏目单独指定的 > list_<模型nid>），没有返回空串
 *
 * 渲染（miu_render_nav_list）和「生成静态」的模板预检都走这里，保证两边判断一致。
 *
 * @param array $nav
 * @param array $channel
 * @return string 模板名（不带 .html），空串表示没有可用模板
 */
function miu_nav_list_tpl(array $nav, array $channel): string
{
    $nid = (string) ($channel['nid'] ?? '');

    $tpl = preg_replace('/[^A-Za-z0-9_\-]/', '_', (string) ($nav['temp_list'] ?? ''));
    if ($tpl !== '' && miu_template_exists($tpl)) {
        return $tpl;
    }
    if ($nid !== '' && miu_template_exists('list_' . $nid)) {
        return 'list_' . $nid;
    }

    return '';
}

/**
 * 文档详情页该用哪个模板（栏目单独指定的 > view_<模型nid> > view_default），没有返回空串
 *
 * @param array $nav
 * @param array $channel
 * @return string
 */
function miu_doc_view_tpl(array $nav, array $channel): string
{
    $nid = (string) ($channel['nid'] ?? '');

    $tpl = preg_replace('/[^A-Za-z0-9_\-]/', '_', (string) ($nav['temp_view'] ?? ''));
    if ($tpl !== '' && miu_template_exists($tpl)) {
        return $tpl;
    }

    $candidates = $nid !== '' ? ['view_' . $nid, 'view_default'] : ['view_default'];
    foreach ($candidates as $name) {
        if (miu_template_exists($name)) {
            return $name;
        }
    }

    return '';
}

/**
 * 栏目页「期望的模板文件名」，只用于报错文案
 *
 * @param string $nid 模型标识
 * @return string
 */
function miu_expect_list_tpl(string $nid): string
{
    return $nid !== '' ? 'list_' . $nid . '.html' : 'list_<模型nid>.html';
}

/**
 * 详情页「期望的模板文件名」，只用于报错文案
 *
 * @param string $nid 模型标识
 * @return string
 */
function miu_expect_view_tpl(string $nid): string
{
    return $nid !== '' ? 'view_' . $nid . '.html' : 'view_<模型nid>.html';
}

/**
 * 「正在批量生成静态页面」的开关（只在当前请求内有效）
 *
 * 生成静态时会把每篇文档都渲染一遍，渲染函数里有「访问量 +1」，
 * 不关掉的话生成一次全站，每篇文档的访问量都白涨一次。生成入口在开始前打开、结束后关掉。
 *
 * @param bool|null $set 传 true/false 表示设置，传 null 表示只读取
 * @return bool
 */
function miu_building_static(?bool $set = null): bool
{
    static $on = false;
    if ($set !== null) {
        $on = $set;
    }
    return $on;
}

/**
 * 取某个模型的自定义字段标识（cms_channel_field 里 is_system=0 且启用的）
 *
 * 附加表里只查这些字段 —— 系统字段（title/litpic/add_time…）主表已经有了，不用重复取。
 * 请求内 static 缓存，同一个模型只查一次。
 *
 * @param int $channelId
 * @return array 字段标识，如 ['price','brand']
 */
function miu_channel_custom_fields(int $channelId): array
{
    static $cache = [];
    $channelId = (int) $channelId;
    if ($channelId < 1) {
        return [];
    }
    if (!isset($cache[$channelId])) {
        $cacheKey = 'homeChannelFields_' . $channelId;
        $cached   = get_cache($cacheKey);
        if (is_array($cached)) {
            return $cache[$channelId] = $cached;
        }
        try {
            $cache[$channelId] = \think\facade\Db::name('channel_field')
                ->where(['channel_id' => $channelId, 'is_system' => 0, 'status' => 1])
                ->order('sort asc,id asc')
                ->column('name');
        } catch (\Throwable $e) {
            $cache[$channelId] = [];
            return $cache[$channelId];
        }
        \think\facade\Cache::tag('homeChannel')->set($cacheKey, $cache[$channelId], 86400);
    }
    return $cache[$channelId];
}

/** 读取模型中类型为「文件下载」的字段标识，供详情页整理下载列表。 */
function miu_channel_download_fields(int $channelId): array
{
    static $cache = [];
    $channelId = (int) $channelId;
    if ($channelId < 1) {
        return [];
    }
    if (!array_key_exists($channelId, $cache)) {
        $cacheKey = 'homeChannelDownloadFields_' . $channelId;
        $cached = get_cache($cacheKey);
        if (is_array($cached)) {
            return $cache[$channelId] = $cached;
        }
        try {
            $cache[$channelId] = \think\facade\Db::name('channel_field')
                ->where(['channel_id' => $channelId, 'is_system' => 0, 'status' => 1, 'type' => 'download'])
                ->order('sort asc,id asc')
                ->column('name');
        } catch (\Throwable $e) {
            $cache[$channelId] = [];
            return $cache[$channelId];
        }
        \think\facade\Cache::tag('homeChannel')->set($cacheKey, $cache[$channelId], 86400);
    }
    return $cache[$channelId];
}

/**
 * 批量取一批文档的「附加表自定义字段」，按文档id返回
 *
 * 前台列表默认就要带模型的自定义字段，这里按「模型」分组，一个模型一条 SQL
 * （doc_id IN 这批id），不会每条文档查一次。只查 cms_channel_field 里登记的自定义字段
 * （is_system=0、status=1），系统字段主表已经有了；content 正文也不取（列表用不上，省性能）。
 *
 * @param array $rows 主表 cms_document 的行（需要 id、channel_id 两个字段）
 * @return array [文档id => [字段名=>值, ...], ...]
 */
function miu_doc_extras(array $rows, bool $usePersistentCache = true): array
{
    if (!$rows) {
        return [];
    }

    //按模型分组，收集各自的文档id
    $byChannel = [];
    foreach ($rows as $row) {
        $cid = (int) ($row['channel_id'] ?? 0);
        $did = (int) ($row['id'] ?? 0);
        if ($cid > 0 && $did > 0) {
            $byChannel[$cid][] = $did;
        }
    }
    if (!$byChannel) {
        return [];
    }

    $map = [];
    foreach ($byChannel as $cid => $docIds) {
        $channel = get_channel((int) $cid);
        $table   = (string) ($channel['table'] ?? '');
        if ($table === '') {
            continue;
        }
        $fields = miu_channel_custom_fields((int) $cid);
        if (!$fields) {
            continue;
        }

        $docIds = array_values(array_unique(array_map('intval', $docIds)));
        sort($docIds, SORT_NUMERIC);
        $cacheKey = 'homeDocExtras_' . md5($cid . '|' . $table . '|' . implode(',', $fields) . '|' . implode(',', $docIds));
        $extraMap = $usePersistentCache ? get_cache($cacheKey) : null;
        if (!is_array($extraMap)) {
            $extraMap = [];
            try {
                $extraRows = \think\facade\Db::name($table)
                    ->whereIn('doc_id', $docIds)
                    ->field(array_merge(['doc_id'], $fields))
                    ->select()
                    ->toArray();
            } catch (\Throwable $e) {
                //字段和表结构对不上（极少见）时跳过这个模型，不要连累整个列表
                continue;
            }

            foreach ($extraRows as $er) {
                $did = (int) ($er['doc_id'] ?? 0);
                unset($er['doc_id']);
                if ($did > 0) {
                    $extraMap[$did] = $er;
                }
            }
            if ($usePersistentCache) {
                \think\facade\Cache::tag(['Document', 'homeChannel'])->set($cacheKey, $extraMap, 86400);
            }
        }
        foreach ($extraMap as $did => $row) {
            $map[(int) $did] = $row;
        }
    }

    return $map;
}

/**
 * 把一批主表行合上各自的附加表自定义字段（列表默认带自定义字段用）
 *
 * @param array $rows 主表 cms_document 的行
 * @return array 合并后的行（主表字段优先）
 */
function miu_doc_merge_extras(array $rows, bool $usePersistentCache = true): array
{
    if (!$rows) {
        return $rows;
    }
    $rows = \app\home\service\ContentI18n::overlayRows('document', $rows);
    $extras = miu_doc_extras($rows, $usePersistentCache);
    if ($extras) {
        $extras = \app\home\service\ContentI18n::overlayDocumentExtras($rows, $extras, 'doc_model');
    }
    foreach ($rows as &$row) {
        $did = (int) ($row['id'] ?? 0);
        if ($did > 0 && !empty($extras[$did])) {
            //用 + 而不是 array_merge：主表字段优先，附加表的同名字段不会顶掉主表
            $row = $row + $extras[$did];
        }
    }
    unset($row);
    return $rows;
}

/**
 * 给一篇文档补上模板里常用的字段
 *
 * @param array $doc   文档数据
 * @param array $nav   所属栏目
 * @param array $extra 额外字段（比如 content）
 * @return array
 */
function miu_doc_row(array $doc, array $nav = [], array $extra = []): array
{
    $doc = \app\home\service\ContentI18n::overlayRow('document', $doc);
    if ($extra && !empty($doc['id'])) {
        $channel = get_channel((int) ($doc['channel_id'] ?? 0));
        $table = (string) ($channel['table'] ?? '');
        if ($table !== '') {
            $extra = \app\home\service\ContentI18n::overlayModelRow('doc_model', $table, (int) $doc['id'], $extra);
        }
    }
    //用 + 而不是 array_merge：附加表（cms_article 等）也有 id、add_time 这些同名字段，
    //合并时必须让主表 cms_document 的值优先，否则文档 id 会被附加表的行 id 顶掉
    $doc = $doc + $extra;

    if (array_key_exists('imgs', $doc)) {
        $rawImgs = $doc['imgs'];
        if (is_string($rawImgs)) {
            $rawImgs = trim($rawImgs);
            if ($rawImgs === '') {
                $images = [];
            } else {
                $images = json_decode($rawImgs, true);
                if (json_last_error() !== JSON_ERROR_NONE) {
                    $images = array_map(
                        static fn($src) => ['src' => $src, 'name' => ''],
                        array_filter(explode('|', $rawImgs))
                    );
                } elseif (is_array($images) && isset($images['src'])) {
                    $images = [$images];
                }
            }
        } else {
            $images = is_array($rawImgs) ? $rawImgs : [];
        }

        $doc['imgs'] = array_values(array_filter(array_map(static function ($image) {
            if (is_string($image)) {
                $image = ['src' => $image];
            }
            if (!is_array($image)) {
                return null;
            }
            $src = trim((string) ($image['src'] ?? ''));
            return $src === '' ? null : ['src' => $src, 'name' => (string) ($image['name'] ?? '')];
        }, is_array($images) ? $images : [])));
    }
    $doc['url']           = doc_url($doc, $nav ?: null);
    $doc['add_time_text'] = !empty($doc['add_time']) ? date('Y-m-d H:i:s', (int) $doc['add_time']) : '';
    $doc['date']          = !empty($doc['add_time']) ? date('Y-m-d', (int) $doc['add_time']) : '';
    $doc['nav_name']      = $nav['title'] ?? '';
    $doc['nav_url']       = $nav ? nav_url($nav) : '';
    //标题颜色、加粗这类后台字段，模板里可以直接用 {$vo.title_color}
    $doc['title_style']   = trim(
        (!empty($doc['title_color']) ? 'color:' . $doc['title_color'] . ';' : '')
        . (!empty($doc['is_b']) ? 'font-weight:bold;' : '')
    );

    return $doc;
}

/** 将视频字段的播放组和“集数$播放地址”文本转换为模板可循环的数组。 */
function miu_doc_video_list(mixed $raw): array
{
    if (is_string($raw)) {
        $raw = trim($raw);
        if ($raw === '') {
            return [];
        }
        $groups = json_decode($raw, true);
        if (json_last_error() !== JSON_ERROR_NONE) {
            $groups = [['name' => '播放组1', 'urls' => $raw]];
        }
    } else {
        $groups = $raw;
    }

    if (!is_array($groups)) {
        return [];
    }
    if (isset($groups['urls'])) {
        $groups = [$groups];
    }

    $playerOptions = config('player.players', []);
    $defaultPlayer = (string) config('player.default', 'html5');
    if (!is_array($playerOptions)) {
        $playerOptions = [];
    }
    if (!isset($playerOptions[$defaultPlayer])) {
        $defaultPlayer = 'html5';
    }

    $videos = [];
    foreach ($groups as $group) {
        if (is_string($group)) {
            $group = ['urls' => $group];
        }
        if (!is_array($group) || !is_string($group['urls'] ?? null)) {
            continue;
        }

        $name = is_scalar($group['name'] ?? null) ? trim(strip_tags((string) $group['name'])) : '';
        $player = is_string($group['player'] ?? null) && isset($playerOptions[$group['player']])
            ? $group['player']
            : $defaultPlayer;
        $episodes = [];
        foreach (preg_split('/\r\n|\n|\r/', $group['urls']) ?: [] as $line) {
            $line = trim($line);
            if ($line === '') {
                continue;
            }

            $separator = strpos($line, '$');
            $title = $separator === false ? '' : trim(substr($line, 0, $separator));
            $url = trim($separator === false ? $line : substr($line, $separator + 1));
            if ($title === '') {
                $title = '第' . (count($episodes) + 1) . '集';
            }
            if ($url === '' || strlen($url) > 2048 || preg_match('/[\x00-\x20\x7f]/', $url)) {
                continue;
            }

            $external = preg_match('#^https?://#i', $url) === 1;
            if ($external) {
                $parts = parse_url($url);
                if (!is_array($parts) || empty($parts['host']) || !in_array(strtolower((string) ($parts['scheme'] ?? '')), ['http', 'https'], true)) {
                    continue;
                }
            } elseif ($url[0] !== '/' || str_starts_with($url, '//')) {
                continue;
            }

            $episodes[] = ['title' => $title, 'url' => $url];
        }

        if ($episodes) {
            $videos[] = [
                'name' => $name !== '' ? $name : '播放组' . (count($videos) + 1),
                'player' => $player,
                'episodes' => $episodes,
            ];
        }
    }

    return $videos;
}

/** 将模型的文件下载字段转换成模板可循环且已过滤的列表。 */
function miu_doc_download_list(array $doc, int $channelId): array
{
    $downloads = [];
    foreach (miu_channel_download_fields($channelId) as $field) {
        $raw = $doc[$field] ?? '';
        if (is_string($raw)) {
            $raw = trim($raw);
            if ($raw === '') {
                continue;
            }
            $items = json_decode($raw, true);
            if (json_last_error() !== JSON_ERROR_NONE) {
                $items = array_map(static fn($url) => ['url' => $url], array_filter(explode('|', $raw), 'strlen'));
            }
        } else {
            $items = $raw;
        }

        if (!is_array($items)) {
            continue;
        }
        if (isset($items['url']) || isset($items['path'])) {
            $items = [$items];
        }
        foreach ($items as $item) {
            if (is_string($item)) {
                $item = ['url' => $item];
            }
            if (!is_array($item)) {
                continue;
            }

            $url = trim((string) ($item['url'] ?? $item['path'] ?? ''));
            if ($url === '' || strlen($url) > 2048 || preg_match('/[\x00-\x20\x7f]/', $url)) {
                continue;
            }
            $external = preg_match('#^https?://#i', $url) === 1;
            if ($external) {
                $parts = parse_url($url);
                if (!is_array($parts) || empty($parts['host']) || !in_array(strtolower((string) ($parts['scheme'] ?? '')), ['http', 'https'], true)) {
                    continue;
                }
            } elseif ($url[0] !== '/' || str_starts_with($url, '//')) {
                continue;
            }

            $buttonName = trim((string) ($item['btn_name'] ?? ''));
            $fileType = strtoupper((string) pathinfo((string) (parse_url($url, PHP_URL_PATH) ?? ''), PATHINFO_EXTENSION));
            if (!preg_match('/^[A-Z0-9]{1,8}$/', $fileType)) {
                $fileType = $external ? 'LINK' : 'FILE';
            }
            $size = trim((string) ($item['size'] ?? ''));
            if ($size !== '' && is_numeric($size)) {
                $bytes = max(0, (float) $size);
                $units = ['B', 'KB', 'MB', 'GB', 'TB'];
                $unit = 0;
                while ($bytes >= 1024 && $unit < count($units) - 1) {
                    $bytes /= 1024;
                    $unit++;
                }
                $size = ($unit === 0
                    ? (string) (int) round($bytes)
                    : rtrim(rtrim(number_format($bytes, 2, '.', ''), '0'), '.')) . ' ' . $units[$unit];
            }
            $downloads[] = [
                'url' => $url,
                'index' => count($downloads),
                'endpoint' => url_entry() . '/home/api/download?id=' . (int) ($doc['id'] ?? 0) . '&index=' . count($downloads),
                'btn_name' => $buttonName !== '' ? $buttonName : ($external ? '打开链接' : '立即下载'),
                'file_type' => $fileType,
                'code' => trim((string) ($item['code'] ?? '')),
                'size' => $size,
                'external' => $external ? 1 : 0,
            ];
        }
    }
    return $downloads;
}

/**
 * 取单页或留言栏目附加表中的栏目内容
 *
 * 正文（content）存在「按模型建的附加表」里，并且用「栏目id」当 doc_id —— 后台
 * 「栏目管理 → 编辑栏目内容」（Nav::content()）就是按 doc_id = 栏目id 存的，这里保持一致。
 *
 * 返回的是「栏目字段 + 附加表字段」合并后的数组，所以模板里：
 *   {$doc.content} 和 {$nav.content} 都能取到正文
 *   {$doc.name} / {$doc.seo_title} 这类栏目字段也都在
 * 附加表没内容时返回栏目本身（content 为空），不会返回 null，模板不会报错。
 *
 * @param int    $navId 栏目id
 * @param array  $nav   栏目数据
 * @param string $table 内容附加表名（cms_channel.table）
 * @return array
 */
function miu_single_doc(int $navId, array $nav, string $table): array
{
    $extra = [];
    if ($table !== '') {
        $rowData = miu_cached_model_row($table, $navId, ['homeNav']);
        if ($rowData) {
            $extra = \app\home\service\ContentI18n::overlayModelRow('nav_model', $table, $navId, $rowData);
        }
    }

    //用 + 而不是 array_merge：让栏目字段优先，附加表的 doc_id 不会把栏目 id 顶掉
    $doc = $nav + $extra;

    //附加表还没填内容时，$extra 是空的 —— 补一个空 content，避免模板读取时报错。
    if (!array_key_exists('content', $doc)) {
        $doc['content'] = '';
    }

    //单页栏目的「地址」就是栏目地址，模板里 {$doc.url} 也能直接用
    $doc['url'] = nav_url($nav);

    return $doc;
}

/**
 * 渲染栏目（文档列表页）
 *
 * 模板文件按模型 nid 走：list_<nid>.html（如 list_article.html），
 * 栏目上单独指定了「栏目模板」(temp_list) 时优先用它；
 * 没有对应模板就直接提示，不会静默用别的模板。
 *
 * 缓存：列表查询结果按「栏目 + 页码 + 每页条数」缓存 24 小时，标记 tag 是 Document
 * （后台文档增删改会 clear_cache('Document')）。这是跟 {miu:list} 标签并列的另一条取数路径，
 * 两条都要各自缓存，改一个别忘了另一个。
 *
 * @param int $navId 栏目id
 * @param int $page  页码，从 1 开始
 * @return \think\response\View|\think\response\Html
 */
function miu_render_nav_list(int $navId, int $page = 1)
{
    $navId = (int) $navId;
    $page  = max(1, (int) $page);

    //先告诉栏目相关函数「当前栏目是谁」，导航菜单才能高亮
    //（要在 miu_nav_items() 第一次被调用之前设，省得白算一遍）
    miu_current_nav_id($navId);

    $nav = nav_by_id($navId);
    if (!$nav) {
        //栏目在回收站 / 被停用时，说清楚是哪种，别一律说「不存在」
        $unavail = miu_nav_unavailable($navId);
        if ($unavail) {
            $name = htmlspecialchars((string) ($unavail['nav']['title'] ?? ''), ENT_QUOTES);
            return miu_front_notice(
                '栏目' . $unavail['title'],
                '<p>栏目「' . $name . '」（id=' . $navId . '）' . $unavail['why'] . '。</p>'
            );
        }

        return miu_front_notice(
            '栏目不存在',
            '<p>找不到 id 为 <code>' . $navId . '</code> 的栏目，可能已被删除或还没发布。</p>'
            . '<p>如果这是新加的栏目，去后台「内容 → 栏目管理」确认它的状态是“显示”。</p>'
        );
    }

    $navs = nav_ancestors($navId);
    $allNavs = nav_ancestors($navId, true);
    $nav['top_navid'] = (int) ($allNavs[0]['id'] ?? $nav['id']);

    $channel = get_channel((int) $nav['channel_id']);
    $nid     = (string) ($channel['nid'] ?? '');
    $table   = (string) ($channel['table'] ?? '');

    //留言栏目也有栏目内容；合并附加表数据供 list_clue.html 的 {$nav.content} 使用。
    if ($nid === 'clue') {
        $nav = miu_single_doc($navId, $nav, $table);
    }

    //模板文件：栏目单独指定的「栏目模板」优先，否则用 list_<模型nid>
    //这里不做通用兜底模板，没对应文件就直接提示，免得用错模板还看不出来
    $tplName = miu_nav_list_tpl($nav, $channel);

    if ($tplName === '') {
        $expect = miu_expect_list_tpl($nid);
        return miu_front_notice(
            '模板文件不存在',
            '<p>栏目「' . htmlspecialchars((string) $nav['title'], ENT_QUOTES) . '」用的模型是 <code>' . htmlspecialchars($nid, ENT_QUOTES) . '</code>，'
            . '列表页需要模板文件 <code>' . htmlspecialchars($expect, ENT_QUOTES) . '</code>。</p>'
            . '<p>请到模板目录 <code>templates/' . htmlspecialchars(get_current_template(), ENT_QUOTES) . '/</code> 下新建这个文件，'
            . '或者在这个栏目上单独指定「栏目模板」。</p>'
        );
    }

    $tplPath = get_templates_path(get_current_template()) . $tplName . '.html';

    //单页栏目：没有文档列表，正文直接存在附加表里（cms_single，按 doc_id = 栏目id 存）
    //把合并后的数组同时给 nav 和 doc，模板里 {$nav.content} 和 {$doc.content} 都能取到
    if ($nid === 'single') {
        $doc = miu_single_doc($navId, $nav, $table);
        miu_assign_page_meta($nav, nav_url($nav));
        return \think\facade\View::fetch('/' . $tplName, [
            'nav'        => $doc,
            'list'        => $doc,
            'navs'       => $navs,
            'channel'    => $channel,
            'nid'        => $nid,
            'editor'     => (string) get_system_config('editor') === '2' ? '2' : '1',
            'doc'        => $doc,
            'list'       => [],
            'total'      => 1,
            'page'       => null,
            'page_html'  => '',
            'page_no'    => 1,
            'page_size'  => 1,
            'page_count' => 1,
            'tpl_file'   => $tplPath,
            'page_title' => miu_page_title('nav', $nav),
        ]);
    }

    //普通栏目：分页取文档
    $pageSize = (int) (get_config('app.page_size') ?: 20);
    if ($pageSize <= 0) {
        $pageSize = 20;
    }

    //缓存：按「栏目 + 页码 + 每页条数」做键，用 Cache::tag('Document') 存
    //（跟后台 clear_cache('Document') 对上）。缓存里只存数据库原始行和总数，
    //补 url/nav_name 这些依赖栏目数据的加工放在缓存外面做。
    $cacheKey = 'public_v2_navDocList_' . md5(\app\home\service\ContentI18n::locale() . '_' . $navId . '_' . $page . '_' . $pageSize);
    $cached   = get_cache($cacheKey);
    if (!is_array($cached) || !array_key_exists('rows', $cached)) {
        $query = \think\facade\Db::name('document')
            ->where('nav_id', $navId)
            ->where('is_del', 0)
            //arcrank = -1 是“未审核”，前台不展示
            ->where('status', 1)->where('arcrank', '>=', 0);

        $total = (clone $query)->count();

        //列表默认带上模型的自定义字段（附加表，只查 channel_field 里登记的那些）
        $rows = miu_doc_merge_extras(
            $query->order('is_top desc, id desc')->page($page, $pageSize)->select()->toArray()
        );

        $cached = ['rows' => $rows, 'total' => $total];
        //必须用 Cache::tag() 存：后台清的是「标签」（Cache::clear($tag)），不打 tag 存的话清不掉。
        //除了总标记 Document，再打一个 docNav{栏目id} 的细标记，供后台「清除相关栏目缓存」用
        \think\facade\Cache::tag(['Document', 'docNav' . $navId])->set($cacheKey, $cached, 86400);
    }

    $rows  = $cached['rows'];
    $total = (int) $cached['total'];

    $list = [];
    foreach ($rows as $row) {
        $list[] = miu_doc_row($row, $nav);
    }

    //把分页信息存一份，{miu:pagelist} 标签要用（它只在列表页有意义）
    $pageCount = $pageSize > 0 ? (int) ceil($total / $pageSize) : 1;
    miu_page_info([
        'nav'   => $nav,
        'page'  => $page,
        'count' => $pageCount,
        'total' => $total,
        'size'  => $pageSize,
    ]);
    $pageHtml = miu_pagelist();

    miu_assign_page_meta($nav, nav_url($nav, $page));
    return \think\facade\View::fetch('/' . $tplName, [
        'nav'        => $nav,
        'list'        => $nav,
        'navs'       => $navs,
        'channel'    => $channel,
        'nid'        => $nid,
        'doc'        => [],
        'list'       => $list,
        'total'      => $total,
        'page'       => $page,
        'page_html'  => $pageHtml,
        'page_no'    => $page,
        'page_size'  => $pageSize,
        'page_count' => $pageCount,
        'tpl_file'   => $tplPath,
        'page_title' => miu_page_title('nav', $nav, $page),
    ]);
}

/** 按文档所属栏目的隐藏状态过滤。 */
function miu_exclude_hidden_nav_documents(\think\db\Query $query): \think\db\Query
{
    return $query->whereNotIn('nav_id', static function ($navQuery) {
        $navQuery->name('nav')->where('is_show', 1)->field('id');
    });
}

/** 标签页列表和分页使用同一套文档筛选条件。 */
function miu_tag_document_query(int $tagId): \think\db\Query
{
    $query = \think\facade\Db::name('document')
        ->where('is_del', 0)
        ->where('status', 1)
        ->where('arcrank', '>=', 0)
        ->where('tag_ids', 'like', '%,' . $tagId . ',%');
    return miu_exclude_hidden_nav_documents($query);
}

/**
 * 渲染一个标签列表页（前台访问和静态生成共用）
 *
 * 静态生成直接调用本函数，避免走 Tag::index() 时增加标签点击数和访问日志。
 *
 * @param int        $tagId 标签 id
 * @param int        $page  页码
 * @param array|null $tag   已查出的标签行；不传时按启用状态查询
 * @return mixed
 */
function miu_render_tag_list(int $tagId, int $page = 1, ?array $tag = null)
{
    $tagId = (int) $tagId;
    $page  = max(1, (int) $page);

    if ($tagId < 1) {
        return miu_front_notice('标签不存在', '<p>标签 id 无效。</p>');
    }

    if ($tag === null) {
        $tag = miu_cached_tag($tagId);
    }
    if (!$tag) {
        return miu_front_notice(
            '标签不存在',
            '<p>找不到 id 为 <code>' . $tagId . '</code> 的标签，可能已被删除或禁用。</p>'
            . '<p>去后台「基础 → 标签管理」确认一下这个标签还在不在。</p>'
        );
    }
    $tag = \app\home\service\ContentI18n::overlayRow('tag', is_object($tag) ? $tag->toArray() : (array) $tag);

    //静态生成会在同一个请求里连续渲染多个页面，先清空其它页面留下的状态。
    miu_current_nav_id(0);
    miu_current_doc([]);
    miu_current_search([]);
    miu_current_tag($tag);

    $pageSize = (int) (get_config('app.page_size') ?: 20);
    if ($pageSize <= 0) {
        $pageSize = 20;
    }

    //栏目隐藏状态变化时，随 homeNav 一起清理标签页缓存。
    $cacheKey = 'public_v3_tagDocList_' . md5(\app\home\service\ContentI18n::locale() . '_' . $tagId . '_' . $page . '_' . $pageSize);
    $cached   = get_cache($cacheKey);
    if (!is_array($cached) || !array_key_exists('rows', $cached)) {
        $query = miu_tag_document_query($tagId);

        $total = (clone $query)->count();
        $rows  = miu_doc_merge_extras(
            $query->order('is_top desc, id desc')->page($page, $pageSize)->select()->toArray()
        );
        $cached = ['rows' => $rows, 'total' => $total];
        \think\facade\Cache::tag(['Document', 'docTag' . $tagId, 'homeNav'])->set($cacheKey, $cached, 86400);
    }

    $list = [];
    foreach ($cached['rows'] as $row) {
        $list[] = miu_doc_row($row, nav_by_id((int) $row['nav_id']));
    }

    $total     = (int) $cached['total'];
    $pageCount = max(1, (int) ceil($total / $pageSize));
    $tagUrl    = miu_tag_url($tagId);
    $tagTitle  = '标签：' . (string) ($tag['title'] ?? '');

    miu_page_info([
        'nav'      => [],
        'base_url' => $tagUrl,
        'tag_id'   => $tagId,
        'page'     => $page,
        'count'    => $pageCount,
        'total'    => $total,
        'size'     => $pageSize,
    ]);

    miu_assign_page_meta([
        'seo_keywords'    => (string) ($tag['title'] ?? ''),
        'seo_description' => '标签「' . (string) ($tag['title'] ?? '') . '」下的所有文章',
    ], $tagUrl);
    return \think\facade\View::fetch('/list_tag', [
        'nav'        => [
            'id'              => 0,
            'title'           => $tagTitle,
            'seo_title'       => $tagTitle,
            'seo_keywords'    => (string) ($tag['title'] ?? ''),
            'seo_description' => '标签「' . (string) ($tag['title'] ?? '') . '」下的所有文章',
            'src'             => $tagUrl,
        ],
        'tag'        => $tag,
        'list'       => $list,
        'total'      => $total,
        'page'       => $page,
        'page_html'  => miu_pagelist(),
        'page_no'    => $page,
        'page_size'  => $pageSize,
        'page_count' => $pageCount,
        'tag_url'    => $tagUrl,
        //标签页使用 SEO → URL配置中的独立「标签标题」规则。
        'page_title' => miu_page_title('tag', ['title' => $tagTitle], $page),
    ]);
}

/**
 * 标签列表页的总页数（生成静态时用）
 *
 * @param int $tagId
 * @return int 至少 1 页
 */
function miu_tag_list_page_count(int $tagId): int
{
    $tagId = (int) $tagId;
    if ($tagId < 1) {
        return 1;
    }

    $pageSize = (int) (get_config('app.page_size') ?: 20);
    if ($pageSize <= 0) {
        $pageSize = 20;
    }

    $cacheKey = 'public_v3_tagDocCount_' . $tagId;
    $total    = get_cache($cacheKey);
    if (!is_numeric($total)) {
        $total = miu_tag_document_query($tagId)->count();
        \think\facade\Cache::tag(['Document', 'docTag' . $tagId, 'homeNav'])->set($cacheKey, (int) $total, 86400);
    }

    return max(1, (int) ceil((int) $total / $pageSize));
}

/**
 * 栏目列表页「真实的总页数」（生成静态时用）
 *
 * 页数不能拿后台「每页条数」硬算，原因有两个：
 *   1. 模板里 {miu:list pagesize='N'} 会改掉每页条数，后台那个 page_size 只是默认值；
 *   2. 列表标签取的范围是「本栏目 + 子栏目」，跟按 nav_id 直接数出来的条数也不一样。
 * 所以这里把第 1 页真渲染一遍，让页面自己把总页数写进分页上下文（miu_page_info），再读回来。
 * 这样生成器跟页面看到的分页完全一致，不会出现「页面有第 2 页、静态文件却没生成」。
 *
 * 渲染结果直接丢掉（生成时还会再渲染一次，数据查询有缓存，第二次很便宜）。
 *
 * @param int $navId
 * @return int 至少 1 页
 */
function miu_nav_list_page_count(int $navId): int
{
    $navId   = (int) $navId;
    $nav     = nav_by_id($navId);
    $channel = get_channel((int) ($nav['channel_id'] ?? 0));

    //单页栏目没有文档列表，固定一页
    if ((string) ($channel['nid'] ?? '') === 'single') {
        return 1;
    }

    //先清掉上一次渲染留下的分页上下文，免得模板没写分页时读到别的栏目的数据
    miu_page_info([]);
    try {
        miu_render_nav_list($navId, 1);
    } catch (\Throwable $e) {
        return 1;
    }

    $count = (int) (miu_page_info()['count'] ?? 0);
    return $count > 0 ? $count : 1;
}

/* ==================== 上一篇 / 下一篇 ==================== */

/**
 * 取同栏目里紧挨着的上一篇 / 下一篇文档（原始行，没加工过）
 *
 * 「上一篇」= 同栏目里 id 比它小的最大那篇，「下一篇」= id 比它大的最小那篇。
 * 详情页渲染、{miu:prenext} 标签、静态生成时的「更新上下篇」都用这一个函数，
 * 保证三处的排序规则永远一致（以前静态生成那边自己写了一份，容易走偏）。
 *
 * 只看已发布的（没进回收站、不是未审核），跟列表页口径一致。
 *
 * @param string $type  pre=上一篇  next=下一篇
 * @param int    $docId 当前文档id
 * @param int    $navId 当前文档所在栏目id
 * @return array|null 没有就返回 null
 */
function miu_doc_sibling(string $type, int $docId, int $navId): ?array
{
    if ($docId < 1 || $navId < 1) {
        return null;
    }

    // 上下篇按「类型 + 当前文档 + 栏目」做持久缓存；没有相邻文档时也缓存空结果。
    $cacheKey = 'public_v2_docSide_' . $type . '_' . $navId . '_' . $docId;
    $cached = get_cache($cacheKey);
    if (is_array($cached) && array_key_exists('row', $cached)) {
        return is_array($cached['row']) ? $cached['row'] : null;
    }

    $query = \think\facade\Db::name('document')
        ->where('nav_id', $navId)
        ->where('is_del', 0)
        ->where('status', 1)->where('arcrank', '>=', 0);

    if ($type === 'pre') {
        $row = $query->where('id', '<', $docId)->order('id', 'desc')->find();
    } else {
        $row = $query->where('id', '>', $docId)->order('id', 'asc')->find();
    }

    $row = $row ?: null;
    \think\facade\Cache::tag('docSide')->set($cacheKey, ['row' => $row], 86400);

    return $row;
}

/**
 * {miu:prenext} 标签用：当前文档的上一篇 / 下一篇，加工成模板要的那几个字段
 *
 * 返回的数组**一定**带着 title / title_full / link / disclass 四个键 ——
 * 模板里写 {$vo.title ?? "没有了"} 时，键不存在会报「Undefined array key」，
 * 所以没有上下篇时不是返回空数组，而是返回一份「占位值」：
 *   title     = null（模板的 ?? 才会生效，显示「没有了」这种兜底文案）
 *   link      = javascript:;（点了不跳走，也不会把页面刷新一遍）
 *   disclass  = 参数传进来的禁用样式 class（默认 disabled）
 *
 * 有上下篇时：title 是截断后的标题（titlelen 按字符数算，中文算 1 个，不加省略号），
 * title_full 是完整标题，link 是详情页地址，disclass 是空串。
 *
 * 取值来源是「当前正在渲染的文档」（miu_current_doc()，由 miu_render_doc_detail() 填），
 * 所以这个标签只在内容页有意义；别的页面上没有当前文档，一律按「没有上下篇」处理。
 *
 * @param string $type      pre=上一篇  next=下一篇
 * @param int    $titlelen  标题截断长度，0 = 不截断
 * @param string $disclass  没有上下篇时给的禁用 class
 * @return array
 */
function miu_prenext(string $type, int $titlelen = 30, string $disclass = 'disabled'): array
{
    //没有上下篇时的占位值（键必须齐全，模板里的 ?? 和 {$vo.link} 才不会报错）
    $empty = [
        'title'      => null,
        'title_full' => '',
        'link'       => 'javascript:;',
        // arcurl 是 link 的兼容别名，保留旧模板中的 {$vo.arcurl} 写法。
        'arcurl'     => 'javascript:;',
        'full_url'   => 'javascript:;',
        'disclass'   => $disclass,
    ];

    $doc = miu_current_doc();
    if (empty($doc['id'])) {
        return $empty;
    }

    $row = miu_doc_sibling($type, (int) $doc['id'], (int) ($doc['nav_id'] ?? 0));
    if (!$row) {
        return $empty;
    }

    $item      = miu_doc_row($row, nav_by_id((int) $row['nav_id']));
    $titleFull = (string) ($item['title'] ?? '');
    $title     = $titleFull;

    if ($titlelen > 0 && mb_strlen($title, 'UTF-8') > $titlelen) {
        $title = mb_substr($title, 0, $titlelen, 'UTF-8');
    }

    $link = (string) ($item['url'] ?? '');

    return [
        'title'      => $title,
        'title_full' => $titleFull,
        'link'       => $link,
        'arcurl'     => $link,
        'full_url'   => miu_full_url($link),
        'disclass'   => '',
    ];
}

/* ==================== 广告（{miu:adv} 标签用） ==================== */

/** 根据当前前台语言覆盖轮播图片标题和描述，缺少译文时保留中文原文。 */
function miu_adv_apply_locale(array $item): array
{
    $locale = class_exists(\app\home\service\TemplateI18n::class)
        ? \app\home\service\TemplateI18n::locale()
        : '';
    $translations = is_array($item['i18n'] ?? null) ? $item['i18n'] : [];
    $fields = is_array($translations[$locale] ?? null) ? $translations[$locale] : [];

    foreach (['img_title', 'img_desc'] as $field) {
        $value = $fields[$field] ?? '';
        if (is_scalar($value) && trim((string) $value) !== '') {
            $item[$field] = (string) $value;
        }
    }

    unset($item['i18n']);
    return $item;
}

/**
 * 按「广告标识」取一个广告的内容，返回可以直接 foreach 的列表
 *
 * 后台「基础 → 广告/轮播」里每条广告都有一个「标识」(mark)，模板里用它调：
 *   {miu:adv mark='INDEX_SLIDE'}...{/miu:adv}
 *
 * 返回什么由广告类型决定（拆分规则在 app/common.php 的 adv_content_items，跟后台共用一份）：
 *   轮播图片  每条：src 图片地址 / title 图片标题 /
 *                   desc 图片注释（HTML，模板里用 {$vo.desc|raw}）/
 *                   url 跳转链接 /
 *                   target 属性片段（'1' 输出带前后空格的 target="_blank"，'0' 输出空字符串）
 *   多媒体    只有一条：media_url
 *   HTML代码  只有一条：html（模板里用 {$vo.html|raw} 输出）
 *
 * 注意：上面这些是**前台输出**的字段名。数据库和后台表单里存的仍然是
 * img_src / img_title / img_desc / img_url / img_target，在下面统一换名（旧名也还留着）。
 *   多媒体    只有一条：media_url
 *   HTML代码  只有一条：html（模板里用 {$vo.html|raw} 输出）
 *
 * 找不到 / 被禁用（status != 1）/ 没内容 → 返回空数组，标签里什么都不输出，不报错。
 *
 * 缓存：前台查库一律走持久缓存，标记是 homeAdv
 * （后台新增/编辑/删除广告时会 clear_cache('homeAdv')，见 app/admin/controller/Adv.php）。
 *
 * @param string $mark 广告标识
 * @return array
 */
function miu_adv(string $mark): array
{
    $mark = trim($mark);
    if ($mark === '') {
        return [];
    }

    $cacheKey = 'adv_' . md5($mark);
    $cached   = get_cache($cacheKey);

    if (!is_array($cached)) {
        $row = \think\facade\Db::name('adv')
            ->where('mark', $mark)
            ->where('status', 1)
            ->find();

        //查不到也缓存（存空数组）：省得每次都去查一遍不存在的标识
        $cached = $row ?: [];
        \think\facade\Cache::tag('homeAdv')->set($cacheKey, $cached, 86400);
    }

    if (!$cached) {
        return [];
    }

    $items = adv_content_items($cached)['items'];

    // 前台模板输出使用短字段名：
    //   img_src → src、img_title → title、img_desc → desc、img_url → url；target 转成带空格的属性片段或空字符串
    //数据库和后台表单仍然是 img_ 开头那一套，只在前台输出这一层换名。
    //旧名一并留着，还没改过来的模板不会因为这次改名报错。
    foreach ($items as &$item) {
        $item = miu_adv_apply_locale($item);
        foreach ([
            'img_src'    => 'src',
            'img_title'  => 'title',
            'img_desc'   => 'desc',
            'img_url'    => 'url',
        ] as $from => $to) {
            if (array_key_exists($from, $item)) {
                $item[$to] = $item[$from];
            }
        }
        $item['full_url'] = miu_full_url((string) ($item['url'] ?? ''));
        //前台模板可直接写 <a href="{$vo.url}"{$vo.target}>；数据库原字段 img_target 仍保留 1/0。
        $item['target'] = ((string) ($item['img_target'] ?? '0')) === '1' ? ' target="_blank" ' : '';
    }
    unset($item);

    return $items;
}

/* ==================== 广告位的 JS 调用（<script src="..."> 引用的那个地址） ==================== */

/**
 * 广告位的 JS 调用内容
 *
 * 后台广告列表的「调用标签」弹窗里会给一段
 *   <script type="text/javascript" src="..."></script>
 * 浏览器打开那个 src 走的就是这里 —— 输出一串 document.write(...)，
 * 执行后把广告画到页面上。这样广告能贴到任意页面（甚至别的站），不用改模板。
 *
 * 按广告类型分别输出：
 *   轮播图片  document.write('<a href="..." target><img src="..." alt=""></a>');
 *   多媒体    document.write('<video src="..." controls preload="auto"></video>');
 *   HTML代码  document.write('<原样 HTML>');
 *
 * 缓存：跟 miu_adv() 共用标记 homeAdv，后台改广告会 clear_cache('homeAdv')。
 *
 * @param int $id  广告id
 * @param int $row 最多输出几条（0 = 不限；轮播图片按张数算）
 * @return string 找不到 / 被禁用 / 没内容时返回一句 JS 注释，不会把引用它的页面搞成 500
 */
function miu_adv_js(int $id, int $row = 0): string
{
    $id = (int) $id;
    if ($id < 1) {
        return '/* 广告id不正确 */';
    }

    $cacheKey = 'advid_' . $id;
    $cached   = get_cache($cacheKey);
    if (!is_array($cached)) {
        $cached = \think\facade\Db::name('adv')
            ->where('id', $id)
            ->where('status', 1)
            ->find() ?: [];
        //查不到也缓存（存空数组），省得每次都查一遍不存在的广告
        \think\facade\Cache::tag('homeAdv')->set($cacheKey, $cached, 86400);
    }

    if (!$cached) {
        return '/* 广告不存在或已禁用 */';
    }

    $type  = (int) ($cached['type'] ?? 0);
    $items = adv_content_items($cached)['items'];
    if (!$items) {
        return '/* 广告还没有内容 */';
    }

    if ($row > 0) {
        $items = array_slice($items, 0, $row);
    }

    $js = '';
    foreach ($items as $item) {
        if ($type === 1) {
            $item = miu_adv_apply_locale($item);
            //轮播图片：一张一条
            $title = htmlspecialchars((string) ($item['img_title'] ?? ''), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
            $html = '<a href="' . (string) ($item['img_url'] ?? '') . '"'
                . (((string) ($item['img_target'] ?? '0')) === '1' ? ' target="_blank"' : '')
                . '><img border="0" src="' . (string) ($item['img_src'] ?? '') . '"'
                . ' alt="' . $title . '"/></a>';
        } elseif ($type === 2) {
            //多媒体
            $html = '<video src="' . (string) ($item['media_url'] ?? '') . '" controls preload="auto"></video>';
        } else {
            //HTML代码：原样输出
            $html = (string) ($item['html'] ?? '');
        }

        if ($html === '') {
            continue;
        }

        $js .= "document.write('" . miu_js_str($html) . "');\n";
    }

    return $js === '' ? '/* 广告还没有内容 */' : $js;
}

/**
 * 把一段 HTML 转成能安全放进 document.write('...') 单引号字符串里的写法
 *
 * 处理三样：
 *   \   → \\      反斜杠本身要转义
 *   '   → \'      单引号会把字符串提前收尾
 *   </  → <\/     不拆开的话，输出里出现 </script> 会把引用它的那个 <script> 标签截断
 *   换行 → 去掉    一行一条 document.write，留着换行会把 JS 语句拆断
 *
 * @param string $html
 * @return string
 */
function miu_js_str(string $html): string
{
    $html = str_replace('\\', '\\\\', $html);
    $html = str_replace("'", "\\'", $html);
    $html = str_replace(["\r\n", "\r", "\n"], '', $html);

    return str_replace('</', '<\\/', $html);
}

/**
 * 渲染文档详情页
 *
 * 模板文件按模型 nid 走：view_<nid>.html（如 view_article.html），
 * 栏目上单独指定了「详情模板」(temp_view) 时优先用它；
 * 没有对应模板时退回 view_default.html，再没有就显示提示页。
 *
 * @param int $docId 文档id
 * @return \think\response\View|\think\response\Html
 */
function miu_render_doc_detail(int $docId)
{
    $docId = (int) $docId;

    $doc = miu_cached_document($docId);
    if (!$doc) {
        return miu_front_notice(
            '文档不存在',
            '<p>找不到 id 为 <code>' . $docId . '</code> 的文档，可能已被删除或还没发布。</p>'
        );
    }

    $nav = nav_by_id((int) $doc['nav_id']);
    if (!$nav) {
        $navId = (int) $doc['nav_id'];

        //栏目在回收站 / 被停用时，说清楚是哪种，别一律说「不存在」
        $unavail = miu_nav_unavailable($navId);
        if ($unavail) {
            $name = htmlspecialchars((string) ($unavail['nav']['title'] ?? ''), ENT_QUOTES);
            return miu_front_notice(
                '文档所属栏目' . $unavail['title'],
                '<p>文档 <code>' . $docId . '</code> 所属的栏目「' . $name . '」（id=' . $navId . '）'
                . $unavail['why'] . '。</p>'
            );
        }

        return miu_front_notice(
            '文档所属栏目不存在',
            '<p>文档 <code>' . $docId . '</code> 所属的栏目（id=' . $navId . '）已被删除或还没发布。'
            . '请到后台重新指定它所属的栏目。</p>'
        );
    }

    //导航菜单高亮（要在 miu_nav_items() 第一次被调用之前设，省得白算一遍）
    miu_current_nav_id((int) $nav['id']);

    $channel = get_channel((int) $doc['channel_id']);
    $nid     = (string) ($channel['nid'] ?? '');
    $table   = (string) ($channel['table'] ?? '');

    //模板文件：栏目单独指定的 > view_<模型nid> > view_default
    $tplName = miu_doc_view_tpl($nav, $channel);

    if ($tplName === '') {
        $expect = miu_expect_view_tpl($nid);
        return miu_front_notice(
            '模板文件不存在',
            '<p>文档「' . htmlspecialchars((string) $doc['title'], ENT_QUOTES) . '」用的模型是 <code>' . htmlspecialchars($nid, ENT_QUOTES) . '</code>，'
            . '详情页需要模板文件 <code>' . htmlspecialchars($expect, ENT_QUOTES) . '</code>。</p>'
            . '<p>请到模板目录 <code>templates/' . htmlspecialchars(get_current_template(), ENT_QUOTES) . '/</code> 下新建这个文件，'
            . '或新建一个 <code>view_default.html</code> 作为通用兜底模板。</p>'
            . '<p>也可以在这个栏目上单独指定「详情模板」。</p>'
        );
    }

    $tplPath = get_templates_path(get_current_template()) . $tplName . '.html';

    //正文在按模型建的附加表里（cms_article / cms_product …），靠 doc_id 关联
    $extra = [];
    if ($table !== '') {
        $extra = miu_cached_model_row($table, $docId, ['Document']);
    }

    $doc = miu_doc_row($doc, $nav, $extra);
    if (array_key_exists('videos', $doc)) {
        $doc['videos'] = miu_doc_video_list($doc['videos']);
        $doc['has_dplayer'] = (bool) array_filter($doc['videos'], static fn(array $group): bool => ($group['player'] ?? '') === 'dplayer');
    }
    // 模板可直接用 {volist name='doc.downloads' id='download'} 循环下载项。
    $doc['downloads'] = miu_doc_download_list($doc, (int) $doc['channel_id']);
    $doc['download_count'] = count($doc['downloads']);

    //上一篇 / 下一篇：同栏目内按 id 排（排序规则统一在 miu_doc_sibling() 里，标签那边共用同一份）
    $prev = miu_doc_sibling('pre', $docId, (int) $nav['id']);
    $next = miu_doc_sibling('next', $docId, (int) $nav['id']);

    //访问量 +1（批量生成静态时跳过，不然生成一次全站每篇文档都白涨一次访问量）
    if (!miu_building_static()) {
        \think\facade\Db::name('document')->where('id', $docId)->inc('click')->update();
    }

    $navs = nav_ancestors((int) $nav['id']);
    $allNavs = nav_ancestors((int) $nav['id'], true);
    $doc['top_navid'] = (int) ($allNavs[0]['id'] ?? $nav['id']);

    //记下当前正在看的文档，{miu:position} 面包屑标签要用
    miu_current_doc($doc);

    miu_assign_page_meta($doc, doc_url($doc, $nav), 'article');
    return \think\facade\View::fetch('/' . $tplName, [
        'nav'     => $nav,
        'navs'    => $navs,
        'channel' => $channel,
        'nid'     => $nid,
        'editor'  => (string) get_system_config('editor') === '2' ? '2' : '1',
        'doc'     => $doc,
        'info'     => $doc,
        'detail'     => $doc,
        'prev'    => $prev ? miu_doc_row($prev, $nav) : [],
        'next'    => $next ? miu_doc_row($next, $nav) : [],
        'tpl_file' => $tplPath,
        'page_title' => miu_page_title('doc', $doc),
    ]);
}

/* ==================== Sitemap（xml / txt / html / llms 地图） ==================== */
/*
 * 配置存在 cms_config 的 sitemap 组，后台页面是「SEO → Sitemap」（admin/seo/sitemap）。
 * 生成的文件放在站点根目录（public/）：sitemap.xml、sitemap.txt、sitemap.html、llms.txt。
 * 因为要读前台的 URL 规则（nav_url / doc_url），这套函数只在 home 应用里能用：
 *   - 后台「手工更新」按钮 → 请求 /home/sitemap/build
 *   - 命令行             → php think sitemap（会先 require 本文件）
 */

/**
 * 读 Sitemap 配置（带默认值，值被改坏也不会报错）
 *
 * @return array
 */
function sitemap_config(): array
{
    static $cfg = null;
    if ($cfg !== null) {
        return $cfg;
    }

    $all = get_config_group('sitemap');

    $pickInt = function ($key, $default) use ($all) {
        $val = $all[$key] ?? null;
        return ($val === null || $val === '') ? $default : (int) $val;
    };
    $pickEnum = function ($key, $default, array $allow) use ($all) {
        $val = (string) ($all[$key] ?? '');
        return in_array($val, $allow, true) ? $val : $default;
    };

    $freq = ['always', 'hourly', 'daily', 'weekly', 'monthly', 'yearly', 'never'];
    $prio = ['0.1', '0.2', '0.3', '0.4', '0.5', '0.6', '0.7', '0.8', '0.9', '1.0'];

    //生成数量夹在 0~5000（后台也校验，这里兜底）
    $num = function ($key, $default) use ($all) {
        $val = (int) ($all[$key] ?? $default);
        return max(0, min(5000, $val));
    };

    $cfg = [
        'xml'  => $pickInt('sitemap_xml', 0) === 1,
        'txt'  => $pickInt('sitemap_txt', 0) === 1,
        'html' => $pickInt('sitemap_html', 0) === 1,
        'llms' => $pickInt('sitemap_llms', 0) === 1,
        'auto' => $pickInt('sitemap_auto', 0) === 1,

        //过滤规则
        'not1' => $pickInt('sitemap_not1', 0) === 1,   //过滤隐藏栏目（cms_nav.is_show = 1 表示隐藏，0 表示显示）
        'not2' => $pickInt('sitemap_not2', 0) === 1,   //过滤外部链接（栏目 cms_nav.is_url = 1；文档 cms_document.is_jump = 1）

        //更新频率 / 优先级别
        'freq_index' => $pickEnum('sitemap_changefreq_index', 'always', $freq),
        'freq_list'  => $pickEnum('sitemap_changefreq_list', 'hourly', $freq),
        'freq_view'  => $pickEnum('sitemap_changefreq_view', 'daily', $freq),
        'prio_index' => $pickEnum('sitemap_priority_index', '1.0', $prio),
        'prio_list'  => $pickEnum('sitemap_priority_list', '0.8', $prio),
        'prio_view'  => $pickEnum('sitemap_priority_view', '0.5', $prio),

        'archives_num' => $num('sitemap_archives_num', 100),
        'tags_num'     => $num('sitemap_tags_num', 100),
        'baidu_push'   => $pickInt('sitemap_baidu_push', 0) === 1,
        'baidu_token'  => trim((string) ($all['sitemap_zzbaidutoken'] ?? '')),
    ];

    return $cfg;
}

/**
 * 站点根地址（绝对地址、不带结尾斜杠）
 *
 * sitemap 里必须是绝对地址。优先用「网站配置 → 站点域名」，
 * 没填才退回当前请求域名（命令行下没有请求域名，所以命令行生成前一定要先把站点域名配好）。
 *
 * @return string
 */
function sitemap_site_url(): string
{
    $domain = trim((string) get_system_config('site_domain'));
    if ($domain !== '' && preg_match('#^https?://#i', $domain)) {
        return rtrim($domain, '/');
    }

    try {
        return rtrim((string) request()->domain(), '/');
    } catch (\Throwable $e) {
        return '';
    }
}

/** 查询有上限的最新文档集合，供 Sitemap 与百度推送复用。 */
function miu_cached_sitemap_documents(int $limit, bool $excludeJumps): array
{
    $limit = max(1, min(5000, $limit));
    $cacheKey = 'public_v2_homeSitemapDocs_' . $limit . '_' . (int) $excludeJumps;
    $docs = get_cache($cacheKey);
    if (is_array($docs)) {
        return $docs;
    }

    try {
        $query = \think\facade\Db::name('document')
            ->where('is_del', 0)
            ->where('status', 1)->where('arcrank', '>=', 0);
        if ($excludeJumps) {
            $query->where('is_jump', 0);
        }
        $docs = $query->order('id desc')->limit($limit)->select()->toArray();
    } catch (\Throwable $e) {
        return [];
    }
    \think\facade\Cache::tag('Document')->set($cacheKey, $docs, 86400);
    return $docs;
}

/** 查询有上限的最新标签集合；URL 根据当前请求的语言和路由规则在缓存外生成。 */
function miu_cached_sitemap_tags(int $limit): array
{
    $limit = max(0, min(5000, $limit));
    if ($limit < 1) {
        return [];
    }
    $cacheKey = 'public_v2_homeSitemapTagsMeta_' . $limit;
    $tags = get_cache($cacheKey);
    if (is_array($tags)) {
        return $tags;
    }

    try {
        $tags = \think\facade\Db::name('keywords')->field('id,title,add_time,update_time')
            ->where('status', 1)->order('id desc')->limit($limit)->select()->toArray();
    } catch (\Throwable $e) {
        return [];
    }
    \think\facade\Cache::tag('homeTag')->set($cacheKey, $tags, 86400);
    return $tags;
}

/**
 * 收集 sitemap 要收录的地址
 *
 * @return array ['index'=>[], 'list'=>[], 'view'=>[], 'tags'=>[]]，每项 ['loc'=>绝对地址, 'lastmod'=>时间戳]
 */
function sitemap_urls(): array
{
    $cfg  = sitemap_config();
    $base = sitemap_site_url();

    $items = ['index' => [], 'list' => [], 'view' => [], 'tags' => []];

    //首页
    $items['index'][] = ['loc' => $base . '/', 'lastmod' => time(), 'title' => '首页'];

    //栏目页（含单页栏目）
    $sitemapNavs = nav_flat_map();
    foreach ($sitemapNavs as $nav) {
        //过滤隐藏栏目（is_show = 1 表示隐藏）
        if ($cfg['not1'] && (int) ($nav['is_show'] ?? 0) === 1) {
            continue;
        }
        //过滤外部链接（栏目的外链：is_url = 1，地址存在 url 里）
        if ($cfg['not2'] && !empty($nav['is_url']) && !empty($nav['url'])) {
            continue;
        }

        $navLoc = nav_url($nav);
        $items['list'][] = [
            'loc'     => preg_match('#^https?://#i', $navLoc) ? $navLoc : $base . $navLoc,
            'lastmod' => (int) ($nav['update_time'] ?: $nav['add_time'] ?: time()),
            'title'   => (string) ($nav['title'] ?? $nav['name'] ?? ''),
            'description' => (string) ($nav['seo_description'] ?? ''),
        ];
    }

    //文档页：最新的 N 篇（只取已发布：没进回收站、且不是未审核）
    $docs = miu_cached_sitemap_documents(max(1, $cfg['archives_num']), $cfg['not2']);

    foreach ($docs as $doc) {
        //在公共地址集合中过滤，确保所有地图类型都排除隐藏栏目下的文档。
        if ($cfg['not1'] && (int) ($sitemapNavs[(int) ($doc['nav_id'] ?? 0)]['is_show'] ?? 0) === 1) {
            continue;
        }
        //跳转文档（没被上面过滤掉时）直接收录它自己的外链地址，不再拼站内地址
        $loc = (!empty($doc['is_jump']) && !empty($doc['jumplinks']))
            ? (string) $doc['jumplinks']
            : $base . doc_url($doc);

        $items['view'][] = [
            'loc'     => $loc,
            'lastmod' => (int) ($doc['update_time'] ?: $doc['add_time'] ?: time()),
            'title'   => (string) ($doc['title'] ?? ''),
            'description' => (string) ($doc['seo_description'] ?? ''),
            'public'  => (int) ($doc['arcrank'] ?? 0) === 0,
        ];
    }

    //标签页数量由 Sitemap 配置里的 TAG 上限控制；设置为 0 时不收录标签页。
    if ($cfg['tags_num'] > 0) {
        foreach (miu_cached_sitemap_tags($cfg['tags_num']) as $tag) {
            $items['tags'][] = [
                'loc'     => $base . miu_tag_url((int) $tag['id']),
                'lastmod' => (int) ($tag['update_time'] ?: $tag['add_time'] ?: time()),
                'title'   => (string) ($tag['title'] ?? ''),
            ];
        }
    }

    return $items;
}

/**
 * 生成 sitemap 文件
 *
 * @param string $type configured（后台勾选）| all（全部）| xml | txt | html | llms
 * @return array ['files'=>[文件名=>字节数], 'counts'=>[各类条数], 'errors'=>[错误说明]]
 */
function sitemap_build(string $type = 'configured'): array
{
    $cfg   = sitemap_config();
    //所有生成入口统一使用模板默认语言，避免后台批量任务沿用上一页的语言前缀。
    $previousLocale = \app\home\service\TemplateI18n::setLocale(\app\home\service\TemplateI18n::defaultLocale());
    try {
        $items = sitemap_urls();
    } finally {
        \app\home\service\TemplateI18n::setLocale($previousLocale);
    }
    $dir   = public_path();

    //这次要生成哪几种
    $want = function (string $key) use ($type, $cfg) {
        return $type === 'all' || ($type === 'configured' ? !empty($cfg[$key]) : $type === $key);
    };

    $result = [
        'files'  => [],
        'errors' => [],
        'counts' => [
            'index' => count($items['index']),
            'list'  => count($items['list']),
            'view'  => count($items['view']),
            'tags'  => count($items['tags'] ?? []),
            'total' => count($items['index']) + count($items['list']) + count($items['view']) + count($items['tags'] ?? []),
        ],
    ];

    if ($want('xml')) {
        $content = sitemap_xml_content($items, $cfg);
        $err     = '';
        if (sitemap_write($dir . 'sitemap.xml', $content, $err)) {
            $result['files']['sitemap.xml'] = strlen($content);
        } else {
            $result['errors'][] = 'sitemap.xml：' . $err;
        }
    }

    if ($want('txt')) {
        $content = sitemap_txt_content($items);
        $err     = '';
        if (sitemap_write($dir . 'sitemap.txt', $content, $err)) {
            $result['files']['sitemap.txt'] = strlen($content);
        } else {
            $result['errors'][] = 'sitemap.txt：' . $err;
        }
    }

    if ($want('html')) {
        $content = sitemap_html_content($items);
        $err     = '';
        if (sitemap_write($dir . 'sitemap.html', $content, $err)) {
            $result['files']['sitemap.html'] = strlen($content);
        } else {
            $result['errors'][] = 'sitemap.html：' . $err;
        }
    }

    if ($want('llms')) {
        $content = sitemap_llms_content($items);
        $err = '';
        if (sitemap_write($dir . 'llms.txt', $content, $err)) {
            $result['files']['llms.txt'] = strlen($content);
        } else {
            $result['errors'][] = 'llms.txt：' . $err;
        }
    }

    if (empty($result['files']) && empty($result['errors'])) {
        $result['errors'][] = '后台没有勾选任何地图类型（xml / txt / html / llms），没有生成任何文件';
    }

    return $result;
}

/**
 * xml 地图内容（标准 sitemaps.org 格式）
 *
 * @param array $items sitemap_urls() 的结果
 * @param array $cfg   sitemap_config() 的结果
 * @return string
 */
function sitemap_xml_content(array $items, array $cfg): string
{
    $meta = [
        'index' => [$cfg['freq_index'], $cfg['prio_index']],
        'list'  => [$cfg['freq_list'],  $cfg['prio_list']],
        'view'  => [$cfg['freq_view'],  $cfg['prio_view']],
        'tags'  => [$cfg['freq_list'],  $cfg['prio_list']],
    ];

    $xml  = '<?xml version="1.0" encoding="UTF-8"?>' . "\n";
    $xml .= '<urlset xmlns="http://www.sitemaps.org/schemas/sitemap/0.9">' . "\n";

    foreach ($meta as $key => $one) {
        foreach ($items[$key] as $item) {
            $xml .= "  <url>\n";
            $xml .= '    <loc>' . htmlspecialchars($item['loc'], ENT_QUOTES, 'UTF-8') . "</loc>\n";
            $xml .= '    <lastmod>' . date('Y-m-d', max(1, (int) $item['lastmod'])) . "</lastmod>\n";
            $xml .= '    <changefreq>' . $one[0] . "</changefreq>\n";
            $xml .= '    <priority>' . $one[1] . "</priority>\n";
            $xml .= "  </url>\n";
        }
    }

    $xml .= '</urlset>';

    return $xml;
}

/**
 * txt 地图内容：一行一个地址
 *
 * @param array $items
 * @return string
 */
function sitemap_txt_content(array $items): string
{
    $lines = [];
    foreach (['index', 'list', 'view', 'tags'] as $key) {
        foreach ($items[$key] as $item) {
            $lines[] = $item['loc'];
        }
    }

    return implode("\n", $lines) . "\n";
}

/** llms.txt 使用 Markdown 标题、网站简介和分组链接，不公开权限文档的摘要。 */
function sitemap_llms_content(array $items): string
{
    $text = static function (string $value, int $length = 200): string {
        $value = strip_tags(html_entity_decode(strip_tags($value), ENT_QUOTES | ENT_HTML5, 'UTF-8'));
        $value = trim(preg_replace('/[\s\x00-\x1F\x7F]+/u', ' ', $value) ?? '');
        $value = mb_substr($value, 0, $length, 'UTF-8');
        return str_replace(['\\', '[', ']', '*', '_', '`', '<', '>'], ['\\\\', '\\[', '\\]', '\\*', '\\_', '\\`', '&lt;', '&gt;'], $value);
    };
    $config = get_system_config();
    $name = $text((string) ($config['site_name'] ?? '')) ?: '网站内容索引';
    //静态 txt 响应可能不带 charset，使用规范允许的 UTF-8 BOM 避免浏览器误判编码。
    $content = "\xEF\xBB\xBF# " . $name . "\n";
    $summary = $text((string) ($config['site_desc'] ?? ''), 400);
    if ($summary !== '') {
        $content .= "\n> " . $summary . "\n";
    }

    $seen = [];
    foreach (['index' => '首页', 'list' => '栏目导航', 'view' => '最新内容', 'tags' => '标签'] as $key => $heading) {
        $lines = [];
        foreach ($items[$key] ?? [] as $item) {
            if (isset($item['public']) && !$item['public']) {
                continue;
            }
            $url = (string) ($item['loc'] ?? '');
            if (!filter_var($url, FILTER_VALIDATE_URL) || !in_array(strtolower((string) parse_url($url, PHP_URL_SCHEME)), ['http', 'https'], true) || isset($seen[$url])) {
                continue;
            }
            $seen[$url] = true;
            $title = $text((string) ($item['title'] ?? ''), 160) ?: $text($url, 160);
            $description = $text((string) ($item['description'] ?? ''));
            $url = str_replace(['(', ')'], ['%28', '%29'], $url);
            $lines[] = '- [' . $title . '](' . $url . ')' . ($description !== '' ? ': ' . $description : '');
        }
        if ($lines) {
            $content .= "\n## " . $heading . "\n\n" . implode("\n", $lines) . "\n";
        }
    }
    return $content;
}

/**
 * html 地图内容：一个给人和爬虫看的链接列表页
 *
 * @param array $items
 * @return string
 */
function sitemap_html_content(array $items): string
{
    $site  = htmlspecialchars((string) get_system_config('site_name'), ENT_QUOTES, 'UTF-8');
    $title = $site !== '' ? $site . ' 网站地图' : '网站地图';

    $esc = function (string $v) {
        return htmlspecialchars($v, ENT_QUOTES, 'UTF-8');
    };

    $html  = "<!DOCTYPE html>\n<html lang=\"zh-CN\">\n<head>\n";
    $html .= "  <meta charset=\"utf-8\">\n";
    $html .= '  <title>' . $title . "</title>\n";
    $html .= "  <meta name=\"viewport\" content=\"width=device-width, initial-scale=1\">\n";
    $html .= "  <style>body{font-family:-apple-system,'Microsoft YaHei',sans-serif;max-width:960px;margin:40px auto;padding:0 16px;line-height:2;color:#333}h1{font-size:20px}h2{font-size:16px;margin:24px 0 8px;color:#666}a{color:#1e9fff;text-decoration:none}a:hover{text-decoration:underline}ul{padding-left:20px}</style>\n";
    $html .= "</head>\n<body>\n";
    $html .= '  <h1>' . $title . "</h1>\n";
    $html .= '  <p>更新时间：' . date('Y-m-d H:i:s') . "</p>\n";

    $blocks = ['index' => '首页', 'list' => '栏目页', 'view' => '内容页', 'tags' => '标签页'];
    foreach ($blocks as $key => $label) {
        if (empty($items[$key])) {
            continue;
        }
        $html .= '  <h2>' . $label . '（' . count($items[$key]) . "）</h2>\n  <ul>\n";
        foreach ($items[$key] as $item) {
            $html .= '    <li><a href="' . $esc($item['loc']) . '">' . $esc($item['loc']) . "</a></li>\n";
        }
        $html .= "  </ul>\n";
    }

    $html .= "</body>\n</html>";

    return $html;
}

/**
 * 写文件（目录不存在自动建）
 *
 * @param string      $file
 * @param string      $content
 * @param string|null $err 失败原因
 * @return bool
 */
function sitemap_write(string $file, string $content, ?string &$err = null): bool
{
    $err = '';
    $dir = dirname($file);

    if (!is_dir($dir) && !@mkdir($dir, 0755, true)) {
        $err = '目录不存在且创建失败：' . $dir;
        return false;
    }

    if (@file_put_contents($file, $content) === false) {
        $err = '写入失败，检查目录是否可写：' . $dir;
        return false;
    }

    return true;
}

/**
 * 百度普通收录（API 推送）
 *
 * 接口：http://data.zz.baidu.com/urls?site=站点&token=token
 * 一次最多 2000 条，地址之间用换行分隔。
 * token 在百度搜索资源平台「普通收录 → API 提交」里拿。
 *
 * @param array  $urls  要推送的绝对地址
 * @param string $token 留空就用后台配置里的
 * @return array ['ok'=>bool, 'msg'=>string, 'success'=>int, 'remain'=>int]
 */
function sitemap_push_baidu(array $urls, string $token = ''): array
{
    $fail = function (string $msg) {
        return ['ok' => false, 'msg' => $msg, 'success' => 0, 'remain' => 0];
    };

    if ($token === '') {
        $token = sitemap_config()['baidu_token'];
    }
    if ($token === '') {
        return $fail('没有配置百度推送 token（后台 SEO → Sitemap → 百度推送）');
    }

    $site = sitemap_site_url();
    if ($site === '') {
        return $fail('站点域名没配置（网站配置 → 站点域名），推送需要它来标识站点');
    }

    $urls = array_slice(array_values(array_unique(array_filter(array_map('trim', $urls)))), 0, 2000);
    if (empty($urls)) {
        return $fail('没有需要推送的地址');
    }

    $api  = 'http://data.zz.baidu.com/urls?site=' . urlencode($site) . '&token=' . urlencode($token);
    $body = implode("\n", $urls);

    $res = '';
    if (function_exists('curl_init')) {
        $ch = curl_init();
        curl_setopt($ch, CURLOPT_URL, $api);
        curl_setopt($ch, CURLOPT_POST, true);
        curl_setopt($ch, CURLOPT_POSTFIELDS, $body);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_HTTPHEADER, ['Content-Type: text/plain']);
        curl_setopt($ch, CURLOPT_TIMEOUT, 30);
        $res = curl_exec($ch);
        $err = curl_error($ch);

        if ($res === false) {
            return $fail('请求百度失败：' . $err);
        }
    } else {
        //没装 curl 就用流的方式
        $ctx = stream_context_create([
            'http' => [
                'method'  => 'POST',
                'header'  => "Content-Type: text/plain\r\n",
                'content' => $body,
                'timeout' => 30,
            ],
        ]);
        $res = @file_get_contents($api, false, $ctx);
        if ($res === false) {
            return $fail('请求百度失败（服务器可能没开 allow_url_fopen，建议装 curl 扩展）');
        }
    }

    $data = json_decode((string) $res, true);
    if (!is_array($data)) {
        return $fail('百度返回的内容无法解析：' . mb_substr((string) $res, 0, 200, 'UTF-8'));
    }
    if (!empty($data['error'])) {
        return $fail('百度返回错误：' . $data['error'] . '（' . ($data['message'] ?? '') . '）');
    }

    $success = (int) ($data['success'] ?? 0);
    $remain  = (int) ($data['remain'] ?? 0);

    return [
        'ok'      => true,
        'msg'     => '推送成功 ' . $success . ' 条，今日剩余 ' . $remain . ' 条',
        'success' => $success,
        'remain'  => $remain,
    ];
}

/**
 * 推送最近发布的一批文档给百度（命令行 php think baidu-push 用）
 *
 * @param int $num 推送最新多少篇，0 = 用后台配置的「生成数量」
 * @return array sitemap_push_baidu() 的返回
 */
function sitemap_push_recent(int $num = 0): array
{
    $cfg = sitemap_config();
    if ($num <= 0) {
        $num = max(1, $cfg['archives_num']);
    }

    $docs = miu_cached_sitemap_documents(min(2000, $num), $cfg['not2']);

    $base = sitemap_site_url();
    $urls = [];
    foreach ($docs as $doc) {
        //跳转文档推它自己的外链地址，别推站内那个会跳走的地址
        $urls[] = (!empty($doc['is_jump']) && !empty($doc['jumplinks']))
            ? (string) $doc['jumplinks']
            : $base . doc_url($doc);
    }

    return sitemap_push_baidu($urls);
}

/**
 * 推送单篇文档地址给百度（发布或编辑文档时调用）。
 *
 * @param int $docId 文档 ID
 * @return array sitemap_push_baidu() 的返回
 */
function sitemap_push_document(int $docId): array
{
    if ($docId < 1) {
        return ['ok' => false, 'msg' => '文档 ID 无效', 'success' => 0, 'remain' => 0];
    }

    $cfg = sitemap_config();
    if (!$cfg['baidu_push']) {
        return ['ok' => false, 'msg' => '百度自动推送未开启', 'success' => 0, 'remain' => 0];
    }
    if ($cfg['baidu_token'] === '') {
        return ['ok' => false, 'msg' => '没有配置百度推送 token', 'success' => 0, 'remain' => 0];
    }

    $doc = miu_cached_document($docId);
    if (!$doc || (int) ($doc['status'] ?? 0) !== 1 || (int) ($doc['arcrank'] ?? -1) < 0 || ($cfg['not2'] && !empty($doc['is_jump']))) {
        return ['ok' => false, 'msg' => '文档不存在或当前状态不参与百度推送', 'success' => 0, 'remain' => 0];
    }

    $base = sitemap_site_url();
    $url = (!empty($doc['is_jump']) && !empty($doc['jumplinks']))
        ? (string) $doc['jumplinks']
        : $base . doc_url($doc);

    return sitemap_push_baidu([$url], $cfg['baidu_token']);
}

/* ==================== 静态页面生成（生成静态模式） ==================== */
/*
 * 把前台页面落成 .html 文件，只在「生成静态模式」（URL配置里选「生成静态」）下可用。
 *
 * 为什么放在 home 应用：
 *   生成要读前台的 URL 规则（nav_url / doc_url）和模板目录（view_path 由 home 的
 *   Template 中间件设置），后台应用和命令行都拿不到这些，所以统一由 home 执行。
 *
 * 流程（后台「SEO → 页面生成」页面 → 这里）：
 *   1) /home/createhtml/start  按选项算出「要生成哪些页面」的清单，存缓存，返回总数
 *   2) /home/createhtml/step   每次取一批渲染 + 写文件，返回进度
 *   循环第 2 步直到 done=true，前端进度条就是按这个刷的。
 *
 * 写到哪个文件：文件路径 = public/ + 页面地址
 *   默认语言首页 /                       → public/index.html
 *   其它语言首页 /html/en-us/            → public/html/en-us/index.html
 *   栏目页      /html/bokezixun/list_2040.html   → public/html/bokezixun/list_2040.html
 *   栏目第2页   /html/bokezixun/list_2040_2.html → public/html/bokezixun/list_2040_2.html
 *   文档页      /html/bokezixun/zixun/128.html   → public/html/bokezixun/zixun/128.html
 *   标签页      /html/tag/92.html               → public/html/tag/92.html
 *   标签第2页   /html/tag/92_p2.html            → public/html/tag/92_p2.html
 *   目录形式（地址以 / 结尾，栏目URL 选「完整链/子目录」时）一律写成该目录下的 index.html，
 *   交给服务器的 DirectoryIndex 接住。
 *
 * 栏目页数由 miu_nav_list_page_count() 渲染第 1 页问模板要；标签页按前台 page_size 配置计算。
 *
 * 文件路径统一由 nav_url() / doc_url() / miu_tag_url() 反推，保证「生成出来的文件」和「页面上给出的链接」
 * 永远是同一个地址，不会两边各写一套规则。
 */

/**
 * 当前是不是「生成静态模式」
 *
 * @return bool
 */
function createhtml_ready(): bool
{
    return url_config()['mode'] === 2;
}

/**
 * 页面地址 → 要写入的本地文件绝对路径
 *
 * @param string $url 页面地址（可以带域名，也可以只给路径）
 * @return string
 */
function createhtml_file_of_url(string $url): string
{
    $path = (string) (parse_url($url, PHP_URL_PATH) ?? '');
    if ($path === '') {
        $path = '/';
    }
    $path = ltrim($path, '/');

    //目录形式（/html/news/）写成该目录下的 index.html
    if ($path === '' || substr($path, -1) === '/') {
        $path .= 'index.html';
    }

    return public_path() . str_replace('/', DIRECTORY_SEPARATOR, $path);
}

/**
 * 判断静态 URL 是否会写到网站根目录的「404」文件。
 * 这个路径容易和站点的 404 页面文件混淆，因此不参与静态页生成。
 */
function createhtml_is_reserved_404_url(string $url): bool
{
    $path = (string) (parse_url($url, PHP_URL_PATH) ?? '');

    return strtolower(trim($path, '/')) === '404';
}

/**
 * 栏目 id 集合 → 展开成「含所有子孙栏目」的 id 列表（保持原有顺序）
 *
 * @param array $ids
 * @return array
 */
function createhtml_nav_ids_with_children(array $ids): array
{
    $all = nav_flat_map();

    //父id => [子id...]
    $byParent = [];
    foreach ($all as $nav) {
        $byParent[(int) ($nav['pid'] ?? 0)][] = (int) $nav['id'];
    }

    $stack = [];
    foreach ($ids as $id) {
        $id = (int) $id;
        if ($id > 0 && isset($all[$id])) {
            $stack[] = $id;
        }
    }

    $result = [];
    while ($stack) {
        $id = array_shift($stack);
        if (isset($result[$id])) {
            continue;
        }
        $result[$id] = $id;
        foreach ($byParent[$id] ?? [] as $child) {
            $stack[] = $child;
        }
    }

    return array_values($result);
}

/**
 * 算「要生成哪些页面」的清单
 *
 * @param array $opt [
 *   'type'    => home | nav | doc | all | tags | sitemap,
 *   'nav_ids' => [栏目id...]      （type = nav / doc 用；空数组 = 全部栏目）
 *   'scope'   => all | today | week | date （type = all 用：全站 / 今天 / 一周内 / 自定义日期）
 *   'start'   => 开始时间戳        （scope = date 用）
 *   'end'     => 截止时间戳        （scope = date 用）
 *   'sitemap' => ['xml','txt','html','llms']  （type = sitemap 用）
 * ]
 * @return array ['items' => [...], 'error' => '失败原因']
 */
function createhtml_plan(array $opt): array
{
    $type  = (string) ($opt['type'] ?? '');

    //静态页按当前模板启用的语言各生成一份；配置的默认语言沿用原路径。
    //Sitemap 仍只生成一份，避免把内容相同、仅界面文字不同的页面重复列入地图。
    if ($type !== 'sitemap' && !array_key_exists('_template_locale', $opt)) {
        $items = [];
        $previousLocale = \app\home\service\TemplateI18n::locale();

        try {
            foreach (\app\home\service\TemplateI18n::availableLocales() as $locale) {
                \app\home\service\TemplateI18n::setLocale($locale);

                $localeOpt = $opt;
                $localeOpt['_template_locale'] = $locale;
                $plan = createhtml_plan($localeOpt);
                if (!empty($plan['error'])) {
                    return $plan;
                }

                foreach ($plan['items'] as $item) {
                    if (createhtml_is_reserved_404_url((string) ($item['url'] ?? ''))) {
                        continue;
                    }
                    $item['locale'] = $locale;
                    $items[] = $item;
                }
            }
        } finally {
            \app\home\service\TemplateI18n::setLocale($previousLocale);
        }
        if (!$items) {
            return ['items' => [], 'error' => '没有需要生成的页面'];
        }

        return ['items' => $items, 'error' => ''];
    }

    $scope = (string) ($opt['scope'] ?? 'all');
    $start = (int) ($opt['start'] ?? 0);
    $end   = (int) ($opt['end'] ?? 0);

    $fail = function (string $msg) {
        return ['items' => [], 'error' => $msg];
    };

    $needHome = in_array($type, ['home', 'all'], true);
    $needNav  = in_array($type, ['nav', 'all'], true);
    $needDoc  = in_array($type, ['doc', 'all'], true);
    $needTags = in_array($type, ['tags', 'all'], true);

    //「一键生成 → 今天 / 一周内 / 自定义日期」：筛选文档，并更新受这些文档影响的页面
    //   首页   → 照常生成（首页要列最新文档，内容变了）
    //   栏目页 → 只生成「这些文档所属的栏目」的列表页
    //   文档页 → 只生成这些文档
    //   tag 页 → 只更新这些文档关联的标签页（全站范围则更新所有标签页）
    if ($type === 'all') {
        if (!in_array($scope, ['all', 'today', 'week', 'date'], true)) {
            return $fail('无效的生成范围');
        }

        if ($scope === 'today' || $scope === 'week') {
            $todayStart = strtotime('today');
            $tomorrow   = strtotime('tomorrow');
            $start      = $scope === 'today' ? $todayStart : strtotime('-6 days', $todayStart);
            $end        = $tomorrow - 1;
        } elseif ($scope === 'date' && $start <= 0 && $end <= 0) {
            return $fail('选了「自定义日期」，请先选择发布时间范围');
        }

        if ($scope === 'date' && $start > 0 && $end > 0 && $start > $end) {
            return $fail('开始日期不能晚于结束日期');
        }
    }
    $byDate = ($type === 'all' && in_array($scope, ['today', 'week', 'date'], true));

    $items = [];

    /* ---- 首页 ---- */
    if ($needHome) {
        $items[] = ['kind' => 'home', 'url' => home_url(), 'label' => '首页'];
    }

    /* ---- 先确定要处理的栏目 ---- */
    $navIds = [];
    if ($needNav || $needDoc) {
        //「一键生成」或单独生成时未选栏目 = 全站栏目；有选择时包含所选栏目及其子孙
        $selectedNavIds = array_values(array_unique(array_filter(array_map(
            'intval',
            (array) ($opt['nav_ids'] ?? [])
        ), static function ($id) {
            return $id > 0;
        })));

        $navIds = ($type === 'all' || !$selectedNavIds)
            ? array_keys(nav_flat_map())
            : createhtml_nav_ids_with_children($selectedNavIds);

        if (!$navIds) {
            return $fail('没有可生成的栏目');
        }
    }

    /* ---- 按日期范围时：查出命中的文档，以及它们所属的栏目和标签 ---- */
    $hitDocs   = [];   // [['id'=>..,'nav_id'=>..,'tag_ids'=>..], ...]
    $hitNavIds = [];   // 命中文档所属的栏目（去重）
    if ($byDate && $navIds) {
        $query = \think\facade\Db::name('document')
            ->field('id, nav_id, tag_ids')
            ->where('is_del', 0)
            ->where('status', 1)->where('arcrank', '>=', 0)
            ->whereIn('nav_id', $navIds);
        if ($start > 0) {
            $query->where('add_time', '>=', $start);
        }
        if ($end > 0) {
            $query->where('add_time', '<=', $end);
        }

        try {
            $hitDocs = $query->order('id desc')->select()->toArray();
        } catch (\Throwable $e) {
            $hitDocs = [];
        }
        foreach ($hitDocs as $doc) {
            $hitNavIds[(int) $doc['nav_id']] = (int) $doc['nav_id'];
        }
        $hitNavIds = array_values($hitNavIds);
    }

    //这一轮真正要生成列表页的栏目（按日期时就是命中文档所在的那些栏目）
    $targetNavIds = $byDate ? $hitNavIds : $navIds;

    /* ---- 模板预检：缺模板就整体停下，说清是哪个栏目、缺哪个文件 ---- */
    if ($needNav || $needDoc) {
        $missing = [];

        if ($needNav) {
            foreach ($targetNavIds as $navId) {
                $nav     = nav_by_id($navId);
                $channel = get_channel((int) ($nav['channel_id'] ?? 0));
                if (miu_nav_list_tpl($nav, $channel) === '') {
                    $missing[] = '栏目「' . (string) $nav['title'] . '」缺列表模板 ' . miu_expect_list_tpl((string) ($channel['nid'] ?? ''));
                }
            }
        }
        if ($needDoc) {
            foreach ($targetNavIds as $navId) {
                $nav     = nav_by_id($navId);
                $channel = get_channel((int) ($nav['channel_id'] ?? 0));
                if (miu_doc_view_tpl($nav, $channel) === '') {
                    $missing[] = '栏目「' . (string) $nav['title'] . '」缺详情模板 ' . miu_expect_view_tpl((string) ($channel['nid'] ?? ''));
                }
            }
        }

        $missing = array_values(array_unique($missing));
        if ($missing) {
            $more = count($missing) > 5 ? '（还有 ' . (count($missing) - 5) . ' 个）' : '';
            return $fail(implode('；', array_slice($missing, 0, 5)) . $more . '。请先补上模板再生成。');
        }
    }

    $navMap = nav_flat_map();

    /* ---- 栏目页 ---- */
    if ($needNav && $targetNavIds) {
        foreach ($targetNavIds as $navId) {
            $nav = nav_by_id($navId);

            //页数问模板要：模板里 {miu:list pagesize='N'} 会改掉每页条数，
            //列表标签取的范围（本栏目 + 子栏目）也跟按 nav_id 直接数出来的不一样。
            //miu_nav_list_page_count() 把第 1 页渲染一遍读回真实总页数（至少 1 页）
            $pages = miu_nav_list_page_count($navId);

            for ($page = 1; $page <= $pages; $page++) {
                $url     = nav_url($nav, $page);
                $items[] = [
                    'kind'   => 'nav',
                    'nav_id' => $navId,
                    'page'   => $page,
                    'url'    => $url,
                    'label'  => '栏目页 ' . $url,
                ];
            }
        }
    }

    /* ---- 文档页 ---- */
    if ($needDoc) {
        if ($byDate) {
            //按日期：只用上面查出来的命中文档
            $docs = $hitDocs;
        } else {
            $query = \think\facade\Db::name('document')
                ->field('id, nav_id')
                ->where('is_del', 0)
                ->where('status', 1)->where('arcrank', '>=', 0)
                ->whereIn('nav_id', $navIds);

            try {
                $docs = $query->order('id desc')->select()->toArray();
            } catch (\Throwable $e) {
                $docs = [];
            }
        }

        foreach ($docs as $doc) {
            $navId = (int) $doc['nav_id'];
            $url   = doc_url(['id' => (int) $doc['id'], 'nav_id' => $navId], $navMap[$navId] ?? null);
            $items[] = [
                'kind'   => 'doc',
                'doc_id' => (int) $doc['id'],
                'url'    => $url,
                'label'  => '文档页 ' . $url,
            ];
        }
    }

    /* ---- 标签页 ---- */
    if ($needTags) {
        $tagIds = [];
        if ($byDate) {
            foreach ($hitDocs as $doc) {
                $rawTagIds = trim((string) ($doc['tag_ids'] ?? ''), ", \t\n\r\0\x0B");
                if ($rawTagIds === '') {
                    continue;
                }
                foreach (explode(',', $rawTagIds) as $tagId) {
                    $tagId = (int) $tagId;
                    if ($tagId > 0) {
                        $tagIds[$tagId] = $tagId;
                    }
                }
            }
        }

        try {
            $tags = [];
            if (!$byDate || $tagIds) {
                $query = \think\facade\Db::name('keywords')
                    ->field('id, title')
                    ->where('status', 1);
                if ($byDate) {
                    $query->whereIn('id', array_values($tagIds));
                }
                $tags = $query->order('id asc')->select()->toArray();
            }
        } catch (\Throwable $e) {
            return $fail('读取标签失败：' . $e->getMessage());
        }

        if (!$tags && $type === 'tags') {
            return $fail('没有可生成的标签页，请先在「基础 → 标签管理」启用标签');
        }

        try {
            foreach ($tags as $tag) {
                $tagId = (int) ($tag['id'] ?? 0);
                if ($tagId < 1) {
                    continue;
                }

                $pages = miu_tag_list_page_count($tagId);
                for ($page = 1; $page <= $pages; $page++) {
                    $url     = miu_tag_url($tagId, $page);
                    $items[] = [
                        'kind'   => 'tag',
                        'tag_id' => $tagId,
                        'page'   => $page,
                        'url'    => $url,
                        'label'  => '标签页「' . (string) ($tag['title'] ?? '') . '」 ' . $url,
                    ];
                }
            }
        } catch (\Throwable $e) {
            return $fail('统计标签文章页数失败：' . $e->getMessage());
        }
    }

    /* ---- Sitemap ---- */
    if ($type === 'sitemap') {
        $types = array_values(array_intersect(['xml', 'txt', 'html', 'llms'], (array) ($opt['sitemap'] ?? [])));
        if (!$types) {
            return $fail('请至少选择一种地图类型（xml / txt / html / llms）');
        }
        foreach ($types as $t) {
            $items[] = ['kind' => 'sitemap', 'stype' => $t, 'label' => strtoupper($t) . ' 地图'];
        }
    }

    if (!$items) {
        return $fail('没有需要生成的页面');
    }

    return ['items' => $items, 'error' => ''];
}

/**
 * 渲染一个页面，统一成字符串返回
 *
 * 渲染函数正常返回字符串，出错（模板不存在、栏目不存在等）时返回的是 Response，
 * 两种都要能接住。
 *
 * @param callable $fn
 * @return string
 */
function createhtml_render(callable $fn): string
{
    $res = $fn();

    if (is_string($res)) {
        return $res;
    }
    if ($res instanceof \think\Response) {
        return (string) $res->getContent();
    }

    return (string) $res;
}

/**
 * 写静态文件（目录不存在自动建）
 *
 * @param string      $file
 * @param string      $html
 * @param string|null $err 失败原因
 * @return bool
 */
function createhtml_write(string $file, string $html, ?string &$err = null): bool
{
    $err = '';
    $dir = dirname($file);

    if (!is_dir($dir) && !@mkdir($dir, 0755, true)) {
        $err = '目录不存在且创建失败：' . $dir;
        return false;
    }

    if (@file_put_contents($file, $html) === false) {
        $err = '写入失败，检查目录是否可写：' . $dir;
        return false;
    }

    return true;
}

/**
 * 生成清单里的一项
 *
 * @param array       $item
 * @param string|null $err 失败原因
 * @return bool
 */
function createhtml_run_item(array $item, ?string &$err = null): bool
{
    $err  = '';
    $kind = (string) ($item['kind'] ?? '');

    //作为最后一道保护，后台自动更新也不能把 /404 写成 public/404。
    if ($kind !== 'sitemap' && createhtml_is_reserved_404_url((string) ($item['url'] ?? ''))) {
        return true;
    }

    $hasLocale = $kind !== 'sitemap' && array_key_exists('locale', $item);
    $previousLocale = $hasLocale
        ? \app\home\service\TemplateI18n::setLocale((string) $item['locale'])
        : null;
    $hasCurrentUrl = $kind !== 'sitemap' && array_key_exists('url', $item);
    $previousUrl = $hasCurrentUrl
        ? \app\home\service\TemplateI18n::setCurrentUrl((string) $item['url'])
        : null;

    try {
        //Sitemap 复用现成的地图生成
        if ($kind === 'sitemap') {
            $previousLocale = \app\home\service\TemplateI18n::setLocale(\app\home\service\TemplateI18n::defaultLocale());
            $res = sitemap_build((string) ($item['stype'] ?? 'xml'));
            if (!empty($res['errors'])) {
                $err = implode('；', $res['errors']);
                return false;
            }
            return true;
        }

        if ($kind === 'home') {
            miu_assign_page_meta([], (string) ($item['url'] ?? '/'));
            $html = \think\facade\View::fetch('/index');
        } elseif ($kind === 'nav') {
            $navId = (int) ($item['nav_id'] ?? 0);
            $page  = (int) ($item['page'] ?? 1);
            $html  = createhtml_render(function () use ($navId, $page) {
                return miu_render_nav_list($navId, $page);
            });
        } elseif ($kind === 'tag') {
            $tagId = (int) ($item['tag_id'] ?? 0);
            $page  = (int) ($item['page'] ?? 1);
            $html  = createhtml_render(function () use ($tagId, $page) {
                return miu_render_tag_list($tagId, $page);
            });
        } else {
            $docId = (int) ($item['doc_id'] ?? 0);
            $html  = createhtml_render(function () use ($docId) {
                return miu_render_doc_detail($docId);
            });
        }

        \app\extend\plugin\Hook::instance()->trigger('static_page_ready', $html);

        return createhtml_write(createhtml_file_of_url((string) ($item['url'] ?? '')), $html, $err);
    } catch (\Throwable $e) {
        $err = $e->getMessage();
        return false;
    } finally {
        if ($hasCurrentUrl) {
            \app\home\service\TemplateI18n::setCurrentUrl($previousUrl);
        }
        if ($hasLocale || $kind === 'sitemap') {
            \app\home\service\TemplateI18n::setLocale($previousLocale);
        }
    }
}
