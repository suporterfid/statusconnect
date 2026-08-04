<?php

namespace App\Application\Incidents;

use App\Infrastructure\Persistence\Eloquent\Environment;
use App\Infrastructure\Persistence\Eloquent\Incident;
use App\Infrastructure\Persistence\Eloquent\IncidentUpdate;
use App\Infrastructure\Persistence\Eloquent\Tenant;
use DateTimeImmutable;
use Illuminate\Database\Eloquent\Collection;

class IncidentManagementService
{
    /** @return Collection<int, Incident> */
    public function list(Tenant $tenant, Environment $environment): Collection
    {
        return Incident::query()
            ->where('tenant_id', $tenant->id)
            ->where('environment_id', $environment->id)
            ->with('updates')
            ->latest('started_at')
            ->get();
    }

    public function get(Tenant $tenant, Environment $environment, string $publicId): Incident
    {
        return Incident::query()
            ->where('tenant_id', $tenant->id)
            ->where('environment_id', $environment->id)
            ->where('public_id', $publicId)
            ->with('updates')
            ->firstOrFail();
    }

    public function createManual(
        Tenant $tenant,
        Environment $environment,
        string $summary,
        string $severity,
        DateTimeImmutable $startedAt,
    ): Incident {
        return Incident::query()->create([
            'tenant_id' => $tenant->id,
            'environment_id' => $environment->id,
            'monitor_id' => null,
            'manual' => true,
            'resolved_flag' => false,
            'started_at' => $startedAt,
            'severity' => $severity,
            'summary' => $summary,
        ]);
    }

    public function addUpdate(
        Tenant $tenant,
        Environment $environment,
        string $incidentPublicId,
        string $message,
        ?string $status,
        DateTimeImmutable $publishedAt,
    ): IncidentUpdate {
        $incident = $this->get($tenant, $environment, $incidentPublicId);

        return $incident->updates()->create([
            'message' => $message,
            'status' => $status,
            'published_at' => $publishedAt,
        ]);
    }
}
