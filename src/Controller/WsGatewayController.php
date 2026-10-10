<?php

declare(strict_types=1);

namespace Hyperf\Apex\WebSocket\Controller;

use Hyperf\Apex\Enum\ErrorCode;
use Hyperf\Apex\WebSocket\Auth\WsHandshakeContext;
use Hyperf\Apex\WebSocket\Contract\WsConnectionStoreInterface;
use Hyperf\Apex\WebSocket\Protocol\WsBuiltinEvent;
use Hyperf\Apex\WebSocket\Protocol\WsResponseDTO;
use Hyperf\Apex\WebSocket\Registry\WsGatewayScanner;
use Hyperf\Apex\WebSocket\Service\WsDispatcher;
use Hyperf\Apex\WebSocket\Session\WsSession;
use Hyperf\Context\Context as CoContext;
use Hyperf\Contract\ConfigInterface;
use Hyperf\Contract\OnCloseInterface;
use Hyperf\Contract\OnMessageInterface;
use Hyperf\Contract\OnOpenInterface;
use Hyperf\Logger\LoggerFactory;
use Hyperf\WebSocketServer\Collector\FdCollector;
use Hyperf\WebSocketServer\Context as ConnectionContext;
use Hyperf\WebSocketServer\Sender;
use Psr\Log\LoggerInterface;
use Swoole\Http\Request as SwooleRequest;
use Throwable;

/**
 * 连接入口的公共实现：一个入口一个子类，子类只负责带 #[WsGateway] 注解。
 *
 * 这里同时是「连接生命周期」的唯一落点：
 * - onOpen  建会话 + 写连接索引
 * - onMessage 交给 WsDispatcher，响应一律回信封
 * - onClose  精确退订房间 + 注销索引 + 清理连接级 Context
 */
abstract class WsGatewayController implements OnCloseInterface, OnMessageInterface, OnOpenInterface
{
    private readonly LoggerInterface $logger;

    public function __construct(
        private readonly WsGatewayScanner $gateways,
        private readonly WsDispatcher $dispatcher,
        private readonly WsConnectionStoreInterface $store,
        private readonly Sender $sender,
        private readonly ConfigInterface $config,
        LoggerFactory $loggerFactory,
    ) {
        $this->logger = $loggerFactory->get('websocket', 'error');
    }

    /**
     * `$server` 显式声明成 `mixed`：`OnOpenInterface` 把它标注成
     * `Response|Server|ServerConnection`，最后一个是 Swow 的类，本项目跑 Swoole、没有装 Swow，
     * 该类型无法解析、父接口的形参因此退化成 `mixed`。子类要满足逆变就必须同样宽，
     * 写 `object` 反而会被判为「比父类更窄」。
     *
     * 连接的真实类型由运行期决定，这里不做假设；fd 的取得走 resolveFd() 的运行时判断。
     * @param mixed $request
     */
    public function onOpen(mixed $server, $request): void
    {
        $fd = $this->resolveFd($request);
        if ($fd <= 0) {
            // 这里不能静默返回：拿不到 fd 就建不了会话，之后每一条消息都会因为「没有会话」被断开，
            // 现象是「握手成功、随即被服务端关闭」，而日志里什么都没有。
            $this->logger->error('WebSocket onOpen 无法确定连接 fd，连接无法建立。');
            return;
        }

        $definition = $this->gateways->forClass(static::class);
        if ($definition === null) {
            // 没有注解说明路由不该存在；启动期校验会拦住，这里只兜底不猜身份。
            $this->logger->error(sprintf('WebSocket 入口 %s 缺少 #[WsGateway] 声明。', static::class));
            $this->sender->disconnect($fd);

            return;
        }

        // onOpen 由框架在 defer/wait 回调里触发，协程 Context 里的 ws.fd 不保证还在，
        // 而连接级 Context 的键是「当前 fd + 数据键」——这里显式补写一次，让下面两次写入落到正确的键上。
        CoContext::set(ConnectionContext::FD, $fd);

        $session = WsSession::open(
            $definition->server,
            $fd,
            $definition->endpoint,
            WsHandshakeContext::principal(),
            time(),
        );
        $session->store();

        try {
            $this->store->register($session);
        } catch (Throwable $throwable) {
            // 索引写不进去（表满、超上限）时连接不能假装建立：注册成功才代表推送找得到它。
            $this->logger->error('WebSocket 连接注册失败。', ['exception' => $throwable]);
            WsSession::clear($fd);
            $this->sender->disconnect($fd);
        }
    }

    public function onMessage($server, $frame): void
    {
        $fd = (int) ($frame->fd ?? 0);
        $session = WsSession::find($fd);
        if ($session === null) {
            // 没有会话说明连接没走完 onOpen（或已被清理），此时不能凭 fd 猜身份。
            $this->logger->warning(sprintf('WebSocket fd[%d] 没有会话，断开连接。', $fd));
            $this->sender->disconnect($fd);

            return;
        }

        $payload = (string) ($frame->data ?? '');
        $maxBytes = (int) $this->config->get('websocket.max_frame_bytes', 65536);
        if ($maxBytes > 0 && strlen($payload) > $maxBytes) {
            $this->respond($fd, WsResponseDTO::error(
                '',
                WsBuiltinEvent::ERROR->value,
                ErrorCode::INVALID_ARGUMENT,
                sprintf('消息体超过单帧上限 %d 字节。', $maxBytes),
            ));

            return;
        }

        $this->respond($fd, $this->dispatcher->dispatch($session, $payload));
    }

    public function onClose($server, int $fd, int $reactorId): void
    {
        $session = WsSession::find($fd);
        if ($session !== null) {
            // 按会话记录的 rooms 精确退订：只清连接行会让房间表里留下永远清不掉的脏 fd。
            foreach ($session->rooms() as $room) {
                $this->store->leaveRoom($session->id(), $room);
            }
            $this->store->unregister($session->id());
        }

        // 三处都要清。FdCollector 与连接级 Context 框架也会在 onClose 的 defer 里清一次，
        // 这里显式再做一遍，不依赖框架实现细节：漏清会让内核复用 fd 后读到上一个连接的身份。
        FdCollector::del($fd);
        WsSession::clear($fd);
    }

    private function respond(int $fd, WsResponseDTO $response): void
    {
        if (! $this->sender->push($fd, $response->toJson())) {
            $this->logger->warning(sprintf('WebSocket 响应投递失败，fd=%d。', $fd));
        }
    }

    /**
     * 从握手请求推断当前 fd。
     *
     * 优先取 Swoole 握手请求自带的 fd：onOpen 所在的回调形态由框架决定
     * （协程服务器走 wait、经典 Swoole\WebSocket\Server 走 defer），
     * 只有握手请求本身是两种形态都带着的可靠来源。退化路径才读协程 Context。
     */
    private function resolveFd(mixed $request): int
    {
        if ($request instanceof SwooleRequest && (int) $request->fd > 0) {
            return (int) $request->fd;
        }

        return (int) ConnectionContext::get(ConnectionContext::FD, 0);
    }
}
