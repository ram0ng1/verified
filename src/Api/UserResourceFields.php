<?php

namespace Ramon\Verified\Api;

use Flarum\Api\Context;
use Flarum\Api\Schema;
use Flarum\Extension\ExtensionManager;
use Flarum\Settings\SettingsRepositoryInterface;
use Flarum\User\User;
use Ramon\Verified\Models\VerificationRequest;
use Ramon\Verified\TierResolver;
use Ramon\Verified\VerifiedStatus;

class UserResourceFields
{
    /**
     * Teto de IDs pendentes pré-carregados em memória. Em operação normal o
     * conjunto de pedidos pendentes é pequeno (um pendente por usuário, e
     * admins os processam), mas um fórum que nunca processa pedidos faria o
     * pluck crescer sem limite — o cap impede isso.
     */
    protected const PRELOAD_CAP = 5000;

    /**
     * Set de user_ids com request pendente, carregado em batch na primeira
     * leitura (§38.1), limitado a `PRELOAD_CAP`. `null` enquanto não
     * inicializado; um `array<int, true>` depois — `isset($map[$id])` é O(1).
     *
     * @var array<int, true>|null
     */
    protected ?array $pendingUserIds = null;

    /**
     * Verdadeiro quando o pré-carregamento devolveu menos linhas que o cap —
     * o set é então autoritativo e a ausência de um id significa "sem
     * pendente". Quando falso, ids fora do set caem no fallback por linha.
     */
    protected bool $pendingSetComplete = false;

    /**
     * Cache dos checks individuais usados só quando o set foi truncado pelo
     * cap. Mantém o caminho degradado idempotente e sem N+1 repetido.
     *
     * @var array<int, bool>
     */
    protected array $individualPendingChecks = [];

    protected ?bool $nicknamesEnabled = null;

    public function __construct(
        protected SettingsRepositoryInterface $settings,
        protected TierResolver $tiers,
        protected VerifiedStatus $verifiedStatus,
        protected ExtensionManager $extensions
    ) {
    }

    /**
     * A coluna `nickname` sobrevive à desativação do flarum/nicknames, então
     * o nickname só é exposto com a extensão ativa.
     */
    protected function nicknamesEnabled(): bool
    {
        return $this->nicknamesEnabled ??= $this->extensions->isEnabled('flarum-nicknames');
    }

    public function __invoke(): array
    {
        return [
            // Enfileira na fase síncrona e resolve adiado: quando o valor é lido,
            // todos os usuários do documento já estão na fila e a relação
            // `verification` sai numa consulta só (antes, uma por usuário).
            Schema\Boolean::make('isVerified')
                ->get(function (User $user) {
                    $this->tiers->defer($user);

                    return fn () => $this->tiers->isVerified($user);
                }),

            Schema\DateTime::make('verifiedAt')
                ->get(function (User $user) {
                    $this->tiers->defer($user);

                    return function () use ($user) {
                        $this->tiers->ensureLoaded($user);

                        return $this->verifiedStatus->verifiedAt($user);
                    };
                })
                ->nullable(),

            Schema\Str::make('verifiedTier')
                ->get(function (User $user) {
                    $this->tiers->defer($user);

                    return fn () => $this->tiers->resolveTierId($user);
                })
                ->nullable(),

            /*
             * Nickname do flarum/nicknames para a segunda linha do popover
             * quando o driver de exibição é o username. O valor já é público
             * com o driver `nickname`; só sai para usuários verificados e
             * quando difere do username.
             */
            Schema\Str::make('verifiedNickname')
                ->visible(fn () => $this->nicknamesEnabled())
                ->get(function (User $user) {
                    $this->tiers->defer($user);

                    return function () use ($user) {
                        $nickname = $user->getAttribute('nickname');
                        if (! is_string($nickname) || trim($nickname) === '' || $nickname === $user->username) {
                            return null;
                        }

                        return $this->tiers->isVerified($user) ? $nickname : null;
                    };
                })
                ->nullable(),

            Schema\Boolean::make('canRequestVerification')
                ->get(function (User $user, Context $context) {
                    $actor = $context->getActor();

                    if ($actor->isGuest() || $actor->id !== $user->id) {
                        return false;
                    }

                    if ($this->tiers->isVerified($user)) {
                        return false;
                    }

                    if (! $actor->hasPermission('verified.request')) {
                        return false;
                    }

                    if (! (bool) $this->settings->get('ramon-verified.requests_open', true)) {
                        return false;
                    }

                    return ! $this->userHasPending((int) $user->id);
                }),

            Schema\Boolean::make('hasPendingVerificationRequest')
                ->get(function (User $user, Context $context) {
                    $actor = $context->getActor();

                    if ($actor->isGuest() || ($actor->id !== $user->id && ! $actor->isAdmin())) {
                        return false;
                    }

                    return $this->userHasPending((int) $user->id);
                }),

            Schema\Boolean::make('isAvatarLocked')
                ->get(function (User $user, Context $context) {
                    $actor = $context->getActor();

                    if ($actor->isGuest() || $actor->id !== $user->id) {
                        return false;
                    }

                    if ((bool) $actor->isAdmin()) {
                        return false;
                    }

                    if (! (bool) $this->settings->get('ramon-verified.lock_avatar')) {
                        return false;
                    }

                    return $this->tiers->isVerified($user);
                }),
        ];
    }

    /**
     * Lookup O(1) contra o set pré-carregado. A primeira chamada dispara UMA
     * query `SELECT DISTINCT user_id FROM verification_requests WHERE
     * status='pending' LIMIT PRELOAD_CAP+1` — o índice composto
     * `(user_id, status)` cobre por inteiro. Substitui o EXISTS-por-linha que
     * tornava o §38.1 N+1 visível em listagens de admin com page[limit]=50.
     *
     * Quando o conjunto de pendentes ultrapassa `PRELOAD_CAP`, o set deixa de
     * ser autoritativo: ids fora dele caem num `exists()` por usuário (também
     * cacheado). É o caminho degradado de um fórum que acumula pendentes — a
     * memória fica limitada ao cap e o resultado continua correto.
     */
    protected function userHasPending(int $userId): bool
    {
        if ($this->pendingUserIds === null) {
            $this->pendingUserIds = [];

            $ids = VerificationRequest::query()
                ->where('status', VerificationRequest::STATUS_PENDING)
                ->distinct()
                ->limit(self::PRELOAD_CAP + 1)
                ->pluck('user_id');

            $this->pendingSetComplete = $ids->count() <= self::PRELOAD_CAP;

            foreach ($ids->take(self::PRELOAD_CAP) as $id) {
                $this->pendingUserIds[(int) $id] = true;
            }
        }

        if (isset($this->pendingUserIds[$userId])) {
            return true;
        }

        if ($this->pendingSetComplete) {
            return false;
        }

        return $this->individualPendingChecks[$userId] ??= VerificationRequest::query()
            ->where('user_id', $userId)
            ->where('status', VerificationRequest::STATUS_PENDING)
            ->exists();
    }
}
