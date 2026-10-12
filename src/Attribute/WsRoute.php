<?php

declare(strict_types=1);

namespace Hyperf\Apex\WebSocket\Attribute;

use Attribute;
use Hyperf\Di\Annotation\AbstractAnnotation;

/**
 * 事件声明，方法级。
 *
 * payload 与响应类型由方法签名推断，不在注解里重复写类名，避免两处漂移：
 * 第一个 AbstractDTO 子类参数是入站 payload，返回类型是出站 data
 * （void / null 表示无响应体），另有可选的 WsRequestContext 参数承载连接身份。
 *
 * 处理器的实例由容器解析，而 Hyperf\Di\Container::get() 会把解析结果缓存下来 —— 处理器因此是
 * **进程级单例，跨连接、跨消息复用**。所以处理器不得把连接级状态放进属性（连接身份走
 * WsRequestContext 参数拿），有状态的业务对象放进 Service，按连接隔离的数据放进连接索引。
 *
 * 不使用「接口 + 接口方法」约束签名：PHP 参数类型不支持逆变收窄，接口声明
 * AbstractDTO $payload 后实现类无法收窄成具体 DTO。因此改为启动期反射校验签名。
 */
#[Attribute(Attribute::TARGET_METHOD)]
class WsRoute extends AbstractAnnotation
{
    public function __construct(public string $event)
    {
    }
}
