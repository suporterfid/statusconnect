<?php

namespace App\Infrastructure\Persistence\Eloquent;

use App\Infrastructure\Persistence\Eloquent\Concerns\HasPublicId;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Incident extends Model
{
    use HasFactory, HasPublicId;

    protected $fillable = [
        'public_id',
        'tenant_id',
        'environment_id',
        'monitor_id',
        'manual',
        'resolved_flag',
        'started_at',
        'confirmed_at',
        'resolved_at',
        'duration_seconds',
        'severity',
        'summary',
    ];

    protected function casts(): array
    {
        return [
            'manual' => 'boolean',
            'resolved_flag' => 'boolean',
            'started_at' => 'datetime',
            'confirmed_at' => 'datetime',
            'resolved_at' => 'datetime',
            'duration_seconds' => 'integer',
        ];
    }

    protected function publicIdPrefix(): string
    {
        return 'inc_';
    }

    public function tenant(): BelongsTo
    {
        return $this->belongsTo(Tenant::class);
    }

    public function environment(): BelongsTo
    {
        return $this->belongsTo(Environment::class);
    }

    public function monitor(): BelongsTo
    {
        return $this->belongsTo(Monitor::class);
    }

    public function updates(): HasMany
    {
        return $this->hasMany(IncidentUpdate::class)->orderBy('published_at');
    }
}
