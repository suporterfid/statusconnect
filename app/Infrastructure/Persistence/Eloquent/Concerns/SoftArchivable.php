<?php

// Ported / mirrored from TaskConnect: app/Infrastructure/Persistence/Eloquent/Concerns/SoftArchivable.php

namespace App\Infrastructure\Persistence\Eloquent\Concerns;

trait SoftArchivable
{
    public function archive(): bool
    {
        $this->archived_at = now();

        return $this->save();
    }

    public function unarchive(): bool
    {
        $this->archived_at = null;

        return $this->save();
    }

    public function isArchived(): bool
    {
        return $this->archived_at !== null;
    }
}
