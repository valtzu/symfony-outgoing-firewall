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
            self::rule(['if' => "host == 'api.example.com'", 'action' => 'allow']),
            self::rule(['if' => "host ends with '.example.com'", 'action' => 'block']),
        ]]));

        self::assertSame('ok', $client->request('GET', 'https://api.example.com/')->getContent());

        $this->expectException(RequestBlockedException::class);
        $client->request('GET', 'https://other.example.com/');
    }

    public function testRequiredHeaderValue(): void
    {
        $client = self::client(self::ruleSet(['rules' => [self::rule(['if' => "headers['User-Agent'] != 'foobar'", 'action' => 'block'])]]));

        self::assertSame('ok', $client->request('GET', 'https://example.com/', ['headers' => ['User-Agent' => 'foobar']])->getContent());

        $this->expectException(RequestBlockedException::class);
        $client->request('GET', 'https://example.com/');
    }

    public function testHeadersSetThroughWithOptionsAndDefaultOptionsAreSeen(): void
    {
        $ruleSet = self::ruleSet(['rules' => [self::rule(['if' => "headers['x-env'] != 'staging'", 'action' => 'block'])]]);

        self::assertSame('ok', self::client($ruleSet, defaultOptions: ['headers' => ['X-Env' => 'staging']])->request('GET', 'https://example.com/')->getContent());
        self::assertSame('ok', self::client($ruleSet)->withOptions(['headers' => ['X-Env' => 'staging']])->request('GET', 'https://example.com/')->getContent());
    }

    public function testRelativeUrlResolvesAgainstBaseUri(): void
    {
        $client = self::client(self::ruleSet(['rules' => [self::rule(['if' => "host == 'api.example.com'", 'action' => 'block'])]]))
            ->withOptions(['base_uri' => 'https://api.example.com']);

        $this->expectException(RequestBlockedException::class);
        $client->request('GET', '/v1/things');
    }

    public function testExtraOptionsCanBeReadAndMissingKeysAreNull(): void
    {
        $ruleSet = self::ruleSet(['rules' => [
            self::rule(['if' => "extra['target'] == 'billing' and extra['nope'] === null", 'action' => 'block']),
        ]]);

        self::assertSame('ok', self::client($ruleSet)->request('GET', 'https://example.com/')->getContent());
        self::assertSame('ok', self::client($ruleSet)->request('GET', 'https://example.com/', ['extra' => ['target' => 'other']])->getContent());

        $this->expectException(RequestBlockedException::class);
        self::client($ruleSet)->request('GET', 'https://example.com/', ['extra' => ['target' => 'billing']]);
    }

    public function testExtraFromWithOptionsAndDefaultOptionsIsMerged(): void
    {
        $ruleSet = self::ruleSet(['rules' => [
            self::rule(['if' => "extra['target'] == 'billing' and extra['use_persistent_connections']", 'action' => 'block']),
        ]]);
        $client = self::client($ruleSet, defaultOptions: ['extra' => ['use_persistent_connections' => true]])->withOptions(['extra' => ['target' => 'billing']]);

        $this->expectException(RequestBlockedException::class);
        $client->request('GET', 'https://example.com/');
    }

    public function testWeekday(): void
    {
        $ruleSet = self::ruleSet(['rules' => [self::rule(['if' => "weekday in ['Sat', 'Sun']", 'action' => 'block'])]]);

        self::assertSame('ok', self::client($ruleSet, now: '2026-10-28')->request('GET', 'https://example.com/')->getContent());

        $this->expectException(RequestBlockedException::class);
        self::client($ruleSet, now: '2026-10-31')->request('GET', 'https://example.com/');
    }

    public function testChainsAreAppliedByJumpAndFallThroughWhenNothingDecides(): void
    {
        $ruleSet = self::ruleSet([
            'default' => 'block',
            'chains' => ['trusted' => self::chain([['if' => "method == 'DELETE'", 'action' => 'block']])],
            'rules' => [
                self::rule(['if' => "host == 'api.example.com'", 'jump' => ['trusted']]),
                self::rule(['if' => "host == 'api.example.com'", 'action' => 'allow']),
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
            self::rule(['if' => "host ends with '.example.com'", 'tag' => ['external']]),
            self::rule(['if' => "tags.external and method != 'GET'", 'action' => 'block']),
        ]]));

        self::assertSame('ok', $client->request('GET', 'https://a.example.com/')->getContent());
        self::assertSame('ok', $client->request('POST', 'https://internal.test/')->getContent());

        $this->expectException(RequestBlockedException::class);
        $client->request('POST', 'https://a.example.com/');
    }

    public function testTagsWithSpecialCharactersAreReadWithBrackets(): void
    {
        $client = self::client(self::ruleSet(['rules' => [
            self::rule(['tag' => ['pre-prod']]),
            self::rule(['if' => "tags['pre-prod'] and not tags.never_set", 'action' => 'block']),
        ]]));

        $this->expectException(RequestBlockedException::class);
        $client->request('GET', 'https://example.com/');
    }

    public function testConnectPhaseBlocksOnceThePeerIpIsKnown(): void
    {
        $ruleSet = self::ruleSet(['rules' => [self::rule(['if' => "ip_in('10.0.0.0/8')", 'action' => 'block', 'phase' => 'connect'])]]);
        $inner = new MockHttpClient(static function (string $method, string $url, array $options): MockResponse {
            $options['on_progress'](0, 0, ['primary_ip' => '10.1.2.3', 'connect_time' => 0.01]);

            return new MockResponse('ok');
        });

        $this->expectException(RequestBlockedException::class);
        self::client($ruleSet, $inner)->request('GET', 'https://example.com/')->getContent();
    }

    public function testConnectPhaseAllowsOtherIpsAndKeepsCallersProgressCallback(): void
    {
        $ruleSet = self::ruleSet(['rules' => [self::rule(['if' => 'connect_time > 1.0', 'action' => 'block', 'phase' => 'connect'])]]);
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

    public function testRecordsOnlyBlockedAndLoggedRequests(): void
    {
        $log = new RequestLog();
        $client = self::client(self::ruleSet(['rules' => [
            self::rule(['if' => ['host' => ['blocked.example.com']], 'action' => 'block']),
            self::rule(['if' => ['host' => ['logged.example.com']], 'action' => 'log']),
        ]]), log: $log);

        $client->request('GET', 'https://plain.example.com/')->getContent();
        $client->request('GET', 'https://logged.example.com/')->getContent();
        try {
            $client->request('GET', 'https://blocked.example.com/');
        } catch (RequestBlockedException) {
        }

        self::assertSame(['https://logged.example.com/', 'https://blocked.example.com/'], array_map(static fn ($record) => $record->url, $log->records()));
    }

    public function testLogRuleDoesNotDecideAndEvaluationContinues(): void
    {
        $log = new RequestLog();
        $client = self::client(self::ruleSet(['default' => 'block', 'rules' => [
            self::rule(['action' => 'log']),
            self::rule(['if' => ['host' => ['example.com']], 'action' => 'allow']),
        ]]), log: $log);

        self::assertSame('ok', $client->request('GET', 'https://example.com/')->getContent());

        [$record] = $log->records();
        self::assertTrue($record->logged);
        self::assertSame(Action::Allow, $record->action);
        self::assertCount(2, $record->steps);
    }

    public function testBlockedAndLoggedRequestsGoToTheLogger(): void
    {
        $logger = new class() extends \Psr\Log\AbstractLogger {
            /** @var list<array{string, string, array<string, mixed>}> */
            public array $records = [];

            public function log($level, string|\Stringable $message, array $context = []): void
            {
                $this->records[] = [(string) $level, (string) $message, $context];
            }
        };
        $client = self::client(self::ruleSet(['rules' => [
            self::rule(['if' => ['host' => ['logged.example.com']], 'action' => 'log']),
            self::rule(['if' => ['host' => ['blocked.example.com']], 'action' => 'block']),
        ]]), logger: $logger);

        $client->request('GET', 'https://logged.example.com/path?secret=1')->getContent();
        $client->request('GET', 'https://plain.example.com/')->getContent();
        try {
            $client->request('GET', 'https://blocked.example.com/');
        } catch (RequestBlockedException) {
        }

        self::assertSame(['info', 'warning'], array_column($logger->records, 0));
        self::assertSame('https://logged.example.com/path', $logger->records[0][2]['url']);
        self::assertStringContainsString('top-level rule #1', $logger->records[1][2]['reason']);
    }

    public function testRecordsDecisionAndTrace(): void
    {
        $log = new RequestLog();
        $client = self::client(self::ruleSet(['rules' => [
            self::rule(['if' => ['host' => ['nope.example.com']], 'action' => 'block']),
            self::rule(['tag' => ['seen'], 'action' => 'log']),
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

    public function testConnectPhaseBlockIsRecorded(): void
    {
        $log = new RequestLog();
        $inner = new MockHttpClient(static function (string $method, string $url, array $options): MockResponse {
            $options['on_progress'](0, 0, ['primary_ip' => '10.1.2.3']);

            return new MockResponse('ok');
        });
        $client = self::client(self::ruleSet(['rules' => [self::rule(['if' => "ip_in('10.0.0.0/8')", 'action' => 'block', 'phase' => 'connect'])]]), $inner, log: $log);

        try {
            $client->request('GET', 'https://example.com/')->getContent();
            self::fail('Expected the connect phase to block.');
        } catch (RequestBlockedException) {
        }

        self::assertCount(1, $log->records());
        self::assertSame(Phase::Connect, $log->records()[0]->decidedIn);
    }
}
