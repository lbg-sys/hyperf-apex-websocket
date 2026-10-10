<?php

declare(strict_types=1);

namespace Hyperf\Apex\WebSocket\Auth;

use Hyperf\Apex\Enum\ErrorCode;
use Hyperf\Apex\Exception\BusinessException;
use Hyperf\Apex\Http\Middleware\AbstractMiddleware;
use Hyperf\Contract\ConfigInterface;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\RequestHandlerInterface;

/**
 * 设备端握手鉴权：集成 token，比对 config('integration.integration_token')。
 *
 * 与 IntegrationTokenMiddleware 同一套判据，只是 token 的来源不同：浏览器 WebSocket API
 * 无法自定义请求头，所以 token 只能走 query 或 Sec-WebSocket-Protocol，不能走 header。
 *
 * 校验通过不写入 Principal：集成 token 标识的是「接入方」而不是某个用户，
 * 设备端连接因此没有用户身份，推送寻址用 endpoint / room。
 */
final class WsIntegrationHandshakeMiddleware extends AbstractMiddleware
{
    /**
     * 握手参数名。与 HTTP 侧的 X-Integration-Token 头对应，这里只能放到 URL 上。
     */
    public const QUERY_PARAM = 'token';

    public function __construct(private readonly ConfigInterface $config)
    {
    }

    public function process(ServerRequestInterface $request, RequestHandlerInterface $handler): ResponseInterface
    {
        $provided = WsBearerToken::token($request, self::QUERY_PARAM);
        $expected = (string) $this->config->get('integration.integration_token', '');
        if ($provided === '' || $expected === '' || ! hash_equals($expected, $provided)) {
            // 未配置集成 token 时同样按未认证处理，不把配置缺失暴露给客户端；
            // 真正的配置缺失由 EnvironmentConfigCheckListener 在启动期拦下。
            throw new BusinessException(ErrorCode::UNAUTHENTICATED);
        }

        return $handler->handle($request);
    }
}
