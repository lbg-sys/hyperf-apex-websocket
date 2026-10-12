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
        // JSON 数组与 JSON 对象解码后都是 PHP 数组，靠 array_is_list 区分：形状校验在这里做完，
        // 否则 `"data": [1,2]` 会一路走到 payload 水合，报出来的错与真实原因完全不相干。
        // 空数组例外：`{}` 与 `[]` 在解码后无法区分，只能都当作「没有数据」放行。
        if (! is_array($data) || ($data !== [] && array_is_list($data))) {
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
