<?php

declare(strict_types=1);

namespace Hyperf\Apex\WebSocket\Listener;

use Hyperf\Apex\WebSocket\Registry\WsRoomStore;
use Hyperf\Contract\ConfigInterface;
use Hyperf\Event\Contract\ListenerInterface;
use Hyperf\Framework\Event\BootApplication;
use Hyperf\Server\CoroutineServer;
use Hyperf\Server\SwowServer;
use InvalidArgumentException;
use RuntimeException;

/**
 * 启动期校验「运行配置」能否支撑连接网关正常工作。
 *
 * 与 WsDeclarationCheckListener 的分工：那个管「声明写对没有」，这个管「运行时装不装得下」。
 * 下面几项都有同一个特征 —— 配错了不会报错，只会在某个时刻静默失效：
 *
 * 1. 多 worker 下推送丢失：Sender 在协程服务器（Hyperf 3.1+ 默认）里只认本 worker 的 fd，
 *    fd 属于别的 worker 时直接返回 false 且不转发，表现为「部分用户永远收不到推送」。
 * 2. 帧上限越过协议层：websocket.max_frame_bytes 大于 server.settings.package_max_length 时，
 *    超限帧在协议层就被 Swoole 断开，客户端拿到的是断连，而不是应用层精心设计的错误信封。
 * 3. 空闲连接无人回收：连接索引是定长表，写满之后新连接会被直接拒绝且无法自愈。
 * 4. 房间索引驱动写错：驱动名不在受支持列表、或选了 redis 却没装 hyperf/redis（可选依赖）。
 */
final class WsRuntimeCheckListener implements ListenerInterface
{
    public function __construct(private readonly ConfigInterface $config)
    {
    }

    public function listen(): array
    {
        return [BootApplication::class];
    }

    public function process(object $event): void
    {
        if (! (bool) $this->config->get('websocket.enabled')) {
            return;
        }

        $errors = [
            ...$this->checkWorkerCeiling(),
            ...$this->checkFrameCeiling(),
            ...$this->checkIdleRecycle(),
            ...$this->checkRoomStore(),
        ];

        if ($errors !== []) {
            throw new RuntimeException("WebSocket 运行配置检查失败：\n - " . implode("\n - ", $errors));
        }
    }

    /**
     * @return list<string>
     */
    private function checkWorkerCeiling(): array
    {
        // 传统 Swoole\Server 下 Sender 会广播 PipeMessage 让持有连接的 worker 投递，不受此项限制。
        if (! $this->usesCoroutineServer()) {
            return [];
        }

        if ((bool) $this->config->get('websocket.allow_multi_worker')) {
            return [];
        }

        $workerNum = (int) $this->config->get('server.settings.worker_num', 1);
        if ($workerNum <= 1) {
            return [];
        }

        return [sprintf(
            '检测到协程服务器（%s）且 worker_num=%d：Sender 只能在当前 worker 内投递，'
            . 'fd 属于其它 worker 时推送会被静默丢弃。请把 server.settings.worker_num 设为 1，'
            . '或改用 %s，或显式设置 websocket.allow_multi_worker=true 接受「只收不发」的限制。',
            (string) $this->config->get('server.type'),
            $workerNum,
            '\Hyperf\Server\Server',
        )];
    }

    /**
     * @return list<string>
     */
    private function checkFrameCeiling(): array
    {
        $packageMax = (int) $this->config->get('server.settings.package_max_length', 0);
        if ($packageMax <= 0) {
            return [];
        }

        $maxFrame = (int) $this->config->get('websocket.max_frame_bytes');
        if ($maxFrame <= $packageMax) {
            return [];
        }

        return [sprintf(
            'websocket.max_frame_bytes=%d 大于 server.settings.package_max_length=%d：'
            . '超过协议层上限的帧会被 Swoole 直接断开连接，客户端拿到的是断连而不是错误信封。'
            . '请调小 max_frame_bytes，或把 package_max_length 提到它之上。',
            $maxFrame,
            $packageMax,
        )];
    }

    /**
     * @return list<string>
     */
    private function checkIdleRecycle(): array
    {
        if ((int) $this->config->get('websocket.idle_timeout') > 0) {
            return [];
        }

        /*
         * Swoole 的 heartbeat_idle_time 是 Swoole\Server（传统服务器）的 settings。协程服务器
         * 用的是 Swoole\Coroutine\Http\Server，它的 set() 只接收 HTTP 层选项，heartbeat 不会生效
         * （WsIdleConnectionRecycleListener 的类注释是同一口径）。所以只有传统服务器才认这个豁免 ——
         * 否则协程模式下可以带着「零回收」通过启动检查，而定长连接表写满后新连接会被直接拒绝且不自愈。
         */
        if (! $this->usesCoroutineServer() && (int) $this->config->get('server.settings.heartbeat_idle_time', 0) > 0) {
            return [];
        }

        return ['websocket.idle_timeout=0，且当前 server.type 下没有生效的心跳回收：'
            . '没有任何机制回收异常掉线的连接（网络中断不会触发 onClose），'
            . '连接索引表写满后新连接会被直接拒绝，且不会自愈。'
            . '请开启 websocket.idle_timeout（Swoole 的 heartbeat_idle_time 只在传统 '
            . '\Hyperf\Server\Server 下生效，协程服务器不认它）。'];
    }

    /**
     * 房间索引驱动的校验与 WsRoomStore 共用同一段判定，避免「检查放行、运行时炸掉」。
     *
     * @return list<string>
     */
    private function checkRoomStore(): array
    {
        try {
            WsRoomStore::assertDriver(WsRoomStore::configuredDriver($this->config));
        } catch (InvalidArgumentException|RuntimeException $exception) {
            return [$exception->getMessage()];
        }

        return [];
    }

    private function usesCoroutineServer(): bool
    {
        return in_array((string) $this->config->get('server.type'), [CoroutineServer::class, SwowServer::class], true);
    }
}
