<?php
/**
 * @copyright Copyright (c) 2026 米柚工作室
 * @license https://opensource.org/licenses/Apache-2.0
 * @link https://www.miucms.com
 */

namespace app\admin\validate;

use think\Validate;

class NavCheck extends Validate
{
    protected $rule = [
        'title' => 'require|unique:nav',
        'id' => 'require',
        'status' => 'require',
    ];

    protected $message = [
        'title.require' => '标题不能为空',
        'title.unique' => '同样的标题已经存在',
        'id.require' => '缺少更新条件',
        'status.require' => '状态为必选',
        'filed.require' => '缺少要更新的字段名',
    ];

    protected $scene = [
        'add' => ['title', 'status'],
        'edit' => ['id', 'title', 'status'],
        'addInfo' => ['title'],
        'editInfo' => ['id', 'title'],
    ];
}
