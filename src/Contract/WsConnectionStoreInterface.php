<?php

declare(strict_types=1);

namespace Hyperf\Apex\WebSocket\Contract;

use Hyperf\Apex\WebSocket\Session\WsSession;

/**
 * 连接索引契约：回答「业务键 → 连接 id 列表」。
 *
 * 「业务键 → fd」这个映射必须跨 worker 可见（推送要跨 worker 投递），
 * 因此实现是共享内存而非进程内结构。单点部署下用 Swoole\Table；
 * 将来要横向扩容时补一个 Redis 实现，业务代码不用改。
 *
 * 连接 id 统一是 `{server}:{fd}`：不同 server 的 fd 编号空间独立，
 * 带 server 维度能以几乎为零的成本把多 server 的 fd 冲突一次堵掉。
 *
 * 实现被要求是「单 worker 内安全」的：连接索引的读改写不是原子的
 * （Swoole\Table 没有行锁），跨 worker 并发注册同一用户时存在丢索引的窗口。
 * 协程服务器下 Sender 本来就无法跨 worker 投递，所以网关强制单 worker，
 * 该前提由 WsRuntimeCheckListener 在启动期守住。
 */
interface WsConnectionStoreInterface
{
    /**
     * 注册一条连接。重复注册同一个连接 id 视为覆盖。
     */
    public function register(WsSession $session): void;

    /**
     * 注销连接：清连接行，并从用户索引里摘掉。
     *
     * 房间索引不在这里清 —— 连接持有哪几个房间只有 WsSession 知道，
     * 由入口在 onClose 里按 rooms() 逐个 leaveRoom()，才能精确退订。
     */
    public function unregister(string $connectionId): void;

    /**
     * 连接是否仍在索引里。
     *
     * 房间成员列表与连接行是两份数据，连接行已经没了而房间行还挂着时，
     * 推送侧靠它把失效成员剔出去（见 WsPushService::pushToRoom）。
     */
    public function hasConnection(string $connectionId): bool;

    /**
     * 刷新连接的活跃时间：任何一条入站消息都算活跃。
     *
     * 空闲回收的唯一判据，因此 onMessage 每条消息都会调一次（共享内存写，无 IO）。
     */
    public function touch(string $connectionId, int $activeAt): void;

    /**
     * 活跃时间早于 $idleBefore 的连接 id（跨 worker 汇总），供空闲连接回收使用。
     *
     * @return list<string>
     */
    public function idleConnections(int $idleBefore): array;

    /**
     * @return list<string>
     */
    public function connectionsByUser(string $userType, int $userId): array;

    /**
     * @return list<string>
     */
    public function connectionsByRoom(string $room): array;

    /**
     * @return list<string>
     */
    public function connectionsByEndpoint(string $endpoint): array;

    /**
     * @return list<string>
     */
    public function allConnections(): array;

    /**
     * 当前连接总数（跨 worker 汇总）。
     */
    public function countConnections(): int;

    /**
     * 当前在线用户数。用户索引在最后一个连接注销时会整体删除，因此行数即在线人数。
     */
    public function countUsers(): int;

    /**
     * 当前房间数。
     */
    public function countRooms(): int;

    /**
     * 加入房间。返回 false 表示房间成员已达上限（不是「连接不存在」，那种情况直接抛错）。
     */
    public function joinRoom(string $connectionId, string $room): bool;

    public function leaveRoom(string $connectionId, string $room): void;
}
