<?php

declare(strict_types=1);

namespace FloopFloop;

/**
 * Main entry point. Construct once with the api_key and reuse — cheap,
 * thread-safe in typical PHP-FPM worker setups since each request goes
 * through a fresh curl handle.
 *
 *     $client = new \FloopFloop\Client(apiKey: $_ENV['FLOOP_API_KEY']);
 *     $created = $client->projects()->create(prompt: 'a cat cafe landing page');
 *     $live    = $client->projects()->waitForLive($created['project']['id']);
 *     echo "Live at: {$live['url']}\n";
 */
final class Client
{
    public const VERSION = '0.1.0-alpha.3';
    private const DEFAULT_BASE_URL = 'https://www.floopfloop.com';
    private const DEFAULT_TIMEOUT = 30.0;

    private readonly string $apiKey;
    public readonly string $baseUrl;
    private readonly float $timeout;
    private readonly string $userAgent;
    private readonly HttpClient $http;

    // Resource accessors memoised on first call.
    private ?Projects $projects = null;
    private ?Subdomains $subdomains = null;
    private ?Secrets $secrets = null;
    private ?Library $library = null;
    private ?Usage $usage = null;
    private ?ApiKeys $apiKeys = null;
    private ?Uploads $uploads = null;
    private ?User $user = null;

    public function __construct(
        string $apiKey,
        string $baseUrl = self::DEFAULT_BASE_URL,
        float $timeout = self::DEFAULT_TIMEOUT,
        ?string $userAgentSuffix = null,
        ?HttpClient $httpClient = null,
    ) {
        if ($apiKey === '') {
            throw new \InvalidArgumentException('api_key is required');
        }
        $this->apiKey = $apiKey;
        $this->baseUrl = rtrim($baseUrl, '/');
        $this->timeout = $timeout;
        $ua = 'floopfloop-php-sdk/' . self::VERSION;
        if ($userAgentSuffix !== null && $userAgentSuffix !== '') {
            $ua .= ' ' . $userAgentSuffix;
        }
        $this->userAgent = $ua;
        $this->http = $httpClient ?? new StreamClient();
    }

    public function projects(): Projects
    {
        return $this->projects ??= new Projects($this);
    }
    public function subdomains(): Subdomains
    {
        return $this->subdomains ??= new Subdomains($this);
    }
    public function secrets(): Secrets
    {
        return $this->secrets ??= new Secrets($this);
    }
    public function library(): Library
    {
        return $this->library ??= new Library($this);
    }
    public function usage(): Usage
    {
        return $this->usage ??= new Usage($this);
    }
    public function apiKeys(): ApiKeys
    {
        return $this->apiKeys ??= new ApiKeys($this);
    }
    public function uploads(): Uploads
    {
        return $this->uploads ??= new Uploads($this);
    }
    public function user(): User
    {
        return $this->user ??= new User($this);
    }

    // ── Internal transport ──────────────────────────────────────────

    /**
     * Performs the HTTP request, unwraps the `{data: ...}` envelope,
     * and throws `FloopFloop\Error` on non-2xx.
     *
     * @internal  Resource classes call this; user code should not.
     * @param array<string, mixed>|null $body  encoded as JSON when not null.
     * @param array<string, scalar>|null $query
     * @return mixed  the inner envelope data (array, list, scalar, or null for empty)
     */
    public function request(string $method, string $path, ?array $body = null, ?array $query = null): mixed
    {
        $url = $this->baseUrl . $path;
        if ($query !== null && $query !== []) {
            $url .= '?' . http_build_query($query);
        }

        $headers = [
            'Authorization' => 'Bearer ' . $this->apiKey,
            'User-Agent' => $this->userAgent,
            'Accept' => 'application/json',
        ];
        $rawBody = null;
        if ($body !== null) {
            $headers['Content-Type'] = 'application/json';
            $rawBody = json_encode($body, JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
        }

        [$status, $responseHeaders, $responseBody] = $this->http->request($method, $url, $headers, $rawBody, $this->timeout);

        $requestId = $responseHeaders['x-request-id'] ?? null;

        if ($status >= 400) {
            throw $this->parseErrorEnvelope($responseBody, $status, $requestId, $responseHeaders['retry-after'] ?? null);
        }

        return $this->parseSuccess($responseBody);
    }

    /**
     * Raw PUT used by Uploads' two-step flow — no bearer auth (the
     * presigned URL carries its own signature), no envelope parsing
     * (S3 returns empty on success, XML on error).
     *
     * @internal
     */
    public function rawPut(string $url, string $body, string $contentType): void
    {
        [$status, , $respBody] = $this->http->request(
            'PUT',
            $url,
            ['Content-Type' => $contentType, 'Content-Length' => (string) strlen($body)],
            $body,
            $this->timeout,
        );
        if ($status >= 400) {
            $snippet = substr($respBody, 0, 512);
            throw new Error('UNKNOWN', "uploads: S3 rejected PUT ({$status}): {$snippet}", $status);
        }
    }

    private function parseSuccess(string $raw): mixed
    {
        if ($raw === '') {
            return null;
        }
        try {
            $decoded = json_decode($raw, true, flags: JSON_THROW_ON_ERROR);
        } catch (\JsonException) {
            return $raw;
        }
        if (is_array($decoded) && array_key_exists('data', $decoded)) {
            return $decoded['data'];
        }
        return $decoded;
    }

    private function parseErrorEnvelope(string $raw, int $status, ?string $requestId, ?string $retryAfterHeader): Error
    {
        $code = self::defaultCodeForStatus($status);
        $message = "request failed ({$status})";
        if ($raw !== '') {
            try {
                $decoded = json_decode($raw, true, flags: JSON_THROW_ON_ERROR);
                if (is_array($decoded) && isset($decoded['error']) && is_array($decoded['error'])) {
                    $code = (string) ($decoded['error']['code'] ?? $code);
                    $message = (string) ($decoded['error']['message'] ?? $message);
                }
            } catch (\JsonException) {
                // non-JSON body — fall through to defaults
            }
        }
        return new Error(
            code: $code,
            message: $message,
            status: $status,
            requestId: $requestId,
            retryAfter: Error::parseRetryAfter($retryAfterHeader),
        );
    }

    private static function defaultCodeForStatus(int $status): string
    {
        return match (true) {
            $status === 401 => 'UNAUTHORIZED',
            $status === 403 => 'FORBIDDEN',
            $status === 404 => 'NOT_FOUND',
            $status === 409 => 'CONFLICT',
            $status === 422 => 'VALIDATION_ERROR',
            $status === 429 => 'RATE_LIMITED',
            $status === 503 => 'SERVICE_UNAVAILABLE',
            $status >= 500 => 'SERVER_ERROR',
            default => 'UNKNOWN',
        };
    }
}
