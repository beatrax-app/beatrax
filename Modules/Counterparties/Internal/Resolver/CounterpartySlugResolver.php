<?php

declare(strict_types=1);

namespace Modules\Counterparties\Internal\Resolver;

use Illuminate\Database\DatabaseManager;
use Modules\Core\Public\Services\SessionFactory;
use Modules\Sync\Public\Services\SensitiveColumnCodec;

/**
 * @link ../../../../.docs/features/counterparties/resolution-chain.md
 */
final readonly class CounterpartySlugResolver
{
    public function __construct(
        private DatabaseManager $db,
        private SensitiveColumnCodec $codec,
        // A factory, not the session: resolving a session builds the encrypter,
        // and Artisan constructs this class merely to list a console command.
        private SessionFactory $session,
    ) {}

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
    // stored name is decrypted before comparing: a raw ciphertext comparison
    // treats every re-import as a different holder and fragments one merchant
    // across bol, bol-2, bol-3 forever.
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

    // Never throws: an undecryptable value comes back as raw ciphertext,
    // which fails the identity comparison and falls through to suffixing.
    private function decryptDisplayName(string $stored, int $userId): string
    {
        return $this->codec->decryptValue('counterparties', 'display_name', $stored, $userId, ($this->session)())['value'];
    }

    // 128 is the width of the slug column carrying the (user_id, slug) UNIQUE.
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
