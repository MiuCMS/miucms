<?php
declare(strict_types=1);

namespace app\services;

use app\admin\middleware\Auth;
use think\db\exception\PDOException;
use think\facade\Db;

final class DashboardQuickNav
{
    public const MIN_ITEMS = 6;
    public const MAX_ITEMS = 24;

    private const DEFAULT_ROUTES = [
        'admin/conf/index',
        'admin/seo/index',
        'admin/seo/sitemap',
        'admin/nav/index',
        'admin/document/index',
        'admin/form/message',
        'admin/seo/createhtml',
        'admin/plugin/index',
        'admin/channel/index',
        'admin/document/add',
        'admin/multilanguage/index',
        'admin/template/setting',
    ];

    private const OPTIONS = [
        'admin/conf/index' => ['网站配置', 'miu-quanju-f'],
        'admin/seo/index' => ['SEO设置', 'miuai-seo', 'URL配置'],
        'admin/seo/sitemap' => ['Sitemap', 'miu-ditu-f'],
        'admin/nav/index' => ['栏目管理', 'miu-guanggao-f', '栏目'],
        'admin/document/index' => ['文档管理', 'miu-neirong-f', '内容'],
        'admin/form/message' => ['留言管理', 'miu-liuyan-f'],
        'admin/seo/createhtml' => ['页面生成', 'miuai-shengchengyemian'],
        'admin/plugin/index' => ['插件', 'layui-icon-app'],
        'admin/channel/index' => ['模型管理', 'miu-xitong-f'],
        'admin/files/index' => ['文件管理器', 'miu-wenjianjia-f'],
        'admin/tplmanage/index' => ['模板管理', 'layui-icon-template-1'],
        'admin/document/add' => ['发布文档', 'layui-icon-edit'],
        'admin/document/draft' => ['草稿箱', 'layui-icon-read'],
        'admin/keywords/index' => ['标签管理', 'layui-icon-note'],
        'admin/adv/index' => ['广告与轮播', 'layui-icon-picture'],
        'admin/multilanguage/index' => ['国际化', 'layui-icon-website'],
        'admin/template/setting' => ['模板切换', 'layui-icon-template'],
        'admin/links/index' => ['友情链接', 'layui-icon-link'],
        'admin/user/index' => ['会员管理', 'layui-icon-user'],
        'admin/admin/index' => ['管理员管理', 'layui-icon-group'],
        'admin/role/index' => ['权限角色', 'layui-icon-auz'],
        'admin/database/database' => ['数据库备份', 'layui-icon-file-b'],
        'admin/log/index' => ['后台日志', 'layui-icon-log'],
        'admin/index/upgrade' => ['系统升级', 'layui-icon-upload-circle'],
    ];

    public static function options(int $adminId): array
    {
        $definitions = self::OPTIONS;
        $rules = Db::name('AdminRule')->where('status', 1)
            ->whereIn('src', array_keys($definitions))->column('id', 'src');
        $allowed = array_fill_keys(Auth::allowedRoutes($adminId), true);

        $options = [];
        foreach ($definitions as $route => $definition) {
            [$label, $icon] = $definition;
            $destination = $route;
            if ($route === 'admin/index/upgrade') {
                if ($adminId !== 1) continue;
                $tabId = 987654321;
            } else {
                if (!isset($allowed[$route], $rules[$route])) continue;
                $tabId = (int) $rules[$route];
            }

            if ($route === 'admin/document/add') {
                $destination = 'admin/document/index';
                if (!isset($allowed[$destination], $rules[$destination])) continue;
            }
            $href = (string) url($destination);
            if ($route === 'admin/document/add') {
                $href .= (str_contains($href, '?') ? '&' : '?') . 'quick_publish=1';
            }

            $options[$route] = [
                'key' => $route,
                'label' => $label,
                'icon' => $icon,
                'title' => $definition[2] ?? $label,
                'id' => $tabId,
                'href' => $href,
                'picker_order' => count($options),
            ];
        }

        return $options;
    }

    public static function selected(int $adminId, array $options): array
    {
        try {
            $stored = Db::name('quick_nav')->where('admin_id', $adminId)->value('items');
        } catch (PDOException $e) {
            // 升级 SQL 尚未执行时，概览仍可使用默认快捷入口。
            if (($e->getData()['PDO Error Info']['SQLSTATE'] ?? '') !== '42S02') throw $e;
            $stored = null;
        }

        $items = is_string($stored) ? json_decode($stored, true) : null;
        if (!is_array($items)) {
            $items = self::DEFAULT_ROUTES;
        }

        $selected = [];
        foreach ($items as $key) {
            if (is_string($key) && isset($options[$key])) {
                $selected[$key] = $options[$key];
            }
        }
        return array_values($selected);
    }
}
