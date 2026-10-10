<?php

declare(strict_types=1);

namespace Hyperf\Apex\WebSocket\Session;

use Hyperf\Apex\Security\Principal;
use Hyperf\WebSocketServer\Context as ConnectionContext;

/**
 * 单连接的会话视图。
 *
 * 存放位置是**连接级** Hyperf\WebSocketServer\Context，不是协程 Context：
 * onOpen / onMessage / onClose 不在同一协程内触发，协程 Context 在它们之间不共享，
 * 而连接级 Context 按 fd 索引，三个回调都能读到同一份数据。
 *
 * rooms 只放在这里（不落 Swoole\Table 之外的第二份），onClose 时据此精确退订，
 * 避免连接断了之后房间表里留下永远清不掉的脏 fd。
 */
final class WsSession
{
    private const CONTEXT_KEY = 'ws.session';

    /**
     * @param list<string> $rooms
     */
    private function __construct(
        public readonly string $server,
        public readonly int $fd,
        public readonly string $endpoint,
        public readonly ?Principal $principal,
        public readonly int $connectedAt,
        private array $rooms = [],
    ) {
    }

    public static function open(string $server, int $fd, string $endpoint, ?Principal $principal, int $connectedAt): self
    {
        return new self($server, $fd, $endpoint, $principal, $connectedAt);
    }

    public static function connectionId(string $server, int $fd): string
    {
        return $server . ':' . $fd;
    }

    /**
     * 写入当前连接的连接级 Context。
     */
    public function store(): void
    {
        ConnectionContext::set(self::CONTEXT_KEY, $this);
    }

    /**
     * 按 fd 读取会话。onMessage / onClose 都显式传 fd，不依赖协程上下文。
     */
    public static function find(int $fd): ?self
    {
        $session = ConnectionContext::get(self::CONTEXT_KEY, null, $fd);

        return $session instanceof self ? $session : null;
    }

    /**
     * 清理整个 fd 的连接级 Context。
     *
     * 必须做：fd 会被内核复用，残留数据会让新连接读到上一个连接的旧身份（串号）。
     */
    public static function clear(int $fd): void
    {
        ConnectionContext::release($fd);
    }

    public function id(): string
    {
        return self::connectionId($this->server, $this->fd);
    }

    /**
     * @return list<string>
     */
    public function rooms(): array
    {
        return $this->rooms;
    }

    public function inRoom(string $room): bool
    {
        return in_array($room, $this->rooms, true);
    }

    public function joinRoom(string $room): void
    {
        if (! $this->inRoom($room)) {
            $this->rooms[] = $room;
        }
    }

    public function leaveRoom(string $room): void
    {
        $this->rooms = array_values(array_filter(
            $this->rooms,
            static fn (string $item): bool => $item !== $room
        ));
    }
}
