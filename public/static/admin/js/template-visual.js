layui.use(['jquery', 'layer'], function () {
    var $ = layui.jquery, layer = layui.layer;
    var config = JSON.parse($('#visual-config').text());
    var fileNames = Object.keys(config.pages);
    var contextFiles = fileNames.filter(function (name) { return config.pages[name].kind !== 'shared'; });
    var $frame = $('#visual-frame'), $file = $('#visual-file'), $context = $('#visual-context'), $nav = $('#visual-nav');
    var currentFile = config.pages['index.html'] ? 'index.html' : fileNames[0];
    var contextFile = '', selectedNav = 0, selectedId = 0, search = '', loadNumber = 0;
    var state = null, target = null, busy = false, dirty = false, previewReady = false, editorLayer = 0;
    var previewItems = [];

    function options($select, items, selected) {
        $select.empty();
        items.forEach(function (item) { $('<option>').val(item[0]).text(item[1]).appendTo($select); });
        if (selected !== undefined) $select.val(String(selected));
    }

    function message(text) { $('#visual-message').text(text); }
    function api(operation, params, data) {
        var query = $.extend({visual: operation, ticket: config.ticket, file: currentFile}, params);
        return $.ajax({url: MIU_ADMIN + '/template/setting?' + $.param(query), type: data ? 'POST' : 'GET', data: data, dataType: 'json'});
    }

    function errorText(xhr) {
        return (xhr.responseJSON && xhr.responseJSON.msg) || (xhr.status === 403 ? '编辑会话已失效，请从模板管理重新进入' : '请求失败，请重新载入后再试');
    }

    function setState(next) {
        state = next;
        $('#visual-undo').prop('disabled', !next || !next.undo);
        $('#visual-history').prop('disabled', !next);
    }

    function pageKind() { return config.pages[contextFile || currentFile].kind; }

    function applyFile(file) {
        currentFile = file;
        $file.val(file);
        var shared = config.pages[file].kind === 'shared';
        contextFile = shared && contextFiles.length ? (config.pages['index.html'] ? 'index.html' : contextFiles[0]) : '';
        $context.val(contextFile);
        $('#visual-context-field').prop('hidden', !shared || !contextFiles.length);
        search = '';
        $('#visual-keyword').val('');
        changeContext();
    }

    function changeContext() {
        var page = config.pages[contextFile || currentFile];
        var needsNav = page.kind === 'list' || page.kind === 'view';
        selectedNav = needsNav && page.nav.length ? page.nav[0].id : 0;
        options($nav, page.nav.map(function (item) { return [item.id, item.title]; }), selectedNav);
        $('#visual-nav-field').prop('hidden', !needsNav || !page.nav.length);
        $('#visual-pick').prop('hidden', page.kind !== 'view' && page.kind !== 'tag')
            .prop('disabled', page.kind === 'view' && !selectedNav);
        $('#visual-search').prop('hidden', page.kind !== 'search');
        chooseDefault();
    }

    function chooseDefault() {
        selectedId = 0;
        var kind = pageKind(), run = ++loadNumber;
        $('#visual-pick').text(kind === 'tag' ? '选择标签' : '选择文档');
        if ((kind === 'view' && selectedNav) || kind === 'tag') {
            message('正在读取预览数据…');
            api('lookup', {type: kind === 'view' ? 'document' : 'tag', nav: selectedNav}).done(function (res) {
                if (run !== loadNumber) return;
                if (res.code === 0 && res.data.rows.length) {
                    setPreviewItem(res.data.rows[0]);
                } else if (res.code === 0) {
                    reload();
                } else {
                    message(res.msg);
                }
            }).fail(function (xhr) { if (run === loadNumber) message(errorText(xhr)); });
        } else {
            reload();
        }
    }

    function setPreviewItem(item) {
        selectedId = item.id;
        $('#visual-pick').text(item.title);
        reload();
    }

    function reload() {
        var run = ++loadNumber;
        previewReady = false;
        $('#visual-hidden').prop('hidden', true);
        setState(null);
        message('正在加载预览…');
        api('state').done(function (res) {
            if (run !== loadNumber) return;
            if (res.code !== 0) { message(res.msg); return; }
            showPreview(res.data);
        }).fail(function (xhr) { if (run === loadNumber) message(errorText(xhr)); });
    }

    function showPreview(next) {
        setState(next);
        previewReady = false;
        $('#visual-hidden').prop('hidden', true);
        var params = {ticket: config.ticket, file: currentFile, revision: next.revision,
            context: contextFile, nav: selectedNav, id: selectedId, keyword: search, refresh: ++loadNumber};
        $frame.attr('src', '/index.php/home/miu-template-preview?' + $.param(params));
    }

    function releaseTarget(restore) {
        if (target && restore) {
            if (target.kind === 'text') target.node.nodeValue = target.original;
            else {
                target.node.setAttribute('src', target.original);
                if (target.originalSrcset !== null) target.node.setAttribute('srcset', target.originalSrcset);
            }
        }
        if (target && target.element) target.element.classList.remove('miu-visual-selected');
        target = null;
        dirty = false;
        var index = editorLayer;
        editorLayer = 0;
        if (index) layer.close(index);
        $('#visual-save').prop('disabled', true);
    }

    function beforeSwitch(next) {
        if (busy) return;
        if (!dirty) { releaseTarget(true); next(); return; }
        var impact = config.pages[currentFile].kind === 'shared' ? '保存后，共用这个文件的页面也会更新。' : '';
        layer.confirm('当前修改尚未保存。' + impact, {title: '切换前处理修改', btn: ['保存并切换', '放弃修改', '继续编辑']},
            function (index) { layer.close(index); save(next); },
            function () { releaseTarget(true); next(); });
    }

    function activate(item) {
        if (!previewReady || target && target.node === item.node) return;
        beforeSwitch(function () {
            if (!previewReady || !item.node.isConnected) return;
            target = item;
            target.element.classList.add('miu-visual-selected');
            var isText = item.kind === 'text';
            var openedIndex;
            $('#visual-text').toggle(isText).val(isText ? item.original : '');
            $('#visual-image-field').toggle(!isText);
            $('#visual-image-name').val('当前模板图片');
            dialog(isText ? '编辑固定文字' : '更换固定图片', $('#visual-editor'), function (index) {
                editorLayer = openedIndex = index;
                $(isText ? '#visual-text' : '#visual-image').trigger('focus');
            }, {
                cancel: function () { return !busy; },
                end: function () {
                    if (editorLayer !== openedIndex) return;
                    editorLayer = 0;
                    releaseTarget(true);
                }
            });
        });
    }

    function blocksPreview(element, subject) {
        if (element.matches('details:not([open])') && element !== subject) {
            var summary = element.querySelector(':scope > summary');
            if (!summary || !summary.contains(subject)) return true;
        }
        var style = element.ownerDocument.defaultView.getComputedStyle(element);
        return element.hidden || element.inert || style.display === 'none' || style.visibility === 'hidden' || style.visibility === 'collapse' ||
            style.contentVisibility === 'hidden' || style.opacity === '0' ||
            (element.clientHeight === 0 && element.scrollHeight > 0 && /^(hidden|clip)$/.test(style.overflowY));
    }

    function hiddenPreviewAncestors(element) {
        var hidden = [];
        for (var ancestor = element; ancestor; ancestor = ancestor.parentElement) {
            if (blocksPreview(ancestor, element)) hidden.push(ancestor);
        }
        return hidden;
    }

    function previewDisclosures(doc) {
        var controls = '[aria-controls],[popovertarget],[data-bs-target],[data-target],[data-collapse-target],' +
            '[data-toggle-target],[data-tab-target],[data-accordion-target],a[href^="#"]';
        var displays = new WeakMap(), disclosureLinks = new WeakSet();

        function panelFor(control) {
            if (!control) return null;
            var target = control.getAttribute('aria-controls') || control.getAttribute('popovertarget') ||
                control.getAttribute('data-bs-target') || control.getAttribute('data-target') ||
                control.getAttribute('data-collapse-target') || control.getAttribute('data-toggle-target') ||
                control.getAttribute('data-tab-target') || control.getAttribute('data-accordion-target');
            var href = control.getAttribute('href') || '';
            var id = target || (href[0] === '#' ? href : '');
            var panel = id ? doc.getElementById(id.replace(/^#/, '')) : null;
            if (!panel || panel === control || panel.contains(control)) return null;
            if (target || disclosureLinks.has(control) || blocksPreview(panel, panel)) {
                if (!target) disclosureLinks.add(control);
                return panel;
            }
            return null;
        }

        function setPanel(panel, open) {
            if (panel.matches('details')) {
                panel.open = open;
                if (!open) return;
            }
            if (panel.matches('dialog')) panel.toggleAttribute('open', open);
            panel.hidden = !open;
            if (panel.hasAttribute('popover') && typeof panel.showPopover === 'function') {
                if (open) panel.showPopover();
                else panel.hidePopover();
            }
            if (!open) {
                if (!displays.has(panel)) displays.set(panel, [panel.style.getPropertyValue('display'), panel.style.getPropertyPriority('display')]);
                panel.style.setProperty('display', 'none', 'important');
                return;
            }
            if (displays.has(panel)) {
                var display = displays.get(panel);
                panel.style.setProperty('display', display[0], display[1]);
                displays.delete(panel);
            }
            panel.removeAttribute('inert');
            if (panel.matches('.collapse,.tab-pane')) panel.classList.add('show');
            if (panel.matches('.tab-pane')) panel.classList.add('active');
            var style = doc.defaultView.getComputedStyle(panel);
            if (style.display === 'none') panel.style.setProperty('display', 'revert', 'important');
            if (style.visibility !== 'visible') panel.style.setProperty('visibility', 'visible', 'important');
            if (style.contentVisibility === 'hidden') panel.style.setProperty('content-visibility', 'visible', 'important');
            if (style.opacity === '0') panel.style.setProperty('opacity', '1', 'important');
            if (panel.clientHeight === 0 && panel.scrollHeight > 0 && /^(hidden|clip)$/.test(style.overflowY)) {
                panel.style.setProperty('height', 'auto', 'important');
                panel.style.setProperty('max-height', 'none', 'important');
            }
        }

        function toggle(control, panel) {
            var tabSelector = '[role="tab"],[data-bs-toggle="tab"],[data-bs-toggle="pill"],[data-toggle="tab"],[data-toggle="pill"]';
            var isTab = control.matches(tabSelector);
            var open = isTab || blocksPreview(panel, panel) || panel.matches('details:not([open])');
            if (isTab) {
                var tabList = control.closest('[role="tablist"],.nav-tabs,.nav-pills');
                if (tabList) {
                    $(tabList).find(tabSelector).each(function () {
                        var other = panelFor(this);
                        if (this === control || !other || other === panel) return;
                        setPanel(other, false);
                        this.setAttribute('aria-selected', 'false');
                        this.classList.remove('active');
                    });
                }
                control.setAttribute('aria-selected', 'true');
                control.classList.add('active');
            } else {
                control.setAttribute('aria-expanded', String(open));
            }
            setPanel(panel, open);
        }

        return {selector: controls, panelFor: panelFor, setPanel: setPanel, toggle: toggle};
    }

    function mountPreview(doc, marker) {
        var items = new Map(), elements = new Map();
        var disclosures = previewDisclosures(doc);
        var $hiddenButton = $('#visual-hidden');
        previewItems = [];
        function add(node, rawId, kind, original) {
            var element = kind === 'text' ? node.parentElement : node;
            if (!element || !/^\d+$/.test(rawId)) return;
            var item = {id: rawId, kind: kind, node: node, element: element, original: original};
            if (kind === 'image') item.originalSrcset = node.getAttribute('srcset');
            previewItems.push(item);
            items.set(node, item);
            if (!elements.has(element)) elements.set(element, []);
            elements.get(element).push(item);
            element.classList.add('miu-visual-editable');
            if (!element.hasAttribute('tabindex') && !element.matches('a[href],button,input,select,textarea,summary')) element.tabIndex = 0;
        }
        var prefix = 'miu-visual:' + marker + ':';
        var walker = doc.createTreeWalker(doc.body || doc.documentElement, 128);
        var comment;
        while ((comment = walker.nextNode())) {
            if (comment.data.indexOf(prefix) !== 0) continue;
            var id = comment.data.slice(prefix.length), node = comment.nextSibling;
            if (node && node.nodeType === 3 && node.nextSibling && node.nextSibling.nodeType === 8 &&
                node.nextSibling.data === 'miu-visual-end:' + marker + ':' + id) {
                add(node, id, 'text', node.nodeValue);
            }
        }
        $(doc).find('img[data-miu-visual^="' + marker + ':"]').each(function () {
            add(this, this.getAttribute('data-miu-visual').slice(marker.length + 1), 'image', this.getAttribute('src'));
        });
        $('<style>').text(
            '.miu-visual-editable:hover,.miu-visual-editable:focus-visible,.miu-visual-selected{outline:1px dashed #168bf1!important;outline-offset:2px;cursor:pointer!important;}' +
            'img.miu-visual-editable{pointer-events:auto!important;}' +
            'img.miu-visual-editable:hover,img.miu-visual-editable:focus-visible,img.miu-visual-selected{outline-offset:-3px!important;}'
        ).appendTo(doc.head || doc.documentElement);

        function findMatches(target, node) {
            var item = items.get(target) || items.get(node);
            return item ? [item] : (elements.get(target) || []);
        }

        function choose(matches) {
            if (matches.length === 1) activate(matches[0]);
            if (matches.length > 1) {
                var $choices = $('<div class="tpl-visual-dialog tpl-visual-results">');
                dialog('选择要修改的文字', $choices, function (index) {
                    matches.forEach(function (item) {
                        $('<button type="button" class="tpl-visual-result">').text(item.original).appendTo($choices).on('click', function () {
                            layer.close(index);
                            activate(item);
                        });
                    });
                });
            }
        }

        function onEditableContent(item, event) {
            if (item.kind === 'image') return item.node === event.target;
            var range = doc.createRange();
            range.selectNodeContents(item.node);
            return Array.from(range.getClientRects()).some(function (rect) {
                return event.clientX >= rect.left && event.clientX <= rect.right && event.clientY >= rect.top && event.clientY <= rect.bottom;
            });
        }

        function summaryFor(element) {
            var summary = element.closest('details > summary');
            var interactive = element.closest('a[href],button,input,select,textarea,label');
            return summary && (!interactive || !summary.contains(interactive)) ? summary : null;
        }

        function refreshHiddenButton() {
            if ($frame[0].contentDocument !== doc || !previewReady) return;
            $hiddenButton.prop('hidden', !previewItems.some(function (item) {
                return hiddenPreviewAncestors(item.element).length > 0;
            }));
        }

        $hiddenButton.off('click.previewDisclosure').on('click.previewDisclosure', function () {
            if (!previewReady || busy) return;
            var groups = new Map();
            previewItems.forEach(function (item) {
                var hidden = hiddenPreviewAncestors(item.element);
                if (hidden.length && !groups.has(hidden[0])) groups.set(hidden[0], {item: item, hidden: hidden});
            });
            if (!groups.size) { refreshHiddenButton(); return; }
            var $body = $('<div class="tpl-visual-dialog tpl-visual-results">');
            dialog('显示隐藏内容', $body, function (index) {
                groups.forEach(function (entry, panel) {
                    var heading = panel.querySelector('summary,h1,h2,h3,h4,h5,h6');
                    var label = heading ? heading.textContent : (entry.item.kind === 'image' ? entry.item.node.alt : entry.item.original);
                    $('<button type="button" class="tpl-visual-result">').text(label.trim().slice(0, 72) || '隐藏内容')
                        .appendTo($body).on('click', function () {
                            entry.hidden.reverse().forEach(function (element) { disclosures.setPanel(element, true); });
                            layer.close(index);
                            entry.item.element.scrollIntoView({block: 'center', inline: 'nearest', behavior: 'instant'});
                            entry.item.element.focus({preventScroll: true});
                            refreshHiddenButton();
                        });
                });
            });
        });

        doc.addEventListener('click', function (event) {
            var element = event.target;
            var summary = summaryFor(element);
            // 收起时点击标题行先展开，展开后文字与展开图标各自响应。
            if (summary && (!summary.parentElement.open || event.detail === 0)) return;
            var caret = doc.caretPositionFromPoint ? doc.caretPositionFromPoint(event.clientX, event.clientY) :
                (doc.caretRangeFromPoint ? doc.caretRangeFromPoint(event.clientX, event.clientY) : null);
            var matches = findMatches(element, caret && (caret.offsetNode || caret.startContainer));
            if (summary) {
                matches = matches.filter(function (item) { return summary.contains(item.element) && onEditableContent(item, event); });
                if (!matches.length) return;
            } else {
                var control = element.closest(disclosures.selector);
                var panel = disclosures.panelFor(control);
                if (panel && (event.detail === 0 || panel.matches('details:not([open])') || hiddenPreviewAncestors(panel).length || !matches.some(function (item) {
                    return control.contains(item.element) && onEditableContent(item, event);
                }))) {
                    event.preventDefault();
                    disclosures.toggle(control, panel);
                    refreshHiddenButton();
                    return;
                }
                var label = element.closest('label');
                var field = label && label.control ? label.control : element;
                if (field.matches('input[type="checkbox"], input[type="radio"]') && !matches.length) return;
            }
            event.preventDefault();
            choose(matches);
        }, true);
        doc.addEventListener('keydown', function (event) {
            if (event.key !== 'Enter' && event.key !== ' ') return;
            if (event.target.matches('details > summary,input[type="checkbox"],input[type="radio"]')) return;
            event.preventDefault();
            var control = event.target.closest(disclosures.selector);
            var panel = disclosures.panelFor(control);
            if (panel) {
                disclosures.toggle(control, panel);
                refreshHiddenButton();
            } else {
                choose(findMatches(event.target, event.target));
            }
        }, true);
        doc.addEventListener('toggle', refreshHiddenButton, true);
        doc.addEventListener('change', refreshHiddenButton, true);
        doc.defaultView.addEventListener('resize', refreshHiddenButton);
        refreshHiddenButton();
        var hint = items.size ? '点击高亮的固定文字或图片进行编辑。' : '这个页面没有可直接编辑的固定文字或图片。';
        message((doc.querySelector('meta[name="miu-visual-sample"]') ? '当前为示例数据预览。' : '') + hint);
    }

    $frame.on('load', function () {
        var doc = this.contentDocument;
        var src = this.getAttribute('src');
        if (!doc || !src || doc.URL !== new URL(src, location.href).href) return;
        var ready = doc.querySelector('meta[name="miu-visual-preview"]');
        var error = doc.querySelector('meta[name="miu-visual-error"]');
        if (ready && state) {
            var parts = ready.content.split(':');
            if (parts[1] === state.revision && /^[a-f0-9]{20}$/.test(parts[0])) {
                previewReady = true;
                mountPreview(doc, parts[0]);
            }
        } else {
            message(error ? error.content : '预览无法打开，请重新载入或从模板管理重新进入');
        }
    });

    function dialog(title, $body, init, options) {
        var maxHeight = Math.max(220, $(window).height() - 54);
        var settings = $.extend({type: 1, title: title, area: [Math.min(640, $(window).width() - 24) + 'px', 'auto'],
            maxHeight: maxHeight, content: $body, success: function (_, index) { init(index); }}, options);
        $body.css('max-height', Math.max(0, settings.maxHeight - 54));
        // Layui 会在原位置包装对象内容，临时弹窗需要先挂载到页面。
        if (!$body[0].isConnected) {
            $body.hide().appendTo(document.body);
            var onEnd = settings.end;
            settings.end = function () {
                $body.remove();
                if (onEnd) onEnd.apply(this, arguments);
            };
        }
        layer.open(settings);
    }

    function validImage(url) {
        return typeof url === 'string' && /^(?:\/(?![\\/])|https?:\/\/)/i.test(url) && !/[\\\s]/.test(url);
    }

    function picker(type, title, callback) {
        var $body = $('<div class="tpl-visual-dialog">');
        var $form = $('<form class="tpl-visual-picker-form">').appendTo($body);
        var $keyword = $('<input type="search" class="layui-input" placeholder="按名称搜索" aria-label="按名称搜索">').appendTo($form);
        $('<button type="submit" class="layui-btn layui-btn-normal">搜索</button>').appendTo($form);
        var $results = $('<div class="tpl-visual-results">').appendTo($body);
        var $pager = $('<div class="tpl-visual-pages">').appendTo($body);
        var version = 0;
        dialog(title, $body, function (index) {
            function list(page) {
                var run = ++version;
                $results.text('正在读取…');
                $pager.empty();
                api('lookup', {type: type, page: page, nav: selectedNav, keyword: $keyword.val()}).done(function (res) {
                    if (run !== version) return;
                    if (res.code !== 0) { $results.text(res.msg); return; }
                    var rows = res.data.rows, total = res.data.total;
                    $results.empty();
                    if (!rows.length) $results.text('没有符合条件的记录');
                    rows.forEach(function (row) {
                        var $item = $('<button type="button" class="tpl-visual-result">').appendTo($results);
                        $('<span>').text(row.name || row.title || '#' + row.id).appendTo($item);
                        $item.on('click', function () { layer.close(index); callback(row); });
                    });
                    if (page > 1) $('<button class="layui-btn layui-btn-sm layui-btn-primary" type="button">上一页</button>').appendTo($pager).on('click', function () { list(page - 1); });
                    $('<span>').text('第 ' + page + ' 页 · 共 ' + total + ' 条').appendTo($pager);
                    if (page * 25 < total) $('<button class="layui-btn layui-btn-sm layui-btn-primary" type="button">下一页</button>').appendTo($pager).on('click', function () { list(page + 1); });
                }).fail(function (xhr) { if (run === version) $results.text(errorText(xhr)); });
            }
            $form.on('submit', function (event) { event.preventDefault(); list(1); });
            list(1);
        });
    }

    function applySavedElement(values, patch) {
        if (!patch) return false;
        var updated = false;
        previewItems.forEach(function (item) {
            if (item.id !== values.element || item.kind !== values.kind || !item.node.isConnected) return;
            item.original = patch.value;
            if (item.kind === 'text') {
                if (item.node.nodeValue !== patch.value) item.node.nodeValue = patch.value;
                if (patch.leading) item.node.before(patch.leading);
                if (patch.trailing) item.node.after(patch.trailing);
            } else {
                if (item.node.getAttribute('src') !== patch.value) item.node.setAttribute('src', patch.value);
                if (item.originalSrcset !== null) {
                    item.originalSrcset = patch.value;
                    if (item.node.getAttribute('srcset') !== patch.value) item.node.setAttribute('srcset', patch.value);
                }
            }
            updated = true;
        });
        return updated && patch.keepEditing;
    }

    function write(operation, values, success, historySource) {
        if (!state || busy) return;
        var source = historySource || {file: currentFile, revision: state.revision};
        busy = true;
        $('#visual-save').prop('disabled', true);
        api(operation, {file: source.file}, $.extend({revision: source.revision}, values)).done(function (res) {
            if (res.code !== 0) { layer.msg($('<div>').text(res.msg).html(), {icon: 2}); return; }
            if (source.file === currentFile) {
                releaseTarget(false);
                if (operation === 'save') {
                    setState(res.data);
                    previewReady = applySavedElement(values, res.data.patch);
                    if (!previewReady) message('已保存。请重新载入预览后继续编辑。');
                } else {
                    showPreview(res.data);
                }
            }
            if (config.static) $('#visual-static').prop('hidden', false);
            layer.msg(operation === 'save' ? '已保存到模板文件' : '模板文件已恢复', {icon: 1});
            if (success) success();
        }).fail(function (xhr) { layer.msg($('<div>').text(errorText(xhr)).html(), {icon: 2}); }).always(function () {
            busy = false;
            $('#visual-save').prop('disabled', !dirty);
        });
    }

    function save(next) {
        if (!target || !dirty) return;
        write('save', {element: target.id, kind: target.kind, text: $('#visual-text').val(), media: target.media || 0}, next);
    }

    function confirmRestore(operation, data, historyLayer, historySource) {
        var label = operation === 'undo' ? '撤销上次保存？' : '恢复到所选记录修改前的版本？';
        var file = historySource ? historySource.file : currentFile;
        if (config.pages[file] && config.pages[file].kind === 'shared') label += '共用这个文件的页面也会更新。';
        layer.confirm(label, {title: '恢复模板文件', icon: 3}, function (index) {
            layer.close(index);
            if (historyLayer) layer.close(historyLayer);
            write(operation, data, null, historySource);
        });
    }

    $('#visual-text').on('input', function () {
        if (!target || target.kind !== 'text') return;
        var text = $(this).val();
        target.node.nodeValue = text;
        dirty = text !== target.original;
        $('#visual-save').prop('disabled', !dirty);
    });
    $('#visual-image').on('click', function () {
        var item = target;
        if (!item || item.kind !== 'image' || busy) return;
        function selectImage(index, row) {
            if (target !== item) return false;
            if (!row || !row.id || !validImage(row.filepath)) {
                layer.msg('请选择一张可用的图片');
                return false;
            }
            item.media = row.id;
            $('#visual-image-name').val(row.name || row.filepath || '#' + row.id);
            item.node.setAttribute('src', row.filepath);
            if (item.originalSrcset !== null) item.node.setAttribute('srcset', row.filepath);
            dirty = true;
            $('#visual-save').prop('disabled', false);
            layer.close(index);
        }
        layer.open({
            type: 2,
            title: '文件管理器 · 选择图片',
            offset: 'auto',
            area: ['80%', '90%'],
            maxHeight: Math.round($(window).height() * 0.9),
            content: MIU_ADMIN + '/template/setting?' + $.param({visual: 'files', ticket: config.ticket}),
            btn: ['确定', '取消'],
            success: function ($layer, index) {
                $layer.find('iframe')[0].contentWindow.pickTemplateVisualImage = function (row) { selectImage(index, row); };
            },
            yes: function (index, $layer) {
                var frame = $layer.find('iframe')[0].contentWindow;
                selectImage(index, typeof frame.getTemplateVisualImage === 'function' ? frame.getTemplateVisualImage() : null);
            }
        });
    });
    $('#visual-save').on('click', function () {
        if (!dirty || busy) return;
        if (config.pages[currentFile].kind !== 'shared') { save(); return; }
        layer.confirm('保存后，共用这个文件的页面也会更新。', {title: '保存公共模板', icon: 3}, function (index) {
            layer.close(index);
            save();
        });
    });
    $('#visual-cancel').on('click', function () { if (!busy) releaseTarget(true); });
    $('#visual-reload').on('click', function () { beforeSwitch(reload); });
    $('#visual-undo').on('click', function () { beforeSwitch(function () { if (state && state.undo) confirmRestore('undo', {}); }); });
    $('#visual-history').on('click', function () {
        beforeSwitch(function () {
            if (!state) return;
            var $body = $('<div class="tpl-visual-dialog">');
            var $select = $('<select class="layui-input" aria-label="已修改的模板文件">').hide().appendTo($body);
            var $records = $('<div>').text('正在读取修改记录…').appendTo($body);
            dialog('修改记录', $body, function (index) {
                function loadHistory() {
                    var file = $select.val();
                    $records.text('正在读取修改记录…');
                    api('state', {file: file}).done(function (res) {
                        if ($select.val() !== file) return;
                        if (res.code !== 0) { $records.text(res.msg); return; }
                        $records.empty();
                        if (!res.data.history.length) $records.text('此模板文件暂无修改记录');
                        res.data.history.forEach(function (record) {
                            var $row = $('<div class="tpl-visual-record">').appendTo($records);
                            var $description = $('<div>').appendTo($row);
                            $('<strong>').text(file).appendTo($description);
                            $('<span>').text('　' + record.time + '　' + record.label + (record.detail ? ' · ' + record.detail : '')).appendTo($description);
                            $('<span class="tpl-visual-record-user">').text(record.username || '管理员 #' + record.admin).appendTo($description);
                            $('<button type="button" class="layui-btn layui-btn-sm layui-btn-primary">恢复到此之前</button>')
                                .appendTo($row).on('click', function () {
                                    confirmRestore('restore', {history: record.id}, index, {file: file, revision: res.data.revision});
                                });
                        });
                    }).fail(function (xhr) { if ($select.val() === file) $records.text(errorText(xhr)); });
                }
                api('history').done(function (res) {
                    if (res.code !== 0) { $records.text(res.msg); return; }
                    var files = res.data;
                    if (!files.length) { $records.text('当前模板暂无修改记录'); return; }
                    options($select, files.map(function (file) { return [file, file]; }), files.includes(currentFile) ? currentFile : files[0]);
                    $select.show().on('change', loadHistory);
                    loadHistory();
                }).fail(function (xhr) { $records.text(errorText(xhr)); });
            }, {offset: '100px', maxHeight: Math.max(54, $(window).height() - 124)});
        });
    });
    $('#visual-pick').on('click', function () {
        beforeSwitch(function () {
            var tags = pageKind() === 'tag';
            picker(tags ? 'tag' : 'document', tags ? '选择预览标签' : '选择预览文档', setPreviewItem);
        });
    });
    $('#visual-search').on('submit', function (event) {
        event.preventDefault();
        var value = $('#visual-keyword').val();
        beforeSwitch(function () { search = value; reload(); });
    });
    $file.on('change', function () {
        var value = this.value;
        $file.val(currentFile);
        beforeSwitch(function () { applyFile(value); });
    });
    $context.on('change', function () {
        var value = this.value;
        $context.val(contextFile);
        beforeSwitch(function () { contextFile = value; $context.val(value); changeContext(); });
    });
    $nav.on('change', function () {
        var value = Number(this.value);
        $nav.val(String(selectedNav));
        beforeSwitch(function () { selectedNav = value; $nav.val(String(value)); chooseDefault(); });
    });
    $(window).on('beforeunload', function () { if (dirty) return '当前修改尚未保存'; });

    options($file, fileNames.map(function (file) { return [file, config.pages[file].label + ' · ' + file]; }));
    options($context, contextFiles.map(function (file) { return [file, config.pages[file].label + ' · ' + file]; }));
    if (currentFile) applyFile(currentFile);
    else {
        $('#visual-reload').prop('disabled', true);
        message('这套模板没有可用的 HTML 模板文件');
    }
});
