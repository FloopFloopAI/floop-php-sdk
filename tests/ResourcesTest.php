<?php

declare(strict_types=1);

namespace FloopFloop\Tests;

use FloopFloop\Client;
use FloopFloop\Error;
use FloopFloop\Uploads;
use PHPUnit\Framework\TestCase;

final class ResourcesTest extends TestCase
{
    private function makeClient(): array
    {
        $fake = new FakeHttpClient();
        $client = new Client(apiKey: 'flp_test', baseUrl: 'https://api.test.local', httpClient: $fake);
        return [$client, $fake];
    }

    public function test_subdomains_check_and_suggest(): void
    {
        [$client, $fake] = $this->makeClient();
        $fake->enqueue(200, '{"data":{"slug":"hello","available":true}}');
        $fake->enqueue(200, '{"data":{"slug":"cat-cafe"}}');
        $check = $client->subdomains()->check('hello');
        $suggest = $client->subdomains()->suggest('a cat cafe');
        $this->assertTrue($check['available']);
        $this->assertSame('cat-cafe', $suggest['slug']);
        $this->assertStringContainsString('slug=hello', $fake->requests[0]['url']);
    }

    public function test_secrets_list_set_remove(): void
    {
        [$client, $fake] = $this->makeClient();
        $fake->enqueue(200, '{"data":{"secrets":[{"name":"STRIPE_KEY"},{"name":"DB_URL"}]}}');
        $fake->enqueue(200, '{"data":{"success":true}}');
        $fake->enqueue(200, '{"data":{"success":true}}');

        $list = $client->secrets()->list('p_1');
        $this->assertCount(2, $list);
        $this->assertSame('STRIPE_KEY', $list[0]['name']);

        $client->secrets()->set('p_1', 'STRIPE_KEY', 'sk_xxx');
        $client->secrets()->remove('p_1', 'STRIPE_KEY');

        $this->assertSame('POST', $fake->requests[1]['method']);
        $this->assertStringContainsString('"name":"STRIPE_KEY"', (string) $fake->requests[1]['body']);
        $this->assertSame('DELETE', $fake->requests[2]['method']);
        $this->assertStringEndsWith('/STRIPE_KEY', $fake->requests[2]['url']);
    }

    public function test_library_list_bare_array(): void
    {
        [$client, $fake] = $this->makeClient();
        $fake->enqueue(200, '{"data":[{"id":"p_1","name":"Cat","cloneCount":42}]}');
        $out = $client->library()->list();
        $this->assertCount(1, $out);
        $this->assertSame(42, $out[0]['cloneCount']);
    }

    public function test_library_list_items_envelope(): void
    {
        [$client, $fake] = $this->makeClient();
        $fake->enqueue(200, '{"data":{"items":[{"id":"p_2","name":"RSI"}]}}');
        $out = $client->library()->list();
        $this->assertSame('p_2', $out[0]['id']);
    }

    public function test_library_clone(): void
    {
        [$client, $fake] = $this->makeClient();
        $fake->enqueue(200, '{"data":{"id":"p_new","subdomain":"my-cafe","status":"queued"}}');
        $out = $client->library()->clone('p_1', 'my-cafe');
        $this->assertSame('p_new', $out['id']);
        $this->assertStringContainsString('"subdomain":"my-cafe"', (string) $fake->requests[0]['body']);
    }

    public function test_usage_summary(): void
    {
        [$client, $fake] = $this->makeClient();
        $fake->enqueue(200, '{"data":{"plan":{"name":"business","monthlyCredits":10000},"credits":{"currentCredits":5000},"currentPeriod":{"buildsUsed":12}}}');
        $out = $client->usage()->summary();
        $this->assertSame('business', $out['plan']['name']);
        $this->assertSame(12, $out['currentPeriod']['buildsUsed']);
    }

    public function test_subscriptions_current_populated(): void
    {
        [$client, $fake] = $this->makeClient();
        $fake->enqueue(200, '{"data":{"subscription":{"status":"active","billingPeriod":"monthly","currentPeriodStart":"2026-04-01T00:00:00Z","currentPeriodEnd":"2026-05-01T00:00:00Z","canceledAt":null,"planName":"pro","planDisplayName":"Pro","priceMonthly":29,"priceAnnual":290,"monthlyCredits":500,"maxProjects":50,"maxStorageMb":5000,"maxBandwidthMb":50000,"creditRolloverMonths":1,"features":{"teams":true}},"credits":{"current":423,"rolledOver":50,"total":473,"rolloverExpiresAt":"2026-05-01T00:00:00Z","lifetimeUsed":1234}}}');
        $out = $client->subscriptions()->current();
        $this->assertSame('pro', $out['subscription']['planName']);
        $this->assertSame(500, $out['subscription']['monthlyCredits']);
        $this->assertSame(473, $out['credits']['total']);
        $this->assertStringEndsWith('/api/v1/subscriptions/current', $fake->requests[0]['url']);
    }

    public function test_subscriptions_current_both_null(): void
    {
        [$client, $fake] = $this->makeClient();
        $fake->enqueue(200, '{"data":{"subscription":null,"credits":null}}');
        $out = $client->subscriptions()->current();
        $this->assertNull($out['subscription']);
        $this->assertNull($out['credits']);
    }

    public function test_api_keys_list_create_remove_by_name(): void
    {
        [$client, $fake] = $this->makeClient();
        $fake->enqueue(200, '{"data":{"keys":[{"id":"k_7","name":"my-script","keyPrefix":"flp_"}]}}');
        $fake->enqueue(200, '{"data":{"id":"k_new","rawKey":"flp_secretsecret","keyPrefix":"flp_secre"}}');
        $fake->enqueue(200, '{"data":{"keys":[{"id":"k_7","name":"my-script","keyPrefix":"flp_"}]}}');
        $fake->enqueue(200, '{"data":{"success":true}}');

        $list = $client->apiKeys()->list();
        $this->assertCount(1, $list);

        $issued = $client->apiKeys()->create('new');
        $this->assertSame('flp_secretsecret', $issued['rawKey']);

        // Remove by name — SDK does preflight list, then DELETE.
        $client->apiKeys()->remove('my-script');
        $this->assertStringEndsWith('/api/v1/api-keys/k_7', $fake->requests[3]['url']);
    }

    public function test_api_keys_remove_not_found(): void
    {
        [$client, $fake] = $this->makeClient();
        $fake->enqueue(200, '{"data":{"keys":[]}}');
        try {
            $client->apiKeys()->remove('ghost');
            $this->fail('should have thrown');
        } catch (Error $e) {
            $this->assertSame('NOT_FOUND', $e->code);
        }
    }

    public function test_uploads_happy_path(): void
    {
        [$client, $fake] = $this->makeClient();
        $s3Url = 'https://s3.test.local/put';
        $fake->enqueue(200, '{"data":{"uploadUrl":"' . $s3Url . '","key":"uploads/u_1/cat.png","fileId":"f_1"}}');
        $fake->enqueue(200, '');

        $out = $client->uploads()->create('cat.png', 'fake-png-bytes');
        $this->assertSame('uploads/u_1/cat.png', $out['key']);
        $this->assertSame('image/png', $out['fileType']);
        $this->assertSame(14, $out['fileSize']);

        // First call was the presign; second was the PUT to S3.
        $this->assertSame('POST', $fake->requests[0]['method']);
        $this->assertSame('PUT', $fake->requests[1]['method']);
        $this->assertSame($s3Url, $fake->requests[1]['url']);
        $this->assertSame('image/png', $fake->requests[1]['headers']['Content-Type']);
    }

    public function test_uploads_rejects_unknown_ext(): void
    {
        [$client] = $this->makeClient();
        try {
            $client->uploads()->create('archive.tar.gz', 'x');
            $this->fail('should have thrown');
        } catch (Error $e) {
            $this->assertSame('VALIDATION_ERROR', $e->code);
        }
    }

    public function test_uploads_rejects_oversize(): void
    {
        [$client] = $this->makeClient();
        $big = str_repeat('x', Uploads::MAX_UPLOAD_BYTES + 1);
        try {
            $client->uploads()->create('big.png', $big);
            $this->fail('should have thrown');
        } catch (Error $e) {
            $this->assertSame('VALIDATION_ERROR', $e->code);
            $this->assertStringContainsString('upload limit', $e->getMessage());
        }
    }

    public function test_uploads_create_from_path(): void
    {
        [$client, $fake] = $this->makeClient();
        $tmp = tempnam(sys_get_temp_dir(), 'floop_') . '.png';
        file_put_contents($tmp, 'png-bytes');
        try {
            $fake->enqueue(200, '{"data":{"uploadUrl":"https://s3.test/put","key":"k","fileId":"f"}}');
            $fake->enqueue(200, '');
            $out = $client->uploads()->createFromPath($tmp);
            $this->assertSame(9, $out['fileSize']);
            $this->assertSame('image/png', $out['fileType']);
        } finally {
            @unlink($tmp);
        }
    }

    public function test_guess_mime_type(): void
    {
        $this->assertSame('image/png', Uploads::guessMimeType('cat.PNG'));
        $this->assertSame('application/pdf', Uploads::guessMimeType('doc.pdf'));
        $this->assertNull(Uploads::guessMimeType('archive.tar.gz'));
        $this->assertNull(Uploads::guessMimeType('noext'));
    }

    public function test_user_me(): void
    {
        [$client, $fake] = $this->makeClient();
        $fake->enqueue(200, '{"data":{"id":"u_1","email":"p@x","name":"Pim"}}');
        $me = $client->user()->me();
        $this->assertSame('u_1', $me['id']);
    }
}
