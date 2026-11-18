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
 * 广告（cms_adv）表单校验
 *
 * unique:adv 会自动排除「当前这条」：编辑时表单带了 id，ThinkPHP 会把
 * id <> 当前值 拼进查询条件（见 think-validate 的 unique()），所以新增/编辑共用一套规则。
 *
 * 这里只校验标题、标识、状态这三个表字段；广告内容（content）怎么存由
 * app\admin\model\Adv::buildContent() 按类型组装，出错也在那里返回。
 */
class AdvCheck extends Validate
{
    protected $rule = [
        'title'  => 'require|max:255|unique:adv',
        'mark'   => 'require|max:64|unique:adv|alphaDash',
        'id'     => 'require|number',
        'status' => 'require|in:1,-1',
    ];

    protected $message = [
        'title.require'  => '标题不能为空',
        'title.max'      => '标题不能超过 255 个字',
        'title.unique'   => '同样的标题已经存在',
        'mark.require'   => '标识不能为空',
        'mark.max'       => '标识不能超过 64 个字符',
        'mark.unique'    => '同样的标识已经存在',
        'mark.alphaDash' => '标识只能是字母、数字、下划线或短横线（模板里要靠它调广告）',
        'id.require'     => '缺少更新条件',
        'id.number'      => '更新条件不正确',
        'status.require' => '状态为必选',
        'status.in'      => '状态只能是 1（正常）或 -1（禁用）',
    ];

    protected $scene = [
        'add'  => ['title', 'mark', 'status'],
        'edit' => ['id', 'title', 'mark', 'status'],
    ];
}
