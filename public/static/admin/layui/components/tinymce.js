layui.define([], function (exports) {
	var MOD_NAME = 'tinymce';
	var modFile = layui.cache.modules['tinymce'];
	var modPath = modFile.substr(0, modFile.lastIndexOf('.'));
	var plugin_filename = 'tinymce.min.js'//插件名称
	var editorOptions = {};
	var settings = {
		base_url:modPath,
		images_upload_url: '#',//图片上传接口
		images_upload_credentials:true,
		placeholder: '请输入内容...',
		language: 'zh_CN',//语言
		suffix: '.min',
		quickbars_insert_toolbar:'',
		quickbars_selection_toolbar: 'cut copy | bold italic underline strikethrough ',
		plugins: 'code quickbars print preview searchreplace autolink fullscreen image link media codesample table charmap hr advlist lists wordcount imagetools paste',
		toolbar: 'code undo redo | forecolor backcolor bold italic underline strikethrough removeformat | alignleft aligncenter alignright alignjustify outdent indent | bullist numlist table blockquote link image media codesample | formatselect fontselect fontsizeselect',
		resize: false,
		elementpath: false,
		branding: false,
		statusbar: false,
		convert_urls: false,// 附件路径无需要转换为相对路径。
		contextmenu_never_use_native: true,
		paste_data_images: true,
		menubar: false,
		menu: {
			file: { title: '文件', items: 'preview fullscreen | wordcount' },
			edit: { title: '编辑', items: 'undo redo | cut copy paste pastetext selectall | searchreplace' },
			format: {
				title: '格式',
				items: 'bold italic underline strikethrough superscript subscript | formats | forecolor backcolor | removeformat'
			},
		},
		codesample_languages: [
			{text: 'HTML', value: 'html'},
			{text: 'JavaScript', value: 'javascript'},
			{text: 'CSS', value: 'css'},
			{text: 'PHP', value: 'php'},
			{text: 'Java', value: 'java'},
			{text: 'Shell', value: 'shell'},
			{text: 'SQL', value: 'sql'},
			{text: 'JOSN', value: 'json'},
			{text: 'Python', value: 'python'},
			{text: 'XML', value: 'xml'},
			{text: 'Golang', value: 'go'},
			{text: 'Objective-c', value: 'objective-c'},
		],
		fontsize_formats: '12px 14px 16px 18px 24px 36px 48px 56px 72px',
		font_formats: '微软雅黑=Microsoft YaHei,Helvetica Neue,PingFang SC,sans-serif;苹果苹方=PingFang SC,Microsoft YaHei,sans-serif;宋体=simsun,serif;仿宋体=FangSong,serif;黑体=SimHei,sans-serif;Arial=arial,helvetica,sans-serif;Arial Black=arial black,avant garde;Book Antiqua=book antiqua,palatino;Comic Sans MS=comic sans ms,sans-serif;Courier New=courier new,courier;Georgia=georgia,palatino;Helvetica=helvetica;Impact=impact,chicago;Symbol=symbol;Tahoma=tahoma,arial,helvetica,sans-serif;Terminal=terminal,monaco;Times New Roman=times new roman,times;Verdana=verdana,geneva;Webdings=webdings;Wingdings=wingdings,zapf dingbats;知乎配置=BlinkMacSystemFont, Helvetica Neue, PingFang SC, Microsoft YaHei, Source Han Sans SC, Noto Sans CJK SC, WenQuanYi Micro Hei, sans-serif;小米配置=Helvetica Neue,Helvetica,Arial,Microsoft Yahei,Hiragino Sans GB,Heiti SC,WenQuanYi Micro Hei,sans-serif',
		setup: function(editor) {
			console.log("ID为: " + editor.id + " 的编辑器即将初始化.");
		},
		init_instance_callback : function(editor) {
			console.log("ID为: " + editor.id + " 的编辑器已初始化完成.");
		}
	};
	function getAdminLocale() {
		if (window.MiuAdminI18n && typeof window.MiuAdminI18n.getLocale === 'function') {
			return window.MiuAdminI18n.getLocale();
		}
		try {
			var locale = window.localStorage.getItem('miu_admin_locale');
			return locale === 'zh-HK' || locale === 'en' ? locale : 'zh-CN';
		} catch (e) {
			return 'zh-CN';
		}
	}
	function cloneOptions(options) {
		var clone = $.extend({}, options);
		if (options.menu) clone.menu = $.extend(true, {}, options.menu);
		if (options.codesample_languages) {
			clone.codesample_languages = $.map(options.codesample_languages, function (item) {
				return $.extend({}, item);
			});
		}
		return clone;
	}
	function applyAdminLocale(options, locale) {
		var localeText = {
			'zh-CN': {language: 'zh_CN', file: '文件', edit: '编辑', format: '格式', placeholder: '请输入内容...'},
			'zh-HK': {language: 'zh_TW', file: '檔案', edit: '編輯', format: '格式', placeholder: '請輸入內容...'},
			en: {language: '', file: 'File', edit: 'Edit', format: 'Format', placeholder: 'Enter content...'}
		}[locale] || {language: 'zh_CN', file: '文件', edit: '编辑', format: '格式', placeholder: '请输入内容...'};
		if (localeText.language) {
			options.language = localeText.language;
			options.language_url = modPath + '/langs/' + localeText.language + '.js';
		} else {
			delete options.language;
			delete options.language_url;
		}
		if (options.placeholder === settings.placeholder) options.placeholder = localeText.placeholder;
		if (options.menu) {
			options.menu = $.extend(true, {}, options.menu);
			$.each({file: localeText.file, edit: localeText.edit, format: localeText.format}, function (name, title) {
				if (options.menu[name] && options.menu[name].title === settings.menu[name].title) {
					options.menu[name].title = title;
				}
			});
		}
		return options;
	}

	var editor = {
		render: function (options) {
			loadScript();
			var opts = $.extend({}, settings, options), edit = this.get(opts.elem);
			if (edit) {
				edit.destroy();
			}
			// 给插件提供 TinyMCE 初始化配置扩展点（插件禁用时无需注册扩展）。
			if (typeof window.__MIU_TINYMCE_OPTIONS_HOOK === 'function') {
				var hookedOptions = window.__MIU_TINYMCE_OPTIONS_HOOK(opts);
				if (hookedOptions && typeof hookedOptions === 'object') {
					opts = hookedOptions;
				}
			}
			var editorSelector = opts.elem || opts.selector;
			if (editorSelector) editorOptions[editorSelector] = cloneOptions(opts);
			opts = applyAdminLocale(opts, getAdminLocale());
			tinymce.init(opts);
			return this.get(opts.elem);
		},
		// 获取ID对应的编辑器对象
		get: function (elem) {
			loadScript();
			if (elem && /^#|\./.test(elem)) {
				var id = elem.substr(1);
				var edit = tinymce.editors[id];
				return edit;
			} else {
				return false;
			}
		}
	}
	$(window).on('miu-admin-locale-change', function (event) {
		var detail = event.originalEvent && event.originalEvent.detail;
		var locale = detail && detail.locale ? detail.locale : getAdminLocale();
		if (typeof tinymce === 'undefined') return;
		$.each(editorOptions, function (selector, storedOptions) {
			var instance = editor.get(selector);
			if (!instance) return;
			var bookmark = null;
			try { bookmark = instance.selection.getBookmark(2, true); } catch (e) {}
			var options = applyAdminLocale(cloneOptions(storedOptions), locale);
			var initCallback = options.init_instance_callback;
			options.init_instance_callback = function (newInstance) {
				if (typeof initCallback === 'function') initCallback.apply(this, arguments);
				if (bookmark) {
					try { newInstance.selection.moveToBookmark(bookmark); } catch (e) {}
				}
			};
			// TinyMCE 5 的语言包只在初始化时生效；重建前保存正文，并在新实例中恢复光标位置。
			instance.save();
			instance.destroy();
			tinymce.init(options);
		});
	});
	function loadScript() {
		if (typeof tinymce == 'undefined') {
			$.ajax({//获取插件
				url: modPath + '/' + plugin_filename,
				dataType: 'script',
				cache: true,
				async: false,
			});
		}
	}
	exports(MOD_NAME, editor);
});
