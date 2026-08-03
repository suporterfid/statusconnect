<?php

// Ported / mirrored from TaskConnect: app/Domain/Shared/SystemClock.php

namespace App\Domain\Shared;

use DateTimeImmutable;
use DateTimeZone;

final class SystemClock implements Clock
{
    public function nowUtc(): DateTimeImmutable
    {
        return new DateTimeImmutable('now', new DateTimeZone('UTC'));
    }
}
