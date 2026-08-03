<?php

// Ported / mirrored from TaskConnect: app/Infrastructure/Persistence/Eloquent/Environment.php

namespace App\Infrastructure\Persistence\Eloquent;

use App\Infrastructure\Persistence\Eloquent\Concerns\HasPublicId;
use App\Infrastructure\Persistence\Eloquent\Concerns\SoftArchivable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Environment extends Model
{
    use HasFactory, HasPublicId, SoftArchivable;

    protected $fillable = [
        'public_id',
        'tenant_id',
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
        return 'env_';
    }

    public function tenant(): BelongsTo
    {
        return $this->belongsTo(Tenant::class);
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
