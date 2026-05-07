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
        protected DocumentRetention $retention
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
            Endpoint\Index::make()
                ->authenticated()
                ->paginate()
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

                    $documentRequired = (bool) $this->settings->get('ramon-verified.require_document');

                    if ($documentRequired) {
                        if (! $documentPath || ! $this->isOwnedDocument($actor, $documentPath)) {
                            throw new ValidationException([
                                'documentPath' => $this->translator->trans('ramon-verified.api.document_required'),
                            ]);
                        }
                    } else {
                        if ($documentPath && ! $this->isOwnedDocument($actor, $documentPath)) {
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
                ->authenticated()
                ->action(function (Context $context) {
                    $actor = $context->getActor();
                    $actor->assertAdmin();

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
                ->authenticated()
                ->action(function (Context $context) {
                    $actor = $context->getActor();
                    $actor->assertAdmin();

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
                ->authenticated()
                ->action(function (Context $context) {
                    $actor = $context->getActor();
                    $actor->assertAdmin();

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
        return [
            Schema\Str::make('status'),
            Schema\Str::make('documentType')
                ->property('document_type')
                ->nullable(),
            Schema\Str::make('documentPath')
                ->property('document_path')
                ->nullable()
                ->visible(function (VerificationRequest $request, Context $context) {
                    $actor = $context->getActor();

                    return $actor->isAdmin() || $actor->id === (int) $request->user_id;
                }),
            Schema\Str::make('reason')->nullable(),
            Schema\Str::make('adminNote')
                ->property('admin_note')
                ->nullable(),
            Schema\DateTime::make('createdAt')
                ->property('created_at'),
            Schema\DateTime::make('updatedAt')
                ->property('updated_at'),
            Schema\DateTime::make('handledAt')
                ->property('handled_at')
                ->nullable(),
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
        $expectedPrefix = '/assets/verified/'.((int) $user->id).'/';

        if (! str_starts_with($path, $expectedPrefix)) {
            return false;
        }

        if (str_contains($path, '..')) {
            return false;
        }

        return true;
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
