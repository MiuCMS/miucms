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

/**
 * 生成 sitemap 地图文件
 *
 * 用法：
 *   php think sitemap                按后台「SEO → Sitemap」勾选的地图类型生成
 *   php think sitemap --type=all     强制生成 xml / txt / html / llms 四种地图
 *   php think sitemap --type=llms    只生成指定类型（xml / txt / html / llms）
 *   php think sitemap --push         生成完顺便推送给百度
 *
 * 生成到站点根目录（public/）：sitemap.xml、sitemap.txt、sitemap.html、llms.txt
 *
 * 建议挂定时任务（比如每小时一次）：
 *   Windows 任务计划程序：程序 php.exe，参数 "think sitemap"，起始位置填项目根目录
 *   Linux：0 * * * * cd /path/to/site && php think sitemap
 */
class Sitemap extends Command
{
    protected function configure()
    {
        $this->setName('sitemap')
            ->addOption('type', 't', Option::VALUE_OPTIONAL, 'all 生成全部；xml / txt / html / llms 生成指定类型，默认按后台设置', 'configured')
            ->addOption('push', 'p', Option::VALUE_NONE, '生成后顺便把最新地址推送给百度')
            ->setDescription('生成 sitemap.xml / sitemap.txt / sitemap.html / llms.txt');
    }

    protected function execute(Input $input, Output $output)
    {
        //生成地图要读前台的 URL 规则（nav_url / doc_url），把 home 应用的公共文件加载进来。
        //命令行下不会自动加载任何应用的 common.php，所以这里是安全的，不会跟别的应用撞函数名。
        $homeCommon = root_path() . 'app' . DIRECTORY_SEPARATOR . 'home' . DIRECTORY_SEPARATOR . 'common.php';
        if (is_file($homeCommon)) {
            require_once $homeCommon;
        }

        $type = (string) $input->getOption('type');
        if (!in_array($type, ['configured', 'all', 'xml', 'txt', 'html', 'llms'], true)) {
            $type = 'configured';
        }

        $result = sitemap_build($type);

        foreach ($result['files'] as $name => $size) {
            $output->writeln('  ' . $name . ' 已生成（' . $this->formatBytes($size) . '）');
        }
        foreach ($result['errors'] as $err) {
            $output->error('  ' . $err);
        }

        $output->writeln(sprintf(
            '地址统计：首页 %d 条，栏目页 %d 条，内容页 %d 条，标签页 %d 条，共 %d 条',
            $result['counts']['index'],
            $result['counts']['list'],
            $result['counts']['view'],
            $result['counts']['tags'],
            $result['counts']['total']
        ));

        if ($input->getOption('push')) {
            $push = sitemap_push_recent();
            if ($push['ok']) {
                $output->writeln('百度推送：' . $push['msg']);
            } else {
                $output->error('百度推送：' . $push['msg']);
            }
        }
    }

    /**
     * 字节数转可读大小
     *
     * @param int $size
     * @return string
     */
    protected function formatBytes(int $size): string
    {
        $units = ['B', 'KB', 'MB', 'GB'];
        $i     = 0;
        while ($size >= 1024 && $i < 3) {
            $size /= 1024;
            $i++;
        }

        return round($size, 2) . $units[$i];
    }
}
