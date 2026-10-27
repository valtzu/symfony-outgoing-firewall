<?php

namespace Valtzu\OutgoingFirewall\Expression;

use Symfony\Component\ExpressionLanguage\ExpressionLanguage;
use Symfony\Component\HttpFoundation\IpUtils;

final class FirewallExpressionLanguage extends ExpressionLanguage
{
    protected function registerFunctions(): void
    {
        parent::registerFunctions();

        $this->register(
            'ip_in',
            static fn (string $ranges): string => \sprintf('(null !== $values["ip"] && \Symfony\Component\HttpFoundation\IpUtils::checkIp($values["ip"], %s))', $ranges),
            static fn (array $values, string|array $ranges): bool => \is_string($values['ip'] ?? null) && IpUtils::checkIp($values['ip'], $ranges),
        );
    }
}
