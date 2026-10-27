<?php

declare(strict_types=1);

namespace Ramon\Verified\Tests\Support;

use Flarum\Settings\SettingsRepositoryInterface;

/**
 * Configurações em memória, para os testes unitários que só leem `get()`.
 */
final class ArraySettings implements SettingsRepositoryInterface
{
    /** @param array<string, mixed> $values */
    public function __construct(
        private array $values = []
    ) {
    }

    public function all(): array
    {
        return $this->values;
    }

    public function get(string $key, mixed $default = null): mixed
    {
        return $this->values[$key] ?? $default;
    }

    public function set(string $key, mixed $value): void
    {
        $this->values[$key] = $value;
    }

    public function delete(string $keyLike): void
    {
        unset($this->values[$keyLike]);
    }
}
