<?php

declare(strict_types=1);

namespace Modules\Ledger\Public\Services;

use Illuminate\Database\DatabaseManager;
use Illuminate\Support\Str;

/**
 * @link ../../../../.docs/features/sync/sensitive-columns-at-rest.md
 */
final readonly class AccountSlugResolver
{
    // Two accounts under one user used to be told apart by appending the tail
    // of the IBAN, which put an account identifier in a column that is
    // plaintext at rest and in every URL built from it. A numeric suffix
    // separates them without carrying anything about the account.
    private const string FALLBACK = 'account';

    public function __construct(private DatabaseManager $db) {}

    public function resolveUnique(int $userId, string $name): string
    {
        $base = self::slugify($name);
        $slug = $base;
        $suffix = 2;

        while ($this->isTaken($userId, $slug)) {
            $slug = $base.'-'.$suffix;
            $suffix++;
        }

        return $slug;
    }

    // A name of nothing but emoji or punctuation clears the length bound and
    // still slugs to the empty string, which no unique index can carry.
    public static function slugify(string $name): string
    {
        $slug = Str::slug($name);

        return $slug === '' ? self::FALLBACK : $slug;
    }

    private function isTaken(int $userId, string $slug): bool
    {
        return $this->db->connection()
            ->table('accounts')
            ->where('user_id', $userId)
            ->where('slug', $slug)
            ->exists();
    }
}
