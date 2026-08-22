<?php

declare(strict_types=1);

namespace Modules\Counterparties\Public\Queries;

use Illuminate\Contracts\Session\Session;
use Illuminate\Database\DatabaseManager;
use Illuminate\Support\Collection;
use Modules\Core\Public\Support\LocaleCollator;
use Modules\Sync\Public\Services\SensitiveColumnCodec;
use stdClass;

final readonly class CounterpartyDisplayName
{
    public function __construct(
        private DatabaseManager $db,
        private SensitiveColumnCodec $codec,
        private Session $session,
    ) {}

    /**
     * @return Collection<int, object{id: int, display_name: string}&stdClass>
     */
    public function forUser(int $userId): Collection
    {
        $rows = $this->db->connection()
            ->table('counterparties')
            ->where('user_id', $userId)
            ->orderBy('id')
            ->get(['id', 'display_name'])
            ->map(fn (stdClass $row): stdClass => (object) [
                'id' => is_numeric($row->id) ? (int) $row->id : 0,
                'display_name' => $this->decrypt($row->display_name ?? null, $userId),
            ]);

        return $rows
            ->sort(static fn (stdClass $a, stdClass $b): int => LocaleCollator::compare(
                $a->display_name,
                $b->display_name,
            ))
            ->values();
    }

    /**
     * @param  list<int>  $ids
     * @return array<int, string> counterparty id => display name
     */
    public function forIds(array $ids, int $userId): array
    {
        if ($ids === []) {
            return [];
        }

        $names = [];
        $rows = $this->db->connection()
            ->table('counterparties')
            ->whereIn('id', $ids)
            ->where('user_id', $userId)
            ->get(['id', 'display_name']);

        foreach ($rows as $row) {
            /** @var stdClass $row */
            $names[is_numeric($row->id) ? (int) $row->id : 0] = $this->decrypt($row->display_name ?? null, $userId);
        }

        return $names;
    }

    // Never an ORDER BY on this column: it is ciphertext at rest once the user
    // turns encryption on, so the database would sort the blobs. The empty
    // value short-circuits because decrypting nothing can only answer nothing.
    private function decrypt(mixed $stored, int $userId): string
    {
        if (! is_string($stored) || $stored === '') {
            return '';
        }

        return $this->codec->decryptValue('counterparties', 'display_name', $stored, $userId, $this->session)['value'];
    }
}
