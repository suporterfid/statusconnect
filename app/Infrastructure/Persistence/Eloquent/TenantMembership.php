<?php

// Ported / mirrored from TaskConnect: app/Infrastructure/Persistence/Eloquent/TenantMembership.php

namespace App\Infrastructure\Persistence\Eloquent;

use App\Domain\Shared\TenantRole;
use App\Infrastructure\Persistence\Eloquent\Concerns\HasPublicId;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class TenantMembership extends Model
{
    use HasFactory, HasPublicId;

    protected $fillable = [
        'public_id',
        'tenant_id',
        'user_id',
        'role',
    ];

    protected function publicIdPrefix(): string
    {
        return 'mem_';
    }

    public function tenant(): BelongsTo
    {
        return $this->belongsTo(Tenant::class);
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function getRoleEnum(): TenantRole
    {
        return TenantRole::from($this->role);
    }
}
