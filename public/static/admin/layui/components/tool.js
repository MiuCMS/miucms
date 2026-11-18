layui.define(function (exports) {
	let MOD_NAME = 'tool',dropdown=layui.dropdown;
	let tool = {
		loading: false,
		expressClose:function(index){return '<div class="express-close" data-index="'+index+'" title="关闭">\
			<div class="express-container">\
				<div class="express-bar">\
					<svg viewBox="0 0 202.9 45.5" >\
					  <clipPath id="menu" clipPathUnits="objectBoundingBox" transform="scale(0.0049285362247413 0.021978021978022)">\
						<path  d="M6.7,45.5c5.7,0.1,14.1-0.4,23.3-4c5.7-2.3,9.9-5,18.1-10.5c10.7-7.1,11.8-9.2,20.6-14.3c5-2.9,9.2-5.2,15.2-7c7.1-2.1,13.3-2.3,17.6-2.1c4.2-0.2,10.5,0.1,17.6,2.1c6.1,1.8,10.2,4.1,15.2,7c8.8,5,9.9,7.1,20.6,14.3c8.3,5.5,12.4,8.2,18.1,10.5c9.2,3.6,17.6,4.2,23.3,4H6.7z"/>\
					  </clipPath>\
					</svg>\
				</div>\
				<div class="express-btn">\
					<svg class="express-icon" viewBox="0 0 1024 1024" version="1.1" xmlns="http://www.w3.org/2000/svg"><path d="M463.787 512l-253.44-253.44a34.133 34.133 0 0 1 48.213-48.213L512 463.787l253.44-253.44a34.133 34.133 0 1 1 48.213 48.213L560.213 512l253.44 253.44a34.133 34.133 0 1 1-48.213 48.213L512 560.213l-253.44 253.44a34.133 34.133 0 0 1-48.213-48.213z" fill="#ffffff"></path></svg>\
				</div>\
			</div>\
		</div>'
		},
		side: function (url, width,title=false) {
			let that = this;
			if (that.loading == true) {
				return false;
			}
			that.loading = true;
			//宽度：传数字按像素（600 → 600px），传字符串按原样（'50%' → '50%'）；不传就按窗口宽度自适应
			if (width) {
				sideWidth = typeof width === 'number' ? width + 'px' : width;
			} else {
				sideWidth = '80%';
				if(window.innerWidth>1366 && window.innerWidth<=1600){
					sideWidth = '86%';
				}
				if(window.innerWidth>1000 && window.innerWidth<=1440){
					sideWidth = '93%';
				}
				if(window.innerWidth<=1000){
					sideWidth = '95%';
				}
			}
			$(parent.$('.express-close')).addClass('parent-colse');
			layer.open({
				type: 2,
				title: title,
				offset: 'r',
				anim: 'slideLeft',
				closeBtn: 0,
				content: url,
				shadeClose: false,
				area: [sideWidth, '100%'],
				skin:'layui-layer-miu-admin',
				end: function(){
					$('body').removeClass('right-open');
					$(parent.$('.express-close')).removeClass('parent-colse');
					if (layui.pageTable && layui.pageTable.resize) {
						layui.pageTable.resize();
					}
				},
				success: function (obj, index) {
					$('body').addClass('right-open');
					obj.append(that.expressClose(index));
					that.loading = false;
					obj.on('click','.express-close', function () {					
						layer.close(index);
					})
				}
			})
		},
		box: function (url,title="内容", width=720,height=405) {
			let that = this;
			if (that.loading == true) {
				return false;
			}
			that.loading = true;
			layer.open({
				type: 2,
				title: title,
				content: url,
				area: [width+'px', height+'px'],
				maxmin: true,
				end: function(){
					if (layui.pageTable && layui.pageTable.resize) {
						layui.pageTable.resize();
					}
				},
				success: function (obj, index) {
					let btn = '<div data-index="'+index+'" class="express-close" style="display:none;" title="关闭">关闭</div>';
					obj.append(btn);
					that.loading = false;
					obj.on('click','.express-close', function () {					
						layer.close(index);
					})
				}
			})
		},
		//右侧ajax请求的方式打开页面参考米柚DEV
		open: function (url, width) {
			let that = this;
			if (that.loading == true) {
				return false;
			}
			that.loading = true;
			let countWidth = window.innerWidth-(window.innerWidth*0.5)+456;
			if(window.innerWidth<=1000){
				countWidth = 750;
			}
			if (width && width > 0) {
				sideWidth = width + 'px';
			}
			else{
				sideWidth = countWidth + 'px';
			}
			$.ajax({
				url: url,
				type: "GET",
				timeout: 10000,
				success: function (res) {
					if (res['code'] && res['code'] > 0) {
						layer.msg(res.msg);
						return false;
					}
					let index = timestamp = new Date().getTime();
					let express = '<section id="expressLayer" class="express-box" style="width:' + sideWidth + '"><article id="articleLayer">' + res + '</article>'+that.expressClose(index)+'</section><div id="expressMask" class="express-mask"></div>';

					$('body').append(express).addClass('right-open');
					$('#expressMask').fadeIn(200);
					$('#expressLayer').animate({ 'right': 0 }, 200, 'linear', function () {
						if (typeof (openInit) == "function") {
							openInit();
						}						
					});					
					that.loading = false;					
					//关闭
					$('body').on('click','.express-close', function () {
						$('#expressMask').fadeOut(100);
						$('body').removeClass('right-open');
						let op_width = $('#expressLayer').outerWidth();
						$('#expressLayer').animate({ left: '+=' + op_width + 'px' }, 200, 'linear', function () {
							$('#expressLayer').remove();
							$('#expressMask').remove();
							if (layui.pageTable) {
								layui.pageTable.resize();
							}
						})
					})
					$(window).resize(function () {
						let resizeWidth = window.innerWidth-(window.innerWidth*0.5)+456;
						if(window.innerWidth<=1000){
							resizeWidth = 750;
						}
						$('#expressLayer').width(resizeWidth);
					})
				},
				error: function (xhr, textstatus, thrown) {
					console.log('错误');
				},
				complete: function () {
					that.loading = false;
				}
			});
		},
		load: function (url) {
			let that = this;
			if (that.loading == true) {
				return false;
			}
			that.loading = true;
			$.ajax({
				url: url,
				type: "GET",
				timeout: 10000,
				success: function (res) {
					if (res['code'] && res['code'] > 0) {
						layer.msg(res.msg);
						return false;
					}
					$('#articleLayer').html(res);
					openInit();
				}
				, error: function (xhr, textstatus, thrown) {
					console.log('错误');
				},
				complete: function () {
					that.loading = false;
				}
			});
		},
		page: function (url) {
			let that = this;
			if (that.loading == true) {
				return false;
			}
			that.loading = true;
			$.ajax({
				url: url,
				type: "GET",
				timeout: 10000,
				success: function (res) {
					if (res['code'] && res['code'] > 0) {
						layer.msg(res.msg);
						return false;
					}
					$('#pageBox').html(res);
					pageInit();
				},
				error: function (xhr, textstatus, thrown) {
					console.log('错误');
				},
				complete: function () {
					that.loading = false;
				}
			});
		},
		reload: function (delay) {
			//延迟刷新，一般是在编辑完页面数据后需要自动关闭页面用到
			if(delay && delay>0){
				setTimeout(function () {
					location.reload();
				}, delay);
			}else{
				location.reload();
			}
		},
		ask:function(tips,yes,cancel){
			layer.confirm(tips, {
				icon: 3,
				title: '提示',
				success:function(){
					$(parent.$('.express-close')).addClass('parent-colse');
				},
				end:function(){
					$(parent.$('.express-close')).removeClass('parent-colse');
				}
			}, function (index) {
				if (yes && typeof yes === 'function') {
					yes();
				}
				layer.close(index);
			}, function (index) {
				if (cancel && typeof cancel === 'function') {
					cancel();
				}
				layer.close(index);
			});
		},
		close: function (delay,table) {
			//延迟关闭，一般是在编辑完页面数据后需要自动关闭页面用到
			if(delay && delay>0){
				setTimeout(function () {
					$('.express-close').last().click();
				}, delay);
			}else{
				$('.express-close').last().click();
			}
			if (typeof(table) == "undefined" || table == '') {
				table = 'pageTable';
			}
			if (layui[table]) {
				layui[table].reload();
			}
			else{
				//tool.reload(delay);
			}
		},
		ajax: function (options, callback) {
			let format = 'json';
			if (options.hasOwnProperty('data')) {
				format = options.data.hasOwnProperty('format') ? options.data.format : 'json';
			}
			callback = callback || options.success;
			callback && delete options.success;
			let optsetting = { timeout: 10000 };
			if (format == 'jsonp') {
				optsetting = { timeout: 10000, dataType: 'jsonp', jsonp: 'callback' }
			}
			let opts = $.extend({}, optsetting, {
				success: function (res) {
					if (callback && typeof callback === 'function') {
						callback(res);
					}
				}
			}, options);
			$.ajax(opts);
		},
		get: function (url, data, callback) {
			this.ajax({url: url,type: "GET",data: data}, callback);
		},
		post: function (url, data, callback, options) {
			this.ajax($.extend({url: url,type: "POST",data: data}, options || {}), callback);
		},
		responseMessage: function (xhr) {
			let response = xhr && xhr.responseJSON;
			if (!response && xhr && typeof xhr.responseText === 'string' && xhr.responseText !== '') {
				try {
					response = JSON.parse(xhr.responseText);
				} catch (e) {}
			}
			let message = response && (response.msg || response.message);
			return typeof message === 'string' && message.trim() !== '' ? this.xss(message.trim()) : '';
		},
		put: function (url, data, callback) {
			this.ajax({url: url,type: "PUT",data: data}, callback);
		},
		delete: function (url, data, callback) {
			this.ajax({url: url,type: "DELETE",data: data}, callback);
		},
		xss:function(str) {
            return str.replace(/&/g, '&amp;').replace(/</g, '&lt;').replace(/>/g, '&gt;').replace(/"/g, '&quot;').replace(/'/g, '&#39;');
		},
		parentAdmin:function () {
			if(parent.layui.admin){
				return parent.layui.admin;
			}
			else{
				if(parent.parent.layui.admin){
					return parent.parent.layui.admin;
				}
				else{
					if(parent.parent.parent.layui.admin){
						return parent.parent.parent.layui.admin;
					}
					else{
						return false;
					}
				}
			}
		},
		sideClose(delay,table){
			if(parent.layui.tool){
				parent.layui.tool.close(delay,table);
			}
			else{
				console.log('父页面没引用tool模块');
			}		
		},
		tabAdd:function(url,title,id){
			let parentAdmin = this.parentAdmin();
			if(parentAdmin){
				parentAdmin.sonAdd(url,title,id);
			}		
		},
		tabClose:function(){
			let parentAdmin = this.parentAdmin();
			if(parentAdmin){
				parentAdmin.sonClose();
			}
		},
		tabDelete:function(id){
			let parentAdmin = this.parentAdmin();
			if(parentAdmin){
				parentAdmin.tabDelete(id);
			}
		},
		tabChange:function(id){
			let parentAdmin = this.parentAdmin();
			if(parentAdmin){
				parentAdmin.tabChange(id);
			}
		},
		tabRefresh:function(id){
			let parentAdmin = this.parentAdmin();
			if(parentAdmin){
				parentAdmin.refresh(id);
			}			
		},
		officeView:function(id,mode){
			let parentAdmin = this.parentAdmin();
			if(parentAdmin){
				parentAdmin.officeView(id,mode);
			}			
		},
		pdfView:function(href){
			let parentAdmin = this.parentAdmin();
			if(parentAdmin){
				parentAdmin.pdfView(href);
			}			
		},
		photoView:function(href){
			let parentAdmin = this.parentAdmin();
			if(parentAdmin){
				parentAdmin.photoView(href);
			}		
		},
		videoView:function(href){
			let parentAdmin = this.parentAdmin();
			if(parentAdmin){
				parentAdmin.videoView(href);
			}		
		},
		audioView:function(href){
			let parentAdmin = this.parentAdmin();
			if(parentAdmin){
				parentAdmin.audioView(href);
			}		
		},
		downloadFile:function(url, fileName) {
			let link = document.createElement("a");
			link.href = url;
			link.download = fileName;
			link.click();
			layer.msg('文件下载中...', {time: 2000});
		},
		copyCtrl:function(content) {
			var save = function(e){
				e.clipboardData.setData('text/plain', content);
				e.preventDefault();
			}
			document.addEventListener('copy', save);
			document.execCommand('copy');
			document.removeEventListener('copy',save);
			if (content != '') {
				layer.msg('复制成功');
			}
		}
	};
	//时间选择快捷操作
	$('body').on('click', '.tool-time', function () {
		let that = $(this);
        let type = that.data('type');
        let range = that.data('range');
        let min = that.data('min');
        let max = that.data('max');
        let format = that.data('format');
		if (typeof(type) == "undefined" || type === '') {
			type = 'date';
		}
		if (typeof(range) == "undefined" || type === '') {
			range = false;
		}
		if (typeof(min) == "undefined" || min === '') {
			min = '1900-1-1';
		}
		if (typeof(max) == "undefined" || max === '') {
			max = '2199-1-1';
		}
		if (typeof(format) == "undefined" || format === '') {
			layui.laydate.render({ 
				elem: that,
				show: true,
				type: type,
				range: range,
				min: min,
				max: max,
				fullPanel: true
			});
		}else{
			layui.laydate.render({ 
				elem: that,
				show: true,
				type: type,
				range: range,
				min: min,
				max: max,
				format:format,
				fullPanel: true
			});
		}

		return false;
	});
	
	//查看图片附件
	$('body').on('click','.file-view-img',function () {
		let href = $(this).data('href');
		if(href){
			tool.photoView(href);
		}
	});	
	
	//查看pdf附件
	$('body').on('click','.file-view-pdf',function () {
		let href = $(this).data('href');
		if(href){
			tool.pdfView(href);
		}
	});
	
	//搜索表单重置快捷操作
	$('body').on('click', '[lay-filter="reset"]', function () {
		let that = $(this);
        let prev = that.prev();
		if (typeof(prev) != "undefined") {
			setTimeout(function () {
				prev.click();
			}, 10)
		}
	});
	
	//新建按钮快捷操作
	$('body').on('click', '.tool-add', function () {
		let href = $(this).data('href');
		if (typeof(href) == "undefined" || href == '') {
			return false;
		}
		tool.side(href);	
		return false;
	});
	
	$('body').on('click', '.tab-a', function () {
        let id = $(this).data('id');
		let url = $(this).data('href');
		let title = $(this).data('title');
		if (url && url !== '') {
			if (typeof(id) == "undefined" || id == '') {
				id = Date.now();
			}
			tool.tabAdd(url,title,id);
		}
		return false;
	});
	$('body').on('click', '.side-a', function () {
		let url = $(this).data('href');
		//可选：加 data-width 指定侧滑面板宽度。
		//数字按像素：data-width="600"（jQuery 会把 "600" 转成数字 600）
		//字符串按原样当 CSS 宽度：data-width="600px" 或 data-width="50%"
		//不写就用默认的自适应宽度
		let width = $(this).data('width');
		if (url && url !== '') {
			tool.side(url, width);
		}
		return false;
	});
	$('body').on('click', '.open-a', function () {
		let url = $(this).data('href');
		if (url && url !== '') {
			tool.open(url);
		}
		return false;
	});
	$('body').on('click', '.link-a', function () {
		let url = $(this).data('href');
		if (url && url !== '') {
			window.location.href=url;
		}
		return false;
	});
	exports(MOD_NAME, tool);
});  
