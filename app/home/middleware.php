<?php
/**
 * @copyright Copyright (c) 2026 米柚工作室
 * @license https://opensource.org/licenses/Apache-2.0
 * @link https://www.miucms.com
 */

// 这是系统自动生成的middleware定义文件
return [
    //验证米柚cms是否完成安装（放最前，未安装时不去读配置表，避免报错）
    \app\home\middleware\Install::class,
    //升级替换程序文件时，暂停前台请求
    \app\middleware\UpgradeMaintenance::class,
    // 后台授权的模板预览始终走独立的动态地址。
    \app\home\middleware\TemplatePreview::class,
    //站点状态：网站关闭时直接返回关闭页，不再往下走
    \app\home\middleware\SiteStatus::class,
    //前台模板切换（放视图渲染前，保证 view_path 已就位）
    \app\home\middleware\Template::class,
    //伪静态 / 静态URL 解析（放路由分发前，把 /bokezixun/ 这类地址翻译成控制器地址）
    \app\home\middleware\UrlRewrite::class,
    //开启session中间件
    'think\middleware\SessionInit',
    // 多语言加载
    \think\middleware\LoadLangPack::class,
    //验证操作
    \app\home\middleware\Auth::class,
];
