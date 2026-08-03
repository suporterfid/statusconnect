<?php

namespace App\Infrastructure\Persistence\Eloquent;

use Illuminate\Database\Eloquent\Model;

// Mirrors taskconnect app/Infrastructure/Persistence/Eloquent/SystemHeartbeat.php.
final class SystemHeartbeat extends Model
{
    protected $fillable = [
        'name',
        'last_seen_at',
        'meta_json',
    ];

    protected function casts(): array
    {
        return [
            'last_seen_at' => 'datetime',
            'meta_json' => 'array',
        ];
    }
}
