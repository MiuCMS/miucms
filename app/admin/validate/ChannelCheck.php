<?php
/**
 * @copyright Copyright (c) 2026 米柚工作室
 * @license https://opensource.org/licenses/Apache-2.0
 * @link https://www.miucms.com
 */

namespace app\admin\validate;

use think\Validate;

class ChannelCheck extends Validate
{
    protected $rule = [
        'title' => 'require|unique',
        'nid' => 'require',
        'type' => 'require',
        'name' => 'require',
    ];

    protected $message = [
        'title.require' => '模型名称不能为空',
        'title.unique' => '同样的模型名称已经存在',
        'nid.require' => '模型标识不能为空',
        'type.require' => '字段类型不能为空',
        'name.require' => '字段标识不能为空',
    ];

    protected $scene = [
        'add' => ['title', 'nid'],
        'edit' => ['id'],
        'addField' => ['title', 'name', 'type'],
        'editField' => ['title', 'name', 'type'],
    ];
}
