<?php

// Ported / mirrored from TaskConnect: app/Application/ApiKeys/ApiKeyService.php

namespace App\Application\ApiKeys;

use App\Infrastructure\Persistence\Eloquent\ApiKey;
use App\Infrastructure\Persistence\Eloquent\Environment;
use App\Infrastructure\Persistence\Eloquent\Tenant;
use App\Infrastructure\Persistence\Eloquent\User;
use Illuminate\Support\Str;

final class ApiKeyService
{
    private const KEY_PREFIX = 'sc_';

    /** @var list<string> */
    public const ALLOWED_PERMISSIONS = [
        '*',
        'monitors:read',
        'monitors:write',
        'monitors:operate',
        'secrets:manage',
        'api_keys:manage',
        'tenant:admin',
    ];

    /**
     * @param  list<string>  $permissions
     * @return list<string>
     */
    public function normalizePermissions(array $permissions): array
    {
        $permissions = array_values(array_unique($permissions));

        if (in_array('*', $permissions, true)) {
            return ['*'];
        }

        return $permissions;
    }

    /**
     * @param  list<string>  $permissions
     * @return array{api_key: ApiKey, plaintext: string}
     */
    public function create(
        Tenant $tenant,
        User $actor,
        string $name,
        array $permissions,
        ?Environment $environment = null,
        ?\DateTimeInterface $expiresAt = null,
    ): array {
        $plaintext = self::KEY_PREFIX.Str::random(40);
        $prefix = substr($plaintext, 0, 8);

        $apiKey = ApiKey::query()->create([
            'tenant_id' => $tenant->id,
            'environment_id' => $environment?->id,
            'name' => $name,
            'key_prefix' => $prefix,
            'key_hash' => $this->hashKey($plaintext),
            'permissions' => $this->normalizePermissions($permissions),
            'created_by' => $actor->id,
            'expires_at' => $expiresAt,
        ]);

        return [
            'api_key' => $apiKey,
            'plaintext' => $plaintext,
        ];
    }

    /**
     * @param  array{name?: string, permissions?: list<string>, expires_at?: \DateTimeInterface|null}  $attributes
     */
    public function update(ApiKey $apiKey, array $attributes): ApiKey
    {
        if (array_key_exists('name', $attributes)) {
            $apiKey->name = $attributes['name'];
        }

        if (array_key_exists('permissions', $attributes)) {
            $apiKey->permissions = $this->normalizePermissions($attributes['permissions']);
        }

        if (array_key_exists('expires_at', $attributes)) {
            $apiKey->expires_at = $attributes['expires_at'];
        }

        $apiKey->save();

        return $apiKey->fresh();
    }

    public function revoke(ApiKey $apiKey): ApiKey
    {
        $apiKey->revoked_at = now();
        $apiKey->save();

        return $apiKey->fresh();
    }

    public function authenticate(string $plaintext): ?ApiKey
    {
        if (! str_starts_with($plaintext, self::KEY_PREFIX)) {
            return null;
        }

        $prefix = substr($plaintext, 0, 8);

        $candidates = ApiKey::query()
            ->where('key_prefix', $prefix)
            ->whereNull('revoked_at')
            ->get();

        foreach ($candidates as $candidate) {
            if (! hash_equals($candidate->key_hash, $this->hashKey($plaintext))) {
                continue;
            }

            if (! $candidate->isActive()) {
                return null;
            }

            $candidate->forceFill(['last_used_at' => now()])->save();

            return $candidate->fresh();
        }

        return null;
    }

    public function hashKey(string $plaintext): string
    {
        return hash('sha256', $plaintext);
    }
}
