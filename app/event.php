<?php

// 全局应用初始化事件
return [
    'listen' => [
        \think\event\AppInit::class => [
            \app\event\CacheDriver::class,
        ],
    ],
];
