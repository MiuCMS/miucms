<?php
/**
 * @copyright Copyright (c) 2026 米柚工作室
 * @license https://opensource.org/licenses/Apache-2.0
 * @link https://www.miucms.com
 */

declare(strict_types=1);

namespace app\services;

use app\extend\plugin\Hook;
use app\extend\plugin\PluginBase;
use app\extend\plugin\PluginManager;

/**
 * 插件服务（业务代码触发钩子的统一入口）
 *
 * 业务代码里不要直接 new Hook，走这里，语义更清楚，将来改钩子名也只改这一处。
 * 注意：带 & 的参数是「引用传参」，插件里改了它，调用方拿到的是改后的值。
 * 完整钩子清单见 Hook::HOOK_LIST / 后台「插件 → 钩子管理」。
 */
class PluginService
{
    /**
     * 通用触发
     * @param string $name
     * @param mixed  ...$args
     * @return mixed
     */
    public static function trigger(string $name, mixed &...$args): mixed
    {
        return Hook::instance()->trigger($name, ...$args);
    }

    // ==================== 生命周期 ====================

    /** 应用初始化 */
    public static function appInit(): void
    {
        Hook::instance()->trigger('app_init');
    }

    /** 模块加载 */
    public static function moduleInit(string $module): void
    {
        Hook::instance()->trigger('module_init', $module);
    }

    /** 控制器执行前 */
    public static function actionBegin(string $controller, string $action): void
    {
        Hook::instance()->trigger('action_begin', $controller, $action);
    }

    /** 控制器执行后 */
    public static function actionEnd(string $controller, string $action, mixed &$result): void
    {
        Hook::instance()->trigger('action_end', $controller, $action, $result);
    }

    /** 模板解析完成（$content 可改） */
    public static function templateParse(string &$content): void
    {
        Hook::instance()->trigger('template_parse', $content);
    }

    /** 预加载启用插件声明的后台语言词条，供菜单和插件页面共用。 */
    public static function adminI18nScript(): string
    {
        $manager = PluginManager::instance();
        $messages = [];
        foreach ($manager->getEnabledPluginNames() as $name) {
            if (!$manager->isValidName($name)) continue;
            $file = $manager->getPluginPath() . $name . '/admin/i18n.json';
            if (!is_file($file) || !is_readable($file)) continue;

            $dictionary = json_decode((string) @file_get_contents($file), true);
            if (is_array($dictionary)) {
                $messages = array_replace_recursive($messages, $dictionary);
            }
        }
        if (!$messages) return '';
        $json = json_encode($messages, JSON_HEX_TAG | JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE);
        return '<script>if(window.MiuAdminI18n&&window.MiuAdminI18n.addMessages){window.MiuAdminI18n.addMessages('
            . $json . ');}</script>';
    }

    // ==================== 文档 ====================

    /** 文档添加前（$data 可改） */
    public static function documentAdd(array &$data): void
    {
        Hook::instance()->trigger('document_add', $data);
    }

    /** 文档添加后 */
    public static function documentAdded(int $id, array $data): void
    {
        Hook::instance()->trigger('document_added', $id, $data);
    }

    /** 文档编辑前（$data 可改） */
    public static function documentEdit(array &$data): void
    {
        Hook::instance()->trigger('document_edit', $data);
    }

    /** 文档编辑后 */
    public static function documentEdited(int $id, array $data): void
    {
        Hook::instance()->trigger('document_edited', $id, $data);
    }

    /** 文档列表操作列扩展；插件可追加 event/title/class 按钮定义。 */
    public static function documentListAction(array &$actions): void
    {
        Hook::instance()->trigger('document_list_action', $actions);
    }

    /** 自定义变量列表操作列扩展；插件可按当前行数据决定是否追加按钮。 */
    public static function customListAction(array $row, array &$actions): void
    {
        Hook::instance()->trigger('custom_list_action', $row, $actions);
    }

    /** 标签列表操作列扩展；插件可追加 event/title/class 按钮定义。 */
    public static function tagListAction(array &$actions): void
    {
        Hook::instance()->trigger('tag_list_action', $actions);
    }

    /** 栏目列表操作列扩展；插件可追加 event/title/class 按钮定义。 */
    public static function navListAction(array &$actions): void
    {
        Hook::instance()->trigger('nav_list_action', $actions);
    }

    /** 后台列表工具栏扩展。 */
    public static function adminListToolbarAction(string $listType, array &$actions): void
    {
        Hook::instance()->trigger('admin_list_toolbar_action', $listType, $actions);
    }

    /** 文档删除前 */
    public static function documentDelete(mixed $id): void
    {
        Hook::instance()->trigger('document_delete', $id);
    }

    /** 文档从回收站恢复 */
    public static function documentRestore(mixed $id): void
    {
        Hook::instance()->trigger('document_restore', $id);
    }

    /** 文档彻底删除前 */
    public static function documentPurge(mixed $id): void
    {
        Hook::instance()->trigger('document_purge', $id);
    }

    /** 文档审核/字段变更 */
    public static function documentExamine(array $ids, string $field, mixed $value): void
    {
        Hook::instance()->trigger('document_examine', $ids, $field, $value);
    }

    // ==================== 栏目 ====================

    /** 栏目添加前（$data 可改） */
    public static function navAdd(array &$data): void
    {
        Hook::instance()->trigger('nav_add', $data);
    }

    /** 栏目添加后 */
    public static function navAdded(int $id, array $data): void
    {
        Hook::instance()->trigger('nav_added', $id, $data);
    }

    /** 栏目编辑前（$data 可改） */
    public static function navEdit(array &$data): void
    {
        Hook::instance()->trigger('nav_edit', $data);
    }

    /** 栏目编辑后 */
    public static function navEdited(int $id, array $data): void
    {
        Hook::instance()->trigger('nav_edited', $id, $data);
    }

    /** 栏目删除前 */
    public static function navDelete(mixed $id): void
    {
        Hook::instance()->trigger('nav_delete', $id);
    }

    // ==================== 会员 ====================

    /** 用户注册（$data 可改） */
    public static function userRegister(array &$data): void
    {
        Hook::instance()->trigger('user_register', $data);
    }

    /** 用户登录（$user 可改） */
    public static function userLogin(array &$user): void
    {
        Hook::instance()->trigger('user_login', $user);
    }

    /** 用户退出 */
    public static function userLogout(array $user): void
    {
        Hook::instance()->trigger('user_logout', $user);
    }

    /** 用户资料修改（$data 可改） */
    public static function userUpdate(array &$data): void
    {
        Hook::instance()->trigger('user_update', $data);
    }

    // ==================== 后台管理员 ====================

    /** 管理员登录（$admin 可改） */
    public static function adminLogin(array &$admin): void
    {
        Hook::instance()->trigger('admin_login', $admin);
    }

    /** 管理员退出 */
    public static function adminLogout(array $admin): void
    {
        Hook::instance()->trigger('admin_logout', $admin);
    }

    // ==================== 搜索 ====================

    /** 搜索前（$keywords 可改） */
    public static function searchBefore(string &$keywords): void
    {
        Hook::instance()->trigger('search_before', $keywords);
    }

    /** 搜索后（$result 可改） */
    public static function searchAfter(array &$result): void
    {
        Hook::instance()->trigger('search_after', $result);
    }

    // ==================== 留言 / 表单 ====================

    /** 留言提交前（$data 可改） */
    public static function formSubmit(array &$data): void
    {
        Hook::instance()->trigger('form_submit', $data);
    }

    /** 留言提交后 */
    public static function formSubmitted(int $id, array $data): void
    {
        Hook::instance()->trigger('form_submitted', $id, $data);
    }

    /** 留言审核 */
    public static function formExamine(array $ids, int $examine): void
    {
        Hook::instance()->trigger('form_examine', $ids, $examine);
    }

    /** 留言回复 */
    public static function formReply(int $id, string $reply): void
    {
        Hook::instance()->trigger('form_reply', $id, $reply);
    }

    /** 留言删除前 */
    public static function formDelete(mixed $ids): void
    {
        Hook::instance()->trigger('form_delete', $ids);
    }

    // ==================== 文件 ====================

    /** 文件上传后 */
    public static function fileUpload(array $file): void
    {
        Hook::instance()->trigger('file_upload', $file);
    }

    // ==================== AI翻译插件 ====================

    /** 当前插件是否启用提交时自动翻译。开关及翻译规则由 AItranslation 插件管理。 */
    public static function isAutoTranslationEnabled(): bool
    {
        $plugin = self::enabledAITranslationPlugin();
        return $plugin !== null
            && method_exists($plugin, 'isAutoTranslateEnabled')
            && $plugin->isAutoTranslateEnabled();
    }

    /** 保存内容前由 AItranslation 插件补齐或更新译文。 */
    public static function autoTranslatePosted(
        string $entityType,
        array &$posted,
        array $source,
        string $entityTable = '',
        int $channelId = 0
    ): void {
        $plugin = self::enabledAITranslationPlugin();
        if ($plugin && method_exists($plugin, 'autoTranslatePosted')) {
            $plugin->autoTranslatePosted($entityType, $posted, $source, $entityTable, $channelId);
        }
    }

    /** 网站配置提交前由 AItranslation 插件准备翻译数据。 */
    public static function autoTranslateSiteConfig(array $source): array
    {
        $plugin = self::enabledAITranslationPlugin();
        if (!$plugin || !method_exists($plugin, 'autoTranslateSiteConfig')) {
            return [];
        }
        return $plugin->autoTranslateSiteConfig($source);
    }

    /** 轮播图片译文也由 AItranslation 插件统一生成。 */
    public static function autoTranslateImageContent(string $content): string
    {
        $plugin = self::enabledAITranslationPlugin();
        if (!$plugin || !method_exists($plugin, 'autoTranslateImageContent')) {
            return $content;
        }
        return $plugin->autoTranslateImageContent($content);
    }

    private static function enabledAITranslationPlugin(): ?PluginBase
    {
        $manager = PluginManager::instance();
        if (!in_array('AItranslation', $manager->getEnabledPluginNames(), true)) {
            return null;
        }
        return $manager->makePlugin('AItranslation');
    }
}
