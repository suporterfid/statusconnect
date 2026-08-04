<?php

namespace App\Application\GrandpaSson;

interface IntrospectionClientInterface
{
    public function introspect(string $token): IntrospectionResult;
}
