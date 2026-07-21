<?php

declare(strict_types=1);

namespace Modules\Search\Internal\Services;

use Illuminate\Contracts\Session\Session;
use Illuminate\Database\DatabaseManager;
use Illuminate\Database\Query\Builder;
use Modules\Core\Models\User;
use Modules\Core\Public\Services\EncryptionMigrationService;
use Modules\Sync\Public\Services\SensitiveColumnCodec;

// Name-only search across palette entity types: counterparties are
// decrypt-then-substring matched (display_name is ciphertext once
// encryption is enabled); categories/goals/pots/recurring stay
// LIKE-matched. Every read goes through the entity's public table.
final class EntityNameSearch
{
    private const COUNTERPARTY_MATCH_LIMIT = 3;

    public function __construct(
        private readonly DatabaseManager $db,
        private readonly SensitiveColumnCodec $codec,
        private readonly Session $session,
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

        $like = '%'.str_replace(['%', '_'], ['\\%', '\\_'], $q).'%';

        /** @var list<array{id: int, type: string, label: string, url: string}> $results */
        $results = [];

        // Fetch the user's counterparties — a naturally small,
        // per-user bounded set — decrypt display_name per row, and
        // substring-match in PHP; the limit(3) cap moves from SQL to
        // PHP since matching now happens post-decrypt.
        $matchCount = 0;
        $encryptionEnabled = $this->encryptionService->isEnabled($user->id);
        $this->db->connection()
            ->table('counterparties')
            ->where('user_id', $user->id)
            ->orderBy('id')
            ->get(['id', 'display_name', 'slug'])
            ->each(function (object $row) use (&$results, &$matchCount, $user, $q, $encryptionEnabled): bool {
                if ($matchCount >= self::COUNTERPARTY_MATCH_LIMIT) {
                    return false;
                }

                $stored = is_string($row->display_name) ? $row->display_name : '';
                if ($stored === '') {
                    return true;
                }

                $result = $this->codec->decryptValue('counterparties', 'display_name', $stored, $user->id, $this->session);

                // A decrypted:false result under encryption is
                // ciphertext (rekey/epoch gap, or a locked app-lock) —
                // skip rather than substring-matching a ciphertext blob.
                if ($encryptionEnabled && ! $result['decrypted']) {
                    return true;
                }

                $displayName = $result['value'];
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

        $this->db->connection()
            ->table('categories')
            ->where(static function (object $q) use ($user): void {
                /** @var Builder $q */
                // Own rows OR global (seeded, null-user) categories —
                // every user sees the shared seeded set.
                $q->where('user_id', $user->id)
                    ->orWhereNull('user_id');
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
