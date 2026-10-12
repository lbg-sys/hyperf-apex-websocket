# hyperf-apex/websocket

WebSocket 长连接网关：多端协议封装、跨 Worker 会话存储、Swoole Table 连接索引、空闲回收与单连接限流。

- **依赖**：`hyperf-apex/core`、`hyperf/websocket-server`
- **可选**：`hyperf/redis` —— 只有把房间成员索引放到 Redis（`websocket.room_store_driver=redis`）时才需要
- **配套**：`hyperf-apex/apidoc-apifox` 可把这里的声明渲染成 Apifox 的 WebSocket 契约
- **完整文档**：见主仓库 [hyperf-apex](https://github.com/lbg-sys/hyperf-apex)
- **变更记录**：见主仓库 [CHANGELOG.md](https://github.com/lbg-sys/hyperf-apex/blob/master/CHANGELOG.md)

## 安装

```bash
composer require hyperf-apex/websocket
php bin/hyperf.php vendor:publish hyperf-apex/websocket --id=config
```

不发布配置也能跑：包内 `ConfigProvider` 已经下发同一套默认值（发布之后配置文件覆盖它）。

## 配置项

| 键 | 环境变量（默认值） | 说明 |
| :--- | :--- | :--- |
| `websocket.enabled` | `WS_ENABLED`（`true`） | 关掉后不注册网关（声明与运行配置检查也一并跳过） |
| `websocket.path` | `WS_PATH`（`/ws`） | 默认入口路径前缀 |
| `websocket.max_connections_per_user` | `WS_MAX_CONN_PER_USER`（10） | 单用户连接数上限，同时决定用户索引行的列宽 |
| `websocket.max_rooms_per_connection` | `WS_MAX_ROOMS`（20） | 单连接订阅房间上限 |
| `websocket.max_frame_bytes` | `WS_MAX_FRAME_BYTES`（65536） | 单帧上限，需 ≤ `server.settings.package_max_length` |
| `websocket.rate_limit.window_seconds` | `WS_RATE_LIMIT_WINDOW`（1） | 单连接限流窗口（秒） |
| `websocket.rate_limit.max_messages` | `WS_RATE_LIMIT_MAX`（50） | 窗口内消息数上限，`0` 表示关闭 |
| `websocket.table_size` | `WS_TABLE_SIZE`（65536） | 连接索引的**主槽位数**（见下方「定长表的容量语义」） |
| `websocket.room_member_capacity` | `WS_ROOM_MEMBER_CAPACITY`（128） | 单房间成员上限（table 驱动下即列宽） |
| `websocket.room_table_size` | `WS_ROOM_TABLE_SIZE`（2048） | 房间索引表行数（仅 table 驱动） |
| `websocket.room_store_driver` | `WS_ROOM_STORE_DRIVER`（`table`） | 房间成员索引的后端：`table` 或 `redis` |
| `websocket.room_store_redis_pool` | `WS_ROOM_STORE_REDIS_POOL`（`default`） | Redis 房间索引使用的连接池 |
| `websocket.room_store_prefix` | `WS_ROOM_STORE_PREFIX`（`core:wsroom:`） | Redis 房间索引的键前缀 |
| `websocket.idle_timeout` | `WS_IDLE_TIMEOUT`（180） | 空闲连接回收时限（秒） |
| `websocket.idle_check_interval` | `WS_IDLE_CHECK_INTERVAL`（30） | 空闲扫描间隔（秒） |
| `websocket.allow_multi_worker` | `WS_ALLOW_MULTI_WORKER`（`false`） | 显式接受多 Worker 下「推送只到当前 Worker」的限制 |
| `websocket.test_entry_enabled` | `WS_TEST_ENTRY_ENABLED`（`false`） | 本地人工测试入口开关；入口由应用自行提供（不在版本管理内） |
| `websocket.excluded` | — | 不进对外契约文档的入口与处理器类（不影响路由注册） |

### 定长表的容量语义

`table_size` 是「主槽位数」，不是「能装下的连接数」的硬保证：Swoole\Table 会把行数向上取整到
2 的幂（最小 64），并且预留的 hash 冲突槽会先于主槽耗尽 —— 冲突槽用完后 `set()` 直接返回
`false`，表现为新连接被拒绝。按峰值再留余量设置。

另外两类「超长只截断、不报错」的限制由组件在写入前主动拦截（见 `Support\TableLimits`）：
连接 id（`{server}:{fd}`）与用户类型的上限是 32 字节，**server 名与 `endpoint` 都不宜很长**。

## 运行前置（启动期会校验，不满足直接起不来）

| 条件 | 为什么 |
| :--- | :--- |
| 协程服务器下 `server.settings.worker_num` 必须为 `1` | `Sender` 只能在当前 Worker 内投递，多 Worker 会让推送给另一条连接的消息静默丢失；确实只收不发时用 `websocket.allow_multi_worker=true` 显式接受。传统 `Hyperf\Server\Server` 会广播 PipeMessage，不受此限 |
| `server.settings.package_max_length` ≥ `websocket.max_frame_bytes` | 否则超限帧在协议层就被 Swoole 断开，客户端拿到的是断连而不是错误信封 |
| `websocket.idle_timeout` 与 Swoole 的 `heartbeat_idle_time` 不能同时为 0 | 连接索引是定长表：网络中断不触发 `onClose`，写满后新连接被直接拒绝且不会自愈。注意 `heartbeat_idle_time` 只在传统 `Hyperf\Server\Server` 下生效 —— 协程服务器用的是 `Swoole\Coroutine\Http\Server`，**不认**这个设置，因此协程模式下必须给 `idle_timeout` 一个正数 |
| `websocket.room_store_driver` 必须是 `table` 或 `redis`，且选 `redis` 时已装 `hyperf/redis` | 驱动名写错会静默走默认实现；没装 Redis 客户端时选 redis 驱动连类都解析不了 |

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
- 入口的 `server` 必须是 `server.php` 里 `type` 为 `SERVER_WEBSOCKET` 的服务名。
- **不要求「至少有一个入口」**：装了元包却不用长连接的应用（`server.php` 里没有 WebSocket 服务）不会因为本包而启动失败；但**配了 WebSocket 服务却没有任何入口**会在启动期直接报错，否则客户端握手只会拿到 404。
- 处理器签名由启动期反射校验：一个 payload DTO（`AbstractDTO` 子类）+ 可选的 `WsRequestContext`，返回 `void` 或响应 DTO。
- 处理器实例由容器解析并被缓存（Hyperf `Container::get()` 的结果是进程级单例）：**跨连接、跨消息复用**，因此不得把连接级状态放进属性，连接身份一律走 `WsRequestContext` 参数拿。
- 事件名格式为 `{domain}.{action}`（全小写、点号分隔）；`sys.` 前缀由内置事件保留，注册它会启动失败。

## 鉴权

- **集成凭证**：入口中间件挂 `WsIntegrationHandshakeMiddleware`，token 走 query 参数 `token`（浏览器 WebSocket API 无法自定义请求头），与 HTTP 侧的 `#[IntegrationAccess]` 共用同一个 `integration.integration_token` 配置、同一套判据。**token 会因此出现在网关与访问日志里**，部署时应在网关层屏蔽该参数的记录。
- **用户身份**：组件不内置登录态鉴权（它属于应用的身份体系）。应用自建握手中间件，校验通过后调用 `WsHandshakeContext::setPrincipal($principal)` 交出身份，入口会在 `onOpen` 时把它组装进会话；token 解析可复用 `WsBearerToken::token($request, 'token')`。
- 校验失败必须发生在 `onOpen` 之前（中间件里抛 `BusinessException` 即可），否则会出现「连接已建立但没有身份」的中间态。

## 推送

业务侧只依赖 `WsPusherInterface`：`pushToUser()` / `pushToRoom()` / `pushToEndpoint()` / `broadcast()`。
**不承诺可靠投递** —— 连接断开期间的推送即丢失，需要可靠性的业务走 HTTP 拉取。

## 房间与存储后端

房间订阅走内置事件 `sys.subscribe` / `sys.unsubscribe`，房间成员索引与连接索引是两份独立契约，
因为两者的跨节点诉求不同：fd 是单机概念，房间成员则常常要跨节点可见。

| 驱动 | 存储 | 适用 |
| :--- | :--- | :--- |
| `table`（默认） | `Swoole\Table` 共享内存 | 单机部署：零额外依赖、零网络开销 |
| `redis` | Redis 集合（一次订阅一次 RTT） | 多台网关共用一个房间：用户在 A 机、推送发起在 B 机 |

Redis 驱动的键结构（前缀取自 `websocket.room_store_prefix`）：

- `<prefix>room:<房间名>`：成员集合，元素是连接 id；
- `<prefix>rooms`：房间名索引集合，供 O(1) 的房间计数。

「判重 + 判容量 + 写入」在单段 Lua 里完成，跨节点并发订阅同一房间不会互相覆盖。两点需要知道：

- 脚本一次操作两个键，Redis Cluster 下两者必须落在同一 slot（本实现未加 hash tag，集群部署需自行规划键前缀）；
- Redis 访问失败**不降级**：房间成员读不到就静默当成「没有成员」会让推送发给错的集合，比抛错更危险。

房间清理不依赖 TTL，与 table 驱动同一口径：连接断开时入口按会话记录的房间精确退订，推送时还会顺手剔除
「成员还在、连接行已消失」的失效 id（惰性自愈）；最后一个成员离开即删除房间键。

## 协议

| 方向 | 报文 |
| :--- | :--- |
| 客户端 → 服务端 | `{"id": "1", "event": "demo.created", "data": {...}}`，`data` 必须是 JSON 对象 |
| 服务端 → 客户端（响应） | `{"id": "1", "event": "demo.created", "code": 0, "message": "成功", "data": {...}}` |
| 服务端 → 客户端（推送） | `{"id": "...", "event": "demo.created", "type": "push", "data": {...}}` |

内置事件：`sys.ping`（心跳，客户端定期发）、`sys.pong`、`sys.subscribe` / `sys.unsubscribe`（房间订阅，`data.room` 为 1-63 位 `[A-Za-z0-9_.:-]`）、`sys.error`（所有失败信封的 `event`）。

约定：

- **任何业务错误都只回错误信封，不断开连接**（含协议错误、限流、未注册事件、业务拒绝），客户端的重连策略只用于处理真正的网络断开。
- 房间名上限是 63 字符而不是 64：房间索引以房间名为键，而 `Swoole\Table` 的键上限固定 63 字节、超长即截断（两个前 63 字节相同的房间名会静默落到同一行）。Redis 驱动本没有这个限制，但同一条协议不该在两个驱动下表现不同。
- 单连接消息限流默认「1 秒 50 条」，超限回 `10007`（`TOO_MANY_REQUESTS`）；用 `websocket.rate_limit.max_messages=0` 关闭。
- 客户端必须在 `websocket.idle_timeout` 内至少发送一条消息（通常就是 `sys.ping`），否则连接会被服务端回收。
- 二进制帧直接回「只接受文本帧」的错误信封，不丢给 JSON 解析。

## 需要自行实现的契约

| 契约 | 说明 |
| :--- | :--- |
| `Hyperf\Apex\WebSocket\Contract\WsConnectionStoreInterface` | 连接索引与用户索引（默认 `Swoole\Table` 实现）。换存储需实现 `hasConnection()` / `touch()` / `idleConnections()` |
| `Hyperf\Apex\WebSocket\Contract\WsRoomStoreInterface` | 房间成员索引。组件内提供 `table` 与 `redis` 两种实现，按 `websocket.room_store_driver` 选择；换别的存储需实现 `joinRoom()` / `leaveRoom()` / `connectionsByRoom()` / `countRooms()` |
