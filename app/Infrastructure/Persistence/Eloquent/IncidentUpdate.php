<?php

namespace App\Infrastructure\Persistence\Eloquent;

use App\Infrastructure\Persistence\Eloquent\Concerns\HasPublicId;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class IncidentUpdate extends Model
{
    use HasFactory, HasPublicId;

    protected $fillable = [
        'public_id',
        'incident_id',
        'status',
        'message',
        'published_at',
    ];

    protected function casts(): array
    {
        return [
            'published_at' => 'datetime',
        ];
    }

    protected function publicIdPrefix(): string
    {
        return 'inu_';
    }

    public function incident(): BelongsTo
    {
        return $this->belongsTo(Incident::class);
    }
}
