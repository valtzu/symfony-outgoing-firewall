<?php

namespace Valtzu\OutgoingFirewall\Rule;

final readonly class Rule
{
    /**
     * @param list<string> $jump
     * @param list<string> $tag
     */
    public function __construct(
        public string $when,
        public ?Action $action,
        public array $jump,
        public array $tag,
        public ?Phase $phase,
    ) {
    }

    public function appliesTo(Phase $phase): bool
    {
        return null === $this->phase || $this->phase === $phase;
    }

    public function describeEffect(): string
    {
        $effects = [];

        if ([] !== $this->tag) {
            $effects[] = 'tag '.implode(', ', $this->tag);
        }

        if ([] !== $this->jump) {
            $effects[] = 'jump '.implode(', ', $this->jump);
        }

        if (null !== $this->action) {
            $effects[] = $this->action->value;
        }

        return implode('; ', $effects);
    }
}
