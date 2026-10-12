<?php

declare(strict_types=1);

namespace Hyperf\Apex\WebSocket\Registry;

use Hyperf\Apex\WebSocket\Contract\WsConnectionStoreInterface;
use Hyperf\Apex\WebSocket\Session\WsSession;
use Hyperf\Apex\WebSocket\Support\TableLimits;
use Hyperf\Contract\ConfigInterface;
use RuntimeException;
use Swoole\Table;
use Throwable;

/**
 * 连接索引与用户索引的 Swoole\Table 实现。
 *
 * 为什么共享内存而不是 Redis：单点部署下跨节点问题不存在，引入 Redis 会让每条连接的
 * 建立与断开都多打一次 Redis，收益为零。限流场景否决 Swoole\Table 的理由（无 TTL、无 CAS、
 * key 空间无界）在这里都不成立：连接生命周期由 onOpen / onClose 显式管理，不需要 TTL，
 * key 空间受峰值连接数约束。
 *
 * 房间索引不在这里 —— 房间成员是可能跨节点的一组 id，单独由 WsRoomStoreInterface 承载，
 * 可选择 Swoole\Table 或 Redis 实现。
 *
 * 表必须在 BeforeMainServerStart（master 进程、fork 之前）创建，否则每个 worker 各建各的，
 * 表之间不共享。
 *
 * 并发口径：用户索引是「一行放一串连接 id」，读改写不是原子的。原因不是「Swoole\Table 没有锁」
 * （单次 get/set 各自都带行级自旋锁），而是 get 与 set 之间锁已经释放，两个协程交错就会丢 id。
 * 单 worker 下协程不会在纯内存操作之间切换，所以这里安全；跨 worker 并发注册同一用户时才存在
 * 丢 id 的窗口 —— 网关强制单 worker（Sender 在协程服务器下也无法跨 worker 投递），
 * 该前提由 WsRuntimeCheckListener 在启动期守住。
 */
final class WsConnectionStore implements WsConnectionStoreInterface
{
    private const SEPARATOR = ',';

    /** 接入端标识的列宽。超长会截断，而 connectionsByEndpoint() 拿的是未截断的值，等于永远匹配不上。 */
    private const ENDPOINT_BYTES = 32;

    /**
     * 用户类型的列宽。超长会截断，而 unregister() 是用行里的 user_type 重建用户索引键的 ——
     * 截断后的键与原键不同，用户索引里那条记录就永远摘不掉。
     */
    private const USER_TYPE_BYTES = 32;

    private const SESSION_ID_BYTES = 64;

    private static ?Table $connections = null;

    private static ?Table $users = null;

    private static int $userCapacity = 0;

    /**
     * 创建共享内存表。重复调用是幂等的：BeforeMainServerStart 只会触发一次，
     * 测试可以按用例重复调用。
     */
    public static function init(ConfigInterface $config): void
    {
        if (self::$connections instanceof Table) {
            return;
        }

        $size = max(1, (int) $config->get('websocket.table_size'));
        self::$userCapacity = max(1, (int) $config->get('websocket.max_connections_per_user'));

        TableLimits::assertRowFits('websocket.max_connections_per_user', self::$userCapacity, self::itemBytes());

        self::$connections = self::createConnections($size);
        self::$users = self::createIndex($size, self::rowBytes(self::$userCapacity));
    }

    public function register(WsSession $session): void
    {
        $id = $session->id();
        $principal = $session->principal;

        // 连接 id 既是连接表的键（键上限 63 字节），又是用户索引行里的值（列宽更小）。
        // 这些字段超长都只会「警告 + 截断」，而不是报错，所以必须在写入前自己拦住：
        // 截断后的连接 id 解析不出 fd，截断后的接入端标识再也匹配不上推送寻址。
        // 可空身份：`??` 的左侧是 isset 语义，null 上的属性访问不会报警告，只是取到空值。
        $userType = $principal->userType ?? '';
        $sessionId = $principal->sessionId ?? '';

        TableLimits::assertValue($id, TableLimits::CONNECTION_ID_BYTES, '连接 id');
        TableLimits::assertValue($session->endpoint, self::ENDPOINT_BYTES, '接入端标识');
        TableLimits::assertValue($userType, self::USER_TYPE_BYTES, '用户类型');
        TableLimits::assertValue($sessionId, self::SESSION_ID_BYTES, '会话标识');

        self::write(self::connections(), $id, [
            'endpoint' => $session->endpoint,
            'user_type' => $userType,
            'user_id' => $principal->userId ?? 0,
            'session_id' => $sessionId,
            'connected_at' => $session->connectedAt,
            'last_active_at' => $session->connectedAt,
        ]);

        if ($principal === null) {
            return;
        }

        $userKey = self::userKey($userType, $principal->userId);
        TableLimits::assertKey($userKey, '用户索引键');

        try {
            if ($this->append(self::users(), $userKey, $id, self::$userCapacity)) {
                return;
            }

            throw new RuntimeException(sprintf(
                '用户 %s 的连接数超过上限 %d，拒绝写入连接索引。',
                $userKey,
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

    public function hasConnection(string $connectionId): bool
    {
        // exist() 只查哈希槽；get() 要把整行（含两个字符串列）拷出来，而这是每条推送路径上的判活。
        return self::connections()->exist($connectionId);
    }

    public function touch(string $connectionId, int $activeAt): void
    {
        // 只写一列：Swoole\Table::set 对已存在的行是部分更新，不会覆盖其余列。
        // 行已经不存在时 set 会新建一行残行，所以先确认连接还在。
        if (! self::connections()->exist($connectionId)) {
            return;
        }

        self::connections()->set($connectionId, ['last_active_at' => $activeAt]);
    }

    /**
     * @return list<string>
     */
    public function idleConnections(int $idleBefore): array
    {
        $ids = [];
        foreach (self::connections() as $key => $row) {
            if (! is_array($row)) {
                continue;
            }

            // 缺列（老表结构残留）按 0 处理：宁可多回收一次，也不要留下永远不回收的连接。
            if ((int) ($row['last_active_at'] ?? 0) < $idleBefore) {
                $ids[] = (string) $key;
            }
        }

        return $ids;
    }

    public function connectionsByUser(string $userType, int $userId): array
    {
        return $this->ids(self::users(), self::userKey($userType, $userId));
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
            if (! is_array($row)) {
                continue;
            }

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

    /**
     * 行内每个连接 id 占用的字节数：id 本身加一个分隔符。
     */
    private static function itemBytes(): int
    {
        return TableLimits::CONNECTION_ID_BYTES + 1;
    }

    private static function rowBytes(int $capacity): int
    {
        return $capacity * self::itemBytes() + 1;
    }

    private static function createConnections(int $size): Table
    {
        $table = new Table($size);
        $table->column('endpoint', Table::TYPE_STRING, self::ENDPOINT_BYTES);
        $table->column('user_type', Table::TYPE_STRING, self::USER_TYPE_BYTES);
        $table->column('user_id', Table::TYPE_INT);
        $table->column('session_id', Table::TYPE_STRING, self::SESSION_ID_BYTES);
        $table->column('connected_at', Table::TYPE_INT);
        $table->column('last_active_at', Table::TYPE_INT);
        if (! $table->create()) {
            throw new RuntimeException('无法创建 WebSocket 连接索引表 ws_conn。');
        }

        return $table;
    }

    private static function createIndex(int $size, int $bytes): Table
    {
        $table = new Table($size);
        $table->column('connections', Table::TYPE_STRING, $bytes);
        if (! $table->create()) {
            throw new RuntimeException('无法创建 WebSocket 索引表。');
        }

        return $table;
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

    /**
     * @param array<string, int|string> $row
     */
    private static function write(Table $table, string $key, array $row): void
    {
        if ($table->set($key, $row) === false) {
            throw new RuntimeException(sprintf(
                'WebSocket 索引写入失败（键 %s）：表容量已满（Swoole\Table 的冲突槽会先于主槽耗尽）或键／值超出列长度。',
                $key,
            ));
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
