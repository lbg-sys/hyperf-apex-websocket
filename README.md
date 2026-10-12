# hyperf-apex/websocket

WebSocket 长连接网关：多端协议封装、跨 Worker 会话存储、Swoole Table 连接索引、空闲回收与单连接限流。

- **依赖**：`hyperf-apex/core`、`hyperf/websocket-server`
- **配套**：`hyperf-apex/apidoc-apifox` 可把这里的声明渲染成 Apifox 的 WebSocket 契约
- **完整文档**：见主仓库 [hyperf-apex](https://github.com/lbg-sys/hyperf-apex)
- **变更记录**：见主仓库 [CHANGELOG.md](https://github.com/lbg-sys/hyperf-apex/blob/master/CHANGELOG.md)

## 安装

```bash
composer require hyperf-apex/websocket
php bin/hyperf.php vendor:publish hyperf-apex/websocket --id=config
```

## 配置项

| 键 | 环境变量（默认值） | 说明 |
| :--- | :--- | :--- |
| `websocket.enabled` | `WS_ENABLED`（`true`） | 关掉后不注册网关（声明检查也一并跳过） |
| `websocket.path` | `WS_PATH`（`/ws`） | 默认入口路径 |
| `websocket.max_connections_per_user` | `WS_MAX_CONN_PER_USER`（10） | 单用户连接数上限 |
| `websocket.max_rooms_per_connection` | `WS_MAX_ROOMS`（20） | 单连接订阅房间上限 |
| `websocket.max_frame_bytes` | `WS_MAX_FRAME_BYTES`（65536） | 单帧上限，需 ≤ `server.settings.package_max_length` |
| `websocket.rate_limit.window_seconds` | `WS_RATE_LIMIT_WINDOW`（1） | 单连接限流窗口（秒） |
| `websocket.rate_limit.max_messages` | `WS_RATE_LIMIT_MAX`（50） | 窗口内消息数上限，`0` 表示关闭 |
| `websocket.table_size` | `WS_TABLE_SIZE`（65536） | 连接索引行数（定长） |
| `websocket.room_member_capacity` | `WS_ROOM_MEMBER_CAPACITY`（128） | 单房间成员上限 |
| `websocket.room_table_size` | `WS_ROOM_TABLE_SIZE`（2048） | 房间表行数 |
| `websocket.idle_timeout` | `WS_IDLE_TIMEOUT`（180） | 空闲连接回收时限（秒） |
| `websocket.idle_check_interval` | `WS_IDLE_CHECK_INTERVAL`（30） | 空闲扫描间隔（秒） |
| `websocket.allow_multi_worker` | `WS_ALLOW_MULTI_WORKER`（`false`） | 显式接受多 Worker 下「推送只到当前 Worker」的限制 |
| `websocket.test_entry_enabled` | `WS_TEST_ENTRY_ENABLED`（`false`） | 本地人工测试入口开关；入口由应用自行提供（不在版本管理内） |
| `websocket.excluded` | — | 排除的网关类 |

## 运行前置（启动期会校验，不满足直接起不来）

| 条件 | 为什么 |
| :--- | :--- |
| 协程服务器下 `server.settings.worker_num` 必须为 `1` | `Sender` 只能在当前 Worker 内投递，多 Worker 会让推送给另一条连接的消息静默丢失；确实只收不发时用 `websocket.allow_multi_worker=true` 显式接受。传统 `Hyperf\Server\Server` 会广播 PipeMessage，不受此限 |
| `server.settings.package_max_length` ≥ `websocket.max_frame_bytes` | 否则超限帧在协议层就被 Swoole 断开，客户端拿到的是断连而不是错误信封 |
| `websocket.idle_timeout` 与 Swoole 的 `heartbeat_idle_time` 不能同时为 0 | 连接索引是定长表：网络中断不触发 `onClose`，写满后新连接被直接拒绝且不会自愈 |

## 声明入口与事件

```php
#[WsGateway(path: '/ws/hub', server: 'ws', endpoint: 'hub', name: '主连接', middlewares: [WsIntegrationHandshakeMiddleware::class])]
final class HubWsGatewayController extends WsGatewayController
{
}

final class HubEventHandler
{
    #[WsRoute('demo.created')]
    public function handle(DemoCreatedPayloadDTO $payload, WsRequestContext $context): DemoCreatedResultDTO
    {
        return new DemoCreatedResultDTO($payload->recordId, $context->connectionId());
    }
}
```

- 入口类必须实现 `OnOpenInterface` / `OnMessageInterface` / `OnCloseInterface`（继承 `WsGatewayController` 即可，子类只负责带注解）。
- 处理器签名由启动期反射校验：一个 payload DTO（`AbstractDTO` 子类）+ 可选的 `WsRequestContext`，返回 `void` 或响应 DTO。
- 事件名格式为 `{domain}.{action}`（全小写、点号分隔）；`sys.` 前缀由内置事件保留，注册它会启动失败。

## 鉴权

- **集成凭证**：入口中间件挂 `WsIntegrationHandshakeMiddleware`，token 走 query 参数 `token`（浏览器 WebSocket API 无法自定义请求头），与 HTTP 侧的 `#[IntegrationAccess]` 共用同一个 `integration.integration_token` 配置、同一套判据。**token 会因此出现在网关与访问日志里**，部署时应在网关层屏蔽该参数的记录。
- **用户身份**：组件不内置登录态鉴权（它属于应用的身份体系）。应用自建握手中间件，校验通过后调用 `WsHandshakeContext::setPrincipal($principal)` 交出身份，入口会在 `onOpen` 时把它组装进会话；token 解析可复用 `WsBearerToken::token($request, 'token')`。
- 校验失败必须发生在 `onOpen` 之前（中间件里抛 `BusinessException` 即可），否则会出现「连接已建立但没有身份」的中间态。

## 推送

业务侧只依赖 `WsPusherInterface`：`pushToUser()` / `pushToRoom()` / `pushToEndpoint()` / `broadcast()`。
**不承诺可靠投递** —— 连接断开期间的推送即丢失，需要可靠性的业务走 HTTP 拉取。

## 协议

| 方向 | 报文 |
| :--- | :--- |
| 客户端 → 服务端 | `{"id": "1", "event": "demo.created", "data": {...}}`，`data` 必须是 JSON 对象 |
| 服务端 → 客户端（响应） | `{"id": "1", "event": "demo.created", "code": 0, "message": "成功", "data": {...}}` |
| 服务端 → 客户端（推送） | `{"id": "...", "event": "demo.created", "type": "push", "data": {...}}` |

内置事件：`sys.ping`（心跳，客户端定期发）、`sys.pong`、`sys.subscribe` / `sys.unsubscribe`（房间订阅，`data.room` 为 1-64 位 `[A-Za-z0-9_.:-]`）、`sys.error`（所有失败信封的 `event`）。

约定：

- **任何业务错误都只回错误信封，不断开连接**（含协议错误、限流、未注册事件、业务拒绝），客户端的重连策略只用于处理真正的网络断开。
- 单连接消息限流默认「1 秒 50 条」，超限回 `10007`（`TOO_MANY_REQUESTS`）；用 `websocket.rate_limit.max_messages=0` 关闭。
- 客户端必须在 `websocket.idle_timeout` 内至少发送一条消息（通常就是 `sys.ping`），否则连接会被服务端回收。
- 二进制帧直接回「只接受文本帧」的错误信封，不丢给 JSON 解析。

## 需要自行实现的契约

| 契约 | 说明 |
| :--- | :--- |
| `Hyperf\Apex\WebSocket\Contract\WsConnectionStoreInterface` | 连接索引（默认 Swoole Table 实现）。换存储（如 Redis）需实现 `hasConnection()` / `touch()` / `idleConnections()` |
