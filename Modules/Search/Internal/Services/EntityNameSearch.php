<?php

declare(strict_types=1);

namespace Modules\Search\Internal\Services;

use Illuminate\Database\DatabaseManager;
use Illuminate\Database\Query\Builder;
use Modules\Core\Models\User;
use Modules\Core\Public\Services\EncryptionMigrationService;
use Modules\Core\Public\Services\SessionFactory;
use Modules\Sync\Public\Services\SensitiveColumnCodec;
use stdClass;

// Name-only search across palette entity types: counterparties are
// decrypt-then-substring matched (display_name is ciphertext once
// encryption is enabled); categories/goals/pots/recurring stay
// LIKE-matched. Every read goes through the entity's public table.
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
            ...$this->goalMatches($user, $q),
            ...$this->potMatches($user, $q),
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

    /**
     * @return list<array{id: int, type: string, label: string, url: string}>
     */
    private function categoryMatches(User $user, string $q): array
    {
        $rows = $this->db->connection()
            ->table('categories')
            ->where(function (Builder $scope) use ($user): void {
                // Own rows OR global (seeded, null-user) categories —
                // every user sees the shared seeded set.
                $scope->where('user_id', $user->id)->orWhereNull('user_id');
            })
            ->where('name', 'LIKE', $this->likePattern($q))
            ->limit(self::ENTITY_MATCH_LIMIT)
            ->get(['id', 'name', 'slug']);

        $results = [];
        foreach ($rows as $row) {
            $id = $this->intField($row, 'id');
            $results[] = [
                'id' => $id,
                'type' => 'category',
                'label' => is_string($row->name) ? $row->name : '',
                'url' => '/transactions?category='.$id,
            ];
        }

        return $results;
    }

    /**
     * @return list<array{id: int, type: string, label: string, url: string}>
     */
    private function goalMatches(User $user, string $q): array
    {
        $rows = $this->db->connection()
            ->table('goals')
            ->where('user_id', $user->id)
            ->where('name', 'LIKE', $this->likePattern($q))
            ->limit(self::ENTITY_MATCH_LIMIT)
            ->get(['id', 'name']);

        $results = [];
        foreach ($rows as $row) {
            $results[] = [
                'id' => $this->intField($row, 'id'),
                'type' => 'goal',
                'label' => is_string($row->name) ? $row->name : '',
                'url' => '/goals',
            ];
        }

        return $results;
    }

    /**
     * @return list<array{id: int, type: string, label: string, url: string}>
     */
    private function potMatches(User $user, string $q): array
    {
        $rows = $this->db->connection()
            ->table('pots')
            ->where('user_id', $user->id)
            ->where('name', 'LIKE', $this->likePattern($q))
            ->limit(self::ENTITY_MATCH_LIMIT)
            ->get(['id', 'name']);

        $results = [];
        foreach ($rows as $row) {
            $results[] = [
                'id' => $this->intField($row, 'id'),
                'type' => 'pot',
                'label' => is_string($row->name) ? $row->name : '',
                'url' => '/pots',
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
