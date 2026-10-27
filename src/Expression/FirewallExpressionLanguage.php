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
            'header',
            static fn (string $name): string => \sprintf('($values["headers"][strtolower(%s)] ?? null)', $name),
            static function (array $values, string $name): ?string {
                $header = $values['headers'][strtolower($name)] ?? null;

                return null === $header ? null : implode(', ', $header);
            },
        );

        $this->register(
            'tagged',
            static fn (string $tag): string => \sprintf('\in_array(%s, $values["tags"], true)', $tag),
            static fn (array $values, string $tag): bool => \in_array($tag, $values['tags'], true),
        );

        $this->register(
            'ip_in',
            static fn (string $ranges): string => \sprintf('(null !== $values["ip"] && \Symfony\Component\HttpFoundation\IpUtils::checkIp($values["ip"], %s))', $ranges),
            static fn (array $values, string|array $ranges): bool => \is_string($values['ip'] ?? null) && IpUtils::checkIp($values['ip'], $ranges),
        );
    }
}
