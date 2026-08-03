<?php

namespace App\Domain\Outbound;

final readonly class TcpTargetValidator
{
    /**
     * @param  list<int>  $allowedPorts
     */
    public function __construct(
        private DnsResolverInterface $dnsResolver,
        private IpClassifier $ipClassifier,
        private array $allowedPorts,
    ) {
    }

    public function validate(string $target): ValidatedEndpoint
    {
        if (! preg_match('/^(?:\[([0-9a-fA-F:]+)\]|([^:\s]+)):(\d+)$/', $target, $matches)) {
            throw new OutboundPolicyViolation('invalid_tcp_target', 'invalid_tcp_target');
        }

        $host = strtolower($matches[1] !== '' ? $matches[1] : $matches[2]);
        $port = (int) $matches[3];
        if ($port < 1 || $port > 65535 || ! in_array($port, $this->allowedPorts, true)) {
            throw new OutboundPolicyViolation('port_not_allowed', 'port_not_allowed');
        }

        $resolvedIps = filter_var($host, FILTER_VALIDATE_IP) ? [$host] : $this->dnsResolver->resolve($host);
        if ($resolvedIps === [] || $this->ipClassifier->containsBlocked($resolvedIps)) {
            throw new OutboundPolicyViolation('private_or_reserved_ip', 'private_or_reserved_ip');
        }

        return new ValidatedEndpoint(
            url: sprintf('tcp://%s:%d', $host, $port),
            scheme: 'tcp',
            host: $host,
            port: $port,
            pinnedIp: $resolvedIps[0],
            resolvedIps: $resolvedIps,
            hostAllowlisted: false,
        );
    }
}
