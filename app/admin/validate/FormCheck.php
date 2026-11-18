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
 * 留言表单 新增/编辑 校验
 *
 * unique 的写法：`unique:表名,字段名` 就够 —— 框架发现数据里带了主键（编辑时的 id）
 * 会自动把它排掉，不会「不改名字也报已存在」。
 * 注意别写成 `unique:form,form_name,id`：第 3 个参数是「要排除的值」不是字段名，
 * 写成字段名会变成 `id <> 'id'`，永远排不掉自己。
 */
class FormCheck extends Validate
{
    protected $rule = [
        'form_name' => 'require|max:100|unique:form,form_name',
        'id'        => 'require',
    ];

    protected $message = [
        'form_name.require' => '表单名称不能为空',
        'form_name.max'     => '表单名称最多 100 个字',
        'form_name.unique'  => '同样的表单名称已经存在',
        'id.require'        => '缺少更新条件',
    ];

    protected $scene = [
        'add'  => ['form_name'],
        'edit' => ['id', 'form_name'],
    ];
}
