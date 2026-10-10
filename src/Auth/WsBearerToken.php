<?php

declare(strict_types=1);

namespace Hyperf\Apex\WebSocket\Auth;

use Psr\Http\Message\ServerRequestInterface;

/**
 * 从握手请求里取 token。
 *
 * 浏览器 WebSocket API 无法自定义请求头，token 只有两个可放的位置：
 * query 参数，或 Sec-WebSocket-Protocol 子协议。两者都支持，query 优先。
 */
final class WsBearerToken
{
    public static function token(ServerRequestInterface $request, string $queryParam): string
    {
        $queries = $request->getQueryParams();
        $token = $queries[$queryParam] ?? null;
        if (is_string($token) && trim($token) !== '') {
            return trim($token);
        }

        foreach ($request->getHeader('Sec-WebSocket-Protocol') as $header) {
            foreach (explode(',', $header) as $protocol) {
                $protocol = trim($protocol);
                if ($protocol !== '') {
                    return $protocol;
                }
            }
        }

        return '';
    }
}
