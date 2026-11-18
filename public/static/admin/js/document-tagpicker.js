(function (window, $) {
	'use strict';

	window.initDocumentTagPicker = function (inputSelector, idsSelector, keywordUrl, groupUrl, tabs) {
		var layer = window.layui.layer;
		var $input = $(inputSelector);
		var $ids = $(idsSelector);
		var opening = false;

		$input.on('click', function () {
			if (opening) return;
			opening = true;

			$.when(
				$.get(keywordUrl),
				$.get(groupUrl, 'page=1&limit=1000')
			).done(function (keywordResult, groupResult) {
				var keywordResponse = keywordResult[0];
				var groupResponse = groupResult[0];
				var keywords = keywordResponse && keywordResponse.data;
				var groups = groupResponse && groupResponse.data;

				if (!keywordResponse || keywordResponse.code !== 0 || !Array.isArray(keywords) ||
					!groupResponse || groupResponse.code !== 0 || !Array.isArray(groups)) {
					opening = false;
					layer.msg((keywordResponse && keywordResponse.msg) || (groupResponse && groupResponse.msg) || '标签数据读取失败');
					return;
				}

				var names = String($input.val() || '').split(',');
				var ids = String($ids.val() || '').split(',');
				var selected = [];
				for (var i = 0; i < names.length; i++) {
					var title = $.trim(names[i]);
					if (title) selected.push({ id: ids[i] || 0, title: title });
				}

				var escapeHtml = function (value) {
					return String(value == null ? '' : value).replace(/[\u0026\u003c\u003e\u0022\u0027]/g, function (char) {
						return '&#' + char.charCodeAt(0) + ';';
					});
				};
				var isSelected = function (id) {
					return selected.some(function (item) { return String(item.id) === String(id); });
				};
				var renderTagList = function (items) {
					var html = '';
					for (var i = 0; i < items.length; i++) {
						var tag = items[i];
						var id = tag.id;
						var title = tag.title || '';
						html += '<li class="' + (isSelected(id) ? 'a_cur' : '') + '" data-id="' + escapeHtml(id) + '" data-title="' + escapeHtml(title) + '">' + escapeHtml(title) + '</li>';
					}
					return html || '<li class="miu-tag-empty">暂无标签</li>';
				};

				var headers = '<li class="layui-this" lay-id="all">全部</li>';
				var bodies = '<div class="layui-tabs-item layui-show"><div class="miu-tag-list"><ul>' + renderTagList(keywords) + '</ul></div></div>';
				var ungrouped = keywords.filter(function (tag) { return Number(tag.group_id || 0) === 0; });
				if (ungrouped.length) {
					headers += '<li lay-id="group_0">未分组</li>';
					bodies += '<div class="layui-tabs-item"><div class="miu-tag-list"><ul>' + renderTagList(ungrouped) + '</ul></div></div>';
				}
				for (var i = 0; i < groups.length; i++) {
					var group = groups[i];
					var groupId = String(group.id);
					var groupTags = keywords.filter(function (tag) { return String(tag.group_id || 0) === groupId; });
					headers += '<li lay-id="group_' + escapeHtml(groupId) + '">' + escapeHtml(group.title || '') + '</li>';
					bodies += '<div class="layui-tabs-item"><div class="miu-tag-list"><ul>' + renderTagList(groupTags) + '</ul></div></div>';
				}

				var content = '<div class="miu-doc-tagpicker"><div class="miu-tag-selected"><ul></ul><input class="miu-tag-input" placeholder="可输入自定义标签，按空格添加" /></div><div id="miu_doc_keyword_tabs" class="layui-tabs layui-tabs-card"><ul class="layui-tabs-header">' + headers + '</ul><div class="layui-tabs-body">' + bodies + '</div></div></div>';
				layer.open({
					title: '请选择标签',
					shadeClose: true,
					area: ['720px', '500px'],
					content: content,
					type: 1,
					btn: ['确定'],
					success: function (layero) {
						opening = false;
						var $selectedList = layero.find('.miu-tag-selected ul');
						var renderSelected = function () {
							var html = '';
							for (var i = 0; i < selected.length; i++) {
								html += '<li data-id="' + escapeHtml(selected[i].id) + '" data-title="' + escapeHtml(selected[i].title) + '">' + escapeHtml(selected[i].title) + '<a class="miu-tag-remove" title="删除">×</a></li>';
							}
							$selectedList.html(html || '<span style="color:#aaa;line-height:32px">请选择标签</span>');
							layero.find('.miu-tag-list li[data-id]').each(function () {
								$(this).toggleClass('a_cur', isSelected($(this).attr('data-id')));
							});
						};
						var removeSelected = function (id, title) {
							for (var i = 0; i < selected.length; i++) {
								if (String(selected[i].id) === String(id) && selected[i].title === title) {
									selected.splice(i, 1);
									break;
								}
							}
							renderSelected();
						};
						var addSelected = function (title, id) {
							title = $.trim(String(title || ''));
							if (!title) return true;
							if (title.length < 2 || !(/^[\u0391-\uFFE5A-Za-z0-9]+$/.test(title))) {
								layer.msg('标签至少两个字，且不能包含特殊字符或空格');
								return false;
							}
							if (selected.some(function (item) { return item.title === title; })) {
								layer.msg('标签已添加');
								return false;
							}
							if (selected.length >= 5) {
								layer.msg('最多添加5个标签');
								return false;
							}
							selected.push({ id: id || 0, title: title });
							renderSelected();
							return true;
						};

						renderSelected();
						tabs.render({ elem: '#miu_doc_keyword_tabs', headerMode: 'auto' });
						layero.on('click', '.miu-tag-list li[data-id]', function () {
							var id = $(this).attr('data-id');
							var title = $(this).attr('data-title');
							if (isSelected(id)) removeSelected(id, title);
							else addSelected(title, id);
						});
						layero.on('click', '.miu-tag-remove', function (event) {
							event.stopPropagation();
							var item = $(this).closest('li');
							removeSelected(item.attr('data-id'), item.attr('data-title'));
						});
						layero.find('.miu-tag-input').on('keypress', function (event) {
							if ((event.which || event.keyCode) === 32) {
								if (addSelected($(this).val(), 0)) $(this).val('');
								event.preventDefault();
							}
						}).on('blur', function () {
							if (addSelected($(this).val(), 0)) $(this).val('');
						});
					},
					yes: function (index) {
						$ids.val(selected.map(function (item) { return item.id; }).join(',')).trigger('change');
						$input.val(selected.map(function (item) { return item.title; }).join(',')).trigger('change');
						layer.close(index);
					}
				});
			}).fail(function () {
				opening = false;
				layer.msg('标签数据读取失败，请稍后重试');
			});
		});
	};
})(window, window.jQuery || window.$);
