<?php
/**
 * @copyright Copyright (c) 2026 米柚工作室
 * @license https://opensource.org/licenses/Apache-2.0
 * @link https://www.miucms.com
 */

namespace app\admin\model;

use think\Model;

// 关键字模型
class Keywords extends Model
{
    // 关联关键字
    public function increase($keywords)
    {
        $is_exist = $this->where('title', $keywords)->find();
        if ($is_exist) {
            $res = $is_exist['id'];
        } else {
            $res = $this->strict(false)->field(true)->insertGetId(['title' => $keywords, 'add_time' => time()]);
        }
        return $res;
    }
}
