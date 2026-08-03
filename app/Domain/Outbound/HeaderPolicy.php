<?php

// Ported / mirrored from TaskConnect: app/Domain/Execution/Outbound/HeaderPolicy.php

namespace App\Domain\Outbound;

final class HeaderPolicy
{
    /** @var list<string> */
    private const FORBIDDEN_HEADERS = [
        'host',
        'content-length',
        'transfer-encoding',
        'connection',
        'proxy-authorization',
        'proxy-connection',
        'proxy-authenticate',
    ];

    /**
     * @param  array<string, string|list<string>>  $headers
     */
    public function validate(array $headers): void
    {
        foreach ($headers as $name => $value) {
            $normalized = strtolower(trim((string) $name));

            if ($normalized === '') {
                throw new OutboundPolicyViolation('invalid_header', 'Header names must not be empty.');
            }

            if (str_starts_with($normalized, 'proxy-')) {
                throw new OutboundPolicyViolation(
                    'forbidden_header',
                    sprintf('Header "%s" is not allowed.', $name),
                );
            }

            if (in_array($normalized, self::FORBIDDEN_HEADERS, true)) {
                throw new OutboundPolicyViolation(
                    'forbidden_header',
                    sprintf('Header "%s" is not allowed.', $name),
                );
            }
        }
    }

    /**
     * @param  array<string, string|list<string>>  $headers
     * @return array<string, string>
     */
    public function sanitize(array $headers, string $userAgent): array
    {
        $this->validate($headers);

        $sanitized = [];

        foreach ($headers as $name => $value) {
            if (is_array($value)) {
                $value = implode(', ', $value);
            }

            $sanitized[(string) $name] = (string) $value;
        }

        $sanitized['User-Agent'] = $userAgent;

        return $sanitized;
    }
}
