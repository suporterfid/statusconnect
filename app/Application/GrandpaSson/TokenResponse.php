<?php

namespace App\Application\GrandpaSson;

final readonly class TokenResponse
{
    public function __construct(
        public string $accessToken,
        public int $expiresAtUnix,
        public string $tokenType,
        public string $scope,
    ) {}
}
