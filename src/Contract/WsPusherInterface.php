<?php

declare(strict_types=1);

namespace Hyperf\Apex\WebSocket\Contract;

use Hyperf\Apex\DTO\AbstractDTO;

/**
 * 业务模块使用的推送门面。
 *
 * 这是 app/Shared/WebSocket/ 唯一对外暴露的写入入口，业务模块不需要认识 Sender、
 * Swoole\Table 或连接 id。事件的业务语义不由这里定义。
 *
 * 本设计不做可靠投递：连接断开期间的推送即丢失。需要可靠性的业务走 HTTP 拉取。
 */
interface WsPusherInterface
{
    public function pushToUser(string $userType, int $userId, string $event, AbstractDTO $payload): void;

    public function pushToRoom(string $room, string $event, AbstractDTO $payload): void;

    public function pushToEndpoint(string $endpoint, string $event, AbstractDTO $payload): void;

    public function broadcast(string $event, AbstractDTO $payload): void;
}
