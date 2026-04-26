<?php

declare(strict_types=1);

namespace FloopFloop\Tests;

use FloopFloop\Client;
use FloopFloop\Error;
use PHPUnit\Framework\TestCase;

final class StreamTest extends TestCase
{
    private function makeClient(): array
    {
        $fake = new FakeHttpClient();
        $client = new Client(apiKey: 'flp_test', baseUrl: 'https://api.test.local', httpClient: $fake);
        return [$client, $fake];
    }

    public function test_yields_unique_events_and_returns_live(): void
    {
        [$client, $fake] = $this->makeClient();
        $fake->enqueue(200, '{"data":{"step":1,"totalSteps":3,"status":"queued","message":""}}');
        $fake->enqueue(200, '{"data":{"step":2,"totalSteps":3,"status":"generating","progress":0.3,"message":""}}');
        // Duplicate — should be deduped.
        $fake->enqueue(200, '{"data":{"step":2,"totalSteps":3,"status":"generating","progress":0.3,"message":""}}');
        $fake->enqueue(200, '{"data":{"step":3,"totalSteps":3,"status":"live","message":""}}');

        $seen = [];
        $final = $client->projects()->stream(
            'p_1',
            function (array $ev) use (&$seen): void {
                $seen[] = $ev['status'];
            },
            interval: 0.005,
            maxWait: 5.0,
        );
        $this->assertSame(['queued', 'generating', 'live'], $seen);
        $this->assertSame('live', $final['status']);
    }

    public function test_failed_throws_typed_error(): void
    {
        [$client, $fake] = $this->makeClient();
        $fake->enqueue(200, '{"data":{"step":1,"totalSteps":1,"status":"failed","message":"typecheck failed"}}');
        try {
            $client->projects()->stream('p_1', fn () => null, interval: 0.005, maxWait: 5.0);
            $this->fail('should have thrown');
        } catch (Error $e) {
            $this->assertSame('BUILD_FAILED', $e->code);
            $this->assertSame('typecheck failed', $e->getMessage());
        }
    }

    public function test_cancelled_throws_typed_error(): void
    {
        [$client, $fake] = $this->makeClient();
        $fake->enqueue(200, '{"data":{"step":1,"totalSteps":1,"status":"cancelled","message":""}}');
        try {
            $client->projects()->stream('p_1', fn () => null, interval: 0.005, maxWait: 5.0);
            $this->fail('should have thrown');
        } catch (Error $e) {
            $this->assertSame('BUILD_CANCELLED', $e->code);
        }
    }

    public function test_archived_terminates_cleanly_like_live(): void
    {
        // Pre-fix this looped until max_wait because the case-statement
        // only matched live / failed / cancelled. Node / Python / Swift
        // / Kotlin treat archived as a non-error terminal; PHP now
        // matches.
        [$client, $fake] = $this->makeClient();
        $fake->enqueue(200, '{"data":{"step":3,"totalSteps":3,"status":"archived","message":""}}');
        $event = $client->projects()->stream('p_1', fn () => null, interval: 0.005, maxWait: 5.0);
        $this->assertSame('archived', $event['status']);
    }

    public function test_max_wait_exceeded_throws_timeout(): void
    {
        [$client, $fake] = $this->makeClient();
        // Keep returning "queued" forever.
        for ($i = 0; $i < 50; $i++) {
            $fake->enqueue(200, '{"data":{"step":1,"totalSteps":3,"status":"queued","message":""}}');
        }
        try {
            $client->projects()->stream('p_1', fn () => null, interval: 0.005, maxWait: 0.05);
            $this->fail('should have thrown');
        } catch (Error $e) {
            $this->assertSame('TIMEOUT', $e->code);
        }
    }

    public function test_wait_for_live_returns_hydrated_project(): void
    {
        [$client, $fake] = $this->makeClient();
        $fake->enqueue(200, '{"data":{"step":1,"totalSteps":2,"status":"queued","message":""}}');
        $fake->enqueue(200, '{"data":{"step":2,"totalSteps":2,"status":"live","message":""}}');
        // Projects#get does a list() lookup after live.
        $fake->enqueue(200, '{"data":[{"id":"p_1","subdomain":"x","status":"live","url":"https://x.floop.tech"}]}');

        $live = $client->projects()->waitForLive('p_1', interval: 0.005, maxWait: 5.0);
        $this->assertSame('https://x.floop.tech', $live['url']);
    }
}
