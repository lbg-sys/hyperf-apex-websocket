<?php

declare(strict_types=1);

namespace Hyperf\Apex\WebSocket\Session;

use Hyperf\Apex\Security\Principal;

/**
 * 事件处理器看到的连接视图。
 *
 * 不直接暴露 WsSession 的写方法：处理器不该改连接状态（订阅、退订走内置事件）。
 */
final readonly class WsRequestContext
{
    public function __construct(private WsSession $session)
    {
    }

    public function session(): WsSession
    {
        return $this->session;
    }

    /**
     * 用集成凭证接入的连接没有用户身份，返回 null。
     */
    public function principal(): ?Principal
    {
        return $this->session->principal;
    }

    public function connectionId(): string
    {
        return $this->session->id();
    }

    public function server(): string
    {
        return $this->session->server;
    }

    public function fd(): int
    {
        return $this->session->fd;
    }

    public function endpoint(): string
    {
        return $this->session->endpoint;
    }

    /**
     * @return list<string>
     */
    public function rooms(): array
    {
        return $this->session->rooms();
    }
}
