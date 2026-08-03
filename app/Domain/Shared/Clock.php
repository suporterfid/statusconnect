<?php

// Ported / mirrored from TaskConnect: app/Domain/Shared/Clock.php

namespace App\Domain\Shared;

use DateTimeImmutable;

interface Clock
{
    public function nowUtc(): DateTimeImmutable;
}
