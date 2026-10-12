<?php

declare(strict_types=1);

namespace Hyperf\Apex\WebSocket\Listener;

use Hyperf\Apex\Logging\LoggerChannels;
use Hyperf\Apex\Support\Time;
use Hyperf\Apex\WebSocket\Contract\WsConnectionStoreInterface;
use Hyperf\Apex\WebSocket\Session\WsSession;
use Hyperf\Contract\ConfigInterface;
use Hyperf\Event\Contract\ListenerInterface;
use Hyperf\Framework\Event\AfterWorkerStart;
use Hyperf\Logger\LoggerFactory;
use Hyperf\Server\Event\MainCoroutineServerStart;
use Hyperf\WebSocketServer\Sender;
use Psr\Log\LoggerInterface;
use Swoole\Timer;

/**
 * 回收空闲连接。
 *
 * 为什么必须有这一步：网络中断（拔网线、进程被 kill、NAT 超时）不会触发 onClose，
 * 连接行会一直挂在共享内存表里。表是定长结构 —— 写满之后新连接注册直接失败、连接被拒，
 * 而且不会自愈。协程服务器下 Swoole 的 heartbeat 配置不生效（那是 Swoole\Server 的 settings），
 * 所以回收只能由应用层做：收到任意消息即刷新活跃时间，超过 idle_timeout 未刷新的连接主动断开。
 *
 * 断开走 Sender：传统 Server 下它会广播 PipeMessage 交给持有连接的 worker，
 * 协程服务器下只有持有连接的 worker 能断掉它 —— 所以定时器在协程模式下每个 worker 都会起，
 * 各自只能处理自己持有的那部分（重复扫表无害，表会被遍历很多次只说明 worker 数偏多）。
 */
final class WsIdleConnectionRecycleListener implements ListenerInterface
{
    private readonly LoggerInterface $logger;

    private bool $started = false;

    public function __construct(
        private readonly ConfigInterface $config,
        private readonly WsConnectionStoreInterface $store,
        private readonly Sender $sender,
        LoggerFactory $loggerFactory,
    ) {
        $this->logger = LoggerChannels::resolve($loggerFactory, 'websocket', 'error');
    }

    public function listen(): array
    {
        return [AfterWorkerStart::class, MainCoroutineServerStart::class];
    }

    public function process(object $event): void
    {
        if ($this->started || ! (bool) $this->config->get('websocket.enabled')) {
            return;
        }

        $timeout = (int) $this->config->get('websocket.idle_timeout');
        if ($timeout <= 0) {
            return;
        }

        // 传统 Server 下每个 worker 都会触发 AfterWorkerStart，只让 0 号起定时器就够：
        // 它断不掉的 fd 由 Sender 广播 PipeMessage 交给持有者。
        if ($event instanceof AfterWorkerStart && $event->workerId !== 0) {
            return;
        }

        $interval = max(1, (int) $this->config->get('websocket.idle_check_interval'));
        $this->started = true;

        Timer::tick($interval * 1000, function () use ($timeout): void {
            $this->recycle($timeout);
        });
    }

    private function recycle(int $timeout): void
    {
        $idleBefore = Time::now()->getTimestamp() - $timeout;

        foreach ($this->store->idleConnections($idleBefore) as $connectionId) {
            $fd = WsSession::fdOf($connectionId);
            if ($fd <= 0) {
                $this->store->unregister($connectionId);
                continue;
            }

            if (! $this->sender->disconnect($fd)) {
                // 与推送同理：false 也可能是「fd 不在本 worker」，不能据此摘索引 ——
                // 误摘会让一条在线连接彻底失联。真正的清理仍由 onClose 负责。
                $this->logger->debug('WebSocket 空闲连接断开指令未被接受。', ['connection' => $connectionId]);
                continue;
            }

            $this->logger->info('WebSocket 空闲连接已回收。', [
                'connection' => $connectionId,
                'idle_timeout' => $timeout,
            ]);
        }
    }
}
