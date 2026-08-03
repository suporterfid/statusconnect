<?php

// Ported / mirrored from TaskConnect: app/Infrastructure/Persistence/Eloquent/ApiKey.php

namespace App\Infrastructure\Persistence\Eloquent;

use App\Infrastructure\Persistence\Eloquent\Concerns\HasPublicId;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ApiKey extends Model
{
    use HasFactory, HasPublicId;

    protected $fillable = [
        'public_id',
        'tenant_id',
        'environment_id',
        'name',
        'key_prefix',
        'key_hash',
        'permissions',
        'created_by',
        'last_used_at',
        'expires_at',
        'revoked_at',
    ];

    protected function casts(): array
    {
        return [
            'permissions' => 'array',
            'last_used_at' => 'datetime',
            'expires_at' => 'datetime',
            'revoked_at' => 'datetime',
        ];
    }

    protected function publicIdPrefix(): string
    {
        return 'key_';
    }

    public function tenant(): BelongsTo
    {
        return $this->belongsTo(Tenant::class);
    }

    public function environment(): BelongsTo
    {
        return $this->belongsTo(Environment::class);
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function isActive(): bool
    {
        if ($this->revoked_at !== null) {
            return false;
        }

        if ($this->expires_at !== null && $this->expires_at->isPast()) {
            return false;
        }

        return true;
    }

    public function hasPermission(string $permission): bool
    {
        if (in_array('*', $this->permissions ?? [], true)) {
            return true;
        }

        return in_array($permission, $this->permissions ?? [], true);
    }
}
