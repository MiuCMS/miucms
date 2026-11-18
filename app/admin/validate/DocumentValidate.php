<?php
/**
 * @copyright Copyright (c) 2026 米柚工作室
 * @license https://opensource.org/licenses/Apache-2.0
 * @link https://www.miucms.com
 */

namespace app\admin\validate;
use think\Validate;

class DocumentValidate extends Validate
{
    protected $rule = [
    'nav_id' => 'require',
    'title' => 'require',
    //'thumb' => 'require',
    //'content' => 'require',
    ];

    protected $message = [
    'nav_id.require' => '栏目不能为空',
    'title.require' => '标题不能为空',
    'thumb.require' => '缩略图不能为空',
    'content.require' => '文档内容不能为空',
    ];
}