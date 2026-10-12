<?php

declare(strict_types=1);

namespace Hyperf\Apex\WebSocket;

use Hyperf\Apex\WebSocket\Contract\WsConnectionStoreInterface;
use Hyperf\Apex\WebSocket\Contract\WsPusherInterface;
use Hyperf\Apex\WebSocket\Listener\WsDeclarationCheckListener;
use Hyperf\Apex\WebSocket\Listener\WsGatewayRegisterListener;
use Hyperf\Apex\WebSocket\Listener\WsIdleConnectionRecycleListener;
use Hyperf\Apex\WebSocket\Listener\WsRuntimeCheckListener;
use Hyperf\Apex\WebSocket\Listener\WsTableInitListener;
use Hyperf\Apex\WebSocket\Registry\WsConnectionStore;
use Hyperf\Apex\WebSocket\Service\WsPushService;

class ConfigProvider
{
    /**
     * @return array<string, mixed>
     */
    public function __invoke(): array
    {
        return [
            'dependencies' => [
                WsPusherInterface::class => WsPushService::class,
                WsConnectionStoreInterface::class => WsConnectionStore::class,
            ],
            'commands' => [],
            'listeners' => [
                // 建表在 master 进程、fork 之前；声明与运行配置的检查必须早于注册器 ——
                // 注册器里的扫描一旦失败会直接抛异常，排在它后面的检查就永远执行不到。
                WsTableInitListener::class,
                WsDeclarationCheckListener::class,
                WsRuntimeCheckListener::class,
                WsGatewayRegisterListener::class,
                // 挂在 worker 启动事件上，与上面几个的顺序无关。
                WsIdleConnectionRecycleListener::class,
            ],
            'annotations' => [
                'scan' => [
                    'paths' => [
                        __DIR__,
                    ],
                ],
            ],
            'publish' => [
                [
                    'id' => 'config',
                    'description' => 'apex websocket 网关的配置模板。',
                    'source' => __DIR__ . '/../publish/',
                    'destination' => BASE_PATH . '/config/autoload/',
                ],
            ],
        ];
    }
}
