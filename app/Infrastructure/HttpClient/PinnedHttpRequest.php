<?php

// Ported / mirrored from TaskConnect: app/Infrastructure/HttpClient/PinnedHttpRequest.php

namespace App\Infrastructure\HttpClient;

use App\Domain\Outbound\EgressProfile;
use App\Domain\Outbound\ValidatedEndpoint;

final readonly class PinnedHttpRequest
{
    /**
     * @param  array<string, string>  $headers
     * @param  list<string>  $additionalAllowHosts
     */
    public function __construct(
        public string $method,
        public ValidatedEndpoint $endpoint,
        public array $headers = [],
        public ?string $body = null,
        public bool $verifyTls = true,
        public bool $followRedirects = true,
        public ?int $connectTimeout = null,
        public ?int $totalTimeout = null,
        public ?int $responseBodyLimit = null,
        public array $additionalAllowHosts = [],
        public EgressProfile $egressProfile = EgressProfile::Internal,
    ) {
    }
}
