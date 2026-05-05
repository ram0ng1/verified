<?php

namespace Ramon\Verified\Notification;

use Carbon\Carbon;
use Carbon\CarbonInterface;
use Flarum\Database\AbstractModel;
use Flarum\Locale\TranslatorInterface;
use Flarum\Notification\AlertableInterface;
use Flarum\Notification\Blueprint\BlueprintInterface;
use Flarum\Notification\MailableInterface;
use Flarum\User\User;

class UserVerifiedBlueprint implements BlueprintInterface, AlertableInterface, MailableInterface
{
    public function __construct(
        public User $user
    ) {
    }

    public function getSubject(): ?AbstractModel
    {
        return $this->user;
    }

    public function getFromUser(): ?User
    {
        return null;
    }

    public function getData(): CarbonInterface
    {
        return Carbon::now();
    }

    public static function getType(): string
    {
        return 'userVerified';
    }

    public static function getSubjectModel(): string
    {
        return User::class;
    }

    public function getEmailViews(): array
    {
        return [
            'text' => 'ramon-verified::emails.plain.verified',
            'html' => 'ramon-verified::emails.html.verified',
        ];
    }

    public function getEmailSubject(TranslatorInterface $translator): string
    {
        return $translator->trans('ramon-verified.email.verified.subject');
    }
}
