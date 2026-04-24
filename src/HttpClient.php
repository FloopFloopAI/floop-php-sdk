<?php

declare(strict_types=1);

namespace FloopFloop;

/**
 * Minimal HTTP-client interface the SDK uses. The default
 * implementation (StreamClient) wraps PHP's stdlib stream layer
 * (`file_get_contents` + `stream_context_create`); tests pass a stub
 * that replays canned responses without hitting the network.
 *
 * Return shape: [int $status, array<string,string> $headers, string $body].
 * `$headers` keys are lower-cased. Transport-level failures (connection
 * refused, timeout) throw FloopFloop\Error directly; let the client
 * interpret status >= 400.
 */
interface HttpClient
{
    /**
     * @param array<string, string> $headers  case-insensitive, string values
     * @return array{int, array<string, string>, string}
     */
    public function request(string $method, string $url, array $headers, ?string $body, float $timeoutSeconds): array;
}

/**
 * Default stdlib implementation. Uses the PHP stream layer so it works
 * on any install — no extensions required beyond ext-json. Stateless.
 *
 * Note: PHP's stream layer emits a warning AND returns false on non-2xx
 * unless `ignore_errors => true` is set — we need the body to parse
 * error envelopes, so we enable it and classify status via headers.
 */
final class StreamClient implements HttpClient
{
    public function request(string $method, string $url, array $headers, ?string $body, float $timeoutSeconds): array
    {
        $headerLines = [];
        foreach ($headers as $k => $v) {
            $headerLines[] = "{$k}: {$v}";
        }

        $context = stream_context_create([
            'http' => [
                'method' => strtoupper($method),
                'header' => implode("\r\n", $headerLines),
                'content' => $body ?? '',
                'timeout' => $timeoutSeconds,
                'ignore_errors' => true, // get the body on 4xx/5xx
                'follow_location' => 1,
                'max_redirects' => 5,
                'protocol_version' => 1.1,
            ],
        ]);

        // file_get_contents + stream_context populates $http_response_header
        // in the calling scope. Any failure (DNS, refused, timeout)
        // triggers a PHP warning — we upgrade that to a FloopFloop\Error.
        $prevHandler = set_error_handler(function (int $errno, string $errstr) {
            throw new Error('NETWORK_ERROR', "stream open failed: {$errstr}");
        });
        try {
            /** @var string|false $responseBody */
            $responseBody = @file_get_contents($url, false, $context);
        } finally {
            set_error_handler($prevHandler);
        }

        if ($responseBody === false) {
            $last = error_get_last();
            $msg = $last['message'] ?? 'unknown error';
            if (str_contains($msg, 'timed out') || str_contains($msg, 'timeout')) {
                throw new Error('TIMEOUT', "request timed out after {$timeoutSeconds}s");
            }
            throw new Error('NETWORK_ERROR', "could not reach {$url}: {$msg}");
        }

        /** @var list<string> $rawHeaders */
        $rawHeaders = $GLOBALS['http_response_header'] ?? [];
        if ($rawHeaders === []) {
            throw new Error('NETWORK_ERROR', "no response headers captured from {$url}");
        }

        [$status, $parsedHeaders] = self::parseHeaders($rawHeaders);
        return [$status, $parsedHeaders, $responseBody];
    }

    /**
     * @param list<string> $rawHeaders
     * @return array{int, array<string, string>}
     */
    private static function parseHeaders(array $rawHeaders): array
    {
        $status = 0;
        $headers = [];
        foreach ($rawHeaders as $line) {
            if (preg_match('#^HTTP/\S+\s+(\d+)#', $line, $m)) {
                // On redirect-follow we get multiple HTTP status lines;
                // keep only the FINAL one.
                $status = (int) $m[1];
                $headers = [];
                continue;
            }
            $parts = explode(':', $line, 2);
            if (count($parts) === 2) {
                $headers[strtolower(trim($parts[0]))] = trim($parts[1]);
            }
        }
        return [$status, $headers];
    }
}
