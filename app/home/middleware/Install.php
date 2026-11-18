<?php
/**
 * @copyright Copyright (c) 2026 米柚工作室
 * @license https://opensource.org/licenses/Apache-2.0
 * @link https://www.miucms.com
 */

declare (strict_types = 1);

namespace app\home\middleware;

class Install
{
    public function handle($request, \Closure $next)
    {
        if (!is_installed()) {
            if ($request->isAjax()) {
                return to_assign(1, '请先完成系统安装引导');
            }
            $baseFile = (string) $request->baseFile();
            if ($baseFile === '') {
                $baseFile = '/index.php';
            }
            return redirect($baseFile . '?s=install/index/index');
        }

        return $next($request);
    }
}
