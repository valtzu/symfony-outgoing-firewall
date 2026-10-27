<?php

namespace Valtzu\OutgoingFirewall\Tests;

use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\Response\MockResponse;
use Valtzu\OutgoingFirewall\Http\RequestBlockedException;
use Valtzu\OutgoingFirewall\Profiler\RequestLog;
use Valtzu\OutgoingFirewall\Rule\Action;
use Valtzu\OutgoingFirewall\Rule\Phase;

final class FirewallHttpClientTest extends FirewallTestCase
{
    public function testDefaultAllowPassesRequestsWithoutRules(): void
    {
        self::assertSame('ok', self::client(self::ruleSet([]))->request('GET', 'https://example.com/')->getContent());
    }

    public function testDefaultBlock(): void
    {
        $this->expectException(RequestBlockedException::class);
        $this->expectExceptionMessage('default is block');

        self::client(self::ruleSet(['default' => 'block']))->request('GET', 'https://example.com/');
    }

    public function testFirstMatchingRuleWins(): void
    {
        $client = self::client(self::ruleSet(['default' => 'block', 'rules' => [
            self::rule(['when' => "host == 'api.example.com'", 'action' => 'allow']),
            self::rule(['when' => "host ends with '.example.com'", 'action' => 'block']),
        ]]));

        self::assertSame('ok', $client->request('GET', 'https://api.example.com/')->getContent());

        $this->expectException(RequestBlockedException::class);
        $client->request('GET', 'https://other.example.com/');
    }

    public function testRequiredHeaderValue(): void
    {
        $client = self::client(self::ruleSet(['rules' => [self::rule(['when' => "header('User-Agent') != 'foobar'", 'action' => 'block'])]]));

        self::assertSame('ok', $client->request('GET', 'https://example.com/', ['headers' => ['User-Agent' => 'foobar']])->getContent());

        $this->expectException(RequestBlockedException::class);
        $client->request('GET', 'https://example.com/');
    }

    public function testHeadersSetThroughWithOptionsAndDefaultOptionsAreSeen(): void
    {
        $ruleSet = self::ruleSet(['rules' => [self::rule(['when' => "header('x-env') != 'staging'", 'action' => 'block'])]]);

        self::assertSame('ok', self::client($ruleSet, defaultOptions: ['headers' => ['X-Env' => 'staging']])->request('GET', 'https://example.com/')->getContent());
        self::assertSame('ok', self::client($ruleSet)->withOptions(['headers' => ['X-Env' => 'staging']])->request('GET', 'https://example.com/')->getContent());
    }

    public function testRelativeUrlResolvesAgainstBaseUri(): void
    {
        $client = self::client(self::ruleSet(['rules' => [self::rule(['when' => "host == 'api.example.com'", 'action' => 'block'])]]))
            ->withOptions(['base_uri' => 'https://api.example.com']);

        $this->expectException(RequestBlockedException::class);
        $client->request('GET', '/v1/things');
    }

    public function testWeekday(): void
    {
        $ruleSet = self::ruleSet(['rules' => [self::rule(['when' => "weekday in ['Sat', 'Sun']", 'action' => 'block'])]]);

        self::assertSame('ok', self::client($ruleSet, now: '2026-10-28')->request('GET', 'https://example.com/')->getContent());

        $this->expectException(RequestBlockedException::class);
        self::client($ruleSet, now: '2026-10-31')->request('GET', 'https://example.com/');
    }

    public function testChainsAreAppliedByJumpAndFallThroughWhenNothingDecides(): void
    {
        $ruleSet = self::ruleSet([
            'default' => 'block',
            'chains' => ['trusted' => self::chain([['when' => "method == 'DELETE'", 'action' => 'block']])],
            'rules' => [
                self::rule(['when' => "host == 'api.example.com'", 'jump' => ['trusted']]),
                self::rule(['when' => "host == 'api.example.com'", 'action' => 'allow']),
            ],
        ]);
        $client = self::client($ruleSet);

        self::assertSame('ok', $client->request('GET', 'https://api.example.com/')->getContent());

        $this->expectException(RequestBlockedException::class);
        $client->request('DELETE', 'https://api.example.com/');
    }

    public function testTagsSetByOneRuleAreVisibleToTheNext(): void
    {
        $client = self::client(self::ruleSet(['rules' => [
            self::rule(['when' => "host ends with '.example.com'", 'tag' => ['external']]),
            self::rule(['when' => "tagged('external') and method != 'GET'", 'action' => 'block']),
        ]]));

        self::assertSame('ok', $client->request('GET', 'https://a.example.com/')->getContent());
        self::assertSame('ok', $client->request('POST', 'https://internal.test/')->getContent());

        $this->expectException(RequestBlockedException::class);
        $client->request('POST', 'https://a.example.com/');
    }

    public function testConnectPhaseBlocksOnceThePeerIpIsKnown(): void
    {
        $ruleSet = self::ruleSet(['rules' => [self::rule(['when' => "ip_in('10.0.0.0/8')", 'action' => 'block', 'phase' => 'connect'])]]);
        $inner = new MockHttpClient(static function (string $method, string $url, array $options): MockResponse {
            $options['on_progress'](0, 0, ['primary_ip' => '10.1.2.3', 'connect_time' => 0.01]);

            return new MockResponse('ok');
        });

        $this->expectException(RequestBlockedException::class);
        self::client($ruleSet, $inner)->request('GET', 'https://example.com/')->getContent();
    }

    public function testConnectPhaseAllowsOtherIpsAndKeepsCallersProgressCallback(): void
    {
        $ruleSet = self::ruleSet(['rules' => [self::rule(['when' => 'connect_time > 1.0', 'action' => 'block', 'phase' => 'connect'])]]);
        $inner = new MockHttpClient(static function (string $method, string $url, array $options): MockResponse {
            $options['on_progress'](5, 10, ['primary_ip' => '93.184.216.34', 'connect_time' => 0.2]);

            return new MockResponse('ok');
        });

        $seen = [];
        $response = self::client($ruleSet, $inner)->request('GET', 'https://example.com/', ['on_progress' => static function (int $now) use (&$seen): void {
            $seen[] = $now;
        }]);

        self::assertSame('ok', $response->getContent());
        self::assertContains(5, $seen);
    }

    public function testRecordsDecisionAndTrace(): void
    {
        $log = new RequestLog();
        $client = self::client(self::ruleSet(['rules' => [
            self::rule(['when' => "host == 'nope.example.com'", 'action' => 'block']),
            self::rule(['when' => 'true', 'tag' => ['seen']]),
        ]]), log: $log);

        $client->request('GET', 'https://example.com/')->getContent();

        [$record] = $log->records();
        self::assertSame(Action::Allow, $record->action);
        self::assertTrue($record->decidedByDefault);
        self::assertSame(Phase::Request, $record->decidedIn);
        self::assertSame(['seen'], $record->tags);
        self::assertCount(2, $record->steps);
        self::assertFalse($record->steps[0]->matched);
        self::assertTrue($record->steps[1]->matched);
    }
}
