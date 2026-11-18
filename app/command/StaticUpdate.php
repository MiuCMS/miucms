<?php
/**
 * @copyright Copyright (c) 2026 米柚工作室
 * @license https://opensource.org/licenses/Apache-2.0
 * @link https://www.miucms.com
 */

declare (strict_types = 1);

namespace app\command;

use think\console\Command;
use think\console\Input;
use think\console\input\Option;
use think\console\Output;
use think\facade\Db;
use think\facade\View;
use app\home\service\TemplateI18n;

/**
 * 静态模式下，把受影响的静态页面重新生成一遍
 *
 * 两种跑法：
 *
 * 1) worker 模式（后台发布/编辑/删除文档时自动调的就是这个）
 *      php think static-update --worker
 *    去队列目录（runtime/static_queue/）把攒下来的任务全取出来，**合并成一份清单**
 *    一起生成 —— 同一个页面只生成一次，不会因为连着发 10 篇就重复生成 10 遍。
 *    同一时间只允许一个 worker（靠锁文件），队列空了再过几秒就自己退出。
 *
 * 2) 单次模式（手工排查用）
 *      php think static-update --doc=131 --nav=2040 --home=1 --navpage=1 --side=1
 *
 * 任务里有什么：
 *   doc/nav/oldnav + home/navpage/side   → 发布/编辑文档（生成文档页、首页、相关栏目页、上下篇）
 *   doc/nav + delete=1                   → 删除文档（删掉它的静态页 + 重生成相关栏目页 + 清掉多出来的尾页）
 *
 * 为什么做成命令行而不是在后台控制器里直接渲染：
 *   渲染要读前台模板目录和前台渲染函数（miu_render_nav_list 等），那些在 home 应用里；
 *   起独立进程顺带也不阻塞发布文档那个请求。
 *   （URL 规则 nav_url / doc_url 已经挪到全局 app/common.php，后台也能直接算了）
 */
class StaticUpdate extends Command
{
    /**
     * 队列连续几轮没任务就退出（每轮 1 秒）
     *
     * 留几轮是为了让「刚好在这时候又发布了一篇」的任务也能被接上。
     */
    const IDLE_ROUNDS = 3;

    protected function configure()
    {
        $this->setName('static-update')
            ->addOption('worker', null, Option::VALUE_NONE, '队列模式：把攒下来的任务合并后一起处理')
            ->addOption('doc', null, Option::VALUE_OPTIONAL, '文档id', '0')
            ->addOption('nav', null, Option::VALUE_OPTIONAL, '文档所在栏目id', '0')
            ->addOption('oldnav', null, Option::VALUE_OPTIONAL, '编辑时文档原来所在的栏目id', '0')
            ->addOption('home', null, Option::VALUE_OPTIONAL, '更新首页：1/0', '0')
            ->addOption('navpage', null, Option::VALUE_OPTIONAL, '更新相关栏目：1/0', '0')
            ->addOption('side', null, Option::VALUE_OPTIONAL, '更新上下篇：1/0', '0')
            ->addOption('delete', null, Option::VALUE_OPTIONAL, '这是删除文档：1/0', '0')
            ->setDescription('静态模式下，重新生成受影响的静态页面');
    }

    protected function execute(Input $input, Output $output)
    {
        $this->bootHome();

        if ($input->getOption('worker')) {
            $this->runWorker($output);
            return;
        }

        //单次模式：直接用命令行参数拼一个任务
        $this->handleTasks([[
            'doc'     => (int) $input->getOption('doc'),
            'nav'     => (int) $input->getOption('nav'),
            'oldnav'  => (int) $input->getOption('oldnav'),
            'home'    => (int) $input->getOption('home') === 1 ? 1 : 0,
            'navpage' => (int) $input->getOption('navpage') === 1 ? 1 : 0,
            'side'    => (int) $input->getOption('side') === 1 ? 1 : 0,
            'delete'  => (int) $input->getOption('delete') === 1 ? 1 : 0,
        ]], $output);
    }

    /* ==================== worker：队列循环 ==================== */

    /**
     * 只有一个 worker 在跑：谁拿到锁谁是 worker，跑完才放锁
     *
     * @param Output $output
     * @return void
     */
    protected function runWorker(Output $output): void
    {
        $lockFile = static_queue_lock_file();
        $lock     = @fopen($lockFile, 'c');

        if (!$lock || !flock($lock, LOCK_EX | LOCK_NB)) {
            //已经有 worker 在跑，它会处理队列，这里直接退出
            return;
        }

        $idle = 0;
        while (true) {
            $tasks = $this->drainQueue();
            if ($tasks) {
                $idle = 0;
                $this->handleTasks($tasks, $output);
            } else {
                $idle++;
            }

            if ($idle >= self::IDLE_ROUNDS) {
                //准备退出。先放锁再确认一次队列：万一刚好卡在退出这一瞬间来了任务，
                //写任务的请求会看到「锁还在」而不起新进程，那就漏了
                flock($lock, LOCK_UN);
                fclose($lock);

                if (!$this->queueCount()) {
                    return;
                }

                //还真有，重新抢锁接着干；被别人抢走了就交给它
                $lock = @fopen($lockFile, 'c');
                if (!$lock || !flock($lock, LOCK_EX | LOCK_NB)) {
                    return;
                }
                $idle = 0;
                continue;
            }

            sleep(1);
        }
    }

    /**
     * 把队列里的任务全取出来（读完就删）
     *
     * @return array
     */
    protected function drainQueue(): array
    {
        $files = glob(static_queue_dir() . '*.json') ?: [];
        $tasks = [];

        foreach ($files as $file) {
            $raw = @file_get_contents($file);
            @unlink($file);

            $task = json_decode((string) $raw, true);
            if (is_array($task)) {
                $tasks[] = $task;
            }
        }

        return $tasks;
    }

    /**
     * 队列里还有几个任务
     *
     * @return int
     */
    protected function queueCount(): int
    {
        return count(glob(static_queue_dir() . '*.json') ?: []);
    }

    /* ==================== 合并 + 生成 ==================== */

    /**
     * 把一批任务合并成一份页面清单，然后生成
     *
     * 合并规则（同一个页面只做一次）：
     *   文档页 → 取并集
     *   首页   → 有一个任务要就生成
     *   栏目页 → 取并集；栏目范围是「文档所在栏目 + 所有上级栏目」
     *   上下篇 → 每个任务各自算相邻文档
     *
     * @param array  $tasks
     * @param Output $output
     * @return void
     */
    protected function handleTasks(array $tasks, Output $output): void
    {
        //静态模式下，内容变更要同步更新当前模板提供的每一种界面语言。
        $previousLocale = TemplateI18n::locale();
        try {
            foreach (TemplateI18n::availableLocales() as $locale) {
                TemplateI18n::setLocale($locale);
                $localeTasks = $tasks;

                //旧路径由后台任务按默认语言记录；删除时换算成当前语言目录。
                foreach ($localeTasks as &$task) {
                    foreach (['delete_nav_urls', 'delete_doc_urls'] as $key) {
                        if (empty($task[$key]) || !is_array($task[$key])) {
                            continue;
                        }
                        foreach ($task[$key] as &$url) {
                            $url = TemplateI18n::localizedUrl($locale, (string) $url);
                        }
                        unset($url);
                    }
                }
                unset($task);

                $this->handleTasksForLocale($localeTasks, $output);
            }
        } finally {
            TemplateI18n::setLocale($previousLocale);
        }
    }

    /** 在当前语言上下文中执行一轮静态更新。 */
    protected function handleTasksForLocale(array $tasks, Output $output): void
    {
        //任务是在静态模式下攒的，中间要是切成了动态/伪静态，这批就没意义了
        if (!createhtml_ready()) {
            $output->writeln('当前不是「生成静态」模式，丢弃 ' . count($tasks) . ' 个任务');
            return;
        }

        $genDocs      = []; // docId => navId
        $delDocs      = []; // docId => navId
        $regenNavs    = [];
        $trimNavs     = [];
        $regenTags    = [];
        $trimTags     = [];
        $delTags      = [];
        $deleteNavs   = [];
        $deleteDocs   = [];
        $home         = false;

        foreach ($tasks as $t) {
            $docId  = (int) ($t['doc'] ?? 0);
            $navId  = (int) ($t['nav'] ?? 0);
            $oldNav = (int) ($t['oldnav'] ?? 0);

            foreach ((array) ($t['delete_nav_urls'] ?? []) as $url) {
                $deleteNavs[(string) $url] = (string) $url;
            }
            foreach ((array) ($t['delete_doc_urls'] ?? []) as $url) {
                $deleteDocs[(string) $url] = (string) $url;
            }
            foreach ((array) ($t['tag_ids'] ?? []) as $tagId) {
                $tagId = (int) $tagId;
                if ($tagId > 0) {
                    $regenTags[$tagId] = true;
                }
            }
            foreach ((array) ($t['delete_tag_ids'] ?? []) as $tagId) {
                $tagId = (int) $tagId;
                if ($tagId > 0) {
                    $delTags[$tagId] = true;
                    $regenTags[$tagId] = true;
                }
            }

            /* ---- 删除文档 ---- */
            if (!empty($t['delete'])) {
                if ($docId > 0 && $navId > 0) {
                    $delDocs[$docId] = $navId;
                }
                $home = true;
                //删了一篇，栏目列表少一条，这条链上的栏目页都要重生成，页数还可能少一页
                foreach ($this->navChainIds($navId) as $nid) {
                    $regenNavs[$nid] = true;
                    $trimNavs[$nid]  = true;
                }
                foreach ($this->siblingDocIds($navId, $docId) as $sibId) {
                    $genDocs[$sibId] = $navId;
                }
                continue;
            }

            /* ---- 发布/编辑文档 ---- */
            if ($docId > 0 && $navId > 0) {
                $genDocs[$docId] = $navId;
            }
            if (!empty($t['home']) || !empty($t['nav_ids']) || !empty($t['parent_ids']) || !empty($t['tag_ids'])) {
                $home = true;
            }
            if (!empty($t['navpage']) || !empty($t['nav_ids'])) {
                //文档所在栏目 + 所有上级栏目（父栏目列表里也有子栏目的文档）；
                //编辑换栏目时，原来那个栏目那条链也要更新
                foreach ([$navId, $oldNav] as $nid) {
                    foreach ($this->navChainIds($nid) as $x) {
                        $regenNavs[$x] = true;
                    }
                }
            }
            if (!empty($t['side']) && $docId > 0) {
                foreach (array_unique(array_filter([$navId, $oldNav])) as $contextNavId) {
                    foreach ($this->siblingDocIds((int) $contextNavId, $docId) as $sibId) {
                        $genDocs[$sibId] = (int) Db::name('document')->where('id', $sibId)->value('nav_id');
                    }
                }
            }

            //栏目新增/编辑/恢复：展开当前子树，重建其中内容页及列表页；旧 URL 在写入前清理。
            $navRoots = array_values(array_unique(array_filter(array_map('intval', (array) ($t['nav_ids'] ?? [])))));
            if ($navRoots) {
                $home = true;
                $subtreeIds = $this->navIdsWithChildren($navRoots);
                foreach ($subtreeIds as $subNavId) {
                    foreach ($this->navChainIds($subNavId) as $chainId) {
                        $regenNavs[$chainId] = true;
                        $trimNavs[$chainId]  = true;
                    }
                }
                $docs = $subtreeIds ? Db::name('document')->field('id,nav_id,tag_ids')->where('is_del', 0)->where('status', 1)->where('arcrank', '>=', 0)->whereIn('nav_id', $subtreeIds)->select()->toArray() : [];
                foreach ($docs as $row) {
                    $rowId = (int) ($row['id'] ?? 0);
                    $rowNavId = (int) ($row['nav_id'] ?? 0);
                    if ($rowId > 0 && $rowNavId > 0) {
                        $genDocs[$rowId] = $rowNavId;
                    }
                    foreach ($this->tagIdList($row['tag_ids'] ?? []) as $tagId) {
                        $regenTags[$tagId] = true;
                    }
                }
            }
            foreach ((array) ($t['parent_ids'] ?? []) as $parentId) {
                foreach ($this->navChainIds((int) $parentId) as $chainId) {
                    $regenNavs[$chainId] = true;
                    $trimNavs[$chainId]  = true;
                }
            }
        }

        //刷新受影响的标签页和标签下文档：标签标题、状态或文档内容变化会影响这两类页面。
        foreach (array_keys($regenTags) as $tagId) {
            $tag = Db::name('keywords')->where('id', $tagId)->find();
            if (!$tag || (int) ($tag['status'] ?? 0) !== 1 || isset($delTags[$tagId])) {
                $delTags[$tagId] = true;
            } else {
                unset($delTags[$tagId]);
            }

            $tagDocs = Db::name('document')->field('id,nav_id')->where('is_del', 0)
                ->where('status', 1)->where('arcrank', '>=', 0)->where('tag_ids', 'like', '%,' . $tagId . ',%')->select()->toArray();
            foreach ($tagDocs as $row) {
                $rowId = (int) ($row['id'] ?? 0);
                $rowNavId = (int) ($row['nav_id'] ?? 0);
                if ($rowId > 0 && $rowNavId > 0) {
                    $genDocs[$rowId] = $rowNavId;
                    foreach ($this->navChainIds($rowNavId) as $chainId) {
                        $regenNavs[$chainId] = true;
                    }
                }
            }
        }

        //重新核对数据库当前状态，避免 worker 处理期间已删除/未审核的文档被旧任务重新写回静态目录。
        foreach (array_keys($genDocs) as $id) {
            $current = Db::name('document')->field('id,nav_id,is_del,status,arcrank')->where('id', $id)->find();
            if (!$current) {
                unset($genDocs[$id]);
                continue;
            }
            $currentNavId = (int) ($current['nav_id'] ?? 0);
            if ((int) ($current['status'] ?? 0) !== 1 || (int) ($current['is_del'] ?? 0) !== 0 || (int) ($current['arcrank'] ?? -1) < 0) {
                unset($genDocs[$id]);
                if ($currentNavId > 0) {
                    $delDocs[$id] = $currentNavId;
                }
                continue;
            }
            if ($currentNavId < 1 || !nav_by_id($currentNavId)) {
                unset($genDocs[$id]);
                continue;
            }
            $genDocs[$id] = $currentNavId;
            unset($delDocs[$id]);
        }

        /* ---- 1) 先清除旧路径和被删除文档/标签的静态页 ---- */
        $deleted = 0;
        foreach ($deleteNavs as $url) {
            $deleted += $this->deletePageFamily($url, 'nav');
        }
        foreach ($deleteDocs as $url) {
            $deleted += $this->deletePublicUrl($url) ? 1 : 0;
        }
        foreach ($delDocs as $docId => $navId) {
            $url = doc_url(['id' => $docId, 'nav_id' => $navId], nav_by_id($navId));
            $deleted += $this->deletePublicUrl($url) ? 1 : 0;
        }
        foreach (array_keys($delTags) as $tagId) {
            $deleted += $this->deletePageFamily(miu_tag_url((int) $tagId), 'tag');
        }

        /* ---- 2) 拼要生成的页面 ---- */
        $items = [];
        $seen  = [];
        $add   = function (array $it) use (&$items, &$seen) {
            if (createhtml_is_reserved_404_url((string) ($it['url'] ?? ''))) {
                return;
            }
            $key = $it['kind'] . '|' . $it['url'];
            if (isset($seen[$key])) {
                return;
            }
            $seen[$key] = true;
            $items[]    = $it;
        };

        if ($home) {
            $add(['kind' => 'home', 'url' => home_url(), 'label' => '首页']);
        }

        foreach ($genDocs as $docId => $navId) {
            $add($this->docItem($docId, $navId));
        }

        foreach (array_keys($regenNavs) as $navId) {
            $nav = nav_by_id($navId);
            if (!$nav || (!empty($nav['is_url']) && !empty($nav['url']))) {
                continue;
            }
            //文档数变了，分页数可能变，整个栏目的每一页都重生成
            $pages = miu_nav_list_page_count($navId);
            for ($page = 1; $page <= $pages; $page++) {
                $url = nav_url($nav, $page);
                $add([
                    'kind'   => 'nav',
                    'nav_id' => $navId,
                    'page'   => $page,
                    'url'    => $url,
                    'label'  => '栏目页 ' . $url,
                ]);
            }
        }

        foreach (array_keys($regenTags) as $tagId) {
            if (isset($delTags[$tagId])) {
                continue;
            }
            $tag = Db::name('keywords')->where('id', $tagId)->where('status', 1)->find();
            if (!$tag) {
                continue;
            }
            $pages = miu_tag_list_page_count((int) $tagId);
            for ($page = 1; $page <= $pages; $page++) {
                $url = miu_tag_url((int) $tagId, $page);
                $add([
                    'kind'   => 'tag',
                    'tag_id' => (int) $tagId,
                    'page'   => $page,
                    'url'    => $url,
                    'label'  => '标签页 ' . $url,
                ]);
            }
            $trimTags[$tagId] = true;
        }

        /* ---- 3) 生成 ---- */
        $ok     = 0;
        $errors = [];

        //生成期间关掉「访问量 +1」，不然更新一次页面每篇文档的访问量都白涨一次
        miu_building_static(true);
        foreach ($items as $it) {
            $err = '';
            if (createhtml_run_item($it, $err)) {
                $ok++;
            } else {
                $errors[] = (string) ($it['label'] ?? '') . '：' . $err;
            }
        }
        miu_building_static(false);

        /* ---- 4) 清掉栏目多出来的尾页 ---- */
        $trimmed = 0;
        foreach (array_keys($trimNavs) as $navId) {
            $trimmed += $this->trimNavTail($navId);
        }
        foreach (array_keys($trimTags) as $tagId) {
            $trimmed += $this->trimTagTail((int) $tagId);
        }

        $output->writeln(sprintf(
            '静态页面更新：生成 %d/%d，清理旧静态页 %d，清理多余尾页 %d',
            $ok,
            count($items),
            $deleted,
            $trimmed
        ));
        foreach ($errors as $err) {
            $output->error('  ' . $err);
        }
    }

    /** 规范化文档上的标签 id。 */
    protected function tagIdList($value): array
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

    /** 展开仍启用的栏目子树。 */
    protected function navIdsWithChildren(array $rootIds): array
    {
        $rows = Db::name('nav')->field('id,pid')->where('status', 1)->where('is_del', 0)->select()->toArray();
        $byParent = [];
        foreach ($rows as $row) {
            $byParent[(int) ($row['pid'] ?? 0)][] = (int) ($row['id'] ?? 0);
        }
        $available = array_fill_keys(array_map(static function ($row) { return (int) $row['id']; }, $rows), true);
        $stack = array_values(array_filter(array_map('intval', $rootIds), static function ($id) use ($available) { return isset($available[$id]); }));
        $ids = [];
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
        return array_values($ids);
    }

    /** 删除一个站内静态文件；拒绝外链、路径穿越和 public 目录外的符号链接。 */
    protected function deletePublicUrl(string $url): bool
    {
        $parts = parse_url($url);
        if (!is_array($parts) || !empty($parts['host']) || !empty($parts['scheme'])) {
            return false;
        }
        $path = rawurldecode((string) ($parts['path'] ?? ''));
        if (strpos($path, "\0") !== false || strpos($path, '\\') !== false) {
            return false;
        }
        $segments = array_values(array_filter(explode('/', trim($path, '/')), static function ($part) { return $part !== ''; }));
        foreach ($segments as $part) {
            if ($part === '.' || $part === '..') {
                return false;
            }
        }
        if (!$segments) {
            $segments = ['index.html'];
        } elseif (substr($path, -1) === '/') {
            $segments[] = 'index.html';
        }

        $file = public_path() . implode(DIRECTORY_SEPARATOR, $segments);
        $root = realpath(public_path());
        $real = realpath($file);
        if (!$root || !$real || !is_file($real)) {
            return false;
        }
        $root = rtrim($root, DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR;
        if (stripos($real, $root) !== 0) {
            return false;
        }
        return @unlink($real);
    }

    /** 清理栏目或标签的首页及分页静态文件。 */
    protected function deletePageFamily(string $url, string $kind): int
    {
        $count = $this->deletePublicUrl($url) ? 1 : 0;
        $parts = parse_url($url);
        if (!is_array($parts) || !empty($parts['host']) || !empty($parts['scheme'])) {
            return $count;
        }
        $path = rawurldecode((string) ($parts['path'] ?? ''));
        $segments = array_values(array_filter(explode('/', trim($path, '/')), static function ($part) { return $part !== ''; }));
        if (strpos($path, "\0") !== false || strpos($path, '\\') !== false) {
            return $count;
        }
        foreach ($segments as $part) {
            if ($part === '.' || $part === '..') {
                return $count;
            }
        }
        if (!$segments) {
            return $count;
        }

        $name = end($segments);
        $dirUrl = substr($path, -1) === '/';
        $baseDir = public_path() . implode(DIRECTORY_SEPARATOR, $dirUrl ? $segments : array_slice($segments, 0, -1));
        $pattern = '';
        $regex = '';
        if ($dirUrl || strtolower($name) === 'index.html') {
            $pattern = rtrim($baseDir, DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR . 'p*.html';
            $regex = '/^p(\d+)\.html$/i';
        } elseif ($kind === 'nav' && preg_match('/^(list_\d+)\.html$/i', $name, $match)) {
            $pattern = $baseDir . DIRECTORY_SEPARATOR . $match[1] . '_*.html';
            $regex = '/^' . preg_quote($match[1], '/') . '_(\d+)\.html$/i';
        } elseif ($kind === 'tag' && preg_match('/^(\d+)\.html$/', $name, $match)) {
            $pattern = $baseDir . DIRECTORY_SEPARATOR . $match[1] . '_p*.html';
            $regex = '/^' . preg_quote($match[1], '/') . '_p(\d+)\.html$/i';
        }
        if ($pattern === '') {
            return $count;
        }
        foreach (glob($pattern) ?: [] as $file) {
            if (!preg_match($regex, basename($file))) {
                continue;
            }
            $relativeUrl = '/' . str_replace(DIRECTORY_SEPARATOR, '/', ltrim(str_replace(public_path(), '', $file), DIRECTORY_SEPARATOR));
            if ($this->deletePublicUrl($relativeUrl)) {
                $count++;
            }
        }
        return $count;
    }

    /** 清理标签分页尾页。 */
    protected function trimTagTail(int $tagId): int
    {
        $first = miu_tag_url($tagId, 1);
        $pages = miu_tag_list_page_count($tagId);
        $parts = parse_url($first);
        if (!is_array($parts) || !empty($parts['host']) || !empty($parts['scheme'])) {
            return 0;
        }
        $path = rawurldecode((string) ($parts['path'] ?? ''));
        $segments = array_values(array_filter(explode('/', trim($path, '/')), static function ($part) { return $part !== ''; }));
        if (!$segments || strpos($path, "\0") !== false || strpos($path, '\\') !== false) {
            return 0;
        }
        $name = end($segments);
        $dir = public_path() . implode(DIRECTORY_SEPARATOR, array_slice($segments, 0, -1));
        if (substr($path, -1) === '/') {
            $dir .= DIRECTORY_SEPARATOR . $name;
            $pattern = rtrim($dir, DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR . 'p*.html';
            $regex = '/^p(\d+)\.html$/i';
        } else {
            $pattern = $dir . DIRECTORY_SEPARATOR . $tagId . '_p*.html';
            $regex = '/^' . preg_quote((string) $tagId, '/') . '_p(\d+)\.html$/i';
        }
        $count = 0;
        foreach (glob($pattern) ?: [] as $file) {
            if (!preg_match($regex, basename($file), $match) || (int) $match[1] <= $pages) {
                continue;
            }
            $relativeUrl = '/' . str_replace(DIRECTORY_SEPARATOR, '/', ltrim(str_replace(public_path(), '', $file), DIRECTORY_SEPARATOR));
            if ($this->deletePublicUrl($relativeUrl)) {
                $count++;
            }
        }
        return $count;
    }

    /**
     * 删掉某个栏目「多出来的」列表页
     *
     * 文档被删掉之后页数可能变少，比如原来 11 页、现在 10 页，
     * 那 list_xxx_11.html 就是废文件，得删掉（不然前台还能翻到）。
     *
     * 做法：按第 1 页的文件名推出「这个栏目的第 2 页往后长什么样」，
     * glob 出同名的文件，把页码大于当前页数的删掉。
     * 不用「从第 N+1 页往上连续删」是因为中间万缺了一个文件就断了，
     * 后面的废页反而留着 —— 这里按文件名判断，断不断都清得掉。
     *
     * 命名规则跟着 URL 规则走（nav_url），所以两种形式都要认：
     *   目录形式（栏目URL 选完整链/子目录）  第1页 <dir>/index.html，第2页起 <dir>/p{N}.html
     *   list_ID 形式（选顶级目录名称）       第1页 list_{栏目id}.html，第2页起 list_{栏目id}_{N}.html
     *
     * @param int $navId
     * @return int 删掉了几个
     */
    protected function trimNavTail(int $navId): int
    {
        $nav = nav_by_id($navId);
        if (!$nav) {
            return 0;
        }

        $pages = miu_nav_list_page_count($navId);
        $first = createhtml_file_of_url(nav_url($nav, 1));
        $dir   = dirname($first);

        if (basename($first) === 'index.html') {
            //目录形式：第 2 页起是 <dir>/p{N}.html
            $pattern = $dir . DIRECTORY_SEPARATOR . 'p*.html';
            $regex   = '/^p(\d+)\.html$/i';
        } else {
            //list_{栏目id}.html 形式：第 2 页起是 list_{栏目id}_{N}.html
            //（带下划线，所以不会误伤 list_20401_2.html 这种别的栏目）
            $base    = basename($first, '.html');
            $pattern = $dir . DIRECTORY_SEPARATOR . $base . '_*.html';
            $regex   = '/^' . preg_quote($base, '/') . '_(\d+)\.html$/i';
        }

        $count = 0;
        foreach (glob($pattern) ?: [] as $file) {
            if (!preg_match($regex, basename($file), $m)) {
                continue;
            }
            //还在页数范围内的不动
            if ((int) $m[1] <= $pages) {
                continue;
            }
            if (@unlink($file)) {
                $count++;
            }
        }

        return $count;
    }

    /**
     * 栏目链（自己 + 所有上级栏目）的 id
     *
     * @param int $navId
     * @return int[]
     */
    protected function navChainIds(int $navId): array
    {
        if ($navId < 1) {
            return [];
        }

        $ids = [];
        foreach (nav_chain($navId) as $item) {
            $ids[] = (int) $item['id'];
        }

        return $ids;
    }

    /**
     * 拼一个「文档详情页」的生成项
     *
     * @param int $docId
     * @param int $navId
     * @return array
     */
    protected function docItem(int $docId, int $navId): array
    {
        $url = doc_url(['id' => $docId, 'nav_id' => $navId], nav_by_id($navId));

        return [
            'kind'   => 'doc',
            'doc_id' => $docId,
            'url'    => $url,
            'label'  => '文档页 ' . $url,
        ];
    }

    /**
     * 同栏目里紧挨着的前后两篇的 id
     *
     * 排序规则跟前台 {miu:prenext} 标签、详情页的 {$prev}/{$next} 完全一致
     * —— 都走 home 应用里的 miu_doc_sibling()，改规则只用改那一处。
     *
     * 删除文档时也用它：文档行虽然没了，但「id 比它小的最大 / 比它大的最小」照样算得出来，
     * 正好就是它原来的上下篇。
     *
     * @param int $navId
     * @param int $docId
     * @return int[]
     */
    protected function siblingDocIds(int $navId, int $docId): array
    {
        $ids = [];
        foreach (['pre', 'next'] as $type) {
            $row = miu_doc_sibling($type, $docId, $navId);
            if ($row) {
                $ids[] = (int) $row['id'];
            }
        }

        return $ids;
    }

    /**
     * 把 home 应用的运行环境准备好（公共函数 + 模板目录）
     *
     * 命令行下不会自动加载任何应用的 common.php，也不会跑 Template 中间件，
     * 而渲染模板这两样都缺不了，所以在这里手工补上。
     *
     * @return void
     */
    protected function bootHome(): void
    {
        $homeCommon = root_path() . 'app' . DIRECTORY_SEPARATOR . 'home' . DIRECTORY_SEPARATOR . 'common.php';
        if (is_file($homeCommon)) {
            require_once $homeCommon;
        }

        //应用级 view 配置（taglib_pre_load 在这里面，模板里 {miu:xxx} 才认得出来）
        $appView = root_path() . 'app' . DIRECTORY_SEPARATOR . 'home' . DIRECTORY_SEPARATOR . 'config' . DIRECTORY_SEPARATOR . 'view.php';
        if (is_file($appView)) {
            View::config(include $appView);
        }

        //模板目录指到当前模板（跟前台 Template 中间件做的一样）
        $tpl  = get_current_template();
        $path = get_templates_path($tpl);
        if (is_dir($path)) {
            View::config([
                'view_path'          => $path,
                'tpl_replace_string' => template_asset_replace($tpl),
            ]);
        }
    }
}
