<?php

declare(strict_types=1);

namespace Hyperf\Apex\WebSocket\Service;

use Hyperf\Apex\DTO\AbstractDTO;
use Hyperf\Apex\WebSocket\Contract\WsConnectionStoreInterface;
use Hyperf\Apex\WebSocket\Contract\WsPusherInterface;
use Hyperf\Apex\WebSocket\Protocol\WsPushDTO;
use Hyperf\WebSocketServer\Sender;

/**
 * 推送的实现：按业务键取连接列表，逐个投递信封。
 *
 * 跨 worker 由 Sender 负责（本 worker 持有直接发，否则广播 PipeMessage 让持有者发），
 * 所以这里不需要知道 fd 属于哪个 worker，注册表只需要保证「能拿到 fd」。
 * 代价是每次跨 worker 推送是 O(worker_num) 广播，因此 ws 的 worker 数不宜大。
 */
final class WsPushService implements WsPusherInterface
{
    public function __construct(
        private readonly WsConnectionStoreInterface $store,
        private readonly Sender $sender,
    ) {
    }

    public function pushToUser(string $userType, int $userId, string $event, AbstractDTO $payload): void
    {
        $this->pushTo($this->store->connectionsByUser($userType, $userId), $event, $payload);
    }

    public function pushToRoom(string $room, string $event, AbstractDTO $payload): void
    {
        $this->pushTo($this->store->connectionsByRoom($room), $event, $payload);
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
     * @param list<string> $connectionIds
     */
    private function pushTo(array $connectionIds, string $event, AbstractDTO $payload): void
    {
        if ($connectionIds === []) {
            return;
        }

        $message = WsPushDTO::make($event, $payload)->toJson();

        foreach ($connectionIds as $connectionId) {
            $fd = self::fdOf($connectionId);
            if ($fd <= 0) {
                $this->store->unregister($connectionId);
                continue;
            }

            if (! $this->sender->push($fd, $message)) {
                // 投递不到说明连接已经不在了，顺带从索引里摘掉。
                // 主清理路径仍是 onClose；这里只处理「onClose 还没跑到」的窗口。
                $this->store->unregister($connectionId);
            }
        }
    }

    /**
     * 连接 id 形如 `{server}:{fd}`，fd 一定在最后一个冒号之后。
     */
    private static function fdOf(string $connectionId): int
    {
        $position = strrpos($connectionId, ':');
        if ($position === false) {
            return 0;
        }

        $fd = substr($connectionId, $position + 1);

        return ctype_digit($fd) ? (int) $fd : 0;
    }
}
