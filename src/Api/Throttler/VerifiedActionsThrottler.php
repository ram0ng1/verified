<?php

/*
 * This file is part of ramon/verified.
 *
 * Copyright (c) 2026 Ramon Guilherme.
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace Ramon\Verified\Api\Throttler;

use Flarum\Http\RequestUtil;
use Illuminate\Cache\RateLimiter;
use Psr\Http\Message\ServerRequestInterface;

/**
 * Devolve `null` para se abster e `true` para limitar. Nunca `false`: isso
 * isentaria o ator de todos os throttlers, inclusive o de login.
 */
class VerifiedActionsThrottler
{
    /**
     * Rota => [tentativas, minutos de decay]. Os endpoints da resource entram como
     * `{type}.{endpoint}`; sem eles, um loop create/delete ficaria ilimitado mesmo
     * com o upload limitado.
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
