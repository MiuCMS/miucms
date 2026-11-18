<?php
/**
 * 钩子注册方式演示。
 *
 * Hook 管理器会合并两种方式：
 * 1. Plugin.php 里使用钩子对应的方法名，例如 documentAdded() 对应 document_added；
 * 2. 本文件里声明“钩子名 => 任意公开方法名”。
 *
 * 本示例的 templateParse、documentAdded、userLogin 使用方式一；
 * search_before 使用方式二，并映射到 onSearchBefore()。
 * 同一个钩子不要同时用两种方式注册，否则会执行两次。
 */

return [
    'search_before' => 'onSearchBefore',
];
