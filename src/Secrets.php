<?php

declare(strict_types=1);

namespace FloopFloop;

final class Secrets
{
    public function __construct(private readonly Client $client)
    {
    }

    /** @return list<array<string, mixed>> */
    public function list(string $ref): array
    {
        $data = $this->client->request('GET', '/api/v1/projects/' . rawurlencode($ref) . '/secrets');
        if (is_array($data) && isset($data['secrets']) && is_array($data['secrets'])) {
            /** @var list<array<string, mixed>> */
            return array_values($data['secrets']);
        }
        return is_array($data) ? array_values($data) : [];
    }

    public function set(string $ref, string $name, string $value): void
    {
        $this->client->request(
            'POST',
            '/api/v1/projects/' . rawurlencode($ref) . '/secrets',
            ['name' => $name, 'value' => $value],
        );
    }

    public function remove(string $ref, string $name): void
    {
        $this->client->request(
            'DELETE',
            '/api/v1/projects/' . rawurlencode($ref) . '/secrets/' . rawurlencode($name),
        );
    }
}
