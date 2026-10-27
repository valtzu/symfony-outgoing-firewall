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
}
