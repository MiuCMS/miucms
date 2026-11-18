<?php
return [
    // 模板文件名分隔符
    'view_depr'    => '_',
    // 模板后缀保持不变
    'view_suffix'  => 'html',
    // 预加载前台自定义标签库，模板里可直接用 {miu:global name='site_name' raw='1' default=''/}
    'taglib_pre_load' => 'app\home\taglib\Miu,app\home\taglib\Comment',
];
