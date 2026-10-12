<?php

declare(strict_types=1);

namespace Hyperf\Apex\WebSocket\Service;

use Hyperf\Apex\DTO\AbstractDTO;
use Hyperf\Apex\Enum\ErrorCode;
use Hyperf\Apex\Exception\BusinessException;
use Hyperf\Apex\Http\ArgumentResolver\RequestDataHydrator;
use Hyperf\Apex\Logging\LoggerChannels;
use Hyperf\Apex\Security\Contract\PrincipalContextInterface;
use Hyperf\Apex\WebSocket\Contract\WsConnectionStoreInterface;
use Hyperf\Apex\WebSocket\Definition\WsRouteDefinition;
use Hyperf\Apex\WebSocket\DTO\WsSubscriptionResultDTO;
use Hyperf\Apex\WebSocket\Protocol\WsBuiltinEvent;
use Hyperf\Apex\WebSocket\Protocol\WsEnvelopeDTO;
use Hyperf\Apex\WebSocket\Protocol\WsResponseDTO;
use Hyperf\Apex\WebSocket\RateLimit\WsConnectionRateLimiter;
use Hyperf\Apex\WebSocket\Registry\WsHandlerRegistry;
use Hyperf\Apex\WebSocket\Session\WsRequestContext;
use Hyperf\Apex\WebSocket\Session\WsSession;
use Hyperf\Contract\ConfigInterface;
use Hyperf\Database\Model\ModelNotFoundException;
use Hyperf\Logger\LoggerFactory;
use Hyperf\Validation\ValidationException;
use JsonException;
use Psr\Container\ContainerInterface;
use Psr\Log\LoggerInterface;
use Throwable;

/**
 * 信封 → 校验 → 派发 → 回包。
 *
 * 唯一的硬约束：**任何业务异常都不得断开连接**。协议级错误（无法解析 JSON、缺少 event、
 * 未知 event）同样只回错误信封，由客户端决定是否重连 —— 断连的代价远高于一次错误响应。
 * 频率超限同理：回错误信封，不断连。
 */
final class WsDispatcher
{
    /**
     * 房间名：1-64 位字母、数字与 _ . : -，避免把任意用户输入塞进共享内存的键。
     */
    private const ROOM_PATTERN = '/^[A-Za-z0-9_.:-]{1,64}$/';

    private readonly LoggerInterface $logger;

    public function __construct(
        private readonly WsHandlerRegistry $handlers,
        private readonly WsConnectionStoreInterface $store,
        private readonly RequestDataHydrator $hydrator,
        private readonly PrincipalContextInterface $principalContext,
        private readonly ContainerInterface $container,
        private readonly ConfigInterface $config,
        private readonly WsConnectionRateLimiter $rateLimiter,
        LoggerFactory $loggerFactory,
    ) {
        $this->logger = LoggerChannels::resolve($loggerFactory, 'websocket', 'error');
    }

    public function dispatch(WsSession $session, string $raw): WsResponseDTO
    {
        // 限流放在最前面：它要挡的正是「大量消息」这件事本身，解析 JSON 也是要被挡下的成本之一。
        // 超限只回错误信封，不断开连接（与其它错误一致）——重连解决不了刷消息。
        if (! $this->rateLimiter->allow($session->id(), time())) {
            return WsResponseDTO::error(
                '',
                WsBuiltinEvent::ERROR->value,
                ErrorCode::TOO_MANY_REQUESTS,
                $this->rateLimiter->message(),
            );
        }

        try {
            /** @var mixed $decoded */
            $decoded = json_decode($raw, true, 512, JSON_THROW_ON_ERROR);
        } catch (JsonException) {
            return WsResponseDTO::error('', WsBuiltinEvent::ERROR->value, ErrorCode::INVALID_ARGUMENT, '消息体不是合法 JSON。');
        }

        try {
            $envelope = WsEnvelopeDTO::fromArray($decoded);
        } catch (BusinessException $exception) {
            return WsResponseDTO::error(
                self::peek($decoded, 'id'),
                WsBuiltinEvent::ERROR->value,
                $exception->getErrorCode(),
                $exception->getMessage(),
            );
        }

        $id = $envelope->replyId();

        try {
            return $this->handle($session, $envelope);
        } catch (BusinessException $exception) {
            return WsResponseDTO::error($id, $envelope->event, $exception->getErrorCode(), $exception->getMessage(), $exception->getData());
        } catch (ValidationException $exception) {
            return WsResponseDTO::error(
                $id,
                $envelope->event,
                ErrorCode::VALIDATION_FAILED,
                ErrorCode::VALIDATION_FAILED->getMessage(),
                ['errors' => $exception->errors()],
            );
        } catch (ModelNotFoundException) {
            // 与 HTTP 侧 AppExceptionHandler 的映射保持一致：资源不存在不该在 WS 上变成 500。
            return WsResponseDTO::error($id, $envelope->event, ErrorCode::RESOURCE_NOT_FOUND);
        } catch (Throwable $throwable) {
            $this->logger->error('WebSocket 事件处理失败。', [
                'event' => $envelope->event,
                'connection' => $session->id(),
                'exception' => $throwable,
            ]);

            return WsResponseDTO::error($id, $envelope->event, ErrorCode::INTERNAL_ERROR);
        }
    }

    private function handle(WsSession $session, WsEnvelopeDTO $envelope): WsResponseDTO
    {
        $id = $envelope->replyId();
        $builtin = WsBuiltinEvent::tryFrom($envelope->event);

        if ($builtin !== null) {
            if (! $builtin->isClientInitiated()) {
                throw new BusinessException(ErrorCode::INVALID_ARGUMENT, sprintf('事件 %s 由服务端发出，客户端不得调用。', $envelope->event));
            }

            return match ($builtin) {
                WsBuiltinEvent::PING => WsResponseDTO::success($id, WsBuiltinEvent::PONG->value),
                WsBuiltinEvent::SUBSCRIBE => $this->subscribe($session, $envelope, $id),
                WsBuiltinEvent::UNSUBSCRIBE => $this->unsubscribe($session, $envelope, $id),
                default => throw new BusinessException(ErrorCode::INVALID_ARGUMENT, sprintf('未支持的内置事件 %s。', $envelope->event)),
            };
        }

        return $this->handleBusiness($session, $envelope, $id);
    }

    private function subscribe(WsSession $session, WsEnvelopeDTO $envelope, string $id): WsResponseDTO
    {
        $room = $this->room($envelope);
        $maxRooms = max(1, (int) $this->config->get('websocket.max_rooms_per_connection', 20));

        if (! $session->inRoom($room) && count($session->rooms()) >= $maxRooms) {
            throw new BusinessException(ErrorCode::RESOURCE_CONFLICT, sprintf('单个连接最多订阅 %d 个房间。', $maxRooms));
        }

        if (! $this->store->joinRoom($session->id(), $room)) {
            throw new BusinessException(ErrorCode::RESOURCE_CONFLICT, sprintf('房间 %s 的成员已达上限。', $room));
        }

        $session->joinRoom($room);

        return WsResponseDTO::success($id, $envelope->event, new WsSubscriptionResultDTO($room, $session->rooms()));
    }

    private function unsubscribe(WsSession $session, WsEnvelopeDTO $envelope, string $id): WsResponseDTO
    {
        $room = $this->room($envelope);
        $this->store->leaveRoom($session->id(), $room);
        $session->leaveRoom($room);

        return WsResponseDTO::success($id, $envelope->event, new WsSubscriptionResultDTO($room, $session->rooms()));
    }

    private function handleBusiness(WsSession $session, WsEnvelopeDTO $envelope, string $id): WsResponseDTO
    {
        $definition = $this->handlers->find($envelope->event);
        if ($definition === null) {
            throw new BusinessException(ErrorCode::INVALID_ARGUMENT, sprintf('未注册的事件：%s。', $envelope->event));
        }

        $payload = $this->hydrator->hydrate($definition->payloadClass, $envelope->data);
        $result = $this->invoke($definition, $session, $payload);

        return WsResponseDTO::success($id, $envelope->event, $result instanceof AbstractDTO ? $result : []);
    }

    private function invoke(WsRouteDefinition $definition, WsSession $session, AbstractDTO $payload): mixed
    {
        $arguments = [];
        if ($definition->contextPosition !== null) {
            $arguments[$definition->contextPosition] = new WsRequestContext($session);
        }
        $arguments[$definition->payloadPosition] = $payload;
        ksort($arguments);

        $handler = $this->container->get($definition->handlerClass);

        $principal = $session->principal;
        if ($principal === null) {
            return $handler->{$definition->method}(...array_values($arguments));
        }

        // 业务 Service 通过 PrincipalContextInterface 取当前身份（与 HTTP 请求期一致）。
        // onMessage 是独立协程，这里把连接身份投影过去，处理完立即清理，避免串到别的协程。
        $this->principalContext->set($principal);
        try {
            return $handler->{$definition->method}(...array_values($arguments));
        } finally {
            $this->principalContext->clear();
        }
    }

    /**
     * @throws BusinessException 房间名缺失或不合法
     */
    private function room(WsEnvelopeDTO $envelope): string
    {
        $room = $envelope->data['room'] ?? null;
        if (! is_string($room) || preg_match(self::ROOM_PATTERN, $room) !== 1) {
            throw new BusinessException(ErrorCode::INVALID_ARGUMENT, '房间名必须是 1-64 位的字母、数字或 _ . : - 之一。');
        }

        return $room;
    }

    /**
     * 从解析失败的报文里尽力取回 id：客户端靠它匹配请求与响应，拿不到就只能留空。
     */
    private static function peek(mixed $decoded, string $key): string
    {
        if (! is_array($decoded)) {
            return '';
        }

        $value = $decoded[$key] ?? null;

        return is_string($value) ? $value : '';
    }
}
