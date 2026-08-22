<?php

declare(strict_types=1);

namespace Modules\Search\Internal\Services;

use Illuminate\Database\DatabaseManager;
use Illuminate\Database\Query\Builder;
use Modules\Core\Models\User;
use Modules\Core\Public\Services\EncryptionMigrationService;
use Modules\Core\Public\Services\SessionFactory;
use Modules\Ledger\Public\Support\CategoryDisplayName;
use Modules\Sync\Public\Services\SensitiveColumnCodec;
use stdClass;

// Name-only search across the palette's entity types. Two of them cannot be
// matched in SQL: a counterparty's display_name is ciphertext once encryption
// is on, and a category's stored name is not the name on screen.
final class EntityNameSearch
{
    private const COUNTERPARTY_MATCH_LIMIT = 3;

    private const ENTITY_MATCH_LIMIT = 3;

    public function __construct(
        private readonly DatabaseManager $db,
        private readonly SensitiveColumnCodec $codec,
        private readonly SessionFactory $session,
        private readonly EncryptionMigrationService $encryptionService,
    ) {}

    /**
     * @return list<array{id: int, type: string, label: string, url: string}>
     */
    public function query(User $user, string $q): array
    {
        if ($q === '') {
            return [];
        }

        return [
            ...$this->counterpartyMatches($user, $q),
            ...$this->categoryMatches($user, $q),
            ...$this->ownedNameMatches($user, $q, 'goals', 'goal', '/goals'),
            ...$this->ownedNameMatches($user, $q, 'pots', 'pot', '/pots'),
            ...$this->recurringMatches($user, $q),
        ];
    }

    // Fetch the user's counterparties — a naturally small, per-user
    // bounded set — decrypt display_name per row, and substring-match
    // in PHP; the cap moves from SQL to PHP since matching now happens
    // post-decrypt.
    /**
     * @return list<array{id: int, type: string, label: string, url: string}>
     */
    private function counterpartyMatches(User $user, string $q): array
    {
        $encryptionEnabled = $this->encryptionService->isEnabled($user->id);
        $rows = $this->db->connection()
            ->table('counterparties')
            ->where('user_id', $user->id)
            ->orderBy('id')
            ->get(['id', 'display_name', 'slug']);

        $needle = mb_strtolower($q);
        $results = [];
        foreach ($rows as $row) {
            if (count($results) >= self::COUNTERPARTY_MATCH_LIMIT) {
                break;
            }

            $label = $this->decryptedDisplayName($user, $row, $encryptionEnabled);
            if ($label === '' || ! str_contains(mb_strtolower($label), $needle)) {
                continue;
            }

            $slug = is_string($row->slug) ? $row->slug : '';
            $results[] = [
                'id' => $this->intField($row, 'id'),
                'type' => 'counterparty',
                'label' => $label,
                'url' => '/counterparties/'.$slug,
            ];
        }

        return $results;
    }

    // Empty string means either a blank stored name or a ciphertext row
    // skipped under encryption (rekey/epoch gap, or a locked app-lock) —
    // both suppress the substring match rather than matching a blob.
    private function decryptedDisplayName(User $user, stdClass $row, bool $encryptionEnabled): string
    {
        $stored = is_string($row->display_name) ? $row->display_name : '';
        if ($stored === '') {
            return '';
        }

        $result = $this->codec->decryptValue('counterparties', 'display_name', $stored, $user->id, ($this->session)());
        if ($encryptionEnabled && ! $result['decrypted']) {
            return '';
        }

        return $result['value'];
    }

    // Matching the stored name alone inverts what the reader sees, because a
    // default category stores English and displays a translation. The reader's
    // wording is not a column, so the slugs it matches are named in PHP first
    // and the row match — and the cap with it — stays one bounded statement.
    /**
     * @link ../../../../.docs/features/ledger/category-display-names.md
     *
     * @return list<array{id: int, type: string, label: string, url: string}>
     */
    private function categoryMatches(User $user, string $q): array
    {
        $slugs = self::slugsDisplayingSubstring($q);

        $rows = $this->db->connection()
            ->table('categories')
            ->where(function (Builder $scope) use ($user): void {
                // Own rows OR global (seeded, null-user) categories —
                // every user sees the shared seeded set.
                $scope->where('user_id', $user->id)->orWhereNull('user_id');
            })
            ->where(function (Builder $match) use ($q, $slugs): void {
                $match->where('name', 'LIKE', $this->likePattern($q));
                if ($slugs !== []) {
                    $match->orWhere(function (Builder $translated) use ($slugs): void {
                        $translated->where('name_is_default', true)->whereIn('slug', $slugs);
                    });
                }
            })
            ->orderBy('id')
            ->limit(self::ENTITY_MATCH_LIMIT)
            ->get(['id', ...CategoryDisplayName::bareColumns()]);

        $results = [];
        foreach ($rows as $row) {
            $id = $this->intField($row, 'id');
            $results[] = [
                'id' => $id,
                'type' => 'category',
                'label' => CategoryDisplayName::fromRow($row) ?? '',
                'url' => '/transactions?category='.$id,
            ];
        }

        return $results;
    }

    /**
     * @return list<string>
     */
    private static function slugsDisplayingSubstring(string $q): array
    {
        $needle = mb_strtolower($q);

        $slugs = [];
        foreach (CategoryDisplayName::displayNamesBySlug() as $slug => $displayed) {
            if (str_contains(mb_strtolower($displayed), $needle)) {
                $slugs[] = $slug;
            }
        }

        return $slugs;
    }

    // Goals and pots share the same shape: own-rows-only, LIKE on `name`,
    // and a fixed section URL. The $type/$url pair is all that varies.
    /**
     * @return list<array{id: int, type: string, label: string, url: string}>
     */
    private function ownedNameMatches(User $user, string $q, string $table, string $type, string $url): array
    {
        $rows = $this->db->connection()
            ->table($table)
            ->where('user_id', $user->id)
            ->where('name', 'LIKE', $this->likePattern($q))
            ->limit(self::ENTITY_MATCH_LIMIT)
            ->get(['id', 'name']);

        $results = [];
        foreach ($rows as $row) {
            $results[] = [
                'id' => $this->intField($row, 'id'),
                'type' => $type,
                'label' => is_string($row->name) ? $row->name : '',
                'url' => $url,
            ];
        }

        return $results;
    }

    /**
     * @return list<array{id: int, type: string, label: string, url: string}>
     */
    private function recurringMatches(User $user, string $q): array
    {
        $like = $this->likePattern($q);
        $rows = $this->db->connection()
            ->table('recurring_series')
            ->where('user_id', $user->id)
            ->where(function (Builder $scope) use ($like): void {
                $scope->where('detected_name', 'LIKE', $like)
                    ->orWhere('display_name_override', 'LIKE', $like);
            })
            ->limit(self::ENTITY_MATCH_LIMIT)
            ->get(['id', 'detected_name', 'display_name_override']);

        $results = [];
        foreach ($rows as $row) {
            $id = $this->intField($row, 'id');
            $override = is_string($row->display_name_override) ? $row->display_name_override : '';
            $detected = is_string($row->detected_name) ? $row->detected_name : '';
            $results[] = [
                'id' => $id,
                'type' => 'recurring',
                'label' => $override !== '' ? $override : $detected,
                'url' => '/recurring/series/'.$id,
            ];
        }

        return $results;
    }

    private function likePattern(string $q): string
    {
        return '%'.str_replace(['%', '_'], ['\\%', '\\_'], $q).'%';
    }

    private function intField(stdClass $row, string $key): int
    {
        $value = $row->{$key} ?? null;

        return is_numeric($value) ? (int) $value : 0;
    }
}
