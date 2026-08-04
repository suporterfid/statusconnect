<?php

namespace App\Infrastructure\HttpClient;

interface MultiPinnedHttpProbe
{
    /**
     * @param  list<MultiPinnedHttpRequest>  $requests
     * @return list<MultiPinnedHttpResult>
     */
    public function probe(array $requests): array;
}
