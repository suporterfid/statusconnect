<?php

namespace App\Application\GrandpaSson;

interface TokenClientInterface
{
    public function clientCredentialsToken(string $scope): TokenResponse;
}
