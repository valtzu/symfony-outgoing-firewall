<?php

use Symfony\Component\DependencyInjection\Loader\Configurator\ContainerConfigurator;
use Valtzu\OutgoingFirewall\Profiler\OutgoingFirewallDataCollector;
use Valtzu\OutgoingFirewall\Profiler\RequestLog;

use function Symfony\Component\DependencyInjection\Loader\Configurator\service;

return static function (ContainerConfigurator $container): void {
    $services = $container->services();

    $services->set(RequestLog::class)
        ->tag('kernel.reset', ['method' => 'reset']);

    $services->set(OutgoingFirewallDataCollector::class)
        ->args([service(RequestLog::class), service(\Valtzu\OutgoingFirewall\Rule\RuleSet::class)])
        ->tag('data_collector', ['id' => 'outgoing_firewall', 'template' => '@ValtzuOutgoingFirewall/data_collector/outgoing_firewall.html.twig']);
};
