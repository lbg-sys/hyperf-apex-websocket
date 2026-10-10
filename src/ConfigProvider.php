<?php

declare(strict_types=1);

namespace Hyperf\Apex\WebSocket;

use Hyperf\Apex\WebSocket\Contract\WsConnectionStoreInterface;
use Hyperf\Apex\WebSocket\Contract\WsPusherInterface;
use Hyperf\Apex\WebSocket\Listener\WsDeclarationCheckListener;
use Hyperf\Apex\WebSocket\Listener\WsGatewayRegisterListener;
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
                WsTableInitListener::class,
                WsGatewayRegisterListener::class,
                WsDeclarationCheckListener::class,
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
                    'description' => 'The configuration files for apex websocket gateway.',
                    'source' => __DIR__ . '/../publish/',
                    'destination' => BASE_PATH . '/config/autoload/',
                ],
            ],
        ];
    }
}
