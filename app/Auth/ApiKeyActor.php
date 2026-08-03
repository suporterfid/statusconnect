<?php

// Ported / mirrored from TaskConnect: app/Auth/ApiKeyActor.php

namespace App\Auth;

use App\Infrastructure\Persistence\Eloquent\ApiKey;
use Illuminate\Contracts\Auth\Authenticatable;

final class ApiKeyActor implements Authenticatable
{
    public function __construct(public readonly ApiKey $apiKey) {}

    public function getAuthIdentifierName(): string
    {
        return 'id';
    }

    public function getAuthIdentifier(): int
    {
        return $this->apiKey->id;
    }

    public function getAuthPasswordName(): string
    {
        return 'key_hash';
    }

    public function getAuthPassword(): string
    {
        return '';
    }

    public function getRememberToken(): ?string
    {
        return null;
    }

    public function setRememberToken($value): void {}

    public function getRememberTokenName(): string
    {
        return '';
    }
}
