(function(window, $){
	function initEditors($panel){
		$panel.find('.miu-i18n-html-editor').each(function(){
			var textarea = this;
			if (textarea.getAttribute('data-editor-state')) return;
			textarea.setAttribute('data-editor-state', 'loading');

			layui.tinymce.render({
				selector: '#' + textarea.id,
				images_upload_url: window.__MIU_URLS.i18nEditorUpload,
				height: 500,
				toolbar: 'code undo redo | forecolor backcolor bold italic underline strikethrough removeformat | alignleft aligncenter alignright alignjustify outdent indent | bullist numlist table blockquote myimage myvideo codesample | formatselect fontselect fontsizeselect',
				setup: function(editor){
					editor.ui.registry.addButton('myimage', { icon: 'image', tooltip: '插入图片', onAction: function(){ window.openFileManagerForTinymce(editor, 'image'); } });
					editor.ui.registry.addButton('myvideo', { icon: 'embed', tooltip: '插入视频', onAction: function(){ window.openFileManagerForTinymce(editor, 'video'); } });
					editor.on('change keyup', function(){
						if (textarea.getAttribute('data-editor-state') !== 'ready') return;
						textarea.value = editor.getContent();
						$(textarea).trigger('change');
					});
				},
				init_instance_callback: function(editor){
					if (textarea.value) editor.setContent(textarea.value);
					textarea.setAttribute('data-editor-state', 'ready');
				}
			});
		});

		$panel.find('.miu-i18n-markdown-editor').each(function(){
			var container = this;
			if ($(container).data('editor')) return;
			var source = document.getElementById(container.getAttribute('data-source-id'));
			if (!source) return;
			var htmlOutput = document.getElementById(container.getAttribute('data-html-output-id'));

			var editor;
			editor = layui.editormd.render(container.id, {
				width: '100%',
				height: 500,
				markdown: source.value || (htmlOutput ? htmlOutput.value : ''),
				onchange: function(){
					if (!editor) return;
					source.value = editor.getMarkdown();
					$(source).trigger('change');
				}
			});
			$(container).data('editor', editor);
		});
	}

	function initVisibleEditors($wrapper){
		if (!$wrapper.is(':visible')) return;
		var $tabs = $wrapper.find('.layui-tabs').first();
		initEditors($tabs.children('.layui-tabs-body').children('.layui-tabs-item.layui-show'));
	}

	window.initDocumentI18nEditors = function($wrapper){
		$wrapper = $($wrapper);
		if (!$wrapper.length) return;

		$wrapper.each(function(){
			var $field = $(this);
			var $tabs = $(this).find('.layui-tabs').first();
			if (!$tabs.length) return;
			if (!$tabs.data('i18n-editor-events')) {
				$tabs.data('i18n-editor-events', true);
				$tabs.children('.layui-tabs-header').on('click', 'li', function(){
					setTimeout(function(){
						initVisibleEditors($field);
					}, 0);
				});
			}

			// 多语言面板本身位于文档的外层标签页中，等外层标签页显示后再计算编辑器尺寸。
			$field.parents('.layui-tabs').not($tabs).each(function(){
				var $parentTabs = $(this);
				var $header = $parentTabs.children('.layui-tabs-header').first();
				if (!$header.length) return;
				if ($parentTabs.data('i18n-editor-parent-events')) return;
				$parentTabs.data('i18n-editor-parent-events', true);
				$header.on('click', 'li', function(){
					setTimeout(function(){
						initVisibleEditors($field);
					}, 0);
				});
			});

			initVisibleEditors($field);
		});
	};

	window.syncDocumentI18nEditors = function(field){
		$('.miu-i18n-html-editor').each(function(){
			var editor = typeof tinymce !== 'undefined' ? tinymce.get(this.id) : null;
			field[this.name] = editor ? editor.getContent() : this.value;
		});
		$('.miu-i18n-markdown-source').each(function(){
			var container = document.querySelector('.miu-i18n-markdown-editor[data-source-id="' + this.id + '"]');
			var editor = container ? $(container).data('editor') : null;
			var markdown = editor ? editor.getMarkdown() : this.value;
			field[this.name] = markdown.trim() ? markdown : '';
			var htmlOutput = container ? document.getElementById(container.getAttribute('data-html-output-id')) : null;
			if (htmlOutput) {
				field[htmlOutput.name] = markdown.trim() ? (editor ? editor.getHTML() : htmlOutput.value) : '';
			}
		});
		// Editor.md 会在容器内生成临时 textarea；只提交上面的翻译字段，避免临时字段进入模型表。
		$('.miu-i18n-markdown-editor').each(function(){
			delete field[this.id + '-markdown-doc'];
			delete field[this.id + '-html-code'];
		});
	};
})(window, jQuery);
