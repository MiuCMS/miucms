<?php
// MiuCMS 官网及官方服务域名；官网地址调整时只需修改此处。
return [
    // MiuCMS 主站：安装上报、程序下载、安装协议及后台官网链接。
    // 调用：app/install/view/index/step3.html、
    // app/services/PluginUpdateManager.php、app/home/controller/Index.php、
    // app/install/view/index/step1.html、app/extend/template/stubs/footer.html.stub、
    // app/admin/view/index/main.html。
    'website' => 'https://www.miucms.com',

    // 官方动态 JSON 接口，供后台首页异步加载新闻。
    // 调用：app/admin/controller/Index.php。
    'news_feed' => 'https://www.miucms.com/api/official-news',

    // CMS 版本清单和升级包所在站点。
    // 调用：app/services/UpgradeManager.php。
    'upgrade' => 'https://www.miucms.com',

    // MiuCMS 演示站：安装协议页展示演示站链接。
    // 调用：app/install/view/index/step1.html。
    'demo' => 'https://demo.miucms.com',

    // MiuCMS 文档站：后台首页“帮助中心”入口。
    // 调用：app/admin/view/index/main.html。
    'docs' => 'https://docs.miucms.com',

    // MiuCMS 博客域名：当前项目暂无代码调用，保留为统一官网域名配置。
    'blog' => 'https://blog.miucms.com',

    // 官方服务站：插件市场 API，以及插件和 CMS 升级包下载。
    // 调用：app/services/PluginMarket.php、app/services/PluginUpdateManager.php、
    // app/services/UpgradeManager.php。
    'service' => 'https://www.miucms.com',
];
