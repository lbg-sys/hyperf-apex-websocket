<?php

declare(strict_types=1);

namespace Hyperf\Apex\WebSocket\Auth;

use Hyperf\Apex\Security\Principal;
use Hyperf\WebSocketServer\Context as ConnectionContext;

/**
 * 握手阶段到 onOpen 之间的身份中转。
 *
 * 握手中间件先于 onOpen 执行，此时还不知道 endpoint（入口类才知道），因此先把 Principal
 * 写进连接级 Context，onOpen 再把它组装进 WsSession。
 *
 * 失败必须发生在 onOpen 之前：否则会出现「连接已建立但无身份」的中间态。
 */
final class WsHandshakeContext
{
    private const CONTEXT_KEY = 'ws.handshake.principal';

    public static function setPrincipal(Principal $principal): void
    {
        ConnectionContext::set(self::CONTEXT_KEY, $principal);
    }

    public static function principal(): ?Principal
    {
        $principal = ConnectionContext::get(self::CONTEXT_KEY);

        return $principal instanceof Principal ? $principal : null;
    }
}
