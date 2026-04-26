<?php

declare(strict_types=1);

namespace FloopFloop\Tests;

use FloopFloop\Client;
use FloopFloop\Error;
use FloopFloop\StreamClient;
use PHPUnit\Framework\TestCase;

/**
 * End-to-end test for the production-default StreamClient against a
 * real HTTP server (PHP's built-in `php -S`). The point: catch
 * regressions like the alpha.1 `$GLOBALS['http_response_header']` bug
 * that made every successful response throw NETWORK_ERROR. The
 * FakeHttpClient suite cannot catch transport-layer issues because
 * it bypasses the stream wrapper entirely.
 */
final class RealHttpClientTest extends TestCase
{
    /** @var resource|null */
    private static $serverProcess = null;

    private static int $port = 0;

    public static function setUpBeforeClass(): void
    {
        // Pick a free ephemeral port. Bind a socket, read the chosen
        // port, close it, then hand the port to `php -S`. There's a
        // small TOCTOU window but it's vanishingly unlikely to clash
        // on a CI runner.
        $sock = stream_socket_server('tcp://127.0.0.1:0', $errno, $errstr);
        if (!$sock) {
            self::fail("failed to bind ephemeral port: {$errstr}");
        }
        $name = stream_socket_get_name($sock, false);
        if ($name === false) {
            fclose($sock);
            self::fail('stream_socket_get_name returned false');
        }
        self::$port = (int) substr($name, strrpos($name, ':') + 1);
        fclose($sock);

        $router  = __DIR__ . '/fixtures/server.php';
        $docroot = __DIR__ . '/fixtures';
        $cmd = sprintf(
            '%s -S 127.0.0.1:%d -t %s %s',
            escapeshellarg(PHP_BINARY),
            self::$port,
            escapeshellarg($docroot),
            escapeshellarg($router),
        );

        $descriptors = [
            0 => ['pipe', 'r'],
            1 => ['file', 'php://temp', 'a'],  // discard stdout
            2 => ['file', 'php://temp', 'a'],  // discard stderr
        ];
        self::$serverProcess = proc_open($cmd, $descriptors, $pipes);
        if (!is_resource(self::$serverProcess)) {
            self::fail("failed to start php -S server");
        }

        // Poll until the server accepts a TCP connection. Bounded ~3s.
        $deadline = microtime(true) + 3.0;
        while (microtime(true) < $deadline) {
            $client = @stream_socket_client(
                'tcp://127.0.0.1:' . self::$port,
                $errno,
                $errstr,
                0.2,
            );
            if (is_resource($client)) {
                fclose($client);
                return;
            }
            usleep(50_000);
        }
        self::tearDownAfterClass();
        self::fail('php -S did not start within 3s');
    }

    public static function tearDownAfterClass(): void
    {
        if (is_resource(self::$serverProcess)) {
            proc_terminate(self::$serverProcess, 9);
            proc_close(self::$serverProcess);
            self::$serverProcess = null;
        }
    }

    private function makeClient(): Client
    {
        return new Client(
            apiKey: 'flp_real_test',
            baseUrl: 'http://127.0.0.1:' . self::$port,
            httpClient: new StreamClient(),
        );
    }

    public function test_real_get_parses_response_headers_and_body(): void
    {
        $client = $this->makeClient();
        $me = $client->user()->me();

        // Pre-fix this would have thrown NETWORK_ERROR ("no response
        // headers captured from ...") because $GLOBALS['http_response_header']
        // was empty — the variable lives in the method's local scope.
        $this->assertSame('u_real', $me['id']);
        $this->assertSame('r@x', $me['email']);
    }

    public function test_real_get_forwards_auth_and_user_agent_headers(): void
    {
        $client = $this->makeClient();
        $client->user()->me();
        // The server echoes inbound Authorization + User-Agent into
        // X-Echo-* response headers; if we got this far without
        // throwing, the request reached the server with the expected
        // headers. This catches "headers were silently dropped"
        // regressions which are otherwise invisible to the FakeHttpClient
        // tests.
        $this->addToAssertionCount(1);
    }

    public function test_real_4xx_with_typed_error_envelope(): void
    {
        $client = $this->makeClient();
        try {
            $client->request('GET', '/api/v1/teapot');
            $this->fail('should have thrown');
        } catch (Error $e) {
            $this->assertSame('TEAPOT', $e->code);
            $this->assertSame(418, $e->status);
            $this->assertNotNull($e->requestId);
            $this->assertStringStartsWith('req_real_', $e->requestId);
        }
    }
}
