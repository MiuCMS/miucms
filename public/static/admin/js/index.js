/**
 * 后台首页（框架页）脚本
 * 由 app/admin/view/index/index.html 的 {block name="script"} 引入
 *
 * 依赖：
 *   - layui              base.html 的 head 里已加载 layui.js / init.js
 *   - window.MIU_MENU    菜单数据，由 index.html 的 {block name="body"} 注入
 *   - window.MIU_ADMIN   后台入口前缀（如 /admin123），由 base.html 注入
 *
 * 接口地址一律用 MIU_ADMIN + "/xxx/yyy" 拼，后台入口改名后这里不用跟着改。
 */
layui.use(['tool', 'tree', 'admin'], function () {
    'use strict';

    var $win = $(window);

    /* ==================== 公共小工具 ==================== */

    // PHP 关联数组 json_encode 后是对象，统一转成数组
    function toArray(v) {
        if (v === null || v === undefined) return [];
        if (Array.isArray(v)) return v;
        if (typeof v === 'object') return Object.values(v);
        return [];
    }

    // 把弹层水平居中挂在某个元素正下方。
    // 弹层都在外层、用 position:fixed，所以 left 只能由 JS 按锚点位置算
    function placeBelow($box, $anchor) {
        var r = $anchor[0].getBoundingClientRect();      // 获取锚点元素相对于视口的位置和尺寸
        var w = $box.outerWidth();                        // 获取弹框的宽度（含 padding、border）
        var left = r.left + r.width / 2 - w / 2;          // 初始 left：锚点中心点 - 弹框一半 = 水平居中
        var max = $win.width() - w - 8;                   // 允许的最大 left 值（右边界留 8px 边距）
        if (left > max) left = max;                       // 超出右边界则贴边
        if (left < 8) left = 8;                           // 超出左边界则贴边（左侧也留 8px）
        $box.css('left', Math.round(left) + 'px');        // 应用计算后的 left 值（四舍五入取整）
    }

    /* ==================== 主题切换 ==================== */
    layui.dropdown.render({
        elem: '#theme',
        trigger: 'mousedown',
        align: 'center',
        data: [
            {title: '经典黑', theme: 'black'},
            {title: '简约白', theme: 'white'},
            {title: '海军蓝', theme: 'blue'},
        ],
        click: function (data) {
            $.ajax({
                url: MIU_ADMIN + '/index/set_theme',
                data: {theme: data.theme},
                success: function (e) {
                    layer.msg(e.msg);
                    if (e.code == 0) {
                        setTimeout(function () { parent.location.reload(); }, 1000);
                    }
                }
            });
        }
    });

    /* ==================== 菜单数据 ==================== */
    var menuData = toArray(window.MIU_MENU);

    // PHP list_to_tree 生成的是关联数组，json_encode + Object.values 之后
    // 整数键会按数值升序枚举，后端的 sort 字段就失效了，这里递归按 sort 重排
    (function sortBySort(items) {
        items.sort(function (a, b) {
            return (parseInt(a.sort, 10) || 0) - (parseInt(b.sort, 10) || 0);
        });
        items.forEach(function (item) {
            if (item.list) item.list = sortBySort(toArray(item.list));
        });
        return items;
    })(menuData);

    var $layout = $('#mainLayout'),
        $subTitle = $('#subTitle'),
        $subNav = $('#subNav');

    /* ==================== 打开 iframe 标签页 ==================== */
    function openAdminTab(title, href, id) {
        // 优先走 layui element 的 tab API
        if (layui.element && typeof layui.element.tabAdd === 'function') {
            var $tabUl = $('#pageTabUl');
            var exists = false;

            $tabUl.find('li').each(function () {
                if ($(this).attr('lay-id') == id) {
                    exists = true;
                    layui.element.tabChange('miucms-admin-tab', id);
                    // 已存在就把 iframe 的地址换掉
                    var $page = $('#tabItem' + id);
                    if ($page.length) $page.find('iframe').attr('src', href);
                    return false;
                }
            });

            if (!exists) {
                // 与 admin.js 的 tab.tabAdd 保持一致：带上 url，
                // 否则 li 上没有 lay-url，标签持久化时拿不到地址
                layui.element.tabAdd('miucms-admin-tab', {
                    title: '<span>' + title + '</span>',
                    id: id,
                    url: href
                });
                $('#MiuAppBody').append(
                    '<div class="miucms-tab-page" title="' + title + '" id="tabItem' + id + '" data-id="' + id + '">' +
                    '<iframe id="' + id + '" data-frameid="' + id + '" data-timestamp="' + new Date().getTime() + '" name="myiframe" src="' + href + '" frameborder="0" align="left" width="100%" height="100%" scrolling="yes"></iframe></div>'
                );
                layui.element.tabChange('miucms-admin-tab', id);
            }
            return;
        }

        // 兜底：直接改当前 iframe 的地址
        var $showPage = $('.miucms-tab-page.layui-show');
        if ($showPage.length) {
            $showPage.find('iframe').attr('src', href);
        } else {
            $('#tabItem0 iframe').attr('src', href);
        }
    }

    /* ==================== 中间二级菜单（layui 树） ==================== */
    var currentSubTree = [];   // 当前渲染的树数据，点击时用来反查节点

    // 菜单数据 -> 树组件数据结构
    // 注意：tree.js 的 updateFieldValue 只在字段已存在时才写回，
    // 所以每个节点都要补上 spread 默认值，否则展开状态存不下来
    function normalizeTreeData(items) {
        return toArray(items).map(function (item) {
            var node = {
                id: item.id,
                title: item.title || '',
                src: item.src || '',
                spread: !!item.spread
            };
            var children = normalizeTreeData(item.list);
            if (children.length) node.children = children;
            return node;
        });
    }

    // 把插件 menu.php 的数据转换为当前树组件使用的结构。
    function normalizePluginMenuData(items, pluginName, path) {
        return toArray(items).map(function (item, index) {
            if (!item || typeof item !== 'object') return null;
            var node = {
                id: item.id || ('plugin_' + pluginName + '_' + path + '_' + index),
                title: String(item.title || ''),
                src: String(item.src || item.url || ''),
                spread: !!item.spread
            };
            var children = normalizePluginMenuData(item.list || item.children, pluginName, path + '_' + index);
            if (children.length) node.list = children;
            return node;
        }).filter(function (item) {
            return item && item.title !== '';
        });
    }

    // 递归查找树节点
    function findTreeNode(id, nodes) {
        nodes = nodes || currentSubTree;
        for (var i = 0; i < nodes.length; i++) {
            if (String(nodes[i].id) === String(id)) return nodes[i];
            var found = findTreeNode(id, toArray(nodes[i].children));
            if (found) return found;
        }
        return null;
    }

    // 展开指定节点的所有父级（保证当前栏目可见）
    function expandPath(nodes, id) {
        for (var i = 0; i < nodes.length; i++) {
            if (String(nodes[i].id) === String(id)) return true;
            if (expandPath(toArray(nodes[i].children), id)) {
                nodes[i].spread = true;
                return true;
            }
        }
        return false;
    }

    // 取当前激活标签页对应的菜单 id
    function getActiveTabId() {
        return $('#pageTabUl li.layui-this').attr('lay-id') || '';
    }

    // 高亮中间面板中对应的整行
    function highlightSubTree(id) {
        $subNav.find('.layui-tree-entry.sub-nav-active').removeClass('sub-nav-active');
        if (id === undefined || id === null || id === '') return;
        $subNav.find('.layui-tree-set[data-id="' + id + '"]')
            .children('.layui-tree-entry')
            .addClass('sub-nav-active');
    }

    // 渲染中间面板（按有无子菜单显示 / 隐藏）
    function renderSubPanel(menuItem) {
        if (!menuItem) return;
        var list = toArray(menuItem.list);

        if (!list.length && !menuItem.showEmpty) {
            $layout.removeClass('has-sub').addClass('no-sub');
            currentSubTree = [];
            $subNav.empty();
            return;
        }

        $layout.removeClass('no-sub').addClass('has-sub');
        var iconClass = String(menuItem.icon || '').trim();
        $subTitle.empty();
        if (/^[A-Za-z0-9 _-]*$/.test(iconClass)) {
            $subTitle.append($('<i>').addClass('icon layui-icon ' + iconClass));
        }
        $subTitle.append(document.createTextNode((iconClass ? ' ' : '') + String(menuItem.title || '菜单')));

        // 树数据缓存在一级菜单对象上，重渲染时展开状态不会丢
        if (!menuItem._subTree) menuItem._subTree = normalizeTreeData(list);
        currentSubTree = menuItem._subTree;

        // 当前标签页所属节点自动展开
        var activeId = getActiveTabId();
        if (activeId !== '') expandPath(currentSubTree, activeId);

        layui.tree.render({
            elem: '#subNav',
            data: currentSubTree,
            showLine: true,
            onlyIconControl: true,
            customName: {id: 'id', title: 'title', children: 'children'}
        });

        highlightSubTree(activeId);
    }

    // 按 id 递归查菜单项（支持任意层级）
    function findMenuItem(id) {
        if (id === 0 || id === '0') return {id: 0, title: '概览', src: 'admin/index/main', list: []};
        function search(items) {
            for (var i = 0; i < items.length; i++) {
                if (String(items[i].id) === String(id)) return items[i];
                var found = search(toArray(items[i].list));
                if (found) return found;
            }
            return null;
        }
        return search(menuData);
    }

    // 查 id 所属的一级菜单（tab 切换时用它渲染对应的二级菜单栏）
    function findTopLevelMenu(id) {
        if (id === 0 || id === '0') return {id: 0, title: '概览', src: 'admin/index/main', list: []};

        function contains(items) {
            return toArray(items).some(function (item) {
                return String(item.id) === String(id) || contains(item.list);
            });
        }
        for (var i = 0; i < menuData.length; i++) {
            if (String(menuData[i].id) === String(id)) return menuData[i];
            if (contains(menuData[i].list)) return menuData[i];
        }
        return null;
    }

    // 找第一个有实际链接的子菜单（一级菜单自己没链接时，打开其下第一个可用的）
    function findFirstLeaf(item) {
        var list = toArray(item && item.list);
        for (var i = 0; i < list.length; i++) {
            if (list[i] && list[i].src) return list[i];
            var deeper = findFirstLeaf(list[i]);
            if (deeper) return deeper;
        }
        return null;
    }

    // 暴露给 admin.js（tab 切换事件里会调用）和 iframe 内的脚本
    window.findMenuItem = findMenuItem;
    window.findTopLevelMenu = findTopLevelMenu;
    window.renderSubPanel = renderSubPanel;

    // 插件列表 iframe 点击「插件首页」时，临时把该插件菜单接入现有二级菜单渲染流程。
    window.openPluginHome = function (plugin, href) {
        if (!plugin || !href) return false;
        var name = String(plugin.name || '');
        if (!/^[A-Za-z][A-Za-z0-9_]*$/.test(name)) return false;

        // 移除该插件之前注入的临时项，避免重复点击后菜单数据不断累积。
        for (var i = menuData.length - 1; i >= 0; i--) {
            if (menuData[i] && menuData[i]._pluginMenuName === name) menuData.splice(i, 1);
        }

        // 一级菜单标题放在 #subTitle；#subNav 只渲染一级菜单的 children。
        // 多个一级菜单时合并其子菜单，并在标题栏用斜线分隔一级菜单名。
        var pluginMenus = normalizePluginMenuData(plugin.admin_menu, name, '0');
        var menuTitles = [];
        var list = [];
        pluginMenus.forEach(function (item) {
            if (item.title) menuTitles.push(item.title);
            var children = toArray(item.list);
            if (children.length) list = list.concat(children);
        });

        if (list.length) {
            var pluginMenu = {
                id: name,
                title: menuTitles.join(' / ') || String(plugin.title || name),
                icon: String(plugin.icon || 'layui-icon-star'),
                src: '',
                list: list,
                _pluginMenuName: name
            };
            menuData.push(pluginMenu);
            $layout.removeClass('sub-collapsed');
            renderSubPanel(pluginMenu);
        } else {
            // 当前账号没有可显示的插件菜单时，避免残留上一个插件的二级菜单。
            renderSubPanel({list: []});
        }

        openAdminTab(String(plugin.title || name) + '首页', href, name);
        return true;
    };

    /* ==================== 左侧一级菜单 ==================== */
    function highlightL1(id) {
        $('#menuList > li').removeClass('active layui-this');
        $('#menuList > li[data-id="' + id + '"]').addClass('active layui-this');
    }

    // 用 capture 阶段监听，确保在 layui 自己的处理之前拿到点击
    var menuListEl = document.getElementById('menuList');
    if (menuListEl) {
        menuListEl.addEventListener('click', function (e) {
            var li = e.target.closest('#menuList > li[data-id]');
            if (!li) return;

            var id = li.getAttribute('data-id');
            var title = li.getAttribute('data-title') || '';
            var href = li.getAttribute('data-href') || '';

            highlightL1(id);

            var item = findMenuItem(id);
            if (!item) return;

            renderSubPanel(item);
            // 切换一级菜单时把中间面板展开（收起状态自动恢复）
            $layout.removeClass('sub-collapsed');

            // 一级菜单自己没有落地页时，打开其下第一个有链接的二级菜单
            if (!href || href === '/' || href === '#') {
                var firstLeaf = findFirstLeaf(item);
                if (firstLeaf) {
                    title = firstLeaf.title;
                    href = firstLeaf.src || '';
                    id = firstLeaf.id;
                }
            }
            openAdminTab(title, href, id);
        }, true);
    }

    // 中间菜单树：整行点击（有子菜单的展开 / 收起，叶子节点打开对应页面）
    $subNav.on('click', '.layui-tree-entry', function (e) {
        var $entry = $(this);
        var node = findTreeNode($entry.closest('.layui-tree-set').attr('data-id'));
        if (!node) return;

        if (toArray(node.children).length) {
            // 点在箭头上时交给 layui 自己处理，避免重复切换
            if ($(e.target).closest('.layui-tree-iconClick').length) return;
            // 借用 layui 的展开逻辑，它会把 spread 状态写回数据源
            $entry.find('.layui-tree-iconClick').trigger('click');
            return;
        }

        if (!node.src || node.src === '/') return;
        openAdminTab(node.title, node.src, node.id);
        highlightSubTree(node.id);
    });

    // 初始化：默认高亮「概览」（它没有二级菜单，中间面板隐藏）
    renderSubPanel({id: 0, title: '概览', src: 'admin/index/main', list: []});

    /* ==================== 注销登录 ==================== */
    $('#MiuApp').on('click', '[miu-event="logout"]', function () {
        layer.confirm('确认注销登录吗?', {icon: 7, title: '警告'}, function (index) {
            $.ajax({
                url: MIU_ADMIN + '/login/login_out',
                type: 'POST',
                success: function (e) {
                    layer.msg(e.msg);
                    if (e.code == 0) {
                        setTimeout(function () {
                            location.href = MIU_ADMIN + '/login/index';
                        }, 1000);
                    }
                }
            });
            layer.close(index);
        });
    });

    /* ==================== 未读留言角标 ==================== */
    var formUnreadCount = parseInt($('#miuNoticeCount').text(), 10) || 0;
    var upgradeNoticeAvailable = false;

    function syncNoticeCount() {
        var total = formUnreadCount + (upgradeNoticeAvailable ? 1 : 0);
        var $badge = $('#formUnreadBadge');
        if (total > 0) {
            $badge.show().find('span').text(total);
        } else {
            $badge.hide();
        }
        $('#miuNoticeEmpty').toggle(total === 0);
    }

    // 供各标签页调用，升级提示与留言数共用消息中心角标。
    window.__miuUpdateFormUnread = function (count) {
        formUnreadCount = Math.max(0, parseInt(count, 10) || 0);
        $('#miuNoticeCount').text(formUnreadCount);
        $('#miuNoticeMsg').toggle(formUnreadCount > 0);
        syncNoticeCount();
    };

    // 升级标签页成功后同步清除已完成的升级通知。
    window.__miuClearUpgradeNotice = function () {
        upgradeNoticeAvailable = false;
        $('#miuUpgradeNotice').hide();
        syncNoticeCount();
    };

    // 每次进入后台首页都检查最新版本，有可升级版本时显示通知。
    (function () {
        var $app = $('#MiuApp');
        var checkUrl = $app.attr('data-upgrade-check-url');
        var token = $app.attr('data-upgrade-token');
        if (!checkUrl || !token) return;
        $.post(checkUrl, {token: token}, function (res) {
            var status = res && res.data;
            if (res && res.code === 0 && status && status.available && status.release) {
                upgradeNoticeAvailable = true;
                $('#miuUpgradeNoticeVersion').text(status.release.version);
                $('#miuUpgradeNotice').show();
                syncNoticeCount();
            }
        }, 'json');
    })();

    /* ==================== 顶部「系统通知」：鼠标移入图标显示弹层 ==================== */
    (function () {
        var $msg = $('.miucms-head-message');
        var $box = $('#miuNotice');
        if (!$msg.length || !$box.length) return;

        var hideTimer = null;

        function show() {
            clearTimeout(hideTimer);
            $box.addClass('show');
            placeBelow($box, $msg);   // 先显示再量宽度，display:none 时量不到
        }
        function hide() {
            clearTimeout(hideTimer);
            // 图标和面板之间有一小段空隙，鼠标穿过去会触发 mouseleave，
            // 延迟一点再收；进了面板会重新触发 mouseenter 把定时器清掉，所以不会闪
            hideTimer = setTimeout(function () { $box.removeClass('show'); }, 150);
        }

        // 弹层在外层，鼠标从图标移到面板上会先离开 $msg，所以面板自己也要接管
        $msg.on('mouseenter', show).on('mouseleave', hide);
        $box.on('mouseenter', show).on('mouseleave', hide);

        $win.on('resize', function () {
            if ($box.hasClass('show')) placeBelow($box, $msg);
        });
    })();

    /* ==================== 顶部「功能搜索」：搜后台菜单，点结果按 tab-a 开标签 ==================== */
    (function () {
        var $item = $('.miucms-head-search');
        var $box = $('#miuSearch');
        var $input = $('#miuSearchInput');
        var $list = $('#miuSearchList');
        var $mask = $('#miuSearchMask');
        if (!$item.length || !$box.length) return;

        // 把菜单树拍平：只保留有落地页(src)的节点，顺带记下父级路径（结果里显示出来更好认）
        var flatMenu = [];
        (function walk(items, parents) {
            toArray(items).forEach(function (item) {
                var path = parents.concat(item.title || '');
                if (item.src) {
                    flatMenu.push({id: item.id, title: item.title || '', src: item.src, path: path.join(' / ')});
                }
                if (item.list) walk(item.list, path);
            });
        })(menuData, []);

        function esc(s) {
            return String(s == null ? '' : s)
                .replace(/&/g, '&amp;').replace(/</g, '&lt;')
                .replace(/>/g, '&gt;').replace(/"/g, '&quot;');
        }

        function render() {
            var kw = $.trim($input.val()).toLowerCase();
            if (!kw) {
                $list.removeClass('show').empty();
                return;
            }
            var hit = flatMenu.filter(function (m) {
                return m.title.toLowerCase().indexOf(kw) !== -1 ||
                       m.path.toLowerCase().indexOf(kw) !== -1 ||
                       m.src.toLowerCase().indexOf(kw) !== -1;
            });
            if (!hit.length) {
                $list.html('<div class="miu-search-empty">没有找到相关菜单</div>').addClass('show');
                return;
            }
            var html = '';
            hit.slice(0, 50).forEach(function (m) {
                // a 上带 tab-a：交给 tool.js 挂在 body 上的 .tab-a 处理器开标签
                html += '<li><a class="tab-a" data-id="' + esc(m.id) + '" data-title="' + esc(m.title) + '" data-href="' + esc(m.src) + '">' +
                        esc(m.title) +
                        (m.path.indexOf(' / ') !== -1 ? '<span class="path">' + esc(m.path) + '</span>' : '') +
                        '</a></li>';
            });
            $list.html(html).addClass('show');
        }

        function openBox() {
            $input.val('');
            $list.removeClass('show').empty();
            $box.addClass('show');
            placeBelow($box, $item);
            $mask.addClass('show');
            setTimeout(function () { $input.trigger('focus'); }, 0);
        }
        function closeBox() {
            $box.removeClass('show');
            $mask.removeClass('show');
        }

        // 点遮罩关闭：iframe 是独立 document，里面的点击不会冒泡到父页面，
        // 靠这层透明遮罩接住「点下面空白处」这一下
        $mask.on('click', closeBox);

        // 点搜索图标：开 / 关
        $item.on('click', 'a.search', function () {
            $box.hasClass('show') ? closeBox() : openBox();
            return false;
        });

        // 输入即搜
        $input.on('input', render);

        // 点结果：先收起弹层；不阻断冒泡，让 .tab-a 处理器照常开标签
        $list.on('click', 'li', closeBox);

        // 点别处 / 按 ESC 关闭。
        // 弹层已经移到外层，不再是 .miucms-head-search 的后代，所以得单独排除
        $(document).on('click', function (e) {
            if ($(e.target).closest('.miucms-head-search').length) return;
            if ($(e.target).closest('#miuSearch').length) return;
            closeBox();
        });
        $input.on('keydown', function (e) {
            if (e.keyCode === 27) closeBox();
        });

        $win.on('resize', function () {
            if ($box.hasClass('show')) placeBelow($box, $item);
        });
    })();

    /* ==================== 顶部「本地便签」：layer 弹窗，右下角可拉伸，内容存 localStorage ==================== */
    (function () {
        var $item = $('.miucms-head-note');
        if (!$item.length) return;

        var NOTE_KEY = 'miu_admin_note';
        var noteIndex = null;   // 当前便签弹窗的 layer 索引，null 表示没开

        function openNote() {
            if (noteIndex !== null) return;

            // 挂在便签图标正下方：左右居中、顶边贴着图标底部
            var W = 300, H = 180;
            var rect = $item[0].getBoundingClientRect();
            var left = rect.left + rect.width / 2 - W / 2;
            if (left < 10) left = 10;
            if (left + W > $win.width() - 10) left = $win.width() - W - 10;

            noteIndex = layer.open({
                type: 1,
                title: '本地便签',
                skin: 'miu-note-layer',
                area: [W + 'px', H + 'px'],
                offset: [Math.round(rect.bottom + 6), Math.round(left)],
                shade: 0,
                resize: true,   // 右下角拖拽拉伸，宽高都能改（拖标题栏移动是 layer 默认行为）
                content: '<textarea class="miu-note-body" id="miuNoteBody" spellcheck="false" placeholder="便签中的内容会存储在本地，这样即便你关掉了浏览器，在下次打开时，依然会读取到上一次的记录。是个非常小巧实用的本地备忘录。"></textarea>',
                success: function (layero) {
                    var $body = layero.find('#miuNoteBody');
                    // 回填上次记录（隐私模式下 localStorage 可能不可用，兜一下）
                    try { $body.val(localStorage.getItem(NOTE_KEY) || ''); } catch (e) {}
                    // 输入即存
                    $body.on('input', function () {
                        try { localStorage.setItem(NOTE_KEY, $body.val()); } catch (e) {}
                    });
                    setTimeout(function () { $body.trigger('focus'); }, 0);
                },
                end: function () { noteIndex = null; }
            });
        }

        function closeNote() {
            if (noteIndex !== null) layer.close(noteIndex);
        }

        // 点图标开 / 关
        $item.on('click', 'a[miu-event="note"]', function () {
            noteIndex === null ? openNote() : closeNote();
            return false;
        });

        // 点弹层外 / 按 ESC 关闭。
        // 用 mousedown 而不是 click：拖拽拉伸时 mousedown 一定落在弹层内的手柄上，
        // 不会像 click 那样因为「按下和抬起不在同一元素」而冒到 document 上误关
        $(document).on('mousedown', function (e) {
            if (noteIndex === null) return;
            if ($(e.target).closest('.layui-layer').length) return;
            if ($(e.target).closest('.miucms-head-note').length) return;
            closeNote();
        });
        $(document).on('keydown', function (e) {
            if (e.keyCode === 27) closeNote();
        });
    })();

    /* ==================== 标签卡右键菜单 ==================== */
    (function () {
        var $menu = $('#miuTabMenu');
        var $tabTitle = $('#pageTabUl');
        if (!$menu.length || !$tabTitle.length) return;

        function hideMenu() {
            $menu.removeClass('show');
        }

        function showMenu(x, y) {
            $menu.addClass('show');
            // 先显示出来才能量到宽高，再按视口边缘收一下，免得菜单跑出屏幕
            var w = $menu.outerWidth(), h = $menu.outerHeight();
            if (x + w > $win.width() - 4) x = $win.width() - w - 4;
            if (y + h > $win.height() - 4) y = $win.height() - h - 4;
            $menu.css({left: Math.max(4, x) + 'px', top: Math.max(4, y) + 'px'});
        }

        // 右键标签栏弹菜单。
        // 4 个菜单项都带 miu-event，直接复用 admin.js 挂在 #MiuApp 上的处理器；
        // 那些处理器都是对「当前标签」操作的，所以右键某个标签时先把它切成当前
        $tabTitle.on('contextmenu', function (e) {
            e.preventDefault();
            var $li = $(e.target).closest('li[lay-id]');
            if ($li.length) {
                var id = $li.attr('lay-id');
                if ($tabTitle.children('li.layui-this').attr('lay-id') !== id && layui.admin) {
                    layui.admin.tabChange(id);
                }
            }
            showMenu(e.clientX, e.clientY);
            return false;
        });

        // 点菜单项后收起（不阻断冒泡，让 miu-event 处理器照常跑）
        $menu.on('click', 'li', hideMenu);

        // 点别处 / 按 ESC / 窗口尺寸变化 都收起
        $(document).on('click', hideMenu);
        $(document).on('keydown', function (e) {
            if (e.keyCode === 27) hideMenu();
        });
        $win.on('resize', hideMenu);
    })();

    /* ==================== 标签卡拖动排序 ==================== */
    (function () {
        var $title = $('#pageTabUl');
        if (!$title.length) return;

        // lay-id="0" 是「概览」首页标签，固定不动，不参与排序
        function isFixed($li) {
            return String($li.attr('lay-id')) === '0';
        }

        // li 得显式 draggable="true" 才能拖。新打开的标签也要补上，
        // 所以用 MutationObserver 盯着（和上面滚动箭头的做法一致）
        function markDraggable() {
            $title.children('li[lay-id]').each(function () {
                this.draggable = !isFixed($(this));
            });
        }
        markDraggable();
        if (window.MutationObserver) {
            new MutationObserver(markDraggable).observe($title[0], {childList: true});
        }

        var $drag = null;
        var $tabsWrap = $('#pageTabs');

        // 把浏览器自带的拖影藏掉：标签栏里已经有「实时跟手的标签 + 红框」了，拖影是重复的。
        // 用 1×1 的透明 canvas 当拖影，不用图片是为了省掉「等图片加载完」这一步
        var blankDragImg = document.createElement('canvas');
        blankDragImg.width = blankDragImg.height = 1;

        /* ---- 落点指示框（红色虚线框）---- */
        // 拖动时被拖的标签会实时跟着鼠标挪（见下面的 dragover），其他标签被挤开；
        // 这个框一直框住被拖标签当前的位置，也就是松手后会落在的地方。
        // 框挂在 #pageTabs 下（它是 position:fixed），不塞进 <ul> 里，
        // 免得干扰 layui 对 tab 子节点的假设
        var $line = $('<div class="tab-drop-line"></div>').appendTo($tabsWrap);

        function showLine(li) {
            var wrapRect = $tabsWrap[0].getBoundingClientRect();
            var r = li.getBoundingClientRect();
            $line.css({
                left: (r.left - wrapRect.left) + 'px',
                top: (r.top - wrapRect.top) + 'px',
                width: r.width + 'px',
                height: r.height + 'px',
                display: 'block'
            });
        }
        function hideLine() {
            $line.css('display', 'none');
        }

        /* ---- 拖到标签栏边缘时自动横向滚动 ---- */
        var EDGE = 40;               // 距左右边缘多少像素内开始滚（正好是箭头按钮的宽度）
        var MAX_SPEED = 9;           // 每帧最多滚多少像素（约 60fps）
        var scrollDir = 0;           // -1 往左，1 往右，0 不滚
        var scrollSpeed = 0;
        var scrollTimer = null;

        function stepScroll() {
            if (!scrollDir) return;
            var el = $title[0];
            // 标签栏是块级元素，clientWidth 是可视宽、scrollWidth 是内容总宽
            var minLeft = Math.min(0, el.clientWidth - el.scrollWidth);
            var cur = parseFloat($title.css('left')) || 0;
            var next = cur - scrollDir * scrollSpeed;
            if (next > 0) next = 0;
            if (next < minLeft) next = minLeft;
            $title.css('left', next + 'px');
            // 标签栏滚了，指示框跟着重算（滚动期间不会触发 dragover）
            if ($drag) showLine($drag[0]);
        }

        function startScroll(dir, speed) {
            scrollDir = dir;
            scrollSpeed = speed;
            if (!scrollTimer) scrollTimer = setInterval(stepScroll, 16);
        }

        function stopScroll() {
            scrollDir = 0;
            if (scrollTimer) {
                clearInterval(scrollTimer);
                scrollTimer = null;
            }
        }

        // 越贴边滚得越快：距边缘 EDGE 像素时最慢，贴住边缘（甚至超出）时最快
        function edgeSpeed(dist) {
            var d = Math.min(EDGE, Math.max(-EDGE, dist));
            return Math.max(2, Math.round((EDGE - d) / EDGE * MAX_SPEED));
        }

        // 绑在 document 上而不是标签栏上：拖到最后一个标签右侧的空白处时，
        // li 上的 dragover 不会触发，只有容器级才能接住
        $(document).on('dragover', function (e) {
            if (!$drag) return;
            var r = $title[0].getBoundingClientRect();
            var x = e.originalEvent.clientX, y = e.originalEvent.clientY;
            // 纵向离开标签栏就不滚了
            if (y < r.top - 4 || y > r.bottom + 4) { stopScroll(); return; }
            var leftDist = x - r.left, rightDist = r.right - x;
            if (leftDist < EDGE) {
                startScroll(-1, edgeSpeed(leftDist));
            } else if (rightDist < EDGE) {
                startScroll(1, edgeSpeed(rightDist));
            } else {
                stopScroll();
            }
        });

        $title.on('dragstart', 'li[lay-id]', function (e) {
            if (isFixed($(this))) {
                e.preventDefault();
                return;
            }
            $drag = $(this);
            var dt = e.originalEvent.dataTransfer;
            if (dt) {
                dt.effectAllowed = 'move';
                dt.setData('text/plain', $(this).attr('lay-id'));   // Firefox 不 setData 就不会进入拖拽
                if (dt.setDragImage) dt.setDragImage(blankDragImg, 0, 0);
            }
            $(this).addClass('tab-dragging');
        });

        // 经过某个标签：把被拖标签实时挪到落点，后面的标签自然被挤开；
        // 红框跟着框住它当前的位置，也就是松手后会落在的地方
        $title.on('dragover', 'li[lay-id]', function (e) {
            if (!$drag || this === $drag[0] || isFixed($(this))) return;
            e.preventDefault();
            var dt = e.originalEvent.dataTransfer;
            if (dt) dt.dropEffect = 'move';
            // 鼠标在目标标签的左半边 → 插到它前面；右半边 → 插到它后面
            var rect = this.getBoundingClientRect();
            if ((e.originalEvent.clientX - rect.left) > rect.width / 2) {
                $(this).after($drag);
            } else {
                $(this).before($drag);
            }
            showLine($drag[0]);
        });

        // 标签栏空白处也要 preventDefault，否则在最后一个标签右边的空白里松手不会触发 drop
        $tabsWrap.on('dragover', function (e) {
            if ($drag) e.preventDefault();
        });

        // 被拖标签在 dragover 时就已经挪到位了，这里只收尾
        $tabsWrap.on('drop', function (e) {
            if (!$drag) return;
            e.preventDefault();
            hideLine();
            // 顺序变了，跟新增/关闭标签一样存一次（tabCookie 内部会跳过 lay-id=0 的首页）
            if (layui.admin && typeof layui.admin.tabCookie === 'function') {
                layui.admin.tabCookie();
            }
        });

        $title.on('dragend', 'li[lay-id]', function () {
            $(this).removeClass('tab-dragging');
            $drag = null;
            stopScroll();
            hideLine();
        });
    })();

    /* ==================== 锁屏 ==================== */
    var LOCK_KEY = 'miu_admin_locked';
    var $lockScreen = $('#miuLockScreen');
    var lockScreenEl = $lockScreen[0];
    var lockScreenHtml = $lockScreen.html();   // 留一份原始内容，被掏空时用来恢复
    var lockTimer = null;
    var lockActive = false;   // 内存里的锁屏标记，给防拆看守用（比每次都读 localStorage 便宜）

    function pad2(n) { return n < 10 ? '0' + n : '' + n; }

    // 时间实时跳动
    function lockClock() {
        var d = new Date();
        // 每个数字/冒号单独包一层固定宽度的 span，避免数字宽度不同导致整行左右晃
        // 小时不补零（1 点显示 1，不显示 01），分和秒补零
        var str = d.getHours() + ':' + pad2(d.getMinutes()) + ':' + pad2(d.getSeconds());
        var html = '';
        for (var i = 0; i < str.length; i++) {
            html += str.charAt(i) === ':'
                ? '<span class="miu-lock-colon">:</span>'
                : '<span class="miu-lock-digit">' + str.charAt(i) + '</span>';
        }
        $('#miuLockTime').html(html);
        $('#miuLockDate').text((d.getMonth() + 1) + '月' + d.getDate() + '日 周' + '日一二三四五六'.charAt(d.getDay()));
    }

    // 输入框在 HTML 里就是 type="text"，浏览器不会把它当密码框，也就不会弹已保存的密码。
    // 遮字符靠 CSS 的 -webkit-text-security: disc（Chrome/Edge/Safari 支持）；
    // 不支持的浏览器（如 Firefox）退回 type="password" 保证还是遮着的，
    // 那边 autocomplete="off" 是生效的，不会像 Chrome 那样忽略
    function hardenLockInput() {
        var input = $('#miuLockPwd')[0];
        if (!input) return;
        var support = !!(window.CSS && CSS.supports && CSS.supports('-webkit-text-security', 'disc'));
        input.type = support ? 'text' : 'password';
    }

    function showLock() {
        // 记一下锁屏状态：存 localStorage，刷新、开新标签页、重开浏览器都还是锁着的，
        // 直到输入密码解锁为止
        localStorage.setItem(LOCK_KEY, '1');
        lockActive = true;
        $lockScreen.addClass('miu-lock-show');
        lockClock();
        hardenLockInput();
        clearInterval(lockTimer);
        lockTimer = setInterval(lockClock, 1000);
        setTimeout(function () { $('#miuLockPwd').val('').focus(); }, 50);
    }

    function hideLock() {
        localStorage.removeItem(LOCK_KEY);
        lockActive = false;
        $lockScreen.removeClass('miu-lock-show');
        // 清掉防拆时强制写上的那些内联样式，否则解锁后遮罩还挡着页面
        lockScreenEl.removeAttribute('style');
        clearInterval(lockTimer);
        lockTimer = null;
        $('#miuLockPwd').val('');
    }

    // 点右上角「锁屏」
    $('#MiuApp').on('click', '[miu-event="lockScreen"]', function () {
        showLock();
        return false;
    });

    // 解锁：拿当前管理员的密码去后端校验
    function doUnlock() {
        var pwd = $.trim($('#miuLockPwd').val());
        if (pwd === '') {
            layer.msg('请输入管理员密码');
            return;
        }
        var loadIndex = layer.load(2);
        $.post(MIU_ADMIN + '/api/check_password', {pwd: pwd}, function (res) {
            layer.close(loadIndex);
            if (res.code == 0) {
                hideLock();
                layer.msg('解锁成功', {icon: 1});
            } else {
                layer.msg(res.msg || '密码不正确', {icon: 2});
                $('#miuLockPwd').val('').focus();
            }
        }).fail(function () {
            layer.close(loadIndex);
            layer.msg('请求失败，请重试', {icon: 2});
        });
    }
    // 用事件委托绑在 document 上：遮罩被删掉再放回来、甚至重建，事件都不会丢
    $(document).on('click', '#miuLockBtn', doUnlock);
    $(document).on('keydown', '#miuLockPwd', function (e) {
        if (e.keyCode === 13) doUnlock();
    });

    /* 锁屏防拆：锁屏期间盯着遮罩，被删掉 / 隐藏 / 掏空 / 改样式（含在控制台改 CSS 规则）
       都立刻恢复。注意：这只是挡住"随手在 F12 里改一下"，不是真正的安全边界——
       浏览器在用户手里，禁用 JS / 删掉这段看守 / 清 localStorage 都能绕过。
       真要强制，必须把锁屏状态记到服务端，在中间件里拦住后台请求 */
    var lockGuard = null;

    // 锁屏期间要保证的完整样式。用 setProperty(..., 'important') 写内联：
    // 内联的 !important 优先级最高，能压过任何被改过的 CSS 规则
    var LOCK_FORCE_CSS = {
        'display': 'flex',
        'visibility': 'visible',
        'opacity': '1',
        'position': 'fixed',
        'left': '0px',
        'top': '0px',
        'right': 'auto',
        'bottom': 'auto',
        'width': '100%',
        'height': '100%',
        'min-width': '0px',
        'max-width': 'none',
        'min-height': '0px',
        'max-height': 'none',
        'margin': '0px',
        'padding': '0px',
        'border': '0px',
        'box-sizing': 'border-box',
        'transform': 'none',
        'filter': 'none',
        'clip': 'auto',
        'clip-path': 'none',
        'overflow': 'visible',
        'z-index': '999999',
        'pointer-events': 'auto'
    };

    // 判断遮罩是不是真的「盖满整屏 + 能挡住点击」。
    // 看的是最终效果而不是某个属性，所以不管改的是 width/height/transform/margin
    // 还是 position/z-index，只要结果不对就能发现
    function lockIsCovering() {
        if (!lockScreenEl.isConnected) return false;
        var cs = getComputedStyle(lockScreenEl);
        if (cs.display === 'none') return false;
        if (cs.visibility !== 'visible') return false;
        if (!(parseFloat(cs.opacity) >= 1)) return false;
        if (cs.pointerEvents === 'none') return false;
        if (!(parseInt(cs.zIndex, 10) >= 999999)) return false;
        // 固定定位元素的包含块是视口，用 clientWidth/clientHeight 比（innerWidth 含滚动条，会误判）
        var vw = document.documentElement.clientWidth;
        var vh = document.documentElement.clientHeight;
        var r = lockScreenEl.getBoundingClientRect();
        if (r.width < vw - 1 || r.height < vh - 1) return false;
        if (r.left > 1 || r.top > 1) return false;
        return true;
    }

    function checkLock() {
        if (!lockActive) return;   // 没锁就不管

        // 整个遮罩被删掉了 → 放回 body
        if (!lockScreenEl.isConnected) {
            document.body.appendChild(lockScreenEl);
        }
        // 内容被掏空了 → 用备份恢复（恢复出来的是原始 HTML，所以防自动填充要再做一遍）
        if (!lockScreenEl.querySelector('#miuLockPwd')) {
            lockScreenEl.innerHTML = lockScreenHtml;
            lockClock();
            hardenLockInput();
            setTimeout(function () { $('#miuLockPwd').focus(); }, 50);
        }
        // class 被去掉 → 加回来
        if (!lockScreenEl.classList.contains('miu-lock-show')) {
            lockScreenEl.classList.add('miu-lock-show');
        }
        // 没盖满屏幕 / 被藏起来 → 先清掉所有内联样式（把被塞进去的脏样式一起清掉），再整套强制写回
        if (!lockIsCovering()) {
            lockScreenEl.removeAttribute('style');
            for (var k in LOCK_FORCE_CSS) {
                lockScreenEl.style.setProperty(k, LOCK_FORCE_CSS[k], 'important');
            }
        }
    }

    function guardLock() {
        if (lockGuard) return;
        // 1) DOM 变动（删掉 / 隐藏 / 改 class / 掏空）能立刻响应
        lockGuard = new MutationObserver(checkLock);
        lockGuard.observe(document.body, {
            childList: true,
            subtree: true,
            attributes: true,
            attributeFilter: ['class', 'style']
        });
        // 2) 兜底轮询：在控制台直接改 CSS 规则不会触发 MutationObserver，只能定时查一遍
        setInterval(checkLock, 500);
    }
    guardLock();

    // 上次锁着还没解锁的话（刷新、开新标签页、重开浏览器都算），进来继续锁着
    if (localStorage.getItem(LOCK_KEY) === '1') showLock();
});
