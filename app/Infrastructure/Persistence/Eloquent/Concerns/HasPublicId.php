<?php

// Ported / mirrored from TaskConnect: app/Infrastructure/Persistence/Eloquent/Concerns/HasPublicId.php

namespace App\Infrastructure\Persistence\Eloquent\Concerns;

use App\Domain\Shared\PublicId;

trait HasPublicId
{
    protected static function bootHasPublicId(): void
    {
        static::creating(function (self $model): void {
            if (empty($model->public_id)) {
                $model->public_id = PublicId::generate($model->publicIdPrefix());
            }
        });
    }

    abstract protected function publicIdPrefix(): string;

    public function getRouteKeyName(): string
    {
        return 'public_id';
    }
}
