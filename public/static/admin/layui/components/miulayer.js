/**
 * miulayer —— layer 的增强模块（独立文件，不改动 layui 底层源码）
 *
 * 作用：弹出 alert / confirm 等提示层时，把「确定」按钮自动设为焦点，
 *      这样直接按 回车 或 空格 就等于点了确定，省掉一次鼠标点击。
 *
 * 原理（都是实测出来的）：
 *   1. layer 的按钮是 <a class="layui-layer-btn0">，没有 href，天生不可聚焦，
 *      直接 .focus() 无效，必须先补 tabindex="0"。
 *   2. 没有 href 的 <a> 浏览器不会把 回车/空格 转成 click（只有 <a href> 和
 *      <button> 才有这个"激活行为"），所以再补一个 keydown 手动触发 click。
 *   3. layer 的 alert/confirm/msg/load/tips/prompt 内部都是
 *      return layer.open($.extend({...}))，调用时才去取 layer.open，
 *      所以只包装 layer.open 一处，就能覆盖所有弹窗。
 *
 * 用法：
 *   layui.use(['miulayer'], function () {
 *       var layer = layui.miulayer;   // API 与原 layer 完全一致
 *
 *       // 默认不自动聚焦
 *       layer.confirm('确定删除吗？', { icon: 3 }, function (index) {
 *           layer.close(index);
 *       });
 *
 *       // 需要"弹出后直接回车/空格确认"的，显式传 focus: true
 *       layer.confirm('确定要把该文档恢复到正常列表吗？', {
 *           icon: 3, title: '提示', focus: true
 *       }, function (index) {
 *           layer.close(index);
 *       });
 *   });
 *
 * 参数：focus —— 是否自动聚焦「确定」按钮
 *      默认 false（不聚焦）；只有显式传 true 才会自动聚焦
 */
layui.define(['layer'], function (exports) {
	var MOD_NAME = 'miulayer';
	var layer = layui.layer;
	var $ = layui.jquery;
	var _open = layer.open;

	// 包装 layer.open，在 success 里把确定按钮设为焦点
	// focus 默认 false（不聚焦），只有显式传 true 才自动聚焦
	layer.open = function (options) {
		if (options && typeof options === 'object' && options.focus === true) {
			var _success = options.success;
			options.success = function (layero, index) {
				if (typeof _success === 'function') { _success.call(this, layero, index); }
				focusOkBtn(layero, options);
			};
		}
		return _open.call(this, options);
	};

	// 把「确定」按钮设为焦点
	function focusOkBtn(layero, options) {
		var btn = layero.find('.layui-layer-btn0').eq(0);
		if (!btn.length) { return; }                     // msg / tips / load 这类没有按钮
		if (options && options.type === 2) { return; }   // iframe 弹窗（文件管理器等）不抢焦点
		// 弹窗里有可见的表单控件时不抢焦点，否则用户没法直接打字
		if (layero.find('input:visible, textarea:visible, select:visible').length) { return; }

		var el = btn[0];
		el.setAttribute('tabindex', '0');
		el.focus();
		// <a> 没有 href，浏览器不会把回车/空格变成 click，这里手动补上
		btn.off('keydown.miuLayerFocus').on('keydown.miuLayerFocus', function (e) {
			if (e.keyCode === 13 || e.keyCode === 32) {
				e.preventDefault();
				e.stopPropagation();
				$(this).click();
			}
		});
	}

	// 对外暴露一份与原 layer 行为完全一致的引用
	var miulayer = {};
	for (var k in layer) { miulayer[k] = layer[k]; }

	exports(MOD_NAME, miulayer);
});
