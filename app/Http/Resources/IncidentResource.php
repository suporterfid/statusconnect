<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** @mixin \App\Infrastructure\Persistence\Eloquent\Incident */
class IncidentResource extends JsonResource
{
    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        return [
            'public_id' => $this->public_id,
            'manual' => $this->manual,
            'severity' => $this->severity,
            'summary' => $this->summary,
            'started_at' => $this->started_at?->utc()->toIso8601String(),
            'confirmed_at' => $this->confirmed_at?->utc()->toIso8601String(),
            'resolved_at' => $this->resolved_at?->utc()->toIso8601String(),
            'resolved' => $this->resolved_at !== null,
            'duration_seconds' => $this->duration_seconds,
            'updates' => IncidentUpdateResource::collection($this->whenLoaded('updates')),
        ];
    }
}
