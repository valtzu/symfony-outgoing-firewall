<?php

namespace Valtzu\OutgoingFirewall\Evaluation;

use Valtzu\OutgoingFirewall\Rule\Phase;

final readonly class Step
{
    public function __construct(
        public Phase $phase,
        public ?string $chain,
        public int $index,
        public string $if,
        public bool $matched,
        public string $effect,
    ) {
    }
}
