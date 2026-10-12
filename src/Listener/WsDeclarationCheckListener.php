<?php

declare(strict_types=1);

namespace Hyperf\Apex\WebSocket\Listener;

use Hyperf\Apex\WebSocket\Exception\WsDeclarationException;
use Hyperf\Apex\WebSocket\Registry\WsGatewayScanner;
use Hyperf\Apex\WebSocket\Registry\WsHandlerRegistry;
use Hyperf\Contract\ConfigInterface;
use Hyperf\Event\Contract\ListenerInterface;
use Hyperf\Framework\Event\BootApplication;
use RuntimeException;

/**
 * 启动期校验 WebSocket 声明完整性。
 *
 * 校验项全部落在 WsGatewayScanner / WsHandlerRegistry 里（它们才是读注解的人），
 * 本监听器只负责把两边的错误一次性汇总抛出：任何一项不满足直接让应用启动失败。
 *
 * 与 PermissionDeclarationCheckListener 同样的时机与风格：契约错误在启动期暴露，
 * 而不是留到客户端握手或第一条消息。
 *
 * 必须排在 WsGatewayRegisterListener 之前：注册器自己也会扫描入口，一旦扫描失败会直接抛错，
 * 排在后面的话「两边一次性汇总」就永远执行不到，声明错误只能一轮一轮地改。
 * 同理，`websocket.enabled = false` 时必须整体跳过 —— 开关关闭代表这套网关不参与本次启动，
 * 此时「没有任何入口」是正常状态，不该让应用起不来。
 */
final class WsDeclarationCheckListener implements ListenerInterface
{
    public function __construct(
        private readonly ConfigInterface $config,
        private readonly WsGatewayScanner $gateways,
        private readonly WsHandlerRegistry $handlers,
    ) {
    }

    public function listen(): array
    {
        return [BootApplication::class];
    }

    public function process(object $event): void
    {
        if (! (bool) $this->config->get('websocket.enabled')) {
            return;
        }

        $errors = [];
        foreach ([$this->gateways, $this->handlers] as $scanner) {
            try {
                $scanner->all();
            } catch (WsDeclarationException $exception) {
                $errors = [...$errors, ...$exception->errors()];
            }
        }

        $errors = [...$errors, ...$this->checkGatewayPresence()];

        if ($errors !== []) {
            throw new RuntimeException("WebSocket 声明检查失败：\n - " . implode("\n - ", $errors));
        }
    }

    /**
     * 「配了 WebSocket 服务却一个入口都没有」必须在启动期炸：客户端握手只会拿到 404，
     * 而从「启动日志一切正常」到「握手 404」之间没有任何线索。
     *
     * 反过来，「没有入口」本身不是错误：monorepo 的元包会一并装上本包，一个只用 HTTP 的
     * 应用（server.php 里没有 WebSocket 服务）不该因为装了这个包就起不来。
     *
     * @return list<string>
     */
    private function checkGatewayPresence(): array
    {
        try {
            if ($this->gateways->all() !== []) {
                return [];
            }
        } catch (WsDeclarationException) {
            // 声明本身就有错，错误已经在上面收集过了，这里不重复报一遍。
            return [];
        }

        $servers = $this->gateways->webSocketServers();
        if ($servers === []) {
            return [];
        }

        return [sprintf(
            'config/autoload/server.php 声明了 WebSocket 服务（%s），但没有任何 #[WsGateway] 入口：'
            . '客户端握手只会拿到 404。请补一个入口，或把 websocket.enabled 设为 false。',
            implode(', ', $servers),
        )];
    }
}
