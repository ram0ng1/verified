<?php

namespace Ramon\Verified\Api;

use Flarum\Api\Context;
use Flarum\Api\Schema;
use Flarum\Settings\SettingsRepositoryInterface;

/**
 * Fields para o `ForumResource` que SÓ devem chegar a usuários autenticados
 * Em vez de `serializeToForum` (que ship pra todo guest),
 * estes campos têm `->visible(actor not guest)` e ficam fora do payload
 * anônimo. Reduz peso por page-load e evita exposição de toggles
 * operacionais a quem nunca abrirá o modal.
 */
class ForumResourceFields
{
    public function __construct(
        protected SettingsRepositoryInterface $settings
    ) {
    }

    public function __invoke(): array
    {
        $loggedIn = fn (object $model, Context $context) => ! $context->getActor()->isGuest();

        return [
            Schema\Boolean::make('canVerifyUsers')
                ->get(fn (object $model, Context $context) =>
                    $context->getActor()->hasPermission('verified.verifyUsers')),

            Schema\Boolean::make('ramonVerifiedRequestsOpen')
                ->get(fn () => (bool) $this->settings->get('ramon-verified.requests_open', true))
                ->visible($loggedIn),

            Schema\Boolean::make('ramonVerifiedRequireDocument')
                ->get(fn () => (bool) $this->settings->get('ramon-verified.require_document', false))
                ->visible($loggedIn),

            Schema\Boolean::make('ramonVerifiedLockAvatar')
                ->get(fn () => (bool) $this->settings->get('ramon-verified.lock_avatar', false))
                ->visible($loggedIn),

            Schema\Arr::make('ramonVerifiedDocumentTypes')
                ->get(fn () => $this->parseDocumentTypes())
                ->visible($loggedIn),
        ];
    }

    /**
     * Parse + saneamento do JSON de tipos de documento. Espelha o cast
     * anterior em `extend.php` (mesma forma de descarte de linhas
     * malformadas, mesmo cap em id/label).
     *
     * @return array<int, array{id: string, label: string}>
     */
    private function parseDocumentTypes(): array
    {
        $raw = $this->settings->get('ramon-verified.document_types');
        $list = is_string($raw) ? json_decode($raw, true) : null;
        if (! is_array($list)) return [];

        $clean = [];
        foreach ($list as $row) {
            if (! is_array($row)) continue;
            $id = isset($row['id']) ? trim((string) $row['id']) : '';
            $label = isset($row['label']) ? trim((string) $row['label']) : '';
            if ($id === '' || $label === '') continue;
            $clean[] = [
                'id'    => mb_substr($id, 0, 32),
                'label' => mb_substr($label, 0, 64),
            ];
        }

        return $clean;
    }
}
