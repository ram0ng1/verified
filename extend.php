<?php

namespace Ramon\Verified;

use Flarum\Api\Context;
use Flarum\Api\Resource\ForumResource;
use Flarum\Api\Resource\UserResource;
use Flarum\Api\Schema;
use Flarum\Extend;
use Flarum\User\Event\AvatarDeleting;
use Flarum\User\Event\AvatarSaving;
use Flarum\User\Event\Deleting as UserDeleting;
use Flarum\User\User;
use Ramon\Verified\Access\VerificationRequestPolicy;
use Ramon\Verified\Api\Controller\DeleteBadgeSvgController;
use Ramon\Verified\Api\Controller\DownloadDocumentController;
use Ramon\Verified\Api\Controller\EncryptionStatusController;
use Ramon\Verified\Api\Controller\GenerateKeypairController;
use Ramon\Verified\Api\Controller\ListApprovedUsersController;
use Ramon\Verified\Api\Controller\UploadBadgeSvgController;
use Ramon\Verified\Api\Controller\UploadDocumentController;
use Ramon\Verified\Api\Controller\VerifyUserController;
use Ramon\Verified\Api\Resource\VerificationRequestResource;
use Ramon\Verified\Api\Throttler\VerifiedActionsThrottler;
use Ramon\Verified\Api\UserResourceFields;
use Ramon\Verified\Console\PurgeDocumentsCommand;
use Ramon\Verified\Event\UserVerified;
use Ramon\Verified\Listener\EnforceAvatarLock;
use Ramon\Verified\Listener\PurgeDocumentOnRequestDelete;
use Ramon\Verified\Listener\PurgeDocumentsOnUserDelete;
use Ramon\Verified\Listener\SendNotificationWhenUserIsVerified;
use Ramon\Verified\Models\VerificationRequest;
use Ramon\Verified\Notification\UserVerifiedBlueprint;

return [
    (new Extend\Frontend('forum'))
        ->css(__DIR__.'/less/forum.less')
        ->js(__DIR__.'/js/dist/forum.js'),

    (new Extend\Frontend('admin'))
        ->css(__DIR__.'/less/admin.less')
        ->js(__DIR__.'/js/dist/admin.js'),

    new Extend\Locales(__DIR__.'/locale'),

    (new Extend\Settings())
        ->default('ramon-verified.requests_open', true)
        ->default('ramon-verified.require_document', false)
        // Document retention for handled requests. Modes:
        //   keep              — never auto-delete (default; safest for forums
        //                       that need the file as proof of verification)
        //   delete_immediate  — wipe the file the moment a request is approved
        //                       or rejected
        //   delete_after_days — wipe N days after handling (set via
        //                       ramon-verified.document_retention_days);
        //                       enforced by a daily scheduled purge
        ->default('ramon-verified.document_retention', 'keep')
        ->default('ramon-verified.document_retention_days', 30)
        // Encryption is opt-in. Storing the public key alone is enough to
        // start encrypting on upload — but new uploads stay readable only
        // while the matching private key is present in config.php under
        // `verified-private-key`. The value here is the base64
        // public key; an empty string means encryption is off.
        ->default('ramon-verified.encryption_public_key', '')
        ->default('ramon-verified.lock_avatar', false)
        ->default('ramon-verified.show_tooltip', true)
        ->default('ramon-verified.badge_svg_path', '')
        ->default('ramon-verified.badge_svg_content', '')
        ->default('ramon-verified.badge_size', '1.2')
        // Document types — JSON-encoded `[{id, label}, ...]`. Admin can add /
        // remove / edit. The default keeps the original Brazilian-context list
        // (RG/CPF/Passport/CNH/Other); it's what shipped before this setting
        // existed, and forums outside Brazil can rewrite it freely.
        ->default('ramon-verified.document_types', '[{"id":"rg","label":"RG"},{"id":"cpf","label":"CPF"},{"id":"passport","label":"Passport"},{"id":"driver","label":"Driver\'s license"},{"id":"other","label":"Other"}]')
        // Tier definitions — JSON-encoded list. Each tier:
        //   { id, label, color (hex), description, learnMoreUrl, autoGroups }
        // Default seeds three X-style tiers (blue / gold / partner). The admin
        // edits this list from the panel — adding new tiers, removing seeded
        // ones, or pointing each tier's "learn more" link wherever their
        // forum's verification policy lives.
        ->default('ramon-verified.tiers', '[{"id":"blue","label":"Verificado","color":"#1d9bf0","description":"Esta conta tem a <strong>identidade verificada</strong>.","learnMoreUrl":"","autoGroups":[]},{"id":"gold","label":"Ouro","color":"#d4af37","description":"Conta de organização com <strong>identidade verificada</strong>.","learnMoreUrl":"","autoGroups":[]},{"id":"partner","label":"Parceiro","color":"#9b59b6","description":"Conta afiliada ou parceira oficial com <strong>identidade verificada</strong>.","learnMoreUrl":"","autoGroups":[]}]')
        ->serializeToForum('ramonVerifiedRequestsOpen',       'ramon-verified.requests_open',        'boolval')
        ->serializeToForum('ramonVerifiedRequireDocument',    'ramon-verified.require_document',     'boolval')
        ->serializeToForum('ramonVerifiedDocumentRetention',  'ramon-verified.document_retention')
        ->serializeToForum('ramonVerifiedDocumentRetentionDays', 'ramon-verified.document_retention_days', 'intval')
        ->serializeToForum('ramonVerifiedLockAvatar',         'ramon-verified.lock_avatar',          'boolval')
        ->serializeToForum('ramonVerifiedShowTooltip',        'ramon-verified.show_tooltip',         'boolval')
        ->serializeToForum('ramonVerifiedBadgeSvgPath',       'ramon-verified.badge_svg_path')
        // Defense-in-depth: the upload controller already sanitises before
        // writing, but a DB restore, admin tinker, or a future migration
        // could land unsanitised SVG in this setting. Re-sanitise on every
        // read so the forum frontend never sees raw `<script>` / event
        // handlers (CLAUDE.md §21 + audit F18). `throwOnInvalid=false`
        // degrades to "" — frontend `getBadgeSvg.ts` falls back to its
        // built-in default badge when the attribute is empty.
        ->serializeToForum('ramonVerifiedBadgeSvgContent',    'ramon-verified.badge_svg_content', fn ($raw) =>
            is_string($raw) && $raw !== '' ? UploadBadgeSvgController::sanitizeSvg($raw, false) : ''
        )
        ->serializeToForum('ramonVerifiedBadgeSize',          'ramon-verified.badge_size')
        ->serializeToForum('ramonVerifiedDocumentTypes',      'ramon-verified.document_types', function ($raw) {
            // Parse the JSON config into a proper array. Bail to an empty list
            // on malformed input — the JS side falls back to its built-in
            // defaults when the attribute is empty.
            $list = is_string($raw) ? json_decode($raw, true) : null;

            if (! is_array($list)) return [];

            $clean = [];
            foreach ($list as $row) {
                if (! is_array($row)) continue;
                $id    = isset($row['id']) ? trim((string) $row['id']) : '';
                $label = isset($row['label']) ? trim((string) $row['label']) : '';
                if ($id === '' || $label === '') continue;
                $clean[] = [
                    'id'    => mb_substr($id, 0, 32),
                    'label' => mb_substr($label, 0, 64),
                ];
            }
            return $clean;
        })
        ->serializeToForum('ramonVerifiedTiers', 'ramon-verified.tiers', [\Ramon\Verified\TierConfig::class, 'parseForFrontend']),

    (new Extend\Model(User::class))
        ->cast('is_verified', 'bool')
        ->cast('verified_at', 'datetime')
        ->cast('verified_tier', 'string')
        ->hasMany('verificationRequests', VerificationRequest::class, 'user_id'),

    (new Extend\ApiResource(UserResource::class))
        ->fields(UserResourceFields::class),

    (new Extend\ApiResource(ForumResource::class))
        ->fields(fn () => [
            Schema\Boolean::make('canVerifyUsers')
                ->get(fn (object $model, Context $context) => $context->getActor()->hasPermission('verified.verifyUsers')),
        ]),

    new Extend\ApiResource(VerificationRequestResource::class),

    (new Extend\Policy())
        ->modelPolicy(VerificationRequest::class, VerificationRequestPolicy::class),

    (new Extend\Notification())
        ->type(UserVerifiedBlueprint::class, ['alert', 'email']),

    (new Extend\View())
        ->namespace('ramon-verified', __DIR__.'/views'),

    (new Extend\Event())
        // Avatar lock fires on BOTH save AND delete. Listening only to save
        // (the original wiring) left a verified user able to wipe their
        // avatar via `DELETE /api/users/{id}/avatar` even with the lock on
        // — defeating the impersonation defense (audit N5). Both events
        // share `(User $user, User $actor)`, so one listener handles both.
        ->listen(AvatarSaving::class, EnforceAvatarLock::class)
        ->listen(AvatarDeleting::class, EnforceAvatarLock::class)
        ->listen(UserVerified::class, SendNotificationWhenUserIsVerified::class)
        // Hard-delete cleanup: when a VerificationRequest row is deleted (a
        // user dropping their pending request, an admin purge, etc.) wipe the
        // backing document file too. Without this hook, an attacker could
        // upload + submit + delete in a loop, leaving every uploaded file
        // orphaned on disk. Eloquent dispatches model events as string-keyed
        // names (`eloquent.deleting: ClassName`), not the global event class.
        ->listen('eloquent.deleting: '.VerificationRequest::class, PurgeDocumentOnRequestDelete::class)
        // User hard-delete cleanup, BOTH paths:
        //   1) `UserResource::deleting()` dispatches the high-level
        //      `User\Event\Deleting` event — covered by listener (a).
        //   2) Direct `$user->delete()` from tinker / a future CLI command /
        //      a non-API admin tool does NOT raise the high-level event. The
        //      FK `onDelete('cascade')` then silently drops the request rows
        //      WITHOUT firing the per-row listener above — files orphan in
        //      `storage/verified-documents/{userId}/`. Audit N6 adds a
        //      second listener on the Eloquent `deleting` model event to
        //      close that path. Both listeners are idempotent
        //      (`purgeAllForUser` no-ops on missing directories), so dual
        //      registration is safe.
        ->listen(UserDeleting::class, PurgeDocumentsOnUserDelete::class)
        ->listen('eloquent.deleting: '.User::class, PurgeDocumentsOnUserDelete::class),

    // Per-actor rate limiting for mutating endpoints. Token-authenticated
    // requests still bypass this (CLAUDE.md §16) — that's fine for
    // service-to-service traffic but not for typical user/admin sessions.
    // Audit F2.
    (new Extend\ThrottleApi())
        ->set('verified.actions', VerifiedActionsThrottler::class),

    (new Extend\Console())
        ->command(PurgeDocumentsCommand::class)
        // Run nightly. The command itself is a no-op unless the retention
        // mode is `delete_after_days`, so this is safe to register
        // unconditionally — admins on `keep` or `delete_immediate` pay
        // nothing for it.
        ->schedule(PurgeDocumentsCommand::class, function (\Illuminate\Console\Scheduling\Event $event) {
            $event->dailyAt('03:30');
        }),

    (new Extend\Routes('api'))
        ->post('/verified/documents', 'verified.documents.upload', UploadDocumentController::class)
        ->get('/verified/documents/{id:[0-9]+}', 'verified.documents.show', DownloadDocumentController::class)
        ->post('/verified/badge-svg',   'verified.badge_svg.upload', UploadBadgeSvgController::class)
        ->delete('/verified/badge-svg', 'verified.badge_svg.delete', DeleteBadgeSvgController::class)
        ->post('/verified/users/{id:[0-9]+}/verify',   'verified.users.verify',   VerifyUserController::class)
        ->delete('/verified/users/{id:[0-9]+}/verify', 'verified.users.unverify', VerifyUserController::class)
        ->get('/verified/approved-users', 'verified.approved.list', ListApprovedUsersController::class)
        ->get('/verified/encryption/status',          'verified.encryption.status',   EncryptionStatusController::class)
        ->post('/verified/encryption/generate-keypair', 'verified.encryption.generate', GenerateKeypairController::class),

    // flarum/gdpr integration — register a DataType so user-data export,
    // anonymization, and erasure flows know about our verification requests
    // and the document files we store on disk. Spread is empty when GDPR
    // isn't installed, so this stays a true no-op for forums without it.
    ...(class_exists(\Flarum\Gdpr\Extend\UserData::class) ? [
        (new \Flarum\Gdpr\Extend\UserData())
            ->addType(\Ramon\Verified\Gdpr\VerifiedDocuments::class),
    ] : []),
];
