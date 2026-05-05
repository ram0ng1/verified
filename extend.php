<?php

namespace Ramon\Verified;

use Flarum\Api\Context;
use Flarum\Api\Resource\ForumResource;
use Flarum\Api\Resource\UserResource;
use Flarum\Api\Schema;
use Flarum\Extend;
use Flarum\User\Event\AvatarSaving;
use Flarum\User\User;
use Ramon\Verified\Access\VerificationRequestPolicy;
use Ramon\Verified\Api\Controller\DeleteBadgeSvgController;
use Ramon\Verified\Api\Controller\DownloadDocumentController;
use Ramon\Verified\Api\Controller\UploadBadgeSvgController;
use Ramon\Verified\Api\Controller\UploadDocumentController;
use Ramon\Verified\Api\Controller\VerifyUserController;
use Ramon\Verified\Api\Resource\VerificationRequestResource;
use Ramon\Verified\Api\UserResourceFields;
use Ramon\Verified\Event\UserVerified;
use Ramon\Verified\Listener\EnforceAvatarLock;
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
        ->default('ramon-verified.require_document', false)
        ->default('ramon-verified.lock_avatar', false)
        ->default('ramon-verified.custom_color_enabled', false)
        ->default('ramon-verified.show_tooltip', true)
        ->default('ramon-verified.badge_color', '')
        ->default('ramon-verified.badge_svg_path', '')
        ->default('ramon-verified.badge_svg_content', '')
        ->default('ramon-verified.badge_size', '1.2')
        ->serializeToForum('ramonVerifiedRequireDocument',    'ramon-verified.require_document',     'boolval')
        ->serializeToForum('ramonVerifiedLockAvatar',         'ramon-verified.lock_avatar',          'boolval')
        ->serializeToForum('ramonVerifiedCustomColorEnabled', 'ramon-verified.custom_color_enabled', 'boolval')
        ->serializeToForum('ramonVerifiedShowTooltip',        'ramon-verified.show_tooltip',         'boolval')
        ->serializeToForum('ramonVerifiedBadgeColor',         'ramon-verified.badge_color')
        ->serializeToForum('ramonVerifiedBadgeSvgPath',       'ramon-verified.badge_svg_path')
        ->serializeToForum('ramonVerifiedBadgeSvgContent',    'ramon-verified.badge_svg_content')
        ->serializeToForum('ramonVerifiedBadgeSize',          'ramon-verified.badge_size'),

    (new Extend\Model(User::class))
        ->cast('is_verified', 'bool')
        ->cast('verified_at', 'datetime')
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
        ->listen(AvatarSaving::class, EnforceAvatarLock::class)
        ->listen(UserVerified::class, SendNotificationWhenUserIsVerified::class),

    (new Extend\Routes('api'))
        ->post('/verified/documents', 'verified.documents.upload', UploadDocumentController::class)
        ->get('/verified/documents/{id:[0-9]+}', 'verified.documents.show', DownloadDocumentController::class)
        ->post('/verified/badge-svg',   'verified.badge_svg.upload', UploadBadgeSvgController::class)
        ->delete('/verified/badge-svg', 'verified.badge_svg.delete', DeleteBadgeSvgController::class)
        ->post('/verified/users/{id:[0-9]+}/verify',   'verified.users.verify',   VerifyUserController::class)
        ->delete('/verified/users/{id:[0-9]+}/verify', 'verified.users.unverify', VerifyUserController::class),
];
