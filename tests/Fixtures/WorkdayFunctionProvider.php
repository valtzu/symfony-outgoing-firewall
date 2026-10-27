<?php

namespace Valtzu\OutgoingFirewall\Tests\Fixtures;

use Symfony\Component\ExpressionLanguage\ExpressionFunction;
use Symfony\Component\ExpressionLanguage\ExpressionFunctionProviderInterface;

final class WorkdayFunctionProvider implements ExpressionFunctionProviderInterface
{
    public function getFunctions(): array
    {
        return [new ExpressionFunction(
            'always_true',
            static fn (): string => 'true',
            static fn (array $values): bool => true,
        )];
    }
}
