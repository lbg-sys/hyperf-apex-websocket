<?php

declare(strict_types=1);

namespace Hyperf\Apex\WebSocket\Protocol;

use Hyperf\Apex\DTO\DTOJsonableTrait;
use Hyperf\Apex\Enum\ErrorCode;
use Hyperf\Contract\Arrayable;
use Hyperf\Contract\Jsonable;
use JsonSerializable;

/**
 * 服务端 → 客户端响应信封：{ id, event, code, message, data }。
 *
 * 不带 type 字段，缺省即响应（推送才写 type: push）。
 * code / message 复用 ErrorCode，0 表示成功。
 *
 * @implements Arrayable<string, mixed>
 */
final readonly class WsResponseDTO implements Arrayable, Jsonable, JsonSerializable
{
    use DTOJsonableTrait;

    public function __construct(
        public string $id,
        public string $event,
        public int $code,
        public string $message,
        public mixed $data = [],
    ) {
    }

    public static function success(string $id, string $event, mixed $data = []): self
    {
        return new self($id, $event, ErrorCode::SUCCESS->value, ErrorCode::SUCCESS->getMessage(), $data);
    }

    public static function error(string $id, string $event, ErrorCode|int $code, ?string $message = null, mixed $data = []): self
    {
        return new self(
            $id,
            $event,
            $code instanceof ErrorCode ? $code->value : $code,
            $message ?? ($code instanceof ErrorCode ? $code->getMessage() : ErrorCode::BUSINESS_ERROR->getMessage()),
            $data,
        );
    }
}
