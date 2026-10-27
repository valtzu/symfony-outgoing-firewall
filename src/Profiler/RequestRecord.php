<?php

namespace Valtzu\OutgoingFirewall\Profiler;

use Valtzu\OutgoingFirewall\Evaluation\Step;
use Valtzu\OutgoingFirewall\Rule\Action;
use Valtzu\OutgoingFirewall\Rule\Phase;

final class RequestRecord
{
    public ?Action $action = null;
    public bool $decidedByDefault = false;
    public ?Phase $decidedIn = null;

    /** @var list<Step> */
    public array $steps = [];

    /** @var list<string> */
    public array $tags = [];

    /** @var array<string, mixed> */
    public array $variables = [];

    public function __construct(
        public readonly string $method,
        public readonly string $url,
    ) {
    }
}
