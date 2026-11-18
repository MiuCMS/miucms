<?php
/**
 * @copyright Copyright (c) 2026 米柚工作室
 * @license https://opensource.org/licenses/Apache-2.0
 * @link https://www.miucms.com
 */
// 全局中间件定义文件
return [
    // 插件系统初始化（必须在最前面）
    \app\middleware\PluginInit::class,
    // 后台入口路径动态化：必须在 MultiApp 之前覆盖 app_map
    // （MultiApp 由 HttpRun 事件追加到队尾，所以这里任何位置都在它之前）
    \app\middleware\AdminPath::class,
    // 全局请求缓存
    // \think\middleware\CheckRequestCache::class,
    // 多语言加载
    // \think\middleware\LoadLangPack::class,
    // Session初始化
    'think\middleware\SessionInit',
];
