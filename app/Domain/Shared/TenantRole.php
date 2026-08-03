<?php

namespace App\Domain\Shared;

enum TenantRole: string
{
    case OWNER = 'owner';
    case ADMIN = 'admin';
    case VIEWER = 'viewer';

    public function canWrite(): bool
    {
        return $this === self::OWNER || $this === self::ADMIN;
    }

    public function isOwner(): bool
    {
        return $this === self::OWNER;
    }
}
