<?php

/*
 * This file is part of ramon/verified.
 *
 * Copyright (c) 2026 Ramon Guilherme.
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace Ramon\Verified\Api\Controller;

use Carbon\Carbon;
use Flarum\Foundation\ValidationException;
use Flarum\Http\RequestUtil;
use Flarum\Http\UrlGenerator;
use Flarum\Locale\TranslatorInterface;
use Flarum\Settings\SettingsRepositoryInterface;
use Laminas\Diactoros\Response\JsonResponse;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\RequestHandlerInterface;
use Psr\Log\LoggerInterface;
use Ramon\Verified\Didit\DiditClient;
use Ramon\Verified\Didit\DiditConfig;
use Ramon\Verified\Didit\DiditException;
use Ramon\Verified\Didit\DiditStatusSync;
use Ramon\Verified\Models\VerificationRequest;
use Ramon\Verified\VerifiedStatus;

/**
 * POST /verified/didit/session: abre (ou retoma) a sessão da Didit do usuário
 * e devolve a URL para onde o fórum o redireciona.
 */
class CreateDiditSessionController implements RequestHandlerInterface
{
    public function __construct(
        protected DiditConfig $config,
        protected DiditClient $client,
        protected SettingsRepositoryInterface $settings,
        protected TranslatorInterface $translator,
        protected VerifiedStatus $verifiedStatus,
        protected UrlGenerator $url,
        protected LoggerInterface $logger
    ) {
    }

    public function handle(ServerRequestInterface $request): ResponseInterface
    {
        $actor = RequestUtil::getActor($request);
        $actor->assertRegistered();
        $actor->assertCan('verified.request');

        if (! $this->config->active()) {
            throw new ValidationException(['status' => $this->translator->trans('ramon-verified.api.didit.disabled')]);
        }

        if (! (bool) $this->settings->get('ramon-verified.requests_open')) {
            throw new ValidationException(['status' => $this->translator->trans('ramon-verified.api.requests_closed')]);
        }

        if ($this->verifiedStatus->isVerified($actor)) {
            throw new ValidationException(['status' => $this->translator->trans('ramon-verified.api.already_verified')]);
        }

        $pending = VerificationRequest::query()
            ->where('user_id', $actor->id)
            ->where('status', VerificationRequest::STATUS_PENDING)
            ->first();

        // Uma solicitação manual pendente (de antes da Didit) segue o caminho dela.
        if ($pending && $pending->provider !== DiditStatusSync::PROVIDER) {
            throw new ValidationException(['status' => $this->translator->trans('ramon-verified.api.already_pending')]);
        }

        try {
            // A Didit devolve a sessão em aberto do mesmo usuário em vez de criar outra.
            $session = $this->client->createSession(
                'flarum-user-'.$actor->id,
                $this->url->to('forum')->route('verified.didit.return'),
                ['user_id' => (int) $actor->id],
                substr((string) $this->translator->getLocale(), 0, 2)
            );
        } catch (DiditException $e) {
            $this->logger->warning('verified: could not create Didit session', ['user_id' => (int) $actor->id, 'error' => $e->getMessage()]);

            throw new ValidationException(['status' => $this->translator->trans('ramon-verified.api.didit.unavailable')]);
        }

        $sessionId = strtolower((string) $session['session_id']);
        $now = Carbon::now();

        VerificationRequest::runInTransaction(function () use ($actor, $sessionId, $session, $now) {
            $existing = VerificationRequest::query()
                ->where('user_id', $actor->id)
                ->where('status', VerificationRequest::STATUS_PENDING)
                ->where('provider', DiditStatusSync::PROVIDER)
                ->lockForUpdate()
                ->first();

            $row = $existing ?? new VerificationRequest();
            if (! $existing) {
                $row->user_id = (int) $actor->id;
                $row->status = VerificationRequest::STATUS_PENDING;
                $row->provider = DiditStatusSync::PROVIDER;
                $row->created_at = $now;
            }

            $row->external_id = $sessionId;
            $row->external_status = mb_substr((string) ($session['status'] ?? ''), 0, 32) ?: null;
            $row->updated_at = $now;
            $row->save();
        });

        return new JsonResponse([
            'url'       => (string) $session['url'],
            'sessionId' => $sessionId,
        ]);
    }
}
