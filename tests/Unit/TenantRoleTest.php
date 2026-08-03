<?php

namespace Tests\Unit;

use App\Domain\Shared\TenantRole;
use PHPUnit\Framework\TestCase;

class TenantRoleTest extends TestCase
{
    public function test_owner_permissions(): void
    {
        $role = TenantRole::OWNER;

        $this->assertTrue($role->isOwner());
        $this->assertTrue($role->canWrite());
    }

    public function test_admin_permissions(): void
    {
        $role = TenantRole::ADMIN;

        $this->assertFalse($role->isOwner());
        $this->assertTrue($role->canWrite());
    }

    public function test_viewer_permissions(): void
    {
        $role = TenantRole::VIEWER;

        $this->assertFalse($role->isOwner());
        $this->assertFalse($role->canWrite());
    }
}
