<?php

declare(strict_types=1);

namespace Hyperf\Apex\WebSocket\Definition;

/**
 * 一个连接入口的声明结果：注解内容 + 承载它的入口类。
 */
final readonly class WsGatewayDefinition
{
    /**
     * @param class-string $class 入口类必须实现 OnOpen / OnMessage / OnClose 三个契约
     * @param list<class-string> $middlewares
     */
    public function __construct(
        public string $class,
        public string $path,
        public string $server,
        public string $endpoint,
        public string $name,
        public array $middlewares,
    ) {
    }

    /**
     * 文档与错误信息里的人类可读名称：没写 name 时退化成入口路径。
     */
    public function displayName(): string
    {
        return $this->name !== '' ? $this->name : $this->path;
    }
}
