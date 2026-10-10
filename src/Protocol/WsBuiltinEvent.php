<?php

declare(strict_types=1);

namespace Hyperf\Apex\WebSocket\Protocol;

use Hyperf\Apex\Enum\Interface\EnumInterface;
use Hyperf\Apex\Enum\Traits\EnumTrait;
use Hyperf\Constants\Annotation\Constants;
use Hyperf\Constants\Annotation\Message;

/**
 * 内置事件。
 *
 * 内置事件由 WsDispatcher 直接处理，不进入业务注册表，也不允许业务侧声明同名事件
 * （#[WsRoute] 的启动期校验会拒绝 sys. 前缀）。
 */
#[Constants]
enum WsBuiltinEvent: string implements EnumInterface
{
    use EnumTrait;

    #[Message('心跳请求')]
    case PING = 'sys.ping';

    #[Message('心跳应答')]
    case PONG = 'sys.pong';

    #[Message('订阅房间')]
    case SUBSCRIBE = 'sys.subscribe';

    #[Message('退订房间')]
    case UNSUBSCRIBE = 'sys.unsubscribe';

    #[Message('协议错误')]
    case ERROR = 'sys.error';

    /**
     * 内置事件保留前缀，业务事件不得使用。
     */
    public const PREFIX = 'sys.';

    /**
     * 只有客户端可以主动发起的内置事件。
     */
    public function isClientInitiated(): bool
    {
        return match ($this) {
            self::PING, self::SUBSCRIBE, self::UNSUBSCRIBE => true,
            self::PONG, self::ERROR => false,
        };
    }
}
