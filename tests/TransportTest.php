<?php

declare(strict_types=1);

namespace FloopFloop\Tests;

use FloopFloop\Client;
use FloopFloop\Error;
use PHPUnit\Framework\TestCase;

final class TransportTest extends TestCase
{
    private function makeClient(?FakeHttpClient $http = null): array
    {
        $fake = $http ?? new FakeHttpClient();
        $client = new Client(apiKey: 'flp_test', baseUrl: 'https://api.test.local', httpClient: $fake);
        return [$client, $fake];
    }

    public function test_bearer_and_data_envelope_unwrap(): void
    {
        [$client, $fake] = $this->makeClient();
        $fake->enqueue(200, '{"data":{"id":"u_1","email":"p@x"}}');
        $me = $client->user()->me();
        $this->assertSame('u_1', $me['id']);
        $this->assertSame('p@x', $me['email']);

        $req = $fake->requests[0];
        $this->assertSame('Bearer flp_test', $req['headers']['Authorization']);
        $this->assertStringStartsWith('floopfloop-php-sdk/', $req['headers']['User-Agent']);
    }

    public function test_user_agent_suffix_appends(): void
    {
        $fake = new FakeHttpClient();
        $client = new Client(apiKey: 'flp_test', baseUrl: 'https://api.test.local', userAgentSuffix: 'myapp/1.2', httpClient: $fake);
        $fake->enqueue(200, '{"data":{}}');
        $client->user()->me();
        $this->assertStringEndsWith(' myapp/1.2', $fake->requests[0]['headers']['User-Agent']);
    }

    public function test_error_envelope_becomes_typed_error(): void
    {
        [$client, $fake] = $this->makeClient();
        $fake->enqueue(
            404,
            '{"error":{"code":"NOT_FOUND","message":"no such user"}}',
            ['x-request-id' => 'req_1'],
        );
        try {
            $client->user()->me();
            $this->fail('should have thrown');
        } catch (Error $e) {
            $this->assertSame('NOT_FOUND', $e->code);
            $this->assertSame(404, $e->status);
            $this->assertSame('no such user', $e->getMessage());
            $this->assertSame('req_1', $e->requestId);
        }
    }

    public function test_retry_after_delta_seconds(): void
    {
        [$client, $fake] = $this->makeClient();
        $fake->enqueue(429, '{"error":{"code":"RATE_LIMITED","message":"slow"}}', ['Retry-After' => '5']);
        try {
            $client->user()->me();
            $this->fail('should have thrown');
        } catch (Error $e) {
            $this->assertSame('RATE_LIMITED', $e->code);
            $this->assertEqualsWithDelta(5.0, $e->retryAfter ?? -1, 0.01);
        }
    }

    public function test_retry_after_http_date_past_is_zero(): void
    {
        $this->assertSame(0.0, Error::parseRetryAfter('Wed, 21 Oct 2015 07:28:00 GMT'));
        $this->assertNull(Error::parseRetryAfter(''));
        $this->assertNull(Error::parseRetryAfter(null));
        $this->assertNull(Error::parseRetryAfter('garbage'));
        $this->assertSame(3.0, Error::parseRetryAfter('3'));
        $this->assertSame(1.5, Error::parseRetryAfter('1.5'));
        $this->assertNull(Error::parseRetryAfter('-1'));
    }

    public function test_unknown_server_code_passes_through(): void
    {
        [$client, $fake] = $this->makeClient();
        $fake->enqueue(418, '{"error":{"code":"TEAPOT_MODE","message":"short and stout"}}');
        try {
            $client->user()->me();
            $this->fail('should have thrown');
        } catch (Error $e) {
            $this->assertSame('TEAPOT_MODE', $e->code);
        }
    }

    public function test_non_json_5xx_falls_back_to_server_error(): void
    {
        [$client, $fake] = $this->makeClient();
        $fake->enqueue(500, 'upstream crashed');
        try {
            $client->user()->me();
            $this->fail('should have thrown');
        } catch (Error $e) {
            $this->assertSame('SERVER_ERROR', $e->code);
            $this->assertSame(500, $e->status);
        }
    }

    public function test_missing_api_key_rejected(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        new Client(apiKey: '');
    }

    public function test_base_url_strips_trailing_slash(): void
    {
        $client = new Client(apiKey: 'flp_test', baseUrl: 'https://x.example.com/');
        $this->assertSame('https://x.example.com', $client->baseUrl);
    }

    public function test_error_to_string_format(): void
    {
        $err = new Error('RATE_LIMITED', 'slow', status: 429, requestId: 'r1');
        $this->assertSame('floop: [RATE_LIMITED 429] slow (request r1)', (string) $err);

        $err2 = new Error('NETWORK_ERROR', 'boom', status: 0);
        $this->assertSame('floop: [NETWORK_ERROR] boom', (string) $err2);
    }
}
