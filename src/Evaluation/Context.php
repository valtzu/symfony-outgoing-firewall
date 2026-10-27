<?php

namespace Valtzu\OutgoingFirewall\Evaluation;

final class Context
{
    /** @var array<string, true> */
    private array $tags = [];

    private bool $logged = false;

    /**
     * @param array<string, mixed> $variables
     */
    public function __construct(private array $variables)
    {
    }

    /**
     * @param array<string, mixed> $variables
     */
    public function with(array $variables): void
    {
        $this->variables = $variables + $this->variables;
    }

    public function markLogged(): void
    {
        $this->logged = true;
    }

    public function isLogged(): bool
    {
        return $this->logged;
    }

    public function addTag(string $tag): void
    {
        $this->tags[$tag] = true;
    }

    /**
     * @return list<string>
     */
    public function tags(): array
    {
        return array_map('strval', array_keys($this->tags));
    }

    /**
     * @return array<string, mixed>
     */
    public function variables(): array
    {
        return $this->variables;
    }

    /**
     * @return array<string, mixed>
     */
    public function expressionValues(): array
    {
        return $this->variables + ['tags' => new TagSet($this->tags())];
    }
}
