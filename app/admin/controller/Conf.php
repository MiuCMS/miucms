<?php
/**
 * @copyright Copyright (c) 2021-2026 米柚工作室
 * @license https://opensource.org/licenses/Apache-2.0
 * @link https://www.miucms.com
 */

declare (strict_types = 1);

namespace app\admin\controller;

use app\admin\BaseController;
use app\admin\validate\ConfCheck;
use app\home\service\ContentI18n;
use app\middleware\AdminPath;
use think\exception\ValidateException;
use think\facade\Db;
use think\facade\View;

class Conf extends BaseController
{

    //编辑配置信息
    public function index()
    {
        $param = get_params();
        if (request()->isAjax()) {
            if (!request()->isPost()) {
                return to_assign(1, '请使用 POST 请求');
            }
            //模板选择由独立的模板设置页维护，忽略旧页面或手工提交的字段。
            unset($param['template']);
            // 核心配置里的 URL 开关只接受 0/1；复选框未勾选时浏览器不会提交，按完整表单补成 0。
            if (array_key_exists('url_no_index', $param)) {
                foreach (['url_del_home', 'url_del_nav', 'url_del_side'] as $key) {
                    if (!array_key_exists($key, $param)) {
                        $param[$key] = 0;
                    }
                }
            }
            foreach (['url_no_index', 'url_del_home', 'url_del_nav', 'url_del_side', 'register_email_verify', 'user_log_open'] as $key) {
                if (!array_key_exists($key, $param)) {
                    continue;
                }
                if (!is_scalar($param[$key]) || !in_array((string) $param[$key], ['0', '1'], true)) {
                    return to_assign(1, $key . ' 配置无效');
                }
                $param[$key] = (int) $param[$key];
            }
            if (array_key_exists('password_reset_template', $param)) {
                if (!is_scalar($param['password_reset_template'])) {
                    return to_assign(1, '找回密码邮件模板格式不正确');
                }
                $param['password_reset_template'] = (string) $param['password_reset_template'];
                if (mb_strlen($param['password_reset_template'], 'UTF-8') > 100000) {
                    return to_assign(1, '找回密码邮件模板不能超过 100000 个字符');
                }
            }
            if (array_key_exists('email_verify_template', $param)) {
                if (!is_scalar($param['email_verify_template'])) {
                    return to_assign(1, '邮箱验证码模板格式不正确');
                }
                $param['email_verify_template'] = (string) $param['email_verify_template'];
                if (mb_strlen($param['email_verify_template'], 'UTF-8') > 100000) {
                    return to_assign(1, '邮箱验证码模板不能超过 100000 个字符');
                }
            }
            if (isset($param['cache_driver']) && !in_array((string) $param['cache_driver'], ['file', 'redis'], true)) {
                return to_assign(1, '缓存方式配置无效');
            }
            if (isset($param['storage_type'])) {
                if (!is_scalar($param['storage_type']) || !in_array((string) $param['storage_type'], ['local', 'oss', 'cos'], true)) {
                    return to_assign(1, '附件存储类型配置无效');
                }
                $param['storage_type'] = (string) $param['storage_type'];
            }
            if (($param['cache_driver'] ?? '') === 'redis') {
                try {
                    //切换前确认 Redis 可用，避免保存后所有缓存读写都报错。
                    \think\facade\Cache::store('redis')->has('__miucms_cache_driver_check__');
                } catch (\Throwable $e) {
                    return to_assign(1, 'Redis 缓存不可用，请检查 Redis 服务及 PHP Redis/Predis 扩展后重试');
                }
            }
            if (isset($param['editor']) && !in_array((string) $param['editor'], ['1', '2'], true)) {
                return to_assign(1, '编辑器配置无效');
            }
            //校验后台管理路径，非法时直接拦下，不写入
            $check = $this->checkAdminPath($param);
            if ($check !== true) {
                return to_assign(1, $check);
            }
            //校验站点状态相关的参数
            $check = $this->checkSiteStatus($param);
            if ($check !== true) {
                return to_assign(1, $check);
            }
            if( (isset($param['is_random_click']) && $param['is_random_click']==1) ){
                if($param['random_click_scope_start']<1 || $param['random_click_scope_end']<1){
                    return to_assign(1,'随机数范围输入错误');
                }
                if($param['random_click_scope_end'] <= $param['random_click_scope_start']){
                    return to_assign(1,'随机数范围的起始数值要小于结束数值');
                }
            }
            if( isset($param['session_expire']) && $param['session_expire'] < 60){
                return to_assign(1,'登录超时时间不能小于60秒');
            }
            //php.ini 里的上传上限（'2M' 这种写法要换算成字节再比）。
            //注意别用 ini_parse_quantity()：那是 PHP 8.2 才有的函数，8.0/8.1 下会报
            //「Call to undefined function」，统一用 ini_size_to_bytes()（app/common.php）
            $ini_upload_max_file_size = (string) ini_get('upload_max_filesize');
            $ini_upload_max_mb        = ini_size_to_bytes($ini_upload_max_file_size) / 1024 / 1024;
            //换算出来是 0（php.ini 里写的 0 或 -1，表示不限制）就不拦
            if ($ini_upload_max_mb > 0
                && isset($param['upload_max_filesize'])
                && $param['upload_max_filesize'] > $ini_upload_max_mb) {
                return to_assign(1, 'php.ini中的上传文件大小限制数值是' . $ini_upload_max_file_size . '，如有需要，请先调整php.ini的upload_max_filesize数值');
            }
            $conf = Db::name('Config')->where('name', 'global')->find();
            if (!$conf) {
                return to_assign(1, '配置数据不存在：cms_config 表里缺少 name=global 的记录');
            }
            //id 不再存进 content（跟行 id 重复）
            unset($param['id']);
            //合并而不是整份覆盖：这个页面只提交自己那些字段，
            //「SEO → URL配置」页保存的 url_* 项、以及以后别的页面加的项都不能被冲掉
            $old = [];
            if (!empty($conf['content'])) {
                $old = @unserialize($conf['content'], ['allowed_classes' => false]);
            }
            if (!is_array($old)) {
                $old = [];
            }
            foreach ([
                'oss_region' => 64, 'oss_bucket' => 63, 'oss_access_key_id' => 255, 'oss_endpoint' => 255, 'oss_domain' => 255,
                'cos_region' => 64, 'cos_bucket' => 50, 'cos_secret_id' => 255, 'cos_domain' => 255,
            ] as $key => $maxLength) {
                if (!array_key_exists($key, $param)) {
                    continue;
                }
                if (!is_scalar($param[$key])) {
                    return to_assign(1, $key . ' 配置格式无效');
                }
                $param[$key] = trim((string) $param[$key]);
                if (strlen($param[$key]) > $maxLength || preg_match('/[\x00-\x1F\x7F]/', $param[$key])) {
                    return to_assign(1, $key . ' 配置长度或格式无效');
                }
            }
            if (array_key_exists('oss_access_key_secret', $param)) {
                if (!is_scalar($param['oss_access_key_secret'])) {
                    return to_assign(1, 'AccessKey Secret 格式无效');
                }
                $postedSecret = trim((string) $param['oss_access_key_secret']);
                if (strlen($postedSecret) > 255 || preg_match('/[\x00-\x1F\x7F]/', $postedSecret)) {
                    return to_assign(1, 'AccessKey Secret 格式无效');
                } else {
                    $param['oss_access_key_secret'] = $postedSecret;
                }
            }
            if (array_key_exists('cos_secret_key', $param)) {
                if (!is_scalar($param['cos_secret_key'])) {
                    return to_assign(1, 'SecretKey 格式无效');
                }
                $postedSecret = trim((string) $param['cos_secret_key']);
                if (strlen($postedSecret) > 255 || preg_match('/[\x00-\x1F\x7F]/', $postedSecret)) {
                    return to_assign(1, 'SecretKey 格式无效');
                } else {
                    $param['cos_secret_key'] = $postedSecret;
                }
            }
            foreach (['oss' => 'aliyun-oss://', 'cos' => 'tencent-cos://'] as $provider => $prefix) {
                foreach (['bucket', 'region'] as $part) {
                    $key = $provider . '_' . $part;
                    if (array_key_exists($key, $param) && (string) ($old[$key] ?? '') !== '' && (string) $param[$key] !== (string) $old[$key]
                        && Db::name('File')->where('filename', 'like', $prefix . '%')->find()) {
                        return to_assign(1, '已有云存储附件，请先迁移并处理原文件记录后再更换存储桶或地域');
                    }
                }
            }
            $globalConfig = array_merge($old, $param);
            $storageType = (string) ($globalConfig['storage_type'] ?? 'local');
            if (!in_array($storageType, ['local', 'oss', 'cos'], true)) {
                return to_assign(1, '附件存储类型配置无效');
            }
            if ($storageType === 'oss') {
                $ossRegion = trim((string) ($globalConfig['oss_region'] ?? ''));
                $ossBucket = trim((string) ($globalConfig['oss_bucket'] ?? ''));
                $ossAccessKeyId = trim((string) ($globalConfig['oss_access_key_id'] ?? ''));
                $ossSecret = trim((string) ($globalConfig['oss_access_key_secret'] ?? ''));
                if (!preg_match('/^[a-z0-9-]{2,64}$/', $ossRegion)) {
                    return to_assign(1, '请填写正确的 OSS 地域，例如 cn-hangzhou');
                }
                if (!preg_match('/^[a-z0-9][a-z0-9-]{1,61}[a-z0-9]$/', $ossBucket)) {
                    return to_assign(1, '请填写 3 至 63 位小写字母、数字或连字符组成的 Bucket 名称');
                }
                $endpoint = trim((string) ($globalConfig['oss_endpoint'] ?? ''));
                if ($endpoint !== '') {
                    $endpointParts = parse_url(str_contains($endpoint, '://') ? $endpoint : 'https://' . $endpoint);
                    if (!is_array($endpointParts) || empty($endpointParts['host']) || isset($endpointParts['user']) || isset($endpointParts['pass'])
                        || (isset($endpointParts['path']) && $endpointParts['path'] !== '' && $endpointParts['path'] !== '/')
                        || isset($endpointParts['query']) || isset($endpointParts['fragment'])) {
                        return to_assign(1, 'OSS Endpoint 请填写域名，不要包含路径或参数');
                    }
                }
                $domain = trim((string) ($globalConfig['oss_domain'] ?? ''));
                if ($domain !== '') {
                    $domainParts = parse_url($domain);
                    if (!filter_var($domain, FILTER_VALIDATE_URL) || !is_array($domainParts)
                        || !in_array(strtolower((string) ($domainParts['scheme'] ?? '')), ['http', 'https'], true)
                        || empty($domainParts['host']) || isset($domainParts['user']) || isset($domainParts['pass'])
                        || isset($domainParts['query']) || isset($domainParts['fragment'])) {
                        return to_assign(1, 'OSS 访问域名请填写有效的 http 或 https URL');
                    }
                }
                if (!\app\services\storage\AliyunOss::isSdkAvailable()) {
                    return to_assign(1, '阿里云 OSS SDK 尚未安装，请先在项目根目录运行 composer install');
                }
            }
            if ($storageType === 'cos') {
                $cosRegion = trim((string) ($globalConfig['cos_region'] ?? ''));
                $cosBucket = trim((string) ($globalConfig['cos_bucket'] ?? ''));
                $cosSecretId = trim((string) ($globalConfig['cos_secret_id'] ?? ''));
                $cosSecretKey = trim((string) ($globalConfig['cos_secret_key'] ?? ''));
                if (!preg_match('/^[a-z0-9-]{2,64}$/', $cosRegion)) {
                    return to_assign(1, '请填写正确的 COS 地域，例如 ap-beijing');
                }
                if (!preg_match('/^[a-z0-9](?:[a-z0-9-]{0,37}[a-z0-9])?-[0-9]{10}$/', $cosBucket)) {
                    return to_assign(1, '请填写包含 APPID 后缀的 COS 存储桶名称，例如 miucms-1250000000');
                }
                $domain = trim((string) ($globalConfig['cos_domain'] ?? ''));
                if ($domain !== '') {
                    $domainParts = parse_url($domain);
                    if (!filter_var($domain, FILTER_VALIDATE_URL) || !is_array($domainParts)
                        || !in_array(strtolower((string) ($domainParts['scheme'] ?? '')), ['http', 'https'], true)
                        || empty($domainParts['host']) || isset($domainParts['user']) || isset($domainParts['pass'])
                        || isset($domainParts['query']) || isset($domainParts['fragment'])) {
                        return to_assign(1, 'COS 访问域名请填写有效的 http 或 https URL');
                    }
                }
                if (!\app\services\storage\TencentCos::isSdkAvailable()) {
                    return to_assign(1, '腾讯云 COS SDK 尚未安装，请先在项目根目录运行 composer install');
                }
            }
            // 云存储密钥只写入配置，不进入操作日志。
            unset($param['oss_access_key_secret'], $param['cos_secret_key']);
            if (array_key_exists('email_verify_template', $param)) {
                // 同步旧配置键，兼容仍读取注册验证码模板的扩展。
                $globalConfig['register_email_template'] = $param['email_verify_template'];
            }
            try {
                //自动补齐网站名称和首页 SEO 字段的空白译文；API 调用在事务外完成。
                $translations = \app\services\PluginService::autoTranslateSiteConfig($globalConfig);
                if (ContentI18n::enabledLocales()) {
                    ContentI18n::ensureSchema();
                }
            } catch (\Throwable $e) {
                return to_assign(1, '自动翻译或翻译表准备失败，网站配置未保存：' . $e->getMessage());
            }

            $data['content'] = serialize($globalConfig);
            $data['update_time'] = time();
            $data['id'] = $conf['id'];
            try {
                $res = Db::transaction(function () use ($data, $translations, $globalConfig) {
                    $saved = Db::name('Config')->strict(false)->field(true)->update($data);
                    if ($saved === false) {
                        throw new \RuntimeException('网站配置写入失败');
                    }
                    ContentI18n::savePosted('site_config', 1, $translations, '', 0, $globalConfig);
                    return $saved;
                });
            } catch (\Throwable $e) {
                return to_assign(1, '网站配置保存失败：' . $e->getMessage());
            }
            $cacheDriver = in_array(($globalConfig['cache_driver'] ?? 'file'), ['file', 'redis'], true)
                ? (string) ($globalConfig['cache_driver'] ?? 'file')
                : 'file';
            \app\event\CacheDriver::persistDriver($cacheDriver);
            try {
                //切换驱动后清理目标缓存池里的旧网站配置，避免读到上次使用该驱动时留下的数据。
                \think\facade\Cache::store($cacheDriver)->tag('system_config_global')->clear();
                \think\facade\Cache::store($cacheDriver)->tag('system_config_template')->clear();
            } catch (\Throwable $e) {
                //缓存不可用时，网站配置仍可在后续请求中回源数据库。
            }
            clear_cache('system_config_global');
            if ($res) {
                safe_add_log('edit', $conf['id'], $param);
            }
            return to_assign();
        } else {
            //配置只有一行，GET 不再依赖 id
            $conf = Db::name('Config')->where('name', 'global')->find();
            $config = [];
            if (!empty($conf['content'])) {
                $config = @unserialize($conf['content'], ['allowed_classes' => false]);
            }
            if (!is_array($config)) {
                $config = [];
            }
            if (!is_string($config['password_reset_template'] ?? null) || trim($config['password_reset_template']) === '') {
                $config['password_reset_template'] = default_password_reset_email_template();
            }
            $emailVerifyTemplate = $config['email_verify_template'] ?? $config['register_email_template'] ?? '';
            if (!is_string($emailVerifyTemplate) || trim($emailVerifyTemplate) === '') {
                $emailVerifyTemplate = default_email_verify_template();
            }
            $config['email_verify_template'] = $emailVerifyTemplate;
            // 新设置保存在 global；global 尚未保存时，回显 SEO 配置组里的旧值。
            $legacySeoConfig = get_config_group('seo');
            foreach ([
                'url_no_index' => 1,
                'url_del_home' => 1,
                'url_del_nav'  => 1,
                'url_del_side' => 1,
            ] as $key => $default) {
                if (!array_key_exists($key, $config) && array_key_exists($key, $legacySeoConfig)) {
                    $config[$key] = $legacySeoConfig[$key];
                }
                $value = (string) ($config[$key] ?? $default);
                $config[$key] = in_array($value, ['0', '1'], true) ? (int) $value : $default;
            }
            //兼容缓存选项此前临时保存在 SEO 配置组的情况，保存网站配置后即以 global 为准。
            if (!isset($config['cache_driver'])) {
                $seoContent = Db::name('Config')->where('name', 'seo')->value('content');
                if (is_string($seoContent) && $seoContent !== '') {
                    $seoConfig = @unserialize($seoContent, ['allowed_classes' => false]);
                    if (is_array($seoConfig) && isset($seoConfig['cache_driver'])) {
                        $config['cache_driver'] = $seoConfig['cache_driver'];
                    }
                }
            }
            $config['cache_driver'] = in_array(($config['cache_driver'] ?? 'file'), ['file', 'redis'], true)
                ? (string) ($config['cache_driver'] ?? 'file')
                : 'file';
            $config['register_email_verify'] = (int) (($config['register_email_verify'] ?? 0) == 1);
            $config['user_log_open'] = (int) (($config['user_log_open'] ?? 0) == 1);
            $editor = (string) ($config['editor'] ?? '1');
            $config['editor'] = in_array($editor, ['1', '2'], true) ? $editor : '1';
            //白名单在 textarea 上下文中输出，先做 HTML 转义以免配置文本被当作标记解析。
            $config['editor_img_clear_link_whitelist'] = htmlspecialchars(
                (string) ($config['editor_img_clear_link_whitelist'] ?? ''),
                ENT_QUOTES | ENT_SUBSTITUTE,
                'UTF-8'
            );
            View::assign('id', $conf['id'] ?? 0);
            View::assign('config', $config);
            return view();
        }
    }

    /**
     * 校验后台管理路径（admin_path）
     * 规则与 app\middleware\AdminPath 共用，避免两处不一致
     *
     * @param array $param 表单提交的参数
     * @return true|string true 表示通过；字符串为错误提示
     */
    protected function checkAdminPath(array $param)
    {
        //该分组里没有这个字段就不校验
        if (!array_key_exists('admin_path', $param)) {
            return true;
        }

        $check = AdminPath::validate((string) $param['admin_path']);

        return $check === true ? true : '后台管理路径' . $check;
    }

    /**
     * 校验站点状态（site_status）相关的参数
     * 规则与 app\home\middleware\SiteStatus 保持一致，避免两边判断不一致
     *
     * @param array $param 表单提交的参数
     * @return true|string true 表示通过；字符串为错误提示
     */
    protected function checkSiteStatus(array $param)
    {
        //该分组里没有这个字段就不校验
        if (!array_key_exists('site_status', $param)) {
            return true;
        }

        //网站是开启的，后面这些参数都不用管
        if ((int) $param['site_status'] !== 0) {
            return true;
        }

        $mode = (int) ($param['site_status_mode'] ?? 0);

        //跳转链接：必须是合法网址
        if ($mode === 1 && !is_valid_url((string) ($param['site_status_url'] ?? ''))) {
            return '关闭方式选择“跳转链接”时，请填写以 http:// 或 https:// 开头的网址';
        }

        //模板页面：只允许 public/ 下的相对路径，挡掉 ../ 之类的越权读取
        if ($mode === 2) {
            $tpl = str_replace('\\', '/', trim((string) ($param['site_status_tpl'] ?? '')));
            if (!str_starts_with($tpl, 'public/') || str_contains($tpl, '..')) {
                return '关闭方式选择“模板页面”时，页面路径要放在 public/ 目录下，例如 public/tpl/close.html';
            }
        }

        return true;
    }

}
