<?php
/**
 * 演示插件的后台菜单。
 *
 * 安装后到“插件管理”列表点击“添加到主菜单”，系统才会把这些菜单登记到后台权限表。
 * 插件停用时菜单隐藏，卸载时菜单节点会一并移除。
 * 插件图标在 Plugin.php 的 $icon 属性中声明；url 使用 admin/plugin/run 约定入口。
 */

return [
    [
        'title'    => '演示插件',
        'url'      => '',
        'children' => [
            [
                'title' => '演示插件首页',
                'url'   => 'admin/plugin/run?name=demo&c=Index&a=index',
            ],
            [
                'title' => '演示插件配置',
                'url'   => 'admin/plugin/run?name=demo&c=Index&a=config',
            ],
        ],
    ],
];
