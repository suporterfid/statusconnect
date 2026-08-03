<?php

// Ported / mirrored from TaskConnect: app/Domain/Execution/Outbound/OutboundPolicy.php

namespace App\Domain\Outbound;

final class OutboundPolicy
{
    public function __construct(
        private readonly OutboundPolicyConfig $config,
        private readonly UrlValidator $urlValidator,
        private readonly HeaderPolicy $headerPolicy,
    ) {
    }

    public static function fromConfig(
        OutboundPolicyConfig $config,
        DnsResolverInterface $dnsResolver,
    ): self {
        $ipClassifier = new IpClassifier($config->metadataIps);

        return new self(
            config: $config,
            urlValidator: new UrlValidator($config, $ipClassifier, $dnsResolver),
            headerPolicy: new HeaderPolicy(),
        );
    }

    /**
     * @param  list<string>  $additionalAllowHosts
     */
    public function validateUrl(
        string $url,
        array $additionalAllowHosts = [],
        EgressProfile|string|null $egressProfile = EgressProfile::Internal,
    ): ValidatedEndpoint {
        $profile = $egressProfile instanceof EgressProfile
            ? $egressProfile
            : EgressProfile::tryFromMixed(is_string($egressProfile) ? $egressProfile : null);

        return $this->urlValidator->validate($url, $additionalAllowHosts, $profile);
    }

    /**
     * @param  array<string, string|list<string>>  $headers
     */
    public function validateHeaders(array $headers): void
    {
        $this->headerPolicy->validate($headers);
    }

    /**
     * @param  array<string, string|list<string>>  $headers
     * @return array<string, string>
     */
    public function sanitizeHeaders(array $headers): array
    {
        return $this->headerPolicy->sanitize($headers, $this->config->userAgent);
    }

    public function config(): OutboundPolicyConfig
    {
        return $this->config;
    }
}
