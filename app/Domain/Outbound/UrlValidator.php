<?php

// Ported / mirrored from TaskConnect: app/Domain/Execution/Outbound/UrlValidator.php

namespace App\Domain\Outbound;

final class UrlValidator
{
    public function __construct(
        private readonly OutboundPolicyConfig $config,
        private readonly IpClassifier $ipClassifier,
        private readonly DnsResolverInterface $dnsResolver,
    ) {
    }

    /**
     * @param  list<string>  $additionalAllowHosts  Tenant / request RP allow hosts (internal profile).
     */
    public function validate(
        string $url,
        array $additionalAllowHosts = [],
        EgressProfile $egressProfile = EgressProfile::Internal,
    ): ValidatedEndpoint {
        $parts = parse_url($url);

        if ($parts === false || ! isset($parts['scheme'], $parts['host'])) {
            throw new OutboundPolicyViolation('invalid_url', 'The URL is malformed.');
        }

        $scheme = strtolower($parts['scheme']);

        if (! in_array($scheme, ['http', 'https'], true)) {
            throw new OutboundPolicyViolation('invalid_scheme', 'Only http and https URLs are allowed.');
        }

        if ($scheme === 'http' && ! $this->config->allowHttp) {
            throw new OutboundPolicyViolation('http_not_allowed', 'Plain HTTP is not allowed by platform policy.');
        }

        if (isset($parts['user']) || isset($parts['pass'])) {
            throw new OutboundPolicyViolation('embedded_credentials', 'URLs with embedded credentials are not allowed.');
        }

        $host = strtolower($parts['host']);

        if (str_starts_with($host, '[') && str_ends_with($host, ']')) {
            $host = substr($host, 1, -1);
        }

        if ($this->isLocalhostHostname($host)) {
            throw new OutboundPolicyViolation('localhost_hostname', 'Localhost hostnames are not allowed.');
        }

        if ($this->isMetadataHostname($host)) {
            throw new OutboundPolicyViolation('metadata_hostname', 'Cloud metadata hostnames are not allowed.');
        }

        $port = $this->resolvePort($scheme, $parts['port'] ?? null);

        if (! in_array($port, $this->config->allowedPorts, true)) {
            throw new OutboundPolicyViolation(
                'port_not_allowed',
                sprintf('Port %d is not allowed by platform policy.', $port),
            );
        }

        $testingAllowlisted = $this->isInHostList($host, $this->config->testingAllowHosts);
        $platformAllowlisted = $this->isInHostList($host, $this->config->platformAllowHosts);
        $tenantAllowlisted = $this->isInHostList($host, $additionalAllowHosts);
        $apiAllowlisted = $this->isInHostList($host, $this->config->profile(EgressProfile::Api)->allowHosts);
        $rpAllowlisted = $platformAllowlisted || $testingAllowlisted || $tenantAllowlisted;

        if (filter_var($host, FILTER_VALIDATE_IP)) {
            return $this->validateLiteralIp($url, $scheme, $host, $port, $egressProfile, $testingAllowlisted);
        }

        $resolvedIps = $this->dnsResolver->resolve($host);

        if ($resolvedIps === []) {
            throw new OutboundPolicyViolation('dns_resolution_failed', 'Unable to resolve destination hostname.');
        }

        $blocked = $this->ipClassifier->containsBlocked($resolvedIps);

        match ($egressProfile) {
            EgressProfile::Internal => $this->assertInternal($rpAllowlisted, $blocked),
            EgressProfile::PublicCrawl => $this->assertPublicCrawl($blocked, $testingAllowlisted),
            EgressProfile::Api => $this->assertApi($apiAllowlisted || $testingAllowlisted, $blocked, $testingAllowlisted),
        };

        $hostAllowlisted = match ($egressProfile) {
            EgressProfile::Internal => $rpAllowlisted,
            EgressProfile::Api => $apiAllowlisted || $testingAllowlisted,
            EgressProfile::PublicCrawl => $testingAllowlisted,
        };

        return new ValidatedEndpoint(
            url: $url,
            scheme: $scheme,
            host: $host,
            port: $port,
            pinnedIp: $resolvedIps[0],
            resolvedIps: $resolvedIps,
            hostAllowlisted: $hostAllowlisted,
        );
    }

    private function validateLiteralIp(
        string $url,
        string $scheme,
        string $host,
        int $port,
        EgressProfile $egressProfile,
        bool $testingAllowlisted,
    ): ValidatedEndpoint {
        $blocked = $this->ipClassifier->isBlocked($host);

        if ($egressProfile === EgressProfile::PublicCrawl) {
            if ($blocked && ! $testingAllowlisted) {
                throw new OutboundPolicyViolation(
                    'blocked_ip',
                    'The destination IP address is not allowed.',
                );
            }
        } elseif ($egressProfile === EgressProfile::Api) {
            throw new OutboundPolicyViolation(
                'host_not_allowlisted',
                'The api egress profile only allows allowlisted API hostnames.',
            );
        } else {
            throw new OutboundPolicyViolation(
                'host_not_allowlisted',
                'The internal egress profile only allows allowlisted hostnames.',
            );
        }

        return new ValidatedEndpoint(
            url: $url,
            scheme: $scheme,
            host: $host,
            port: $port,
            pinnedIp: $host,
            resolvedIps: [$host],
            hostAllowlisted: false,
        );
    }

    private function assertInternal(bool $allowlisted, bool $blocked): void
    {
        if (! $allowlisted) {
            throw new OutboundPolicyViolation(
                'host_not_allowlisted',
                'The internal egress profile only allows allowlisted RP hosts.',
            );
        }

        unset($blocked);
    }

    private function assertPublicCrawl(bool $blocked, bool $testingAllowlisted): void
    {
        if ($blocked && ! $testingAllowlisted) {
            throw new OutboundPolicyViolation(
                'blocked_ip',
                'The destination resolves to a blocked IP address.',
            );
        }
    }

    private function assertApi(bool $allowlisted, bool $blocked, bool $testingAllowlisted): void
    {
        if (! $allowlisted) {
            throw new OutboundPolicyViolation(
                'host_not_allowlisted',
                'The api egress profile only allows allowlisted API hosts.',
            );
        }

        if ($blocked && ! $testingAllowlisted) {
            throw new OutboundPolicyViolation(
                'blocked_ip',
                'The destination resolves to a blocked IP address.',
            );
        }
    }

    private function isLocalhostHostname(string $host): bool
    {
        if ($host === 'localhost') {
            return true;
        }

        return str_ends_with($host, '.localhost');
    }

    private function isMetadataHostname(string $host): bool
    {
        foreach ($this->config->metadataHosts as $metadataHost) {
            if ($host === $metadataHost || str_ends_with($host, '.'.$metadataHost)) {
                return true;
            }
        }

        return false;
    }

    /**
     * @param  list<string>  $hosts
     */
    private function isInHostList(string $host, array $hosts): bool
    {
        foreach ($hosts as $allowedHost) {
            $normalized = strtolower(trim((string) $allowedHost));
            if ($normalized !== '' && $host === $normalized) {
                return true;
            }
        }

        return false;
    }

    private function resolvePort(string $scheme, mixed $explicitPort): int
    {
        if ($explicitPort !== null && $explicitPort !== '') {
            return (int) $explicitPort;
        }

        return $scheme === 'https' ? 443 : 80;
    }
}
