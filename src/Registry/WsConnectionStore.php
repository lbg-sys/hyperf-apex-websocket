<?php

declare(strict_types=1);

namespace Hyperf\Apex\WebSocket\Registry;

use Hyperf\Apex\WebSocket\Contract\WsConnectionStoreInterface;
use Hyperf\Apex\WebSocket\Session\WsSession;
use Hyperf\Contract\ConfigInterface;
use RuntimeException;
use Swoole\Table;
use Throwable;

/**
 * Swoole\Table 连接索引。
 *
 * 为什么共享内存而不是 Redis：单点部署下跨节点问题不存在，引入 Redis 会让每条连接的
 * 建立与断开都多打一次 Redis，收益为零。限流场景否决 Swoole\Table 的理由（无 TTL、无 CAS、
 * key 空间无界）在这里都不成立：连接生命周期由 onOpen / onClose 显式管理，不需要 TTL，
 * key 空间受峰值连接数约束。
 *
 * 表必须在 BeforeMainServerStart（master 进程、fork 之前）创建，否则每个 worker 各建各的，
 * 表之间不共享。
 */
final class WsConnectionStore implements WsConnectionStoreInterface
{
    /** `{server}:{fd}` 的字节上限，留足 32 字节。 */
    private const CONNECTION_ID_BYTES = 32;

    private const ENDPOINT_BYTES = 32;

    private const USER_TYPE_BYTES = 32;

    private const SESSION_ID_BYTES = 64;

    private const SEPARATOR = ',';

    /** Swoole\Table 单行上限 2M，留一半余量做配置错误拦截。 */
    private const MAX_ROW_BYTES = 1048576;

    private static ?Table $connections = null;

    private static ?Table $users = null;

    private static ?Table $rooms = null;

    private static int $userCapacity = 0;

    private static int $roomCapacity = 0;

    /**
     * 创建共享内存表。重复调用是幂等的：BeforeMainServerStart 只会触发一次，
     * 测试可以按用例重复调用。
     */
    public static function init(ConfigInterface $config): void
    {
        if (self::$connections instanceof Table) {
            return;
        }

        $size = max(1, (int) $config->get('websocket.table_size', 65536));
        self::$userCapacity = max(1, (int) $config->get('websocket.max_connections_per_user', 10));
        self::$roomCapacity = max(1, (int) $config->get('websocket.room_member_capacity', 128));
        $roomSize = max(1, (int) $config->get('websocket.room_table_size', 2048));

        self::assertRowFits('websocket.max_connections_per_user', self::$userCapacity);
        self::assertRowFits('websocket.room_member_capacity', self::$roomCapacity);

        self::$connections = self::createConnections($size);
        self::$users = self::createIndex($size, self::$userCapacity);
        self::$rooms = self::createIndex($roomSize, self::$roomCapacity);
    }

    public function register(WsSession $session): void
    {
        $id = $session->id();
        $principal = $session->principal;

        self::write(self::connections(), $id, [
            'endpoint' => $session->endpoint,
            'user_type' => $principal->userType ?? '',
            'user_id' => $principal->userId ?? 0,
            'tenant_id' => $principal->tenantId ?? 0,
            'session_id' => $principal->sessionId ?? '',
            'connected_at' => $session->connectedAt,
        ]);

        if ($principal === null) {
            return;
        }

        try {
            if ($this->append(self::users(), self::userKey($principal->userType, $principal->userId), $id, self::$userCapacity)) {
                return;
            }

            throw new RuntimeException(sprintf(
                '用户 %s:%d 的连接数超过上限 %d，拒绝写入连接索引。',
                $principal->userType,
                $principal->userId,
                self::$userCapacity,
            ));
        } catch (Throwable $throwable) {
            // 连接行与用户索引两步都成立才算注册成功，任一步失败都要撤回已写入的连接行。
            // 调用方在注册失败时会先清会话再断开，随后的 onClose 找不到会话就跳过 unregister()，
            // 留下的连接行再没人清：广播与接入端成员列表会一直向已经关闭的 fd 投递。
            self::connections()->del($id);

            throw $throwable;
        }
    }

    public function unregister(string $connectionId): void
    {
        $row = self::connections()->get($connectionId);
        if (! is_array($row)) {
            return;
        }

        $userType = (string) ($row['user_type'] ?? '');
        if ($userType !== '') {
            $this->remove(self::users(), self::userKey($userType, (int) ($row['user_id'] ?? 0)), $connectionId);
        }

        self::connections()->del($connectionId);
    }

    public function connectionsByUser(string $userType, int $userId): array
    {
        return $this->ids(self::users(), self::userKey($userType, $userId));
    }

    public function connectionsByRoom(string $room): array
    {
        return $this->ids(self::rooms(), $room);
    }

    /**
     * 接入端连接列表由连接行过滤得出，不单独维护反向索引。
     *
     * 推送本身就是 O(目标连接数) 的工作量，多一次 O(表行数) 的共享内存扫描不改变量级；
     * 换来的是「接入端成员列表」这个无界集合不再需要一个定长列去承载（Swoole\Table 装不下）。
     */
    public function connectionsByEndpoint(string $endpoint): array
    {
        $ids = [];
        foreach (self::connections() as $key => $row) {
            if (is_array($row) && (string) ($row['endpoint'] ?? '') === $endpoint) {
                $ids[] = (string) $key;
            }
        }

        return $ids;
    }

    public function allConnections(): array
    {
        $ids = [];
        foreach (self::connections() as $key => $row) {
            $ids[] = (string) $key;
        }

        return $ids;
    }

    public function countConnections(): int
    {
        return self::connections()->count();
    }

    public function countUsers(): int
    {
        return self::users()->count();
    }

    public function countRooms(): int
    {
        return self::rooms()->count();
    }

    public function joinRoom(string $connectionId, string $room): bool
    {
        if (! is_array(self::connections()->get($connectionId))) {
            throw new RuntimeException(sprintf('连接 %s 未注册，无法加入房间 %s。', $connectionId, $room));
        }

        return $this->append(self::rooms(), $room, $connectionId, self::$roomCapacity);
    }

    public function leaveRoom(string $connectionId, string $room): void
    {
        $this->remove(self::rooms(), $room, $connectionId);
    }

    private static function createConnections(int $size): Table
    {
        $table = new Table($size);
        $table->column('endpoint', Table::TYPE_STRING, self::ENDPOINT_BYTES);
        $table->column('user_type', Table::TYPE_STRING, self::USER_TYPE_BYTES);
        $table->column('user_id', Table::TYPE_INT);
        $table->column('tenant_id', Table::TYPE_INT);
        $table->column('session_id', Table::TYPE_STRING, self::SESSION_ID_BYTES);
        $table->column('connected_at', Table::TYPE_INT);
        if (! $table->create()) {
            throw new RuntimeException('无法创建 WebSocket 连接索引表 ws_conn。');
        }

        return $table;
    }

    private static function createIndex(int $size, int $capacity): Table
    {
        $table = new Table($size);
        $table->column('connections', Table::TYPE_STRING, self::rowBytes($capacity));
        if (! $table->create()) {
            throw new RuntimeException('无法创建 WebSocket 索引表。');
        }

        return $table;
    }

    /**
     * 定长表必须按峰值预留：写满后 set() 只返回 false，溢出是静默丢数据。
     * 因此这里宁可让配置本身失败，也不接受「悄悄少记一个连接」。
     */
    private static function assertRowFits(string $path, int $capacity): void
    {
        if (self::rowBytes($capacity) > self::MAX_ROW_BYTES) {
            throw new RuntimeException(sprintf(
                '%s=%d 会让索引列超过 Swoole\Table 的单行上限，请调小该值或用更小的连接 id。',
                $path,
                $capacity,
            ));
        }
    }

    private static function rowBytes(int $capacity): int
    {
        return $capacity * (self::CONNECTION_ID_BYTES + 1) + 1;
    }

    private static function userKey(string $userType, int $userId): string
    {
        return $userType . ':' . $userId;
    }

    private static function connections(): Table
    {
        return self::$connections ?? throw new RuntimeException('WebSocket 连接索引尚未初始化，请确认已注册 WsTableInitListener。');
    }

    private static function users(): Table
    {
        return self::$users ?? throw new RuntimeException('WebSocket 用户索引尚未初始化，请确认已注册 WsTableInitListener。');
    }

    private static function rooms(): Table
    {
        return self::$rooms ?? throw new RuntimeException('WebSocket 房间索引尚未初始化，请确认已注册 WsTableInitListener。');
    }

    /**
     * @param array<string, int|string> $row
     */
    private static function write(Table $table, string $key, array $row): void
    {
        if ($table->set($key, $row) === false) {
            throw new RuntimeException(sprintf('WebSocket 索引写入失败（键 %s）：表容量已满或键／值超出列长度。', $key));
        }
    }

    /**
     * @return list<string>
     */
    private function ids(Table $table, string $key): array
    {
        $row = $table->get($key);
        if (! is_array($row)) {
            return [];
        }

        return self::split((string) ($row['connections'] ?? ''));
    }

    /**
     * 追加一个连接 id。返回 false 表示已达上限，由调用方决定是报错还是转成业务错误。
     */
    private function append(Table $table, string $key, string $connectionId, int $capacity): bool
    {
        $ids = $this->ids($table, $key);
        if (in_array($connectionId, $ids, true)) {
            return true;
        }

        if (count($ids) >= $capacity) {
            return false;
        }

        $ids[] = $connectionId;
        self::write($table, $key, ['connections' => implode(self::SEPARATOR, $ids)]);

        return true;
    }

    private function remove(Table $table, string $key, string $connectionId): void
    {
        $ids = $this->ids($table, $key);
        $remaining = array_values(array_filter($ids, static fn (string $item): bool => $item !== $connectionId));
        if ($remaining === $ids) {
            return;
        }

        if ($remaining === []) {
            $table->del($key);
            return;
        }

        self::write($table, $key, ['connections' => implode(self::SEPARATOR, $remaining)]);
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
