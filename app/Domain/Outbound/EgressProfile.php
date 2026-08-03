<?php

// Ported / mirrored from TaskConnect: app/Domain/Execution/Outbound/EgressProfile.php

namespace App\Domain\Outbound;

enum EgressProfile: string
{
    case Internal = 'internal';
    case PublicCrawl = 'public-crawl';
    case Api = 'api';

    public static function tryFromMixed(?string $value): self
    {
        if ($value === null || $value === '') {
            return self::Internal;
        }

        return self::tryFrom($value) ?? self::Internal;
    }
}
