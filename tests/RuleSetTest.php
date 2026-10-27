<?php

namespace Valtzu\OutgoingFirewall\Tests;

use Symfony\Component\Config\Definition\Exception\InvalidConfigurationException;
use Valtzu\OutgoingFirewall\Rule\Phase;

final class RuleSetTest extends FirewallTestCase
{
    public function testJumpToUnknownChainIsRejected(): void
    {
        $this->expectException(InvalidConfigurationException::class);
        $this->expectExceptionMessage('unknown chain "nope"');

        self::ruleSet(['rules' => [self::rule(['jump' => ['nope']])]]);
    }

    public function testChainCycleIsRejected(): void
    {
        $this->expectException(InvalidConfigurationException::class);
        $this->expectExceptionMessage('a -> b -> a');

        self::ruleSet(['chains' => [
            'a' => self::chain([['jump' => ['b']]]),
            'b' => self::chain([['jump' => ['a']]]),
        ]]);
    }

    public function testRulesInheritChainPhaseAndJumpsStayPhaseless(): void
    {
        $ruleSet = self::ruleSet([
            'rules' => [self::rule(['jump' => ['late']]), self::rule(['action' => 'block'])],
            'chains' => ['late' => self::chain([['action' => 'block']], 'connect')],
        ]);

        self::assertNull($ruleSet->rules[0]->phase);
        self::assertSame(Phase::Request, $ruleSet->rules[1]->phase);
        self::assertSame(Phase::Connect, $ruleSet->chains['late'][0]->phase);
        self::assertTrue($ruleSet->hasConnectRules());
    }

    public function testWithoutConnectRules(): void
    {
        self::assertFalse(self::ruleSet(['rules' => [self::rule(['action' => 'block'])]])->hasConnectRules());
    }

    /**
     * @return iterable<string, array{array<string, mixed>, string}>
     */
    public static function matcherShorthands(): iterable
    {
        yield 'none' => [[], 'true'];
        yield 'host and path' => [['host' => ['Foo.com'], 'path' => ['/bar']], "host == 'foo.com' and path == '/bar'"];
        yield 'list' => [['method' => ['get', 'head']], "method in ['GET', 'HEAD']"];
        yield 'port is an int' => [['port' => ['8080']], 'port == 8080'];
        yield 'quotes are escaped' => [['path' => ["/it's"]], "path == '/it\\'s'"];
        yield 'combined with when' => [['host' => ['foo.com'], 'when' => "weekday == 'Sun' or hour < 6"], "host == 'foo.com' and (weekday == 'Sun' or hour < 6)"];
        yield 'when alone is untouched' => [['when' => 'hour < 6'], 'hour < 6'];
    }

    /**
     * @param array<string, mixed> $rule
     */
    #[\PHPUnit\Framework\Attributes\DataProvider('matcherShorthands')]
    public function testMatcherKeysCompileToAnExpression(array $rule, string $expected): void
    {
        $ruleSet = self::ruleSet(['rules' => [self::rule($rule + ['action' => 'block'])]]);

        self::assertSame($expected, $ruleSet->rules[0]->when);
    }
}
