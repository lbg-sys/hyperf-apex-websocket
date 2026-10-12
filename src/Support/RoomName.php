<?php

declare(strict_types=1);

namespace Hyperf\Apex\WebSocket\Support;

/**
 * 房间名的合法性规则。
 *
 * 规则只有一份，是因为它的上限并不是哪个实现说了算，而是**最弱的那个后端**说了算：
 * Swoole\Table 的房间索引以房间名做键，而键上限固定 63 字节、超长即截断，两个前 63 字节
 * 相同的房间名会静默落到同一行。Redis 没有这个限制，但让同一条协议在两个驱动下
 * 表现不同更糟，所以统一按 63 收口。
 *
 * 房间名是客户端输入，直接进共享内存的键空间，因此字符集也必须收紧。
 */
final class RoomName
{
    /** 房间名字节上限（Swoole\Table 的键上限）。 */
    public const MAX_BYTES = 63;

    /** 房间名允许的字符集与长度。 */
    public const PATTERN = '/^[A-Za-z0-9_.:-]{1,' . self::MAX_BYTES . '}$/';

    /** 规则的文字描述，错误提示与对外契约文档共用，避免两处各写一份。 */
    public const RULE = '1-' . self::MAX_BYTES . ' 位的字母、数字或 _ . : - 之一';

    public static function isValid(string $room): bool
    {
        return preg_match(self::PATTERN, $room) === 1;
    }
}
