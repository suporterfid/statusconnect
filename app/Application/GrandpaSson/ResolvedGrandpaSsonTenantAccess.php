<?php

namespace App\Application\GrandpaSson;

use App\Domain\Shared\TenantRole;
use App\Infrastructure\Persistence\Eloquent\Tenant;

final readonly class ResolvedGrandpaSsonTenantAccess
{
    public function __construct(
        public Tenant $tenant,
        public TenantRole $role,
    ) {}
}
