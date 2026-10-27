<?php

namespace Valtzu\OutgoingFirewall\Evaluation;

/**
 * Adds variables to the expression context of every request.
 *
 * Register as a service tagged "outgoing_firewall.variable_provider".
 */
interface VariableProviderInterface
{
    /**
     * @return array<string, mixed>
     */
    public function getVariables(): array;
}
