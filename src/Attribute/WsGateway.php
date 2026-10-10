<?php

declare(strict_types=1);

namespace Hyperf\Apex\WebSocket\Attribute;

use Attribute;
use Hyperf\Di\Annotation\AbstractAnnotation;

/**
 * 连接入口声明，类级。
 *
 * 必须继承 AbstractAnnotation：Scanner 只对实现了 AnnotationInterface 的 Attribute
 * 调用 collectClass()，这是 WsGatewayScanner 能通过 AnnotationCollector 读到入口的
 * 唯一前提（与 #[Controller] 走的是同一套机制，无需自己写反射扫描）。
 *
 * PHP Attribute 不可重复，因此每个接入端一个入口类；多入口推荐「同一 server、多条 path」，
 * 不要用「多个 server」——不同 server 的 fd 编号空间是独立的。
 *
 * 入口类本身必须实现 OnOpenInterface + OnMessageInterface + OnCloseInterface（启动期校验）。
 */
#[Attribute(Attribute::TARGET_CLASS)]
class WsGateway extends AbstractAnnotation
{
    /**
     * @param string $path 连接路径，必须以 / 开头
     * @param string $server server.php 中声明的 server 名
     * @param list<class-string> $middlewares 握手阶段挂载的中间件，鉴权在这里做
     * @param string $endpoint 接入端标识，推送寻址依赖它，非空且全局唯一
     * @param string $name 文档中展示的入口名称
     */
    public function __construct(
        public string $path,
        public string $server = 'ws',
        public array $middlewares = [],
        public string $endpoint = '',
        public string $name = '',
    ) {
    }
}
