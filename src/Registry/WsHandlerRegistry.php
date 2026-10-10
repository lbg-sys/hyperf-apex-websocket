<?php

declare(strict_types=1);

namespace Hyperf\Apex\WebSocket\Registry;

use Hyperf\Apex\DTO\AbstractDTO;
use Hyperf\Apex\WebSocket\Attribute\WsRoute;
use Hyperf\Apex\WebSocket\Definition\WsRouteDefinition;
use Hyperf\Apex\WebSocket\Exception\WsDeclarationException;
use Hyperf\Apex\WebSocket\Protocol\WsBuiltinEvent;
use Hyperf\Apex\WebSocket\Session\WsRequestContext;
use Hyperf\Di\Annotation\AnnotationCollector;
use ReflectionMethod;
use ReflectionNamedType;
use ReflectionType;

/**
 * 扫描 #[WsRoute]，产出 event → handler 映射并做启动期校验。
 *
 * payload 与响应类型从方法签名推断（见 #[WsRoute]），这样注解里不会出现第二份类名。
 */
final class WsHandlerRegistry
{
    /**
     * 事件名格式：{domain}.{action}，全小写、点号分隔。
     */
    private const EVENT_PATTERN = '/^[a-z][a-z0-9_]*\.[a-z][a-z0-9_]*$/';

    /** @var null|array<string, WsRouteDefinition> */
    private ?array $cache = null;

    /**
     * @return array<string, WsRouteDefinition>
     *
     * @throws WsDeclarationException 任意一项声明校验不通过
     */
    public function all(): array
    {
        if ($this->cache !== null) {
            return $this->cache;
        }

        $errors = [];
        $definitions = [];

        foreach (AnnotationCollector::getMethodsByAnnotation(WsRoute::class) as $metadata) {
            $class = (string) $metadata['class'];
            $method = (string) $metadata['method'];
            $annotation = $metadata['annotation'];
            if (! $annotation instanceof WsRoute) {
                continue;
            }

            $where = $class . '::' . $method;

            if (preg_match(self::EVENT_PATTERN, $annotation->event) !== 1) {
                $errors[] = sprintf('%s 的事件名「%s」不符合 {domain}.{action} 格式（全小写、点号分隔）。', $where, $annotation->event);
                continue;
            }

            if (str_starts_with($annotation->event, WsBuiltinEvent::PREFIX)) {
                $errors[] = sprintf('%s 的事件名「%s」占用了内置前缀 %s。', $where, $annotation->event, WsBuiltinEvent::PREFIX);
                continue;
            }

            try {
                $definition = $this->definition($class, $method, $annotation->event);
            } catch (WsDeclarationException $exception) {
                $errors = [...$errors, ...$exception->errors()];
                continue;
            }

            if (isset($definitions[$definition->event])) {
                $errors[] = sprintf(
                    '事件「%s」重复注册：%s 与 %s。后注册的会覆盖先注册的，必须只保留一个。',
                    $definition->event,
                    $definitions[$definition->event]->handlerId(),
                    $definition->handlerId(),
                );
                continue;
            }

            $definitions[$definition->event] = $definition;
        }

        if ($errors !== []) {
            throw new WsDeclarationException($errors);
        }

        return $this->cache = $definitions;
    }

    public function find(string $event): ?WsRouteDefinition
    {
        return $this->all()[$event] ?? null;
    }

    public function has(string $event): bool
    {
        return isset($this->all()[$event]);
    }

    /**
     * @return list<string>
     */
    public function events(): array
    {
        return array_keys($this->all());
    }

    private function definition(string $class, string $method, string $event): WsRouteDefinition
    {
        $reflection = new ReflectionMethod($class, $method);
        $errors = [];
        $payloadPosition = null;
        $payloadClass = null;
        $contextPosition = null;

        foreach ($reflection->getParameters() as $position => $parameter) {
            $name = self::typeName($parameter->getType());
            if ($name === WsRequestContext::class) {
                if ($contextPosition !== null) {
                    $errors[] = sprintf('%s 有多个 WsRequestContext 参数。', self::where($class, $method));
                }
                $contextPosition = $position;
                continue;
            }

            if ($name !== null && is_a($name, AbstractDTO::class, true)) {
                if ($payloadPosition !== null) {
                    $errors[] = sprintf('%s 有多个 AbstractDTO 参数，无法判断哪个是 payload。', self::where($class, $method));
                    continue;
                }
                $payloadPosition = $position;
                $payloadClass = $name;
                continue;
            }

            $errors[] = sprintf(
                '%s 的参数 $%s 类型必须是 payload DTO（%s 子类）或 %s，当前是 %s。',
                self::where($class, $method),
                $parameter->getName(),
                AbstractDTO::class,
                WsRequestContext::class,
                $name ?? '未声明',
            );
        }

        if ($payloadPosition === null || $payloadClass === null) {
            $errors[] = sprintf('%s 至少需要一个 AbstractDTO 子类参数作为 payload。', self::where($class, $method));
        }

        $responseClass = null;
        $returnType = $reflection->getReturnType();
        if ($returnType !== null) {
            $returnName = self::typeName($returnType);
            if ($returnName !== null && in_array($returnName, ['void', 'null', 'never'], true)) {
                // 无响应体，合法。
            } elseif ($returnName !== null && is_a($returnName, AbstractDTO::class, true)) {
                $responseClass = $returnName;
            } else {
                $errors[] = sprintf(
                    '%s 的返回类型必须是 void、null 或 AbstractDTO 子类，当前是 %s。',
                    self::where($class, $method),
                    $returnName ?? (string) $returnType,
                );
            }
        }

        if ($errors !== []) {
            throw new WsDeclarationException($errors);
        }

        /* @var class-string<AbstractDTO> $payloadClass */
        /* @var int $payloadPosition */
        return new WsRouteDefinition($event, $class, $method, $payloadClass, $responseClass, $contextPosition, $payloadPosition);
    }

    private static function typeName(?ReflectionType $type): ?string
    {
        return $type instanceof ReflectionNamedType ? $type->getName() : null;
    }

    private static function where(string $class, string $method): string
    {
        return $class . '::' . $method;
    }
}
