<?php

declare(strict_types=1);

namespace Hyperf\Apex\WebSocket\Listener;

use Hyperf\Apex\Logging\LoggerChannels;
use Hyperf\Apex\WebSocket\Route\WsRouteRegistrar;
use Hyperf\Contract\ConfigInterface;
use Hyperf\Event\Contract\ListenerInterface;
use Hyperf\Framework\Event\BootApplication;
use Hyperf\Logger\LoggerFactory;
use Psr\Log\LoggerInterface;

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
 *
 * 必须排在 WsDeclarationCheckListener / WsRuntimeCheckListener 之后：那两个负责把声明与运行
 * 配置的错误一次性汇总抛出，本监听器里的扫描一旦失败会直接抛出（见各自的类注释）。
 */
final class WsGatewayRegisterListener implements ListenerInterface
{
    private readonly LoggerInterface $logger;

    public function __construct(
        private readonly ConfigInterface $config,
        private readonly WsRouteRegistrar $registrar,
        LoggerFactory $loggerFactory,
    ) {
        $this->logger = LoggerChannels::resolve($loggerFactory, 'websocket', 'error');
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

        $registered = $this->registrar->register();

        // 启动日志是「路由到底装没装」的第一现场：没有它，握手 404 只能靠翻框架代码定位。
        foreach ($registered as $server => $paths) {
            $this->logger->info(sprintf('WebSocket 入口已注册：server=%s，path=%s。', $server, implode(', ', $paths)));
        }
    }
}
