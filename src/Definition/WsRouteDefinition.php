<?php

declare(strict_types=1);

namespace Hyperf\Apex\WebSocket\Definition;

use Hyperf\Apex\DTO\AbstractDTO;

/**
 * 一个事件的处理契约，由 #[WsRoute] + 方法签名反射得出。
 */
final readonly class WsRouteDefinition
{
    /**
     * @param class-string $handlerClass
     * @param class-string<AbstractDTO> $payloadClass
     * @param null|class-string<AbstractDTO> $responseClass
     * @param null|int $contextPosition WsRequestContext 参数在签名中的位置
     */
    public function __construct(
        public string $event,
        public string $handlerClass,
        public string $method,
        public string $payloadClass,
        public ?string $responseClass,
        public ?int $contextPosition,
        public int $payloadPosition,
    ) {
    }

    /**
     * 报错与文档里标识处理器的字符串。
     */
    public function handlerId(): string
    {
        return $this->handlerClass . '::' . $this->method;
    }
}
