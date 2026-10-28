<?php

namespace Valtzu\OutgoingFirewall\Evaluation;

/**
 * Lets expressions write extra['target'] for a key that may not be set: null when absent.
 *
 * @implements \ArrayAccess<string, mixed>
 */
final readonly class OptionMap implements \ArrayAccess
{
    /**
     * @param array<string, mixed> $options
     */
    public function __construct(private array $options)
    {
    }

    public function offsetExists(mixed $offset): bool
    {
        return true;
    }

    public function offsetGet(mixed $offset): mixed
    {
        return $this->options[$offset] ?? null;
    }

    public function offsetSet(mixed $offset, mixed $value): void
    {
        throw new \LogicException('Extra options are read-only in expressions.');
    }

    public function offsetUnset(mixed $offset): void
    {
        throw new \LogicException('Extra options are read-only in expressions.');
    }
}
