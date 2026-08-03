<?php

namespace App\Infrastructure\Persistence\Eloquent;

use App\Domain\Monitoring\AssertionOperator;
use App\Domain\Monitoring\AssertionType;
use App\Infrastructure\Persistence\Eloquent\Concerns\HasPublicId;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class MonitorAssertion extends Model
{
    use HasFactory, HasPublicId;

    protected $table = 'monitor_assertions';

    protected function publicIdPrefix(): string
    {
        return 'ast_';
    }

    protected $fillable = [
        'public_id',
        'monitor_id',
        'type',
        'operator',
        'target_property',
        'expected_value',
        'case_sensitive',
        'order',
    ];

    protected $casts = [
        'type' => AssertionType::class,
        'operator' => AssertionOperator::class,
        'case_sensitive' => 'boolean',
    ];

    public function monitor(): BelongsTo
    {
        return $this->belongsTo(Monitor::class, 'monitor_id');
    }
}
