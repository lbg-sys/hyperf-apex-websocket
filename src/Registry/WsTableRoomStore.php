<?php

declare(strict_types=1);

namespace Hyperf\Apex\WebSocket\Registry;

use Hyperf\Apex\WebSocket\Contract\WsRoomStoreInterface;
use Hyperf\Apex\WebSocket\Support\TableLimits;
use Hyperf\Contract\ConfigInterface;
use RuntimeException;
use Swoole\Table;

/**
 * 房间成员索引的 Swoole\Table 实现（默认驱动，单机共享内存）。
 *
 * 结构与连接索引一致：一个房间一行，行内放一串连接 id。行是定长的，所以
 * `websocket.room_member_capacity` 决定列宽、`websocket.room_table_size` 决定行数，
 * 两者都必须按峰值预留 —— 写满之后 set() 只返回 false，溢出是静默丢成员。
 *
 * 表必须在 BeforeMainServerStart（master 进程、fork 之前）创建，否则每个 worker 各建各的。
 * 驱动切到 redis 时本表根本不会被创建（见 WsTableInitListener），因此连接索引与房间索引
 * 的内存可以按需取舍。
 */
final class WsTableRoomStore implements WsRoomStoreInterface
{
    private const SEPARATOR = ',';

    private static ?Table $rooms = null;

    private static int $capacity = 0;

    /**
     * 创建共享内存表。重复调用是幂等的：BeforeMainServerStart 只会触发一次，
     * 测试可以按用例重复调用。
     */
    public static function init(ConfigInterface $config): void
    {
        if (self::$rooms instanceof Table) {
            return;
        }

        self::$capacity = max(1, (int) $config->get('websocket.room_member_capacity'));
        $size = max(1, (int) $config->get('websocket.room_table_size'));

        TableLimits::assertRowFits('websocket.room_member_capacity', self::$capacity, self::itemBytes());

        self::$rooms = self::create($size, self::$capacity);
    }

    /**
     * 丢弃全部房间行。
     *
     * 生产路径不需要它：最后一个成员离开时房间行会被删掉，连接断开时入口也会按会话记录的
     * 房间精确退订。存在的意义与 init() 的幂等一致 —— 让用例之间互不影响。
     */
    public static function reset(): void
    {
        // 不能在遍历中删行：删除冲突链表首行会让后续遍历跳行或读到过期数据（Swoole\Table::del 的说明）。
        $keys = [];
        foreach (self::rooms() as $key => $row) {
            $keys[] = (string) $key;
        }

        foreach ($keys as $key) {
            self::rooms()->del($key);
        }
    }

    public function joinRoom(string $connectionId, string $room): bool
    {
        // 房间名是房间表的键（截断会让两个房间落到同一行），连接 id 是行内的值
        // （截断后解析不出 fd，推送与回收都找不到这条连接）：两者都必须在写入前拦住。
        TableLimits::assertKey($room, '房间名');
        TableLimits::assertValue($connectionId, TableLimits::CONNECTION_ID_BYTES, '连接 id');

        return $this->append($room, $connectionId);
    }

    public function leaveRoom(string $connectionId, string $room): void
    {
        TableLimits::assertKey($room, '房间名');

        $ids = $this->ids($room);
        $remaining = array_values(array_filter($ids, static fn (string $item): bool => $item !== $connectionId));
        if ($remaining === $ids) {
            return;
        }

        if ($remaining === []) {
            // 最后一个成员离开即房间不存在：留着空行会让「房间数」这个指标只增不减。
            self::rooms()->del($room);

            return;
        }

        self::write($room, ['connections' => implode(self::SEPARATOR, $remaining)]);
    }

    public function connectionsByRoom(string $room): array
    {
        return $this->ids($room);
    }

    public function countRooms(): int
    {
        return self::rooms()->count();
    }

    /**
     * 行内每个连接 id 占用的字节数：id 本身加一个分隔符。
     */
    private static function itemBytes(): int
    {
        return TableLimits::CONNECTION_ID_BYTES + 1;
    }

    /**
     * 承载 $capacity 个连接 id 所需的列宽。
     */
    private static function rowBytes(int $capacity): int
    {
        return $capacity * self::itemBytes() + 1;
    }

    private static function create(int $size, int $capacity): Table
    {
        $table = new Table($size);
        $table->column('connections', Table::TYPE_STRING, self::rowBytes($capacity));
        if (! $table->create()) {
            throw new RuntimeException('无法创建 WebSocket 房间索引表 ws_room。');
        }

        return $table;
    }

    private static function rooms(): Table
    {
        return self::$rooms ?? throw new RuntimeException('WebSocket 房间索引尚未初始化：请确认已注册 WsTableInitListener，且 websocket.room_store_driver=table。');
    }

    /**
     * @param array<string, int|string> $row
     */
    private static function write(string $key, array $row): void
    {
        if (self::rooms()->set($key, $row) === false) {
            throw new RuntimeException(sprintf('WebSocket 房间索引写入失败（房间 %s）：表容量已满或键／值超出列长度。', $key));
        }
    }

    /**
     * @return list<string>
     */
    private function ids(string $room): array
    {
        $row = self::rooms()->get($room);
        if (! is_array($row)) {
            return [];
        }

        return self::split((string) ($row['connections'] ?? ''));
    }

    /**
     * 追加一个连接 id。返回 false 表示成员已达上限。
     */
    private function append(string $room, string $connectionId): bool
    {
        $ids = $this->ids($room);
        if (in_array($connectionId, $ids, true)) {
            return true;
        }

        if (count($ids) >= self::$capacity) {
            return false;
        }

        $ids[] = $connectionId;
        self::write($room, ['connections' => implode(self::SEPARATOR, $ids)]);

        return true;
    }

    /**
     * @return list<string>
     */
    private static function split(string $value): array
    {
        if ($value === '') {
            return [];
        }

        return array_values(array_filter(explode(self::SEPARATOR, $value), static fn (string $item): bool => $item !== ''));
    }
}
