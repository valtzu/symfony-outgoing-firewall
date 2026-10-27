<?php

namespace Valtzu\OutgoingFirewall\Profiler;

use Symfony\Contracts\Service\ResetInterface;

final class RequestLog implements ResetInterface
{
    /** @var list<RequestRecord> */
    private array $records = [];

    public function add(RequestRecord $record): void
    {
        if (!\in_array($record, $this->records, true)) {
            $this->records[] = $record;
        }
    }

    /**
     * @return list<RequestRecord>
     */
    public function records(): array
    {
        return $this->records;
    }

    public function reset(): void
    {
        $this->records = [];
    }
}
