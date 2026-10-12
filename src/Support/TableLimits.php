<?php

declare(strict_types=1);

namespace Hyperf\Apex\WebSocket\Support;

use RuntimeException;

/**
 * Swoole\Table 的硬限制，以及写入前的断言。
 *
 * 这些限制**不会以异常形式出现**，只会「警告 + 悄悄截断」，因此必须在写入前自己拦住：
 * - 键上限 63 字节：超长键只取前 63 字节，两条不同的键会落到同一行（房间名、用户索引键）；
 * - 字符串列上限：超长值被截断，截断后的连接 id 再也解析不出 fd，要等到推送与回收时才被发现；
 * - 单行字节上限：超出会让 set() 直接失败，连接注册被拒。
 *
 * 依据见 vendor/swoole/ide-helper 里 Swoole\Table::set() 的说明。
 */
final class TableLimits
{
    /** Swoole\Table 的键上限，不可配置。 */
    public const KEY_BYTES = 63;

    /**
     * 连接 id（`{server}:{fd}`）在索引列里的字节上限。
     *
     * 它与用户/房间索引行的列宽直接相乘，放大它会让索引表内存成倍增长，所以这里取「足够放下
     * 常见 server 名 + 10 位 fd」的 32，并由断言把超长配置挡在写入之前。
     */
    public const CONNECTION_ID_BYTES = 32;

    /** 单行字节上限按 Swoole 的 2M 取一半，留余量做配置错误拦截。 */
    public const ROW_BYTES = 1048576;

    public static function assertKey(string $key, string $what): void
    {
        if (strlen($key) > self::KEY_BYTES) {
            throw new RuntimeException(sprintf(
                '%s「%s」超过 Swoole\Table 的键上限 %d 字节：写入时会被截断，与其它行冲突。',
                $what,
                $key,
                self::KEY_BYTES,
            ));
        }
    }

    public static function assertValue(string $value, int $columnBytes, string $what): void
    {
        if (strlen($value) > $columnBytes) {
            throw new RuntimeException(sprintf(
                '%s「%s」超过索引列的 %d 字节上限：写入时会被静默截断（截断后的连接 id 解析不出 fd）。',
                $what,
                $value,
                $columnBytes,
            ));
        }
    }

    /**
     * 定长表必须按峰值预留：写满后 set() 只返回 false，溢出是静默丢数据。
     * 因此这里宁可让配置本身失败，也不接受「悄悄少记一个连接」。
     */
    public static function assertRowFits(string $path, int $capacity, int $perItemBytes): void
    {
        if ($capacity * $perItemBytes + 1 > self::ROW_BYTES) {
            throw new RuntimeException(sprintf(
                '%s=%d 会让索引列超过 Swoole\Table 的单行上限，请调小该值或用更短的连接 id。',
                $path,
                $capacity,
            ));
        }
    }
}
