<?php

// Adapted from taskconnect app/Auth/GrandpaSsonActor.php.

namespace App\Auth;

use App\Application\GrandpaSson\IntrospectionResult;
use Illuminate\Contracts\Auth\Authenticatable;

final class GrandpaSsonActor implements Authenticatable
{
    public function __construct(
        public readonly IntrospectionResult $introspection,
        public readonly string $tokenFingerprint,
    ) {}

    public function getAuthIdentifierName(): string
    {
        return 'id';
    }

    public function getAuthIdentifier(): string
    {
        return $this->tokenFingerprint;
    }

    public function getAuthPasswordName(): string
    {
        return 'token';
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
