<?php

declare(strict_types=1);

namespace Hyperf\Apex\WebSocket;

use Hyperf\Apex\WebSocket\Contract\WsConnectionStoreInterface;
use Hyperf\Apex\WebSocket\Contract\WsPusherInterface;
use Hyperf\Apex\WebSocket\Contract\WsRoomStoreInterface;
use Hyperf\Apex\WebSocket\Listener\WsDeclarationCheckListener;
use Hyperf\Apex\WebSocket\Listener\WsGatewayRegisterListener;
use Hyperf\Apex\WebSocket\Listener\WsIdleConnectionRecycleListener;
use Hyperf\Apex\WebSocket\Listener\WsRuntimeCheckListener;
use Hyperf\Apex\WebSocket\Listener\WsTableInitListener;
use Hyperf\Apex\WebSocket\Registry\WsConnectionStore;
use Hyperf\Apex\WebSocket\Registry\WsRoomStore;
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
                // 房间索引按 websocket.room_store_driver 在 table / redis 之间选择，
                // 选择动作收敛在 WsRoomStore 里，依赖注入这一层保持「接口 => 实现」的直线绑定。
                WsRoomStoreInterface::class => WsRoomStore::class,
            ],
            /*
             * 配置默认值。Hyperf 的 ConfigFactory 按
             * array_merge_recursive(ConfigProvider 配置, config/config.php, config/autoload/*.php)
             * 合并，后者覆盖前者，因此这里给出的是「应用没有 vendor:publish、或发布了但删掉某项」时的兜底。
             * 读配置的代码不再各自内联默认值 —— 那正是 idle_timeout 一度在三个地方是三个数（180/120/60）的原因。
             * 与 publish/websocket.php 的一致性由 test/WebSocket/WsConfigDefaultsTest 断言。
             */
            'websocket' => [
                'enabled' => true,
                'path' => '/ws',
                'max_connections_per_user' => 10,
                'max_rooms_per_connection' => 20,
                'max_frame_bytes' => 65536,
                'rate_limit' => [
                    'window_seconds' => 1,
                    'max_messages' => 50,
                ],
                'table_size' => 65536,
                'room_member_capacity' => 128,
                'room_table_size' => 2048,
                // 房间成员索引的存储后端：table（Swoole\Table，单机）或 redis（跨节点，需要可选的 hyperf/redis）。
                'room_store_driver' => WsRoomStore::DRIVER_TABLE,
                'room_store_redis_pool' => 'default',
                'room_store_prefix' => 'core:wsroom:',
                'idle_timeout' => 180,
                'idle_check_interval' => 30,
                'allow_multi_worker' => false,
                'excluded' => [],
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
