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
use think\console\Output;

/**
 * 清理已过期的文件缓存
 *
 * 用法：php think cache-clean
 *
 * File 驱动只在 key 被再次读到时才删过期文件，没有后台 GC，所以长尾缓存会一直占磁盘。
 * 这个命令主动扫一遍缓存目录，把过期的删掉。建议挂定时任务每天跑一次：
 *   Windows：任务计划程序 → 每天 → 程序 php.exe，参数 "think cache-clean"，起始位置填项目根目录
 *   Linux  ：0 4 * * * cd /path/to/site && php think cache-clean
 */
class CacheClean extends Command
{
    protected function configure()
    {
        $this->setName('cache-clean')
            ->setDescription('清理已过期的文件缓存，释放磁盘空间');
    }

    protected function execute(Input $input, Output $output)
    {
        $result = clear_expired_cache();

        if ($result['files'] < 1) {
            $output->writeln('没有过期的缓存文件需要清理');
            return;
        }

        $output->writeln(sprintf(
            '已清理过期缓存：%d 个文件，释放 %s',
            $result['files'],
            $this->formatBytes($result['bytes'])
        ));
    }

    /**
     * 字节数转可读大小
     *
     * 没有直接用后台的 format_bytes()：那个定义在 app/admin/common.php，
     * 命令行下不会加载，调用会报「Call to undefined function」。
     *
     * @param int $size
     * @return string
     */
    protected function formatBytes(int $size): string
    {
        $units = ['B', 'KB', 'MB', 'GB', 'TB'];
        $i     = 0;
        while ($size >= 1024 && $i < 4) {
            $size /= 1024;
            $i++;
        }

        return round($size, 2) . $units[$i];
    }
}
