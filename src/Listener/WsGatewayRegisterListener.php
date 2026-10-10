<?php

declare(strict_types=1);

namespace Hyperf\Apex\WebSocket\Listener;

use Hyperf\Apex\WebSocket\Route\WsRouteRegistrar;
use Hyperf\Contract\ConfigInterface;
use Hyperf\Event\Contract\ListenerInterface;
use Hyperf\Framework\Event\BootApplication;

/**
 * 注册 WebSocket 入口路由。
 *
 * 挂在 BootApplication 而不是 BeforeMainServerStart：Hyperf\Server\Server::initServers()
 * 会把 WebSocket server 排到最前面当主 server（Server::sortServers()），于是
 * registerSwooleEvents() → Hyperf\WebSocketServer\Server::initCoreMiddleware('ws')
 * 会在 BeforeMainServerStart 之前就先调用一次 DispatcherFactory::getDispatcher('ws')，
 * 而 getDispatcher() 的结果是缓存的 —— 那时再注册就永远不会生效（表现为「启动正常、握手 404」）。
 *
 * BootApplication 在 ApplicationFactory 里最先派发，早于任何一次 getDispatcher()，
 * 且在 master 进程、fork worker 之前，路由表随 fork 复制到每个 worker。
 * WsRouteRegistrar 注册后的回查断言会在时机被破坏时直接把启动拦下来，不会静默退化成 404。
 */
final class WsGatewayRegisterListener implements ListenerInterface
{
    public function __construct(
        private readonly ConfigInterface $config,
        private readonly WsRouteRegistrar $registrar,
    ) {
    }

    public function listen(): array
    {
        return [BootApplication::class];
    }

    public function process(object $event): void
    {
        if (! (bool) $this->config->get('websocket.enabled', true)) {
            return;
        }

        $this->registrar->register();
    }
}
