<?php

declare(strict_types=1);

namespace FloopFloop;

final class Subdomains
{
    public function __construct(private readonly Client $client)
    {
    }

    /** @return array<string, mixed> */
    public function check(string $slug): array
    {
        /** @var array<string, mixed> */
        return $this->client->request('GET', '/api/v1/subdomains/check', query: ['slug' => $slug]);
    }

    /** @return array<string, mixed> */
    public function suggest(string $prompt): array
    {
        /** @var array<string, mixed> */
        return $this->client->request('GET', '/api/v1/subdomains/suggest', query: ['prompt' => $prompt]);
    }
}
