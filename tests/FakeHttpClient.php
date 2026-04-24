<?php

declare(strict_types=1);

namespace FloopFloop\Tests;

use FloopFloop\Error;
use FloopFloop\HttpClient;

/**
 * Test double: every `enqueue(...)` call queues one canned response;
 * each `request(...)` call dequeues the next one in order and records
 * the outbound request for assertions. Raises out-of-band assertions
 * when more calls happen than responses are queued.
 */
final class FakeHttpClient implements HttpClient
{
    /** @var list<array{int, array<string, string>, string}> */
    private array $queue = [];
    /** @var list<array{method: string, url: string, headers: array<string, string>, body: ?string}> */
    public array $requests = [];

    /**
     * @param array<string, string> $headers
     */
    public function enqueue(int $status, string $body = '', array $headers = []): void
    {
        $normalised = [];
        foreach ($headers as $k => $v) {
            $normalised[strtolower($k)] = $v;
        }
        $this->queue[] = [$status, $normalised, $body];
    }

    /**
     * Enqueue a transport-level failure — the next request() throws.
     */
    public function enqueueError(string $code, string $message): void
    {
        $this->queue[] = ['__error__', $code, $message]; // sentinel
    }

    public function request(string $method, string $url, array $headers, ?string $body, float $timeoutSeconds): array
    {
        $this->requests[] = [
            'method' => strtoupper($method),
            'url' => $url,
            'headers' => $headers,
            'body' => $body,
        ];
        if ($this->queue === []) {
            throw new Error('UNKNOWN', "FakeHttpClient: no response queued for {$method} {$url}");
        }
        $next = array_shift($this->queue);
        if ($next[0] === '__error__') {
            /** @var array{string, string, string} $next */
            throw new Error($next[1], $next[2]);
        }
        /** @var array{int, array<string, string>, string} $next */
        return $next;
    }

    public function lastRequest(): array
    {
        return end($this->requests) ?: [];
    }
}
