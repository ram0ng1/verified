<?php

namespace Ramon\Verified\Api\Throttler;

use Flarum\Http\RequestUtil;
use Illuminate\Cache\RateLimiter;
use Psr\Http\Message\ServerRequestInterface;

/**
 * Per-actor rate limiter for mutating verified-extension endpoints.
 *
 * Without this, an authenticated user with `verified.request` can spam
 * the 8 MB document upload endpoint, which performs full libsodium
 * sealed-box encryption per request — concurrent abuse can OOM PHP-FPM
 * workers (audit F2). The verify/unverify and keypair-regenerate
 * endpoints are admin-only but still benefit from limiting in case an
 * admin session token leaks.
 *
 * Return semantics (CLAUDE.md §18 — the canonical trap):
 *   - `null` → abstain (route not throttled here; other throttlers may decide)
 *   - `true` → limit reached (caller emits 429)
 *
 * NEVER return `false` — that exempts the request from EVERY throttler
 * including login rate limits.
 */
class VerifiedActionsThrottler
{
    /**
     * Route → [max attempts per minute, decay minutes].
     *
     * Conservative caps: a normal user uploads one document, submits one
     * request, and triggers verify ONCE per minute at most. The keypair
     * regenerate route is even more rare — capped at 3/minute is more
     * than enough.
     *
     * The `verification-requests.*` entries cover the JSON:API resource
     * endpoints registered through `Endpoint\Endpoint::make()` on
     * `VerificationRequestResource` (audit N2). Without them, a
     * `create → delete` loop is unbounded even though uploads are
     * throttled — a user reusing a stale token can churn DB rows.
     */
    protected const LIMITS = [
        // Custom controllers (registered via Extend\Routes).
        'verified.documents.upload'              => [5,  1],
        'verified.badge_svg.upload'              => [10, 1],
        'verified.badge_svg.delete'              => [10, 1],
        'verified.users.verify'                  => [30, 1],
        'verified.users.unverify'                => [30, 1],
        'verified.encryption.generate'           => [3,  1],
        // Resource endpoints (registered via VerificationRequestResource).
        // Names are `{resource_type}.{endpoint_name}` — see Flarum core
        // ApiServiceProvider::215.
        'verification-requests.create'           => [5,  1],
        'verification-requests.delete'           => [10, 1],
        'verification-requests.verified.approve' => [60, 1],
        'verification-requests.verified.reject'  => [60, 1],
        'verification-requests.verified.revoke'  => [60, 1],
    ];

    public function __construct(
        protected RateLimiter $limiter
    ) {
    }

    public function __invoke(ServerRequestInterface $request): null|bool
    {
        $route = $request->getAttribute('routeName');
        if (! is_string($route) || ! isset(self::LIMITS[$route])) {
            return null;
        }

        [$max, $decayMinutes] = self::LIMITS[$route];

        $actor = RequestUtil::getActor($request);

        // None of the throttled routes accept unauthenticated traffic —
        // every handler calls `assertRegistered()` (or stricter) as its
        // first line. Abstain on guests rather than key by `REMOTE_ADDR`,
        // which behind a reverse proxy without `trusted-proxies`
        // configured would collapse every guest into one bucket and DoS
        // the limiter (audit N1). If a future endpoint is added to LIMITS
        // that legitimately serves guests, reintroduce a per-IP key here
        // AND document the proxy-configuration prerequisite.
        if ($actor->isGuest()) {
            return null;
        }

        $key = 'verified:'.$route.':'.(int) $actor->id;

        if ($this->limiter->tooManyAttempts($key, $max)) {
            return true;
        }

        $this->limiter->hit($key, $decayMinutes * 60);

        return null;
    }
}
