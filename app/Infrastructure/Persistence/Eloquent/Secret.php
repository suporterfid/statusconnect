<?php

// Ported / mirrored from TaskConnect: app/Infrastructure/Persistence/Eloquent/Secret.php

namespace App\Infrastructure\Persistence\Eloquent;

use App\Infrastructure\Persistence\Eloquent\Concerns\HasPublicId;
use App\Infrastructure\Persistence\Eloquent\Concerns\SoftArchivable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Secret extends Model
{
    use HasFactory, HasPublicId, SoftArchivable;

    protected $fillable = [
        'public_id',
        'tenant_id',
        'environment_id',
        'name',
        'encrypted_payload',
        'version',
        'created_by',
        'updated_by',
        'archived_at',
    ];

    protected function casts(): array
    {
        return [
            'archived_at' => 'datetime',
            'version' => 'integer',
        ];
    }

    protected function publicIdPrefix(): string
    {
        return 'sec_';
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

    public function updater(): BelongsTo
    {
        return $this->belongsTo(User::class, 'updated_by');
    }
}
