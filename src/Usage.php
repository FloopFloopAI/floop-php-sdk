<?php

declare(strict_types=1);

namespace FloopFloop;

final class Usage
{
    public function __construct(private readonly Client $client)
    {
    }

    /**
     * @return array<string, mixed>  full {plan, credits, currentPeriod} hash
     */
    public function summary(): array
    {
        /** @var array<string, mixed> */
        return $this->client->request('GET', '/api/v1/usage/summary');
    }
}
