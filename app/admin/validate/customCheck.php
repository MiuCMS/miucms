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
 * 自定义变量的表单校验
 *
 * 只校验「必须校验」的几个：
 *   name  字段标识：必填、只能是字母数字下划线短横线、最长 32（表字段是 varchar(32)）、不能重名
 *   title 字段名称：必填、最长 250（varchar(250)）
 *   type  变量类型：必填、必须在白名单里
 *   tips  提示说明：最长 256（varchar(256)）
 *
 * 数字类（maxlength / sort / status）不在这里校验，交给控制器统一整理
 * （页面上的输入框都是 type=number，正常也填不出脏数据）。
 *
 * 重名检查用 unique:custom：TP 在提交数据里带主键（id）时会自动排除当前这条，
 * 所以「编辑时不改标识」不会误报。
 */
class customCheck extends Validate
{
    protected $rule = [
        'name'  => 'require|alphaDash|length:1,32|unique:custom',
        'title' => 'require|length:1,250',
        'type'  => 'require|in:text,multitext,img,switch,media',
        'tips'  => 'length:0,256',
        'id'    => 'require',
    ];

    protected $message = [
        'name.require'   => '字段标识不能为空',
        'name.alphaDash' => '字段标识只能是字母、数字、下划线或短横线',
        'name.length'    => '字段标识最多 32 个字符',
        'name.unique'    => '同样的字段标识已经存在',
        'title.require'  => '字段名称不能为空',
        'title.length'   => '字段名称最多 250 个字符',
        'type.require'   => '请选择变量类型',
        'type.in'        => '变量类型不在可选范围内',
        'tips.length'    => '提示说明最多 256 个字符',
        'id.require'     => '缺少更新条件',
    ];

    protected $scene = [
        'add'  => ['name', 'title', 'type', 'tips'],
        'edit' => ['id', 'name', 'title', 'type', 'tips'],
    ];
}
