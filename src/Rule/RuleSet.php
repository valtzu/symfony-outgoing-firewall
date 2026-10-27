<?php

namespace Valtzu\OutgoingFirewall\Rule;

use Symfony\Component\Config\Definition\Exception\InvalidConfigurationException;

final readonly class RuleSet
{
    /**
     * @param list<Rule>                $rules
     * @param array<string, list<Rule>> $chains
     */
    public function __construct(
        public Action $default,
        public array $rules,
        public array $chains,
    ) {
        $this->assertJumpsResolve();
        $this->assertNoCycles();
    }

    /**
     * @param array{default: string, rules: list<array<string, mixed>>, chains: array<string, array{phase: ?string, rules: list<array<string, mixed>>}>} $config
     */
    public static function fromConfig(array $config): self
    {
        $chains = [];
        foreach ($config['chains'] as $name => $chain) {
            $chainPhase = null === $chain['phase'] ? null : Phase::from($chain['phase']);
            $chains[$name] = array_map(static fn (array $rule): Rule => self::createRule($rule, $chainPhase), $chain['rules']);
        }

        return new self(
            Action::from($config['default']),
            array_map(static fn (array $rule): Rule => self::createRule($rule, null), $config['rules']),
            $chains,
        );
    }

    public function hasConnectRules(): bool
    {
        foreach ([$this->rules, ...array_values($this->chains)] as $rules) {
            foreach ($rules as $rule) {
                if (Phase::Connect === $rule->phase) {
                    return true;
                }
            }
        }

        return false;
    }

    /**
     * @param array<string, mixed> $rule
     */
    private static function createRule(array $rule, ?Phase $chainPhase): Rule
    {
        $jump = $rule['jump'];
        $explicitPhase = null === $rule['phase'] ? null : Phase::from($rule['phase']);
        $phase = $explicitPhase ?? $chainPhase ?? ([] === $jump ? Phase::Request : null);

        return new Rule(
            $rule['when'],
            null === $rule['action'] ? null : Action::from($rule['action']),
            $jump,
            $rule['tag'],
            $phase,
        );
    }

    private function assertJumpsResolve(): void
    {
        foreach ([$this->rules, ...array_values($this->chains)] as $rules) {
            foreach ($rules as $rule) {
                foreach ($rule->jump as $name) {
                    if (!isset($this->chains[$name])) {
                        throw new InvalidConfigurationException(\sprintf('Outgoing firewall rule jumps to unknown chain "%s". Defined chains: %s.', $name, [] === $this->chains ? 'none' : '"'.implode('", "', array_keys($this->chains)).'"'));
                    }
                }
            }
        }
    }

    private function assertNoCycles(): void
    {
        $visit = function (string $name, array $path) use (&$visit): void {
            if (\in_array($name, $path, true)) {
                throw new InvalidConfigurationException(\sprintf('Outgoing firewall chains form a cycle: %s.', implode(' -> ', [...$path, $name])));
            }

            foreach ($this->chains[$name] as $rule) {
                foreach ($rule->jump as $next) {
                    $visit($next, [...$path, $name]);
                }
            }
        };

        foreach ($this->chains as $name => $_) {
            $visit($name, []);
        }
    }
}
