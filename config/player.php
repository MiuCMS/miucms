<?php
// +----------------------------------------------------------------------
// | 视频播放器配置
// +----------------------------------------------------------------------
return [
    // 新增播放组时默认使用的播放器
    'default' => 'html5',
    // 播放器标识和后台显示名称，播放组保存播放器标识
    'players' => [
        'html5' => [
            'name' => 'HTML5播放器',
        ],
        'dplayer' => [
            'name' => 'DPlayer播放器',
            'script' => '/static/home/dplayer/dplayer.js',
            // DPlayer 基础参数；外部脚本已包含播放器样式。
            'options' => [
                'lang' => 'zh-cn',
                'theme' => '#1e9fff',
                'preload' => 'metadata',
                'mutex' => true,
                'volume' => 0.7,
            ],
        ],
    ],
];
