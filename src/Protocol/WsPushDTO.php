<?php

declare(strict_types=1);

namespace Hyperf\Apex\WebSocket\Protocol;

use Hyperf\Apex\DTO\DTOJsonableTrait;
use Hyperf\Contract\Arrayable;
use Hyperf\Contract\Jsonable;
use JsonSerializable;

/**
 * 服务端 → 客户端推送信封：{ id, event, type, data }。
 *
 * 缺少 code / message 是刻意的：推送不是任何请求的应答，没有「成功或失败」可言。
 * id 由服务端生成，供客户端做去重与日志关联。
 *
 * @implements Arrayable<string, mixed>
 */
final readonly class WsPushDTO implements Arrayable, Jsonable, JsonSerializable
{
    use DTOJsonableTrait;

    public const TYPE_PUSH = 'push';

    public function __construct(
        public string $id,
        public string $event,
        public string $type = self::TYPE_PUSH,
        public mixed $data = [],
    ) {
    }

    public static function make(string $event, mixed $data = []): self
    {
        return new self(bin2hex(random_bytes(8)), $event, self::TYPE_PUSH, $data);
    }
}
