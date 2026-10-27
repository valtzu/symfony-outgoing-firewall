<?php

namespace Valtzu\OutgoingFirewall\Tests;

use PHPUnit\Framework\TestCase;
use Symfony\Component\Config\Definition\Exception\InvalidConfigurationException;
use Valtzu\OutgoingFirewall\Http\RequestBlockedException;
use Valtzu\OutgoingFirewall\Tests\Fixtures\TestKernel;

final class BundleTest extends TestCase
{
    public function testScopedClientHeadersReachTheRules(): void
    {
        $kernel = new TestKernel(['rules' => [['when' => "headers['user-agent'] != 'foobar'", 'action' => 'block']]], mockTransport: true);
        $kernel->boot();
        $container = $kernel->getContainer();

        self::assertSame('mocked', $container->get('test.github')->request('GET', '/zen')->getContent());

        $this->expectException(RequestBlockedException::class);
        $this->expectExceptionMessage('top-level rule #0');
        $container->get('test.plain')->request('GET', '/');
    }

    public function testFrameworkDefaultOptionsAndVariableProvidersReachTheRules(): void
    {
        $kernel = new TestKernel(['rules' => [['when' => "headers['x-default'] == 'yes' and region == 'eu'", 'action' => 'block']]]);
        $kernel->boot();

        $this->expectException(RequestBlockedException::class);
        $kernel->getContainer()->get('test.plain')->request('GET', '/');
    }

    public function testChainsAndTagsFromYamlShapedConfig(): void
    {
        $kernel = new TestKernel(mockTransport: true, firewall: [
            'default' => 'block',
            'chains' => [
                'no_post' => [['when' => "method == 'POST'", 'action' => 'block']],
            ],
            'rules' => [
                ['when' => "host == 'example.com'", 'tag' => 'known', 'jump' => 'no_post'],
                ['when' => "tags.known", 'action' => 'allow'],
            ],
        ]);
        $kernel->boot();
        $client = $kernel->getContainer()->get('test.plain');

        $this->expectException(RequestBlockedException::class);
        $client->request('POST', '/');
    }

    public function testTaggedExpressionFunctionProvidersAreRegistered(): void
    {
        $kernel = new TestKernel(['rules' => [['when' => 'always_true()', 'action' => 'block']]], mockTransport: true);
        $kernel->boot();

        $this->expectException(RequestBlockedException::class);
        $kernel->getContainer()->get('test.plain')->request('GET', '/');
    }

    public function testInvalidConfigIsRejectedAtBuildTime(): void
    {
        $this->expectException(InvalidConfigurationException::class);

        (new TestKernel(['rules' => [['jump' => 'missing']]]))->boot();
    }

    public function testRuleNeedsAnEffect(): void
    {
        $this->expectException(InvalidConfigurationException::class);
        $this->expectExceptionMessage('at least one of "action", "jump" or "tag"');

        (new TestKernel(['rules' => [['when' => 'true']]]))->boot();
    }

    public function testCollectorSummarisesRequests(): void
    {
        $kernel = new TestKernel(['rules' => [['when' => "host == 'example.com'", 'action' => 'block']]]);
        $kernel->boot();
        $container = $kernel->getContainer();
        $log = $container->get('test.log');

        try {
            $container->get('test.plain')->request('GET', '/');
        } catch (RequestBlockedException) {
        }

        $collector = $container->get('test.collector');
        $collector->lateCollect();

        self::assertCount(1, $log->records());
        self::assertSame(1, $collector->countBlocked());
        self::assertSame(0, $collector->countAllowed());
    }
}
