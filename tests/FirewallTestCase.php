<?php

namespace Valtzu\OutgoingFirewall\Tests;

use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use Symfony\Component\Clock\MockClock;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\Response\MockResponse;
use Symfony\Contracts\HttpClient\HttpClientInterface;
use Valtzu\OutgoingFirewall\Evaluation\Evaluator;
use Valtzu\OutgoingFirewall\Expression\FirewallExpressionLanguage;
use Valtzu\OutgoingFirewall\Http\FirewallHttpClient;
use Valtzu\OutgoingFirewall\Profiler\RequestLog;
use Valtzu\OutgoingFirewall\Rule\RuleSet;

abstract class FirewallTestCase extends TestCase
{
    /**
     * @param array<string, mixed> $config
     */
    protected static function ruleSet(array $config): RuleSet
    {
        return RuleSet::fromConfig($config + ['default' => 'allow', 'rules' => [], 'chains' => []]);
    }

    /**
     * @param array<string, mixed> $rule
     *
     * @return array<string, mixed>
     */
    protected static function rule(array $rule): array
    {
        return $rule + ['if' => null, 'action' => null, 'jump' => [], 'tag' => [], 'phase' => null];
    }

    /**
     * @param list<array<string, mixed>> $rules
     *
     * @return array{phase: ?string, rules: list<array<string, mixed>>}
     */
    protected static function chain(array $rules, ?string $phase = null): array
    {
        return ['phase' => $phase, 'rules' => array_map(self::rule(...), $rules)];
    }

    protected static function client(RuleSet $ruleSet, ?HttpClientInterface $inner = null, string $now = '2026-10-28 12:00:00', ?RequestLog $log = null, array $defaultOptions = [], ?LoggerInterface $logger = null): FirewallHttpClient
    {
        return new FirewallHttpClient(
            $inner ?? new MockHttpClient(static fn (): MockResponse => new MockResponse('ok')),
            new Evaluator($ruleSet, new FirewallExpressionLanguage()),
            new MockClock($now),
            [],
            $log,
            $defaultOptions,
            $logger,
        );
    }
}
