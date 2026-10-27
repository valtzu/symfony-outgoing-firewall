<?php

namespace Valtzu\OutgoingFirewall\Evaluation;

/**
 * Lets expressions write headers['User-Agent']: case-insensitive, several values joined with ", ", null when absent.
 *
 * @implements \ArrayAccess<string, ?string>
 */
final readonly class HeaderSet implements \ArrayAccess
{
    /**
     * @param array<string, list<string>> $headers lowercase name to values
     */
    public function __construct(private array $headers)
    {
    }

    public function offsetExists(mixed $offset): bool
    {
        return true;
    }

    public function offsetGet(mixed $offset): ?string
    {
        $values = $this->headers[strtolower((string) $offset)] ?? null;

        return null === $values ? null : implode(', ', $values);
    }

    public function offsetSet(mixed $offset, mixed $value): void
    {
        throw new \LogicException('Headers are read-only in expressions.');
    }

    public function offsetUnset(mixed $offset): void
    {
        throw new \LogicException('Headers are read-only in expressions.');
    }
}
