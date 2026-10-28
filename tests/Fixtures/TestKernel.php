<?php

namespace Valtzu\OutgoingFirewall\Tests\Fixtures;

use Symfony\Bundle\FrameworkBundle\FrameworkBundle;
use Symfony\Bundle\FrameworkBundle\Kernel\MicroKernelTrait;
use Symfony\Component\Config\Loader\LoaderInterface;
use Symfony\Component\DependencyInjection\Compiler\CompilerPassInterface;
use Symfony\Component\DependencyInjection\Compiler\PassConfig;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\DependencyInjection\Reference;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpKernel\Kernel;
use Valtzu\OutgoingFirewall\ValtzuOutgoingFirewallBundle;

final class TestKernel extends Kernel
{
    use MicroKernelTrait;

    /**
     * @param array<string, mixed> $firewall
     */
    public function __construct(private readonly array $firewall, private readonly bool $mockTransport = false)
    {
        parent::__construct('test', true);
    }

    public function registerBundles(): iterable
    {
        yield new FrameworkBundle();
        yield new ValtzuOutgoingFirewallBundle();
    }

    public function getCacheDir(): string
    {
        return sys_get_temp_dir().'/outgoing-firewall-tests/'.md5(serialize([$this->firewall, $this->mockTransport, Kernel::VERSION]));
    }

    protected function build(ContainerBuilder $container): void
    {
        if (!$this->mockTransport) {
            return;
        }

        $container->addCompilerPass(new class() implements CompilerPassInterface {
            public function process(ContainerBuilder $container): void
            {
                $container->getDefinition('http_client.transport')
                    ->setClass(MockHttpClient::class)
                    ->setFactory(null)
                    ->setMethodCalls([])
                    ->setArguments([new Reference(MockResponseFactory::class)]);
            }
        }, PassConfig::TYPE_BEFORE_OPTIMIZATION, 0);
    }

    protected function configureContainer(\Symfony\Component\DependencyInjection\Loader\Configurator\ContainerConfigurator $container): void
    {
        $container->extension('framework', [
            'secret' => 'test',
            'test' => true,
            'http_client' => [
                'default_options' => ['headers' => ['X-Default' => 'yes']],
                'scoped_clients' => [
                    'github.client' => ['base_uri' => 'https://api.github.com', 'headers' => ['User-Agent' => 'foobar']],
                    'plain.client' => ['base_uri' => 'https://example.com', 'extra' => ['target' => 'plain']],
                ],
            ],
        ]);
        $container->extension('outgoing_firewall', $this->firewall);
        $container->services()->set(MockResponseFactory::class);
        $container->services()
            ->set(RegionProvider::class)
            ->tag('outgoing_firewall.variable_provider')
            ->public();
        $container->services()->set(WorkdayFunctionProvider::class)->tag('outgoing_firewall.expression_function_provider');
        $container->services()->alias('test.github', 'github.client')->public();
        $container->services()->alias('test.plain', 'plain.client')->public();
        $container->services()->alias('test.log', \Valtzu\OutgoingFirewall\Profiler\RequestLog::class)->public();
        $container->services()->alias('test.collector', \Valtzu\OutgoingFirewall\Profiler\OutgoingFirewallDataCollector::class)->public();
    }
}
