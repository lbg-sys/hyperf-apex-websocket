<?php

declare(strict_types=1);

namespace Hyperf\Apex\WebSocket\Controller;

use Hyperf\Apex\WebSocket\Attribute\WsGateway;
use Hyperf\Apex\WebSocket\Auth\WsIntegrationHandshakeMiddleware;

/**
 * 设备端连接入口。
 *
 * 与管理端入口分开而不是「一个入口按 token 类型分流」：分流需要先尝试解析再判断，
 * 失败路径不清晰，且无法在启动期校验中间件是否挂全。
 */
#[WsGateway(
    path: '/ws/device',
    server: 'ws',
    middlewares: [WsIntegrationHandshakeMiddleware::class],
    endpoint: 'device',
    name: '设备端连接',
)]
final class DeviceWsGatewayController extends WsGatewayController
{
}
