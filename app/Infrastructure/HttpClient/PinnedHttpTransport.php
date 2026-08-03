<?php

// Ported / mirrored from TaskConnect: app/Infrastructure/HttpClient/PinnedHttpTransport.php

namespace App\Infrastructure\HttpClient;

interface PinnedHttpTransport
{
    public function send(PinnedHttpRequest $request): PinnedHttpResponse;
}
