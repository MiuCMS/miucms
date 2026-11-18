<?php
/**
 * 演示插件 - 前台路由
 *
 * 安装并启用插件后，访问 /demo/hello 即可打开插件的前台演示页。
 * 格式：'路由规则' => '完整控制器类@方法'
 */

return [
    'demo/hello' => 'plugins\demo\controller\Index@hello',
];
