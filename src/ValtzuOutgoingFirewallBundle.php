<?php

namespace Valtzu\OutgoingFirewall;

use Symfony\Component\Config\Definition\Builder\ArrayNodeDefinition;
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

    private function conditionNode(): ArrayNodeDefinition
    {
        $node = new ArrayNodeDefinition('if');
        $node
            ->info('When the rule applies. An expression, or a map of matchers that must all hold.')
            ->beforeNormalization()->ifString()->then(static fn (string $expression): array => ['expression' => $expression])->end()
            ->children()
                ->scalarNode('expression')->info('Expression, truthy when the rule applies.')->defaultNull()->end()
                ->append($this->matcherNode('method'))
                ->append($this->matcherNode('scheme'))
                ->append($this->matcherNode('host'))
                ->append($this->matcherNode('port'))
                ->append($this->matcherNode('path'))
                ->append($this->matcherNode('tags'))
            ->end();

        return $node;
    }

    private function matcherNode(string $name): ArrayNodeDefinition
    {
        $node = new ArrayNodeDefinition($name);
        $node
            ->info(\sprintf('Matches when %s equals the value, or any of the values given a list.', $name))
            ->beforeNormalization()->ifTrue(static fn (mixed $value): bool => !\is_array($value))->then(static fn (mixed $value): array => [$value])->end()
            ->scalarPrototype()->end();

        return $node;
    }
}
