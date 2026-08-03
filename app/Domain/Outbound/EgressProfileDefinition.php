<?php

// Ported / mirrored from TaskConnect: app/Domain/Execution/Outbound/EgressProfileDefinition.php

namespace App\Domain\Outbound;

final readonly class EgressProfileDefinition
{
    /**
     * @param  list<string>  $allowHosts
     */
    public function __construct(
        public EgressProfile $profile,
        public array $allowHosts = [],
        public ?int $redirectLimit = null,
        public ?int $responseBodyLimit = null,
        public ?int $connectTimeout = null,
        public ?int $totalTimeout = null,
    ) {
    }
}
