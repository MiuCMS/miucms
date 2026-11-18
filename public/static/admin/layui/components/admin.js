layui.define([], function (exports) {
	let element = layui.element;
	let $miuApp = $("#MiuApp");
	let $miuAppBody = $("#MiuAppBody");
	let $miuMenuList = $("#menuList");
	let tab = {
        // tab动画加载效果
        loading: function() {
            let load = '<div class="tab-loading"><div class="tab-loader"></div></div>';
            let $iframe = $miuAppBody.find('.miucms-tab-page.layui-show iframe');
            if (!$iframe.next().length) {
                $iframe.parent().append(load);
                let tabLoad = $iframe.parent().find('.tab-loading');
				let tab_timestamp =  $iframe.data('timestamp');
				let timestamp = new Date().getTime();
				//console.log(tab_timestamp);
				if(timestamp-tab_timestamp<666){
					$iframe.on('load', function() {
						tabLoad.fadeOut(666, function() {
							tabLoad.remove();
						});
					})
				}
				else{
					tabLoad.fadeOut(666, function() {
						tabLoad.remove();
					});
				}
            }
        },
		//历史tab预加载
		tabTem: function (id, url, title) {
			element.tabAdd('miucms-admin-tab', {
				id: id,
				url:url,
				title: '<span class="miucms-tab-active"></span>' + title
			});
			$miuAppBody.append('<div class="miucms-tab-page" title="'+title+'" id="tabItem' + id + '" data-id="' + id + '" data-url="' + url + '"></div>');
		},
		/*新增一个Tab页面
		* @id，tab页面唯一标识，可是标签中data-id的属性值
		* @url,tab页面地址
		* @name,tab页面标题，
		*/
		tabAdd: function (id, url, title) {
			let thetabs = $('#pageTabUl').find('li');
			if (thetabs.length > 15) {
				layer.tips('点击LOGO快速关闭已开的TAB页面', $('.layui-logo'));
			}
			if (thetabs.length > 20) {
				layer.msg('你已打开了太多TAB页面了，请关闭部分TAB再使用');
				return false;
			}
			element.tabAdd('miucms-admin-tab', {
				id: id,
				url:url,
				title: '<span class="miucms-tab-active"></span>' + title
			});
			// 获取当前时间戳（毫秒数）
			let timestamp = new Date().getTime();
			$miuAppBody.append('<div class="miucms-tab-page" title="'+title+'" id="tabItem'+id+'" data-id="'+id +'"><iframe id="'+id+'" data-frameid="'+id+'" data-timestamp="'+timestamp+'" src="'+url+'" frameborder="0" align="left" width="100%" height="100%" scrolling="yes"></iframe></div>');
			this.tabChange(id);
		},
		//从子页面打开新的Tab页面，防止id重复，使用时间戳作为唯一标识
		sonAdd: function (url, title,id) {
			if ($(".layui-tab-title li[lay-id]").length <= 0) {
				//打开新的tab项
				this.tabAdd(id, url, title);
			} else {
				//否则判断该tab项是否以及存在
				let isHas = false;
				$.each($(".layui-tab-title li[lay-id]"), function () {
					//如果点击左侧菜单栏所传入的id 在右侧tab项中的lay-id属性可以找到，则说明该tab项已经打开
					if ($(this).attr("lay-id") == id) {
						isHas = true;
						$('[data-frameid="' + id + '"]').attr('src', url);
						//最后不管是否新增tab，最后都转到要打开的选项页面上
						tab.tabChange(id);
					}
				})
				if (isHas == false) {
					//标志为false 新增一个tab项
					tab.tabAdd(id, url, title);
				}
			}
			try {
				layer.close(window.openTips);
			} catch (e) {
				console.log(e.message);
			}
		},
		//根据传入的id传入到指定的tab项，并滚动定位
		tabChange: function (id) {
			element.tabChange('miucms-admin-tab', id);
		},
		//删除tab页面
		tabDelete: function (id) {
			if (id == 0) {
				return;
			}
			element.tabDelete('miucms-admin-tab', id);
		},
		/*删除所有tab页面
		*@ids 是一个数组，存放多个id，调用tabDelete方法分别删除
		*/
		tabDeleteAll: function (ids) {
			let that = this;
			$.each(ids, function (i, item) {
				that.tabDelete(item);
			})
		},
		//tab页面关闭自己，需要使用父iframe配合一起调用，如：parent.tab.sonClose();
		sonClose: function (id) {
			$('.layui-tab .layui-tab-title .layui-this i').click();
		},
		//滚动tab
		tabRoll: function (event, index) {
			let $tabTitle = $("#pageTabs .layui-tab-title"),
				$tabs = $tabTitle.children("li"),
				$outerWidth = ($tabTitle.prop("scrollWidth"), $tabTitle.outerWidth()),
				$left = parseFloat($tabTitle.css("left"));
			if ("left" === event) {
				if (!$left && $left <= 0){
					return;
				}
				let roll = -$left - $outerWidth;
				$tabs.each(function (item, i) {
					let $item = $(i),$itemleft = $item.position().left;
					if ($itemleft >= roll) {
						return $tabTitle.css("left", -$itemleft), false;
					}
				})
			} else "auto" === event ? ! function () {
				let $itemleft, $item = $tabs.eq(index);
				if ($item[0]) {
					if ($itemleft = $item.position().left, $itemleft < -$left) return $tabTitle.css("left", -$itemleft);
					if ($itemleft + $item.outerWidth() >= $outerWidth - $left) {
						let $diff = $itemleft + $item.outerWidth() - ($outerWidth - $left);
						$tabs.each(function (e, i) {
							let $tabitem = $(i),$tabitemleft = $tabitem.position().left;
							if ($tabitemleft + $left > 0 && $tabitemleft - $left > $diff) return $tabTitle.css("left", -$tabitemleft), false;
						})
					}
				}
			}() : $tabs.each(function (item, i) {
				let $item = $(i),$itemleft = $item.position().left;
				if ($itemleft + $item.outerWidth() >= $outerWidth - $left) return $tabTitle.css("left", -$itemleft), false;
			})
		},
		//标签是否超出可视宽度：超出才显示左右滚动箭头（给 #pageTabs 加 .tabs-overflow 类）
		tabRollBtn: function () {
			let $wrap = $("#pageTabs"),
				$tabTitle = $wrap.find(".layui-tab-title"),
				$tabs = $tabTitle.children("li"),
				over = false;
			if ($tabs.length) {
				let $last = $tabs.last();
				over = ($last.position().left + $last.outerWidth()) > ($tabTitle.outerWidth() + 1);
			}
			$wrap.toggleClass("tabs-overflow", over);
			//没超出时把滚动位移归零，免得残留负 left 让标签整体偏左
			if (!over) $tabTitle.css("left", 0);
		},
		tabFollow:function(id){
			$miuMenuList.find('.side-menu-item').removeClass('layui-this');
			$miuMenuList.find('dd').removeClass('layui-this').removeClass('layui-nav-itemed');
			$miuMenuList.find('.menu-li').removeClass('layui-nav-itemed');
			$miuMenuList.find('.miucms-second-menu').removeClass('current');
			$('#MiuAppBody').removeClass('expand-menu');
			$('.side-menu-item').each(function (index,item){
				if($(item).data("id") == id) {
					//console.log(item);
					$(item).addClass('layui-this');
					$(item).parents('dd').addClass('layui-nav-itemed');
					$(item).parents('dl').addClass('current');
					
					let $menuli = $(item).parents('.menu-li');
					$menuli.addClass('layui-nav-itemed');
					
					// 展开菜单模式
					if ($('.layout-menu-expand').length) {
						$menuli.siblings().find('a').removeClass('layui-this');
						$menuli.children('a').addClass('layui-this');
						// 子级菜单
						$menuli.siblings().find('.miucms-second-menu').removeClass('current');
						if ($menuli.children('.miucms-second-menu').length) {
							$('#MiuAppBody').addClass('expand-menu');
							let second_menu = $(this).parent().parent();
							second_menu.removeClass('show-up');
							//console.log(second_menu.html());
						}
					}
				}
			})
		},
		refresh:function(id){
			if(parent.document.getElementById(id)){
				let src = parent.document.getElementById(id).contentWindow.location.href ? parent.document.getElementById(id).contentWindow.location.href : iframe.src;
				document.getElementById(id).src = src;
			}
		},
		tabCookie:function(){
			let thetabs = $('#pageTabUl').find('li'),tab_id = 0,tab_array=[];
			thetabs.each(function(index,item){
				let _id = $(item).attr('lay-id'),_url = $(item).attr('lay-url'),_title = $(item).text();
				if(_id>0){
					tab_array.push({'id':_id,'url':_url,'title':_title});
				}
				if($(item).hasClass('layui-this')){
					tab_id = _id;
				}				
			})
			if(tab_array.length>0){
				let tabs_obj = {'tab_id':tab_id,'tab_array':tab_array};
				tab.setCookie('miucmstab',JSON.stringify(tabs_obj));
			}
			else{
				tab.delCookie('miucmstab');
			}
		},
		setCookie: function (name, value, days) {
			let expires = "";
			if (days) {
				let date = new Date();
				date.setTime(date.getTime() + (days * 24 * 60 * 60 * 1000));
				expires = "; expires=" + date.toGMTString();
			}
			document.cookie = name + "=" + value + expires + "; path=/";
		},
		getCookie: function (name) {
			let arr = document.cookie.match(new RegExp("(^| )" + name + "=([^;]*)(;|$)"));
			if (arr != null) {
				return unescape(arr[ 2 ]);
			}
			return null;
		},
		delCookie: function (name) {
			this.setCookie(name, "", -1);
		},
		officeView:function (id,mode){
			layer.open({
				type: 2,
				title: 'OFFICE文件查看（当前使用的是微软官方的OFFICE接口，服务器需要配置域名且连接外网才能查看）',
				id:'officeView',
				shadeClose: false,
				maxmin: false, //开启最大化最小化按钮
				area: ['100%', window.innerHeight+'px'],
				offset: 'b',
				//zIndex:2999,
				resize:false,
				anim: 2,
				scrollbar:false,
				//content: '/api/office/view?id='+id+'&mode='+mode,
				content: '/api/office/officeapps?id='+id+'&mode='+mode,
				cancel: function(index, layero, that){
					//layer.msg('文件在后台保存中，请稍再查看或下载...', {time: 3*1000});
				}
			});
		},
		pdfView:function (href){
			layer.open({
				type: 2,
				title: 'PDF文件查看（当前使用的是浏览器自身接口查看）',
				id:'pdfView',
				shadeClose: false,
				anim: 2,
				maxmin: false, //开启最大化最小化按钮
				area: ['100%', window.innerHeight+'px'],
				offset: 'b',
				//zIndex:2999,
				resize:false,
				content: href
			});
		},
		videoView:function (href){
			layer.open({
				type: 1,
				title: '视频文件播放',
				id:'videoView',
				shadeClose: false,
				area: ['810px', '510px'],
				content: '<div style="padding:5px 5px 0"><video src="'+href+'" playsinline="" controls="true" style="width:800px; height:450px"></video></div>'
			});
		},
		audioView:function (href){
			layer.open({
				type: 1,
				title: '音频文件播放',
				id:'audioView',
				shadeClose: false,
				area: ['500px', '120px'],
				content: '<audio src="'+href+'" playsinline="" controls="true" style="width:500px; height:60px"></audio>'
			});
		},
		photoView:function (href){
			let photos = { "data": [{"src": href}]};
			layer.photos({
				photos: photos,
				anim: 5 //0-6的选择，指定弹出图片动画类型，默认随机（请注意，3.0之前的版本用shift参数）
			});
		}
	};
	//选中/切换当前 tab 时，高亮中间面板对应菜单项（样式与 hover 一致）
	function highlightSubMenu(id) {
		let $subNav = $('#subNav');
		if ($subNav.length == 0 || !id) {
			return;
		}
		$subNav.find('.active').removeClass('active');
		$subNav.find('li').each(function () {
			if ($(this).children('a[data-id="' + id + '"]').length > 0) {
				$(this).addClass('active');
				return false;
			}
		});
	}
	//切换tab
	element.on('tab(miucms-admin-tab)', function (data) {
		// 优先按 lay-id 精确定位页面容器，避免 li 与 .miucms-tab-page 数量错位时
		// eq(index) 取到上一个（上一次点击的）页面；找不到再退回按索引匹配
		let thisPage = $miuAppBody.find('.miucms-tab-page[data-id="' + data.id + '"]');
		if (thisPage.length == 0) {
			thisPage = $miuAppBody.find('.miucms-tab-page').eq(data.index);
		}
		// 切换标签时联动中间二级菜单：展开当前 tab 所属一级菜单的二级菜单
		if (typeof window.findTopLevelMenu === 'function' && typeof window.renderSubPanel === 'function') {
			var topMenu = window.findTopLevelMenu(data.id);
			if (topMenu) {
				window.renderSubPanel(topMenu);
			} else {
				// 找不到所属一级菜单（如直接打开的三级页面），隐藏中间栏
				$('#mainLayout').removeClass('has-sub').addClass('no-sub');
			}
		}
		// 高亮中间面板中对应当前 tab 的菜单项（必须在 renderSubPanel 之后，避免被覆盖）
		highlightSubMenu(data.id);
		let id = thisPage.data('id');
		let url = thisPage.data('url');
		if(thisPage.find('iframe').length==0){
			// 获取当前时间戳（毫秒数）
			let timestamp = new Date().getTime();
			thisPage.html('<iframe id="'+id+'" data-frameid="'+id+'" data-timestamp="'+timestamp+'" src="'+url+'" frameborder="0" align="left" width="100%" height="100%" scrolling="yes"></iframe>');
		}
		tab.tabFollow(id);
		$miuAppBody.find('.miucms-tab-page').removeClass('layui-show');
		thisPage.addClass('layui-show');
		if(data.index==0){
			tab.refresh(0);
		}
		tab.loading();
		tab.tabRoll("auto", data.index);
		tab.tabCookie();
	});
	//删除tab
	element.on('tabDelete(miucms-admin-tab)', function (data) {
		// 同样优先按 lay-id 删除，数量错位时避免误删相邻页面
		let delPage = $miuAppBody.find('.miucms-tab-page[data-id="' + data.id + '"]');
		if (delPage.length == 0) {
			delPage = $miuAppBody.find('.miucms-tab-page').eq(data.index);
		}
		delPage.remove();
		tab.tabRoll("auto", data.index);
		tab.tabCookie();
	});

	//右滚动tab
	$('[miu-event="tabRollRight"]').click(function () {
		tab.tabRoll("right");
	})
	//左滚动tab
	$('[miu-event="tabRollLeft"]').click(function () {
		tab.tabRoll("left");
	})

	//左右滚动箭头：只有标签超出可视宽度时才显示
	// 标签增删 → MutationObserver 自动重算；窗口尺寸变化 → resize 重算
	if (window.MutationObserver) {
		let tabTitleEl = document.querySelector("#pageTabs .layui-tab-title");
		if (tabTitleEl) {
			new MutationObserver(function () {
				tab.tabRollBtn();
			}).observe(tabTitleEl, {childList: true});
		}
	}
	$(window).on("resize", function () {
		tab.tabRollBtn();
	});
	tab.tabRollBtn();

	//关闭全部tab，只保留首页
	$miuApp.on('click', '[miu-event="closeAllTabs"]', function () {
		let thetabs = $('#pageTabs .layui-tab-title').find('li'), ids = [];
		for (let i = 0; i < thetabs.length; i++) {
			let id = thetabs.eq(i).attr('lay-id');
			ids.push(id);
		}
		tab.tabDeleteAll(ids);
		$('#mainLayout').removeClass('has-sub').addClass('no-sub');
		return false;
	})

	//关闭其他tab
	$miuApp.on('click', '[miu-event="closeOtherTabs"]', function () {
		let thetabs = $('#pageTabs .layui-tab-title').find('li'), ids = [];
		let thisid = $('#pageTabs .layui-tab-title').find('.layui-this').attr('lay-id');
		for (let i = 0; i < thetabs.length; i++) {
			let id = thetabs.eq(i).attr('lay-id');
			if (id != thisid) {
				ids.push(id);
			}
		}
		tab.tabDeleteAll(ids);
		return false;
	})

	//关闭当前tab
	$miuApp.on('click', '[miu-event="closeThisTabs"]', function () {
		let thisid = $('#pageTabs .layui-tab-title').find('.layui-this').attr('lay-id');
		tab.tabDelete(thisid);
		return false;
	})

	//当点击有side-menu-item属性的标签时，即左侧菜单栏中内容 ，触发tab
	$('body').on('click', 'a.side-menu-item', function () {
		let that = $(this);
		let url = that.data("href"), id = that.data("id"), title = that.data("title");
		if (url == '' || url == '/') {
			return false;
		}
		//这时会判断右侧.layui-tab-title属性下的有lay-id属性的li的数目，即已经打开的tab项数目
		//$('.site-menu-active').removeClass('layui-this');
		//that.addClass('layui-this');
		//$miuApp.removeClass('side-spread-sm');
		if ($(".layui-tab-title li[lay-id]").length <= 0) {
			//打开新的tab项
			tab.tabAdd(id, url, title);
		} else {
			//否则判断该tab项是否以及存在
			let isHas = false;
			$.each($(".layui-tab-title li[lay-id]"), function () {
				//如果点击左侧菜单栏所传入的id 在右侧tab项中的lay-id属性可以找到，则说明该tab项已经打开
				if ($(this).attr("lay-id") == id) {
					isHas = true;
					$('[data-frameid="' + id + '"]').attr('src', url);
					//最后不管是否新增tab，最后都转到要打开的选项页面上
					tab.tabChange(id);
				}
			})
			if (isHas == false) {
				//标志为false 新增一个tab项
				tab.tabAdd(id, url, title);
			}
		}
		try {
			layer.close(window.openTips);
		} catch (e) {
			console.log(e.message);
		}
	});

	//左侧菜单展开&收缩
	$miuApp.on('click', '[miu-event="shrink"]', function () {
		let that_i = $(this).children('i');
		if (that_i.hasClass("layui-icon-shrink-right")) {
			that_i.removeClass("layui-icon-shrink-right").addClass("layui-icon-spread-left");			
		}
		else{
			that_i.removeClass("layui-icon-spread-left").addClass("layui-icon-shrink-right");	
		}
		$miuApp.toggleClass('side-spread');
	})
	$miuApp.on('click', '[miu-event="toggleSubSide"]', function () {
		var $layout = $('#mainLayout');
		if ($layout.hasClass('sub-collapsed')) {
			$layout.removeClass('sub-collapsed').addClass('has-sub');
		} else {
			$layout.removeClass('has-sub').addClass('sub-collapsed');
		}
		return false;
	})

	/*
	$miuApp.on('click', '[miu-event="shade"]', function () {
		$miuApp.removeClass('side-spread-sm');
	})
	*/

	//左上角清除缓存
	$miuApp.on('click', '[miu-event="cache"]', function (e) {
		let that = $(this);
		let url = $(this).data('href');
		if (that.hasClass('clearThis') === true) {
			layer.tips('正在努力清理中...', this);
			return false;
		}
		layer.tips('正在清理系统缓存...', this);
		that.addClass('clearThis');
		$.ajax({
			url: url,
			type: 'POST',
			success: function (res) {
				if (res.code == 0) {
					layer.tips(res.msg, that);
				} else {
					layer.alert('<div style="max-height:60vh;overflow:auto;word-break:break-all;line-height:1.8;">' + res.msg + '</div>', {title: '缓存清理结果'});
				}
			},
			complete:function(){
				that.removeClass('clearThis');
			}
		})
	})

	//右上角刷新当前tab页面
	$miuApp.on('click', '[miu-event="refresh"]', function () {
		let that = $(this);
		if (that.hasClass("refreshThis")) {
			that.removeClass("refreshThis");
			let iframe = $(".miucms-tab-page.layui-show").find("iframe")[0];
			if (iframe) {
				tab.refresh(iframe.id);
			}
			setTimeout(function () {
				that.attr("class", "refreshThis");
			}, 2000)
		} else {
			layer.tips("每2秒只可刷新一次", this, {
				tips: 1
			});
		}
		return false;
	})

	//右上角全屏&退出全屏
	$miuApp.on("click", ".fullScreen", function () {
		if ($(this).hasClass("layui-icon-screen-restore")) {
			screenFun(2).then(function () {
				$(".fullScreen").eq(0).removeClass("layui-icon-screen-restore");
			});
		} else {
			screenFun(1).then(function () {
				$(".fullScreen").eq(0).addClass("layui-icon-screen-restore");
			});
		}
	});

	//小菜单展现多级菜单
	$miuApp.on("mouseenter", ".layui-nav-tree .menu-li", function () {
		let tips = $(this).prop("innerHTML");
		if ($miuApp.hasClass('side-spread') && tips) {
			tips = "<ul class='layuimini-menu-left-zoom layui-nav layui-nav-tree layui-this'><li class='layui-nav-item layui-nav-itemed'>" + tips + "</li></ul>";
			window.openTips = layer.tips(tips, $(this), {
				tips: [2, '#2f4056'],
				time: 300000,
				skin: "popup-tips",
				success: function (el) {
					let left = $(el).position().left - 10;
					$(el).addClass('side-layout').css({ left: left });
					element.render();
				}
			});
		}
	});

	$("body").on("mouseleave", ".popup-tips", function () {
		try {
			layer.close(window.openTips);
		} catch (e) {
			console.log(e.message);
		}
	});

	function screenFun(num) {
		num = num || 1;
		num = num * 1;
		let docElm = document.documentElement;
		switch (num) {
			case 1:
				if (docElm.requestFullscreen) {
					docElm.requestFullscreen();
				} else if (docElm.mozRequestFullScreen) {
					docElm.mozRequestFullScreen();
				} else if (docElm.webkitRequestFullScreen) {
					docElm.webkitRequestFullScreen();
				} else if (docElm.msRequestFullscreen) {
					docElm.msRequestFullscreen();
				}
				break;
			case 2:
				if (document.exitFullscreen) {
					document.exitFullscreen();
				} else if (document.mozCancelFullScreen) {
					document.mozCancelFullScreen();
				} else if (document.webkitCancelFullScreen) {
					document.webkitCancelFullScreen();
				} else if (document.msExitFullscreen) {
					document.msExitFullscreen();
				}
				break;
		}
		return new Promise(function (res, rej) {
			res("返回值");
		});
	}

	function isFullscreen() {
		return document.fullscreenElement ||
			document.msFullscreenElement ||
			document.mozFullScreenElement ||
			document.webkitFullscreenElement || false;
	}

	window.onresize = function () {
		if (!isFullscreen()) {
			$(".fullScreen").eq(0).removeClass("layui-icon-screen-restore");
		}
	}
	exports('admin', tab);
});  
