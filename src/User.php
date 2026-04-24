<?php

declare(strict_types=1);

namespace FloopFloop;

/**
 * Account-level resource. Accessed via `$client->user()`.
 */
final class User
{
    public function __construct(private readonly Client $client)
    {
    }

    /**
     * @return array<string, mixed>
     */
    public function me(): array
    {
        /** @var array<string, mixed> */
        return $this->client->request('GET', '/api/v1/user/me');
    }
}
