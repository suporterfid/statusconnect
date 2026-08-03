<?php

// Ported / mirrored from TaskConnect: app/Domain/Execution/Outbound/IpClassifier.php

namespace App\Domain\Outbound;

final class IpClassifier
{
    /**
     * @param  list<string>  $metadataIps
     */
    public function __construct(
        private readonly array $metadataIps = [],
    ) {
    }

    public function isBlocked(string $ip): bool
    {
        if ($this->isMetadataIp($ip)) {
            return true;
        }

        if (! filter_var($ip, FILTER_VALIDATE_IP)) {
            return true;
        }

        if (filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4)) {
            return $this->isBlockedIpv4($ip);
        }

        return $this->isBlockedIpv6(strtolower($ip));
    }

    /**
     * @param  list<string>  $ips
     */
    public function containsBlocked(array $ips): bool
    {
        foreach ($ips as $ip) {
            if ($this->isBlocked($ip)) {
                return true;
            }
        }

        return false;
    }

    private function isMetadataIp(string $ip): bool
    {
        $normalized = strtolower($ip);

        foreach ($this->metadataIps as $metadataIp) {
            if (strcasecmp($normalized, strtolower($metadataIp)) === 0) {
                return true;
            }
        }

        return false;
    }

    private function isBlockedIpv4(string $ip): bool
    {
        $long = ip2long($ip);

        if ($long === false) {
            return true;
        }

        if ($ip === '0.0.0.0') {
            return true;
        }

        if ($this->inRange($long, ip2long('127.0.0.0'), ip2long('127.255.255.255'))) {
            return true;
        }

        if ($this->inRange($long, ip2long('10.0.0.0'), ip2long('10.255.255.255'))) {
            return true;
        }

        if ($this->inRange($long, ip2long('172.16.0.0'), ip2long('172.31.255.255'))) {
            return true;
        }

        if ($this->inRange($long, ip2long('192.168.0.0'), ip2long('192.168.255.255'))) {
            return true;
        }

        if ($this->inRange($long, ip2long('169.254.0.0'), ip2long('169.254.255.255'))) {
            return true;
        }

        if ($this->inRange($long, ip2long('224.0.0.0'), ip2long('239.255.255.255'))) {
            return true;
        }

        if ($this->inRange($long, ip2long('240.0.0.0'), ip2long('255.255.255.255'))) {
            return true;
        }

        return false;
    }

    private function isBlockedIpv6(string $ip): bool
    {
        $packed = inet_pton($ip);

        if ($packed === false) {
            return true;
        }

        if ($ip === '::') {
            return true;
        }

        if ($ip === '::1') {
            return true;
        }

        if (str_starts_with($ip, 'fe8') || str_starts_with($ip, 'fe9') || str_starts_with($ip, 'fea') || str_starts_with($ip, 'feb')) {
            return true;
        }

        if (str_starts_with($ip, 'fc') || str_starts_with($ip, 'fd')) {
            return true;
        }

        if (str_starts_with($ip, 'ff')) {
            return true;
        }

        if (str_starts_with($ip, '2001:db8:')) {
            return true;
        }

        return false;
    }

    private function inRange(int $value, int $start, int $end): bool
    {
        return $value >= $start && $value <= $end;
    }
}
