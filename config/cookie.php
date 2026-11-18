<?php
// +----------------------------------------------------------------------
// | Cookie设置
// +----------------------------------------------------------------------
return [
    // cookie 保存时间
    'expire'    => 0,
    // cookie 保存路径
    'path'      => '/',
    // cookie 有效域名
    'domain'    => '',
    //  cookie 启用安全传输
    'secure'    => !empty($_SERVER['HTTPS']) && strtolower((string) $_SERVER['HTTPS']) !== 'off',
    // httponly设置
    'httponly'  => true,
    // 是否使用 setcookie
    'setcookie' => true,
    // samesite 设置，支持 'strict' 'lax'
    // 用 'lax' 缓解 CSRF：跨站发起的 POST/表单/XHR/fetch 不带 session cookie，
    // 后台的登录态就不会被第三方页面带着去发请求（注意：顶层 GET 跳转仍会带 cookie）
    'samesite' => 'lax',
];
