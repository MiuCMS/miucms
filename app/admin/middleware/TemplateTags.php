<?php
/**
 * @copyright Copyright (c) 2026 米柚工作室
 * @license https://opensource.org/licenses/Apache-2.0
 * @link https://www.miucms.com
 */

declare(strict_types=1);

namespace app\admin\middleware;

use think\facade\View;

class TemplateTags
{
    public function handle($request, \Closure $next)
    {
        $taglibs = array_filter(array_map('trim', explode(',', (string) config('view.taglib_build_in', 'cx'))));
        $taglibs[] = \app\admin\taglib\Tips::class;
        View::config(['taglib_build_in' => implode(',', array_unique($taglibs))]);

        return $next($request);
    }
}
