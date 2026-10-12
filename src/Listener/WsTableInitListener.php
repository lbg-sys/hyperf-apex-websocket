<?php

declare(strict_types=1);

namespace Hyperf\Apex\WebSocket\Listener;

use Hyperf\Apex\WebSocket\Registry\WsConnectionStore;
use Hyperf\Apex\WebSocket\Registry\WsRoomStore;
use Hyperf\Apex\WebSocket\Registry\WsTableRoomStore;
use Hyperf\Contract\ConfigInterface;
use Hyperf\Event\Contract\ListenerInterface;
use Hyperf\Framework\Event\BeforeMainServerStart;

/**
 * 在 master 进程、fork worker 之前创建 Swoole\Table。
 *
 * 时机不能挪到 WorkerStart：那样每个 worker 会各建一份自己的表，跨 worker 推送就找不到对方持有的连接。
 */
final class WsTableInitListener implements ListenerInterface
{
    public function __construct(private readonly ConfigInterface $config)
    {
    }

    public function listen(): array
    {
        return [BeforeMainServerStart::class];
    }

    public function process(object $event): void
    {
        if (! $this->enabled()) {
            return;
        }

        WsConnectionStore::init($this->config);

        // 房间索引只在 table 驱动下建共享内存表：选了 redis 驱动还照建，等于白白占住
        // room_table_size × room_member_capacity 这份共享内存，而且没人会用到它。
        if (WsRoomStore::configuredDriver($this->config) === WsRoomStore::DRIVER_TABLE) {
            WsTableRoomStore::init($this->config);
        }
    }

    private function enabled(): bool
    {
        return (bool) $this->config->get('websocket.enabled');
    }
}
