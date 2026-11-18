<?php
/**
 * 演示插件后台控制器。
 *
 * 插件自己的后台控制器继承 PluginAdminBase，得到后台登录校验和 render() 方法。
 * 控制器地址由系统统一入口分发：/admin/plugin/run?name=demo&c=Index&a=index
 */

declare(strict_types=1);

namespace plugins\demo\admin\controller;

use app\extend\plugin\PluginAdminBase;
use app\extend\plugin\PluginManager;
use think\facade\Db;

class Index extends PluginAdminBase
{
    /** 当前控制器所属的插件标识。 */
    protected string $plugin = 'demo';

    /** 插件后台首页：读取配置并查询插件自己的最近文档记录。 */
    public function index()
    {
        $config = PluginManager::instance()->getPluginConfig('demo');
        $limit = (int) ($config['record_limit'] ?? 10);
        $limit = max(1, min(100, $limit));

        $records = [];
        try {
            $records = Db::name('demo_record')
                ->order('id', 'desc')
                ->limit($limit)
                ->select()
                ->toArray();
        } catch (\Throwable $e) {
            // 未安装数据表时页面仍可打开；错误细节写日志便于管理员排查。
            trace('演示插件：读取记录失败 ' . $e->getMessage(), 'error');
        }

        return $this->render('index', [
            'config'     => $config,
            'pluginInfo' => PluginManager::instance()->getPluginInfoFromFile('demo'),
            'records'    => $records,
            'limit'      => $limit,
        ]);
    }

    /**
     * 插件自定义配置页。
     * 系统提供的配置弹窗仍可在插件列表点击“配置”打开；这个页面演示插件自己的表单。
     */
    public function config()
    {
        $config = PluginManager::instance()->getPluginConfig('demo');
        $greetingStyle = (string) ($config['greeting_style'] ?? 'friendly');
        if (!in_array($greetingStyle, ['friendly', 'brief', 'professional'], true)) {
            $greetingStyle = 'friendly';
        }
        $footerAlign = (string) ($config['footer_align'] ?? 'center');
        if (!in_array($footerAlign, ['left', 'center', 'right'], true)) {
            $footerAlign = 'center';
        }

        return $this->render('config', [
            'config'        => $config,
            'greetingStyle' => $greetingStyle,
            'footerAlign'   => $footerAlign,
        ]);
    }

    /** 清空演示记录，仅删除插件自己的数据；必须使用 POST 请求。 */
    public function clear()
    {
        if (!request()->isPost()) {
            return to_assign(1, '请使用 POST 提交');
        }

        try {
            Db::name('demo_record')->where('id', '>', 0)->delete();
        } catch (\Throwable $e) {
            trace('演示插件：清空记录失败 ' . $e->getMessage(), 'error');
            return to_assign(1, '清空失败，请检查插件数据表是否已安装');
        }

        return to_assign(0, '演示记录已清空');
    }
}
