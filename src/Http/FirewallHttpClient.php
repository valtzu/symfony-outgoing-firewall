<?php

namespace Valtzu\OutgoingFirewall\Http;

use Symfony\Component\Clock\ClockInterface;
use Symfony\Component\HttpClient\HttpClientTrait;
use Symfony\Contracts\HttpClient\HttpClientInterface;
use Symfony\Contracts\HttpClient\ResponseInterface;
use Symfony\Contracts\HttpClient\ResponseStreamInterface;
use Symfony\Contracts\Service\ResetInterface;
use Valtzu\OutgoingFirewall\Evaluation\Context;
use Valtzu\OutgoingFirewall\Evaluation\Evaluator;
use Valtzu\OutgoingFirewall\Evaluation\Trace;
use Valtzu\OutgoingFirewall\Evaluation\VariableProviderInterface;
use Valtzu\OutgoingFirewall\Profiler\RequestLog;
use Valtzu\OutgoingFirewall\Profiler\RequestRecord;
use Valtzu\OutgoingFirewall\Rule\Action;
use Valtzu\OutgoingFirewall\Rule\Phase;

final class FirewallHttpClient implements HttpClientInterface, ResetInterface
{
    use HttpClientTrait;

    /** @var array<string, mixed> */
    private array $defaultOptions;

    /**
     * @param array<string, mixed>                 $defaultOptions options the wrapped client applies on its own, as the firewall cannot read them from it
     * @param iterable<VariableProviderInterface>  $variableProviders
     */
    public function __construct(
        private HttpClientInterface $client,
        private readonly Evaluator $evaluator,
        private readonly ClockInterface $clock,
        private readonly iterable $variableProviders = [],
        private readonly ?RequestLog $log = null,
        array $defaultOptions = [],
    ) {
        $this->defaultOptions = self::mergeDefaultOptions($defaultOptions, [], true);
    }

    public function request(string $method, string $url, array $options = []): ResponseInterface
    {
        [$parts, $prepared] = self::prepareRequest($method, $url, $options, $this->defaultOptions, true);
        $fullUrl = implode('', $parts);

        $context = new Context($this->createVariables($method, $fullUrl, $prepared['normalized_headers']));
        $trace = new Trace();
        $record = new RequestRecord($method, $fullUrl);
        $this->log?->add($record);

        $action = $this->evaluator->evaluate(Phase::Request, $context, $trace);
        $ruleSet = $this->evaluator->ruleSet();
        $awaitsConnect = null === $action && $ruleSet->hasConnectRules();

        $this->conclude($record, $trace, $context, $action, Phase::Request, !$awaitsConnect);
        $this->assertAllowed($record, $method, $fullUrl);

        if ($awaitsConnect) {
            $options['on_progress'] = $this->createConnectGuard($record, $context, $method, $fullUrl, $options['on_progress'] ?? $this->defaultOptions['on_progress'] ?? null);
        }

        return $this->client->request($method, $url, $options);
    }

    public function stream(ResponseInterface|iterable $responses, ?float $timeout = null): ResponseStreamInterface
    {
        return $this->client->stream($responses, $timeout);
    }

    public function withOptions(array $options): static
    {
        $clone = clone $this;
        $clone->client = $this->client->withOptions($options);
        $clone->defaultOptions = self::mergeDefaultOptions($options, $this->defaultOptions, true);

        return $clone;
    }

    public function reset(): void
    {
        if ($this->client instanceof ResetInterface) {
            $this->client->reset();
        }
    }

    private function createConnectGuard(RequestRecord $record, Context $context, string $method, string $url, ?callable $inner): \Closure
    {
        $evaluated = false;

        return function (int $dlNow, int $dlSize, array $info) use (&$evaluated, $record, $context, $method, $url, $inner): void {
            if (!$evaluated && \is_string($ip = $info['primary_ip'] ?? null) && '' !== $ip) {
                $evaluated = true;
                $connectTime = (float) ($info['connect_time'] ?? 0.0);
                $context->with(['ip' => $ip, 'connect_time' => $connectTime > 0.0 ? $connectTime : null]);

                $trace = new Trace();
                $action = $this->evaluator->evaluate(Phase::Connect, $context, $trace);
                $this->conclude($record, $trace, $context, $action, Phase::Connect, true);
                $this->assertAllowed($record, $method, $url);
            }

            if (null !== $inner) {
                $inner($dlNow, $dlSize, $info);
            }
        };
    }

    private function conclude(RequestRecord $record, Trace $trace, Context $context, ?Action $action, Phase $phase, bool $final): void
    {
        array_push($record->steps, ...$trace->steps());
        $record->tags = $context->tags();
        $record->variables = $context->variables();

        if (null === $action && !$final) {
            return;
        }

        $record->decidedIn = $phase;
        $record->decidedByDefault = null === $action;
        $record->action = $action ?? $this->evaluator->ruleSet()->default;
    }

    private function assertAllowed(RequestRecord $record, string $method, string $url): void
    {
        if (Action::Block !== $record->action) {
            return;
        }

        $step = [] === $record->steps ? null : $record->steps[array_key_last($record->steps)];
        throw new RequestBlockedException($method, $url, $record->decidedByDefault || null === $step
            ? 'no rule matched, default is block'
            : \sprintf('%s rule #%d: %s', $step->chain ?? 'top-level', $step->index, $step->when));
    }

    /**
     * @param array<string, list<string>> $normalizedHeaders
     *
     * @return array<string, mixed>
     */
    private function createVariables(string $method, string $url, array $normalizedHeaders): array
    {
        $parts = parse_url($url);
        $scheme = $parts['scheme'] ?? 'http';
        $now = \DateTimeImmutable::createFromInterface($this->clock->now());

        $headers = [];
        foreach ($normalizedHeaders as $name => $lines) {
            $headers[strtolower($name)] = array_map(static fn (string $line): string => ltrim(substr($line, (int) strpos($line, ':') + 1)), $lines);
        }

        $variables = [
            'method' => $method,
            'url' => $url,
            'scheme' => $scheme,
            'host' => strtolower($parts['host'] ?? ''),
            'port' => $parts['port'] ?? ('https' === $scheme ? 443 : 80),
            'path' => $parts['path'] ?? '/',
            'query' => $parts['query'] ?? '',
            'headers' => $headers,
            'now' => $now,
            'weekday' => $now->format('D'),
            'hour' => (int) $now->format('G'),
            'ip' => null,
            'connect_time' => null,
        ];

        foreach ($this->variableProviders as $provider) {
            $variables = $provider->getVariables() + $variables;
        }

        return $variables;
    }
}
