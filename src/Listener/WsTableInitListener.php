<?php

declare(strict_types=1);

namespace Hyperf\Apex\WebSocket\Listener;

use Hyperf\Apex\WebSocket\Registry\WsConnectionStore;
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
    }

    private function enabled(): bool
    {
        return (bool) $this->config->get('websocket.enabled', true);
    }
}
