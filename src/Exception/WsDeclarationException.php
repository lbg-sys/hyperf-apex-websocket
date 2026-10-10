<?php

declare(strict_types=1);

namespace Hyperf\Apex\WebSocket\Exception;

use RuntimeException;

/**
 * WebSocket 声明不完整时抛出。
 *
 * 声明校验一次性收集全部问题再抛，避免「改一条、启动一次」的往返。
 */
final class WsDeclarationException extends RuntimeException
{
    /**
     * @param list<string> $errors
     */
    public function __construct(private readonly array $errors)
    {
        parent::__construct("WebSocket 声明检查失败：\n - " . implode("\n - ", $errors));
    }

    /**
     * @return list<string>
     */
    public function errors(): array
    {
        return $this->errors;
    }
}
