<?php

declare(strict_types=1);

namespace Modules\Search\Internal\Services;

use Illuminate\Database\DatabaseManager;
use Illuminate\Database\Query\Builder;
use Modules\Core\Models\User;
use Modules\Core\Public\Services\EncryptionMigrationService;
use Modules\Core\Public\Services\SessionFactory;
use Modules\Counterparties\Public\Support\CounterpartyDefaultName;
use Modules\Ledger\Public\Support\CategoryDisplayName;
use Modules\Ledger\Public\Support\CategoryPathName;
use Modules\Search\Public\Contracts\SearchResultsProvider;
use Modules\Search\Public\Enums\SearchEntityKind;
use Modules\Sync\Public\Services\SensitiveColumnCodec;
use stdClass;

// Name-only search across the palette's entity types. Two of them cannot be
// matched in SQL: a counterparty's display_name is ciphertext once encryption
// is on, and neither table's stored name is reliably the name on screen — the
// rows the app had to name itself store English and display a translation.
/**
 * @phpstan-import-type PaletteEntity from SearchResultsProvider
 */
final readonly class EntityNameSearch
{
    private const int COUNTERPARTY_MATCH_LIMIT = 3;

    // How far ahead of the match limit the walk reads. A keystroke whose
    // matches are near the front of the table stops inside the first window,
    // and one that matches nothing pays a statement per window instead of a
    // single one holding the reader's whole merchant list.
    private const int COUNTERPARTY_SCAN_CHUNK = 250;

    private const int ENTITY_MATCH_LIMIT = 3;

    public function __construct(
        private DatabaseManager $db,
        private SensitiveColumnCodec $codec,
        private SessionFactory $session,
        private EncryptionMigrationService $encryptionService,
    ) {}

    /**
     * @return list<PaletteEntity>
     */
    public function query(User $user, string $q): array
    {
        if ($q === '') {
            return [];
        }

        return [
            ...$this->counterpartyMatches($user, $q),
            ...$this->categoryMatches($user, $q),
            ...$this->ownedNameMatches($user, $q, 'goals', SearchEntityKind::Goal, '/goals'),
            ...$this->ownedNameMatches($user, $q, 'pots', SearchEntityKind::Pot, '/pots'),
            ...$this->recurringMatches($user, $q),
        ];
    }

    // The cap lives in PHP because ciphertext has no name predicate SQL can
    // widen, so the rows arrive id-ordered a window at a time and the break
    // below abandons the walk. A get() had already paid for the whole table by
    // then; this pays for the window the third name was found in.
    /**
     * @link ../../../../.docs/architecture/reads-bounded-by-the-user.md#8--every-counterparty-per-palette-keystroke
     * @link ../../../../.docs/features/counterparties/resolution-chain.md#the-apps-own-words-for-a-row-it-had-to-name
     *
     * @return list<PaletteEntity>
     */
    private function counterpartyMatches(User $user, string $q): array
    {
        $encryptionEnabled = $this->encryptionService->isEnabled($user->id);
        $rows = $this->db->connection()
            ->table('counterparties')
            ->where('user_id', $user->id)
            ->select(['id', 'display_name', 'slug', 'metadata'])
            ->lazyById(self::COUNTERPARTY_SCAN_CHUNK);

        $needle = mb_strtolower($q);
        $results = [];
        foreach ($rows as $row) {
            if (count($results) >= self::COUNTERPARTY_MATCH_LIMIT) {
                break;
            }

            $stored = $this->decryptedDisplayName($user, $row, $encryptionEnabled);
            if ($stored === '') {
                continue;
            }

            // Both words reach the match, and only the reader's is shown. The
            // stored English is what a screenshot, an export and a support
            // thread all say, so typing it has to keep working.
            $label = CounterpartyDefaultName::resolve($stored, $row->metadata ?? null);
            if (! str_contains(mb_strtolower($label), $needle) && ! str_contains(mb_strtolower($stored), $needle)) {
                continue;
            }

            $slug = is_string($row->slug) ? $row->slug : '';
            $results[] = [
                'id' => $this->intField($row, 'id'),
                'type' => SearchEntityKind::Counterparty->value,
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
     * @return list<PaletteEntity>
     */
    private function categoryMatches(User $user, string $q): array
    {
        $slugs = self::slugsDisplayingSubstring($q);

        $rows = CategoryPathName::joinParent($this->db->connection()->table('categories'), $user->id, 'categories', 'parent_categories')
            ->where(function (Builder $scope) use ($user): void {
                // Own rows OR global (seeded, null-user) categories —
                // every user sees the shared seeded set.
                $scope->where('categories.user_id', $user->id)->orWhereNull('categories.user_id');
            })
            ->where(function (Builder $match) use ($q, $slugs): void {
                LikeNeedle::contains($match, 'categories.name', $q);
                if ($slugs !== []) {
                    $match->orWhere(function (Builder $translated) use ($slugs): void {
                        $translated->where('categories.name_is_default', true)->whereIn('categories.slug', $slugs);
                    });
                }
            })
            ->orderBy('categories.id')
            ->limit(self::ENTITY_MATCH_LIMIT)
            ->get(['categories.id', ...CategoryPathName::columns('categories', 'parent_categories')]);

        // Disambiguated across what this hands back, not the whole tree: the
        // palette's row bound is measured, and widening it to number the labels
        // would cost every keystroke the category table. A colliding pair
        // matches one term and sorts by id, so the bounded set holds both.
        $paths = [];
        foreach ($rows as $row) {
            $paths[$this->intField($row, 'id')] = CategoryPathName::fromRow($row) ?? '';
        }

        $results = [];
        foreach (CategoryPathName::distinct($paths) as $id => $label) {
            $results[] = [
                'id' => $id,
                'type' => SearchEntityKind::Category->value,
                'label' => $label,
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
    // and a fixed section URL. The $kind/$url pair is all that varies.
    /**
     * @return list<PaletteEntity>
     */
    private function ownedNameMatches(User $user, string $q, string $table, SearchEntityKind $kind, string $url): array
    {
        $query = $this->db->connection()
            ->table($table)
            ->where('user_id', $user->id);

        LikeNeedle::contains($query, 'name', $q);

        $rows = $query
            ->limit(self::ENTITY_MATCH_LIMIT)
            ->get(['id', 'name']);

        $results = [];
        foreach ($rows as $row) {
            $results[] = [
                'id' => $this->intField($row, 'id'),
                'type' => $kind->value,
                'label' => is_string($row->name) ? $row->name : '',
                'url' => $url,
            ];
        }

        return $results;
    }

    /**
     * @return list<PaletteEntity>
     */
    private function recurringMatches(User $user, string $q): array
    {
        $rows = $this->db->connection()
            ->table('recurring_series')
            ->where('user_id', $user->id)
            ->where(function (Builder $scope) use ($q): void {
                LikeNeedle::contains($scope, 'detected_name', $q);
                LikeNeedle::orContains($scope, 'display_name_override', $q);
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
                'type' => SearchEntityKind::Recurring->value,
                'label' => $override !== '' ? $override : $detected,
                'url' => '/recurring/series/'.$id,
            ];
        }

        return $results;
    }

    private function intField(stdClass $row, string $key): int
    {
        $value = $row->{$key} ?? null;

        return is_numeric($value) ? (int) $value : 0;
    }
}
