<?php

// Ported / mirrored from TaskConnect: app/Domain/Execution/Outbound/DnsResolverInterface.php

namespace App\Domain\Outbound;

interface DnsResolverInterface
{
    /**
     * @return list<string>
     */
    public function resolve(string $hostname): array;
}
