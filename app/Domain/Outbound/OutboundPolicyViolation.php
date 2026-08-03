<?php

// Ported / mirrored from TaskConnect: app/Domain/Execution/Outbound/OutboundPolicyViolation.php

namespace App\Domain\Outbound;

use RuntimeException;

final class OutboundPolicyViolation extends RuntimeException
{
    public function __construct(
        public readonly string $reasonCode,
        string $message,
    ) {
        parent::__construct($message);
    }
}
