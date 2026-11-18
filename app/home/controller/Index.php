<?php
/**
 * @copyright Copyright (c) 2026 米柚工作室
 * @license https://opensource.org/licenses/Apache-2.0
 * @link https://www.miucms.com
 */

declare (strict_types = 1);

namespace app\home\controller;

use app\home\BaseController;
use think\facade\View;
use think\facade\Db;

class Index extends BaseController
{
    public function index()
    {

        add_user_log('view', '首页');
        return View('/index');
    }
	
    public function logs()
    {
        add_user_log('view', '开发日志');
        return View('');
    }

	public function down()
    {
        $version = CMS_VERSION;
        add_user_log('down', $version.'版本代码');
        header("Location: " . rtrim((string) config('official.website'), '/') . "/storage/miucms_v" . $version . "_full.zip");
        //确保重定向后，后续代码不会被执行
        exit;
    }
    public function test(){


        return view();
    }
}
