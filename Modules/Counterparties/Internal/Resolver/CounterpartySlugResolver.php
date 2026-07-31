<?php

declare(strict_types=1);

namespace Modules\Counterparties\Internal\Resolver;

use Illuminate\Database\DatabaseManager;
use Modules\Core\Public\Services\SessionFactory;
use Modules\Sync\Public\Services\SensitiveColumnCodec;

// Owns the slug half of counterparty resolution: derive a base slug from a
// display name, then walk numbered suffixes until one is free for this user.
// Extracted from CounterpartyResolverService so that class stays under the
// method-count ceiling; the slug question is a cohesive slice of its own.
/**
 * @link ../../../../.docs/features/counterparties/architecture.md
 */
final readonly class CounterpartySlugResolver
{
    public function __construct(
        private DatabaseManager $db,
        private SensitiveColumnCodec $codec,
        // A factory, not the session itself: resolving a session builds the
        // encrypter, and the host is reachable from a console command that
        // Artisan constructs merely to list it.
        private SessionFactory $session,
    ) {}

    // The stored display_name is decrypted before the identity comparison so
    // an already-resolved counterparty is never wrongly treated as "taken by
    // a different name" just because the column is now ciphertext.
    public function resolveUnique(int $userId, string $displayName): string
    {
        $baseSlug = $this->slugify($displayName);
        if ($this->slugIsFreeFor($userId, $baseSlug, $displayName)) {
            return $baseSlug;
        }

        $suffix = 2;
        while (! $this->slugIsFreeFor($userId, $baseSlug.'-'.$suffix, $displayName)) {
            $suffix++;
        }

        return $baseSlug.'-'.$suffix;
    }

    // Free means unused, or already held by this same counterparty. The
    // stored name is decrypted before comparing so a row whose column is now
    // ciphertext is not mistaken for a different holder. The base slug and
    // every numbered candidate ask this one question.
    private function slugIsFreeFor(int $userId, string $slug, string $displayName): bool
    {
        $existing = $this->db->connection()
            ->table('counterparties')
            ->where('user_id', $userId)
            ->where('slug', $slug)
            ->value('display_name');

        return $existing === null
            || (is_string($existing) && $this->decryptDisplayName($existing, $userId) === $displayName);
    }

    // Never throws — an undecryptable value falls back to the raw ciphertext
    // string, which simply fails the identity comparison above and falls
    // through to slug suffixing.
    private function decryptDisplayName(string $stored, int $userId): string
    {
        return $this->codec->decryptValue('counterparties', 'display_name', $stored, $userId, ($this->session)())['value'];
    }

    // Strips punctuation/accents to a lowercase ASCII approximation and
    // collapses whitespace/underscores into single `-` separators; bounded
    // to the column's 128-char UNIQUE-index width.
    private function slugify(string $value): string
    {
        $ascii = (string) iconv('UTF-8', 'ASCII//TRANSLIT//IGNORE', $value);
        $lower = strtolower($ascii);
        $cleaned = preg_replace('/[^a-z0-9]+/', '-', $lower) ?? '';
        $trimmed = trim($cleaned, '-');

        if ($trimmed === '') {
            return 'counterparty';
        }

        return substr($trimmed, 0, 128);
    }
}
