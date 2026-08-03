<?php

namespace Tests\Unit;

use App\Domain\Shared\PublicId;
use PHPUnit\Framework\TestCase;

class PublicIdTest extends TestCase
{
    public function test_generates_prefixed_ulid(): void
    {
        $id = PublicId::generate('ten_');

        $this->assertStringStartsWith('ten_', $id);
        $this->assertEquals(30, strlen($id)); // 'ten_' (4) + ULID (26) = 30
    }

    public function test_appends_underscore_if_missing(): void
    {
        $id = PublicId::generate('mon');

        $this->assertStringStartsWith('mon_', $id);
    }

    public function test_request_id_prefix(): void
    {
        $id = PublicId::requestId();

        $this->assertStringStartsWith('req_', $id);
    }
}
