<?php

namespace Ramon\Verified\Api\Controller;

use Carbon\Carbon;
use Flarum\Foundation\ValidationException;
use Flarum\Http\RequestUtil;
use Flarum\Locale\TranslatorInterface;
use Flarum\Settings\SettingsRepositoryInterface;
use Flarum\User\User;
use Illuminate\Contracts\Events\Dispatcher;
use Laminas\Diactoros\Response\JsonResponse;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\RequestHandlerInterface;
use Ramon\Verified\Documents\DocumentRetention;
use Ramon\Verified\Event\UserVerified;
use Ramon\Verified\Models\VerificationRequest;
use Ramon\Verified\TierConfig;
use Ramon\Verified\TierResolver;

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
        protected Dispatcher $events,
        protected DocumentRetention $retention,
        protected SettingsRepositoryInterface $settings,
        protected TierResolver $tiers
    ) {
    }

    public function handle(ServerRequestInterface $request): ResponseInterface
    {
        $actor = RequestUtil::getActor($request);
        $actor->assertRegistered();

        // Route param is exposed via request attributes; query bag merge is
        // a fallback for callers that pass `?id=` explicitly.
        $rawId  = $request->getAttribute('id') ?? ($request->getQueryParams()['id'] ?? 0);
        $userId = (int) $rawId;
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

        $tierId = isset($body['tier']) && is_string($body['tier'])
            ? trim($body['tier'])
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

        return $this->verify($target, $actor, $note, $tierId, $now);
    }

    private function verify(User $target, User $actor, ?string $note, ?string $tierId, Carbon $now): JsonResponse
    {
        if ((bool) $target->is_verified) {
            throw new ValidationException(['status' => $this->translator->trans('ramon-verified.api.already_verified')]);
        }

        $resolvedTierId = $this->resolveTierId($tierId);
        $adminNote      = $note ?: $this->translator->trans('ramon-verified.api.verified_by_admin_note');

        // Flip every pending request for this user to APPROVED in one
        // atomic UPDATE. Eloquent's `update()` returns the affected row
        // count — we use that DIRECTLY to decide whether to insert a fresh
        // audit row (previous code re-queried with EXISTS, which both read
        // wrong as `$hasPending` and opened a tiny race between the UPDATE
        // and the SELECT-EXISTS where two concurrent verifies could each
        // insert a duplicate APPROVED row — audit F3).
        $flippedRows = VerificationRequest::query()
            ->where('user_id', $target->id)
            ->where('status', VerificationRequest::STATUS_PENDING)
            ->update([
                'status'     => VerificationRequest::STATUS_APPROVED,
                'handled_by' => (int) $actor->id,
                'handled_at' => $now,
                'updated_at' => $now,
                'admin_note' => $adminNote,
            ]);

        if ($flippedRows === 0) {
            VerificationRequest::query()->insert([
                'user_id'       => (int) $target->id,
                'status'        => VerificationRequest::STATUS_APPROVED,
                'reason'        => null,
                'admin_note'    => $adminNote,
                'handled_by'    => (int) $actor->id,
                'handled_at'    => $now,
                'created_at'    => $now,
                'updated_at'    => $now,
            ]);
        }

        $target->is_verified  = true;
        $target->verified_at  = $now;
        $target->verified_by  = (int) $actor->id;
        $target->verified_tier = $resolvedTierId;
        $target->save();

        // Run retention on every request row we just flipped to approved so
        // delete_immediate forums don't keep document files around after a
        // direct admin verify.
        VerificationRequest::query()
            ->where('user_id', $target->id)
            ->where('handled_at', $now)
            ->get()
            ->each(fn (VerificationRequest $req) => $this->retention->onRequestHandled($req));

        $this->events->dispatch(new UserVerified($target, $actor));

        return new JsonResponse([
            'data' => [
                'type' => 'users',
                'id'   => (string) $target->id,
                'attributes' => [
                    'isVerified'   => true,
                    'verifiedAt'   => $now->toRfc3339String(),
                    'verifiedTier' => $resolvedTierId,
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

        $target->is_verified   = false;
        $target->verified_at   = null;
        $target->verified_by   = null;
        $target->verified_tier = null;
        $target->save();

        // Auto-grant overlap: an admin (or the user themselves via the
        // avatar-change flow) just cleared the MANUAL verification, but
        // the target is also in a group that auto-grants a tier — so
        // `TierResolver::isVerified` still returns true on the next read,
        // and the badge stays visible (audit L5). The unverify still has
        // its effect: `EnforceAvatarLock` reads the column, not the
        // resolver, so the avatar IS now editable. Surface this asymmetry
        // in `meta.autoTierPersists` so the caller / frontend toast can
        // tell the truth instead of pretending the badge is gone.
        $target->load('groups');
        $autoTier = $this->tiers->resolveAutoTier($target);

        $meta = $autoTier !== null
            ? ['autoTierPersists' => [
                'id'    => $autoTier['id'],
                'label' => $autoTier['label'],
            ]]
            // Empty object (not array) so JSON serialises as `{}`, not
            // `[]` — clients can `if (response.meta.autoTierPersists)`
            // without first checking the type of `meta` itself.
            : new \stdClass();

        return new JsonResponse([
            'data' => [
                'type' => 'users',
                'id'   => (string) $target->id,
                'attributes' => [
                    // Truthful: the user is "verified" iff the resolver
                    // still says so (i.e. an auto-tier survives).
                    'isVerified'   => $autoTier !== null,
                    'verifiedAt'   => null,
                    'verifiedTier' => $autoTier['id'] ?? null,
                ],
            ],
            'meta' => $meta,
        ], 200);
    }

    /**
     * Validate the tier id requested by the admin against the configured
     * tier list. Falls back to the default tier (or, if absent, the first
     * configured tier) so a missing/invalid input never blocks a verify.
     */
    private function resolveTierId(?string $requested): ?string
    {
        $tiers = TierConfig::fromSettings($this->settings);
        if (empty($tiers)) {
            // Admin emptied the tier list entirely. Persist null and let the
            // resource fallback handle rendering — better than crashing.
            return null;
        }

        if ($requested) {
            $found = TierConfig::findById($tiers, $requested);
            if ($found) return $found['id'];
        }

        $fallback = TierConfig::findById($tiers, TierConfig::DEFAULT_TIER_ID) ?? $tiers[0];
        return $fallback['id'];
    }
}
