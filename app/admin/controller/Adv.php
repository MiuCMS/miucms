<?php
/**
 * @copyright Copyright (c) 2026 米柚工作室
 * @license https://opensource.org/licenses/Apache-2.0
 * @link https://www.miucms.com
 */

declare (strict_types = 1);

namespace app\admin\controller;

use app\admin\BaseController;
use app\admin\model\Adv as AdvModel;
use app\admin\validate\AdvCheck;
use think\exception\ValidateException;
use think\facade\Db;
use think\facade\View;

/**
 * 后台「基础 → 广告/轮播」
 *
 * 一个广告 = 一条 cms_adv 记录（title / type / mark / status / content / desc）。
 * 广告内容都存在 content 里，按 type 长得不一样（见 app\admin\model\Adv）：
 *
 *   轮播图片    content 是一段 JSON 数组，每张图一条：
 *               img_src 图片地址 / img_title 图片标题 / img_desc 图片注释（HTML）/
 *               img_url 跳转链接 / img_target 是否新窗口 / i18n 各语言标题和注释
 *   多媒体      content 就是那条媒体地址
 *   HTML代码    content 就是那段 HTML
 *
 * 前台用 {miu:adv mark='标识'} 调（mark 就是这里的「标识」）。
 */
class Adv extends BaseController
{
    /**
     * 列表
     */
    public function index()
    {
        if (request()->isAjax()) {
            $param = get_params();
            $where = [];
            if (!empty($param['keywords'])) {
                $where[] = ['id|title|mark|desc', 'like', '%' . $param['keywords'] . '%'];
            }
            $rows = empty($param['limit']) ? get_config('app.page_size') : $param['limit'];
            $adv  = AdvModel::where($where)
                ->order('id desc')
                ->paginate(['list_rows' => $rows]);

            //列表里要显示「类型 + 缩略图 + 内容摘要」，摘要要按 content 现算
            $adv->each(function ($item) {
                $type = (int) ($item['type'] ?? 0);
                $sum  = AdvModel::contentSummary($item['content'], $type);

                $item['type_name']     = AdvModel::typeName($type);
                $item['content_text']  = $sum['text'];
                $item['content_thumb'] = $sum['thumb'];
                //条数：轮播图是图片张数，弹窗里拼 JS 的 row 参数用它
                $item['content_count'] = (int) ($sum['count'] ?? 0);

                //轮播图片：把每张图的地址都带上，列表里只显示前几张，点开能预览整行
                //（$item 是 Model 对象，不能写 $item['x'][] = ...，那样改的是副本）
                $images = [];
                if ($type === AdvModel::TYPE_IMAGE) {
                    foreach (AdvModel::decodeImages($item['content']) ?: [] as $img) {
                        $src = (string) ($img['img_src'] ?? '');
                        if ($src !== '') {
                            $images[] = [
                                'src' => $src,
                                'alt' => (string) ($img['img_title'] ?? ''),
                            ];
                        }
                    }
                }
                $item['content_images'] = $images;

                return $item;
            });

            return table_assign(0, '', $adv);
        }

        //「调用标签」弹窗里那段 JS 的地址：**动态地址**，不随后台的伪静态/静态规则变，
        //这样贴到模板里（甚至别的站）引用时永远打得开。
        //后台拿不到 home 应用的 url_entry()，这里按同样的规则自己拼一份
        $urlConfig = \url_config();
        $entry = ((int) ($urlConfig['no_index'] ?? 1)) === 1 ? '' : '/index.php';
        View::assign('adv_js_base', request()->domain() . $entry . '/home/api/adv');

        return view();
    }

    /**
     * 添加 / 编辑（同一个方法，带了 id 就是编辑）
     */
    public function add()
    {
        $param = get_params();

        if (request()->isAjax()) {
            $isEdit = !empty($param['id']) && $param['id'] > 0;

            try {
                validate(AdvCheck::class)->scene($isEdit ? 'edit' : 'add')->check($param);
            } catch (ValidateException $e) {
                return to_assign(1, $e->getError());
            }

            //广告类型决定 content 怎么存
            $type = (int) ($param['type'] ?? 0);
            if (!AdvModel::isValidType($type)) {
                return to_assign(1, '请选择广告类型');
            }

            [$content, $err] = AdvModel::buildContent($type, $param);
            if ($err !== '') {
                return to_assign(1, $err);
            }

            if ($type === AdvModel::TYPE_IMAGE) {
                try {
                    $content = \app\services\PluginService::autoTranslateImageContent($content);
                } catch (\Throwable $e) {
                    return to_assign(1, '自动翻译失败，广告未保存：' . $e->getMessage());
                }
            }

            $data = [
                'title'   => trim((string) $param['title']),
                'type'    => $type,
                'mark'    => trim((string) $param['mark']),
                'status'  => (int) $param['status'],
                'content' => $content,
                'desc'    => trim((string) ($param['desc'] ?? '')),
            ];

            if ($isEdit) {
                $data['id']          = (int) $param['id'];
                $data['update_time'] = time();
                $res = AdvModel::where('id', $data['id'])->strict(false)->field(true)->update($data);
                if ($res !== false) {
                    safe_add_log('edit', $data['id'], $data);
                }
            } else {
                $data['add_time']    = time();
                $data['update_time'] = time();
                $res = AdvModel::strict(false)->field(true)->insertGetId($data);
                if ($res) {
                    safe_add_log('add', $res, $data);
                }
            }

            //前台 {miu:adv} 的广告是持久缓存的，改完必须清，否则前台还是旧内容
            clear_cache('homeAdv');
            return to_assign();
        }

        //GET：编辑时把 content 拆开，回填到对应类型的输入框里
        $id  = (int) ($param['id'] ?? 0);
        $adv = [];

        $type        = AdvModel::TYPE_IMAGE;
        $images      = [];
        $mediaUrl    = '';
        $htmlContent = '';

        if ($id > 0) {
            $adv = Db::name('Adv')->where('id', $id)->find();
            if (!$adv) {
                return $this->error('广告不存在或已删除');
            }

            //类型取库里的；值被改坏了就按图片类型打开
            $type = (int) ($adv['type'] ?? 0);
            if (!AdvModel::isValidType($type)) {
                $type = AdvModel::TYPE_IMAGE;
            }

            if ($type === AdvModel::TYPE_IMAGE) {
                $images = AdvModel::decodeImages($adv['content']) ?: [];
            } elseif ($type === AdvModel::TYPE_MEDIA) {
                $mediaUrl = (string) $adv['content'];
            } else {
                $htmlContent = (string) $adv['content'];
            }
        }

        View::assign('adv', $adv);
        View::assign('id', $id);
        View::assign('adv_type', $type);
        View::assign('adv_images', $images);
        //给表单里的 JS 用：现有图片列表（JSON_HEX_TAG 把 < > 转义掉，
        //万一某条描述里带了 </script> 也不会把页面脚本截断）
        View::assign('adv_images_json', json_encode(
            $images,
            JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT
        ));
        View::assign('adv_media_url', $mediaUrl);
        View::assign('adv_html', $htmlContent);

        return view();
    }

    /**
     * 删除
     */
    public function delete()
    {
        if (!request()->isDelete() || !valid_admin_csrf()) return to_assign(1, '请求无效，请刷新页面后重试');
        $id    = get_params('id');
        $logId = is_array($id) ? implode(',', $id) : $id;

        if (Db::name('Adv')->where('id', 'in', $id)->delete() !== false) {
            safe_add_log('delete', $logId);
            clear_cache('homeAdv');
            return to_assign(0, '删除成功');
        }

        return to_assign(1, '删除失败');
    }
}
