<?php

namespace Ramon\Verified\Api\Throttler;

use Flarum\Http\RequestUtil;
use Illuminate\Cache\RateLimiter;
use Psr\Http\Message\ServerRequestInterface;

/**
 * Rate limiter por ator. Devolve `null` para abster, `true` para limitar.
 * NUNCA `false` — isso isenta de todos os throttlers, inclusive login.
 */
class VerifiedActionsThrottler
{
    /**
     * Rota → [max attempts/min, decay minutes]. Caps conservadores: usuário
     * normal envia um upload, submete uma request, dispara um verify por
     * minuto no máximo. Endpoints da resource registrados via
     * `Endpoint\Endpoint::make()` recebem nomes
     * `{resource_type}.{endpoint_name}` — sem eles, um loop create→delete
     * fica unbounded mesmo com upload throttled.
     */
    protected const LIMITS = [
        'verified.documents.upload'              => [5,  1],
        'verified.badge_svg.upload'              => [10, 1],
        'verified.badge_svg.delete'              => [10, 1],
        'verified.users.verify'                  => [30, 1],
        'verified.users.unverify'                => [30, 1],
        'verified.encryption.generate'           => [3,  1],
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
