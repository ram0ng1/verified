<?php

namespace Ramon\Verified\Api\Controller;

use Carbon\Carbon;
use Flarum\Foundation\ValidationException;
use Flarum\Http\RequestUtil;
use Flarum\Locale\TranslatorInterface;
use Flarum\User\User;
use Illuminate\Contracts\Events\Dispatcher;
use Laminas\Diactoros\Response\JsonResponse;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\RequestHandlerInterface;
use Ramon\Verified\Event\UserVerified;
use Ramon\Verified\Models\VerificationRequest;

/**
 * Lets an admin (or any actor with `verified.verifyUsers`) flip a user's
 * verified flag directly, bypassing the standard request workflow. A
 * `VerificationRequest` row is still written so the action shows up in the
 * audit log under "Approved" / "Rejected".
 *
 * - POST   /verified/users/{id}/verify     → mark verified
 * - DELETE /verified/users/{id}/verify     → revoke verification
 */
class VerifyUserController implements RequestHandlerInterface
{
    public function __construct(
        protected TranslatorInterface $translator,
        protected Dispatcher $events
    ) {
    }

    public function handle(ServerRequestInterface $request): ResponseInterface
    {
        $actor = RequestUtil::getActor($request);
        $actor->assertRegistered();

        $userId = (int) ($request->getQueryParams()['id'] ?? 0);
        if ($userId <= 0) {
            throw new ValidationException(['id' => $this->translator->trans('ramon-verified.api.user_missing')]);
        }

        // Authorization: an actor can flip their OWN verification flag
        // (used by the "request to change avatar" flow on the forum side
        // — verified users renounce their badge to unlock avatar editing
        // and then submit a fresh request). Otherwise the actor must hold
        // the admin permission.
        $isSelf = (int) $actor->id === $userId;
        if (! $isSelf) {
            $actor->assertCan('verified.verifyUsers');
        }

        /** @var User|null $target */
        $target = User::query()->find($userId);
        if (! $target) {
            throw new ValidationException(['id' => $this->translator->trans('ramon-verified.api.user_missing')]);
        }

        $body = (array) $request->getParsedBody();
        $note = isset($body['adminNote']) && is_string($body['adminNote'])
            ? mb_substr(trim($body['adminNote']), 0, 1000)
            : null;

        $method = strtoupper($request->getMethod());
        $now    = Carbon::now();

        if ($method === 'DELETE') {
            // Users self-revoking can't write `admin notes` — replace any
            // payload with a fixed self-revoke marker so the audit log is
            // honest about who triggered the revocation.
            if ($isSelf && ! $actor->isAdmin()) {
                $note = $this->translator->trans('ramon-verified.api.self_revoked_note');
            }
            return $this->unverify($target, $actor, $note, $now);
        }

        // POST (verify) is admin-only — self-verification doesn't make sense.
        if ($isSelf && ! $actor->isAdmin()) {
            $actor->assertCan('verified.verifyUsers');
        }

        return $this->verify($target, $actor, $note, $now);
    }

    private function verify(User $target, User $actor, ?string $note, Carbon $now): JsonResponse
    {
        if ((bool) $target->is_verified) {
            throw new ValidationException(['status' => $this->translator->trans('ramon-verified.api.already_verified')]);
        }

        // Mark any pending request handled, then create an audit row.
        VerificationRequest::query()
            ->where('user_id', $target->id)
            ->where('status', VerificationRequest::STATUS_PENDING)
            ->update([
                'status'     => VerificationRequest::STATUS_APPROVED,
                'handled_by' => (int) $actor->id,
                'handled_at' => $now,
                'updated_at' => $now,
                'admin_note' => $note ?: $this->translator->trans('ramon-verified.api.verified_by_admin_note'),
            ]);

        $hasPending = VerificationRequest::query()
            ->where('user_id', $target->id)
            ->where('handled_at', $now)
            ->exists();

        if (! $hasPending) {
            VerificationRequest::query()->insert([
                'user_id'       => (int) $target->id,
                'status'        => VerificationRequest::STATUS_APPROVED,
                'reason'        => null,
                'admin_note'    => $note ?: $this->translator->trans('ramon-verified.api.verified_by_admin_note'),
                'handled_by'    => (int) $actor->id,
                'handled_at'    => $now,
                'created_at'    => $now,
                'updated_at'    => $now,
            ]);
        }

        $target->is_verified = true;
        $target->verified_at = $now;
        $target->verified_by = (int) $actor->id;
        $target->save();

        $this->events->dispatch(new UserVerified($target, $actor));

        return new JsonResponse([
            'data' => [
                'type' => 'users',
                'id'   => (string) $target->id,
                'attributes' => [
                    'isVerified' => true,
                    'verifiedAt' => $now->toRfc3339String(),
                ],
            ],
        ], 200);
    }

    private function unverify(User $target, User $actor, ?string $note, Carbon $now): JsonResponse
    {
        if (! (bool) $target->is_verified) {
            throw new ValidationException(['status' => $this->translator->trans('ramon-verified.api.not_verified')]);
        }

        VerificationRequest::query()->insert([
            'user_id'       => (int) $target->id,
            'status'        => VerificationRequest::STATUS_REJECTED,
            'reason'        => null,
            'admin_note'    => $note ?: $this->translator->trans('ramon-verified.api.revoked_default_note'),
            'handled_by'    => (int) $actor->id,
            'handled_at'    => $now,
            'created_at'    => $now,
            'updated_at'    => $now,
        ]);

        $target->is_verified = false;
        $target->verified_at = null;
        $target->verified_by = null;
        $target->save();

        return new JsonResponse([
            'data' => [
                'type' => 'users',
                'id'   => (string) $target->id,
                'attributes' => [
                    'isVerified' => false,
                    'verifiedAt' => null,
                ],
            ],
        ], 200);
    }
}
