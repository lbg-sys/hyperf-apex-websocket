<?php

declare(strict_types=1);

namespace Hyperf\Apex\WebSocket\Contract;

/**
 * 房间成员索引契约：回答「房间 → 连接 id 列表」。
 *
 * 与连接 / 用户索引分成两个契约，是因为两者的存储诉求本来就不同：
 * - 连接索引必须与「这一台网关自己持有的连接」同生命周期，Swoole\Table 共享内存就是最优解；
 * - 房间成员是「一组跨接入端共享的 id」，横向扩容时要跨节点可见，因此需要能换成 Redis。
 *
 * 所以连接索引固定是 Swoole\Table（见 WsConnectionStoreInterface），而房间索引按
 * `websocket.room_store_driver` 在 table（WsTableRoomStore）与 redis（WsRedisRoomStore）之间选择。
 *
 * 实现被要求是「单 worker 内安全」的：并发的读改写不做加锁（跨 worker 同时注册同一房间
 * 才存在丢 id 的窗口）。网关强制单 worker，该前提由 WsRuntimeCheckListener 在启动期守住；
 * Redis 实现用一段 Lua 把「判重 + 判容量 + 写入」做成原子操作，跨节点也成立。
 */
interface WsRoomStoreInterface
{
    /**
     * 加入房间。返回 false 表示房间成员已达上限（不是「连接不存在」）。
     *
     * 本契约不认识连接索引，因此调用方必须先确认连接已注册（见 WsDispatcher::subscribe）。
     */
    public function joinRoom(string $connectionId, string $room): bool;

    /**
     * 退出房间。最后一个成员离开即视为房间不存在，实现应把房间本身一并回收，
     * 否则「房间数」这个指标会随着历史房间一直涨。
     */
    public function leaveRoom(string $connectionId, string $room): void;

    /**
     * @return list<string>
     */
    public function connectionsByRoom(string $room): array;

    /**
     * 当前房间数（空房间不计数）。
     */
    public function countRooms(): int;
}
