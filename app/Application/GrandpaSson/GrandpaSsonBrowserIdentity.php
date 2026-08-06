<?php

namespace App\Application\GrandpaSson;

final readonly class GrandpaSsonBrowserIdentity
{
    /** @param list<string> $groups */
    public function __construct(
        public string $email,
        public string $name,
        public string $brokerTenantId,
        public string $brokerRole,
        public array $groups,
        public string $locale = 'en',
    ) {}
}
