<?php

// Ported / mirrored from TaskConnect: app/Domain/Secrets/SecretRedactor.php

namespace App\Domain\Secrets;

final class SecretRedactor
{
    private const REDACTED = '[REDACTED]';

    /** @var list<string> */
    private const SENSITIVE_HEADER_NAMES = [
        'authorization',
        'proxy-authorization',
        'x-api-key',
        'api-key',
        'x-auth-token',
    ];

    /** @var list<string> */
    private const SENSITIVE_QUERY_PARAMS = [
        'token',
        'access_token',
        'api_key',
        'apikey',
        'key',
        'secret',
        'password',
        'auth',
    ];

    /**
     * Redact secrets from arbitrary text/body excerpt.
     */
    public function redactString(string $input, array $extraSecrets = []): string
    {
        if ($input === '') {
            return '';
        }

        $redacted = $input;

        // Redact Authorization headers if present in text
        $redacted = preg_replace('/(Authorization:\s*)(Bearer|Basic)\s+[^\s\r\n]+/i', '$1$2 [REDACTED]', $redacted) ?? $redacted;

        // Redact custom extra secrets
        foreach ($extraSecrets as $secret) {
            $secret = trim((string) $secret);
            if ($secret !== '' && strlen($secret) >= 4) {
                $redacted = str_replace($secret, self::REDACTED, $redacted);
            }
        }

        return $redacted;
    }

    /**
     * @param  array<string, mixed>|list<mixed>  $data
     * @param  list<string>  $extraSensitiveKeys
     * @return array<string, mixed>|list<mixed>
     */
    public function redact(mixed $data, array $extraSensitiveKeys = []): mixed
    {
        if (! is_array($data)) {
            return $data;
        }

        $isList = array_is_list($data);
        $redacted = [];

        foreach ($data as $key => $value) {
            if (is_int($key)) {
                $redacted[$key] = is_array($value)
                    ? $this->redact($value, $extraSensitiveKeys)
                    : $value;

                continue;
            }

            $normalizedKey = strtolower((string) $key);

            if ($normalizedKey === 'authorization' && is_string($value)) {
                $redacted[$key] = $this->redactAuthorizationHeader($value);

                continue;
            }

            if ($this->shouldRedactKey($normalizedKey, $extraSensitiveKeys)) {
                $redacted[$key] = self::REDACTED;

                continue;
            }

            if (is_array($value)) {
                $redacted[$key] = $this->redact($value, $extraSensitiveKeys);

                continue;
            }

            if (is_string($value) && $this->looksLikeBasicAuthInUrl($normalizedKey, $value)) {
                $redacted[$key] = self::REDACTED;

                continue;
            }

            $redacted[$key] = $value;
        }

        return $isList ? array_values($redacted) : $redacted;
    }

    /**
     * @param  array<string, string>  $headers
     * @param  list<string>  $extraSensitiveKeys
     * @return array<string, string>
     */
    public function redactHeaders(array $headers, array $extraSensitiveKeys = []): array
    {
        $redacted = [];

        foreach ($headers as $name => $value) {
            $normalized = strtolower($name);

            if ($this->shouldRedactKey($normalized, $extraSensitiveKeys) || in_array($normalized, self::SENSITIVE_HEADER_NAMES, true)) {
                if ($normalized === 'authorization') {
                    $redacted[$name] = $this->redactAuthorizationHeader($value);
                } else {
                    $redacted[$name] = self::REDACTED;
                }

                continue;
            }

            $redacted[$name] = $value;
        }

        return $redacted;
    }

    public function redactUrl(string $url, array $secretQueryParams = []): string
    {
        $parts = parse_url($url);

        if ($parts === false) {
            return $url;
        }

        if (isset($parts['user'], $parts['pass'])) {
            $parts['user'] = self::REDACTED;
            $parts['pass'] = self::REDACTED;
        }

        if (isset($parts['query'])) {
            parse_str($parts['query'], $query);
            $parts['query'] = http_build_query($this->redactQueryParams($query, $secretQueryParams));
        }

        return $this->buildUrl($parts);
    }

    /**
     * @param  array<string, string|null>  $query
     * @param  list<string>  $secretQueryParams
     * @return array<string, string|null>
     */
    public function redactQueryParams(array $query, array $secretQueryParams = []): array
    {
        $redacted = [];

        foreach ($query as $key => $value) {
            $normalized = strtolower((string) $key);

            if (in_array($normalized, self::SENSITIVE_QUERY_PARAMS, true)
                || in_array($normalized, array_map('strtolower', $secretQueryParams), true)
                || str_contains($normalized, 'token')
                || str_contains($normalized, 'secret')
                || str_contains($normalized, 'key')
            ) {
                $redacted[$key] = self::REDACTED;

                continue;
            }

            $redacted[$key] = $value;
        }

        return $redacted;
    }

    private function redactAuthorizationHeader(string $value): string
    {
        if (preg_match('/^(Bearer|Basic)\s+(.+)$/i', $value, $matches) === 1) {
            return $matches[1].' '.self::REDACTED;
        }

        return self::REDACTED;
    }

    /**
     * @param  list<string>  $extraSensitiveKeys
     */
    private function shouldRedactKey(string $normalizedKey, array $extraSensitiveKeys): bool
    {
        foreach (array_merge(self::SENSITIVE_HEADER_NAMES, self::SENSITIVE_QUERY_PARAMS, $extraSensitiveKeys) as $sensitive) {
            $sensitive = strtolower($sensitive);

            if ($normalizedKey === $sensitive || str_contains($normalizedKey, $sensitive)) {
                return true;
            }
        }

        return false;
    }

    private function looksLikeBasicAuthInUrl(string $key, string $value): bool
    {
        return in_array($key, ['url', 'request_url', 'request_url_redacted', 'base_url'], true)
            && str_contains($value, '@');
    }

    /**
     * @param  array<string, mixed>  $parts
     */
    private function buildUrl(array $parts): string
    {
        $scheme = isset($parts['scheme']) ? $parts['scheme'].'://' : '';
        $user = $parts['user'] ?? '';
        $pass = isset($parts['pass']) ? ':'.($parts['pass'] ?? '') : '';
        $auth = ($user !== '' || $pass !== '') ? $user.$pass.'@' : '';
        $host = $parts['host'] ?? '';
        $port = isset($parts['port']) ? ':'.$parts['port'] : '';
        $path = $parts['path'] ?? '';
        $query = isset($parts['query']) && $parts['query'] !== '' ? '?'.$parts['query'] : '';
        $fragment = isset($parts['fragment']) ? '#'.$parts['fragment'] : '';

        return $scheme.$auth.$host.$port.$path.$query.$fragment;
    }
}
