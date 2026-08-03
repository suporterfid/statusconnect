<?php

// Ported / mirrored from TaskConnect: app/Domain/Execution/Outbound/OutboundPolicyConfig.php

namespace App\Domain\Outbound;

final readonly class OutboundPolicyConfig
{
    /**
     * @param  list<int>  $allowedPorts
     * @param  list<string>  $platformAllowHosts
     * @param  list<string>  $testingAllowHosts
     * @param  list<string>  $metadataHosts
     * @param  list<string>  $metadataIps
     * @param  array<string, EgressProfileDefinition>  $profiles
     */
    public function __construct(
        public array $allowedPorts = [80, 443],
        public bool $allowHttp = false,
        public array $platformAllowHosts = [],
        public array $testingAllowHosts = [],
        public array $metadataHosts = [],
        public array $metadataIps = [],
        public int $redirectLimit = 3,
        public string $userAgent = 'StatusConnect/1.0',
        public int $connectTimeout = 5,
        public int $totalTimeout = 15,
        public int $responseBodyLimit = 65536,
        public array $profiles = [],
    ) {
    }

    /**
     * @param  array<string, mixed>  $config
     */
    public static function fromArray(array $config): self
    {
        $profiles = [];
        foreach (($config['profiles'] ?? []) as $name => $profileConfig) {
            if (! is_array($profileConfig)) {
                continue;
            }
            $profile = EgressProfile::tryFrom((string) $name);
            if ($profile === null) {
                continue;
            }
            $hosts = array_values(array_filter(array_map(
                static fn ($host): string => strtolower(trim((string) $host)),
                (array) ($profileConfig['allow_hosts'] ?? []),
            )));
            $profiles[$profile->value] = new EgressProfileDefinition(
                profile: $profile,
                allowHosts: $hosts,
                redirectLimit: isset($profileConfig['redirect_limit']) ? (int) $profileConfig['redirect_limit'] : null,
                responseBodyLimit: isset($profileConfig['response_body_limit']) ? (int) $profileConfig['response_body_limit'] : null,
                connectTimeout: isset($profileConfig['connect_timeout']) ? (int) $profileConfig['connect_timeout'] : null,
                totalTimeout: isset($profileConfig['total_timeout']) ? (int) $profileConfig['total_timeout'] : null,
            );
        }

        return new self(
            allowedPorts: $config['allowed_ports'] ?? [80, 443],
            allowHttp: (bool) ($config['allow_http'] ?? false),
            platformAllowHosts: array_map('strtolower', $config['platform_allow_hosts'] ?? []),
            testingAllowHosts: array_map('strtolower', $config['testing_allow_hosts'] ?? []),
            metadataHosts: array_map('strtolower', $config['metadata_hosts'] ?? []),
            metadataIps: $config['metadata_ips'] ?? [],
            redirectLimit: (int) ($config['redirect_limit'] ?? 3),
            userAgent: (string) ($config['user_agent'] ?? 'StatusConnect/1.0'),
            connectTimeout: (int) ($config['connect_timeout'] ?? 5),
            totalTimeout: (int) ($config['total_timeout'] ?? 15),
            responseBodyLimit: (int) ($config['response_body_limit'] ?? 65536),
            profiles: $profiles,
        );
    }

    public function profile(EgressProfile $profile): EgressProfileDefinition
    {
        return $this->profiles[$profile->value] ?? new EgressProfileDefinition($profile);
    }
}
