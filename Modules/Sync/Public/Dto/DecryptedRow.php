<?php

declare(strict_types=1);

namespace Modules\Sync\Public\Dto;

use ArrayAccess;
use LogicException;

/**
 * @implements ArrayAccess<string, mixed>
 *
 * @link ../../../../.docs/features/sync/sensitive-columns-at-rest.md
 */
final readonly class DecryptedRow implements ArrayAccess
{
    /**
     * @param  array<string, mixed>  $values
     * @param  list<string>  $unreadable
     */
    public function __construct(
        private array $values,
        private array $unreadable,
    ) {}

    // The fact the empty string used to stand in for. A registered column no
    // epoch opens comes back blank, and a column whose plaintext is genuinely
    // blank comes back the same way — this is the only thing separating them.
    public function isUnreadable(string $field): bool
    {
        return in_array($field, $this->unreadable, true);
    }

    public function hasUnreadable(): bool
    {
        return $this->unreadable !== [];
    }

    public function offsetExists(mixed $offset): bool
    {
        return array_key_exists($offset, $this->values);
    }

    public function offsetGet(mixed $offset): mixed
    {
        return $this->values[$offset] ?? null;
    }

    public function offsetSet(mixed $offset, mixed $value): void
    {
        throw new LogicException('DecryptedRow is read-only: decrypt again rather than editing what came back.');
    }

    public function offsetUnset(mixed $offset): void
    {
        throw new LogicException('DecryptedRow is read-only: decrypt again rather than editing what came back.');
    }
}
