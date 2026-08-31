<?php

declare(strict_types=1);

namespace Modules\Counterparties\Public\Queries;

use Illuminate\Contracts\Session\Session;
use Illuminate\Database\DatabaseManager;
use Illuminate\Support\Collection;
use Modules\Core\Public\Support\LocaleCollator;
use Modules\Counterparties\Public\Support\CounterpartyDefaultName;
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
            ->get(['id', 'display_name', 'metadata'])
            ->map(fn (stdClass $row): stdClass => (object) [
                'id' => is_numeric($row->id) ? (int) $row->id : 0,
                'display_name' => $this->readable($row, $userId),
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
            ->get(['id', 'display_name', 'metadata']);

        foreach ($rows as $row) {
            /** @var stdClass $row */
            $names[is_numeric($row->id) ? (int) $row->id : 0] = $this->readable($row, $userId);
        }

        return $names;
    }

    // Both callers hand the same row to the same two steps, and this list
    // feeds a rule form, a report filter and a transaction's own picker, so
    // one of them skipping the second step is one screen still in English.
    private function readable(stdClass $row, int $userId): string
    {
        return CounterpartyDefaultName::resolve(
            $this->decrypt($row->display_name ?? null, $userId),
            $row->metadata ?? null,
        );
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
