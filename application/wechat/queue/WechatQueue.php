<?php

// +----------------------------------------------------------------------
// | framework
// +----------------------------------------------------------------------
// | 版权所有 2014~2018 广州楚才信息科技有限公司 [ http://www.cuci.cc ]
// +----------------------------------------------------------------------
// | 官方网站: http://framework.thinkadmin.top
// +----------------------------------------------------------------------
// | 开源协议 ( https://mit-license.org )
// +----------------------------------------------------------------------
// | github开源项目：https://github.com/zoujingli/framework
// +----------------------------------------------------------------------

namespace app\wechat\queue;

use app\admin\queue\JobsQueue;
use app\wechat\service\FansService;
use app\wechat\service\WechatService;
use think\Db;

/**
 * Class Jobs
 * @package app\wechat
 */
class WechatQueue extends JobsQueue
{
    /**
     * 当前任务URI
     */
    const URI = self::class;

    /**
     * 执行任务
     * @return boolean
     */
    public function execute()
    {
        try {
            $appid = WechatService::getAppid();
            $wechat = WechatService::WeChatUser();
            // 获取远程粉丝
            list($next, $done) = ['', 0];
            $this->output->writeln('Start synchronizing fans from the Wechat server');
            while ($next !== null && is_array($result = $wechat->getUserList($next)) && !empty($result['data']['openid'])) {
                $done += $result['count'];
                foreach (array_chunk($result['data']['openid'], 100) as $chunk) {
                    if (is_array($list = $wechat->getBatchUserInfo($chunk)) && !empty($list['user_info_list'])) {
                        foreach ($list['user_info_list'] as $user) FansService::set($user, $appid);
                    }
                }
                $next = $result['total'] > $done ? $result['next_openid'] : null;
            }
            // 同步粉丝黑名单
            list($next, $done) = ['', 0];
            $this->output->writeln('Start synchronizing black from the Wechat server');
            while ($next !== null && is_array($result = $wechat->getBlackList($next)) && !empty($result['data']['openid'])) {
                $done += $result['count'];
                foreach (array_chunk($result['data']['openid'], 100) as $chunk) {
                    Db::name('WechatFans')->where(['is_black' => '0'])->whereIn('openid', $chunk)->update(['is_black' => '1']);
                }
                $next = $result['total'] > $done ? $result['next_openid'] : null;
            }
            // 同步粉丝标签列表
            $this->output->writeln('Start synchronizing tags from the Wechat server');
            if (is_array($list = WechatService::WeChatTags()->getTags()) && !empty($list['tags'])) {
                foreach ($list['tags'] as &$tag) $tag['appid'] = $appid;
                Db::name('WechatFansTags')->where('1=1')->delete();
                Db::name('WechatFansTags')->insertAll($list['tags']);
            }
            return true;
        } catch (\Exception $e) {
            $this->statusDesc = $e->getMessage();
            return false;
        }
    }

}