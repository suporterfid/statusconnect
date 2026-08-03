<?php

namespace App\Infrastructure\Persistence\Eloquent;

use App\Domain\Monitoring\CheckState;
use App\Infrastructure\Persistence\Eloquent\Concerns\HasPublicId;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class CheckResult extends Model
{
    use HasFactory, HasPublicId;

    public const UPDATED_AT = null;

    protected $table = 'check_results';

    protected function publicIdPrefix(): string
    {
        return 'res_';
    }

    protected $fillable = [
        'public_id',
        'tenant_id',
        'environment_id',
        'monitor_id',
        'state',
        'latency_ms',
        'status_code',
        'failure_reason',
        'failure_excerpt',
        'checked_at',
    ];

    protected $casts = [
        'state' => CheckState::class,
        'checked_at' => 'datetime',
    ];

    public function tenant(): BelongsTo
    {
        return $this->belongsTo(Tenant::class, 'tenant_id');
    }

    public function environment(): BelongsTo
    {
        return $this->belongsTo(Environment::class, 'environment_id');
    }

    public function monitor(): BelongsTo
    {
        return $this->belongsTo(Monitor::class, 'monitor_id');
    }
}
