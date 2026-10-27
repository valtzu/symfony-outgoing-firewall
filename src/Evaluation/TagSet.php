<?php

namespace Valtzu\OutgoingFirewall\Evaluation;

/**
 * Lets expressions write tags.external, or tags['my-tag'], for any tag name: true when set, false otherwise.
 *
 * @implements \ArrayAccess<string, bool>
 */
final readonly class TagSet implements \ArrayAccess
{
    /**
     * @param list<string> $tags
     */
    public function __construct(private array $tags)
    {
    }

    public function __isset(string $name): bool
    {
        return true;
    }

    public function __get(string $name): bool
    {
        return \in_array($name, $this->tags, true);
    }

    public function offsetExists(mixed $offset): bool
    {
        return true;
    }

    public function offsetGet(mixed $offset): bool
    {
        return \in_array($offset, $this->tags, true);
    }

    public function offsetSet(mixed $offset, mixed $value): void
    {
        throw new \LogicException('Tags are read-only in expressions.');
    }

    public function offsetUnset(mixed $offset): void
    {
        throw new \LogicException('Tags are read-only in expressions.');
    }
}
