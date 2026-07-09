<?php

declare(strict_types=1);

namespace Modules\Search\Internal\Services;

use Illuminate\Contracts\Session\Session;
use Illuminate\Database\DatabaseManager;
use Illuminate\Database\Query\Builder;
use Modules\Core\Models\User;
use Modules\Sync\Public\Services\SensitiveColumnCodec;

/**
 * Name-only LIKE search across palette entity types (D-28).
 *
 * Searches: counterparties (display_name + slug), categories (name),
 * recurring series (detected_name + display_name_override), goals (name), pots (name).
 *
 * Each type is capped at 3 results; the order follows the UI-SPEC palette
 * section ordering. All reads go through the entity's public table — never
 * through any module's Internal class (BoundaryArchTest enforcement, T-08-08).
 *
 * Returns a flat list of tagged entity rows ready for the palette "entities"
 * section in PaletteSearchEndpoint.
 */
final class EntityNameSearch
{
    /**
     * Max counterparty matches returned — mirrors every other entity type's
     * `limit(3)` here. Applied in PHP (not SQL) now that the match itself
     * happens post-decrypt (CRYPT-01/D-06).
     */
    private const COUNTERPARTY_MATCH_LIMIT = 3;

    public function __construct(
        private readonly DatabaseManager $db,
        private readonly SensitiveColumnCodec $codec,
        private readonly Session $session,
    ) {}

    /**
     * @return list<array{id: int, type: string, label: string, url: string}>
     */
    public function query(User $user, string $q): array
    {
        if ($q === '') {
            return [];
        }

        $like = '%'.str_replace(['%', '_'], ['\\%', '\\_'], $q).'%';

        /** @var list<array{id: int, type: string, label: string, url: string}> $results */
        $results = [];

        // 1. Counterparties — decrypt-then-substring on display_name (CRYPT-01/
        // D-06). display_name is ciphertext at rest once encryption is
        // enabled, so a raw `where(...,'LIKE',...)` on the stored column can
        // never match plaintext user input. Converted to the decrypt-then-
        // match template (`CounterpartyIndexQuery::forUser()` precedent):
        // fetch the user's counterparties — a naturally small, per-user
        // bounded set, unlike `transactions` — decrypt `display_name` per
        // row, and substring-match in PHP. `decryptValue()` is a documented
        // pass-through no-op when encryption is not enabled, so this stays
        // behavior-identical to the old LIKE for a plaintext user. The
        // limit(3) cap moves from SQL to PHP since matching now happens
        // post-decrypt; `each()` returning false stops iteration early once
        // the cap is hit.
        $matchCount = 0;
        $this->db->connection()
            ->table('counterparties')
            ->where('user_id', $user->id)
            ->orderBy('id')
            ->get(['id', 'display_name', 'slug'])
            ->each(function (object $row) use (&$results, &$matchCount, $user, $q): bool {
                if ($matchCount >= self::COUNTERPARTY_MATCH_LIMIT) {
                    return false;
                }

                $stored = is_string($row->display_name) ? $row->display_name : '';
                if ($stored === '') {
                    return true;
                }

                $displayName = $this->codec->decryptValue('counterparties', 'display_name', $stored, $user->id, $this->session)['value'];
                if (! str_contains(mb_strtolower($displayName), mb_strtolower($q))) {
                    return true;
                }

                $id = is_numeric($row->id) ? (int) $row->id : 0;
                $slug = is_string($row->slug) ? $row->slug : '';
                /** @var list<array{id: int, type: string, label: string, url: string}> $results */
                $results[] = [
                    'id' => $id,
                    'type' => 'counterparty',
                    'label' => $displayName,
                    'url' => '/counterparties/'.$slug,
                ];
                $matchCount++;

                return true;
            });

        // 2. Categories — name LIKE query, result URL: /transactions?category={id}
        $this->db->connection()
            ->table('categories')
            ->where(static function (object $q) use ($user): void {
                /** @var Builder $q */
                $q->where('user_id', $user->id)
                    ->orWhereNull('user_id'); // include global (seeded) categories
            })
            ->where('name', 'LIKE', $like)
            ->limit(3)
            ->get(['id', 'name', 'slug'])
            ->each(static function (object $row) use (&$results): void {
                $id = is_numeric($row->id) ? (int) $row->id : 0;
                $label = is_string($row->name) ? $row->name : '';
                /** @var list<array{id: int, type: string, label: string, url: string}> $results */
                $results[] = [
                    'id' => $id,
                    'type' => 'category',
                    'label' => $label,
                    'url' => '/transactions?category='.$id,
                ];
            });

        // 3. Goals — name LIKE query, result URL: /goals (no detail page)
        $this->db->connection()
            ->table('goals')
            ->where('user_id', $user->id)
            ->where('name', 'LIKE', $like)
            ->limit(3)
            ->get(['id', 'name'])
            ->each(static function (object $row) use (&$results): void {
                $id = is_numeric($row->id) ? (int) $row->id : 0;
                $label = is_string($row->name) ? $row->name : '';
                /** @var list<array{id: int, type: string, label: string, url: string}> $results */
                $results[] = [
                    'id' => $id,
                    'type' => 'goal',
                    'label' => $label,
                    'url' => '/goals',
                ];
            });

        // 4. Pots — name LIKE query, result URL: /pots (no detail page)
        $this->db->connection()
            ->table('pots')
            ->where('user_id', $user->id)
            ->where('name', 'LIKE', $like)
            ->limit(3)
            ->get(['id', 'name'])
            ->each(static function (object $row) use (&$results): void {
                $id = is_numeric($row->id) ? (int) $row->id : 0;
                $label = is_string($row->name) ? $row->name : '';
                /** @var list<array{id: int, type: string, label: string, url: string}> $results */
                $results[] = [
                    'id' => $id,
                    'type' => 'pot',
                    'label' => $label,
                    'url' => '/pots',
                ];
            });

        // 5. Recurring series — detected_name or display_name_override, URL: /recurring/series/{id}
        $this->db->connection()
            ->table('recurring_series')
            ->where('user_id', $user->id)
            ->where(static function (object $q) use ($like): void {
                /** @var Builder $q */
                $q->where('detected_name', 'LIKE', $like)
                    ->orWhere('display_name_override', 'LIKE', $like);
            })
            ->limit(3)
            ->get(['id', 'detected_name', 'display_name_override'])
            ->each(static function (object $row) use (&$results): void {
                $id = is_numeric($row->id) ? (int) $row->id : 0;
                $override = is_string($row->display_name_override) ? $row->display_name_override : '';
                $detected = is_string($row->detected_name) ? $row->detected_name : '';
                $label = $override !== '' ? $override : $detected;
                /** @var list<array{id: int, type: string, label: string, url: string}> $results */
                $results[] = [
                    'id' => $id,
                    'type' => 'recurring',
                    'label' => $label,
                    'url' => '/recurring/series/'.$id,
                ];
            });

        return $results;
    }
}
