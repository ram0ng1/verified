<?php

namespace Ramon\Verified\Api\Resource;

use Carbon\Carbon;
use Flarum\Api\Context;
use Flarum\Api\Endpoint;
use Flarum\Api\Resource\AbstractDatabaseResource;
use Flarum\Api\Schema;
use Flarum\Api\Sort\SortColumn;
use Flarum\Foundation\ValidationException;
use Flarum\Locale\TranslatorInterface;
use Flarum\Settings\SettingsRepositoryInterface;
use Flarum\User\User;
use Illuminate\Contracts\Events\Dispatcher;
use Illuminate\Database\Eloquent\Builder;
use Ramon\Verified\Documents\DocumentPathResolver;
use Ramon\Verified\Documents\DocumentRetention;
use Ramon\Verified\Event\UserVerified;
use Ramon\Verified\Models\VerificationRequest;
use Ramon\Verified\TierConfig;

/**
 * @extends AbstractDatabaseResource<VerificationRequest>
 */
class VerificationRequestResource extends AbstractDatabaseResource
{
    public function __construct(
        protected SettingsRepositoryInterface $settings,
        protected TranslatorInterface $translator,
        protected Dispatcher $events,
        protected DocumentRetention $retention,
        protected DocumentPathResolver $pathResolver
    ) {
    }

    public function type(): string
    {
        return 'verification-requests';
    }

    public function model(): string
    {
        return VerificationRequest::class;
    }

    public function scope(Builder $query, \Tobyz\JsonApiServer\Context $context): void
    {
        $actor = $context->getActor();

        if (! $actor->isAdmin()) {
            $query->where('user_id', $actor->id);
        }
    }

    public function endpoints(): array
    {
        return [
            // Index relies on the `scope()` method above to restrict
            // non-admins to their own rows. The explicit page-size limits
            // here mirror `ListApprovedUsersController` defaults and cap the
            // worst-case row count an authenticated client can ask for in a
            // single request — defense-in-depth against pagination abuse.
            Endpoint\Index::make()
                ->authenticated()
                ->paginate(15, 50)
                ->defaultInclude(['user', 'handler']),

            Endpoint\Show::make()
                ->authenticated()
                ->can('view')
                ->defaultInclude(['user', 'handler']),

            Endpoint\Create::make()
                ->authenticated()
                ->action(function (Context $context) {
                    $actor = $context->getActor();
                    $actor->assertRegistered();
                    $actor->assertCan('verified.request');

                    // Admin kill-switch: when intake is closed, reject every
                    // new request even if the actor has the permission.
                    if (! (bool) $this->settings->get('ramon-verified.requests_open', true)) {
                        throw new ValidationException([
                            'status' => $this->translator->trans('ramon-verified.api.requests_closed'),
                        ]);
                    }

                    if ((bool) $actor->is_verified) {
                        throw new ValidationException([
                            'status' => $this->translator->trans('ramon-verified.api.already_verified'),
                        ]);
                    }

                    $existing = VerificationRequest::query()
                        ->where('user_id', $actor->id)
                        ->where('status', VerificationRequest::STATUS_PENDING)
                        ->first();

                    if ($existing) {
                        throw new ValidationException([
                            'status' => $this->translator->trans('ramon-verified.api.already_pending'),
                        ]);
                    }

                    $body = $context->body();
                    $attributes = (array) ($body['data']['attributes'] ?? []);

                    $reason        = isset($attributes['reason']) ? trim((string) $attributes['reason']) : null;
                    $documentType  = isset($attributes['documentType']) ? trim((string) $attributes['documentType']) : null;
                    $documentPath  = isset($attributes['documentPath']) ? trim((string) $attributes['documentPath']) : null;

                    if ($reason !== null && mb_strlen($reason) > 1000) {
                        $reason = mb_substr($reason, 0, 1000);
                    }
                    if ($documentType !== null && mb_strlen($documentType) > 32) {
                        $documentType = mb_substr($documentType, 0, 32);
                    }

                    // Validate `documentType` against the admin-configured
                    // allowlist. Anything outside the list is dropped to
                    // null — silent rather than throwing because the field
                    // is technically optional. Prevents the audit trail
                    // being used as a covert sidechannel (audit F3) and
                    // mirrors the tier-id resolution pattern in
                    // VerifyUserController::resolveTierId.
                    if ($documentType !== null && $documentType !== '') {
                        $documentType = $this->resolveDocumentType($documentType);
                    }

                    $documentRequired = (bool) $this->settings->get('ramon-verified.require_document');

                    // The token's shape AND the backing file's presence are
                    // both validated here (audit N4). A pure shape-check
                    // would let a stale token (file purged by an aged-out
                    // sweep between upload and submit) persist as a row
                    // pointing at nothing — admin downloads would 404.
                    $documentIsLive = $documentPath !== null
                        && $documentPath !== ''
                        && $this->isOwnedDocument($actor, $documentPath)
                        && $this->documentFileExists($actor, $documentPath);

                    if ($documentRequired) {
                        if (! $documentIsLive) {
                            throw new ValidationException([
                                'documentPath' => $this->translator->trans('ramon-verified.api.document_required'),
                            ]);
                        }
                    } else {
                        if (! $documentIsLive) {
                            $documentPath = null;
                        }
                    }

                    $request = new VerificationRequest();
                    $request->user_id = (int) $actor->id;
                    $request->status = VerificationRequest::STATUS_PENDING;
                    $request->reason = $reason ?: null;
                    $request->document_type = $documentType ?: null;
                    $request->document_path = $documentPath ?: null;
                    $request->created_at = Carbon::now();
                    $request->updated_at = Carbon::now();
                    $request->save();

                    return $request;
                }),

            Endpoint\Delete::make()
                ->authenticated()
                ->can('delete'),

            Endpoint\Endpoint::make('verified.approve')
                ->route('POST', '/{id}/approve')
                ->admin()
                ->action(function (Context $context) {
                    $actor = $context->getActor();

                    /** @var VerificationRequest|null $request */
                    $request = VerificationRequest::query()->find($context->modelId);

                    if (! $request) {
                        throw new ValidationException([
                            'id' => $this->translator->trans('ramon-verified.api.not_found'),
                        ]);
                    }

                    /** @var User|null $user */
                    $user = User::query()->find($request->user_id);
                    if (! $user) {
                        throw new ValidationException([
                            'user' => $this->translator->trans('ramon-verified.api.user_missing'),
                        ]);
                    }

                    $now = Carbon::now();

                    $request->status     = VerificationRequest::STATUS_APPROVED;
                    $request->handled_by = (int) $actor->id;
                    $request->handled_at = $now;
                    $request->updated_at = $now;
                    $request->admin_note = $this->extractNote($context);
                    $request->save();

                    $user->is_verified   = true;
                    $user->verified_at   = $now;
                    $user->verified_by   = (int) $actor->id;
                    $user->verified_tier = $this->resolveTierFromContext($context);
                    $user->save();

                    $this->retention->onRequestHandled($request);

                    $this->events->dispatch(new UserVerified($user, $actor));

                    return $request;
                }),

            Endpoint\Endpoint::make('verified.reject')
                ->route('POST', '/{id}/reject')
                ->admin()
                ->action(function (Context $context) {
                    $actor = $context->getActor();

                    /** @var VerificationRequest|null $request */
                    $request = VerificationRequest::query()->find($context->modelId);

                    if (! $request) {
                        throw new ValidationException([
                            'id' => $this->translator->trans('ramon-verified.api.not_found'),
                        ]);
                    }

                    $now = Carbon::now();

                    $request->status     = VerificationRequest::STATUS_REJECTED;
                    $request->handled_by = (int) $actor->id;
                    $request->handled_at = $now;
                    $request->updated_at = $now;
                    $request->admin_note = $this->extractNote($context);
                    $request->save();

                    $this->retention->onRequestHandled($request);

                    return $request;
                }),

            Endpoint\Endpoint::make('verified.revoke')
                ->route('POST', '/{id}/revoke')
                ->admin()
                ->action(function (Context $context) {
                    $actor = $context->getActor();

                    /** @var VerificationRequest|null $request */
                    $request = VerificationRequest::query()->find($context->modelId);

                    if (! $request) {
                        throw new ValidationException([
                            'id' => $this->translator->trans('ramon-verified.api.not_found'),
                        ]);
                    }

                    /** @var User|null $user */
                    $user = User::query()->find($request->user_id);

                    $now = Carbon::now();

                    $request->status     = VerificationRequest::STATUS_REJECTED;
                    $request->handled_by = (int) $actor->id;
                    $request->handled_at = $now;
                    $request->updated_at = $now;
                    $request->admin_note = $this->extractNote($context, $this->translator->trans('ramon-verified.api.revoked_default_note'));
                    $request->save();

                    if ($user) {
                        $user->is_verified   = false;
                        $user->verified_at   = null;
                        $user->verified_by   = null;
                        $user->verified_tier = null;
                        $user->save();
                    }

                    $this->retention->onRequestHandled($request);

                    return $request;
                }),
        ];
    }

    public function fields(): array
    {
        // SECURITY INVARIANT (audit N9 + CLAUDE.md §7):
        //
        // None of the fields below declare `->writable(...)`. That alone
        // means "field is NOT writable from the JSON:API client" — but
        // ONLY when the endpoint actions follow the default code path
        // that enforces `assertFieldsWritable`. The custom `->action(fn)`
        // closures registered on the Create endpoint REPLACE that default
        // action and read only three named body keys (`reason`,
        // `documentType`, `documentPath`); every other field is
        // server-derived.
        //
        // If you ever register `Endpoint\Update::make(...)` or refactor
        // the Create action to call `setValues($context, $data)`, you
        // MUST decorate every writable field individually — there is NO
        // implicit allowlist. To make the intent explicit, server-only
        // fields below carry `->writable(fn() => false)`, which no-ops
        // today but documents the contract for future readers.
        $serverOnly = fn () => false;

        return [
            Schema\Str::make('status')->writable($serverOnly),
            Schema\Str::make('documentType')
                ->property('document_type')
                ->nullable(),
            // SECURITY: `documentPath` resolves to a virtual URL the owner
            // can't actually download (DownloadDocumentController is
            // admin-only). Exposing it to the owner only leaks the upload
            // existence + file extension — gate the field to admins to
            // match the download-controller posture and avoid the leak
            // entirely (audit F14).
            Schema\Str::make('documentPath')
                ->property('document_path')
                ->nullable()
                ->visible(fn (VerificationRequest $request, Context $context) =>
                    $context->getActor()->isAdmin()),
            Schema\Str::make('reason')->nullable(),
            // SECURITY NOTE: `adminNote` is intentionally readable by the
            // request owner (so they see the rejection reason). If your forum
            // uses this field for INTERNAL moderator-only memos, restrict it:
            //   ->visible(fn (VerificationRequest $r, Context $ctx) =>
            //       $ctx->getActor()->isAdmin())
            // and surface user-facing feedback through a separate field.
            Schema\Str::make('adminNote')
                ->property('admin_note')
                ->nullable()
                ->writable($serverOnly),
            Schema\DateTime::make('createdAt')
                ->property('created_at')
                ->writable($serverOnly),
            Schema\DateTime::make('updatedAt')
                ->property('updated_at')
                ->writable($serverOnly),
            Schema\DateTime::make('handledAt')
                ->property('handled_at')
                ->nullable()
                ->writable($serverOnly),
            Schema\Relationship\ToOne::make('user')
                ->type('users')
                ->includable(),
            Schema\Relationship\ToOne::make('handler')
                ->type('users')
                ->includable(),
        ];
    }

    public function sorts(): array
    {
        return [
            SortColumn::make('createdAt')->column('created_at'),
            SortColumn::make('updatedAt')->column('updated_at'),
            SortColumn::make('status'),
        ];
    }

    protected function isOwnedDocument(User $user, string $path): bool
    {
        // Delegate to the single hardened resolver — never re-implement the
        // checks (CLAUDE.md §13 / audit F6). The resolver covers traversal,
        // stream wrappers, null bytes, and integer-id strictness.
        return $this->pathResolver->isOwnedDocumentToken($path, (int) $user->id);
    }

    /**
     * Liveness check on a `documentPath` token — confirms the backing
     * file actually exists on disk under the user's verified-documents
     * directory. Used by the Create action to refuse stale tokens whose
     * file was swept by an unrelated upload (audit N4).
     */
    protected function documentFileExists(User $user, string $path): bool
    {
        $absolute = $this->pathResolver->resolveAbsolute($path, (int) $user->id);
        return $absolute !== null && is_file($absolute);
    }

    /**
     * Drop a submitted `documentType` value if it isn't in the admin's
     * configured allowlist. Returns null when no allowlist is configured
     * (admin emptied the setting) so the audit row isn't blocked entirely
     * — mirrors the tier-id resolution shape elsewhere.
     */
    protected function resolveDocumentType(string $requested): ?string
    {
        $configured = $this->settings->get('ramon-verified.document_types');
        $list       = is_string($configured) ? json_decode($configured, true) : null;

        if (! is_array($list) || empty($list)) {
            // No allowlist configured — allow the request through with the
            // submitted value rather than failing the request silently.
            return $requested;
        }

        $valid = [];
        foreach ($list as $row) {
            if (! is_array($row)) continue;
            $id = isset($row['id']) ? trim((string) $row['id']) : '';
            if ($id !== '') $valid[mb_substr($id, 0, 32)] = true;
        }

        return isset($valid[$requested]) ? $requested : null;
    }

    protected function extractNote(Context $context, ?string $default = null): ?string
    {
        $body = $context->body();
        $note = $body['meta']['adminNote'] ?? $body['data']['attributes']['adminNote'] ?? null;

        if (! is_string($note)) {
            return $default;
        }

        $note = trim($note);

        if ($note === '') {
            return $default;
        }

        return mb_substr($note, 0, 1000);
    }

    /**
     * Pull the requested tier id from the approval body and map it onto a
     * configured tier. Falls back to the default (`blue`) when the admin
     * didn't pick one or picked one that no longer exists.
     */
    protected function resolveTierFromContext(Context $context): ?string
    {
        $body = $context->body();
        $requested = $body['meta']['tier'] ?? $body['data']['attributes']['tier'] ?? null;
        $requested = is_string($requested) ? trim($requested) : null;

        $tiers = TierConfig::fromSettings($this->settings);
        if (empty($tiers)) return null;

        if ($requested) {
            $found = TierConfig::findById($tiers, $requested);
            if ($found) return $found['id'];
        }

        $fallback = TierConfig::findById($tiers, TierConfig::DEFAULT_TIER_ID) ?? $tiers[0];
        return $fallback['id'];
    }
}
