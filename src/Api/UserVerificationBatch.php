<?php

/*
 * This file is part of ramon/verified.
 *
 * Copyright (c) 2026 Ramon Guilherme.
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace Ramon\Verified\Api;

use Flarum\Settings\SettingsRepositoryInterface;
use Psr\Http\Message\ServerRequestInterface;
use Ramon\Verified\Models\VerificationRequest;
use Ramon\Verified\TierResolver;
use WeakMap;

/**
 * Estado da serialização de usuários de uma requisição: os tiers e a fila de
 * `verification` (no TierResolver) e quem tem solicitação pendente.
 *
 * Não pode morar nos campos do UserResource: eles vivem tanto quanto o processo
 * e vazariam estado entre requisições em workers e testes de integração.
 */
final class UserVerificationBatch
{
    /** @var WeakMap<ServerRequestInterface, self>|null */
    private static ?WeakMap $batches = null;

    private TierResolver $tiers;

    /** @var array<int, true> usuários à espera da consulta de pendentes */
    private array $queued = [];

    /** @var array<int, bool> */
    private array $pending = [];

    private function __construct(SettingsRepositoryInterface $settings)
    {
        $this->tiers = new TierResolver($settings);
    }

    public static function forRequest(ServerRequestInterface $request, SettingsRepositoryInterface $settings): self
    {
        self::$batches ??= new WeakMap();

        return self::$batches[$request] ??= new self($settings);
    }

    public function tiers(): TierResolver
    {
        return $this->tiers;
    }

    public function queuePending(int $userId): void
    {
        if (! isset($this->pending[$userId])) {
            $this->queued[$userId] = true;
        }
    }

    /**
     * Na primeira leitura, uma consulta só para todos os usuários da fila.
     */
    public function hasPending(int $userId): bool
    {
        $this->queuePending($userId);

        if ($this->queued !== []) {
            $ids = array_keys($this->queued);
            $this->queued = [];

            $found = VerificationRequest::query()
                ->whereIn('user_id', $ids)
                ->where('status', VerificationRequest::STATUS_PENDING)
                ->distinct()
                ->pluck('user_id')
                ->map(fn ($id) => (int) $id)
                ->all();

            foreach ($ids as $id) {
                $this->pending[$id] = in_array($id, $found, true);
            }
        }

        return $this->pending[$userId] ?? false;
    }
}
