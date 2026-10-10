<?php

declare(strict_types=1);

namespace Hyperf\Apex\WebSocket\Protocol;

use Hyperf\Apex\DTO\DTOJsonableTrait;
use Hyperf\Apex\Enum\ErrorCode;
use Hyperf\Apex\Exception\BusinessException;
use Hyperf\Contract\Arrayable;
use Hyperf\Contract\Jsonable;
use JsonSerializable;

/**
 * 客户端 → 服务端请求信封：{ id, event, data }。
 *
 * 字段名由本项目的线上契约固定，属性名与报文键名一致。
 * data 只做「是 JSON 对象」的形状校验，字段级校验交给 payload DTO。
 *
 * @implements Arrayable<string, mixed>
 */
final readonly class WsEnvelopeDTO implements Arrayable, Jsonable, JsonSerializable
{
    use DTOJsonableTrait;

    /**
     * @param array<string, mixed> $data
     */
    private function __construct(
        public ?string $id,
        public string $event,
        public array $data,
    ) {
    }

    /**
     * @throws BusinessException 报文形状不合法时抛出，调用方转成错误信封
     */
    public static function fromArray(mixed $raw): self
    {
        if (! is_array($raw)) {
            throw new BusinessException(ErrorCode::INVALID_ARGUMENT, '消息体必须是 JSON 对象。');
        }

        $event = $raw['event'] ?? null;
        if (! is_string($event) || trim($event) === '') {
            throw new BusinessException(ErrorCode::INVALID_ARGUMENT, '消息缺少 event 字段。');
        }

        $id = $raw['id'] ?? null;
        if ($id !== null && ! is_string($id)) {
            throw new BusinessException(ErrorCode::INVALID_ARGUMENT, '消息 id 必须是字符串。');
        }

        $data = $raw['data'] ?? [];
        if (! is_array($data)) {
            throw new BusinessException(ErrorCode::INVALID_ARGUMENT, '消息 data 必须是 JSON 对象。');
        }

        return new self($id, $event, $data);
    }

    /**
     * 响应用的回传 id：客户端没给 id 时是空字符串。
     */
    public function replyId(): string
    {
        return $this->id ?? '';
    }
}
