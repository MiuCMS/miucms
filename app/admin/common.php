<?php
/**
 * @copyright Copyright (c) 2026 米柚工作室
 * @license https://opensource.org/licenses/Apache-2.0
 * @link https://www.miucms.com
 */
// 应用公共文件,内置主要的数据处理方法
use think\facade\Config;
use think\facade\Request;
use think\facade\Cache;
use think\facade\Db;
use Overtrue\Pinyin\Pinyin;
use app\services\StorageService;
use app\services\UploadPolicy;
/**
 * 汉字生成拼音
 * @param string $text
 * @param string $mode string | pinyin:拼音，pinyin_url：友好url，pinyin_first：首字母
 * @param string $end bool | 添加随机数字后缀
 * @return string
 */
function pinyin(string $text,$end=false, string $mode = 'pinyin'): string {
    $pinyin = new Pinyin();
    switch ($mode) {
        case 'pinyin':$return=$pinyin->sentence($text);break;
        case 'pinyin_url':$return=$pinyin->permalink($text);break;
        case 'pinyin_first':$return= $pinyin->abbr($text);break;
        default :$return=$pinyin->sentence($text);
    }
    $return=mb_strtolower(clean_all_char($return),'utf-8');
    if($end==true){
        $return=$return.mt_rand(1000,9999);
    }
    return $return;
}
/**
 * 清理字符串：只保留中文、英文、数字，删除全部空格和特殊符号
 * @param string $str
 * @return string
 */
function clean_all_char(string $str): string
{
    if (empty($str)) return '';
    // 先清除空白字符，再过滤符号
    $str = preg_replace('/\s+/u', '', $str);
    return preg_replace('/[^\x4e00-\x9fa5a-zA-Z0-9]/u', '', $str);
}
//获取后台模块当前登录用户的信息
function get_login_admin($key = "",$default="/static/admin/images/default_avatar.png")
{
    $session_admin = get_config('app.session_admin');
    if (\think\facade\Session::has($session_admin)) {
        $miu_admin = \think\facade\Session::get($session_admin);
        if (!empty($key)) {
            if (isset($miu_admin[$key])) {
                return $miu_admin[$key];
            } else {
                return '';
            }
        } else {
            return $miu_admin;
        }
    } else {
        return $default;
    }
}
/**
 * 截取摘要
 *  @return bool
 */
function getDescriptionFromContent($content, $count=100)
{
    $content = preg_replace("@<script(.*?)</script>@is", "", $content);
    $content = preg_replace("@<iframe(.*?)</iframe>@is", "", $content);
    $content = preg_replace("@<style(.*?)</style>@is", "", $content);
    $content = preg_replace("@<(.*?)>@is", "", $content);
    $content = str_replace(PHP_EOL, '', $content);
    $space = array(" ", "　", "  ", " ", " ");
    $go_away = array("", "", "", "", "");
    $content = str_replace($space, $go_away, $content);
    $res = mb_substr($content, 0, $count, 'UTF-8');
    if (mb_strlen($content, 'UTF-8') > $count) {
        $res = $res . "...";
    }
    return $res;
}
/**
 * PHP格式化字节大小
 * @param number $size      字节数
 * @param string $delimiter 数字和单位分隔符
 * @return string            格式化后的带单位的大小
 */
function format_bytes($size, $delimiter = '')
{
    $units = array('B', 'KB', 'MB', 'GB', 'TB', 'PB');
    for ($i = 0; $size >= 1024 && $i < 5; $i++) {
        $size /= 1024;
    }

    return round($size, 2) . $delimiter . $units[$i];
}

function create_tree_list($pid, $arr, $group, &$tree = [])
{
    foreach ($arr as $key => $vo) {
        if ($key == 0) {
            $vo['spread'] = true;
        }
        if (!empty($group) and in_array($vo['id'], $group)) {
            $vo['checked'] = true;
        } else {
            $vo['checked'] = false;
        }
        if ($vo['pid'] == $pid) {
            $child = create_tree_list($vo['id'], $arr, $group);
            if ($child) {
                $vo['children'] = $child;
            }
            $tree[] = $vo;
        }
    }
    return $tree;
}

//递归排序，用于分类选择
function set_recursion($result, $pid = 0, $level=-1)
{
    /*记录排序后的类别数组*/
    static $list = array();
    static $space = ['','├─','　├─','　　├─','　　　├─'];
	$level++;
    foreach ($result as $k => $v) {
        if ($v['pid'] == $pid) {
            if ($pid != 0) {
                if(isset($v['title'])) $v['title'] = $space[$level] . $v['title'];
                if(isset($v['name'])) $v['name'] = $space[$level] . $v['name'];
            }
            /*将该类别的数据放入list中*/
            $list[] = $v;
            set_recursion($result, $v['id'],$level);
        }
    }
    return $list;
}

/**
 * 根据id递归返回子数据
 * @param  $data 数据
 * @param  $pid 父节点id
 */
function get_data_node($data=[],$pid=0){
	$dep = [];		
	foreach($data as $k => $v){			
		if($v['pid'] == $pid){
			$node=get_data_node($data, $v['id']);
			array_push($dep,$v);
			if(!empty($node)){					
				$dep=array_merge($dep,$node);
			}
		}   	
	}
	return array_values($dep);
}

function generateTree($flatArray, $parentId = 0) {
  $tree = [];
  foreach ($flatArray as $item) {
    if ($item['pid'] === $parentId) {
      $node = $item;
	  $node['children'] = generateTree($flatArray, $item['id']);
      $tree[] = $node;
    }
  }
  return $tree;
}

//获取指定管理员的信息
function get_admin($id)
{
    $admin = Db::name('Admin')->where(['id' => $id])->find();
    $admin['group_id'] = Db::name('AdminGroupAccess')->where(['uid' => $id])->column('group_id');
    return $admin;
}

//读取权限节点列表
function get_admin_rule()
{
    $rule = Db::name('AdminRule')->where(['status'=>1])->order('sort asc,id asc')->select()->toArray();
    return $rule;
}

//读取模块列表
function get_admin_module()
{
    $group = Db::name('AdminModule')->order('id asc')->select()->toArray();
    return $group;
}

//读取权限分组列表
function get_admin_group()
{
    $group = Db::name('AdminGroup')->order('create_time asc')->select()->toArray();
    return $group;
}

//读取指定权限分组详情
function get_admin_group_info($id)
{
    $rule = Db::name('AdminGroup')->where(['id' => $id])->value('rules');
	$rules = explode(',', $rule);
    return $rules;
}

//读取部门列表
function get_department()
{
    $department = Db::name('Department')->where(['status' => 1])->select()->toArray();
    return $department;
}

//获取某部门的子部门id.$is_self时候包含自己
function get_department_son($did = 0, $is_self = 1)
{
    $department = get_department();
    $department_list = get_data_node($department, $did);
    $department_array = array_column($department_list, 'id');
    if ($is_self == 1) {
        //包括自己在内
        $department_array[] = $did;
    }
    return $department_array;
}

//读取员工所在部门的负责人
function get_department_leader($uid=0,$pid=0)
{
	$did = get_admin($uid)['did'];
	if($pid==0){
		$leader = Db::name('Department')->where(['id' => $did])->value('leader_id');
	}
	else{
		$pdid = Db::name('Department')->where(['id' => $did])->value('pid');
		if($pdid == 0){
			$leader = 0;
		}
		else{
			$leader = Db::name('Department')->where(['id' => $pdid])->value('leader_id');
		}		
	}    
    return $leader;
}

//读取职位
function get_position()
{
    $position = Db::name('Position')->where(['status' => 1])->select()->toArray();
    return $position;
}

/**
 * 回收站开关是否开启
 * 开启后删除文档/栏目走逻辑删除（is_del=1 + delete_time），关闭则直接物理删除
 * @return bool
 */
function is_recycle_on()
{
    //注意：get_system_config 按 key 取时，键不存在会抛 Undefined array key，
    //所以取整组再判断，配置项还没保存过也不会报错
    $other = get_system_config() ?: [];
    return !empty($other['is_recycle']);
}

//读取导航列表,用于后台
//$with_channel = true 时连带栏目所用模型的 nid（判断单页 nid=single 等要用）
function get_navs($channel_id=0, $with_channel=false)
{
    //回收站里的栏目不参与后台的树、下拉等展示
    //统一带表别名 n：下面 join channel 之后 status/is_del 两张表都有，不带别名 SQL 会报 ambiguous
    $where[] = ['n.status', '=', 1];
    $where[] = ['n.is_del', '=', 0];
    if(!empty($channel_id) && $channel_id > 0){
        $where[] = ['n.channel_id', '=', $channel_id];
    }

    $query = Db::name('Nav')->alias('n');
    if ($with_channel) {
        $query = $query->join('channel c', 'n.channel_id=c.id')->leftJoin('form f','n.id=f.nav_id')->field('n.*,c.nid,f.id form_id');
    }
    return $query->where($where)->order('n.sort asc,n.id asc')->select();
}

//读取导航列表,用于后台内容页发布文档弹窗 选择栏目
//$with_channel = true 时连带栏目所用模型的 nid（判断单页 nid=single,nid=clue 等要用），
//并排除单页(single)、留言(clue)模型的栏目 —— 这两类模型不该出现在「选内容模型」的栏目列表里
function get_navs_without($channel_id=0, $with_channel=false)
{
    //回收站里的栏目不参与后台的树、下拉等展示
    //统一带表别名 n：下面 join channel 之后 status/is_del 两张表都有，不带别名 SQL 会报 ambiguous
    $where = [
        ['n.status', '=', 1],
        ['n.is_del', '=', 0],
    ];
    if(!empty($channel_id) && $channel_id > 0){
        $where[] = ['n.channel_id', '=', $channel_id];
    }

    $query = Db::name('Nav')->alias('n');
    if ($with_channel) {
        $query = $query->join('channel c', 'n.channel_id=c.id')->field('n.*,c.nid');
        //排除单页(single)、留言(clue)模型。c.nid 依赖上面的 join，所以必须放这个分支里
        $where[] = ['c.nid', 'not in', ['single', 'clue']];
    }

    return $query->where($where)->order('n.sort asc,n.id asc')->select();
}
/**
 * 递归渲染栏目导航树为 HTML（用于弹窗/下拉等场景）
 * @param array $items  树形数组（含 list 子节点）
 * @param int   $level  当前层级（控制缩进）
 * @return string
 */
function renderNavTreeHtml($items, $level = 0)
{
    $html = '';
    foreach ($items as $item) {
        $paddingLeft = 12 + $level * 18;
        $icon = !empty($item['children']) ? 'layui-icon-triangle-d' : 'layui-icon-file';
        $html .= '<div class="nav-item" data-id="' . $item['id'] . '" style="padding:10px 12px;padding-left:' . $paddingLeft . 'px;cursor:pointer;transition:background .2s;border-bottom:1px solid #f5f5f5;font-size:14px;">';
        $html .= '<i class="layui-icon ' . $icon . '" style="color:#999;margin-right:6px;font-size:12px;"></i>';
        $html .= $item['title'] . '</div>';
        if (!empty($item['children'])) {
            $html .= renderNavTreeHtml($item['children'], $level + 1);
        }
    }
    return $html;
}

//读取关键字列表
function get_keywords()
{
    $keywords = Db::name('Keywords')->where(['status' => 1])->order('add_time asc')->select();
    return $keywords;
}

//读取文章分类列表
function get_article_cate()
{
    $cate = Db::name('ArticleCate')->where(['delete_time' => 0])->order('create_time asc')->select()->toArray();
    return $cate;
}

//读取图集分类列表
function get_gallery_cate()
{
    $cate = Db::name('GalleryCate')->where(['delete_time' => 0])->order('create_time asc')->select()->toArray();
    return $cate;
}

//读取商品分类列表
function get_goods_cate()
{
    $cate = Db::name('GoodsCate')->where(['delete_time' => 0])->order('create_time asc')->select()->toArray();
    return $cate;
}

//访问按小时归档统计
function hour_document($arrData)
{
    $documents = array();
    $hour = [0, 1, 2, 3, 4, 5, 6, 7, 8, 9, 10, 11, 12, 13, 14, 15, 16, 17, 18, 19, 20, 21, 22, 23];
    foreach ($hour as $val) {
        $documents[$val] = 0;
    }
    foreach ($arrData as $index => $value) {
        $archivesTime = intval(date("H", $value['create_time']));
        $documents[$archivesTime] += 1;
    }
    return $documents;
}

//访问按日期归档统计
function date_document($arrData)
{
    $documents = array();
    foreach ($arrData as $index => $value) {
        $archivesTime = date("Y-m-d", $value['create_time']);
        if (empty($documents[$archivesTime])) {
            $documents[$archivesTime] = 1;
        } else {
            $documents[$archivesTime] += 1;
        }
    }
    return $documents;
}

/**
 * 管理员操作日志
 * @param string $type 操作类型 login add edit view delete
 * @param int    $param_id 操作类型
 * @param array  $param 提交的参数
 * @param subject  $param  操作主题
 */
function add_log($type, $param_id = '', $param = [],$subject='')
{
	$action = '未知操作';
	$type_action = get_config('log.admin_action');
	if($type_action[$type]){
		$action = $type_action[$type];
	}
    if ($type == 'login') {
        $login_admin = Db::name('Admin')->where(array('id' => $param_id))->find();
    } else {
        $session_admin = get_config('app.session_admin');
        $login_admin = \think\facade\Session::get($session_admin);
    }
    $data = [];
	$data['title'] = '';
    $data['uid'] = $login_admin['id'];
    $data['nickname'] = $login_admin['nickname'];
    $data['type'] = $type;
    $data['action'] = $action;
    $data['param_id'] = $param_id;
    $data['param'] = json_encode(redact_log_secrets($param));
    $data['module'] = strtolower(app('http')->getName());
    $data['controller'] = uncamelize(app('request')->controller());
    $data['function'] = strtolower(app('request')->action());
    $parameter = $data['module'] . '/' . $data['controller'] . '/' . $data['function'];
    $rule_menu = Db::name('AdminRule')->where(array('src' => $parameter))->find();
	if($rule_menu){
		$data['title'] = $rule_menu['title'];
		$data['subject'] = $rule_menu['name'];
	}
	else{
		if(empty($subject)){
			$data['subject'] = '系统';
		}else{
			$data['subject'] = $subject;
		}
	}
    $content = $login_admin['nickname'] . '在' . date('Y-m-d H:i:s') . $data['action'] . '了' . $data['subject'];
    $data['content'] = $content;
    $data['ip'] = app('request')->ip();
    $data['create_time'] = time();
    Db::name('AdminLog')->strict(false)->field(true)->insert($data);
}

/**
 * 写操作日志 —— 写不进去也不能影响业务
 *
 * 为什么单独包一层：add_log() 里要读 session 里的登录管理员、查后台菜单表，
 * 任何一步出问题都会抛异常。而这些调用点大多在
 *   try { 事务写库; add_log(...); } catch { return 失败 }
 * 这种结构里 —— 一旦日志抛异常，就会被 catch 吞掉，变成
 *   1) 库其实已经写成功了，却告诉用户「操作失败」（发布文档时会让人以为没发成功，再发一遍就是两篇）
 *   2) catch 后面那些「清缓存 / 更新静态页面 / 重建 sitemap」全都不执行
 * 日志只是留痕，不该有这么大权力，所以这里把它的异常压掉。
 *
 * @param string $type
 * @param mixed  $param_id
 * @param array  $param
 * @param string $subject
 * @return void
 */
function safe_add_log($type, $param_id = '', $param = [], $subject = ''): void
{
    try {
        add_log($type, $param_id, $param, $subject);
    } catch (\Throwable $e) {
        //忽略：日志写不进去不影响业务
    }
}

//获取预定字段数据类型
function get_field_type()
{
    $data = Db::name('FieldType')->where(['status'=>1])->order('sort asc,id asc')->select()->toArray();
    return $data;
}
/**
 * 获取附加表名
 * @param int    $channel_id
 */
function get_content_table(int $channel_id){
    $res=Db::name('Channel')->where(['id' => $channel_id])->find();
    return $res['table'];
}
/**
 * 删除正文中的非站内链接，只保留链接文字；白名单域名及其子域名保留链接。
 * 白名单按行配置，每行只接受域名，不接受协议、端口或路径。
 */
function clear_outbound_links(string $content = '', string $whitelist = ''): string
{
    $currentHost = strtolower((string) parse_url('http://' . request()->host(), PHP_URL_HOST));
    $allowedDomains = [];
    $domainPattern = '/^(?:[a-z0-9](?:[a-z0-9-]{0,61}[a-z0-9])?)(?:\.(?:[a-z0-9](?:[a-z0-9-]{0,61}[a-z0-9])?))*$/i';
    foreach (preg_split('/\r\n|\r|\n/', $whitelist) ?: [] as $line) {
        $domain = strtolower(rtrim(trim($line), '.'));
        if ($domain !== '' && preg_match($domainPattern, $domain)) {
            $allowedDomains[] = $domain;
        }
    }
    $hostMatchesDomain = static function (string $host, string $domain): bool {
        return $host === $domain
            || (strlen($host) > strlen($domain)
                && substr($host, -strlen('.' . $domain)) === '.' . $domain);
    };

    return preg_replace_callback(
        '/<\s*a\s+[^<>]*href\s*=\s*["\']([^"\']+)["\'][^<>]*>([\s\S]*?)<\s*\/\s*a\s*>/i',
        function ($matches) use ($currentHost, $allowedDomains, $hostMatchesDomain) {
            $href  = $matches[1];
            $text  = $matches[2];
            // 相对路径、锚点和非网页链接保留原样。
            if (strpos($href, '#') === 0 || stripos($href, 'javascript:') === 0) {
                return $matches[0];
            }
            if (strpos($href, '//') === 0) {
                $href = 'http:' . $href;
            } elseif (strpos($href, '/') === 0) {
                return $matches[0];
            }
            $host = strtolower((string) parse_url($href, PHP_URL_HOST));
            if (!$host) {
                return $matches[0];
            }
            $host = rtrim($host, '.');

            // 当前站点域名及其子域名始终保留。
            if ($currentHost !== '' && $hostMatchesDomain($host, $currentHost)) {
                return $matches[0];
            }
            // 白名单域名及其子域名保留；其他外站链接去掉 a 标签，只保留文字。
            foreach ($allowedDomains as $domain) {
                if ($hostMatchesDomain($host, $domain)) {
                    return $matches[0];
                }
            }
            return $text;
        },
        $content
    );
}
/*
 * 下载远程图片到本地，并替换原图片地址
 * @param string $content 编辑器 HTML 内容
 * @return string 替换后的 HTML 内容
 * */
function download_web_images(string $content = ''): string
{
    if (empty($content)) return $content;

    $currentHost = request()->host();
    $scheme      = request()->scheme();
    $dataPath    = date('Ym');
    $userId      = get_login_admin('id') ?: 0;
    // 图片下载是保存流程中的尽力操作，整篇内容最多占用 15 秒，超时后保留原图地址继续保存。
    $downloadDeadline = microtime(true) + 15;
    // 沿用当前浏览器的 UA，部分图床会校验请求客户端类型。
    $requestUserAgent = substr(str_replace(["\r", "\n"], '', (string) request()->server('HTTP_USER_AGENT')), 0, 512);
    if ($requestUserAgent === '') {
        $requestUserAgent = 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/124.0.0.0 Safari/537.36';
    }

    return preg_replace_callback(
        '/<\s*img\s+[^<>]*src\s*=\s*["\']([^"\']+)["\'][^<>]*>/i',
        function ($matches) use ($currentHost, $scheme, $dataPath, $userId, $requestUserAgent, $downloadDeadline) {
            $src = $matches[1];

            // 跳过 base64、相对路径、无协议链接
            if (stripos($src, 'data:image/') === 0) return $matches[0];
            if (!preg_match('/^https?:\/\//i', $src) && strpos($src, '//') !== 0) return $matches[0];

            // 补齐无协议 URL
            if (strpos($src, '//') === 0) {
                $src = $scheme . ':' . $src;
            }

            // 解析 host，跳过本站
            $host = parse_url($src, PHP_URL_HOST);
            if (!$host) return $matches[0];
            if (stripos($host, $currentHost) !== false || stripos($currentHost, $host) !== false) {
                return $matches[0];
            }

            // 每张图片只请求一次，使用图片源站作为 Referer/Origin；失败时保留原图地址。
            $imageScheme = strtolower((string) parse_url($src, PHP_URL_SCHEME));
            if (!in_array($imageScheme, ['http', 'https'], true)) return $matches[0];
            $sourceOrigin = $imageScheme . '://' . $host;
            $sourcePort = parse_url($src, PHP_URL_PORT);
            if ($sourcePort) {
                $sourceOrigin .= ':' . $sourcePort;
            }
            $remainingSeconds = $downloadDeadline - microtime(true);
            if ($remainingSeconds <= 0.1) return $matches[0];
            $timeoutMs = min(5000, max(100, (int) ($remainingSeconds * 1000)));
            $headers = [
                'Accept: image/avif,image/webp,image/apng,image/svg+xml,image/*,*/*;q=0.8',
                'Accept-Language: zh-CN,zh;q=0.9,en;q=0.8',
                'Referer: ' . str_replace(["\r", "\n"], '', $sourceOrigin . '/'),
                'Origin: ' . str_replace(["\r", "\n"], '', $sourceOrigin),
            ];

            try {
                $candidateContent = \app\services\RemoteImage::get($src, $timeoutMs / 1000, $requestUserAgent);
            } catch (\Throwable $e) {
                // 图床异常时保留原图地址，继续保存文档。
                return $matches[0];
            }
            if (!is_string($candidateContent) || $candidateContent === '') return $matches[0];

            // 防盗链失败时有些站点会返回 200 + HTML 错误页，不能把错误页面存成图片。
            $detectedMime = strtolower((string) (new finfo(FILEINFO_MIME_TYPE))->buffer($candidateContent));
            if ($detectedMime === 'application/octet-stream' && substr($candidateContent, 0, 2) === 'BM') {
                $detectedMime = 'image/bmp';
            }
            if (preg_match('/^\s*(?:<\?xml[^>]*\?>\s*)?(?:<!DOCTYPE\s+svg[^>]*>\s*)?<svg\b/i', $candidateContent)) {
                $detectedMime = 'image/svg+xml';
            }
            $ext = UploadPolicy::extensionForImageMime($detectedMime);
            if ($ext === null || !UploadPolicy::imageMimeMatches($detectedMime, $ext)) return $matches[0];

            $imgContent = $candidateContent;

            $md5  = md5($imgContent);
            $sha1 = sha1($imgContent);

            $filename = $dataPath . '/' . $md5 . '.' . $ext;
            try {
                $stored = StorageService::putContents($filename, $imgContent, $detectedMime);
            } catch (\Throwable $error) {
                return $matches[0];
            }
            $filepath = $stored['filepath'];

            // 写入 cms_file 表
            $data = [
                'filepath'     => $filepath,
                'name'         => basename(parse_url($src, PHP_URL_PATH)) ?: 'image.' . $ext,
                'mimetype'     => $detectedMime,
                'fileext'      => $ext,
                'filesize'     => strlen($imgContent),
                'filename'     => $stored['filename'],
                'sha1'         => $sha1,
                'md5'          => $md5,
                'module'       => app('http')->getName(),
                'action'       => app('request')->action(),
                'uploadip'     => app('request')->ip(),
                'create_time'  => time(),
                'user_id'      => $userId,
                'status'       => 1,
                'admin_id'     => $userId,
                'audit_time'   => time(),
                'use'          => 'content',
                'group_id'     => 1,
            ];
            Db::name('file')->insert($data);

            // 替换 src 为本地地址（只改 src 值，保留其他属性）
            return str_replace($matches[1], $filepath, $matches[0]);
        },
        $content
    );
}
/*
 * 获取模型字段表
 * */
function get_channel_field_column_array($id){
    if(!$id) return;
    $res=Db::name('channel_field')->where(['id'=>$id])->where(['status'=>1])->value('default_value');
    $res=explode('|',$res);
    return $res;
}

/**
 * 发布/编辑/删除文档、增删改栏目后，按「SEO → Sitemap → 自动更新」开关重建地图
 *
 * 勾了「自动更新」时，生成哪几种地图由「地图类型」那几个勾选框决定
 * （和手动点「手工更新」、命令行 php think sitemap 完全同一套规则）。
 *
 * 为什么起独立进程，不直接在本进程里生成：
 *   生成地图要读前台的 URL 规则（nav_url / doc_url），那些函数在 app/home/common.php；
 *   后台进程里再 require 它，会跟 app/admin/common.php 的同名函数（比如 get_navs）撞车，
 *   直接致命错误。所以起一个后台 php 进程去跑 `php think sitemap`，
 *   那个进程只加载 home 的公共文件，不会冲突。
 *
 * 为什么不用 HTTP 请求自己的前台入口（/home/sitemap/build）：
 *   当前后台请求还占着 session 锁，前台入口又要读 session 校验登录态，
 *   会一直阻塞到超时。独立进程不碰 session，没有这个问题。
 *
 * 起不来（exec/popen 被禁用、找不到 php 可执行文件）就静默跳过：
 *   地图没重建成功不影响文档/栏目保存，挂个定时任务 `php think sitemap` 就能补上。
 *
 * @return void
 */
function sitemap_auto_update(): void
{
    //没勾选「自动更新」就直接返回
    $cfg = get_config_group('sitemap');
    if ((int) ($cfg['sitemap_auto'] ?? 0) !== 1) {
        return;
    }

    run_think_background('sitemap');
}

/**
 * 按「SEO → Sitemap → 开启推送」设置，后台异步推送单篇文档地址给百度。
 * token 未配置时跳过，不影响文档发布或编辑。
 */
function sitemap_auto_push_document(int $docId): void
{
    $cfg = get_config_group('sitemap');
    if ((int) ($cfg['sitemap_baidu_push'] ?? 0) !== 1
        || trim((string) ($cfg['sitemap_zzbaidutoken'] ?? '')) === ''
        || $docId < 1) {
        return;
    }

    run_think_background('baidu-push --doc=' . $docId);
}

/**
 * 静态模式下，发布/编辑文档后更新受影响的静态页面
 *
 * 文档发布/编辑时一并重建首页、相关栏目页、上下篇上下文和关联标签页。
 *
 * 这里不直接起进程，只往队列里写一个任务，再叫一下 worker：
 * 连着发布多篇时，worker 会把攒下来的任务合并成一份清单一起生成，
 * 同一个页面只生成一次（详见 app/command/StaticUpdate.php）。
 *
 * @param array  $doc      文档数据，至少要有 id、nav_id
 * @param string $type     操作来源（保留原调用参数，pub=发布文档、edit=编辑文档）
 * @param int    $oldNavId 编辑时文档原来所在的栏目id（换栏目时原栏目那条链也要更新）
 * @return void
 */
function static_update_after_doc(
    array $doc,
    string $type = 'pub',
    int $oldNavId = 0,
    array $oldTagIds = [],
    string $oldUrl = ''
): void
{
    //只有「生成静态」模式才需要更新静态页面（动态、伪静态走清缓存那套）
    $seo = get_config_group('seo');
    if ((int) ($seo['url_pseudo'] ?? 3) !== 2) {
        return;
    }

    $docId = (int) ($doc['id'] ?? 0);
    $navId = (int) ($doc['nav_id'] ?? 0);
    if ($docId < 1 || $navId < 1) {
        return;
    }

    $tagIds = array_values(array_unique(array_merge(
        static_tag_id_list($doc['tag_ids'] ?? []),
        static_tag_id_list($oldTagIds)
    )));

    //静态 worker 会复用前台列表缓存；当前文档变更会影响首页、栏目、上下篇和标签页。
    clear_cache('Document');
    //静态 worker 重建上下篇关联页面前，不能复用旧的相邻文档查询结果。
    clear_cache('docSide');

    // 撤审或屏蔽同删除一样清理已有静态详情，防止绕过 PHP 继续访问旧文件。
    if ((int) ($doc['status'] ?? 1) !== 1 || (int) ($doc['arcrank'] ?? 0) < 0 || !empty($doc['is_del'])) {
        $doc['old_url'] = $oldUrl;
        static_delete_after_doc([$doc]);
        return;
    }
    //发布/编辑时同步维护首页、栏目列表、详情页上下篇和标签页，避免相关页继续引用旧数据。
    static_push_task([
        'doc'              => $docId,
        'nav'              => $navId,
        'oldnav'           => $oldNavId,
        'home'             => 1,
        'navpage'          => 1,
        'side'             => 1,
        'tag_ids'          => $tagIds,
        'delete_doc_urls'  => $oldUrl !== '' ? [$oldUrl] : [],
        'delete'           => 0,
    ]);

    static_spawn_worker();
}

/**
 * 静态模式下，删除文档后清理静态页面
 *
 * 要干两件事：
 *   1) 删掉被删文档自己的静态页 —— 不删的话文档删了页面还在，等于没删掉
 *   2) 重新生成相关栏目页 —— 列表里不能再出现这篇文档；文档变少后
 *      栏目页数可能减少，多出来的尾页也要删掉（worker 里一并处理）
 *
 * 这两件都是「不做就不对」的事，所以没有开关，静态模式下直接做。
 *
 * @param array $docs 删除前查到的文档行，至少包含 id、nav_id、tag_ids
 * @return void
 */
function static_delete_after_doc(array $docs): void
{
    //只有「生成静态」模式才有静态文件要清
    $seo = get_config_group('seo');
    if ((int) ($seo['url_pseudo'] ?? 3) !== 2) {
        return;
    }

    //静态 worker 重建上下篇关联页面前，不能复用旧的相邻文档查询结果。
    clear_cache('docSide');

    $map = nav_flat_map();
    $count = 0;
    foreach ($docs as $doc) {
        if (!is_array($doc)) {
            continue;
        }
        $docId = (int) ($doc['id'] ?? 0);
        $navId = (int) ($doc['nav_id'] ?? 0);
        if ($docId < 1 || $navId < 1) {
            continue;
        }
        $oldUrl = trim((string) ($doc['old_url'] ?? ''));
        if ($oldUrl === '') {
            $oldUrl = doc_url($doc, $map[$navId] ?? null);
        }
        static_push_task([
            'doc'             => $docId,
            'nav'             => $navId,
            'tag_ids'         => static_tag_id_list($doc['tag_ids'] ?? []),
            'delete_doc_urls' => [$oldUrl],
            'home'            => 1,
            'navpage'         => 1,
            'side'            => 1,
            'delete'          => 1,
        ]);
        $count++;
    }

    if ($count > 0) {
        static_spawn_worker();
    }
}

/**
 * 把文档的 tag_ids 字段规范化成去重后的正整数数组。
 *
 * @param mixed $value
 * @return int[]
 */
function static_tag_id_list($value): array
{
    if (is_array($value)) {
        $values = $value;
    } else {
        $raw = trim((string) $value, ", \t\n\r\0\x0B ");
        $values = $raw === '' ? [] : explode(',', $raw);
    }

    $ids = array_map('intval', $values);
    $ids = array_filter($ids, static function ($id) { return $id > 0; });
    return array_values(array_unique($ids));
}

/**
 * 变更栏目之前，保存旧栏目树对应的栏目页、文档页和标签，供静态 worker 清理旧 URL。
 *
 * @param int[] $rootIds
 * @return array
 */
function static_capture_nav_snapshot(array $rootIds): array
{
    $seo = get_config_group('seo');
    if ((int) ($seo['url_pseudo'] ?? 3) !== 2) {
        return [];
    }

    $map = nav_flat_map();
    $byParent = [];
    foreach ($map as $nav) {
        $byParent[(int) ($nav['pid'] ?? 0)][] = (int) ($nav['id'] ?? 0);
    }

    $ids = [];
    $parents = [];
    $stack = [];
    foreach ($rootIds as $rootId) {
        $rootId = (int) $rootId;
        if ($rootId > 0 && isset($map[$rootId])) {
            $stack[] = $rootId;
            $parentId = (int) ($map[$rootId]['pid'] ?? 0);
            if ($parentId > 0) {
                $parents[$parentId] = $parentId;
            }
        }
    }

    while ($stack) {
        $id = array_pop($stack);
        if (isset($ids[$id])) {
            continue;
        }
        $ids[$id] = $id;
        foreach ($byParent[$id] ?? [] as $childId) {
            $stack[] = $childId;
        }
    }

    $navUrls = [];
    foreach ($ids as $id) {
        if (isset($map[$id]) && (empty($map[$id]['is_url']) || empty($map[$id]['url']))) {
            $navUrls[] = nav_url($map[$id]);
        }
    }

    $docs = $ids ? \think\facade\Db::name('document')
        ->field('id,nav_id,tag_ids')
        ->whereIn('nav_id', array_values($ids))
        ->select()
        ->toArray() : [];
    $docUrls = [];
    $tagIds = [];
    foreach ($docs as $doc) {
        $navId = (int) ($doc['nav_id'] ?? 0);
        if (isset($map[$navId])) {
            $docUrls[] = doc_url($doc, $map[$navId]);
        }
        foreach (static_tag_id_list($doc['tag_ids'] ?? []) as $tagId) {
            $tagIds[$tagId] = $tagId;
        }
    }

    return [
        'nav_ids'      => array_values($ids),
        'parent_ids'   => array_values($parents),
        'nav_urls'     => array_values(array_unique($navUrls)),
        'doc_urls'     => array_values(array_unique($docUrls)),
        'tag_ids'      => array_values($tagIds),
    ];
}

/**
 * 栏目新增、编辑、删除或恢复后更新对应静态页面。
 *
 * @param int[] $navIds
 * @param array $snapshot static_capture_nav_snapshot() 的结果
 * @param int[] $parentIds 变更后的父栏目 id
 * @return void
 */
function static_update_after_nav(array $navIds, array $snapshot = [], array $parentIds = []): void
{
    $seo = get_config_group('seo');
    if ((int) ($seo['url_pseudo'] ?? 3) !== 2) {
        return;
    }

    $navIds = array_values(array_unique(array_filter(array_map('intval', $navIds), static function ($id) { return $id > 0; })));
    $parents = array_values(array_unique(array_filter(array_map('intval', array_merge(
        (array) ($snapshot['parent_ids'] ?? []), $parentIds
    )), static function ($id) { return $id > 0; })));
    if (!$navIds && !$parents) {
        return;
    }

    clear_cache('homeNav');

    static_push_task([
        'home'            => 1,
        'nav_ids'         => $navIds,
        'parent_ids'      => $parents,
        'delete_nav_urls' => (array) ($snapshot['nav_urls'] ?? []),
        'delete_doc_urls' => (array) ($snapshot['doc_urls'] ?? []),
        'tag_ids'         => (array) ($snapshot['tag_ids'] ?? []),
    ]);
    static_spawn_worker();
}

/**
 * 标签新增、修改、禁用或删除后更新关联文档、栏目和标签页。
 *
 * @param int[] $tagIds
 * @param int[] $deletedTagIds 已禁用或删除的标签
 * @return void
 */
function static_update_after_tags(array $tagIds, array $deletedTagIds = []): void
{
    $seo = get_config_group('seo');
    if ((int) ($seo['url_pseudo'] ?? 3) !== 2) {
        return;
    }

    $tagIds = array_values(array_unique(array_filter(array_map('intval', $tagIds), static function ($id) { return $id > 0; })));
    $deletedTagIds = array_values(array_unique(array_filter(array_map('intval', $deletedTagIds), static function ($id) { return $id > 0; })));
    if (!$tagIds && !$deletedTagIds) {
        return;
    }

    clear_cache('homeTag');
    clear_cache('Document');

    static_push_task([
        'home'           => 1,
        'tag_ids'        => array_values(array_unique(array_merge($tagIds, $deletedTagIds))),
        'delete_tag_ids' => $deletedTagIds,
    ]);
    static_spawn_worker();
}

/**
 * 队列里已经有任务时，保证有一个 worker 在跑
 *
 * 拿得到锁 = 当前没有 worker → 起一个；拿不到 = 已经有 worker 了，
 * 它下一轮就会读到刚写进去的任务，这里什么都不用做（这就是「节流」）。
 *
 * 锁只在这里拿一下就立刻放掉，真正的持有者是 worker 自己（它跑完才放）。
 * 这样两个请求同时进来最多各起一个进程，第二个 worker 起来发现锁被占会自己退出。
 *
 * @return void
 */
function static_spawn_worker(): void
{
    $lockFile = static_queue_lock_file();
    $lock     = @fopen($lockFile, 'c');

    if ($lock) {
        $free = flock($lock, LOCK_EX | LOCK_NB);
        if ($free) {
            flock($lock, LOCK_UN);
        }
        fclose($lock);

        if (!$free) {
            //已经有 worker 在跑，它会顺带处理刚写进去的任务
            return;
        }
    }

    run_think_background('static-update --worker');
}

/**
 * 起一个后台 php 进程跑 think 命令，立刻返回，不阻塞当前请求
 *
 * 起不来（exec/popen 被禁用、找不到 php 可执行文件）就静默跳过：
 * 这些页面没更新成功不影响文档保存，去后台「SEO → 页面生成」重新生成一遍就行。
 *
 * @param string $command think 命令和参数，例如 'sitemap'、'static-update --doc=1 --nav=2'
 * @return void
 */
function run_think_background(string $command): void
{
    $php = console_php_binary();
    if ($php === '') {
        return;
    }

    $think = root_path() . 'think';
    if (!is_file($think)) {
        return;
    }

    $cmd = '"' . $php . '" "' . $think . '" ' . $command;

    try {
        if (stripos(PHP_OS, 'WIN') === 0) {
            //Windows：start /B 丢到后台，立刻返回，不阻塞当前请求
            if (function_exists('popen')) {
                pclose(popen('start /B "" ' . $cmd . ' > NUL 2>&1', 'r'));
            }
        } else {
            //Linux：& 丢到后台，输出丢弃
            if (function_exists('exec')) {
                exec($cmd . ' > /dev/null 2>&1 &');
            }
        }
    } catch (\Throwable $e) {
        //静默：更新失败不影响文档保存
    }
}

/**
 * 找 php 可执行文件（给 run_think_background() 起后台进程用）
 *
 * 命令行下 PHP_BINARY 就是 php.exe；网页里（php-cgi / php-fpm）PHP_BINARY 可能指向
 * php-cgi，不能直接用，优先取 PHP_BINDIR 目录下的 php(.exe)。
 *
 * @return string 找不到返回空串
 */
function console_php_binary(): string
{
    $isWin = stripos(PHP_OS, 'WIN') === 0;
    $name  = $isWin ? 'php.exe' : 'php';

    $candidates = [];
    if (defined('PHP_BINDIR') && PHP_BINDIR) {
        $candidates[] = rtrim(PHP_BINDIR, '/\\') . DIRECTORY_SEPARATOR . $name;
    }
    if (defined('PHP_BINARY') && PHP_BINARY) {
        $candidates[] = PHP_BINARY;
    }

    foreach ($candidates as $file) {
        if ($file !== '' && is_file($file)) {
            return $file;
        }
    }

    //退回 PATH 里的 php（能不能执行交给系统判断）
    return $name;
}
