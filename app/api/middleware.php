<?php
// 这是系统自动生成的middleware定义文件
return [
	//开启session中间件
	//'think\middleware\SessionInit',
	//验证米柚cms是否完成安装
	\app\home\middleware\Install::class,
	//升级替换程序文件时，暂停接口请求
	\app\middleware\UpgradeMaintenance::class,
];
