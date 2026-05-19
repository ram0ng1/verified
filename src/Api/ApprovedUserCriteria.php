<?php

namespace Ramon\Verified\Api;

/**
 * Critérios saneados do `ListApprovedUsersController`. Encapsula o parsing
 * da query string em um value object com defaults estáveis.
 */
final class ApprovedUserCriteria
{
    public function __construct(
        public readonly string $q,
        public readonly string $tierFilter,
        public readonly int $offset,
        public readonly int $limit
    ) {
    }

    /**
     * @param array<string, mixed> $params
     */
    public static function fromQuery(array $params, int $defaultLimit, int $maxLimit): self
    {
        $limit = (int) ($params['limit'] ?? $defaultLimit);
        if ($limit <= 0) $limit = $defaultLimit;
        if ($limit > $maxLimit) $limit = $maxLimit;

        return new self(
            q:          isset($params['q']) ? trim((string) $params['q']) : '',
            tierFilter: isset($params['tier']) ? trim((string) $params['tier']) : '',
            offset:     max(0, (int) ($params['offset'] ?? 0)),
            limit:      $limit
        );
    }
}
