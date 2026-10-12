<?php

declare(strict_types=1);

namespace Hyperf\Apex\WebSocket\Service;

use Hyperf\Apex\DTO\AbstractDTO;
use Hyperf\Apex\Logging\LoggerChannels;
use Hyperf\Apex\WebSocket\Contract\WsConnectionStoreInterface;
use Hyperf\Apex\WebSocket\Contract\WsPusherInterface;
use Hyperf\Apex\WebSocket\Protocol\WsPushDTO;
use Hyperf\Apex\WebSocket\Session\WsSession;
use Hyperf\Logger\LoggerFactory;
use Hyperf\WebSocketServer\Sender;
use Psr\Log\LoggerInterface;

/**
 * 推送的实现：按业务键取连接列表，逐个投递信封。
 *
 * 投递能力取决于 server.type，这一点必须写清楚（见 vendor/hyperf/websocket-server/src/Sender.php）：
 * - 传统 `Hyperf\Server\Server`：Sender 广播 PipeMessage 让持有连接的 worker 去发，
 *   但返回值恒为 true，投递是否成功无从得知；
 * - 协程服务器（Hyperf 3.1+ 默认）：Sender 只认本 worker 的 fd，fd 不在本 worker 时
 *   直接返回 false，且不会跨 worker 转发。
 *
 * 于是 `push()` 返回 false 既可能是「连接真的没了」，也可能是「fd 不在本 worker」。
 * 不能用它来决定摘除索引：误摘会让一条在线连接此后彻底收不到推送，而客户端毫无感知。
 * 索引清理的唯一权威路径是 onClose 与空闲回收。网关因此要求单 worker，
 * 该前提由 WsRuntimeCheckListener 在启动期守住。
 */
final class WsPushService implements WsPusherInterface
{
    private readonly LoggerInterface $logger;

    public function __construct(
        private readonly WsConnectionStoreInterface $store,
        private readonly Sender $sender,
        LoggerFactory $loggerFactory,
    ) {
        $this->logger = LoggerChannels::resolve($loggerFactory, 'websocket', 'error');
    }

    public function pushToUser(string $userType, int $userId, string $event, AbstractDTO $payload): void
    {
        $this->pushTo($this->store->connectionsByUser($userType, $userId), $event, $payload);
    }

    public function pushToRoom(string $room, string $event, AbstractDTO $payload): void
    {
        $this->pushTo($this->pruneRoom($room), $event, $payload);
    }

    public function pushToEndpoint(string $endpoint, string $event, AbstractDTO $payload): void
    {
        $this->pushTo($this->store->connectionsByEndpoint($endpoint), $event, $payload);
    }

    public function broadcast(string $event, AbstractDTO $payload): void
    {
        $this->pushTo($this->store->allConnections(), $event, $payload);
    }

    /**
     * 房间成员里已经失效的连接顺手退订。
     *
     * 房间行只在 onClose 里摘（unregister 不知道连接持有哪几个房间），连接行先消失的窗口里
     * 失效 id 会一直挂着，每次推房间都白投一次。这里做惰性自愈：成本是 O(成员数) 次共享内存读，
     * 跑在本来就要遍历成员的推送路径上，不额外增加量级。
     *
     * @return list<string>
     */
    private function pruneRoom(string $room): array
    {
        $alive = [];
        foreach ($this->store->connectionsByRoom($room) as $connectionId) {
            if ($this->store->hasConnection($connectionId)) {
                $alive[] = $connectionId;
                continue;
            }

            $this->store->leaveRoom($connectionId, $room);
        }

        return $alive;
    }

    /**
     * @param list<string> $connectionIds
     */
    private function pushTo(array $connectionIds, string $event, AbstractDTO $payload): void
    {
        if ($connectionIds === []) {
            return;
        }

        $message = WsPushDTO::make($event, $payload)->toJson();

        foreach ($connectionIds as $connectionId) {
            $fd = WsSession::fdOf($connectionId);
            if ($fd <= 0) {
                // 连接 id 自身不可解析说明索引已经坏了；它不指向任何在线连接，摘掉是安全的。
                $this->store->unregister($connectionId);
                continue;
            }

            if (! $this->sender->push($fd, $message)) {
                // 只记日志、不摘索引：见类注释，false 不等于「连接已死」。
                $this->logger->warning('WebSocket 推送投递失败。', [
                    'connection' => $connectionId,
                    'event' => $event,
                ]);
            }
        }
    }
}
