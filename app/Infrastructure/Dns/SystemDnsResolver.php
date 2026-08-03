<?php

// Ported / mirrored from TaskConnect: app/Infrastructure/Dns/SystemDnsResolver.php

namespace App\Infrastructure\Dns;

use App\Domain\Outbound\DnsResolverInterface;

final class SystemDnsResolver implements DnsResolverInterface
{
    /**
     * @return list<string>
     */
    public function resolve(string $hostname): array
    {
        $ips = [];

        $records = @dns_get_record($hostname, DNS_A | DNS_AAAA);

        if (is_array($records)) {
            foreach ($records as $record) {
                if (isset($record['ip'])) {
                    $ips[] = $record['ip'];
                }

                if (isset($record['ipv6'])) {
                    $ips[] = $record['ipv6'];
                }
            }
        }

        if ($ips === []) {
            $fallback = @gethostbynamel($hostname);

            if (is_array($fallback)) {
                $ips = array_values($fallback);
            }
        }

        return array_values(array_unique($ips));
    }
}
