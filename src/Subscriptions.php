<?php

declare(strict_types=1);

namespace FloopFloop;

/**
 * Plan + credit-balance snapshot for the authenticated user.
 *
 * Distinct from {@see Usage} — `usage()->summary()` returns current-period
 * consumption (credits remaining + builds used + storage), while
 * `subscriptions()->current()` returns the plan tier itself (price,
 * billing period, cancel state). They overlap on `monthlyCredits` and
 * `maxProjects` but serve different audiences:
 * `usage()->summary()` for "am I about to hit my limits?",
 * `subscriptions()->current()` for "what plan am I on, and when does it
 * renew?".
 */
final class Subscriptions
{
    public function __construct(private readonly Client $client)
    {
    }

    /**
     * Fetch the authenticated user's current subscription + credit
     * snapshot.
     *
     * Returns the full `{subscription: {...} | null, credits: {...} | null}`
     * array — both keys are independently nullable: a user may exist
     * without an active subscription (mid-signup, cancelled with no
     * grace credits).
     *
     * @return array{subscription: array<string, mixed>|null, credits: array<string, mixed>|null}
     */
    public function current(): array
    {
        /** @var array{subscription: array<string, mixed>|null, credits: array<string, mixed>|null} */
        return $this->client->request('GET', '/api/v1/subscriptions/current');
    }
}
