<?php

namespace Valtzu\OutgoingFirewall\Http;

use Symfony\Contracts\HttpClient\Exception\TransportExceptionInterface;

final class RequestBlockedException extends \RuntimeException implements TransportExceptionInterface
{
    public function __construct(string $method, string $url, string $reason)
    {
        parent::__construct(\sprintf('Outgoing request blocked by firewall: %s %s (%s)', $method, preg_replace('/\?.*/', '', $url), $reason));
    }
}
