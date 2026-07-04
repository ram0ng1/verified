<?php

/**
 * Stubs de análise estática para a integração opcional com a `ramon/audit`.
 *
 * As classes reais vêm com a extensão `ramon/audit` e só são acionadas sob
 * `class_exists` no extend.php (§43). A audit não é dependência composer da
 * verified, então o PHPStan da CI não a enxerga — este arquivo é referenciado
 * por `scanFiles` no phpstan.neon para o PHPStan validar o listener de
 * auditoria sem exigir o pacote. Não é autoloadado em runtime.
 *
 * Mantenha as assinaturas em sincronia com a `ramon/audit`.
 */

namespace Ramon\Audit\Activity;

class ActivityRecord
{
}

class Recorder
{
    /**
     * @param array<string, mixed> $payload
     */
    public function record(
        string $type,
        ?\Flarum\User\User $actor = null,
        ?object $target = null,
        array $payload = [],
        ?\Flarum\User\User $subject = null,
        ?string $severity = null,
        ?string $targetLabel = null
    ): ActivityRecord {
    }
}

namespace Ramon\Audit\Extend;

class ActivityType
{
    /**
     * @param array{severity?: string, label?: string} $options
     */
    public function register(string $type, array $options = []): self
    {
    }
}
