<?php

declare(strict_types=1);

namespace FloopFloop;

final class ApiKeys
{
    public function __construct(private readonly Client $client)
    {
    }

    /** @return list<array<string, mixed>> */
    public function list(): array
    {
        $data = $this->client->request('GET', '/api/v1/api-keys');
        if (is_array($data) && isset($data['keys']) && is_array($data['keys'])) {
            /** @var list<array<string, mixed>> */
            return array_values($data['keys']);
        }
        return is_array($data) ? array_values($data) : [];
    }

    /**
     * Mint a new API key. The returned "rawKey" is the ONLY time the
     * full secret leaves the server — surface it once, then discard.
     *
     * @return array<string, mixed>
     */
    public function create(string $name): array
    {
        /** @var array<string, mixed> */
        return $this->client->request('POST', '/api/v1/api-keys', ['name' => $name]);
    }

    /**
     * Revoke by id OR name — does a preflight list to resolve the
     * name, mirroring the Node / Ruby / Rust SDKs' ergonomic shortcut.
     */
    public function remove(string $idOrName): void
    {
        foreach ($this->list() as $key) {
            if (($key['id'] ?? null) === $idOrName || ($key['name'] ?? null) === $idOrName) {
                $this->client->request('DELETE', '/api/v1/api-keys/' . rawurlencode((string) $key['id']));
                return;
            }
        }
        throw new Error('NOT_FOUND', "API key not found: {$idOrName}", 404);
    }
}
