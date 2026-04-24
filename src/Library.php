<?php

declare(strict_types=1);

namespace FloopFloop;

final class Library
{
    public function __construct(private readonly Client $client)
    {
    }

    /**
     * Backend emits either a bare list or a {"items": [...]} envelope
     * — both are tolerated, matching the Node / Python / Go / Rust / Ruby SDKs.
     *
     * @return list<array<string, mixed>>
     */
    public function list(
        ?string $botType = null,
        ?string $search = null,
        ?string $sort = null,
        ?int $page = null,
        ?int $limit = null,
    ): array {
        $query = [];
        if ($botType !== null) {
            $query['botType'] = $botType;
        }
        if ($search !== null) {
            $query['search'] = $search;
        }
        if ($sort !== null) {
            $query['sort'] = $sort;
        }
        if ($page !== null) {
            $query['page'] = $page;
        }
        if ($limit !== null) {
            $query['limit'] = $limit;
        }

        $data = $this->client->request('GET', '/api/v1/library', query: $query === [] ? null : $query);
        if (!is_array($data)) {
            throw new Error('UNKNOWN', 'library list: unrecognised response shape');
        }
        if (array_is_list($data)) {
            return $data;
        }
        if (isset($data['items']) && is_array($data['items']) && array_is_list($data['items'])) {
            return $data['items'];
        }
        throw new Error('UNKNOWN', 'library list: unrecognised response shape');
    }

    /** @return array<string, mixed> */
    public function clone(string $projectId, string $subdomain): array
    {
        /** @var array<string, mixed> */
        return $this->client->request(
            'POST',
            '/api/v1/library/' . rawurlencode($projectId) . '/clone',
            ['subdomain' => $subdomain],
        );
    }
}
