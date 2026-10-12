<?php

declare(strict_types=1);

namespace Hyperf\Apex\WebSocket\Registry;

use Hyperf\Apex\WebSocket\Contract\WsRoomStoreInterface;
use Hyperf\Contract\ConfigInterface;
use Hyperf\Redis\RedisFactory;
use InvalidArgumentException;
use Psr\Container\ContainerInterface;
use RuntimeException;

/**
 * 房间索引驱动的选择器：按 `websocket.room_store_driver` 决定委派给哪个实现。
 *
 * 为什么用「接口 => 选择器」而不是工厂：Hyperf 的 dependencies 只认「id => 可以 make 出来的类」，
 * 选择器本身就是那个可以 make 出来的类，选择动作放在它的构造期与首次调用，依赖注入这一层
 * 保持直线绑定（与本仓库其余包一致，不需要框架扩展）。
 *
 * 校验（驱动名合法、Redis 驱动所需的包已安装）与 WsRuntimeCheckListener 共用同一段判定，
 * 保证「启动期能拦下的」与「运行期会抛的」是同一套规则，不会出现检查放行、运行时炸掉。
 */
final class WsRoomStore implements WsRoomStoreInterface
{
    /** 单机驱动：Swoole\Table 共享内存。 */
    public const DRIVER_TABLE = 'table';

    /** 跨节点驱动：Redis（可选依赖 hyperf/redis）。 */
    public const DRIVER_REDIS = 'redis';

    private ?WsRoomStoreInterface $driver = null;

    public function __construct(
        private readonly ContainerInterface $container,
        private readonly ConfigInterface $config,
    ) {
        // 构造期就校验：驱动配错不该等到第一条订阅消息才暴露。
        self::assertDriver(self::configuredDriver($config));
    }

    /**
     * @return list<string>
     */
    public static function drivers(): array
    {
        return [self::DRIVER_TABLE, self::DRIVER_REDIS];
    }

    public static function configuredDriver(ConfigInterface $config): string
    {
        return (string) $config->get('websocket.room_store_driver');
    }

    /**
     * hyperf/redis 是本包的可选依赖（见包内 composer.json 的 suggest）：只有选了 redis 驱动才需要它。
     */
    public static function redisAvailable(): bool
    {
        return class_exists(RedisFactory::class);
    }

    /**
     * @throws InvalidArgumentException 驱动名不在受支持列表里
     * @throws RuntimeException redis 驱动而 hyperf/redis 未安装
     */
    public static function assertDriver(string $driver): void
    {
        if (! in_array($driver, self::drivers(), true)) {
            throw new InvalidArgumentException(sprintf(
                'websocket.room_store_driver 只能是 %s，当前是「%s」。',
                implode(' 或 ', self::drivers()),
                $driver,
            ));
        }

        if ($driver === self::DRIVER_REDIS && ! self::redisAvailable()) {
            throw new RuntimeException(
                'websocket.room_store_driver=redis 需要可选的 hyperf/redis（见本包 composer.json 的 suggest）：'
                . '请 composer require hyperf/redis，或把驱动改回 table。',
            );
        }
    }

    public function joinRoom(string $connectionId, string $room): bool
    {
        return $this->driver()->joinRoom($connectionId, $room);
    }

    public function leaveRoom(string $connectionId, string $room): void
    {
        $this->driver()->leaveRoom($connectionId, $room);
    }

    public function connectionsByRoom(string $room): array
    {
        return $this->driver()->connectionsByRoom($room);
    }

    public function countRooms(): int
    {
        return $this->driver()->countRooms();
    }

    /**
     * 首次调用时才解析具体实现：两个实现都不轻（一个要建共享内存表，一个要建连接池），
     * 而房间索引可能在本次启动里根本用不到。
     */
    private function driver(): WsRoomStoreInterface
    {
        return $this->driver ??= $this->make();
    }

    private function make(): WsRoomStoreInterface
    {
        $driver = self::configuredDriver($this->config);
        self::assertDriver($driver);

        return $driver === self::DRIVER_REDIS
            ? $this->container->get(WsRedisRoomStore::class)
            : $this->container->get(WsTableRoomStore::class);
    }
}
