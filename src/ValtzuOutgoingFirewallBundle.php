<?php

namespace Valtzu\OutgoingFirewall;

use Symfony\Component\Config\Definition\Builder\ArrayNodeDefinition;
use Symfony\Component\Config\Definition\Builder\VariableNodeDefinition;
use Symfony\Component\Config\Definition\Configurator\DefinitionConfigurator;
use Symfony\Component\DependencyInjection\Compiler\PassConfig;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\DependencyInjection\Loader\Configurator\ContainerConfigurator;
use Symfony\Component\HttpKernel\Bundle\AbstractBundle;
use Valtzu\OutgoingFirewall\DependencyInjection\DecorateTransportsPass;
use Valtzu\OutgoingFirewall\Rule\RuleSet;

final class ValtzuOutgoingFirewallBundle extends AbstractBundle
{
    protected string $extensionAlias = 'outgoing_firewall';

    public function build(ContainerBuilder $container): void
    {
        $container->addCompilerPass(new DecorateTransportsPass(), PassConfig::TYPE_BEFORE_OPTIMIZATION, -10);
    }

    public function configure(DefinitionConfigurator $definition): void
    {
        $root = $definition->rootNode();
        $root
            ->children()
                ->booleanNode('enabled')->defaultTrue()->end()
                ->enumNode('default')
                    ->info('What happens to a request no rule decided.')
                    ->values(['allow', 'block'])
                    ->defaultValue('allow')
                ->end()
                ->arrayNode('transports')
                    ->info('Services to put the firewall in front of. Scoped clients all end up in http_client.transport.')
                    ->scalarPrototype()->end()
                    ->defaultValue(['http_client.transport'])
                ->end()
                ->append($this->rulesNode())
                ->arrayNode('chains')
                    ->useAttributeAsKey('name')
                    ->arrayPrototype()
                        ->beforeNormalization()
                            ->ifTrue(static fn (mixed $chain): bool => \is_array($chain) && array_is_list($chain))
                            ->then(static fn (array $rules): array => ['rules' => $rules])
                        ->end()
                        ->children()
                            ->enumNode('phase')->values(['request', 'connect'])->defaultNull()->end()
                            ->append($this->rulesNode())
                        ->end()
                    ->end()
                ->end()
            ->end();
    }

    public function loadExtension(array $config, ContainerConfigurator $container, ContainerBuilder $builder): void
    {
        if (!$config['enabled']) {
            return;
        }

        $ruleSetConfig = array_intersect_key($config, array_flip(['default', 'rules', 'chains']));
        RuleSet::fromConfig($ruleSetConfig);

        $builder->setParameter('outgoing_firewall.transports', $config['transports']);

        $container->import('../config/services.php');
        $container->services()
            ->get(RuleSet::class)
            ->args([$ruleSetConfig]);

        if ($builder->getParameter('kernel.debug')) {
            $container->import('../config/profiler.php');
        }
    }

    private function rulesNode(): ArrayNodeDefinition
    {
        $rules = new ArrayNodeDefinition('rules');
        $rules
            ->arrayPrototype()
                ->validate()
                    ->ifTrue(static fn (array $rule): bool => null === $rule['action'] && [] === $rule['jump'] && [] === $rule['tag'])
                    ->thenInvalid('A rule needs at least one of "action", "jump" or "tag".')
                ->end()
                ->children()
                    ->append($this->conditionNode())
                    ->enumNode('action')->info('allow and block decide the request, log flags it for the log and the profiler and evaluation continues.')->values(['allow', 'block', 'log'])->defaultNull()->end()
                    ->arrayNode('jump')
                        ->beforeNormalization()->ifString()->then(static fn (string $chain): array => [$chain])->end()
                        ->scalarPrototype()->end()
                    ->end()
                    ->arrayNode('tag')
                        ->beforeNormalization()->ifString()->then(static fn (string $tag): array => [$tag])->end()
                        ->scalarPrototype()->end()
                    ->end()
                    ->enumNode('phase')->values(['request', 'connect'])->defaultNull()->end()
                ->end()
            ->end();

        return $rules;
    }

    private function conditionNode(): VariableNodeDefinition
    {
        $node = new VariableNodeDefinition('if');
        $node
            ->info('When the rule applies: an expression string, or a map of matchers that must all hold. Not both.')
            ->defaultNull()
            ->validate()
                ->always(static function (mixed $condition): string|array|null {
                    if (null === $condition || (\is_string($condition) && '' !== $condition)) {
                        return $condition;
                    }

                    if (!\is_array($condition) || [] === $condition || array_is_list($condition)) {
                        throw new \InvalidArgumentException('Must be an expression string or a map of matchers.');
                    }

                    if ([] !== $unknown = array_diff(array_keys($condition), RuleSet::MATCHERS)) {
                        throw new \InvalidArgumentException(\sprintf('Unknown matcher "%s", expected one of "%s". Use an expression string for anything else.', implode('", "', $unknown), implode('", "', RuleSet::MATCHERS)));
                    }

                    foreach ($condition as $name => $values) {
                        foreach ((array) $values as $value) {
                            if (!\is_scalar($value)) {
                                throw new \InvalidArgumentException(\sprintf('Matcher "%s" takes a value or a list of values.', $name));
                            }
                        }
                    }

                    return $condition;
                })
            ->end();

        return $node;
    }
}
