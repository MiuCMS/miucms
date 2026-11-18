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
 * 百度普通收录（API 推送）
 *
 * 用法：
 *   php think baidu-push                       推送最新的一批文档（数量取后台「生成数量」）
 *   php think baidu-push --num=50              推送最新 50 篇
 *   php think baidu-push --doc=123             推送指定文档
 *   php think baidu-push --url=https://a.com/,https://a.com/2.html   只推送指定地址
 *
 * token 在后台「SEO → Sitemap → 百度推送」里填（百度搜索资源平台 → 普通收录 → API 提交）。
 *
 * 建议挂定时任务（比如每天一次）：
 *   Windows 任务计划程序：程序 php.exe，参数 "think baidu-push"，起始位置填项目根目录
 *   Linux：0 3 * * * cd /path/to/site && php think baidu-push
 *
 * 注意：百度普通收录接口每天有配额（一般个人站 10~100 条/天），
 * 返回里会带今日剩余条数，别把 --num 设太大浪费配额。
 */
class BaiduPush extends Command
{
    protected function configure()
    {
        $this->setName('baidu-push')
            //注意：短选项别用 -n，那是 think 内置的 --no-interaction，会冲突报错
            ->addOption('num', 'm', Option::VALUE_OPTIONAL, '推送最新多少篇文档，默认用后台配置的「生成数量」', 0)
            ->addOption('doc', 'd', Option::VALUE_OPTIONAL, '只推送指定文档 ID', 0)
            ->addOption('url', 'u', Option::VALUE_OPTIONAL, '只推送指定地址，多个用英文逗号隔开', '')
            ->setDescription('把站点地址推送给百度（普通收录 API）');
    }

    protected function execute(Input $input, Output $output)
    {
        //推送要按前台的 URL 规则拼地址，加载 home 的公共文件
        $homeCommon = root_path() . 'app' . DIRECTORY_SEPARATOR . 'home' . DIRECTORY_SEPARATOR . 'common.php';
        if (is_file($homeCommon)) {
            require_once $homeCommon;
        }

        $url = trim((string) $input->getOption('url'));
        if ($url !== '') {
            $urls = array_filter(array_map('trim', explode(',', $url)));
            $res  = sitemap_push_baidu($urls);
        } elseif ((int) $input->getOption('doc') > 0) {
            $res = sitemap_push_document((int) $input->getOption('doc'));
        } else {
            $res = sitemap_push_recent((int) $input->getOption('num'));
        }

        if ($res['ok']) {
            $output->writeln($res['msg']);
        } else {
            $output->error($res['msg']);
        }
    }
}
