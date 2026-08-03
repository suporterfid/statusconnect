<?php

// Ported / mirrored from TaskConnect: app/Infrastructure/Persistence/Eloquent/AuditLog.php

namespace App\Infrastructure\Persistence\Eloquent;

use App\Infrastructure\Persistence\Eloquent\Concerns\HasPublicId;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class AuditLog extends Model
{
    use HasFactory, HasPublicId;

    public $timestamps = false;

    protected $fillable = [
        'public_id',
        'tenant_id',
        'environment_id',
        'actor_user_id',
        'action',
        'resource_type',
        'resource_id',
        'request_id',
        'summary_json',
        'created_at',
    ];

    protected function casts(): array
    {
        return [
            'summary_json' => 'array',
            'created_at' => 'datetime',
        ];
    }

    protected function publicIdPrefix(): string
    {
        return 'aud_';
    }

    public function tenant(): BelongsTo
    {
        return $this->belongsTo(Tenant::class);
    }

    public function environment(): BelongsTo
    {
        return $this->belongsTo(Environment::class);
    }

    public function actor(): BelongsTo
    {
        return $this->belongsTo(User::class, 'actor_user_id');
    }
}
