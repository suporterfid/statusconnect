<?php

// Ported / mirrored from TaskConnect: app/Infrastructure/HttpClient/PinnedHttpResponse.php

namespace App\Infrastructure\HttpClient;

final readonly class PinnedHttpResponse
{
    /**
     * @param  array<string, list<string>>  $headers
     */
    public function __construct(
        public int $statusCode,
        public array $headers,
        public string $bodyTruncated,
        public string $bodySha256,
        public bool $bodyTruncatedFlag,
        public string $finalUrl,
        public int $redirectCount,
        public ?string $transportError = null,
    ) {
    }
}
