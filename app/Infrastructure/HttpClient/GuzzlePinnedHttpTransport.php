<?php

// Ported / mirrored from TaskConnect: app/Infrastructure/HttpClient/GuzzlePinnedHttpTransport.php

namespace App\Infrastructure\HttpClient;

use App\Domain\Outbound\OutboundPolicy;
use App\Domain\Outbound\OutboundPolicyViolation;
use App\Domain\Outbound\ValidatedEndpoint;
use GuzzleHttp\Client;
use GuzzleHttp\Exception\ConnectException;
use GuzzleHttp\Exception\RequestException;
use GuzzleHttp\Exception\TransferException;
use Psr\Http\Message\ResponseInterface;

final class GuzzlePinnedHttpTransport implements PinnedHttpTransport
{
    public function __construct(
        private readonly OutboundPolicy $policy,
        private readonly ?Client $client = null,
    ) {
    }

    public function send(PinnedHttpRequest $request): PinnedHttpResponse
    {
        $config = $this->policy->config();
        $profileLimits = $config->profile($request->egressProfile);
        $connectTimeout = $request->connectTimeout ?? $profileLimits->connectTimeout ?? $config->connectTimeout;
        $totalTimeout = $request->totalTimeout ?? $profileLimits->totalTimeout ?? $config->totalTimeout;
        $bodyLimit = $request->responseBodyLimit ?? $profileLimits->responseBodyLimit ?? $config->responseBodyLimit;
        $redirectLimit = $profileLimits->redirectLimit ?? $config->redirectLimit;
        $client = $this->client ?? new Client([
            'http_errors' => false,
            'allow_redirects' => false,
            'connect_timeout' => $connectTimeout,
            'timeout' => $totalTimeout,
            'verify' => $request->verifyTls,
        ]);

        $currentEndpoint = $request->endpoint;
        $currentUrl = $currentEndpoint->url;
        $redirectCount = 0;
        $headers = $this->policy->sanitizeHeaders($request->headers);

        while (true) {
            try {
                $response = $client->request(
                    $request->method,
                    $currentUrl,
                    [
                        'headers' => $headers,
                        'body' => $request->body,
                        'connect_timeout' => $connectTimeout,
                        'timeout' => $totalTimeout,
                        'verify' => $request->verifyTls,
                        'curl' => [
                            CURLOPT_RESOLVE => [
                                sprintf('%s:%d:%s', $currentEndpoint->host, $currentEndpoint->port, $currentEndpoint->pinnedIp),
                            ],
                        ],
                    ],
                );
            } catch (ConnectException|TransferException $exception) {
                return new PinnedHttpResponse(
                    statusCode: 0,
                    headers: [],
                    bodyTruncated: '',
                    bodySha256: hash('sha256', ''),
                    bodyTruncatedFlag: false,
                    finalUrl: $currentUrl,
                    redirectCount: $redirectCount,
                    transportError: $exception->getMessage(),
                );
            }

            if ($request->followRedirects && $this->isRedirect($response->getStatusCode())) {
                if ($redirectCount >= $redirectLimit) {
                    throw new OutboundPolicyViolation(
                        'redirect_limit_exceeded',
                        'Redirect limit exceeded.',
                    );
                }

                $location = $this->resolveRedirectLocation($currentUrl, $response);

                if ($location === null) {
                    throw new OutboundPolicyViolation(
                        'invalid_redirect',
                        'Redirect response is missing a Location header.',
                    );
                }

                $validated = $this->policy->validateUrl(
                    $location,
                    $request->additionalAllowHosts,
                    $request->egressProfile,
                );
                $currentEndpoint = $this->selectPinnedEndpoint($validated);
                $currentUrl = $currentEndpoint->url;
                $redirectCount++;

                continue;
            }

            return $this->buildResponse($response, $currentUrl, $redirectCount, $bodyLimit);
        }
    }

    private function isRedirect(int $statusCode): bool
    {
        return in_array($statusCode, [301, 302, 303, 307, 308], true);
    }

    private function resolveRedirectLocation(string $currentUrl, ResponseInterface $response): ?string
    {
        $location = $response->getHeaderLine('Location');

        if ($location === '') {
            return null;
        }

        if (parse_url($location, PHP_URL_SCHEME) === null) {
            $base = parse_url($currentUrl);

            if ($base === false || ! isset($base['scheme'], $base['host'])) {
                return null;
            }

            $port = isset($base['port']) ? ':'.$base['port'] : '';
            $prefix = sprintf('%s://%s%s', $base['scheme'], $base['host'], $port);

            if (str_starts_with($location, '/')) {
                return $prefix.$location;
            }

            $path = $base['path'] ?? '/';
            $directory = str_contains($path, '/') ? substr($path, 0, (int) strrpos($path, '/') + 1) : '/';

            return $prefix.$directory.$location;
        }

        return $location;
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

    private function buildResponse(
        ResponseInterface $response,
        string $finalUrl,
        int $redirectCount,
        int $bodyLimit,
    ): PinnedHttpResponse {
        $body = (string) $response->getBody();
        $truncatedFlag = strlen($body) > $bodyLimit;

        if ($truncatedFlag) {
            $body = substr($body, 0, $bodyLimit);
        }

        /** @var array<string, list<string>> $headers */
        $headers = $response->getHeaders();

        return new PinnedHttpResponse(
            statusCode: $response->getStatusCode(),
            headers: $headers,
            bodyTruncated: $body,
            bodySha256: hash('sha256', $body),
            bodyTruncatedFlag: $truncatedFlag,
            finalUrl: $finalUrl,
            redirectCount: $redirectCount,
        );
    }
}
