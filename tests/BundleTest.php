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
        $kernel = new TestKernel(['rules' => [['if' => "headers['user-agent'] != 'foobar'", 'action' => 'block']]], mockTransport: true);
        $kernel->boot();
        $container = $kernel->getContainer();

        self::assertSame('mocked', $container->get('test.github')->request('GET', '/zen')->getContent());

        $this->expectException(RequestBlockedException::class);
        $this->expectExceptionMessage('top-level rule #0');
        $container->get('test.plain')->request('GET', '/');
    }

    public function testFrameworkDefaultOptionsAndVariableProvidersReachTheRules(): void
    {
        $kernel = new TestKernel(['rules' => [['if' => "headers['x-default'] == 'yes' and region == 'eu'", 'action' => 'block']]]);
        $kernel->boot();

        $this->expectException(RequestBlockedException::class);
        $kernel->getContainer()->get('test.plain')->request('GET', '/');
    }

    public function testChainsAndTagsFromYamlShapedConfig(): void
    {
        $kernel = new TestKernel(mockTransport: true, firewall: [
            'default' => 'block',
            'chains' => [
                'no_post' => [['if' => "method == 'POST'", 'action' => 'block']],
            ],
            'rules' => [
                ['if' => "host == 'example.com'", 'tag' => 'known', 'jump' => 'no_post'],
                ['if' => "tags.known", 'action' => 'allow'],
            ],
        ]);
        $kernel->boot();
        $client = $kernel->getContainer()->get('test.plain');

        $this->expectException(RequestBlockedException::class);
        $client->request('POST', '/');
    }

    public function testTaggedExpressionFunctionProvidersAreRegistered(): void
    {
        $kernel = new TestKernel(['rules' => [['if' => 'always_true()', 'action' => 'block']]], mockTransport: true);
        $kernel->boot();

        $this->expectException(RequestBlockedException::class);
        $kernel->getContainer()->get('test.plain')->request('GET', '/');
    }

    public function testMatcherShorthandsFromConfig(): void
    {
        $kernel = new TestKernel(['rules' => [
            ['if' => ['host' => 'example.com', 'path' => '/admin', 'method' => ['post', 'delete']], 'action' => 'block'],
        ]], mockTransport: true);
        $kernel->boot();
        $client = $kernel->getContainer()->get('test.plain');

        self::assertSame('mocked', $client->request('GET', '/admin')->getContent());
        self::assertSame('mocked', $client->request('POST', '/public')->getContent());

        $this->expectException(RequestBlockedException::class);
        $client->request('POST', '/admin');
    }

    public function testTagConditionAndEffectAreDistinctInConfig(): void
    {
        $kernel = new TestKernel(['rules' => [
            ['if' => ['host' => 'example.com'], 'tag' => 'internal'],
            ['if' => ['tags' => 'internal', 'method' => 'POST'], 'action' => 'block'],
        ]], mockTransport: true);
        $kernel->boot();
        $client = $kernel->getContainer()->get('test.plain');

        self::assertSame('mocked', $client->request('GET', '/')->getContent());

        $this->expectException(RequestBlockedException::class);
        $client->request('POST', '/');
    }

    public function testInvalidConfigIsRejectedAtBuildTime(): void
    {
        $this->expectException(InvalidConfigurationException::class);

        (new TestKernel(['rules' => [['jump' => 'missing']]]))->boot();
    }

    /**
     * @return iterable<string, array{mixed, string}>
     */
    public static function invalidConditions(): iterable
    {
        yield 'matchers and expression mixed' => [['host' => 'a.test', 'expression' => 'true'], 'Unknown matcher "expression"'];
        yield 'unknown matcher' => [['hostname' => 'a.test'], 'Unknown matcher "hostname"'];
        yield 'empty map' => [[], 'expression string or a map of matchers'];
        yield 'list' => [['a.test'], 'expression string or a map of matchers'];
        yield 'nested value' => [['host' => [['a.test']]], 'Matcher "host" takes a value or a list of values'];
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('invalidConditions')]
    public function testInvalidConditionIsRejectedAtBuildTime(mixed $condition, string $message): void
    {
        $this->expectException(InvalidConfigurationException::class);
        $this->expectExceptionMessage($message);

        (new TestKernel(['rules' => [['if' => $condition, 'action' => 'block']]]))->boot();
    }

    public function testRuleNeedsAnEffect(): void
    {
        $this->expectException(InvalidConfigurationException::class);
        $this->expectExceptionMessage('at least one of "action", "jump" or "tag"');

        (new TestKernel(['rules' => [['if' => 'true']]]))->boot();
    }

    public function testCollectorSummarisesRequests(): void
    {
        $kernel = new TestKernel(['rules' => [['if' => "host == 'example.com'", 'action' => 'block']]]);
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
        self::assertSame(0, $collector->countLogged());
    }
}
