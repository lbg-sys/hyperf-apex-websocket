<?php

declare(strict_types=1);

namespace Hyperf\Apex\WebSocket\Route;

use FastRoute\Dispatcher;
use Hyperf\Apex\WebSocket\Definition\WsGatewayDefinition;
use Hyperf\Apex\WebSocket\Exception\WsDeclarationException;
use Hyperf\Apex\WebSocket\Registry\WsGatewayScanner;
use Hyperf\HttpServer\Router\DispatcherFactory;
use Hyperf\HttpServer\Router\Handler;
use Hyperf\HttpServer\Router\Router;
use Psr\Container\ContainerInterface;

/**
 * 把 #[WsGateway] 声明的入口注册进按 server 隔离的路由表。
 *
 * 三条来自框架实现的硬约束：
 * 1. Router::addServer() 会把 serverName 硬编码复位成 'http'，所以每个 server 只能调一次，
 *    该 server 的所有 path 必须在同一次回调里装完；
 * 2. getDispatcher() 会缓存，所以回查断言必须放在全部注册完成之后；
 * 3. 注册必须早于任何一次 getDispatcher() —— 见 WsGatewayRegisterListener 的说明。
 */
final class WsRouteRegistrar
{
    /**
     * 本实例已经注册过的 (server, path)。
     *
     * FastRoute 对重复路由是直接抛 BadRouteException，不是覆盖；而注册器是容器单例，
     * 监听器理论上可能被触发多次。记住已注册的键，既能重复调用，又能在新增入口时只补装新增的那条。
     *
     * @var array<string, true>
     */
    private array $registered = [];

    public function __construct(
        private readonly WsGatewayScanner $gateways,
        private readonly ContainerInterface $container,
    ) {
    }

    /**
     * @return array<string, list<string>> server 名 => 该 server 下已注册的 path
     */
    public function register(): array
    {
        /** @var array<string, list<WsGatewayDefinition>> $grouped */
        $grouped = [];
        foreach ($this->gateways->all() as $definition) {
            $grouped[$definition->server][] = $definition;
        }

        $factory = $this->container->get(DispatcherFactory::class);

        foreach ($grouped as $server => $entries) {
            $pending = array_values(array_filter(
                $entries,
                fn (WsGatewayDefinition $entry): bool => ! isset($this->registered[$server . ':' . $entry->path])
            ));
            if ($pending === []) {
                continue;
            }

            Router::addServer($server, function () use ($server, $pending): void {
                foreach ($pending as $entry) {
                    // 握手是 GET 升级请求，路由必须按 GET 注册，否则 FastRoute 直接 405。
                    // 处理器的 action 固定为 onOpen：框架用 handler 的类名驱动三个生命周期回调。
                    Router::addRoute('GET', $entry->path, [$entry->class, 'onOpen'], [
                        'middleware' => $entry->middlewares,
                    ]);
                    $this->registered[$server . ':' . $entry->path] = true;
                }
            });
        }

        $this->assertRegistered($factory, $grouped);

        return array_map(
            static fn (array $entries): array => array_map(static fn (WsGatewayDefinition $entry): string => $entry->path, $entries),
            $grouped,
        );
    }

    /**
     * 注册后回查：dispatcher 缓存已经定型，此刻查得到才说明真的注册成功。
     *
     * 没有这一步，注册时机写错（例如 dispatcher 已经被更早的一次 getDispatcher() 冻结）
     * 的表现是「启动正常、握手 404」，排查成本极高。
     *
     * @param array<string, list<WsGatewayDefinition>> $grouped
     */
    private function assertRegistered(DispatcherFactory $factory, array $grouped): void
    {
        $errors = [];
        foreach ($grouped as $server => $entries) {
            $dispatcher = $factory->getDispatcher($server);
            foreach ($entries as $entry) {
                $result = $dispatcher->dispatch('GET', $entry->path);
                $handler = $result[1] ?? null;
                if (($result[0] ?? null) !== Dispatcher::FOUND
                    || ! $handler instanceof Handler
                    || ($handler->callback[0] ?? null) !== $entry->class
                ) {
                    $errors[] = sprintf('%s 未注册成功：server「%s」的路由表里查不到 GET %s 指向该入口。', $entry->class, $server, $entry->path);
                }
            }
        }

        if ($errors !== []) {
            throw new WsDeclarationException([
                ...$errors,
                '路由注册必须早于第一次 DispatcherFactory::getDispatcher()：dispatcher 一旦缓存，后续注册不会生效。',
            ]);
        }
    }
}
