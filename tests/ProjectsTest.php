<?php

declare(strict_types=1);

namespace FloopFloop\Tests;

use FloopFloop\Client;
use FloopFloop\Error;
use PHPUnit\Framework\TestCase;

final class ProjectsTest extends TestCase
{
    private function makeClient(): array
    {
        $fake = new FakeHttpClient();
        $client = new Client(apiKey: 'flp_test', baseUrl: 'https://api.test.local', httpClient: $fake);
        return [$client, $fake];
    }

    public function test_create_sends_right_body_and_returns_envelope(): void
    {
        [$client, $fake] = $this->makeClient();
        $fake->enqueue(200, '{"data":{"project":{"id":"p_1","name":"Cat","subdomain":"cat","status":"queued"},"deployment":{"id":"d_1","status":"queued","version":1}}}');

        $out = $client->projects()->create(prompt: 'a cat cafe', botType: 'site');
        $this->assertSame('p_1', $out['project']['id']);
        $this->assertSame(1, $out['deployment']['version']);

        $req = $fake->requests[0];
        $this->assertSame('POST', $req['method']);
        $this->assertStringContainsString('"prompt":"a cat cafe"', (string) $req['body']);
        $this->assertStringContainsString('"botType":"site"', (string) $req['body']);
    }

    public function test_list_with_team_id_encoded_in_query(): void
    {
        [$client, $fake] = $this->makeClient();
        $fake->enqueue(200, '{"data":[{"id":"p_1","subdomain":"x","status":"live"}]}');
        $list = $client->projects()->list(teamId: 't 1');
        $this->assertCount(1, $list);
        $this->assertStringContainsString('teamId=t+1', $fake->requests[0]['url']);
    }

    public function test_get_by_subdomain(): void
    {
        [$client, $fake] = $this->makeClient();
        $fake->enqueue(200, '{"data":[{"id":"p_1","subdomain":"alpha","status":"live"},{"id":"p_2","subdomain":"beta","status":"live"}]}');
        $this->assertSame('p_2', $client->projects()->get('beta')['id']);
    }

    public function test_get_not_found_throws(): void
    {
        [$client, $fake] = $this->makeClient();
        $fake->enqueue(200, '{"data":[]}');
        try {
            $client->projects()->get('ghost');
            $this->fail('should have thrown');
        } catch (Error $e) {
            $this->assertSame('NOT_FOUND', $e->code);
        }
    }

    public function test_status_endpoint_path(): void
    {
        [$client, $fake] = $this->makeClient();
        $fake->enqueue(200, '{"data":{"step":2,"totalSteps":5,"status":"generating","message":"w","progress":0.4}}');
        $ev = $client->projects()->status('p_1');
        $this->assertSame('generating', $ev['status']);
        $this->assertEqualsWithDelta(0.4, $ev['progress'], 0.001);
        $this->assertStringEndsWith('/api/v1/projects/p_1/status', $fake->requests[0]['url']);
    }

    public function test_cancel_and_reactivate_post_paths(): void
    {
        [$client, $fake] = $this->makeClient();
        $fake->enqueue(200, '{"data":{}}');
        $client->projects()->cancel('p_1');
        $fake->enqueue(200, '{"data":{}}');
        $client->projects()->reactivate('p_1');

        $this->assertSame('POST', $fake->requests[0]['method']);
        $this->assertStringEndsWith('/p_1/cancel', $fake->requests[0]['url']);
        $this->assertStringEndsWith('/p_1/reactivate', $fake->requests[1]['url']);
    }

    public function test_refine_queued_variant(): void
    {
        [$client, $fake] = $this->makeClient();
        $fake->enqueue(200, '{"data":{"queued":true,"messageId":"m_1"}}');
        $res = $client->projects()->refine('p_1', message: 'change X');
        $this->assertTrue($res['queued']);
        $this->assertSame('m_1', $res['messageId']);
    }

    public function test_conversations_forwards_limit(): void
    {
        [$client, $fake] = $this->makeClient();
        $fake->enqueue(200, '{"data":{"messages":[{"id":"m_1"}],"queued":[],"latestVersion":3}}');
        $out = $client->projects()->conversations('p_1', limit: 10);
        $this->assertSame(3, $out['latestVersion']);
        $this->assertStringContainsString('limit=10', $fake->requests[0]['url']);
    }

    public function test_conversations_omits_limit_when_null(): void
    {
        [$client, $fake] = $this->makeClient();
        $fake->enqueue(200, '{"data":{"messages":[],"queued":[],"latestVersion":0}}');
        $client->projects()->conversations('p_1');
        $this->assertStringNotContainsString('limit=', $fake->requests[0]['url']);
    }
}
