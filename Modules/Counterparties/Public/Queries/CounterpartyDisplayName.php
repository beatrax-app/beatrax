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
    // One id-ordered window of the picker at a time. Small enough that the
    // rows held alongside the built list stay a rounding error, wide enough
    // that a household-sized merchant list is still one statement.
    private const int PICKER_CHUNK = 500;

    public function __construct(
        private DatabaseManager $db,
        private SensitiveColumnCodec $codec,
        private Session $session,
    ) {}

    // Streamed rather than fetched, because the picker this fills already holds
    // one object per counterparty and the raw result set beside it doubled that
    // for no reader-visible reason; the keyset walk is what keeps the peak to
    // one chunk of rows rather than the whole table.
    /**
     * @link ../../../../.docs/architecture/reads-bounded-by-the-user.md#7--every-counterparty-on-every-transaction-detail-render
     *
     * @return Collection<int, object{id: int, display_name: string}&stdClass>
     */
    public function forUser(int $userId): Collection
    {
        $rows = [];
        $stream = $this->db->connection()
            ->table('counterparties')
            ->where('user_id', $userId)
            ->select(['id', 'display_name', 'metadata'])
            ->lazyById(self::PICKER_CHUNK);

        foreach ($stream as $row) {
            $rows[] = (object) [
                'id' => is_numeric($row->id) ? (int) $row->id : 0,
                'display_name' => $this->readable($row, $userId),
            ];
        }

        /** @var Collection<int, object{id: int, display_name: string}&stdClass> */
        return new Collection(LocaleCollator::sorted(
            $rows,
            static fn (stdClass $row): string => $row->display_name,
        ));
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
