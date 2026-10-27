<?php

namespace Valtzu\OutgoingFirewall\DependencyInjection;

use Symfony\Component\Clock\ClockInterface;
use Symfony\Component\DependencyInjection\Argument\TaggedIteratorArgument;
use Symfony\Component\DependencyInjection\Compiler\CompilerPassInterface;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Symfony\Component\DependencyInjection\Definition;
use Symfony\Component\DependencyInjection\Reference;
use Valtzu\OutgoingFirewall\Evaluation\Evaluator;
use Valtzu\OutgoingFirewall\Http\FirewallHttpClient;
use Valtzu\OutgoingFirewall\Profiler\RequestLog;

final class DecorateTransportsPass implements CompilerPassInterface
{
    private const DECORATION_PRIORITY = 100;

    public function process(ContainerBuilder $container): void
    {
        if (!$container->hasParameter('outgoing_firewall.transports')) {
            return;
        }

        $language = $container->getDefinition('outgoing_firewall.expression_language');
        foreach (array_keys($container->findTaggedServiceIds('outgoing_firewall.expression_function_provider')) as $id) {
            $language->addMethodCall('registerProvider', [new Reference($id)]);
        }

        foreach ($container->getParameter('outgoing_firewall.transports') as $transportId) {
            if (!$container->hasDefinition($transportId)) {
                continue;
            }

            $decorator = (new Definition(FirewallHttpClient::class))
                ->setDecoratedService($transportId, null, self::DECORATION_PRIORITY)
                ->setArguments([
                    '$client' => new Reference('.inner'),
                    '$evaluator' => new Reference(Evaluator::class),
                    '$clock' => new Reference(ClockInterface::class),
                    '$variableProviders' => new TaggedIteratorArgument('outgoing_firewall.variable_provider'),
                    '$log' => $container->has(RequestLog::class) ? new Reference(RequestLog::class) : null,
                    '$defaultOptions' => $this->defaultOptionsOf($container->getDefinition($transportId)),
                    '$logger' => new Reference('logger', ContainerInterface::IGNORE_ON_INVALID_REFERENCE),
                ])
                ->addTag('monolog.logger', ['channel' => 'outgoing_firewall']);

            $container->setDefinition($transportId.'.outgoing_firewall', $decorator);
        }
    }

    /**
     * @return array<string, mixed>
     */
    private function defaultOptionsOf(Definition $transport): array
    {
        $options = $transport->getArguments()[0] ?? [];

        return \is_array($options) ? $options : [];
    }
}
