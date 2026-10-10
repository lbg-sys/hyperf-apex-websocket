<?php

declare(strict_types=1);

namespace Hyperf\Apex\WebSocket\DTO;

use Hyperf\Apex\DTO\AbstractDTO;
use Hyperf\Apex\DTO\Attribute\ArrayOf;
use Hyperf\Apex\DTO\Attribute\FieldDescription;

/**
 * 房间订阅 / 退订的响应数据。
 *
 * 同时回传本次操作的房间与操作完成后的房间全集，客户端不必自己维护本地镜像。
 */
final readonly class WsSubscriptionResultDTO extends AbstractDTO
{
    /**
     * @param list<string> $rooms
     */
    public function __construct(
        #[FieldDescription('本次操作的房间名')]
        public string $room,
        #[FieldDescription('操作完成后的房间全集')]
        #[ArrayOf('string')]
        public array $rooms,
    ) {
    }
}
