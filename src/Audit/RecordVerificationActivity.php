<?php

namespace Ramon\Verified\Audit;

use Flarum\User\User;
use Psr\Log\LoggerInterface;
use Ramon\Audit\Activity\Recorder;
use Ramon\Verified\Event\UserUnverified;
use Ramon\Verified\Event\UserVerified;
use Ramon\Verified\TierResolver;
use Throwable;

/**
 * Grava a trilha de auditoria da extensão `ramon/audit` quando o status
 * verificado de um usuário muda. Só é fiado quando a audit está instalada
 * (guard `class_exists` no extend.php), então a referência a `Recorder`
 * nunca é autoloadada em installs sem a audit.
 *
 * Falha no registro de auditoria não pode desfazer o verify/unverify já
 * commitado — log e segue, como o listener de notificação.
 */
class RecordVerificationActivity
{
    public function __construct(
        protected Recorder $recorder,
        protected TierResolver $tiers,
        protected LoggerInterface $logger
    ) {
    }

    public function whenVerified(UserVerified $event): void
    {
        $this->record('verified.user.verified', $event->user, $event->actor, [
            'tier' => $this->tiers->resolveTierId($event->user),
        ]);
    }

    public function whenUnverified(UserUnverified $event): void
    {
        $this->record('verified.user.unverified', $event->user, $event->actor, []);
    }

    /**
     * @param array<string, mixed> $payload
     */
    private function record(string $type, User $user, User $actor, array $payload): void
    {
        try {
            $this->recorder->record(
                type: $type,
                actor: $actor,
                target: $user,
                payload: $payload,
            );
        } catch (Throwable $e) {
            $this->logger->warning('verified: failed to record audit activity', [
                'type'    => $type,
                'user_id' => (int) $user->id,
                'error'   => $e->getMessage(),
            ]);
        }
    }
}
