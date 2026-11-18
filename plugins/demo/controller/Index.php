<?php
/**
 * 演示插件前台控制器。
 *
 * 前台插件控制器不要求继承后台基类。这里演示读取插件配置、接收 URL 参数，
 * 并切换到插件自己的模板目录渲染页面。
 */

declare(strict_types=1);

namespace plugins\demo\controller;

use app\extend\plugin\PluginManager;
use think\facade\View;

class Index
{
    /** 对应 route.php 中的 demo/hello。 */
    public function hello()
    {
        $pm = PluginManager::instance();
        $config = $pm->getPluginConfig('demo');

        $styleLabels = [
            'friendly'     => '友好亲切',
            'brief'        => '简洁直接',
            'professional' => '专业正式',
        ];
        $style = (string) ($config['greeting_style'] ?? 'friendly');
        if (!isset($styleLabels[$style])) {
            $style = 'friendly';
        }

        View::config([
            'view_path' => $pm->getPluginPath() . 'demo' . DIRECTORY_SEPARATOR . 'view' . DIRECTORY_SEPARATOR,
        ]);

        return View::fetch('/hello', [
            'config'       => $config,
            'name'         => (string) input('name/s', ''),
            'greetingStyle' => $styleLabels[$style],
        ]);
    }
}
