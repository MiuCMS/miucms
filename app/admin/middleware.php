<?php
// 这是系统自动生成的middleware定义文件
return [
	//开启session中间件
	//'think\middleware\SessionInit',
	//验证米柚cms是否完成安装
	\app\home\middleware\Install::class,
	//升级替换程序文件时暂停后台业务请求，但保留升级恢复入口
	\app\middleware\UpgradeMaintenance::class,
	//注册后台内置模板标签
	\app\admin\middleware\TemplateTags::class,
	//验证管理员操作权限
	\app\admin\middleware\Auth::class,
];
