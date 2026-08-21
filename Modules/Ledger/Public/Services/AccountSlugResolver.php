<?php

declare(strict_types=1);

namespace Modules\Ledger\Public\Services;

use Illuminate\Database\DatabaseManager;
use Modules\Core\Public\Support\UniqueSlug;

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
        return UniqueSlug::walk(
            self::slugify($name),
            fn (string $slug): bool => ! $this->isTaken($userId, $slug),
        );
    }

    public static function slugify(string $name): string
    {
        return UniqueSlug::slugify($name, self::FALLBACK);
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
