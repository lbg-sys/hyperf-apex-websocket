<?php

declare(strict_types=1);

namespace Hyperf\Apex\WebSocket\Registry;

use Hyperf\Apex\WebSocket\Attribute\WsGateway;
use Hyperf\Apex\WebSocket\Definition\WsGatewayDefinition;
use Hyperf\Apex\WebSocket\Exception\WsDeclarationException;
use Hyperf\Contract\ConfigInterface;
use Hyperf\Contract\OnCloseInterface;
use Hyperf\Contract\OnMessageInterface;
use Hyperf\Contract\OnOpenInterface;
use Hyperf\Di\Annotation\AnnotationCollector;
use Hyperf\Server\ServerInterface;
use Psr\Http\Server\MiddlewareInterface;
use ReflectionClass;

/**
 * 扫描 #[WsGateway]，产出入口定义并做启动期校验。
 *
 * 读 AnnotationCollector（与 #[Controller] 同一套机制），因此入口类所在文件必须
 * 落在 annotations.php 的 scan.paths 内。
 */
final class WsGatewayScanner
{
    /** @var null|array<class-string, WsGatewayDefinition> */
    private ?array $cache = null;

    public function __construct(private readonly ConfigInterface $config)
    {
    }

    /**
     * 全部入口定义，键为入口类名。
     *
     * 「一个入口都没有」不是错误：通过元包安装、但根本不用长连接的应用（server.php 里没有
     * WebSocket 服务）不该因此起不来。真正该拦的是「配了 WebSocket 服务却没有入口」——
     * 那种情况客户端握手只会拿到 404，由 WsDeclarationCheckListener 在能读到 server 配置的
     * 地方判定（它才需要回答「这个应用到底用不用长连接」）。
     *
     * @return array<class-string, WsGatewayDefinition>
     *
     * @throws WsDeclarationException 任意一项声明校验不通过
     */
    public function all(): array
    {
        if ($this->cache !== null) {
            return $this->cache;
        }

        $collected = AnnotationCollector::getClassesByAnnotation(WsGateway::class);

        $servers = $this->webSocketServers();
        $prefix = trim((string) $this->config->get('websocket.path'), '/');
        $errors = [];
        $paths = [];
        $endpoints = [];
        $definitions = [];

        foreach ($collected as $class => $annotation) {
            $class = (string) $class;
            if (! $annotation instanceof WsGateway) {
                continue;
            }

            $definition = self::definition($class, $annotation);
            $errors = [...$errors, ...$this->validate($definition, $prefix, $servers, $paths, $endpoints)];
            $definitions[$class] = $definition;
        }

        if ($errors !== []) {
            throw new WsDeclarationException($errors);
        }

        return $this->cache = $definitions;
    }

    /**
     * 按类名取定义，供入口自身在运行时使用。
     *
     * 只做「注解是否存在」的判断，不做 @see all() 那套全局校验：那个校验已经由
     * WsDeclarationCheckListener 在启动期做过，运行期再做一遍只会让每个连接多付一次扫描成本。
     */
    public function forClass(string $class): ?WsGatewayDefinition
    {
        if ($this->cache !== null) {
            return $this->cache[$class] ?? null;
        }

        $attribute = (new ReflectionClass($class))->getAttributes(WsGateway::class)[0] ?? null;
        if ($attribute === null) {
            return null;
        }

        /** @var WsGateway $annotation */
        $annotation = $attribute->newInstance();

        return self::definition($class, $annotation);
    }

    /**
     * config/autoload/server.php 里声明的 WebSocket 服务名。
     *
     * 只认 type === SERVER_WEBSOCKET 的端口：握手回调（onHandShake）只挂在 WebSocket 服务上，
     * 而「名字存在」是很弱的校验 —— 注解里写成 http 端口一样能通过，结果客户端握手 404。
     *
     * 这一项最有价值：把「注解写了 ws 但 server.php 没配这个服务」挡在启动期。
     *
     * @return list<string>
     */
    public function webSocketServers(): array
    {
        $servers = $this->config->get('server.servers', []);
        if (! is_array($servers)) {
            return [];
        }

        $names = [];
        foreach ($servers as $server) {
            if (! is_array($server)) {
                continue;
            }

            $name = $server['name'] ?? null;
            if (is_string($name) && $name !== '' && ($server['type'] ?? null) === ServerInterface::SERVER_WEBSOCKET) {
                $names[] = $name;
            }
        }

        return $names;
    }

    private static function definition(string $class, WsGateway $annotation): WsGatewayDefinition
    {
        return new WsGatewayDefinition(
            $class,
            $annotation->path,
            $annotation->server,
            $annotation->endpoint,
            $annotation->name,
            array_map(static fn (mixed $middleware): string => (string) $middleware, $annotation->middlewares),
        );
    }

    /**
     * @param string $prefix websocket.path，去掉首尾斜杠后的路径前缀
     * @param list<string> $servers
     * @param array<string, string> $paths
     * @param array<string, string> $endpoints
     * @return list<string>
     */
    private function validate(WsGatewayDefinition $definition, string $prefix, array $servers, array &$paths, array &$endpoints): array
    {
        $errors = [];

        if ($definition->path === '' || $definition->path[0] !== '/') {
            $errors[] = sprintf('%s 的 path「%s」必须以 / 开头。', $definition->class, $definition->path);
        } elseif ($prefix !== '' && ! self::underPrefix($definition->path, $prefix)) {
            // websocket.path 是入口路径前缀，与注解里的 path 互为校验：
            // 客户端只需要记住一个前缀，网关转发规则也不会被任意路径绕过。
            $errors[] = sprintf('%s 的 path「%s」不在 websocket.path「/%s」之下。', $definition->class, $definition->path, $prefix);
        }

        if ($definition->endpoint === '') {
            $errors[] = sprintf('%s 未声明 endpoint：推送寻址依赖它，不能留空。', $definition->class);
        }

        if (! in_array($definition->server, $servers, true)) {
            $errors[] = sprintf(
                '%s 声明的 server「%s」在 config/autoload/server.php 里不存在，或不是 WebSocket 服务（type 需为 SERVER_WEBSOCKET）。'
                . '已声明的 WebSocket 服务：%s。',
                $definition->class,
                $definition->server,
                $servers === [] ? '（无）' : implode(', ', $servers),
            );
        }

        $path = $definition->server . ':' . $definition->path;
        if (isset($paths[$path])) {
            $errors[] = sprintf('入口 (server=%s, path=%s) 重复：%s 与 %s。', $definition->server, $definition->path, $paths[$path], $definition->class);
        } else {
            $paths[$path] = $definition->class;
        }

        if ($definition->endpoint !== '') {
            if (isset($endpoints[$definition->endpoint])) {
                $errors[] = sprintf('endpoint「%s」重复：%s 与 %s。', $definition->endpoint, $endpoints[$definition->endpoint], $definition->class);
            } else {
                $endpoints[$definition->endpoint] = $definition->class;
            }
        }

        $errors = [...$errors, ...$this->checkHandler($definition)];
        $errors = [...$errors, ...$this->checkMiddlewares($definition)];

        return $errors;
    }

    /**
     * 入口类必须同时实现三个生命周期契约：否则要等到第一条消息或第一次断开才炸。
     *
     * @return list<string>
     */
    private function checkHandler(WsGatewayDefinition $definition): array
    {
        // $definition->class 是 class-string（定义由注解扫描真实类得来），反射不会失败。
        $reflection = new ReflectionClass($definition->class);

        $errors = [];
        foreach ([OnOpenInterface::class, OnMessageInterface::class, OnCloseInterface::class] as $interface) {
            if (! $reflection->implementsInterface($interface)) {
                $errors[] = sprintf('%s 必须实现 %s。', $definition->class, $interface);
            }
        }

        return $errors;
    }

    /**
     * 中间件必须实现 MiddlewareInterface：Hyperf 对不合规的中间件不会报错，只会静默不执行，
     * 结果是握手鉴权被跳过而没有任何迹象。
     *
     * @return list<string>
     */
    private function checkMiddlewares(WsGatewayDefinition $definition): array
    {
        $errors = [];
        foreach ($definition->middlewares as $middleware) {
            if (! class_exists($middleware)) {
                $errors[] = sprintf('%s 的中间件 %s 不存在。', $definition->class, $middleware);
                continue;
            }
            if (! is_a($middleware, MiddlewareInterface::class, true)) {
                $errors[] = sprintf('%s 的中间件 %s 未实现 %s。', $definition->class, $middleware, MiddlewareInterface::class);
            }
        }

        return $errors;
    }

    /**
     * 路径是否落在前缀之下。用「等于前缀」或「前缀 + /」判断，避免 /websocket 被 /ws 误判为合法。
     */
    private static function underPrefix(string $path, string $prefix): bool
    {
        $root = '/' . $prefix;

        return $path === $root || str_starts_with($path, $root . '/');
    }
}
