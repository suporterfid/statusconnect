<?php

namespace App\Infrastructure\Tcp;

final class PendingTcpBatch
{
    /** @param array<int, array{socket: resource, request: PinnedTcpRequest, startedAt: int, deadline: int}> $pending @param list<PinnedTcpResult> $results */
    public function __construct(public array $pending = [], public array $results = [])
    {
    }
}
