<?php

namespace Valtzu\OutgoingFirewall\Tests\Fixtures;

use Symfony\Component\HttpClient\Response\MockResponse;

final class MockResponseFactory
{
    public function __invoke(): MockResponse
    {
        return new MockResponse('mocked');
    }
}
