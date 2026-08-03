<?php

// Ported / mirrored from TaskConnect: app/Infrastructure/Persistence/Eloquent/Tenant.php

namespace App\Infrastructure\Persistence\Eloquent;

use App\Infrastructure\Persistence\Eloquent\Concerns\HasPublicId;
use App\Infrastructure\Persistence\Eloquent\Concerns\SoftArchivable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Tenant extends Model
{
    use HasFactory, HasPublicId, SoftArchivable;

    protected $fillable = [
        'public_id',
        'name',
        'slug',
        'archived_at',
    ];

    protected function casts(): array
    {
        return [
            'archived_at' => 'datetime',
        ];
    }

    protected function publicIdPrefix(): string
    {
        return 'ten_';
    }

    public function memberships(): HasMany
    {
        return $this->hasMany(TenantMembership::class);
    }

    public function environments(): HasMany
    {
        return $this->hasMany(Environment::class);
    }

    public function apiKeys(): HasMany
    {
        return $this->hasMany(ApiKey::class);
    }

    public function secrets(): HasMany
    {
        return $this->hasMany(Secret::class);
    }
}
