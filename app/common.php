<?php
/**
 * @copyright Copyright (c) 2026 米柚工作室
 * @license https://opensource.org/licenses/Apache-2.0
 * @link https://www.miucms.com
 */
// 应用公共文件
use think\facade\Cache;
use think\facade\Config;
use think\facade\Db;
use think\facade\Request;

if (!defined('CMS_VERSION')) {
    define('CMS_VERSION', require dirname(__DIR__) . '/config/version.php');
}
if (!defined('LAYUI_VERSION')) {
    define('LAYUI_VERSION', '2.13.9');
}
/**
 * 模板普通变量默认过滤：保留 htmlspecialchars 的安全转义，只放行广告标签生成的固定 target 属性片段。
 * 该片段不是用户输入，广告开关关闭时为空；其它所有模板变量仍按原规则转义。
 */
function miu_template_default_filter($value)
{
    if ($value === ' target="_blank" ') {
        return $value;
    }

    return htmlspecialchars((string) ($value ?? ''), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}

//设置缓存
function set_cache($key, $value, $date = 86400)
{
    Cache::set($key, $value, $date);
}

//读取缓存
function get_cache($key)
{
   return Cache::get($key);
}

//按「标签」清缓存
//
//注意：think\Cache::clear() 是**不接受参数**的（见 vendor/.../think/Cache.php），
//它永远清空整个缓存池。以前这里写成 Cache::clear($key)，参数被静默忽略 ——
//等于每次调用都把整站缓存清光：连登录 token、网站配置缓存一起干掉，
//也没法只清某一块（后台「发布文档只清相关栏目缓存」这种需求就实现不了）。
//
//正确做法是 Cache::tag($key)->clear()：只清「用 Cache::tag($key) 存过」的那批数据。
//所以凡是想被 clear_cache('xxx') 清掉的缓存，都必须用 Cache::tag('xxx') 存。
//
//传空则退回全量清空（后台「清缓存」按钮走的是 Cache::clear()）。
function clear_cache($key = '')
{
    $key = trim((string) $key);
    if ($key === '') {
        Cache::clear();
        return;
    }
    Cache::tag($key)->clear();
}


//读取文件配置
function get_config($key)
{
    return Config::get($key);
}

//读取一组配置
//cms_config 表按 name 分组，一行一组，content 是序列化后的数组
//（global = 网站配置及通用开关，seo = SEO/URL规则；以后要加新分组，往表里加一行即可）
function get_config_group(string $name = 'global'): array
{
    $name = trim($name);
    if ($name === '') {
        $name = 'global';
    }

    $cacheKey = 'system_config_' . $name;
    $config   = get_cache($cacheKey);
    if (is_array($config)) {
        return $config;
    }

    $conf   = Db::name('config')->where('name', $name)->find();
    $config = [];
    if (!empty($conf['content'])) {
        //序列化数据被手工改坏时 unserialize 会报错，用 @ 压掉并回退成空数组
        $config = @unserialize($conf['content'], ['allowed_classes' => false]);
    }
    $config = is_array($config) ? $config : [];
    //必须用 Cache::tag() 存：后台保存配置时调的是 clear_cache('system_config_' . $name)，
    //而 clear_cache() 清的是「标签」（Cache::clear($tag)），不打 tag 存的话清不掉，
    //改了网站配置前台会一直读旧值（最长到 24 小时过期）
    Cache::tag($cacheKey)->set($cacheKey, $config, 86400);

    return $config;
}

//保存一组配置：只覆盖传进来的键，这一组里其它键原样保留；分组不存在时自动新建一行
function save_config_group(string $name, array $data): bool
{
    $name = trim($name);
    if ($name === '') {
        return false;
    }

    $config = get_config_group($name);
    foreach ($data as $key => $value) {
        $config[$key] = $value;
    }

    $conf = Db::name('config')->where('name', $name)->find();
    $row  = [
        'content'     => serialize($config),
        'update_time' => time(),
    ];

    if ($conf) {
        $row['id'] = $conf['id'];
        $res = Db::name('config')->strict(false)->field(true)->update($row);
    } else {
        $row['name']        = $name;
        $row['title']       = ['seo' => 'SEO配置', 'template' => '模板配置'][$name] ?? $name;
        $row['status']      = 1;
        $row['create_time'] = time();
        $res = Db::name('config')->strict(false)->field(true)->insert($row);
    }

    clear_cache('system_config_' . $name);

    return $res !== false;
}

//读取系统配置（global 组）：传 key 返回该配置项，不传 key 返回全部配置项
function get_system_config($key='')
{
    $config = get_config_group('global');
    //前台非中文页面自动覆盖网站名称和首页 SEO 字段；原始配置仍保存在 global 分组。
    if ($key === '' || (is_string($key) && in_array($key, ['site_name', 'site_title', 'site_keywords', 'site_desc'], true))) {
        try {
            $config = \app\home\service\ContentI18n::overlaySiteConfig($config);
        } catch (\Throwable $e) {
            //翻译读取失败时继续使用原始配置，不影响页面访问。
        }
    }
    if($key==''){
        return $config;
    }
    //原来是 if($config[$key])，值为 0/'' 时会返回 null，这里改成键存在就返回
    return array_key_exists($key, $config) ? $config[$key] : null;
}

//模板根目录（传模板名则返回该模板目录，均以分隔符结尾）
function get_templates_path(string $name = ''): string
{
    $path = root_path() . 'templates' . DIRECTORY_SEPARATOR;
    return $name === '' ? $path : $path . $name . DIRECTORY_SEPARATOR;
}

//读取模板元信息；新模板使用 JSON 格式 info.json，旧模板继续兼容 info.txt 和 info.ini。
function get_templates(): array
{
    $dir = get_templates_path();
    if (!is_dir($dir)) {
        return [];
    }

    $list = [];
    $root = realpath($dir);
    foreach (glob($dir . '*', GLOB_ONLYDIR) ?: [] as $path) {
        $name = basename($path);
        if ($name === '' || $name[0] === '.') {
            continue;
        }

        //目录名才是实际模板标识，不能用元信息中的 name 拼接文件路径。
        $info     = [];
        $error    = '';
        $resolved = realpath($path);
        if (!preg_match('/^[A-Za-z0-9_]{1,32}$/', $name)) {
            $error = '模板目录名仅支持 1 至 32 位字母、数字和下划线';
        } elseif (!$resolved || dirname($resolved) !== $root) {
            $error = '模板目录必须位于 templates 根目录内';
        }
        $jsonFile = $path . DIRECTORY_SEPARATOR . 'info.json';
        $txtFile  = $path . DIRECTORY_SEPARATOR . 'info.txt';
        $iniFile  = $path . DIRECTORY_SEPARATOR . 'info.ini';
        if ($error === '' && is_file($jsonFile)) {
            $text = @file_get_contents($jsonFile, false, null, 0, 65537);
            if (!is_string($text) || strlen($text) > 65536) {
                $error = 'info.json 无法读取或超过 64KB';
            } else {
                $text = preg_replace('/^\xEF\xBB\xBF/', '', $text);
                $decoded = json_decode($text);
                if (!$decoded instanceof \stdClass) {
                    $error = 'info.json 格式无效，必须是 JSON 对象';
                } else {
                    foreach (['name', 'title', 'version', 'minCmsVersion', 'description', 'author', 'authorUrl', 'createdAt', 'type'] as $field) {
                        if (property_exists($decoded, $field)) {
                            $info[$field] = $decoded->{$field};
                        }
                    }
                }
            }
        } elseif ($error === '' && is_file($txtFile)) {
            $text = @file_get_contents($txtFile, false, null, 0, 65537);
            if (!is_string($text) || strlen($text) > 65536) {
                $error = '旧版 info.txt 无法读取或超过 64KB';
            } else {
                $text = trim(preg_replace('/^\xEF\xBB\xBF/', '', $text));
                if (strpos($text, '=') === false) {
                    $info['title'] = $text;
                } else {
                    foreach (preg_split('/\R/u', $text) ?: [] as $line) {
                        $parts = explode('=', trim($line), 2);
                        if (count($parts) === 2 && in_array(trim($parts[0]), ['name', 'title', 'version', 'minCmsVersion', 'description', 'author', 'authorUrl', 'createdAt', 'type'], true)) {
                            $info[trim($parts[0])] = trim($parts[1]);
                        }
                    }
                }
            }
        } elseif ($error === '' && is_file($iniFile)) {
            $ini  = @parse_ini_file($iniFile, false, INI_SCANNER_RAW);
            $info = is_array($ini) ? $ini : [];
        }

        foreach (['name', 'title', 'version', 'minCmsVersion', 'description', 'author', 'authorUrl', 'type'] as $field) {
            if (isset($info[$field]) && !is_string($info[$field])) {
                $error = '模板信息字段必须为文本：' . $field;
                $info[$field] = '';
            }
        }
        if ($error === '' && isset($info['name']) && $info['name'] !== '' && $info['name'] !== $name) {
            $error = '模板元信息中的 name 与模板目录名不一致';
        }
        $required = (string) ($info['minCmsVersion'] ?? '');
        if ($error === '' && $required !== '') {
            if (!preg_match('/\A\d+(?:\.\d+)*(?:[-+][0-9A-Za-z.-]+)?\z/', $required)) {
                $error = 'minCmsVersion 版本格式无效';
            } elseif (defined('CMS_VERSION') && version_compare((string) CMS_VERSION, $required, '<')) {
                $error = "当前 CMS 版本低于模板要求的 {$required}，请升级后再启用";
            }
        }

        // 旧模板可以没有作者信息；网址只放行 HTTP(S)，避免卡片链接注入其他协议。
        $authorUrl = trim((string) ($info['authorUrl'] ?? ''));
        if (strlen($authorUrl) > 2048 || !filter_var($authorUrl, FILTER_VALIDATE_URL) || !preg_match('#^https?://#i', $authorUrl)) {
            $authorUrl = '';
        }
        $createdAtValue = $info['createdAt'] ?? '';
        $createdAtText = is_int($createdAtValue) || is_string($createdAtValue) ? (string) $createdAtValue : '';
        $createdAt = preg_match('/\A[0-9]{1,12}\z/', $createdAtText)
            ? (int) $createdAtText
            : 0;
        if ($createdAt < 1) {
            $createdAt = (int) (@filectime($path) ?: @filemtime($path) ?: 0);
        }
        $type = (string) ($info['type'] ?? '');
        $typeTitles = [
            'corporate' => '企业官网',
            'blog' => '博客站',
            'image' => '图片站',
            'video' => '视频站',
            'portal' => '资讯门户',
        ];
        $typeTitle = $name === 'default' ? '默认' : ($typeTitles[$type] ?? '');
        $list[] = [
            'name'  => $name,
            'title' => (string) (($info['title'] ?? '') ?: $name),
            'version' => (string) ($info['version'] ?? ''),
            'minCmsVersion' => $required,
            'description' => (string) ($info['description'] ?? ''),
            'author' => (string) ($info['author'] ?? ''),
            'authorUrl' => $authorUrl,
            'type' => $type,
            'typeTitle' => $typeTitle,
            'createdAt' => $createdAt,
            'error' => $error,
        ];
    }

    return $list;
}

//获取当前启用的前台模板名，配置缺失/非法/目录不存在时回退到 default
function get_current_template(): string
{
    if (\app\services\TemplateVisualEditor::$previewTheme !== '') {
        return \app\services\TemplateVisualEditor::$previewTheme;
    }
    $default = 'default';

    try {
        //模板选择必须读取原始配置，避免读取本地化配置时反向触发模板/语言解析。
        $settings = get_config_group('template');
        //旧站尚未保存独立模板配置时保留原来的选择，避免升级后意外切换模板。
        $value = $settings['current'] ?? (get_config_group('global')['template'] ?? '');
        $name = is_string($value) ? trim($value) : '';
    } catch (\Throwable $e) {
        return $default;
    }

    if (!preg_match('/^[A-Za-z0-9_]{1,32}$/', $name)) {
        return $default;
    }

    return is_dir(get_templates_path($name)) ? $name : $default;
}

//前台模板的静态资源地址替换表
//模板自带 static/ 时走 /skin/<模板名>/（由 sync_template_assets 发布过去），
//没自带就沿用 config/view.php 里的全局默认，避免只放了一半资源导致整站 404
function template_asset_replace(string $tpl): array
{
    $own = is_dir(get_templates_path($tpl) . 'static');
    $t   = '/skin/' . $tpl;

    return [
        '{__STATIC__}' => $own ? $t             : '/static',
        '{__ASSETS__}' => $own ? $t . '/assets' : '/static/assets',
        '{__CSS__}'    => $own ? $t . '/css'    : '/static/home/css',
        '{__JS__}'     => $own ? $t . '/js'     : '/static/home/js',
        '{__IMG__}'    => $own ? $t . '/images' : '/static/home/images',
        //后台资源全局共用，不跟着模板走
        '{__ADMIN__}'  => '/static/admin',
    ];
}

//把模板自带的静态资源同步（发布）到 public/skin/<模板名>/ 下
//模板目录在 docroot 外，资源要能被浏览器访问就得复制到 public 下；
//只在后台切换模板、点清缓存时调用，不放在请求路径上
//@return array ['copy' => 复制数, 'del' => 删除数, 'skip' => 未变动数, 'failed' => 失败数, 'failed_details' => 失败路径和原因]
function sync_template_assets(string $tpl): array
{
    $result = ['copy' => 0, 'del' => 0, 'skip' => 0, 'failed' => 0, 'failed_details' => []];

    //模板名只允许字母数字下划线，避免拼出目录穿越
    if (!preg_match('/^[A-Za-z0-9_]{1,32}$/', $tpl)) {
        return $result;
    }

    $src = get_templates_path($tpl) . 'static' . DIRECTORY_SEPARATOR;
    $dst = public_path() . 'skin' . DIRECTORY_SEPARATOR . $tpl . DIRECTORY_SEPARATOR;
    $failures = [];
    $recordFailure = function (string $path, string $reason) use (&$failures, $dst): void {
        $relative = str_replace('\\', '/', substr($path, strlen($dst)));
        $failures[$reason . "\0" . $relative] = ['path' => $relative === '' ? '.' : $relative, 'reason' => $reason];
    };

    //模板没自带 static/ 就什么都不做，目标目录里已有的文件也不动
    if (!is_dir($src)) {
        return $result;
    }
    if (!is_dir($dst) && !@mkdir($dst, 0755, true) && !is_dir($dst)) {
        $recordFailure($dst, '无法创建目录');
        $result['failed'] = count($failures);
        $result['failed_details'] = array_slice(array_values($failures), 0, 5);
        return $result;
    }

    //清缓存时实际探测目标树的写权限，避免资源未变化而被跳过时漏报权限问题。
    $probeDirectory = function (string $directory) use ($recordFailure): void {
        try {
            $probePath = rtrim($directory, '\\/') . DIRECTORY_SEPARATOR . '.miucms_asset_check_' . bin2hex(random_bytes(8));
        } catch (\Throwable $e) {
            $recordFailure($directory, '无法生成权限检测文件名');
            return;
        }
        $handle = @fopen($probePath, 'xb');
        if (!is_resource($handle)) {
            $recordFailure($directory, '目录不可写（无法创建检测文件）');
            return;
        }
        $written = @fwrite($handle, 'miucms-write-check');
        $flushed = @fflush($handle);
        @fclose($handle);
        $removed = !file_exists($probePath) || @unlink($probePath);
        if ($written !== 18 || !$flushed || !$removed) {
            $recordFailure($directory, '目录写入检测失败');
        }
    };
    $probeDirectory($dst);
    $destinationIterator = new \RecursiveIteratorIterator(
        new \RecursiveDirectoryIterator($dst, \FilesystemIterator::SKIP_DOTS),
        \RecursiveIteratorIterator::SELF_FIRST
    );
    foreach ($destinationIterator as $item) {
        if ($item->isDir()) {
            $probeDirectory($item->getPathname());
        }
    }

    $files    = [];
    $directories = [];
    $iterator = new \RecursiveIteratorIterator(
        new \RecursiveDirectoryIterator($src, \FilesystemIterator::SKIP_DOTS),
        \RecursiveIteratorIterator::SELF_FIRST
    );
    foreach ($iterator as $item) {
        $rel = str_replace('\\', '/', substr($item->getPathname(), strlen($src)));
        if ($rel === '') {
            continue;
        }
        $target = $dst . str_replace('/', DIRECTORY_SEPARATOR, $rel);

        if ($item->isDir()) {
            $directories[$rel] = true;
            if (!is_dir($target) && !@mkdir($target, 0755, true) && !is_dir($target)) {
                $recordFailure($target, '无法创建目录');
            }
            continue;
        }

        $files[$rel] = true;

        //大小和修改时间都没变就跳过，避免每次全量复制
        if (is_file($target) && filesize($target) === $item->getSize() && filemtime($target) >= $item->getMTime()) {
            $result['skip']++;
            continue;
        }

        if (!is_dir(dirname($target))) {
            if (!@mkdir(dirname($target), 0755, true) && !is_dir(dirname($target))) {
                $recordFailure(dirname($target), '无法创建目录');
                continue;
            }
        }
        try {
            $tempTarget = dirname($target) . DIRECTORY_SEPARATOR . '.miucms_asset_' . bin2hex(random_bytes(8));
        } catch (\Throwable $e) {
            $recordFailure($target, '无法生成临时文件名');
            continue;
        }
        $tempHandle = @fopen($tempTarget, 'xb');
        if (!is_resource($tempHandle)) {
            $recordFailure(dirname($target), '无法创建临时文件');
            continue;
        }
        @fclose($tempHandle);
        if (@copy($item->getPathname(), $tempTarget)) {
            //同目录临时文件替换旧资源，避免旧文件只读时直接覆盖失败。
            @touch($tempTarget, $item->getMTime());
            $copied = @rename($tempTarget, $target);
            if (!$copied && is_file($target) && @unlink($target)) {
                $copied = @rename($tempTarget, $target);
            }
            if ($copied) {
                $result['copy']++;
            } else {
                @unlink($tempTarget);
                $recordFailure($target, '替换文件失败');
            }
        } else {
            @unlink($tempTarget);
            $recordFailure($target, '复制文件失败');
        }
    }

    //源里已经删掉的文件，目标里也清掉，避免残留旧资源
    $iterator = new \RecursiveIteratorIterator(
        new \RecursiveDirectoryIterator($dst, \FilesystemIterator::SKIP_DOTS),
        \RecursiveIteratorIterator::CHILD_FIRST
    );
    foreach ($iterator as $item) {
        $rel = str_replace('\\', '/', substr($item->getPathname(), strlen($dst)));
        if (isset($files[$rel]) || isset($directories[$rel])) {
            continue;
        }
        if ($item->isFile()) {
            if (@unlink($item->getPathname())) {
                $result['del']++;
            } else {
                $recordFailure($item->getPathname(), '无法删除旧文件');
            }
        } elseif ($item->isDir()) {
            if (!@rmdir($item->getPathname()) && is_dir($item->getPathname())) {
                $recordFailure($item->getPathname(), '无法删除旧目录');
            }
        }
    }

    $result['failed'] = count($failures);
    $result['failed_details'] = array_slice(array_values($failures), 0, 5);
    return $result;
}

//清掉各应用的模板编译缓存
//模板编译产物是 runtime/<应用>/temp/ 下的文件，Cache::clear() 清不到它们
function clear_tpl_cache(): int
{
    $count = 0;
    //runtime_path() 返回的是当前应用自己的 runtime 目录（后台请求时是 runtime/admin），
    //所以还要从项目根的 runtime 再扫一遍，才能清到前台的编译缓存
    $bases = [
        app()->getRuntimePath(),
        root_path() . 'runtime' . DIRECTORY_SEPARATOR,
    ];
    foreach ($bases as $base) {
        if (!is_dir($base)) {
            continue;
        }
        foreach (glob($base . 'temp' . DIRECTORY_SEPARATOR . '*.php') ?: [] as $file) {
            if (is_file($file) && @unlink($file)) {
                $count++;
            }
        }
        foreach (glob($base . '*' . DIRECTORY_SEPARATOR . 'temp' . DIRECTORY_SEPARATOR . '*.php') ?: [] as $file) {
            if (is_file($file) && @unlink($file)) {
                $count++;
            }
        }
    }

    return $count;
}

/**
 * 清理「已经过期」的文件缓存，释放磁盘空间
 *
 * 为什么需要它：ThinkPHP 的 File 驱动只在「某个 key 又被读到」的时候才顺手删掉它的过期
 * 文件（见 vendor/.../cache/driver/File.php 的 getRaw()），**没有任何后台 GC**。
 * 所以再也不会被访问的长尾 key（比如深度翻页的栏目列表）过期后会一直占着磁盘，
 * 越攒越多。这个函数主动扫一遍缓存目录，把过期的文件删掉。
 *
 * 过期判断跟驱动内部保持一致：文件头第 8 字节起 12 位是 TTL，
 * 「文件修改时间 + TTL < 现在」就算过期（TTL = 0 表示永久，不动它）。
 *
 * 缓存目录取自配置 cache.stores.file.path（配置里已经用 runtime_path() 存成绝对路径，
 * 所以后台、前台、命令行拿到的都是同一个目录）。换成 redis 驱动后这里扫的是个空目录，
 * 不会有副作用。
 *
 * 一般挂定时任务每天跑一次即可：
 *   php think cache-clean
 *
 * @return array ['files' => 删掉的文件数, 'bytes' => 释放的字节数]
 */
function clear_expired_cache(): array
{
    $result = ['files' => 0, 'bytes' => 0];

    $dir = (string) config('cache.stores.file.path');
    if ($dir === '' || !is_dir($dir)) {
        return $result;
    }

    $now = time();

    try {
        //CHILD_FIRST：子项先于父目录被访问，这样删完文件后正好可以收掉空目录
        $iterator = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($dir, \FilesystemIterator::SKIP_DOTS),
            \RecursiveIteratorIterator::CHILD_FIRST
        );

        foreach ($iterator as $item) {
            /** @var \SplFileInfo $item */
            $path = $item->getPathname();

            if ($item->isDir()) {
                //空目录收掉（此时子项已经处理完）；非空的话 rmdir 会失败，忽略即可
                @rmdir($path);
                continue;
            }
            if (!$item->isFile()) {
                continue;
            }

            //读文件头：偏移 0 开始是 8 字节的固定前缀，紧接着 12 位就是 TTL
            $fp = @fopen($path, 'rb');
            if (!$fp) {
                continue;
            }
            $head = fread($fp, 32);
            fclose($fp);

            if ($head === false || strlen($head) < 20) {
                continue;
            }
            $ttl = (int) substr($head, 8, 12);
            if ($ttl <= 0) {
                //0 = 永久缓存，不删
                continue;
            }

            $mtime = @filemtime($path);
            if ($mtime === false || $mtime + $ttl > $now) {
                //还没过期
                continue;
            }

            $size = (int) @filesize($path);
            if (@unlink($path)) {
                $result['files']++;
                $result['bytes'] += $size;
            }
        }
    } catch (\Throwable $e) {
        //扫不动就返回已经删掉的数量，别把调用方（后台按钮/定时任务）搞崩
        return $result;
    }

    return $result;
}

/**
 * 取某个栏目自己 + 它所有上级栏目的 id（顺序：自己 → 一级栏目）
 *
 * 用于「清除相关栏目缓存」：文档变动会影响自己所在栏目，也会影响所有上级栏目
 * （{miu:list navid=上级栏目} 会连子栏目的文档一起取）。
 * 直接查 nav 表，不依赖前台 home 应用的栏目缓存，后台/命令行都能用。
 *
 * @param int $navId
 * @return array
 */
function doc_nav_chain_ids(int $navId): array
{
    $navId = (int) $navId;
    if ($navId < 1) {
        return [];
    }

    $ids = [];
    $cur = $navId;
    //pid 被人工改坏成环时用次数 + 去重兜底
    for ($i = 0; $cur > 0 && $i < 32; $i++) {
        if (in_array($cur, $ids, true)) {
            break;
        }
        $ids[] = $cur;
        $cur   = (int) Db::name('nav')->where('id', $cur)->value('pid');
    }

    return $ids;
}

/**
 * 按后台「发布/编辑文档」的勾选，清掉这篇文档影响到的前台列表缓存
 *
 * 前台文档列表缓存除了总标记 Document，还按范围打了细标记：
 *   docHome          首页/全站那种「不限栏目、不限模型」的列表
 *   docNav{栏目id}    某个栏目的列表
 *   docMid{模型id}    按模型取的列表
 * 这里按开关只清受影响的那部分，不用整个 clear_cache('Document')。
 *
 * 新设置优先读 global 组；尚未迁移的站点兼容读取 seo 组中的旧值。
 *
 * @param array $doc  文档数据，至少要有 nav_id；channel_id 可选
 * @param array $extraNavIds 额外要清的栏目id（比如编辑时文档原来所在的栏目）
 * @return void
 */
function clear_doc_list_cache(array $doc, array $extraNavIds = []): void
{
    $seo = get_config_group('seo');
    $global = get_config_group('global');
    foreach (['url_del_home', 'url_del_nav', 'url_del_side'] as $key) {
        if (array_key_exists($key, $global)) {
            $seo[$key] = $global[$key];
        }
    }
    //没存过就按配置页默认值（首页1 栏目1 上下篇1）
    $on = function (string $key, int $default) use ($seo) {
        $val = $seo[$key] ?? null;
        return ($val === null || $val === '') ? $default : (int) $val;
    };

    //1) 清除首页缓存：首页那种「不限栏目」的列表
    if ($on('url_del_home', 1)) {
        clear_cache('docHome');
    }

    //2) 清除相关栏目缓存：文档所在栏目 + 所有上级栏目（编辑换栏目时把原栏目也带上）
    if ($on('url_del_nav', 1)) {
        $navIds = array_merge(doc_nav_chain_ids((int) ($doc['nav_id'] ?? 0)), $extraNavIds);
        foreach (array_unique(array_map('intval', $navIds)) as $navId) {
            if ($navId > 0) {
                clear_cache('docNav' . $navId);
            }
        }

        $channelId = (int) ($doc['channel_id'] ?? 0);
        if ($channelId > 0) {
            clear_cache('docMid' . $channelId);
        }
    }

    //3) 清除上下篇缓存：miu_doc_sibling() 的结果打了 docSide 标签。
    if ($on('url_del_side', 1)) {
        clear_cache('docSide');
    }
}

/* ==================== 静态页面更新任务队列 ==================== */

/**
 * 队列目录（发布/编辑/删除文档时写任务，后台 worker 合并处理）
 *
 * 为什么要排队：静态模式下每发布一篇文档都要重新生成首页、相关栏目页这些，
 * 一篇一个后台进程的话，批量导入 100 篇就是 100 个进程。改成
 * 「写任务文件 + 只有一个 worker 在跑」，worker 把攒下来的任务合并成一份清单，
 * 同一个页面只生成一次。
 *
 * 为什么一个任务一个文件，而不是都写进同一个队列文件：
 * 一个文件就要处理并发写（加锁、读改写），一个任务一个文件各写各的，
 * 天然不会互相踩；worker 直接 glob 出来读完就删。
 *
 * @return string
 */
function static_queue_dir(): string
{
    // 后台 HTTP 请求与 CLI 命令可能使用不同的应用运行时目录（如 runtime/admin）。
    // 队列由后台写入、CLI worker 读取，必须放在两者共享的项目级 runtime 下。
    return root_path() . 'runtime' . DIRECTORY_SEPARATOR . 'static_queue' . DIRECTORY_SEPARATOR;
}

/**
 * worker 的锁文件（谁拿着锁谁就是当前在跑的 worker，同一时间只允许一个）
 *
 * @return string
 */
function static_queue_lock_file(): string
{
    return static_queue_dir() . '.worker.lock';
}

/**
 * 往队列里放一个任务
 *
 * @param array $task
 * @return bool
 */
function static_push_task(array $task): bool
{
    $dir = static_queue_dir();
    if (!is_dir($dir) && !@mkdir($dir, 0755, true)) {
        return false;
    }

    $file = $dir . date('YmdHis') . '_' . bin2hex(random_bytes(6)) . '.json';
    $tmp  = $file . '.tmp';

    if (@file_put_contents($tmp, json_encode($task, JSON_UNESCAPED_UNICODE)) === false) {
        @unlink($tmp);
        return false;
    }

    //先写 .tmp 再改名：改名是原子的，worker 只 glob *.json，
    //不会读到写了一半的文件（读到半个 json 解析失败，任务就丢了）
    if (!@rename($tmp, $file)) {
        @unlink($tmp);
        return false;
    }

    return true;
}

/* ==================== 广告（cms_adv）内容解析 ==================== */

/**
 * 广告的 content 按类型拆成能直接用的数组
 *
 * 后台列表（显示类型/缩略图/摘要）和前台 {miu:adv} 标签都要按类型拆 content，
 * 规则放一份在这里，两边共用，免得改一处漏一处。
 *
 * 类型（cms_adv.type）：
 *   1 轮播图片   content 是 JSON 数组，每张图一条：
 *                img_src 图片地址 / img_title 图片标题 / img_desc 图片注释（HTML）/
 *                img_url 跳转链接 / img_target 是否新窗口
 *   2 多媒体     content 就是那条媒体地址
 *   3 HTML代码   content 就是那段 HTML
 *
 * @param array $adv cms_adv 的一行
 * @return array ['type' => int, 'items' => array]
 */
function adv_content_items(array $adv): array
{
    $type    = (int) ($adv['type'] ?? 0);
    $content = (string) ($adv['content'] ?? '');

    if ($type === 1) {
        return ['type' => 1, 'items' => adv_decode_images($content)];
    }

    if ($type === 2) {
        return ['type' => 2, 'items' => $content === '' ? [] : [['media_url' => $content]]];
    }

    if ($type === 3) {
        return ['type' => 3, 'items' => $content === '' ? [] : [['html' => $content]]];
    }

    return ['type' => 0, 'items' => []];
}

/**
 * 广告 content 是不是「轮播图片」的 JSON
 *
 * @param string $content
 * @return array 不是 JSON 就返回空数组
 */
function adv_decode_images(string $content): array
{
    $content = trim($content);
    //图片 JSON 一定以 [ 开头；先看第一个字符，避免每段 HTML 都去 json_decode
    if ($content === '' || $content[0] !== '[') {
        return [];
    }

    $arr = json_decode($content, true);

    return is_array($arr) ? $arr : [];
}

//系统信息
function get_system_info($key)
{
    $system = [
        'os' => PHP_OS,
        'php' => PHP_VERSION,
        'upload_max_filesize' => get_cfg_var("upload_max_filesize") ? get_cfg_var("upload_max_filesize") : "不允许上传附件",
        'max_execution_time' => get_cfg_var("max_execution_time") . "秒 ",
    ];
    if (empty($key)) {
        return $system;
    } else {
        return $system[$key];
    }
}

/**
 * 把 php.ini 里的容量写法换算成字节数（'2M' => 2097152）
 *
 * 为什么要自己写：PHP 8.2 才提供 ini_parse_quantity()，8.0 / 8.1 下调用它会直接
 * 「Call to undefined function」。本项目 composer.json 要求 php >= 8.0，
 * 所以统一用这个函数，各版本行为一致（K / M / G 都按 1024 进制）。
 *
 * 支持：'0'、'2'、'2K'、'2M'、'1G'、'2MB'（大小写都行，数字和单位之间可以有空格）。
 * 认不出来（空串、'abc'、'-1' 等）返回 0，调用处把 0 当「没限制」处理即可。
 *
 * @param string $val ini_get() / get_cfg_var() 拿到的原始值，如 '2M'
 * @return int 字节数
 */
function ini_size_to_bytes($val): int
{
    $val = trim((string) $val);
    //数字 + 可选单位（K/M/G，后面再带个 B 也认）
    if (!preg_match('/^([0-9.]+)\s*([kmgb]*)$/i', $val, $m)) {
        return 0;
    }

    $num = (float) $m[1];
    if ($num <= 0) {
        return 0;
    }

    switch (strtolower(substr($m[2], 0, 1))) {
        case 'g':
            $num *= 1024 * 1024 * 1024;
            break;
        case 'm':
            $num *= 1024 * 1024;
            break;
        case 'k':
            $num *= 1024;
            break;
    }

    return (int) $num;
}

//获取url参数
function get_params($key = "")
{
    return Request::instance()->param($key);
}

//生成一个不会重复的字符串
function make_token()
{
    $str = md5(uniqid(md5(microtime(true)), true));
    $str = sha1($str); //加密
    return $str;
}

//随机字符串，默认长度10
function set_salt($num = 10)
{
    $str = 'qwertyuiopasdfghjklzxcvbnmQWERTYUIOPASDFGHJKLZXCVBNM1234567890';
    $salt = substr(str_shuffle($str), 10, $num);
    return $salt;
}
//随机纯字母字符串，默认长度10
function set_salt_letter($num = 10)
{
    $str = 'qwertyuiopasdfghjklzxcvbnm';
    $salt = substr(str_shuffle($str), 10, $num);
    return $salt;
}
//密码加密
function set_password($pwd, $salt)
{
    return md5(md5($pwd . $salt) . $salt);
}

//新密码使用慢哈希；先定长预处理，避免 bcrypt 对长密码只处理前 72 字节。
function hash_user_password($password): string
{
    $hash = password_hash(hash('sha256', (string) $password), PASSWORD_DEFAULT);
    if (!is_string($hash)) {
        throw new \RuntimeException('密码加密失败');
    }
    return $hash;
}

//兼容旧版双重 MD5 盐值；旧密码在成功登录后由调用处升级。
function verify_user_password($password, $storedHash, $salt = ''): bool
{
    $password = (string) $password;
    $storedHash = (string) $storedHash;
    $info = password_get_info($storedHash);
    if (!empty($info['algo'])) {
        return password_verify(hash('sha256', $password), $storedHash);
    }
    $legacyHash = set_password($password, (string) $salt);
    return strlen($storedHash) === strlen($legacyHash) && hash_equals($legacyHash, $storedHash);
}

function user_password_needs_rehash($storedHash): bool
{
    $info = password_get_info((string) $storedHash);
    return empty($info['algo']) || password_needs_rehash((string) $storedHash, PASSWORD_DEFAULT);
}

function is_duplicate_key_error(\Throwable $exception): bool
{
    for ($error = $exception; $error; $error = $error->getPrevious()) {
        if ((string) $error->getCode() === '1062'
            || preg_match('/duplicate (entry|key)/i', $error->getMessage())) {
            return true;
        }
    }
    return false;
}

//判断cms是否完成安装
function is_installed()
{
    static $isInstalled;
    if (empty($isInstalled)) {
        //命令行入口也需要检测安装状态，不能依赖仅在 HTTP 入口定义的 CMS_ROOT。
        $isInstalled = file_exists(root_path() . 'config/install.lock');
    }
    return $isInstalled;
}

//判断cms是否存在模板
function isTemplate($url='')
{
    static $isTemplate;
    if (empty($isTemplate)) {
        $isTemplate = file_exists(CMS_ROOT . 'app/'.$url);
    }
    return $isTemplate;
}

/**
 * 返回json数据，用于接口
 * @param    integer    $code
 * @param    string     $msg
 * @param    array      $data
 * @param    string     $url
 * @param    integer    $httpCode
 * @param    array      $header
 * @param    array      $options
 * @return   json
 */
function to_assign($code = 0, $msg = "操作成功", $data = [], $url = '', $httpCode = 200, $header = [], $options = [])
{
    $res = ['code' => $code];
    $res['msg'] = $msg;
    $res['url'] = $url;
    if (is_object($data)) {
        $data = $data->toArray();
    }
    $res['data'] = $data;
    $response = \think\Response::create($res, "json", $httpCode, $header, $options);
    throw new \think\exception\HttpResponseException($response);
}

/**
 * 适配layui数据列表的返回数据方法，用于接口
 * @param    integer    $code
 * @param    string     $msg
 * @param    array      $data
 * @param    integer    $httpCode
 * @param    array      $header
 * @param    array      $options
 * @return   json
 */
function table_assign($code = 0, $msg = '请求成功', $data = [], $httpCode = 200, $header = [], $options = [])
{
    $res['code'] = $code;
    $res['msg'] = $msg;
    if (is_object($data)) {
        $data = $data->toArray();
    }
    if (!empty($data['total'])) {
        $res['count'] = $data['total'];
    } else {
        $res['count'] = 0;
    }
    $res['data'] = $data['data'];
    $response = \think\Response::create($res, "json", $httpCode, $header, $options);
    throw new \think\exception\HttpResponseException($response);
}

//菜单转为父子菜单
function list_to_tree($list, $pk = 'id', $pid = 'pid', $child = 'list', $root = 0)
{
    // 创建Tree
    $tree = array();
    if (is_array($list)) {
        // 创建基于主键的数组引用
        $refer = array();
        foreach ($list as $key => $data) {
            $refer[$data[$pk]] = &$list[$key];
        }
        foreach ($list as $key => $data) {
            // 判断是否存在parent
            $parentId = $data[$pid];
            if ($root == $parentId) {
                $tree[$data[$pk]] = &$list[$key];
            } else {
                if (isset($refer[$parentId])) {
                    $parent = &$refer[$parentId];
                    $parent[$child][$data[$pk]] = &$list[$key];
                }
            }
        }
    }
    return $tree;
}
/**
 * 时间戳格式化
 * @param int    $time
 * @param string $format 默认'Y-m-d H:i'，x代表毫秒
 * @return string 完整的时间显示
 */
function time_format($time = NULL, $format = 'Y-m-d H:i:s')
{
    $usec = $time = $time === null ? '' : $time;
    if (strpos($time, '.')!==false) {
        list($usec, $sec) = explode(".", $time);
    } else {
        $sec = 0;
    }

    return $time != '' ? str_replace('x', $sec, date($format, intval($usec))) : '';
}

/**
 * 根据附件表的id返回url地址
 * 兼容两种存法：老数据存的是附件表 id；用文件管理器选出来的新数据直接存图片地址，
 * 这时原样返回，不用再查表
 * @param  [type] $id [description]
 */
function get_file($id)
{
    if ($id) {
        if (!is_numeric($id)) {
            //已经是图片地址（文件管理器选的），直接返回
            return $id;
        }
        $geturl = \think\facade\Db::name("file")->where(['id' => $id])->find();
        if ($geturl['status'] == 1) {
            //审核通过
            //获取签名的URL
            $url = $geturl['filepath'];
            return $url;
        } elseif ($geturl['status'] == 0) {
            //待审核
            return '/static/admin/images/nonepic360x360.jpg';
        } else {
            //不通过
            return '/static/admin/images/nonepic360x360.jpg';
        }
    }
    return false;
}

function get_file_list($dir)
{
	$list=[];
    if(is_dir($dir)){
        $info = opendir($dir);
        while (($file = readdir($info)) !== false) {
            //echo $file.'<br>';
			$pathinfo=pathinfo($file);
			if($pathinfo['extension']=='html'){   //只获取符合后缀的文件
				array_push($list, $pathinfo);
			}
        }
        closedir($info);
    }
	return $list;
}

/** 后台敏感操作的会话令牌，不接受 URL 参数传递。 */
function admin_csrf_token(): string
{
    $token = \think\facade\Session::get('admin_csrf_token');
    if (!is_string($token) || strlen($token) !== 64) {
        $token = bin2hex(random_bytes(32));
        \think\facade\Session::set('admin_csrf_token', $token);
    }
    return $token;
}
function valid_admin_csrf(): bool
{
    $token = request()->header('X-Miu-Csrf');
    return is_string($token) && hash_equals(admin_csrf_token(), $token);
}
//获取当前登录用户的信息
function get_login_user($key = "")
{
    $session_user = get_config('app.session_user');
    if (\think\facade\Session::has($session_user)) {
        $miu_user = \think\facade\Session::get($session_user);
        // 密码变更立即撤销旧会话，兼容既有数据库，无需新增字段。
        static $accounts = [];
        $uid = (int) ($miu_user['id'] ?? 0);
        if (!array_key_exists($uid, $accounts)) $accounts[$uid] = \think\facade\Db::name('User')->where('id', $uid)->where('status', 1)->field('password')->find();
        $account = $accounts[$uid];
        if (!$account || !is_string($miu_user['credential'] ?? null) || !hash_equals(hash('sha256', (string) $account['password']), $miu_user['credential'])) {
            \think\facade\Session::delete($session_user);
            return '';
        }
        if (!empty($key)) {
            if (isset($miu_user[$key])) {
                return $miu_user[$key];
            } else {
                return '';
            }
        } else {
            return $miu_user;
        }
    } else {
        return '';
    }
}

/**
 * 时间戳格式化
 * @param int    $time
 * @param string $format 默认'Y-m-d H:i:s'
 * @return string 完整的时间显示
 */
function to_date($time = NULL, $format = 'Y-m-d H:i:s')
{
	if(empty($time)){
		return '';
	}
	else{
		if (is_numeric($time)) {
		return date($format, intval($time));
		}
		else{
			return $time;
		}
	}
}

/**
 * 数字转中文（逐位），年份这种要写成「二〇二六」的地方用
 *
 * 只逐位替换、不做进位读法：2026 → 二〇二六（不是「两千零二十六」）。
 * 非数字字符原样保留，所以 '2026'、2026、'2026-10' 都能传。
 *
 * @param string|int $num
 * @return string
 */
function num_to_cn($num): string
{
    static $map = [
        '0' => '〇', '1' => '一', '2' => '二', '3' => '三', '4' => '四',
        '5' => '五', '6' => '六', '7' => '七', '8' => '八', '9' => '九',
    ];
    return strtr((string) $num, $map);
}

/**
 * 判断访客是否是蜘蛛
 */
function isRobot($except = '') {
    //没带 User-Agent 的请求（扫描器、curl 等）取不到这个头，兜个空串
    $ua = strtolower(Request::header('user-agent', ''));
    $botchar = "/(baidu|google|spider|soso|yahoo|sohu-search|yodao|robozilla|AhrefsBot)/i";
    $except ? $botchar = str_replace ( $except . '|', '', $botchar ) : '';
    if (preg_match ( $botchar, $ua )) {
       return true;
    }
    return false;
 }

if (!function_exists('getBrowserInfo')) {
	/**
	 * 解析 User-Agent，识别浏览器和操作系统
	 *
	 * 判断顺序很关键：
	 *  1. 先判爬虫/脚本，否则带 Chrome 头的采集器会被当成正常浏览器；
	 *  2. 再判微信、QQ、抖音这类 App 内置浏览器，它们的 UA 里同时带着
	 *     Chrome/Safari，排在后面就会被误判成 Chrome；
	 *  3. 最后才是 Chrome / Safari 兜底。
	 *
	 * @param string $ua User-Agent 原始字符串
	 * @return array ['browser' => '浏览器', 'os' => '操作系统']
	 */
	function getBrowserInfo($ua)
	{
		$data = [
			'browser' => '未知浏览器',
			'os'      => '未知系统',
		];

		$ua = trim((string) $ua);
		if ($ua === '') {
			return $data;
		}

		//UA 里大小写并不统一，统一转小写再比对
		$s = strtolower($ua);

		//命中任意一个关键字即返回 true
		$has = function ($keys) use ($s) {
			foreach ((array) $keys as $key) {
				if (strpos($s, $key) !== false) {
					return true;
				}
			}
			return false;
		};

		/* ---------- 浏览器 ---------- */
		if ($has(['googlebot', 'baiduspider', 'bingbot', 'msnbot', 'sogou web spider', 'sogou spider', '360spider', 'sosospider', 'yisouspider', 'shenmaspider', 'haosouspider', 'yandexbot', 'applebot', 'bytespider', 'petalbot', 'duckduckbot', 'slurp', 'toutiaospider', 'quarkbot', 'exabot', 'teoma', 'yodaobot'])) {
			//搜索引擎的蜘蛛
			$data['browser'] = '蜘蛛';
		} elseif ($has(['bot', 'spider', 'crawler', 'curl/', 'wget/', 'python-requests', 'python-urllib', 'go-http-client', 'java/', 'okhttp', 'postmanruntime', 'axios/', 'node-fetch', 'facebookexternalhit'])) {
			//其它采集器、脚本请求
			$data['browser'] = '采集器';
		} elseif ($has(['wxwork'])) {
			$data['browser'] = '企业微信内置浏览器';
		} elseif ($has(['micromessenger'])) {
			$data['browser'] = $has(['miniprogram']) ? '微信小程序' : '微信内置浏览器';
		} elseif ($has(['dingtalk'])) {
			$data['browser'] = '钉钉内置浏览器';
		} elseif ($has(['alipayclient'])) {
			$data['browser'] = '支付宝内置浏览器';
		} elseif ($has(['weibo'])) {
			$data['browser'] = '微博内置浏览器';
		} elseif ($has(['aweme', 'bytedancewebview', 'toutiaomicroapp'])) {
			$data['browser'] = '抖音内置浏览器';
		} elseif ($has(['baiduboxapp', 'baidubrowser', 'bidubrowser'])) {
			$data['browser'] = '百度App/浏览器';
		} elseif ($has(['mqqbrowser'])) {
			//mqqbrowser 里包含 qqbrowser，必须排在前面
			$data['browser'] = 'QQ手机浏览器';
		} elseif ($has(['qqbrowser'])) {
			$data['browser'] = 'QQ浏览器';
		} elseif ($has(['ucbrowser', 'ucweb'])) {
			$data['browser'] = 'UC浏览器';
		} elseif ($has(['quark'])) {
			$data['browser'] = '夸克浏览器';
		} elseif ($has(['miuibrowser'])) {
			$data['browser'] = '小米浏览器';
		} elseif ($has(['huaweibrowser'])) {
			$data['browser'] = '华为浏览器';
		} elseif ($has(['vivobrowser'])) {
			$data['browser'] = 'vivo浏览器';
		} elseif ($has(['heytapbrowser', 'oppobrowser'])) {
			$data['browser'] = 'OPPO浏览器';
		} elseif ($has(['samsungbrowser'])) {
			$data['browser'] = '三星浏览器';
		} elseif ($has(['sogoumobilebrowser', 'metasr', 'se 2.x'])) {
			$data['browser'] = '搜狗浏览器';
		} elseif ($has(['360se', '360ee', 'qihoobrowser'])) {
			$data['browser'] = '360浏览器';
		} elseif ($has(['maxthon'])) {
			$data['browser'] = '傲游浏览器';
		} elseif ($has(['lbbrowser'])) {
			$data['browser'] = '猎豹浏览器';
		} elseif ($has(['theworld'])) {
			$data['browser'] = '世界之窗浏览器';
		} elseif ($has(['2345explorer', '2345chrome'])) {
			$data['browser'] = '2345浏览器';
		} elseif ($has(['electron'])) {
			$data['browser'] = 'Electron应用';
		} elseif ($has(['firefox', 'fxios'])) {
			$data['browser'] = 'Firefox火狐';
		} elseif ($has(['opr/', 'opios', 'opera'])) {
			$data['browser'] = 'Opera欧朋';
		} elseif ($has(['edg'])) {
			//Edge 的 UA 里带着 Chrome，必须排在 Chrome 前面
			$data['browser'] = 'Edge';
		} elseif ($has(['vivaldi'])) {
			$data['browser'] = 'Vivaldi';
		} elseif ($has(['yabrowser'])) {
			$data['browser'] = 'Yandex浏览器';
		} elseif ($has(['msie', 'trident'])) {
			$data['browser'] = 'IE浏览器';
		} elseif ($has(['chromium'])) {
			$data['browser'] = 'Chromium';
		} elseif ($has(['crios', 'chrome'])) {
			$data['browser'] = 'Chrome谷歌';
		} elseif ($has(['safari'])) {
			$data['browser'] = 'Safari苹果';
		}

		/* ---------- 操作系统 ---------- */
		if ($has(['windows nt 10.0'])) {
			//Win10 和 Win11 的 UA 完全一样，没法区分，只能写在一起
			$data['os'] = 'Windows10/11';
		} elseif ($has(['windows nt 6.3'])) {
			$data['os'] = 'Windows8.1';
		} elseif ($has(['windows nt 6.2'])) {
			$data['os'] = 'Windows8';
		} elseif ($has(['windows nt 6.1'])) {
			$data['os'] = 'Windows7';
		} elseif ($has(['windows nt 6.0'])) {
			$data['os'] = 'Windows Vista';
		} elseif ($has(['windows nt 5.2'])) {
			$data['os'] = 'WindowsXP/2003';
		} elseif ($has(['windows nt 5.1', 'windows xp'])) {
			$data['os'] = 'WindowsXP';
		} elseif ($has(['windows phone'])) {
			//WindowsPhone 的 UA 里还带着 Android，必须排在 Android 前面
			$data['os'] = 'WindowsPhone';
		} elseif ($has(['windows'])) {
			$data['os'] = 'Windows其它';
		} elseif ($has(['harmonyos', 'openharmony'])) {
			//鸿蒙的 UA 里也带 Android，必须排在 Android 前面
			$data['os'] = '鸿蒙HarmonyOS';
		} elseif ($has(['android'])) {
			$data['os'] = '安卓';
		} elseif ($has(['iphone', 'ipod'])) {
			$data['os'] = 'IOS苹果手机';
		} elseif ($has(['ipad'])) {
			$data['os'] = 'iPadOS苹果平板';
		} elseif ($has(['macintosh', 'mac os x'])) {
			//iPadOS 13 起会把自己伪装成 Mac，特征是同时带着 Macintosh 和 Mobile
			$data['os'] = $has(['mobile']) ? 'iPadOS苹果平板' : 'MacOS';
		} elseif ($has(['cros'])) {
			$data['os'] = 'ChromeOS';
		} elseif ($has(['ubuntu'])) {
			$data['os'] = 'Ubuntu';
		} elseif ($has(['debian'])) {
			$data['os'] = 'Debian';
		} elseif ($has(['fedora'])) {
			$data['os'] = 'Fedora';
		} elseif ($has(['centos', 'red hat'])) {
			$data['os'] = 'CentOS/RedHat';
		} elseif ($has(['freebsd'])) {
			$data['os'] = 'FreeBSD';
		} elseif ($has(['linux'])) {
			$data['os'] = 'Linux';
		} elseif ($has(['unix'])) {
			$data['os'] = 'Unix';
		}

		return $data;
	}
}

/**
 * 客户操作日志
 * @param string $type 操作类型 login reg add edit view delete down join sign play order pay
 * @param string    $param_str 操作内容
 * @param int    $param_id 操作内容id
 * @param array  $param 提交的参数
 */
function redact_log_secrets($value)
{
	if (!is_array($value)) {
		return $value;
	}
	foreach ($value as $key => $item) {
		if (is_string($key) && preg_match('/password|passwd|pwd|secret|secrect|salt|token|authorization|api[_-]?key|access[_-]?key|captcha|verification[_-]?code|email[_-]?code|sms[_-]?code|workspace[_-]?id/i', $key)) {
			$value[$key] = '[已隐藏]';
		} elseif (is_array($item)) {
			$value[$key] = redact_log_secrets($item);
		}
	}
	return $value;
}

function add_user_log($type, $param_str = '', $param_id = 0, $param = [])
{
	if ((string) get_system_config('user_log_open') !== '1') {
		return;
	}

	$request = request();
	$title = '未知操作';
	$type_action = get_config('log.user_action');
	if($type_action[$type]){
		$title = $type_action[$type];
	}
    if ($type == 'login') {
        $login_user = \think\facade\Db::name('User')->where(array('id' => $param_id))->find();
        if ($login_user['nickname'] == '') {
            $login_user['nickname'] = $login_user['name'];
        }
        if ($login_user['nickname'] == '') {
            $login_user['nickname'] = $login_user['username'];
        }
    } else {
        $login_user = get_login_user();
        if (empty($login_user)) {
            $login_user = [];
            $login_user['id'] = 0;
            $login_user['nickname'] = '游客';
            if(isRobot()){
                $login_user['nickname'] = '蜘蛛';
                $type = 'spider';
                $title = '爬行';
            }
        } else {
            if ($login_user['nickname'] == '') {
                $login_user['nickname'] = $login_user['username'];
            }
        }
    }
    $content = $login_user['nickname'] . '在' . date('Y-m-d H:i:s') . '执行了' . $title . '操作';
    if ($param_str != '') {
        $content = $login_user['nickname'] . '在' . date('Y-m-d H:i:s') . $title . '了' . $param_str;
    }
    $data = [];
    $data['uid'] = $login_user['id'];
    $data['nickname'] = $login_user['nickname'];
    $data['type'] = $type;
    $data['title'] = $title;
    $data['content'] = $content;
    $data['param_id'] = $param_id;
	$data['param'] = json_encode(redact_log_secrets($param));
	$data['module'] = strtolower(app('http')->getName());
    $data['controller'] = strtolower(app('request')->controller());
    $data['function'] = strtolower(app('request')->action());
    $data['ip'] = app('request')->ip();
    //访问 URL 保留用于审计，但不能把密码、令牌或验证码记入日志。
	$data['url'] = preg_replace('/([?&](?:access[_-]?token|refresh[_-]?token|reset[_-]?token|token|password|passwd|pwd|captcha|(?:email|sms|verification)[_-]?code|api[_-]?key|secret|secrect)=)[^&]*/i', '$1[redacted]', $request->url(true));
    $ua = Request::header('user-agent', '');
    $browserInfo = getBrowserInfo($ua);
    $data['browser'] = $browserInfo['browser'];
    $data['os']      = $browserInfo['os'];
    $data['create_time'] = time();
    \think\facade\Db::name('UserLog')->strict(false)->field(true)->insert($data);
    if ((int) $data['uid'] > 0 && $type === 'login') {
        Cache::tag('homeUserLog')->clear();
    }
}

/** 找回密码邮件的默认正文；后台可以在网站配置的邮箱配置页替换。 */
function default_password_reset_email_template(): string
{
    return <<<'HTML'
<div style="max-width:600px;margin:0 auto;padding:32px;color:#303630;font:15px/1.8 Arial,'Microsoft YaHei',sans-serif">
  <h2 style="margin:0 0 20px">重置登录密码</h2>
  <p>你好，%username%：</p>
  <p>我们收到了重置 %site_name% 会员账号密码的请求。请点击下面的链接设置新密码：</p>
  <p style="margin:26px 0"><a href="%reset_url%" style="display:inline-block;padding:11px 22px;background:#26352b;color:#fff;text-decoration:none">重置密码</a></p>
  <p>如果按钮无法打开，请复制下面的链接到浏览器中：</p>
  <p style="word-break:break-all;color:#596159">%reset_url_text%</p>
  <p>链接将在 %expire_minutes% 分钟后失效，且只能使用一次。</p>
  <p>如果这不是你发起的请求，请忽略这封邮件。</p>
  <p style="margin-top:28px;color:#858a85">%site_name%</p>
</div>
HTML;
}

/** 注册及其他邮箱验证场景共用的默认验证码邮件正文。 */
function default_email_verify_template(): string
{
    return <<<'HTML'
<div style="max-width:600px;margin:0 auto;padding:32px;color:#303630;font:15px/1.8 Arial,'Microsoft YaHei',sans-serif">
  <h2 style="margin:0 0 20px">邮箱验证码</h2>
  <p>你正在验证 %site_name% 账号的邮箱。</p>
  <p>邮箱：%email%</p>
  <p style="font-size:28px;font-weight:bold;letter-spacing:8px">%code%</p>
  <p>验证码 %expire_minutes% 分钟内有效，请勿转发给他人。</p>
  <p style="margin-top:28px;color:#858a85">%site_name%</p>
</div>
HTML;
}

/** 兼容旧调用；新验证码邮件统一使用 default_email_verify_template()。 */
function default_register_email_template(): string
{
    return default_email_verify_template();
}

/**
 * 邮件发送
 * @param $to    接收人
 * @param string $subject 邮件标题
 * @param string $content 邮件内容(html模板渲染后的内容)
 * @throws Exception
 * @throws phpmailerException
 */
function send_email($to, $subject = '', $content = '')
{
    $mail = new PHPMailer\PHPMailer\PHPMailer();
    //配置已合并到 name=global 一行，统一走 helper 取
    $config = get_system_config() ?: [];

    $mail->CharSet = 'UTF-8'; //设定邮件编码，默认ISO-8859-1，如果发中文此项必须设置，否则乱码
    $mail->isSMTP();
    $mail->SMTPDebug = 0;

    //调试输出格式
    //$mail->Debugoutput = 'html';
    //smtp服务器
    $mail->Host = $config['smtp'];
    //端口 - likely to be 25, 465 or 587
    $mail->Port = $config['smtp_port'];
    if($mail->Port == '465'){
        $mail->SMTPSecure = 'ssl';// 使用安全协议
    }    
    //Whether to use SMTP authentication
    $mail->SMTPAuth = true;
    //发送邮箱
    $mail->Username = $config['smtp_user'];
    //密码
    $mail->Password = $config['smtp_pwd'];
    //Set who the message is to be sent from
    $mail->setFrom($config['email'], $config['from']);
    //回复地址
    //$mail->addReplyTo('replyto@example.com', 'First Last');
    //接收邮件方
    if (is_array($to)) {
        foreach ($to as $v) {
            $mail->addAddress($v);
        }
    } else {
        $mail->addAddress($to);
    }

    $mail->isHTML(true);// send as HTML
    //标题
    $mail->Subject = $subject;
    //HTML内容转换
    $mail->msgHTML($content);
    $status = $mail->send();
    if ($status) {
        return true;
    } else {
//        echo "Mailer Error: ".$mail->ErrorInfo;// 输出错误信息
//        die;
        return false;
    }
}

/*
* 下划线转驼峰
* 思路:
* step1.原字符串转小写,原字符串中的分隔符用空格替换,在字符串开头加上分隔符
* step2.将字符串中每个单词的首字母转换为大写,再去空格,去字符串首部附加的分隔符.
*/
function camelize($uncamelized_words,$separator='_')
{
	$uncamelized_words = $separator. str_replace($separator, " ", strtolower($uncamelized_words));
	return ltrim(str_replace(" ", "", ucwords($uncamelized_words)), $separator );
}

/**
* 驼峰命名转下划线命名
* 思路:
* 小写和大写紧挨一起的地方,加上分隔符,然后全部转小写
*/
function uncamelize($camelCaps,$separator='_')
{
	return strtolower(preg_replace('/([a-z])([A-Z])/', "$1" . $separator . "$2", $camelCaps));
}
/**
 * 提取内容摘要，去除html，取前$length个字（中英文都算1字）
 * @param string $content 富文本内容
 * @param int $length 截取长度
 * @return string
 */
function get_desc(string $content, int $length = 200): string
{
    // HTML实体转义还原
    $content = html_entity_decode($content, ENT_QUOTES, 'UTF-8');
    // 去除所有HTML标签
    $content = strip_tags($content);
    // 去除空白换行、多个空格
    $content = preg_replace('/\s+/', ' ', $content);
    // 首尾去空格
    $content = trim($content);
    // 截取前200字，utf-8
    $summary = mb_substr($content, 0, $length, 'UTF-8');
    // 如果原文超过长度，末尾加省略号
    if (mb_strlen($content) > $length) {
        $summary .= '...';
    }
    return $summary;
}
/**
 * 判断是否是合法的http/https网址
 * @param string $url
 * @return bool
 */
function is_valid_url(string $url): bool
{
    $url = trim($url);
    // 必须是http或https开头，并且通过url校验
    return filter_var($url, FILTER_VALIDATE_URL)
        && preg_match('/^https?:\/\//i', $url);
}
if (!function_exists('is_mobile')) {
    /**
     * 判断是否为移动端访问
     */
    function is_mobile(): bool
    {
        if (isset($_SERVER['HTTP_VIA']) && stristr($_SERVER['HTTP_VIA'], "wap")) {
            return true;
        }
        if (isset($_SERVER['HTTP_ACCEPT']) && strpos(strtoupper($_SERVER['HTTP_ACCEPT']), "VND.WAP.WML")) {
            return true;
        }
        if (isset($_SERVER['HTTP_X_WAP_PROFILE']) || isset($_SERVER['HTTP_PROFILE'])) {
            return true;
        }
        $userAgent = Request::header('user-agent', '');
        if ($userAgent !== '') {
            $mobile_agents = [
                'iphone', 'android', 'phone', 'mobile', 'wap', 'netfront', 'java', 'opera mobi',
                'opera mini', 'ucweb', 'windows ce', 'symbian', 'series', 'webos', 'sony',
                'blackberry', 'dopod', 'nokia', 'samsung', 'palmsource', 'xda', 'pieplus',
                'meizu', 'midp', 'cldc', 'motorola', 'foma', 'docomo', 'up.browser',
                'up.link', 'blazer', 'helio', 'hosin', 'huawei', 'novarra', 'coolpad',
                'webmate', 'alcatel', 'amoi', 'ktouch', 'nexian', 'ericsson', 'philips',
                'sagem', 'wellcom', 'bunjalloo', 'maui', 'smartphone', 'iemobile', 'spice',
                'bird', 'zte-', 'longcos', 'pantech', 'gionee', 'portalmmm', 'jig browser',
                'hiptop', 'benq', 'haier', '^lct', '320x320', '240x320', '176x220'
            ];
            $user_agent = strtolower($userAgent);
            foreach ($mobile_agents as $agent) {
                if (strpos($user_agent, $agent) !== false) {
                    return true;
                }
            }
        }
        return false;
    }
}

/* ==================== 留言表单（后台「留言」模块 + 前台表单） ==================== */
/*
 * 自由表单的数据表：
 *   cms_form              表单
 *   cms_form_field        表单字段，用 form_id 归属
 *   cms_form_message      留言主表
 *   cms_form_message_attr 留言的字段值
 *
 * 字段控件类型编号：
 *   0=单行文本 1=下拉框 2=多行文本 3=单选框 4=多选框
 *   5=单张图 6=手机号码 7=Email邮箱 8=附件 9=区域联动 10=时间类型 11=多张图
 * 目前前台渲染只实现了 0/1/2/3/4/6/7/10 —— 图片、附件、区域联动需要上传/地区组件，
 * 暂时没做，后台也不提供这几个选项（见 form_input_types()）。
 */

/**
 * 可选的字段控件类型（后台「字段类型」单选框用，前台渲染也认这套编号）
 *
 * @return array 编号 => 中文名
 */
function form_input_types(): array
{
    return [
        0  => '单行文本',
        1  => '下拉框',
        2  => '多行文本',
        3  => '单选框',
        4  => '多选框',
        6  => '手机号码',
        7  => 'Email邮箱',
        10 => '时间类型',
    ];
}

/**
 * 把字段的「可选值列表」拆成数组
 *
 * 后台使用 textarea 填写，一行一个值。
 *
 * @param string $attrValues
 * @return array
 */
function form_field_options(string $attrValues): array
{
    $list = [];
    foreach (preg_split('/\r\n|\r|\n/', $attrValues) as $line) {
        $line = trim($line);
        if ($line !== '') {
            $list[] = $line;
        }
    }

    return $list;
}

/**
 * 未读留言数（后台右上角「消息中心」的红色角标用）
 *
 * 缓存 60 秒：后台每开一个页面都会读它，不能每次都查库；
 * 有新留言 / 标记已读 / 删除留言时都会 clear_cache('formMessage') 立刻刷新。
 *
 * @return int
 */
function form_unread_count(): int
{
    $count = get_cache('formUnreadCount');
    if ($count === null) {
        try {
            $count = (int) \think\facade\Db::name('form_message')->where('is_read', 0)->count();
        } catch (\Throwable $e) {
            //表还没建（比如还没升级到这一版）就当 0，不要影响后台打开
            $count = 0;
        }
        \think\facade\Cache::tag('formMessage')->set('formUnreadCount', $count, 60);
    }

    return (int) $count;
}

/* ==================== 前台 URL（动态 / 伪静态 / 静态） ==================== */
/*
 * 三种模式由后台「SEO → URL配置」控制，配置存在 cms_config 表 name=seo 的那一行里。
 * 页面联动与下列配置键及取值保持一致。
 *
 * 【后台可配】
 *   url_pseudo              1=动态URL  2=静态页面  3=伪静态化
 *   url_html_arcdir         静态页面保存目录（留空 = 根目录）
 *   url_html_listname       静态：栏目页面路径 1/2/3
 *   url_html_pagename       静态：文档页面路径 1/2/3
 *   url_html_tagname        静态：标签页面路径 1=tag/ID.html 2=tag/ID/
 *   url_rewrite_format      伪静态：栏目URL 1=目录名称  2=模型标识
 *   url_rewrite_view_format 伪静态：文档URL 1=父目录  3=当前目录  4=父目录/当前目录/
 *   url_liststitle_format   栏目标题 3=栏目名称 1=.._网站名称 4=.._第N页 2=.._第N页_网站名称
 *   url_tagtitle_format     标签标题 3=标签名称 1=.._网站名称 4=.._第N页 2=.._第N页_网站名称
 *   url_viewtitle_format    文档标题 1=内容标题 2=.._网站名称 3=.._栏目名称_网站名称
 *   url_title_symbol        标题间连接符
 *   url_pub_* / url_edit_*  静态：发布/编辑文档后更新哪些页面
 *   url_del_*               动态/伪静态：发布/编辑文档时清除哪些缓存
 *   url_no_index            去除index.php 1=开启 0=关闭
 *
 * 【固定规则】以下规则不提供后台配置开关
 *   suffix    = html   伪静态/静态的文件后缀
 *   separator = -      URL 连接符
 *   栏目地址固定「目录形式」：/目录/，第2页 /目录/p2.html
 *   文档文件名固定「{文档ID}.html」；标签页可选「tag/{标签ID}.html」或目录形式
 *
 * 下面 url_config() 会把上面这些键**换算成内部统一表示**（mode / nav_name / doc_path …），
 * 前台其它函数只认内部表示，这样后台改取值口径时不用满地改。
 *
 * 生成的地址长这样（栏目 2004=博客资讯/bokezixun；一级栏目「产品」/chanpin 下二级「手机」/shouji）：
 *   动态    /home/nav/index?id=2004                    /home/detail/index?id=127
 *   伪静态  /bokezixun/  第2页 /bokezixun/p2.html    /chanpin/shouji/127.html
 *   静态    /html/bokezixun/ ...                        /html/chanpin/shouji/127.html
 *
 * 栏目地址一律带**完整祖先目录链**（/一级/二级/三级/）；
 * 文档地址带哪几级由「文档URL」决定。
 *
 * 静态模式下的首页、栏目、文档和标签页由「SEO → 页面生成」写成真实 HTML 文件。
 */

/**
 * 读取 URL 配置
 *
 * 把后台存的键换算成内部统一表示，并做白名单校验（配置项没保存过、值被改成非法值
 * 都不会报错，直接回退默认）。
 *
 * @return array
 */
function url_config(): array
{
    static $cfg = null;
    if ($cfg !== null) {
        return $cfg;
    }

    //URL 规则存在 seo 组；已移到「网站配置」的四个开关优先从 global 读取。
    $all = get_config_group('seo');
    $global = get_config_group('global');
    foreach (['url_no_index', 'url_del_home', 'url_del_nav', 'url_del_side'] as $key) {
        if (array_key_exists($key, $global)) {
            $all[$key] = $global[$key];
        }
    }

    //取整数配置项，不在允许范围里就回退默认值
    $pickInt = function ($key, $default, array $allow) use ($all) {
        $val = $all[$key] ?? null;
        if ($val === null || $val === '') {
            return $default;
        }
        $val = (int) $val;
        return in_array($val, $allow, true) ? $val : $default;
    };

    //静态保存目录：允许多级（a/b），禁止 .. 和空段；留空 = 存到根目录
    $arcdir = trim(str_replace('\\', '/', (string) ($all['url_html_arcdir'] ?? '')), '/');
    if ($arcdir !== '' && (strpos($arcdir, '..') !== false || !preg_match('#^[A-Za-z0-9_\-/]{1,32}$#', $arcdir))) {
        $arcdir = 'html';
    }

    //标题间连接符：不合法就回退 -
    $symbol = trim((string) ($all['url_title_symbol'] ?? ''));
    if (!preg_match('/^[A-Za-z0-9_\-]{1,3}$/', $symbol)) {
        $symbol = '_';
    }

    //后台取值 → 内部表示
    $pseudo        = $pickInt('url_pseudo', 3, [1, 2, 3]);
    $rewriteFormat = $pickInt('url_rewrite_format', 1, [1, 2]);
    $viewFormat    = $pickInt('url_rewrite_view_format', 3, [1, 3, 4]);
    $liststitle    = $pickInt('url_liststitle_format', 2, [1, 2, 3, 4]);
    //旧配置未保存标签规则时，沿用栏目规则，保持升级前的输出不变。
    $tagtitle      = $pickInt('url_tagtitle_format', $liststitle, [1, 2, 3, 4]);
    $viewtitle     = $pickInt('url_viewtitle_format', 2, [1, 2, 3]);

    $cfg = [
        //1动态 → 0，3伪静态 → 1，2静态 → 2
        'mode'      => [1 => 0, 2 => 2, 3 => 1][$pseudo],

        //栏目URL：1目录名称 2模型标识（内部跟后台取值一致）
        'nav_name'  => $rewriteFormat,

        //文档URL：1父目录 3当前目录 4父目录+当前 → 内部 1父目录 2当前目录 3父目录+当前
        'doc_path'  => [1 => 1, 3 => 2, 4 => 3][$viewFormat],

        //栏目标题：3栏目名称 1.._网站名 4.._第N页 2.._第N页_网站名 → 内部 1/2/3/4
        'nav_title' => [3 => 1, 1 => 2, 4 => 3, 2 => 4][$liststitle],

        //标签标题：格式与栏目标题相同，旧配置由上方回退到栏目标题规则
        'tag_title' => [3 => 1, 1 => 2, 4 => 3, 2 => 4][$tagtitle],

        //文档标题：内部跟后台取值一致
        'doc_title' => $viewtitle,

        'title_sep' => $symbol,

        //发布/编辑文档时清哪些缓存（动态、伪静态模式用）
        'del_home'  => $pickInt('url_del_home', 1, [0, 1]),
        'del_nav'   => $pickInt('url_del_nav', 1, [0, 1]),
        'del_side'  => $pickInt('url_del_side', 1, [0, 1]),

        //静态模式下发布/编辑文档后更新哪些页面（静态生成那轮用）
        'pub_home'  => $pickInt('url_pub_home', 1, [0, 1]),
        'pub_nav'   => $pickInt('url_pub_nav', 1, [0, 1]),
        'pub_side'  => $pickInt('url_pub_side', 1, [0, 1]),
        'edit_home' => $pickInt('url_edit_home', 1, [0, 1]),
        'edit_nav'  => $pickInt('url_edit_nav', 1, [0, 1]),
        'edit_side' => $pickInt('url_edit_side', 1, [0, 1]),

        'no_index'  => $pickInt('url_no_index', 1, [0, 1]),

        //静态模式的页面路径规则（静态生成那轮用）
        'html_listname' => $pickInt('url_html_listname', 2, [1, 2, 3]),
        'html_pagename' => $pickInt('url_html_pagename', 2, [1, 2, 3]),
        'html_tagname'  => $pickInt('url_html_tagname', 1, [1, 2]),

        // 固定后缀和连接符，不提供后台配置开关。
        'suffix'    => 'html',
        'separator' => '-',
        'save_dir'  => $arcdir,   //''=根目录
    ];

    return $cfg;
}

/**
 * 动态URL前面的入口前缀（对应后台「去除index.php」开关）
 *
 *   开启（no_index=1）→ ''            地址是 /home/nav/index?id=2004
 *   关闭（no_index=0）→ '/index.php'  地址是 /index.php/home/nav/index?id=2004
 *   当前模板使用非默认语言时，会在入口脚本后增加 /<locale>。
 *
 * 两种都靠 public/.htaccess（或 nginx 规则）转发，去掉 index.php 需要服务器
 * 开启了重写；去掉更短更好看，所以默认开启。
 *
 * @return string
 */
function url_entry(): string
{
    $entry  = url_config()['no_index'] ? '' : '/index.php';
    $locale = class_exists('app\\home\\service\\TemplateI18n')
        ? \app\home\service\TemplateI18n::urlSegment()
        : '';

    //index.php 模式把语言段放在入口脚本之后：/index.php/en-us/home/...
    return $entry . $locale;
}

/**
 * 伪静态/静态模式下的语言和「页面保存目录」前缀
 *
 * 后台留空 = 存到根目录，这时不加前缀（不能拼出 // 这种地址）。
 * 动态模式由 url_entry() 处理语言前缀。
 *
 * @return string 如 '/html'、'/html/en-us'，或空串
 */
function url_static_prefix(): string
{
    $cfg = url_config();
    if ($cfg['mode'] === 0) {
        return '';
    }

    $locale = class_exists('app\\home\\service\\TemplateI18n')
        ? \app\home\service\TemplateI18n::urlSegment()
        : '';
    $saveDir = $cfg['mode'] === 2 && $cfg['save_dir'] !== ''
        ? '/' . $cfg['save_dir']
        : '';

    //静态页的非默认语言版本放进页面保存目录，例如 /html/en-us/、/html/zh-tw/。
    if ($cfg['mode'] === 2 && $saveDir !== '' && $locale !== '') {
        return $saveDir . $locale;
    }

    return $locale . $saveDir;
}

/**
 * 当前栏目id（前台菜单高亮用）
 *
 * 动态URL靠 id 参数判断，伪静态/静态URL没有这个参数，
 * 所以控制器解析出栏目后要主动调一次 miu_current_nav_id($id) 告诉它。
 *
 * @param int $set 传 >=0 表示设置，传 -1 表示只读取
 * @return int
 */
function miu_current_nav_id(int $set = -1): int
{
    static $id = 0;
    if ($set >= 0) {
        $id = $set;
    }
    return $id;
}

/**
 * 前台栏目树（只含「显示中」的栏目，排除回收站、禁用、隐藏）
 *
 * 前台菜单/列表读它；后台文档列表要算「前台详情页地址」也得用同一份栏目数据，
 * 所以放在全局 common.php，不再挂在 home 应用里。
 * 缓存标记 homeNav —— 后台改栏目时会 clear_cache('homeNav')，两个应用一起失效。
 *
 * @return array
 */
function miu_nav_tree(): array
{
    $locale = class_exists('app\\home\\service\\ContentI18n')
        ? \app\home\service\ContentI18n::locale()
        : 'zh-cn';
    $cacheKey = $locale === 'zh-cn' ? 'homeNav' : 'homeNav_' . str_replace('-', '_', $locale);
    if (!get_cache($cacheKey)) {
        //回收站（is_del=1）、禁用（status=0）、隐藏（is_show=1）的栏目都不在前台展示
        $list = \think\facade\Db::name('Nav')->where(['status' => 1, 'is_del' => 0, 'is_show' => 0])->order('sort asc')->select()->toArray();
        $list = \app\home\service\ContentI18n::overlayRows('nav', $list);
        $nav  = list_to_tree($list);
        \think\facade\Cache::tag('homeNav')->set($cacheKey, $nav);
    }
    return get_cache($cacheKey);
}

/**
 * 前台栏目树（含隐藏栏目）
 *
 * 和 miu_nav_tree() 的区别：不按 is_show 过滤，隐藏栏目（is_show=1）也在里面。
 * 隐藏只是「不出现在导航/列表里」，栏目本身还在 —— 它的页面能访问、
 * 它下面的文档也照常，所以「按 id 取栏目」「算栏目地址」都得用它，
 * 否则隐藏栏目的祖先链是断的、地址会拼成 //。
 * 仍然排除回收站（is_del=1）和禁用（status=0）的栏目。
 * 缓存标记同样是 homeNav，后台改栏目时两个一起失效。
 *
 * @return array
 */
function miu_nav_tree_all(): array
{
    $locale = class_exists('app\\home\\service\\ContentI18n')
        ? \app\home\service\ContentI18n::locale()
        : 'zh-cn';
    $cacheKey = $locale === 'zh-cn' ? 'homeNavAll' : 'homeNavAll_' . str_replace('-', '_', $locale);
    if (!get_cache($cacheKey)) {
        $list = \think\facade\Db::name('Nav')->where(['status' => 1, 'is_del' => 0])->order('sort asc')->select()->toArray();
        $list = \app\home\service\ContentI18n::overlayRows('nav', $list);
        $nav  = list_to_tree($list);
        \think\facade\Cache::tag('homeNav')->set($cacheKey, $nav);
    }
    return get_cache($cacheKey);
}

/**
 * 栏目树拍平成 [栏目id => 栏目]，只做拍平，不算任何链接
 *
 * 含隐藏栏目（is_show=1）：隐藏只是「不出现在导航/列表里」，栏目本身和它的页面都照常存在，
 * 所以路由解析（/目录/ 认栏目）、算栏目地址（要往上找父级）都得看得见它。
 * 前台菜单和列表不用这张表 —— 它们走 miu_nav_items()，is_show 的过滤在那里面。
 *
 * 为什么要单独来一个（而不是直接用 miu_nav_items()）：
 * miu_nav_items() 会给每个栏目算链接（调 nav_url），而 nav_url 又要算栏目路径（要往上找父栏目），
 * 两者互相调用会**死循环**。所以所有「算 URL 路径」的地方都走这个原始版本，链路就断开了。
 *
 * @return array
 */
function nav_flat_map(): array
{
    static $maps = [];
    $locale = class_exists('app\\home\\service\\ContentI18n')
        ? \app\home\service\ContentI18n::locale()
        : 'zh-cn';
    if (!isset($maps[$locale])) {
        $map  = [];
        $walk = function ($list) use (&$walk, &$map) {
            foreach ($list as $item) {
                $id = (int) ($item['id'] ?? 0);
                if ($id > 0) {
                    $map[$id] = $item;
                }
                if (!empty($item['list']) && is_array($item['list'])) {
                    $walk($item['list']);
                }
            }
        };
        $walk(miu_nav_tree_all());
        $maps[$locale] = $map;
    }
    return $maps[$locale];
}

/**
 * 取栏目的上级链（含自己，从一级栏目开始）—— 不依赖 miu_nav_items()，专供拼 URL 用
 *
 * @param int $id
 * @return array
 */
function nav_chain(int $id): array
{
    $items = nav_flat_map();
    $chain = [];
    $cur   = (int) $id;
    //栏目表理论上不会成环，但 pid 被人工改坏时用次数兜底，避免死循环
    for ($i = 0; $cur && isset($items[$cur]) && $i < 32; $i++) {
        array_unshift($chain, $items[$cur]);
        $cur = (int) $items[$cur]['pid'];
    }
    return $chain;
}

/**
 * 栏目在 URL 里用的那一段（**不含祖先链**，如 bokezixun / article / nav2004）
 *
 * 两种命名规则（后台「栏目URL」）：
 *   目录名称  用栏目管理里填的「目录名称」(title_en)，如 bokezixun
 *   模型标识  用栏目所属模型的标识 (cms_channel.nid)，如 article
 * 两种都可能为空或含中文等非法字符，一律退化成 nav{栏目ID}。
 *
 * @param array $nav
 * @return string
 */
function nav_dir_raw(array $nav): string
{
    $id = (int) ($nav['id'] ?? 0);

    //模型标识：用栏目所属模型的标识（cms_channel.nid）
    //目录形式下同一模型的多个栏目会算出同一个段名（比如都是 article），
    //所以 nid 取不到时退回带 id 的 nav{栏目ID}，保证唯一
    if (url_config()['nav_name'] === 2) {
        $nid = nav_nid_seg($nav);
        return $nid === 'nav' ? ('nav' . $id) : $nid;
    }

    //目录名称（默认）
    return nav_alias_seg($nav);
}

/**
 * 栏目别名（栏目管理里填的「目录名称」title_en）
 *
 * 为空或含中文等非法字符时退化成 nav{栏目ID}，保证一定能拼出一个合法路径段。
 *
 * @param array $nav
 * @return string
 */
function nav_alias_seg(array $nav): string
{
    $id   = (int) ($nav['id'] ?? 0);
    $name = trim((string) ($nav['title_en'] ?? ''));

    return ($name !== '' && preg_match('/^[A-Za-z0-9_\-]{1,64}$/', $name)) ? $name : ('nav' . $id);
}

/**
 * 栏目所属模型的标识（cms_channel.nid），如 article
 *
 * @param array $nav
 * @return string 取不到返回 'nav'
 */
function nav_nid_seg(array $nav): string
{
    $channelId = (int) ($nav['channel_id'] ?? 0);
    if ($channelId > 0) {
        $nid = trim((string) (get_channel($channelId)['nid'] ?? ''));
        if ($nid !== '' && preg_match('/^[A-Za-z0-9_\-]{1,64}$/', $nid)) {
            return $nid;
        }
    }

    return 'nav';
}

/**
 * 「模型标识」模式下栏目的 URL 路径段：{模型标识}/{栏目别名}，如 article/bokezixun
 *
 * 使用模型标识与栏目别名组合路径：
 *   栏目页   /article/news.html
 *   栏目分页 /article/news/list_2004_2.html
 *   文档页   /article/news/20.html
 *
 * @param array $nav
 * @return string
 */
function nav_nid_path(array $nav): string
{
    return nav_nid_seg($nav) . '/' . nav_alias_seg($nav);
}

/**
 * 全部栏目的「URL段」映射：栏目id => 段名（已处理重名兜底）
 *
 * 「模型标识」模式下同一个模型的多个栏目会算出同一个段名（比如都是 article），地址就撞了。
 * 这里统一算一遍，撞车的那几个补上 -栏目ID，保证每个栏目的段名唯一。
 * 「目录名称」模式一般不会撞（后台本来就要求目录名称唯一），也一并走这套逻辑兜底。
 *
 * @return array
 */
function nav_dir_map(): array
{
    static $map = null;
    if ($map !== null) {
        return $map;
    }

    $raw = [];
    foreach (nav_flat_map() as $id => $item) {
        $id = (int) $id;
        if ($id > 0) {
            $raw[$id] = nav_dir_raw($item);
        }
    }

    $count = array_count_values($raw);
    $map   = [];
    foreach ($raw as $id => $seg) {
        $map[$id] = ($count[$seg] > 1) ? $seg . '-' . $id : $seg;
    }

    return $map;
}

/**
 * 栏目在 URL 里用的那一段（含重名兜底）
 *
 * @param array $nav
 * @return string
 */
function nav_dir(array $nav): string
{
    $id  = (int) ($nav['id'] ?? 0);
    $map = nav_dir_map();

    return $map[$id] ?? nav_dir_raw($nav);
}

/**
 * 栏目的完整 URL 路径（祖先目录链，含自己），如 chanpin/shouji
 *
 * @param int|array $nav 栏目id 或栏目数据
 * @return string
 */
function nav_path($nav): string
{
    $id = is_array($nav) ? (int) ($nav['id'] ?? 0) : (int) $nav;
    if ($id < 1) {
        return '';
    }

    $segs = [];
    foreach (nav_chain($id) as $item) {
        $segs[] = nav_dir($item);
    }

    return implode('/', $segs);
}

/**
 * 首页地址
 *
 * 默认语言首页是 `/`；其它模板语言在静态模式下放进静态保存目录的语言子目录，
 * 例如 `/html/en-us/`，对应 `public/html/en-us/index.html`。动态和伪静态仍使用 `/<locale>/`。
 *
 * @return string
 */
function home_url(): string
{
    $locale = class_exists('app\\home\\service\\TemplateI18n')
        ? \app\home\service\TemplateI18n::urlSegment()
        : '';

    if ($locale !== '' && url_config()['mode'] === 2) {
        return url_static_prefix() . '/';
    }

    return $locale . '/';
}

/**
 * 栏目（文档列表页）地址
 *
 * 伪静态/静态模式下地址带**完整祖先目录链**，并且固定用「目录形式」：
 *   一级栏目 /chanpin/           二级栏目 /chanpin/shouji/
 *   第2页 /chanpin/shouji/p2.html
 *
 * @param array $nav  栏目数据
 * @param int   $page 页码，1 或 0 表示第一页（地址里不带页码）
 * @return string
 */
function nav_url(array $nav, int $page = 1): string
{
    //标记为外链时，原样返回栏目表的 url，避免空地址被误拼成站内链接
    if (!empty($nav['is_url'])) {
        return (string) ($nav['url'] ?? '');
    }

    $cfg = url_config();
    $id  = (int) ($nav['id'] ?? 0);

    //动态
    if ($cfg['mode'] === 0) {
        $url = url_entry() . '/home/nav/index?id=' . $id;
        return $page > 1 ? $url . '&page=' . $page : $url;
    }

    //静态模式：按后台「生成静态路径设置 → 栏目页面路径」拼（跟伪静态那套设置无关）
    if ($cfg['mode'] === 2) {
        return nav_url_static($nav, $page, $cfg);
    }

    //「模型标识」模式（伪静态）：栏目页 /article/bokezixun.html，第2页 /article/bokezixun/list_2004_2.html
    if ($cfg['nav_name'] === 2) {
        $base = url_static_prefix() . '/' . nav_nid_path($nav);
        return $page > 1
            ? $base . '/list_' . $id . '_' . $page . '.' . $cfg['suffix']
            : $base . '.' . $cfg['suffix'];
    }

    //目录形式（固定）：/a/b/  第2页 /a/b/p2.html
    //
    //分页用 p{页码}，**故意不用 list_{页码}**：list_ 前缀留给静态模式的
    //「栏目页面路径」规则（{栏目目录}/list_{栏目ID}.html），两边不能撞。
    $url = url_static_prefix() . '/' . nav_path($id) . '/';

    return $page > 1 ? $url . 'p' . $page . '.' . $cfg['suffix'] : $url;
}

/**
 * 文档（详情页）地址
 *
 * 文件名固定「{文档ID}.html」，不提供命名规则配置。
 * 带哪几级栏目目录由后台「文档URL」决定（假设文档在 一级/二级/三级 栏目下）：
 *   父目录           上级目录链             /一级/二级/127.html
 *   当前目录         文档所在栏目目录        /三级/127.html
 *   父目录/当前目录/  上级目录链 + 当前目录   /一级/二级/三级/127.html
 * 文档在顶级栏目下时「父目录」为空，会自动退化成只带当前目录。
 *
 * @param array      $doc 文档数据，至少要有 id、nav_id
 * @param array|null $nav 文档所属栏目，不传会自己查
 * @return string
 */
function doc_url(array $doc, ?array $nav = null): string
{
    $cfg = url_config();
    $id  = (int) ($doc['id'] ?? 0);

    //动态
    if ($cfg['mode'] === 0) {
        return url_entry() . '/home/detail/index?id=' . $id;
    }

    if ($nav === null) {
        //栏目数据从 nav_flat_map() 取（和 nav_by_id() 是同一份前台栏目），
        //这样后台（没有 miu_nav_items()）也能算出和前台一样的地址
        $nav = nav_flat_map()[(int) ($doc['nav_id'] ?? 0)] ?? [];
    }

    //静态模式：按后台「生成静态路径设置 → 文档页面路径」拼（跟伪静态那套设置无关）
    if ($cfg['mode'] === 2 && $nav) {
        return doc_url_static($doc, $nav, $cfg);
    }

    //「模型标识」模式（伪静态）：/article/bokezixun/128.html
    if ($cfg['nav_name'] === 2 && $nav) {
        return url_static_prefix() . '/' . nav_nid_path($nav) . '/' . $id . '.' . $cfg['suffix'];
    }

    $path = '';
    if ($nav) {
        $navId = (int) ($nav['id'] ?? 0);

        //nav_chain 含自己，去掉最后一段就是「上级目录链」
        $ancestors = nav_chain($navId);
        array_pop($ancestors);

        $segs = [];
        foreach ($ancestors as $item) {
            $segs[] = nav_dir($item);
        }
        $parentPath = implode('/', $segs);
        $selfPath   = nav_dir($nav);

        if ($cfg['doc_path'] === 1) {
            //父目录（没有上级栏目时退化成当前目录）
            $path = $parentPath !== '' ? $parentPath : $selfPath;
        } elseif ($cfg['doc_path'] === 2) {
            //当前目录
            $path = $selfPath;
        } else {
            //父目录/当前目录/
            $path = $parentPath !== '' ? ($parentPath . '/' . $selfPath) : $selfPath;
        }
    }

    $path = $path !== '' ? '/' . $path : '';

    return url_static_prefix() . $path . '/' . $id . '.' . $cfg['suffix'];
}

/**
 * 静态模式下栏目页的地址（按后台「生成静态路径设置 → 栏目页面路径」）
 *
 *   1 = 顶级目录名称/list_ID.html    /html/news/list_2004.html（顶级栏目就用它自己的目录）
 *   2 = 父级目录名称/子目录名称/      /html/news/tech/（完整目录链）
 *   3 = 子目录名称/                  /html/tech/（只带自己那一级）
 *
 * 分页：目录形式用 p{页码}.html；list_ID 形式用 list_ID_{页码}.html
 * 目录段统一用「目录名称」(title_en)，跟伪静态那个「栏目URL」设置无关。
 *
 * @param array $nav
 * @param int   $page
 * @param array $cfg url_config() 的结果
 * @return string
 */
function nav_url_static(array $nav, int $page, array $cfg): string
{
    $id = (int) ($nav['id'] ?? 0);

    $segs = [];
    foreach (nav_chain($id) as $item) {
        $segs[] = nav_alias_seg($item);
    }

    $topDir  = $segs === [] ? '' : $segs[0];
    $ownDir  = $segs === [] ? '' : end($segs);
    $fullDir = implode('/', $segs);

    if ($cfg['html_listname'] === 1) {
        $dir = $topDir !== '' ? $topDir : $ownDir;
        $url = url_static_prefix() . '/' . $dir . '/list_' . $id;

        return $page > 1 ? $url . '_' . $page . '.' . $cfg['suffix'] : $url . '.' . $cfg['suffix'];
    }

    $dir = ($cfg['html_listname'] === 3) ? $ownDir : $fullDir;
    $url = url_static_prefix() . '/' . $dir . '/';

    return $page > 1 ? $url . 'p' . $page . '.' . $cfg['suffix'] : $url;
}

/**
 * 静态模式下文档页的地址（按后台「生成静态路径设置 → 文档页面路径」）
 *
 *   1 = 顶级目录名称/ID.html             /html/news/127.html
 *   2 = 父级目录名称/子目录名称/ID.html   /html/news/tech/127.html（完整目录链）
 *   3 = 子目录名称/ID.html               /html/tech/127.html
 *
 * 目录段统一用「目录名称」(title_en)，跟伪静态那个「文档URL」设置无关。
 *
 * @param array $doc
 * @param array $nav
 * @param array $cfg url_config() 的结果
 * @return string
 */
function doc_url_static(array $doc, array $nav, array $cfg): string
{
    $id = (int) ($doc['id'] ?? 0);

    $segs = [];
    foreach (nav_chain((int) ($nav['id'] ?? 0)) as $item) {
        $segs[] = nav_alias_seg($item);
    }

    $topDir  = $segs === [] ? '' : $segs[0];
    $ownDir  = $segs === [] ? '' : end($segs);
    $fullDir = implode('/', $segs);

    if ($cfg['html_pagename'] === 1) {
        $dir = $topDir !== '' ? $topDir : $ownDir;
    } elseif ($cfg['html_pagename'] === 3) {
        $dir = $ownDir;
    } else {
        $dir = $fullDir;
    }

    return url_static_prefix() . '/' . $dir . '/' . $id . '.' . $cfg['suffix'];
}

/**
 * 栏目在前台的链接地址（{miu:nav} 标签里 {$vo.url} 用的就是它）
 *
 * @param array $nav 栏目数据
 * @return string
 */
function miu_nav_url(array $nav): string
{
    return nav_url($nav);
}

/**
 * 取内容模型信息
 *
 * 两层缓存：请求内 static（同一次请求里多次调用只算一次）+ 跨请求持久缓存（标记 homeChannel）。
 * 后台「模型管理」增删改都会 clear_cache('homeChannel')，所以改了模型刷新前台就生效。
 *
 * @param int $channelId
 * @return array 找不到返回空数组
 */
function get_channel(int $channelId): array
{
    static $cache = [];
    $channelId = (int) $channelId;
    if ($channelId < 1) {
        return [];
    }
    if (isset($cache[$channelId])) {
        return $cache[$channelId];
    }

    //持久缓存（标记 homeChannel）
    $cacheKey = 'channel_' . $channelId;
    $row      = get_cache($cacheKey);
    if (!is_array($row)) {
        try {
            $row = \think\facade\Db::name('Channel')->where('id', $channelId)->find() ?: [];
        } catch (\Throwable $e) {
            $row = [];
        }
        //必须用 Cache::tag('homeChannel') 存：后台清的是「标签」（Cache::clear($tag)），
        //不打 tag 存的话 clear_cache('homeChannel') 清不掉
        \think\facade\Cache::tag('homeChannel')->set($cacheKey, $row, 86400);
    }

    return $cache[$channelId] = $row;
}
