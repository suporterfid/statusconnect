<?php

namespace App\Application\Api;

use App\Infrastructure\Persistence\Eloquent\Environment;
use App\Infrastructure\Persistence\Eloquent\IdempotencyKey;
use App\Infrastructure\Persistence\Eloquent\Tenant;
use App\Infrastructure\Persistence\Eloquent\User;
use DateTimeImmutable;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class IdempotentWriteService
{
    /** @param callable(): IdempotencyResponse $write */
    public function handle(
        Tenant $tenant,
        Environment $environment,
        ?User $user,
        ?int $apiKeyId,
        string $key,
        string $route,
        string $requestBody,
        DateTimeImmutable $now,
        callable $write,
    ): IdempotencyResponse {
        $requestHash = hash('sha256', $requestBody);

        try {
            return DB::transaction(function () use ($tenant, $environment, $user, $apiKeyId, $key, $route, $requestHash, $now, $write) {
                $existing = IdempotencyKey::query()
                    ->where('tenant_id', $tenant->id)
                    ->where('environment_id', $environment->id)
                    ->where('key', $key)
                    ->where('route', $route)
                    ->lockForUpdate()
                    ->first();

                if ($existing !== null) {
                    return $this->replay($existing, $requestHash);
                }

                $response = $write();
                IdempotencyKey::query()->create([
                    'tenant_id' => $tenant->id,
                    'environment_id' => $environment->id,
                    'user_id' => $user?->id,
                    'api_key_id' => $apiKeyId,
                    'key' => $key,
                    'route' => $route,
                    'request_hash' => $requestHash,
                    'response_code' => $response->statusCode,
                    'response_body' => $response->body,
                    'expires_at' => $now->modify('+24 hours'),
                ]);

                return $response;
            });
        } catch (QueryException $exception) {
            $existing = IdempotencyKey::query()
                ->where('tenant_id', $tenant->id)
                ->where('environment_id', $environment->id)
                ->where('key', $key)
                ->where('route', $route)
                ->first();

            if ($existing !== null) {
                return $this->replay($existing, $requestHash);
            }

            throw $exception;
        }
    }

    private function replay(IdempotencyKey $existing, string $requestHash): IdempotencyResponse
    {
        if (! hash_equals($existing->request_hash, $requestHash)) {
            throw ValidationException::withMessages([
                'Idempotency-Key' => 'This key was already used with a different request.',
            ]);
        }

        return new IdempotencyResponse($existing->response_code, $existing->response_body);
    }
}
