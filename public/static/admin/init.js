window.rootPath=(function(src){src=document.currentScript?document.currentScript.src:document.scripts[document.scripts.length-1].src;return src.substring(0,src.lastIndexOf("/")+1)})();if(typeof $=="undefined"){window.jQuery=layui.jquery;window.$=layui.jquery}if(typeof moduleInit=="undefined"){window.moduleInit=[]}var module={};if(moduleInit.length>0){for(var i=0;i<moduleInit.length;i++){module[moduleInit[i]]=moduleInit[i]}}
function formatTime(timestamp) {
	const date = new Date(timestamp * 1000);
	const pad = (num) => String(num).padStart(2, '0');
	const year = date.getFullYear();
	const month = pad(date.getMonth() + 1);
	const day = pad(date.getDate());
	const hours = pad(date.getHours());
	const minutes = pad(date.getMinutes());
	const seconds = pad(date.getSeconds());
	return `${year}-${month}-${day} ${hours}:${minutes}:${seconds}`;
}
// 页面级 PHP 渲染路由注入点（add.html / edit.html 等页面通过 window.__MIU_URLS 预注入）
var __MIU=window.__MIU_URLS||{};
layui.config({
	base: rootPath + "layui/components/",
	version: "2.13.9"
}).extend(module).use(moduleInit);
layui.use(['miulayer']);
/*
* 文件管理器 - 通用版，适用于表单字段（缩略图、单图选择等）
* @param {string} bind - 目标表单字段 ID
*/
function openFileManager(bind,filter='image'){
	if(!bind){
		layer.msg('未传入绑定的input表单');
		return false;
	}
	layer.open({
		type: 2,
		title: '文件管理器',
		area: ['70%', '80%'],
		content: (__MIU.filesIndex || MIU_ADMIN + "/files/index") + "?target="+bind+"&filter="+filter,
		maxmin: true,
		shadeClose: true,
		btn: ['确定', '取消'],
		btnAlign: 'c',
		success: function(layero, index){
			var iframeWin = window[layero.find('iframe')[0]['name']];
			if(iframeWin){
				iframeWin.THUMB_LAYER_INDEX = index;
			}
		},
		yes: function(index, layero){
			var iframeWin =  window[layero.find('iframe')[0]['name']];
			var value = iframeWin.getThumbSrc ? iframeWin.getThumbSrc() : '';
			if(!value){
				layer.msg('只能选择一张图片');
				return false;
			}
			// 回填到对应输入框并触发 input 事件，使 lay-affix 清除按钮正确显示
			$('#'+bind).val(value).trigger('input');
			layer.close(index);
		}
	});
}

/*
 * =============================================
 * TinyMCE 编辑器：文件管理器弹层
 * =============================================
 */
function openFileManagerForTinymce(editorInst, filterType) {
	var filterParam = filterType || 'media';
	var fileIndex = layer.open({
		type: 2,
		title: '插入媒体',
		area: ['70%', '80%'],
		content: (__MIU.filesIndex || MIU_ADMIN + "/files/index") + "?target=editor&filter=" + filterParam,
		maxmin: true,
		shadeClose: true,
		btn: ['确定', '取消'],
		btnAlign: 'c',
		success: function(layero, index) {
			var iframeWin = window[layero.find('iframe')[0]['name']];
			if (iframeWin) iframeWin.THUMB_LAYER_INDEX = index;
		},
		yes: function(index, layero) {
			var iframeWin = window[layero.find('iframe')[0]['name']];
			var files = iframeWin.getMultiThumbSrc ? iframeWin.getMultiThumbSrc() : [];
			if (!files.length) {
				layer.tips('请至少选择一个媒体文件', layero.find('.layui-layer-btn0'), { tips: 3 });
				return false;
			}
			files.forEach(function(file) {
				if (file.type === 'video') {
					editorInst.insertContent('<video src="' + file.src + '" controls style="max-width:100%;"></video>');
				} else {
					editorInst.insertContent('<img src="' + file.src + '" alt="' + (file.name || '') + '" style="max-width:100%;">');
				}
			});
			layer.close(index);
		},
		end: function() {
			delete window._editorImageCallback;
		}
	});
	// 暴露回调供 iframe 双击直接插入单张图片/视频
	window._editorImageCallback = function(src, fileType, name) {
		if (fileType === 'video') {
			editorInst.insertContent('<video src="' + src + '" controls style="max-width:100%;"></video>');
		} else {
			editorInst.insertContent('<img src="' + src + '" alt="' + (name || '') + '" style="max-width:100%;">');
		}
		delete window._editorImageCallback;
	};
}

/*
 * Markdown 编辑器图片按钮：通过文件管理器选择图片并插入 Markdown 图片语法
 */
function openFileManagerForEditormd(editorInst) {
	function insertImage(src, name) {
		var alt = String(name || '').replace(/\\/g, '\\\\').replace(/\]/g, '\\]');
		var imageUrl = String(src || '').replace(/\s/g, '%20').replace(/\)/g, '%29');
		if (!imageUrl) return;
		editorInst.replaceSelection('![' + alt + '](' + imageUrl + ')');
		editorInst.focus();
	}

	layer.open({
		type: 2,
		title: '插入图片',
		area: ['70%', '80%'],
		content: (__MIU.filesIndex || MIU_ADMIN + "/files/index") + "?target=editor&filter=image",
		maxmin: true,
		shadeClose: true,
		btn: ['确定', '取消'],
		btnAlign: 'c',
		success: function(layero, index) {
			var iframeWin = window[layero.find('iframe')[0]['name']];
			if (iframeWin) iframeWin.THUMB_LAYER_INDEX = index;
		},
		yes: function(index, layero) {
			var iframeWin = window[layero.find('iframe')[0]['name']];
			var files = iframeWin.getMultiThumbSrc ? iframeWin.getMultiThumbSrc() : [];
			if (!files.length) {
				layer.tips('请至少选择一张图片', layero.find('.layui-layer-btn0'), { tips: 3 });
				return false;
			}
			files.forEach(function(file) {
				insertImage(file.src, file.name);
			});
			layer.close(index);
		},
		end: function() {
			delete window._editorImageCallback;
		}
	});

	// 支持在文件管理器中双击图片后直接插入
	window._editorImageCallback = function(src, fileType, name) {
		if (fileType === 'image' || !fileType) insertImage(src, name);
		delete window._editorImageCallback;
	};
}

/*
 * =============================================
 * 文档预设值管理（下拉 + 弹窗设置）
 * @param {object} options - { presets, saveUrl }
 *   presets: 预设数据对象 { author: [...], from: [...] }
 *   saveUrl: 保存接口 URL（如 MIU_ADMIN + '/api/save_preset'）
 * =============================================
 */
function initDocPresets(options) {
	var PRESETS = options.presets || {};
	var SAVE_URL = options.saveUrl || __MIU.savePreset || MIU_ADMIN + '/api/save_preset';

	function renderDropdown(type) {
		var list = PRESETS[type] || [];
		var $box = $('#preset-' + type);
		if (!list.length) {
			$box.html('<div class="preset-empty">点击"设置"添加</div>').show();
			return;
		}
		var html = list.map(function(v){
			return '<div class="preset-item" data-val="' + layui.util.escape(v) + '">' + layui.util.escape(v) + '</div>';
		}).join('');
		$box.html(html).show();
	}

	$(document).on('focus click', '.miu-preset-input', function(){
		var type = $(this).data('preset');
		renderDropdown(type);
	});

	$(document).on('blur', '.miu-preset-input', function(e){
		var type = $(this).data('preset');
		setTimeout(function(){
			$('#preset-' + type).hide();
		}, 200);
	});

	$(document).on('mousedown', '.preset-item', function(e){
		e.preventDefault();
		var val = $(this).data('val');
		$(this).closest('.layui-input-inline').find('.miu-preset-input').val(val);
		$(this).closest('.layui-input-inline').find('.miu-preset-dropdown').hide();
	});

	$(document).on('click', '[data-action="set-preset"]', function(){
		var type = $(this).data('type');
		var title = type === 'author' ? '作者' : '文章来源';
		var arr = PRESETS[type] || [];
		var content = '<div style="padding:15px;"><textarea class="layui-textarea miu-preset-area" id="preset-area-' + type + '" placeholder="每行一个预设值">' + layui.util.escape(arr.join("\n")) + '</textarea><div style="color:#999;margin-top:8px;">每行一个选项，回车换行</div></div>';
		layer.open({
			type: 1,
			title: '设置' + title + '预置值',
			area: ['400px', '280px'],
			content: content,
			btn: ['保存', '取消'],
			yes: function(idx){
				var raw = $('#preset-area-' + type).val();
				var lines = raw.split(/\n/).map(function(s){ return $.trim(s); }).filter(function(s){ return s !== ''; });
				PRESETS[type] = lines;
				$.post(SAVE_URL, {type: type, values: lines.join("\n")}, function(res){
					if (res.code === 0) {
						layer.msg('保存成功');
						layer.close(idx);
					} else {
						layer.msg(res.msg || '保存失败');
					}
				});
			}
		});
	});
}

/*
 * =============================================
 * 缩略图预览图标交互
 * @param {string} inputId - 缩略图输入框 ID（默认 litpic）
 * @param {string} iconId - 预览图标 ID（默认 litpic-preview-icon）
 * =============================================
 */
function initThumbPreview(inputId, iconId) {
	inputId = inputId || 'litpic';
	iconId = iconId || 'litpic-preview-icon';

	// 鼠标悬停预览大图
	$('#' + iconId).on('mouseenter', function(){
		var src = $('#' + inputId).val();
		if(!src) return;
		layer.tips('<img src="'+src+'" style="max-width:200px;max-height:200px;">', this, {
			tips: [1, '#fff'],
			time: 0,
			area: ['auto', 'auto'],
			skin: 'preview-thumb-tips'
		});
	}).on('mouseleave', function(){
		layer.closeAll('tips');
	});

	// 根据输入框内容动态显示/隐藏预览图标
	function toggle(){
		if($('#' + inputId).val()){
			$('#' + iconId).show();
		}else{
			$('#' + iconId).hide();
		}
	}
	toggle();
	$('#' + inputId).on('input', toggle);

	// 兼容 lay-affix 清除按钮：通过 MutationObserver 监听 value 属性变化
	var el = $('#' + inputId)[0];
	if(el && window.MutationObserver){
		new MutationObserver(function(){
			toggle();
		}).observe(el, { attributes: true, attributeFilter: ['value'] });
	}
}

$(function(){
	// 表单元素右侧提示
	$('.right-tips').on({
		mouseenter: function(){
			var that = this, oldIndex = $(that).data('tip-index');
			if(oldIndex) layer.close(oldIndex);
			var color = $(that).css('color') || '#1e9fff';
			var idx = layer.tips($(that).data('tips'), that, { tips: [1, color], time:0 });
			$('#layui-layer' + idx).find('.layui-layer-content').css('background-color', color);
			$(that).data('tip-index', idx);
		},
		mouseleave: function(){
			var that = this, oldIndex = $(that).data('tip-index');
			if(oldIndex){ layer.close(oldIndex); $(that).data('tip-index', null); }
		}
	});
	// 页面加载完 tips 为空则隐藏
	$(function(){
		$('.right-tips').each(function(){ if(!$(this).data('tips')) $(this).hide(); });
	});
})
