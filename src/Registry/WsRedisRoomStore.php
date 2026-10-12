<?php

declare(strict_types=1);

namespace Hyperf\Apex\WebSocket\Registry;

use Hyperf\Apex\WebSocket\Contract\WsRoomStoreInterface;
use Hyperf\Contract\ConfigInterface;
use Hyperf\Redis\RedisFactory;
use Hyperf\Redis\RedisProxy;
use RuntimeException;
use Throwable;

/**
 * 房间成员索引的 Redis 实现（`websocket.room_store_driver=redis`）。
 *
 * 为什么需要它：Swoole\Table 是**进程内共享内存**，只在单机内一致。网关本身强制单 worker，
 * 但房间成员的场景常常是「多台网关 + 一个房间」—— 用户连在 A 机、业务在 B 机推房间时，
 * 只有把成员集合放到 Redis 才能让另一台机器找得到这批连接。连接索引不需要跨节点
 * （fd 是单机概念），所以只有房间索引提供 Redis 驱动。
 *
 * 键结构（前缀取自 websocket.room_store_prefix）：
 * - `<prefix>room:<房间名>`  成员集合，元素是连接 id；
 * - `<prefix>rooms`          房间名索引集合，供 O(1) 的房间计数。
 *
 * 「判重 + 判容量 + 写入」放在一段 Lua 里，单次 RTT 且原子，跨节点并发注册同一房间也不会
 * 互相覆盖（Swoole\Table 实现在跨 worker 时有丢 id 的窗口，这里没有）。
 *
 * 已知边界：
 * - 脚本一次操作两个键，Redis Cluster 下两者必须落在同一 slot（本实现未加 hash tag，
 *   集群部署需要自行规划键前缀）；
 * - 房间名索引是「近似」一致的派生数据：越过本实现（例如运维直接 DEL 成员键）改动键空间，
 *   索引集合会留下不再存在的房间名，表现为「房间数」偏大。
 */
final class WsRedisRoomStore implements WsRoomStoreInterface
{
    /**
     * KEYS[1] 成员集合、KEYS[2] 房间名索引
     * ARGV[1] capacity、ARGV[2] connectionId、ARGV[3] room.
     *
     * 已在房间里时直接返回成功（重复订阅是幂等的），容量判断在写入之前完成，
     * 满了返回 0 —— 与 Swoole\Table 实现的 joinRoom() 返回值语义一致。
     * 脚本内不做扫描与循环：Lua 执行期间 Redis 全局阻塞。
     */
    private const JOIN_SCRIPT = <<<'LUA'
local capacity = tonumber(ARGV[1])
if redis.call('SISMEMBER', KEYS[1], ARGV[2]) == 1 then
    return 1
end
if redis.call('SCARD', KEYS[1]) >= capacity then
    return 0
end
redis.call('SADD', KEYS[1], ARGV[2])
redis.call('SADD', KEYS[2], ARGV[3])
return 1
LUA;

    /**
     * KEYS[1] 成员集合、KEYS[2] 房间名索引
     * ARGV[1] connectionId、ARGV[2] room.
     *
     * 最后一个成员离开即删掉房间键并把它从索引里摘掉，否则「房间数」只增不减。
     */
    private const LEAVE_SCRIPT = <<<'LUA'
redis.call('SREM', KEYS[1], ARGV[1])
if redis.call('SCARD', KEYS[1]) == 0 then
    redis.call('DEL', KEYS[1])
    redis.call('SREM', KEYS[2], ARGV[2])
end
return 1
LUA;

    private readonly RedisProxy $redis;

    private readonly string $prefix;

    private readonly int $capacity;

    private readonly string $joinSha;

    private readonly string $leaveSha;

    public function __construct(RedisFactory $factory, ConfigInterface $config)
    {
        $this->redis = $factory->get((string) $config->get('websocket.room_store_redis_pool'));
        $this->prefix = (string) $config->get('websocket.room_store_prefix');
        $this->capacity = max(1, (int) $config->get('websocket.room_member_capacity'));
        $this->joinSha = sha1(self::JOIN_SCRIPT);
        $this->leaveSha = sha1(self::LEAVE_SCRIPT);
    }

    public function joinRoom(string $connectionId, string $room): bool
    {
        $result = $this->evaluate(
            self::JOIN_SCRIPT,
            $this->joinSha,
            [$this->roomKey($room), $this->roomsKey()],
            [(string) $this->capacity, $connectionId, $room],
        );

        return (int) $result === 1;
    }

    public function leaveRoom(string $connectionId, string $room): void
    {
        $this->evaluate(
            self::LEAVE_SCRIPT,
            $this->leaveSha,
            [$this->roomKey($room), $this->roomsKey()],
            [$connectionId, $room],
        );
    }

    public function connectionsByRoom(string $room): array
    {
        $members = $this->redis->sMembers($this->roomKey($room));
        if (! is_array($members)) {
            return [];
        }

        $ids = [];
        foreach ($members as $member) {
            $id = (string) $member;
            if ($id !== '') {
                $ids[] = $id;
            }
        }

        return $ids;
    }

    public function countRooms(): int
    {
        return max(0, (int) $this->redis->sCard($this->roomsKey()));
    }

    /**
     * @param list<string> $keys
     * @param list<string> $arguments
     */
    private function evaluate(string $script, string $sha, array $keys, array $arguments): mixed
    {
        $payload = [...$keys, ...$arguments];
        $numKeys = count($keys);

        try {
            // phpredis 的 evalSha 在脚本缓存丢失（Redis 重启、SCRIPT FLUSH）时不会自动回落，
            // 需要自己捕获后改用 eval 重新带上脚本体。
            return $this->redis->evalSha($sha, $payload, $numKeys);
        } catch (Throwable) {
            // 脚本缓存丢失与连接失败都走这里：前者由下面的 eval 兜住，后者会再次失败并抛出。
        }

        try {
            return $this->redis->eval($script, $payload, $numKeys);
        } catch (Throwable $throwable) {
            // 不降级：房间成员读不到就意味着推送会发给错误的集合，
            // 静默当成「没有成员」比抛错更危险（与限流的 fail-open 取舍相反）。
            throw new RuntimeException('WebSocket 房间索引访问 Redis 失败。', 0, $throwable);
        }
    }

    private function roomKey(string $room): string
    {
        return $this->prefix . 'room:' . $room;
    }

    private function roomsKey(): string
    {
        return $this->prefix . 'rooms';
    }
}
