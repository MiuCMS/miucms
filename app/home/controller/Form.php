<?php
/**
 * @copyright Copyright (c) 2026 米柚工作室
 * @license https://opensource.org/licenses/Apache-2.0
 * @link https://www.miucms.com
 */

declare (strict_types = 1);

namespace app\home\controller;

use app\home\BaseController;
use think\facade\Cache;
use think\facade\Db;
use think\facade\View;

/**
 * 前台留言表单
 *
 * 地址：
 *   GET  /form/12        表单页（12 是表单id，走 app/home/route/route.php 里的路由）
 *   POST /form/submit    提交留言（AJAX）
 * 老地址 /home/form/index?id=12 也照样能访问。
 *
 * 字段是后台「表单字段列表」里配的，模板按类型动态渲染；
 * 提交时再按同一套定义校验（必填、手机/邮箱格式），然后写：
 *   cms_form_message       留言主表
 *   cms_form_message_attr  每个字段一行
 */
class Form extends BaseController
{
    /**
     * 表单页
     *
     * @return \think\Response|\think\response\View
     */
    public function index()
    {
        $param = get_params();
        $id    = (int) ($param['id'] ?? 0);

        add_user_log('view', '留言表单页', $id, $param);

        $form = miu_cached_form($id);
        if (!$form) {
            miu_current_nav_id(0);
            return miu_front_notice(
                '表单不存在',
                '<p>找不到 id 为 <code>' . $id . '</code> 的留言表单，可能已被删除或关闭。</p>'
                . '<p>去后台「留言 → 表单管理」确认一下这个表单还在不在、状态是不是「开启」。</p>'
            );
        }

        //表单绑了栏目（后台「关联栏目」选的）就把那个栏目点亮，没绑就不点亮任何菜单。
        //要在模板用到导航菜单之前设好
        miu_current_nav_id((int) ($form['nav_id'] ?? 0));

        //留言表单现在挂在栏目页上（栏目模板 list_clue.html 里用 {miu:clue} 渲染）。
        //模板目录里要是没有 form.html 了，老地址 /form/12 就直接跳到它绑定的栏目页，
        //别在这里留一个 500
        if (!miu_template_exists('form')) {
            $nav = !empty($form['nav_id']) ? nav_by_id((int) $form['nav_id']) : [];
            if ($nav) {
                return redirect(nav_url($nav));
            }

            return miu_front_notice(
                '表单页不可用',
                '<p>当前模板目录下没有 <code>form.html</code>，而这个表单又没有关联栏目，没地方可以跳。</p>'
                . '<p>去后台「留言 → 表单管理」给它选一个「关联栏目」，或者把 <code>form.html</code> 放回模板目录。</p>'
            );
        }

        $types  = form_input_types();
        $fields = miu_cached_form_fields($id);

        $list = [];
        foreach ($fields as $f) {
            $type = (int) $f['attr_input_type'];
            $list[] = [
                'id'         => (int) $f['id'],
                'name'       => (string) $f['attr_name'],
                'type'       => $type,
                'type_name'  => $types[$type] ?? '单行文本',
                'options'    => form_field_options((string) $f['attr_values']),
                'required'   => (int) $f['required'] === 1,
                'input_name' => 'field_' . (int) $f['id'],
            ];
        }

        $formUrl = miu_form_url($id);
        miu_assign_page_meta([
            'seo_keywords'    => (string) $form['form_name'],
            'seo_description' => (string) ($form['intro'] !== '' ? $form['intro'] : $form['form_name']),
        ], $formUrl);

        return View('/form', [
            //把「表单」包成一个栏目对象，模板头部那套 {$nav.title} / {$nav.seo_title} 可以直接复用
            'nav'        => [
                'id'              => 0,
                'title'           => (string) $form['form_name'],
                'seo_title'       => (string) $form['form_name'],
                'seo_keywords'    => (string) $form['form_name'],
                'seo_description' => (string) ($form['intro'] !== '' ? $form['intro'] : $form['form_name']),
                'src'             => $formUrl,
            ],
            'form'       => $form,
            'fields'     => $list,
            'form_url'   => $formUrl,
            //提交地址：动态地址，跟后台伪静态/静态规则无关
            'submit_url' => miu_form_submit_url(),
            'page_title' => miu_page_title('nav', ['title' => (string) $form['form_name']], 1),
        ]);
    }

    /**
     * 提交留言
     *
     * 模板里用内置 JS（onsubmit="return miuClueSubmit(this)"）走 ajax → 返回 JSON；
     * 用普通 POST（没写 onsubmit）提交的话，返回一个简单的结果页，
     * 别把 JSON 直接怼到用户脸上。
     *
     * @return \think\Response
     */
    public function submit()
    {
        $res = $this->doSubmit();

        if (request()->isAjax()) {
            return json($res);
        }

        return response($this->resultHtml($res), 200)->contentType('text/html; charset=utf-8');
    }

    /**
     * 提交留言的实际逻辑（校验 + 落库）
     *
     * @return array ['code' => 0 成功 / 1 失败, 'msg' => 提示文案]
     */
    protected function doSubmit(): array
    {
		if (!request()->isPost()) {
			return ['code' => 1, 'msg' => '请使用 POST 请求'];
		}
        $param  = get_params();
        $formId = (int) ($param['form_id'] ?? 0);

        $form = miu_cached_form($formId);
        if (!$form) {
            return ['code' => 1, 'msg' => '表单不存在或已关闭'];
        }

        $fields = miu_cached_form_fields($formId);
        if (!$fields) {
            return ['code' => 1, 'msg' => '这个表单还没有配置字段，请联系管理员'];
        }

        $values = []; // attr_id => 值
        $plain  = []; // 用于算去重的 md5

        foreach ($fields as $f) {
            $attrId = (int) $f['id'];
            $label  = (string) $f['attr_name'];
            $raw    = $param['field_' . $attrId] ?? '';

            // 多选值数组使用「|」连接存储，单个值中不允许包含「|」。
            if (is_array($raw)) {
                $arr = [];
                foreach ($raw as $v) {
                    $v = str_replace('|', '', trim((string) $v));
                    if ($v !== '') {
                        $arr[] = $v;
                    }
                }
                $val = implode('|', $arr);
            } else {
                $val = str_replace('|', '', trim((string) $raw));
            }

            //长度保护：字段值表是 text，但也别让人塞一兆进来
            if (mb_strlen($val, 'UTF-8') > 1000) {
                return ['code' => 1, 'msg' => '「' . $label . '」内容太长了，最多 1000 个字'];
            }

            if ((int) $f['required'] === 1 && $val === '') {
                return ['code' => 1, 'msg' => '请填写「' . $label . '」'];
            }

            //格式校验：字段类型（手机/邮箱）或后台单独设的「验证格式」都要过
            if ($val !== '') {
                $type = (int) $f['attr_input_type'];
                $vt   = (int) $f['validate_type'];

                if ($type === 6 || $vt === 6) {
                    if (!preg_match('/^1[3-9]\d{9}$/', $val)) {
                        return ['code' => 1, 'msg' => '「' . $label . '」手机号码格式不正确'];
                    }
                }
                if ($type === 7 || $vt === 7) {
                    if (!filter_var($val, FILTER_VALIDATE_EMAIL)) {
                        return ['code' => 1, 'msg' => '「' . $label . '」邮箱格式不正确'];
                    }
                }
            }

            $values[$attrId] = $val;
            $plain[]         = $label . '=' . $val;
        }

        $md5 = md5(implode('&', $plain));
        $ip  = (string) request()->ip();

        //插件钩子：留言提交前（插件可修改字段值）
        $submitData = [
            'form_id' => $formId,
            'form'    => $form,
            'values'  => $values, // attr_id => 值
            'ip'      => $ip,
        ];
        \app\services\PluginService::formSubmit($submitData);
        $values = $submitData['values'] ?? $values;

        // 相同IP提交相同内容时，5分钟内不允许重复提交。
        try {
            $dup = Db::name('form_message')
                ->where('form_id', $formId)
                ->where('md5data', $md5)
                ->where('ip', $ip)
                ->where('add_time', '>', time() - 300)
                ->count();
            if ($dup > 0) {
                return ['code' => 1, 'msg' => '您刚刚已经提交过了，请勿重复提交'];
            }
        } catch (\Throwable $e) {
            //去重查询出错不影响提交，继续往下走
        }

		$rateKey = 'homeFormSubmit_' . hash('sha256', $ip . '|' . $formId);
		$submitCount = (int) Cache::get($rateKey, 0);
		if ($submitCount >= 10) {
			return ['code' => 1, 'msg' => '提交过于频繁，请稍后再试'];
		}
		Cache::set($rateKey, $submitCount + 1, 60);

        $now = time();
        $aid = 0;
        try {
            Db::transaction(function () use ($formId, $values, $md5, $ip, $now, $form, &$aid) {
                $aid = (int) Db::name('form_message')->insertGetId([
                    'form_id'     => $formId,
                    'users_id'    => 0,
                    'md5data'     => $md5,
                    'ip'          => $ip,
                    'is_read'     => 0,
                    'is_star'     => 0,
                    'source'      => is_mobile() ? 2 : 1,
                    'lang'        => 'cn',
                    //表单开了审核就是「未审核」，否则直接算通过
                    'examine'     => (int) ($form['open_examine'] ?? 0) === 1 ? 0 : 1,
                    'add_time'    => $now,
                    'update_time' => $now,
                ]);

                $rows = [];
                foreach ($values as $attrId => $val) {
                    $rows[] = [
                        'aid'         => $aid,
                        'attr_id'     => $attrId,
                        'attr_value'  => $val,
                        'add_time'    => $now,
                        'update_time' => $now,
                    ];
                }
                if ($rows) {
                    Db::name('form_message_attr')->insertAll($rows);
                }
            });
        } catch (\Throwable $e) {
            return ['code' => 1, 'msg' => '提交失败，请稍后再试'];
        }

        //插件钩子：留言提交后（参数：新留言ID、数据）
        \app\services\PluginService::formSubmitted((int) $aid, [
            'form_id' => $formId,
            'values'  => $values,
            'ip'      => $ip,
        ]);

        //有新留言了，后台右上角那个未读角标要立刻更新
        clear_cache('formMessage');

        return ['code' => 0, 'msg' => '提交成功，我们会尽快与您联系'];
    }

    protected function resultHtml(array $res): string
    {
        $ok  = (int) ($res['code'] ?? 1) === 0;
        $msg = htmlspecialchars((string) ($res['msg'] ?? ''), ENT_QUOTES);
        $title = $ok ? '提交成功' : '提交失败';
        $icon  = $ok ? '✓' : '✗';
        $style = 'text-align:center;padding:60px 20px;font-family:sans-serif;background:#f9f9f9;';
        $boxStyle = 'display:inline-block;padding:40px 60px;background:#fff;border-radius:8px;box-shadow:0 2px 12px rgba(0,0,0,.1);';
        $iconStyle = 'font-size:48px;margin-bottom:16px;color:' . ($ok ? '#52c41a' : '#ff4d4f') . ';';
        $msgStyle = 'font-size:16px;color:#333;margin:16px 0 24px;';
        $btnStyle = 'display:inline-block;padding:8px 32px;background:' . ($ok ? '#52c41a' : '#1890ff') . ';color:#fff;border-radius:4px;text-decoration:none;font-size:14px;';
        return <<<HTML
<!DOCTYPE html>
<html lang="zh-CN">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width,initial-scale=1">
<title>{$title}</title>
</head>
<body style="margin:0;{$style}">
  <div style="{$boxStyle}">
    <div style="{$iconStyle}">{$icon}</div>
    <p style="{$msgStyle}">{$msg}</p>
    <a href="javascript:history.back()" style="{$btnStyle}">返回上一页</a>
  </div>
</body>
</html>
HTML;
    }
}
