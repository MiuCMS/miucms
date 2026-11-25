<?php
/**
 * @copyright Copyright (c) 2026 米柚工作室
 * @license https://opensource.org/licenses/Apache-2.0
 * @link https://www.miucms.com
 */

declare (strict_types = 1);

namespace app\admin\controller;

use app\admin\BaseController;
use app\extend\plugin\PluginManager;
use app\services\DashboardQuickNav;
use app\services\UpgradeManager;
use think\facade\Cache;
use think\facade\Db;
use think\facade\Session;
use think\facade\View;

class Index extends BaseController
{
    public function index()
    {
		$admin = get_login_admin();
		if (get_cache('menu' . $admin['id'])) {
			$list = get_cache('menu' . $admin['id']);
		} else {
			$adminGroup = Db::name('AdminGroupAccess')->where(['uid' => get_login_admin('id')])->column('group_id');
			$adminMenu = Db::name('AdminGroup')->where('id', 'in', $adminGroup)->column('rules');
			$adminMenus = [];
			foreach ($adminMenu as $k => $v) {
				$v = explode(',', $v);
				$adminMenus = array_merge($adminMenus, $v);
			}
			$menu = Db::name('AdminRule')->where(['menu' => 1,'status'=>1])->where('id', 'in', $adminMenus)->order('sort asc,id asc')->select()->toArray();
			$list = list_to_tree($menu);
            //dump($list);die;
			\think\facade\Cache::tag('adminMenu')->set('menu' . $admin['id'], $list);
		}
		$theme = Db::name('Admin')->where('id',$admin['id'])->value('theme');
		View::assign('theme',$theme);
        //菜单 src 转成完整 URL：缓存里保留数据库原始 src，这里每次动态生成，
        //这样改了 app_map（后台入口改名）后不用清缓存就能生效
        View::assign('menu', $this->buildMenuUrl($list));
        //右上角「消息中心」的未读留言数（红点角标，0 就不显示）
        View::assign('formUnreadCount', form_unread_count());
        View::assign('upgradeCsrfToken', $this->isUpgradeAdmin() ? $this->upgradeCsrfToken() : '');
        return View();
    }

    /** 升级管理页仅允许超级管理员（管理员 ID=1）访问。 */
    public function upgrade()
    {
        if (!$this->isUpgradeAdmin()) return response('仅超级管理员可以管理系统升级。', 403);
        View::assign('upgradeStatus', (new UpgradeManager())->check());
        View::assign('upgradeCsrfToken', $this->upgradeCsrfToken());
        return View();
    }

    /** 后台首页每次打开时异步检查最新版本。 */
    public function upgrade_check()
    {
        if (!$this->isUpgradeAdmin()) return json(['code' => 403, 'msg' => '仅超级管理员可以检查系统升级']);
        if (!request()->isPost() || !$this->validUpgradeCsrf()) return json(['code' => 403, 'msg' => '请求无效，请刷新页面后重试']);
        return json(['code' => 0, 'msg' => '检查完成', 'data' => (new UpgradeManager())->check()]);
    }

    /** 应用一个 from_version 与当前版本相符的增量包。 */
    public function upgrade_apply()
    {
        if (!$this->isUpgradeAdmin()) return json(['code' => 403, 'msg' => '仅超级管理员可以执行系统升级']);
        if (!request()->isPost() || !$this->validUpgradeCsrf()) return json(['code' => 403, 'msg' => '请求无效，请刷新页面后重试']);
        $result = (new UpgradeManager())->applyNext();
        if (isset($result['error'])) return json(['code' => 1, 'msg' => $result['error']]);
        return json(['code' => 0, 'msg' => '升级完成', 'data' => $result]);
    }

    private function isUpgradeAdmin(): bool
    {
        return (int) get_login_admin('id') === 1;
    }

    private function upgradeCsrfToken(): string
    {
        $token = Session::get('miu_upgrade_csrf');
        if (!is_string($token) || !preg_match('/\A[a-f0-9]{64}\z/', $token)) {
            $token = bin2hex(random_bytes(32));
            Session::set('miu_upgrade_csrf', $token);
        }
        return $token;
    }

    private function validUpgradeCsrf(): bool
    {
        $known = Session::get('miu_upgrade_csrf');
        $given = request()->post('token');
        return is_string($known) && is_string($given) && $known !== '' && hash_equals($known, $given);
    }

    /**
     * 递归把菜单的 src 转成完整 URL
     * 依赖 url() 的 app_map 反向映射，后台入口改名后会自动跟着变
     */
    protected function buildMenuUrl(array $list): array
    {
        foreach ($list as &$item) {
            //src 为空表示该菜单只是分组，没有自己的落地页，保持空字符串。
            //注意：url() 返回的是 Url 对象（靠 __toString 才变字符串），必须强制转成字符串，
            //否则 json_encode 后会变成 {}，前端拿到的是 [object Object]
            $item['src'] = !empty($item['src']) ? (string) url($item['src']) : '';
            if (!empty($item['list'])) {
                $item['list'] = $this->buildMenuUrl($item['list']);
            }
        }
        unset($item);
        return $list;
    }

    public function main()
    {
        View::assign('upgradeCsrfToken', $this->isUpgradeAdmin() ? $this->upgradeCsrfToken() : '');
        $adminId = (int) get_login_admin('id');
        $quickNavOptions = DashboardQuickNav::options($adminId);
        $quickNavSelected = array_column(DashboardQuickNav::selected($adminId, $quickNavOptions), null, 'key');
        $quickNavItems = [];
        foreach ($quickNavSelected + $quickNavOptions as $key => $item) {
            $quickNavItems[] = $item + ['selected' => isset($quickNavSelected[$key])];
        }
        View::assign([
            'quickNavItems' => $quickNavItems,
            'quickNavMin' => DashboardQuickNav::MIN_ITEMS,
            'quickNavMax' => DashboardQuickNav::MAX_ITEMS,
        ]);
        $install = false;
        if (file_exists(CMS_ROOT . 'app/install')) {
            $install = true;
        }
        View::assign('install', $install);

        $adminCount = Db::name('Admin')->where('status', '1')->count();
        $userCount = Db::name('User')->where('status', '1')->count();
        $docCount = Db::name('document')->count();
        $fileCount = Db::name('File')->count();
        $tagsCount = Db::name('keywords')->count();
        $messageCount = Db::name('form_message')->count();

        View::assign('adminCount', $adminCount);
        View::assign('userCount', $userCount);
        View::assign('docCount', $docCount);
        View::assign('fileCount', $fileCount);
        View::assign('tagsCount', $tagsCount);
        View::assign('messageCount', $messageCount);
        View::assign('TP_VERSION',\think\facade\App::version());
        View::assign('MYSQL_VERSION', Db::query('SELECT VERSION() AS version')[0]['version'] ?? '');
        $dashboardWidgets = '';
        $dashboardShortcuts = '';
        \app\extend\plugin\Hook::instance()->trigger('admin_dashboard', $dashboardWidgets, $dashboardShortcuts);
        $plugins = PluginManager::instance();
        View::assign('showAccessPlaceholder', !in_array('access', $plugins->getEnabledPluginNames(), true)
            || !is_file($plugins->getPluginPath() . 'access/Plugin.php'));
        View::assign('dashboardWidgets', $dashboardWidgets);
        View::assign('dashboardShortcuts', $dashboardShortcuts);
        return View();
    }

    public function save_quick_nav()
    {
        $this->requireRequestMethod();
        if (!valid_admin_csrf()) return to_assign(1, '请求无效，请刷新页面后重试');

        $raw = request()->post('items');
        $items = is_string($raw) && strlen($raw) <= 4096 ? json_decode($raw) : null;
        if (!is_array($items)) return to_assign(1, '快捷入口格式不正确');
        if (count($items) < DashboardQuickNav::MIN_ITEMS) {
            return to_assign(1, '请至少选择 ' . DashboardQuickNav::MIN_ITEMS . ' 个快捷入口');
        }
        if (count($items) > DashboardQuickNav::MAX_ITEMS) {
            return to_assign(1, '最多选择 ' . DashboardQuickNav::MAX_ITEMS . ' 个快捷入口');
        }

        $adminId = (int) get_login_admin('id');
        $options = DashboardQuickNav::options($adminId);
        $selected = [];
        foreach ($items as $key) {
            if (!is_string($key) || !isset($options[$key]) || isset($selected[$key])) {
                return to_assign(1, '部分快捷入口不可用，请刷新页面后重新选择');
            }
            $selected[$key] = $options[$key];
        }

        try {
            Db::name('quick_nav')->replace()->insert([
                'admin_id' => $adminId,
                'items' => json_encode(array_keys($selected), JSON_THROW_ON_ERROR),
            ]);
        } catch (\think\db\exception\PDOException $e) {
            if (($e->getData()['PDO Error Info']['SQLSTATE'] ?? '') !== '42S02') throw $e;
            return to_assign(1, '快捷导航数据表尚未创建，请先执行升级 SQL');
        }
        return to_assign(0, '快捷导航已保存');
    }

    public function official_news()
    {
        $this->requireRequestMethod('GET');
        $feed = (string) config('official.news_feed');
        $cacheKey = 'miucms_official_news_' . md5($feed);
        if (!Cache::get($cacheKey . '_checked_v2')) {
            $items = $this->fetchOfficialNews($feed);
            if ($items !== null) {
                Cache::set($cacheKey, $items);
            }
            // 官网异常时保留上次结果，并避免每次打开首页都重试。
            Cache::set($cacheKey . '_checked_v2', true, $items === null ? 60 : 1800);
        }

        $items = Cache::get($cacheKey);
        return json(['code' => is_array($items) ? 0 : 1, 'data' => $items ?? []]);
    }

    private function fetchOfficialNews(string $feed): ?array
    {
        if (!str_starts_with($feed, 'https://') || !filter_var(ini_get('allow_url_fopen'), FILTER_VALIDATE_BOOLEAN)) {
            return null;
        }

        $context = stream_context_create([
            'http' => ['timeout' => 3, 'follow_location' => 0, 'header' => "Accept: application/json\r\n"],
            'ssl' => ['verify_peer' => true, 'verify_peer_name' => true],
        ]);
        $body = @file_get_contents($feed, false, $context, 0, 16385);
        $headers = function_exists('http_get_last_response_headers') ? http_get_last_response_headers() : ($http_response_header ?? []);
        if (!is_string($body) || strlen($body) > 16384 || !preg_match('/\s200(?:\s|$)/', $headers[0] ?? '')) {
            return null;
        }

        $payload = json_decode($body, true);
        if (($payload['code'] ?? null) !== 0 || !is_array($payload['data'] ?? null)) {
            return null;
        }

        $hosts = [parse_url($feed, PHP_URL_HOST), parse_url((string) config('official.website'), PHP_URL_HOST)];
        $items = [];
        foreach (array_slice($payload['data'], 0, 5) as $row) {
            $title = $row['title'] ?? null;
            $url = $row['url'] ?? null;
            if (!is_string($title) || trim($title) === '' || !is_string($url) || !filter_var($url, FILTER_VALIDATE_URL)) {
                return null;
            }
            $parts = parse_url($url);
            if (($parts['scheme'] ?? '') !== 'https' || !in_array($parts['host'] ?? '', $hosts, true)
                || isset($parts['user']) || isset($parts['pass'])) {
                return null;
            }
            $color = is_string($row['title_color'] ?? null) ? trim($row['title_color']) : '';
            $items[] = [
                'title' => trim($title),
                'url' => $url,
                'title_color' => preg_match('/\A#(?:[0-9a-f]{3}|[0-9a-f]{6})\z/i', $color) ? $color : '',
                'is_b' => in_array($row['is_b'] ?? 0, [1, '1', true], true),
            ];
        }

        return $items;
    }
	
	//设置theme
	public function set_theme()
    {
        $this->requireRequestMethod();
        if (request()->isAjax()) {
            $param = get_params();
			$admin = get_login_admin();
			Db::name('Admin')->where('id',$admin['id'])->update(['theme'=>$param['theme']]);
            return to_assign();
        }
		else{
			return to_assign(1,'操作错误');
		}
    }
}
