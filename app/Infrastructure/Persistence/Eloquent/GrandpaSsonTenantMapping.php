<?php

namespace App\Infrastructure\Persistence\Eloquent;

use App\Infrastructure\Persistence\Eloquent\Concerns\HasPublicId;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class GrandpaSsonTenantMapping extends Model
{
    use HasPublicId;

    protected $table = 'grandpasson_tenant_mappings';

    protected $fillable = [
        'public_id',
        'broker_tenant_id',
        'tenant_id',
        'role_mappings',
        'group_mappings',
        'created_by',
        'updated_by',
    ];

    protected function casts(): array
    {
        return [
            'role_mappings' => 'array',
            'group_mappings' => 'array',
        ];
    }

    protected function publicIdPrefix(): string
    {
        return 'gtm_';
    }

    public function tenant(): BelongsTo
    {
        return $this->belongsTo(Tenant::class);
    }

    public function createdBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function updatedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'updated_by');
    }
}
