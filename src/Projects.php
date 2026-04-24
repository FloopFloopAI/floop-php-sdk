<?php

declare(strict_types=1);

namespace FloopFloop;

final class Projects
{
    public const TERMINAL_STATUSES = ['live', 'failed', 'cancelled'];

    public function __construct(private readonly Client $client)
    {
    }

    /**
     * POST /api/v1/projects
     *
     * @return array<string, mixed>  the { "project": {...}, "deployment": {...} } envelope
     */
    public function create(
        string $prompt,
        ?string $name = null,
        ?string $subdomain = null,
        ?string $botType = null,
        ?bool $isAuthProtected = null,
        ?string $teamId = null,
    ): array {
        $body = ['prompt' => $prompt];
        if ($name !== null) {
            $body['name'] = $name;
        }
        if ($subdomain !== null) {
            $body['subdomain'] = $subdomain;
        }
        if ($botType !== null) {
            $body['botType'] = $botType;
        }
        if ($isAuthProtected !== null) {
            $body['isAuthProtected'] = $isAuthProtected;
        }
        if ($teamId !== null) {
            $body['teamId'] = $teamId;
        }
        /** @var array<string, mixed> $result */
        $result = $this->client->request('POST', '/api/v1/projects', $body);
        return $result;
    }

    /**
     * @return list<array<string, mixed>>
     */
    public function list(?string $teamId = null): array
    {
        $query = $teamId !== null ? ['teamId' => $teamId] : null;
        /** @var list<array<string, mixed>> $result */
        $result = $this->client->request('GET', '/api/v1/projects', query: $query);
        return $result;
    }

    /**
     * Fetch a single project by id or subdomain. No dedicated backend
     * route — filters list() locally, matching the other SDKs.
     *
     * @return array<string, mixed>
     */
    public function get(string $ref, ?string $teamId = null): array
    {
        foreach ($this->list($teamId) as $project) {
            if (($project['id'] ?? null) === $ref || ($project['subdomain'] ?? null) === $ref) {
                return $project;
            }
        }
        throw new Error('NOT_FOUND', "project not found: {$ref}", 404);
    }

    /**
     * @return array<string, mixed>
     */
    public function status(string $ref): array
    {
        /** @var array<string, mixed> $result */
        $result = $this->client->request('GET', '/api/v1/projects/' . rawurlencode($ref) . '/status');
        return $result;
    }

    public function cancel(string $ref): void
    {
        $this->client->request('POST', '/api/v1/projects/' . rawurlencode($ref) . '/cancel');
    }

    public function reactivate(string $ref): void
    {
        $this->client->request('POST', '/api/v1/projects/' . rawurlencode($ref) . '/reactivate');
    }

    /**
     * Refine returns one of three response shapes — keyed by "queued"
     * (bool) or "processing" (bool). We return the raw hash so the
     * caller can branch on presence, matching the Python / Go / Node SDKs.
     *
     * @param list<array<string, mixed>>|null $attachments
     * @return array<string, mixed>
     */
    public function refine(
        string $ref,
        string $message,
        ?array $attachments = null,
        ?bool $codeEditOnly = null,
    ): array {
        $body = ['message' => $message];
        if ($attachments !== null) {
            $body['attachments'] = $attachments;
        }
        if ($codeEditOnly !== null) {
            $body['codeEditOnly'] = $codeEditOnly;
        }
        /** @var array<string, mixed> $result */
        $result = $this->client->request('POST', '/api/v1/projects/' . rawurlencode($ref) . '/refine', $body);
        return $result;
    }

    /**
     * @return array<string, mixed>
     */
    public function conversations(string $ref, ?int $limit = null): array
    {
        $query = ($limit !== null && $limit > 0) ? ['limit' => $limit] : null;
        /** @var array<string, mixed> $result */
        $result = $this->client->request('GET', '/api/v1/projects/' . rawurlencode($ref) . '/conversations', query: $query);
        return $result;
    }

    /**
     * Poll the status endpoint, calling $handler with every
     * de-duplicated snapshot (same (status, step, progress,
     * queuePosition) tuple the other SDKs use) until a terminal state.
     *
     * @param callable(array<string, mixed>): void  $handler
     * @return array<string, mixed> the final "live" status event
     * @throws Error  on BUILD_FAILED / BUILD_CANCELLED / TIMEOUT
     */
    public function stream(
        string $ref,
        callable $handler,
        float $interval = 2.0,
        float $maxWait = 600.0,
    ): array {
        $deadline = microtime(true) + $maxWait;
        $lastKey = null;

        while (true) {
            if (microtime(true) >= $deadline) {
                throw new Error('TIMEOUT', "stream: project {$ref} did not reach a terminal state within {$maxWait}s");
            }

            $event = $this->status($ref);
            $key = self::dedupKey($event);
            if ($key !== $lastKey) {
                $lastKey = $key;
                $handler($event);
            }

            $status = (string) ($event['status'] ?? '');
            if ($status === 'live') {
                return $event;
            }
            if ($status === 'failed') {
                $msg = $event['message'] ?? '';
                throw new Error('BUILD_FAILED', $msg !== '' ? (string) $msg : 'build failed');
            }
            if ($status === 'cancelled') {
                $msg = $event['message'] ?? '';
                throw new Error('BUILD_CANCELLED', $msg !== '' ? (string) $msg : 'build cancelled');
            }

            $remaining = $deadline - microtime(true);
            $sleepFor = min($interval, max(0.0, $remaining));
            if ($sleepFor > 0) {
                usleep((int) ($sleepFor * 1_000_000));
            }
        }
    }

    /**
     * Block until the project reaches 'live' and return the hydrated
     * project hash. Wraps stream() with a no-op handler.
     *
     * @return array<string, mixed>
     */
    public function waitForLive(string $ref, float $interval = 2.0, float $maxWait = 600.0): array
    {
        $this->stream($ref, fn (array $_ev) => null, interval: $interval, maxWait: $maxWait);
        return $this->get($ref);
    }

    /**
     * @param array<string, mixed> $event
     */
    private static function dedupKey(array $event): string
    {
        return implode('|', [
            (string) ($event['status'] ?? ''),
            (string) ($event['step'] ?? ''),
            (string) ($event['progress'] ?? ''),
            (string) ($event['queuePosition'] ?? ''),
        ]);
    }
}
