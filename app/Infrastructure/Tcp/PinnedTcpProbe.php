<?php

namespace App\Infrastructure\Tcp;

interface PinnedTcpProbe
{
    /**
     * @param  list<PinnedTcpRequest>  $requests
     * @return list<PinnedTcpResult>
     */
    public function probe(array $requests): array;
}
