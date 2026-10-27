<?php

namespace Valtzu\OutgoingFirewall\Profiler;

use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\DataCollector\DataCollector;
use Symfony\Component\HttpKernel\DataCollector\LateDataCollectorInterface;
use Valtzu\OutgoingFirewall\Rule\Action;
use Valtzu\OutgoingFirewall\Rule\Rule;
use Valtzu\OutgoingFirewall\Rule\RuleSet;

/**
 * @internal
 */
final class OutgoingFirewallDataCollector extends DataCollector implements LateDataCollectorInterface
{
    public function __construct(
        private readonly RequestLog $log,
        private readonly RuleSet $ruleSet,
    ) {
    }

    public function collect(Request $request, Response $response, ?\Throwable $exception = null): void
    {
    }

    public function lateCollect(): void
    {
        $this->data = [
            'default' => $this->ruleSet->default->value,
            'rules' => $this->describeRules($this->ruleSet->rules),
            'chains' => array_map($this->describeRules(...), $this->ruleSet->chains),
            'requests' => array_map(fn (RequestRecord $record): array => [
                'method' => $record->method,
                'url' => $record->url,
                'action' => $record->action?->value,
                'byDefault' => $record->decidedByDefault,
                'phase' => $record->decidedIn?->value,
                'tags' => $record->tags,
                'variables' => $this->cloneVar(array_diff_key($record->variables, ['now' => 0, 'url' => 0, 'method' => 0])),
                'steps' => array_map(static fn ($step): array => [
                    'phase' => $step->phase->value,
                    'chain' => $step->chain,
                    'index' => $step->index,
                    'when' => $step->when,
                    'matched' => $step->matched,
                    'effect' => $step->effect,
                ], $record->steps),
            ], $this->log->records()),
        ];
    }

    public function reset(): void
    {
        $this->data = [];
    }

    public function getName(): string
    {
        return 'outgoing_firewall';
    }

    public function getDefault(): string
    {
        return $this->data['default'] ?? 'allow';
    }

    /**
     * @return list<array<string, mixed>>
     */
    public function getRequests(): array
    {
        return $this->data['requests'] ?? [];
    }

    public function countBlocked(): int
    {
        return \count(array_filter($this->getRequests(), static fn (array $request): bool => Action::Block->value === $request['action']));
    }

    public function countAllowed(): int
    {
        return \count($this->getRequests()) - $this->countBlocked();
    }

    /**
     * @return list<array<string, mixed>>
     */
    public function getRules(): array
    {
        return $this->data['rules'] ?? [];
    }

    /**
     * @return array<string, list<array<string, mixed>>>
     */
    public function getChains(): array
    {
        return $this->data['chains'] ?? [];
    }

    /**
     * @param list<Rule> $rules
     *
     * @return list<array<string, mixed>>
     */
    private function describeRules(array $rules): array
    {
        return array_map(static fn (Rule $rule): array => [
            'when' => $rule->when,
            'effect' => $rule->describeEffect(),
            'phase' => $rule->phase?->value,
        ], $rules);
    }
}
