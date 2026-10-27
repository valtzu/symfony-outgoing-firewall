<?php

namespace Valtzu\OutgoingFirewall\Evaluation;

final class Trace
{
    /** @var list<Step> */
    private array $steps = [];

    public function record(Step $step): void
    {
        $this->steps[] = $step;
    }

    /**
     * @return list<Step>
     */
    public function steps(): array
    {
        return $this->steps;
    }
}
