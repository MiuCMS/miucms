<?php
/**
 * @copyright Copyright (c) 2026 米柚工作室
 * @license https://opensource.org/licenses/Apache-2.0
 * @link https://www.miucms.com
 */

declare(strict_types=1);

namespace app\extend\plugin;

use app\admin\BaseController;

/**
 * 插件后台控制器基类
 *
 * 插件自己的后台控制器（plugins/<name>/admin/controller/Xxx.php）继承这个，
 * 就自动获得「后台登录校验（父类的中间件）+ 渲染插件自己的视图」的能力。
 *
 * 用法：
 *   class Index extends PluginAdminBase {
 *       public function index() {
 *           return $this->render('index', ['foo' => 'bar']);
 *       }
 *   }
 * 视图放在 plugins/<name>/admin/view/index.html。
 *
 * 插件标识默认从命名空间推断（plugins\<name>\admin\controller\...），也可手动指定 $plugin。
 */
abstract class PluginAdminBase extends BaseController
{
    /**
     * 插件标识，留空则从命名空间推断
     * @var string
     */
    protected string $plugin = '';

    /**
     * 渲染插件自己的后台视图
     * @param string $template 模板名（不含后缀）
     * @param array  $vars     模板变量
     * @return string
     */
    protected function render(string $template, array $vars = []): string
    {
        return PluginManager::instance()->renderView($this->pluginName(), $template, $vars, 'admin');
    }

    /**
     * 当前插件标识
     * @return string
     */
    protected function pluginName(): string
    {
        if ($this->plugin !== '') {
            return $this->plugin;
        }
        if (preg_match('/^plugins\\\\([^\\\\]+)\\\\/', static::class, $m)) {
            return $m[1];
        }
        return '';
    }
}
