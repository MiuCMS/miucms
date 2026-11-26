<?php

// +----------------------------------------------------------------------

// | 控制台配置

// +----------------------------------------------------------------------

return [

    // 指令定义

    'commands' => [
		'crud' => 'app\crud\command\Crud',
		'crud-c' => 'app\crud\command\CrudController',
		'crud-m' => 'app\crud\command\CrudModel',
		'crud-v' => 'app\crud\command\CrudValidate',
		'crud-l' => 'app\crud\command\CrudList',
		'crud-a' => 'app\crud\command\CrudAdd',
		'crud-e' => 'app\crud\command\CrudEdit',
		'crud-r' => 'app\crud\command\CrudRead',
		//清理已过期的文件缓存（挂定时任务用：php think cache-clean）
		'cache-clean' => 'app\command\CacheClean',
		'db-collation' => 'app\command\DatabaseCollation',
		//生成 sitemap.xml / txt / html（php think sitemap，可挂定时任务）
		'sitemap' => 'app\command\Sitemap',
		//静态模式下发布/编辑文档后更新受影响的静态页面（后台发布文档时自动调）
		'static-update' => 'app\command\StaticUpdate',
		//把地址推送给百度（php think baidu-push，可挂定时任务）
		'baidu-push' => 'app\command\BaiduPush',
    ],

];

