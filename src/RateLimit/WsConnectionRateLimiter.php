<?php

declare(strict_types=1);

namespace Hyperf\Apex\WebSocket\RateLimit;

use Hyperf\Contract\ConfigInterface;

/**
 * 连接级消息限流（固定窗口）。
 *
 * 为什么是进程内结构：网关强制单 worker（Sender 在协程服务器下无法跨 worker 投递，
 * 见 WsRuntimeCheckListener），计数不需要跨进程可见；放进 Swoole\Table 反而会让每一条
 * 消息都多一次共享内存读写，而限流要防的恰恰是「消息很多」这件事本身。
 *
 * 状态按连接 id 索引，入口在 onClose 里调 forget() 清掉 —— 不清会让已关闭连接的条目常驻。
 * 固定窗口的取舍：实现与内存开销最小，代价是跨窗口边界的瞬时双倍放行；
 * 这里是「防刷」而不是「精确配额」，可接受。
 */
final class WsConnectionRateLimiter
{
    /** @var array<string, array{window: int, count: int}> */
    private array $windows = [];

    private readonly int $windowSeconds;

    private readonly int $maxMessages;

    public function __construct(ConfigInterface $config)
    {
        // 配置在进程生命周期内不会变，而这条路径每条消息都要走：把两次 config->get()
        // （内部是 explode + 逐层取值）从热路径提前到构造期。
        $this->windowSeconds = (int) $config->get('websocket.rate_limit.window_seconds');
        $this->maxMessages = (int) $config->get('websocket.rate_limit.max_messages');
    }

    /**
     * 是否放行当前消息（放行时顺带计数）。
     *
     * 未配置或阈值设为 0 表示不限流。
     */
    public function allow(string $connectionId, int $now): bool
    {
        if ($this->windowSeconds <= 0 || $this->maxMessages <= 0) {
            return true;
        }

        $window = intdiv($now, $this->windowSeconds);
        $state = $this->windows[$connectionId] ?? null;
        if ($state === null || $state['window'] !== $window) {
            $this->windows[$connectionId] = ['window' => $window, 'count' => 1];

            return true;
        }

        if ($state['count'] >= $this->maxMessages) {
            return false;
        }

        ++$this->windows[$connectionId]['count'];

        return true;
    }

    public function forget(string $connectionId): void
    {
        unset($this->windows[$connectionId]);
    }

    /**
     * 超限提示文案：限额与文案一起给出，调用点不必自己拼。
     */
    public function message(): string
    {
        return sprintf('消息发送过于频繁：每 %d 秒最多 %d 条。', $this->windowSeconds, $this->maxMessages);
    }
}
