<?php

use Flarum\Database\Migration;
use Flarum\Group\Group;

/**
 * Permissão dedicada para auto-revogação do badge. Default em MEMBER_ID
 * (§4 — permissões custom nunca defaultam para GUEST) preserva o fluxo
 * existente em que o usuário verificado clica "trocar avatar" e o
 * `EnforceAvatarLock` o força a passar pelo self-revoke do badge.
 *
 * Admins que quiserem que toda revogação passe pelo painel administrativo
 * só precisam remover essa permissão do grupo Members.
 */
return Migration::addPermissions([
    'verified.selfRevoke' => Group::MEMBER_ID,
]);
