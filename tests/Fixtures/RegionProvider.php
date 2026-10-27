<?php

namespace Valtzu\OutgoingFirewall\Tests\Fixtures;

use Valtzu\OutgoingFirewall\Evaluation\VariableProviderInterface;

final class RegionProvider implements VariableProviderInterface
{
    public function getVariables(): array
    {
        return ['region' => 'eu'];
    }
}
