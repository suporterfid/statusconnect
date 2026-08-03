<?php

namespace App\Infrastructure\HttpClient;

use App\Domain\Outbound\OutboundPolicy;
use App\Domain\Outbound\OutboundPolicyViolation;
use App\Domain\Outbound\ValidatedEndpoint;

final class CurlMultiPinnedProbe implements MultiPinnedHttpProbe
{
    public function __construct(private readonly OutboundPolicy $policy)
    {
    }

    /**
     * @return array<int, mixed>
     */
    public static function optionsFor(PinnedHttpRequest $request): array
    {
        $endpoint = $request->endpoint;

        return [
            CURLOPT_FOLLOWLOCATION => false,
            CURLOPT_RESOLVE => [sprintf('%s:%d:%s', self::formatHost($endpoint->host), $endpoint->port, self::formatIp($endpoint->pinnedIp))],
        ];
    }

    /**
     * @param  list<MultiPinnedHttpRequest>  $requests
     * @return list<MultiPinnedHttpResult>
     */
    public function probe(array $requests): array
    {
        $multi = curl_multi_init();
        $contexts = [];
        $results = [];

        foreach ($requests as $request) {
            $this->addHandle($multi, $contexts, $request, 0);
        }

        do {
            do {
                $status = curl_multi_exec($multi, $running);
            } while ($status === CURLM_CALL_MULTI_PERFORM);

            while (($completed = curl_multi_info_read($multi)) !== false) {
                $handle = $completed['handle'];
                $key = spl_object_id($handle);
                $context = $contexts[$key];
                unset($contexts[$key]);

                $response = $this->responseFor($handle, $context, $completed['result']);
                curl_multi_remove_handle($multi, $handle);
                curl_close($handle);

                if ($context['request']->followRedirects && $this->isRedirect($response->statusCode)) {
                    $redirectResult = $this->addRedirectHandle($multi, $contexts, $context, $response);
                    if ($redirectResult === null) {
                        continue;
                    }
                    $response = $redirectResult;
                }

                $results[] = new MultiPinnedHttpResult(
                    monitorId: $context['monitorId'],
                    response: $response,
                    latencyMs: (int) round((hrtime(true) - $context['startedAt']) / 1_000_000),
                );
            }

            if ($running > 0) {
                curl_multi_select($multi, 1.0);
            }
        } while ($running > 0 || $contexts !== []);

        curl_multi_close($multi);

        return $results;
    }

    /**
     * @param  array<int, array{monitorId: int, request: PinnedHttpRequest, startedAt: int, body: string, bodyTruncated: bool, headers: array<string, list<string>>, redirectCount: int}>  $contexts
     */
    private function addHandle(\CurlMultiHandle $multi, array &$contexts, MultiPinnedHttpRequest $multiRequest, int $redirectCount, ?int $startedAt = null, ?int $deadline = null): void
    {
        $request = $multiRequest->request;
        $config = $this->policy->config();
        $profile = $config->profile($request->egressProfile);
        $connectTimeout = $request->connectTimeout ?? $profile->connectTimeout ?? $config->connectTimeout;
        $totalTimeout = $request->totalTimeout ?? $profile->totalTimeout ?? $config->totalTimeout;
        $bodyLimit = $request->responseBodyLimit ?? $profile->responseBodyLimit ?? $config->responseBodyLimit;
        $startedAt ??= hrtime(true);
        $deadline ??= $startedAt + ($totalTimeout * 1_000_000_000);
        $remainingSeconds = (int) ceil(max(0, $deadline - hrtime(true)) / 1_000_000_000);
        if ($remainingSeconds < 1) {
            return;
        }
        $body = '';
        $bodyTruncated = false;
        $headers = [];
        $handle = curl_init($request->endpoint->url);

        curl_setopt_array($handle, self::optionsFor($request) + [
            CURLOPT_CUSTOMREQUEST => $request->method,
            CURLOPT_HTTPHEADER => $this->formatHeaders($this->policy->sanitizeHeaders($request->headers)),
            CURLOPT_CONNECTTIMEOUT => $connectTimeout,
            CURLOPT_TIMEOUT => min($totalTimeout, $remainingSeconds),
            CURLOPT_SSL_VERIFYPEER => $request->verifyTls,
            CURLOPT_SSL_VERIFYHOST => $request->verifyTls ? 2 : 0,
            CURLOPT_WRITEFUNCTION => static function (\CurlHandle $handle, string $chunk) use (&$body, &$bodyTruncated, $bodyLimit): int {
                $remaining = $bodyLimit - strlen($body);
                if ($remaining > 0) {
                    $body .= substr($chunk, 0, $remaining);
                }
                $bodyTruncated = $bodyTruncated || strlen($chunk) > max(0, $remaining);

                return strlen($chunk);
            },
            CURLOPT_HEADERFUNCTION => static function (\CurlHandle $handle, string $line) use (&$headers): int {
                $separator = strpos($line, ':');
                if ($separator !== false) {
                    $name = trim(substr($line, 0, $separator));
                    $value = trim(substr($line, $separator + 1));
                    if ($name !== '') {
                        $headers[$name] ??= [];
                        $headers[$name][] = $value;
                    }
                }

                return strlen($line);
            },
        ] + ($request->body === null ? [] : [CURLOPT_POSTFIELDS => $request->body]));

        curl_multi_add_handle($multi, $handle);
        $contexts[spl_object_id($handle)] = [
            'monitorId' => $multiRequest->monitorId,
            'request' => $request,
            'startedAt' => $startedAt,
            'deadline' => $deadline,
            'body' => &$body,
            'bodyTruncated' => &$bodyTruncated,
            'headers' => &$headers,
            'redirectCount' => $redirectCount,
        ];
    }

    /**
     * @param  array{monitorId: int, request: PinnedHttpRequest, startedAt: int, body: string, bodyTruncated: bool, headers: array<string, list<string>>, redirectCount: int}  $context
     */
    private function responseFor(\CurlHandle $handle, array $context, int $resultCode): PinnedHttpResponse
    {
        $error = $resultCode === CURLE_OK ? null : curl_error($handle);

        return new PinnedHttpResponse(
            statusCode: (int) curl_getinfo($handle, CURLINFO_RESPONSE_CODE),
            headers: $context['headers'],
            bodyTruncated: $context['body'],
            bodySha256: hash('sha256', $context['body']),
            bodyTruncatedFlag: $context['bodyTruncated'],
            finalUrl: (string) curl_getinfo($handle, CURLINFO_EFFECTIVE_URL),
            redirectCount: $context['redirectCount'],
            transportError: $error === '' ? 'curl_error' : $error,
        );
    }

    /**
     * @param  array<int, array{monitorId: int, request: PinnedHttpRequest, startedAt: int, body: string, bodyTruncated: bool, headers: array<string, list<string>>, redirectCount: int}>  $contexts
     * @param  array{monitorId: int, request: PinnedHttpRequest, startedAt: int, body: string, bodyTruncated: bool, headers: array<string, list<string>>, redirectCount: int}  $context
     */
    private function addRedirectHandle(\CurlMultiHandle $multi, array &$contexts, array $context, PinnedHttpResponse $response): ?PinnedHttpResponse
    {
        $config = $this->policy->config();
        $profile = $config->profile($context['request']->egressProfile);
        $redirectLimit = $profile->redirectLimit ?? $config->redirectLimit;
        $location = $this->redirectLocation($context['request']->endpoint->url, $response);

        if ($context['redirectCount'] >= $redirectLimit || $location === null) {
            return $response;
        }
        try {
            $endpoint = $this->selectPinnedEndpoint($this->policy->validateUrl($location, $context['request']->additionalAllowHosts, $context['request']->egressProfile));
        } catch (OutboundPolicyViolation $violation) {
            return new PinnedHttpResponse(0, [], '', hash('sha256', ''), false, $location, $context['redirectCount'], 'blocked:'.$violation->reasonCode);
        }
        $remainingSeconds = (int) ceil(max(0, $context['deadline'] - hrtime(true)) / 1_000_000_000);
        if ($remainingSeconds < 1) {
            return new PinnedHttpResponse(0, [], '', hash('sha256', ''), false, $location, $context['redirectCount'], 'curl_timeout');
        }
        $request = new PinnedHttpRequest(
            method: $context['request']->method,
            endpoint: $endpoint,
            headers: $context['request']->headers,
            body: $context['request']->body,
            verifyTls: $context['request']->verifyTls,
            followRedirects: $context['request']->followRedirects,
            connectTimeout: $context['request']->connectTimeout,
            totalTimeout: min($context['request']->totalTimeout ?? $remainingSeconds, $remainingSeconds),
            responseBodyLimit: $context['request']->responseBodyLimit,
            additionalAllowHosts: $context['request']->additionalAllowHosts,
            egressProfile: $context['request']->egressProfile,
        );
        $this->addHandle($multi, $contexts, new MultiPinnedHttpRequest($context['monitorId'], $request), $context['redirectCount'] + 1, $context['startedAt'], $context['deadline']);

        return null;
    }

    private function isRedirect(int $statusCode): bool
    {
        return in_array($statusCode, [301, 302, 303, 307, 308], true);
    }

    private function redirectLocation(string $currentUrl, PinnedHttpResponse $response): ?string
    {
        $location = $response->headers['Location'][0] ?? $response->headers['location'][0] ?? null;
        if ($location === null || $location === '') {
            return null;
        }
        if (parse_url($location, PHP_URL_SCHEME) !== null) {
            return $location;
        }
        $base = parse_url($currentUrl);
        if ($base === false || ! isset($base['scheme'], $base['host'])) {
            return null;
        }
        $prefix = sprintf('%s://%s%s', $base['scheme'], $base['host'], isset($base['port']) ? ':'.$base['port'] : '');

        if (str_starts_with($location, '/')) {
            return $prefix.$location;
        }
        $path = $base['path'] ?? '/';
        $directory = str_contains($path, '/') ? substr($path, 0, (int) strrpos($path, '/') + 1) : '/';

        return $prefix.$directory.$location;
    }

    private function selectPinnedEndpoint(ValidatedEndpoint $validated): ValidatedEndpoint
    {
        return new ValidatedEndpoint(
            url: $validated->url,
            scheme: $validated->scheme,
            host: $validated->host,
            port: $validated->port,
            pinnedIp: $validated->resolvedIps[0],
            resolvedIps: $validated->resolvedIps,
            hostAllowlisted: $validated->hostAllowlisted,
        );
    }

    /**
     * @param  array<string, string|list<string>>  $headers
     * @return list<string>
     */
    private function formatHeaders(array $headers): array
    {
        $formatted = [];
        foreach ($headers as $name => $values) {
            foreach ((array) $values as $value) {
                $formatted[] = sprintf('%s: %s', $name, $value);
            }
        }

        return $formatted;
    }

    private static function formatIp(string $ip): string
    {
        return str_contains($ip, ':') ? "[{$ip}]" : $ip;
    }

    private static function formatHost(string $host): string
    {
        return str_contains($host, ':') ? "[{$host}]" : $host;
    }
}
