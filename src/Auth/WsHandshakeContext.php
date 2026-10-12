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

    /**
     * 清掉中转身份，由入口在组装完 WsSession 之后调用。
     *
     * 这一层的值只服务于「握手中间件 → onOpen」这一跳：连接的身份此后由 WsSession 承载，
     * 继续留在连接级 Context 里除了占位没有作用，还会让后来读它的人误以为身份来自握手。
     *
     * 置空而不是 ConnectionContext::destroy()：框架的 destroy() 用 `$container[$fd][$id]`
     * 删键，而 set() 走的是点号路径写入（`{fd}.{id}` 展开成嵌套数组），
     * 键里带点的 destroy() 根本删不到东西。
     */
    public static function clear(): void
    {
        ConnectionContext::set(self::CONTEXT_KEY, null);
    }
}
