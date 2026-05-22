<?php

namespace Ramon\Verified;

use Flarum\Api\Context;
use Flarum\Api\Endpoint;
use Flarum\Api\Resource\ForumResource;
use Flarum\Api\Resource\UserResource;
use Flarum\Api\Schema;
use Flarum\Extend;
use Flarum\Foundation\Paths;
use Flarum\Http\UrlGenerator;
use Flarum\Settings\Event\Saving as SettingsSaving;
use Flarum\User\Event\AvatarDeleting;
use Flarum\User\Event\AvatarSaving;
use Flarum\User\User;
use League\Flysystem\Visibility;
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
use Ramon\Verified\Documents\DocumentPathResolver;
use Ramon\Verified\Event\UserVerified;
use Ramon\Verified\Listener\EnforceAvatarLock;
use Ramon\Verified\Listener\PurgeDocumentOnRequestDelete;
use Ramon\Verified\Listener\PurgeDocumentsOnUserDelete;
use Ramon\Verified\Listener\SanitizeTiersOnSave;
use Ramon\Verified\Listener\SendNotificationWhenUserIsVerified;
use Ramon\Verified\Models\UserVerification;
use Ramon\Verified\Models\VerificationRequest;
use Ramon\Verified\Notification\UserVerifiedBlueprint;

return [
    (new Extend\Filesystem())
        ->disk(DocumentPathResolver::DISK, function (Paths $paths, UrlGenerator $url) {
            return [
                'root'       => rtrim($paths->storage, '/\\').DIRECTORY_SEPARATOR.'verified-documents',
                'visibility' => Visibility::PRIVATE,
            ];
        }),

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
        ->default('ramon-verified.document_retention', 'keep')
        ->default('ramon-verified.document_retention_days', 30)
        ->default('ramon-verified.encryption_public_key', '')
        ->default('ramon-verified.lock_avatar', false)
        ->default('ramon-verified.show_tooltip', true)
        ->default('ramon-verified.badge_svg_path', '')
        ->default('ramon-verified.badge_svg_content', '')
        ->default('ramon-verified.badge_size', '1.2')
        ->default('ramon-verified.document_types', '[{"id":"rg","label":"RG"},{"id":"cpf","label":"CPF"},{"id":"passport","label":"Passport"},{"id":"driver","label":"Driver\'s license"},{"id":"other","label":"Other"}]')
        ->default('ramon-verified.tiers', '[{"id":"blue","label":"Verificado","color":"#1d9bf0","description":"Esta conta tem a <strong>identidade verificada</strong>.","learnMoreUrl":"","autoGroups":[]},{"id":"gold","label":"Ouro","color":"#d4af37","description":"Conta de organização com <strong>identidade verificada</strong>.","learnMoreUrl":"","autoGroups":[]},{"id":"partner","label":"Parceiro","color":"#9b59b6","description":"Conta afiliada ou parceira oficial com <strong>identidade verificada</strong>.","learnMoreUrl":"","autoGroups":[]}]')
        ->serializeToForum('ramonVerifiedShowTooltip',     'ramon-verified.show_tooltip',     'boolval')
        ->serializeToForum('ramonVerifiedBadgeSvgPath',    'ramon-verified.badge_svg_path')
        ->serializeToForum('ramonVerifiedBadgeSvgContent', 'ramon-verified.badge_svg_content', function ($raw) {
            if (! is_string($raw) || $raw === '') return '';
            return strlen($raw) <= UploadBadgeSvgController::INLINE_SVG_THRESHOLD ? $raw : '';
        })
        ->serializeToForum('ramonVerifiedBadgeSize',       'ramon-verified.badge_size')
        ->serializeToForum('ramonVerifiedTiers', 'ramon-verified.tiers', [\Ramon\Verified\TierConfig::class, 'parseForFrontend']),

    (new Extend\Model(User::class))
        ->hasOne('verification', UserVerification::class, 'user_id')
        ->hasMany('verificationRequests', VerificationRequest::class, 'user_id'),

    (new Extend\ApiResource(UserResource::class))
        ->fields(UserResourceFields::class)
        ->endpoint(
            [Endpoint\Index::class, Endpoint\Show::class],
            fn (Endpoint\Endpoint $endpoint) => $endpoint->eagerLoad('verification')
        ),

    (new Extend\ApiResource(ForumResource::class))
        ->fields(\Ramon\Verified\Api\ForumResourceFields::class),

    new Extend\ApiResource(VerificationRequestResource::class),

    (new Extend\Policy())
        ->modelPolicy(VerificationRequest::class, VerificationRequestPolicy::class),

    (new Extend\Notification())
        ->type(UserVerifiedBlueprint::class, ['alert', 'email']),

    (new Extend\View())
        ->namespace('ramon-verified', __DIR__.'/views'),

    (new Extend\Event())
        ->listen(AvatarSaving::class, EnforceAvatarLock::class)
        ->listen(AvatarDeleting::class, EnforceAvatarLock::class)
        ->listen(UserVerified::class, SendNotificationWhenUserIsVerified::class)
        ->listen('eloquent.deleting: '.VerificationRequest::class, PurgeDocumentOnRequestDelete::class)
        ->listen('eloquent.deleting: '.User::class, PurgeDocumentsOnUserDelete::class)
        ->listen(SettingsSaving::class, SanitizeTiersOnSave::class),

    (new Extend\ThrottleApi())
        ->set('verified.actions', VerifiedActionsThrottler::class),

    (new Extend\Console())
        ->command(PurgeDocumentsCommand::class)
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
        ->get('/verified/encryption/status',            'verified.encryption.status',   EncryptionStatusController::class)
        ->post('/verified/encryption/generate-keypair', 'verified.encryption.generate', GenerateKeypairController::class),

    ...(class_exists(\Flarum\Gdpr\Extend\UserData::class) ? [
        (new \Flarum\Gdpr\Extend\UserData())
            ->addType(\Ramon\Verified\Gdpr\VerifiedDocuments::class),
    ] : []),
];
