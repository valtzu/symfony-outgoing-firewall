<?php

use Symfony\Component\DependencyInjection\Loader\Configurator\ContainerConfigurator;
use Valtzu\OutgoingFirewall\Evaluation\Evaluator;
use Valtzu\OutgoingFirewall\Expression\FirewallExpressionLanguage;
use Valtzu\OutgoingFirewall\Rule\RuleSet;

use function Symfony\Component\DependencyInjection\Loader\Configurator\service;

return static function (ContainerConfigurator $container): void {
    $services = $container->services();

    $services->set(RuleSet::class)
        ->factory([RuleSet::class, 'fromConfig']);

    $services->set('outgoing_firewall.expression_language', FirewallExpressionLanguage::class);

    $services->set(Evaluator::class)
        ->args([service(RuleSet::class), service('outgoing_firewall.expression_language')]);
};
