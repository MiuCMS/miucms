<?php
/**
 * @copyright Copyright (c) 2026 米柚工作室
 * @license https://opensource.org/licenses/Apache-2.0
 * @link https://www.miucms.com
 */

declare (strict_types = 1);

namespace app\admin\validate;

use think\Validate;

/**
 * 表单字段 新增/编辑 校验
 *
 * 可选值列表、字段类型这些跟类型相关的校验放在控制器里做
 * （要按类型判断，写成规则反而绕）。
 */
class FormFieldCheck extends Validate
{
    protected $rule = [
        'attr_name' => 'require|max:60',
        'form_id'   => 'require|integer|gt:0',
        'id'        => 'require',
    ];

    protected $message = [
        'attr_name.require' => '字段名称不能为空',
        'attr_name.max'     => '字段名称最多 60 个字',
        'form_id.require'   => '缺少所属表单',
        'form_id.gt'        => '缺少所属表单',
        'id.require'        => '缺少更新条件',
    ];

    protected $scene = [
        'add'  => ['attr_name', 'form_id'],
        'edit' => ['id', 'attr_name'],
    ];
}
