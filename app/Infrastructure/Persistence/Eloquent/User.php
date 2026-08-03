<?php

// Ported / mirrored from TaskConnect: app/Infrastructure/Persistence/Eloquent/User.php

namespace App\Infrastructure\Persistence\Eloquent;

use App\Infrastructure\Persistence\Eloquent\Concerns\HasPublicId;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Laravel\Sanctum\HasApiTokens;

class User extends Authenticatable
{
    use HasApiTokens, HasFactory, HasPublicId, Notifiable;

    protected $fillable = [
        'public_id',
        'name',
        'email',
        'password',
        'is_platform_admin',
    ];

    protected $hidden = [
        'password',
        'remember_token',
    ];

    protected function casts(): array
    {
        return [
            'email_verified_at' => 'datetime',
            'password' => 'hashed',
            'is_platform_admin' => 'boolean',
        ];
    }

    protected function publicIdPrefix(): string
    {
        return 'usr_';
    }

    protected static function newFactory()
    {
        return \Database\Factories\UserFactory::new();
    }

    public function memberships(): HasMany
    {
        return $this->hasMany(TenantMembership::class);
    }

    public function preferences(): HasOne
    {
        return $this->hasOne(UserPreference::class);
    }

    public function isPlatformAdmin(): bool
    {
        return (bool) $this->is_platform_admin;
    }
}
