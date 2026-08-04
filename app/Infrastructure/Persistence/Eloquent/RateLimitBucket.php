<?php

// Ported / mirrored from TaskConnect: app/Infrastructure/Persistence/Eloquent/RateLimitBucket.php

namespace App\Infrastructure\Persistence\Eloquent;

use Illuminate\Database\Eloquent\Model;

class RateLimitBucket extends Model
{
    protected $fillable = [
        'bucket_key',
        'hits',
        'resets_at',
    ];

    protected function casts(): array
    {
        return [
            'hits' => 'integer',
            'resets_at' => 'datetime',
        ];
    }
}
