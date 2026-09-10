<?php

declare(strict_types=1);

use Illuminate\Database\Connection;
use Modules\Core\Database\Support\ModuleMigration;
use Modules\Ledger\Public\Dto\FingerprintTuple;
use Modules\Ledger\Public\Services\FingerprintComposer;

// The migration importer told two same-day rows apart by adding the ordinal to
// booked_at as seconds, which wrapped at 86,400 rows of one kind and stated a
// time of day no export ever gave. The ordinal has had its own column since
// 2026_09_10_000010; these rows are the ones promoted before it did.
return new class extends ModuleMigration
{
    private const string PROMOTED = 'migration_%';

    public function up(): void
    {
        if (! $this->schema()->hasTable('transactions')
            || ! $this->schema()->hasColumn('transactions', 'occurrence_ordinal')) {
            return;
        }

        $composer = app(FingerprintComposer::class);

        foreach ($this->promotedRowsCarryingAnOffset() as $row) {
            $seconds = $this->offsetSeconds($row);

            // A row whose booked_at is not its posting date plus a whole number
            // of seconds within the same day was not written by the offset this
            // undoes. Left exactly as found rather than guessed at.
            if ($seconds === null) {
                continue;
            }

            $this->rewrite($row, $seconds, $composer);
        }
    }

    // Irreversible by choice: the offset it replaced is unrecoverable once two
    // rows share a booked_at, and re-deriving it would invent the ordering.
    public function down(): void {}

    /**
     * @return iterable<int, object>
     */
    private function promotedRowsCarryingAnOffset(): iterable
    {
        return $this->connection()->table('transactions')
            ->where('source_format', 'like', self::PROMOTED)
            ->where('occurrence_ordinal', 0)
            ->whereRaw("booked_at <> posted_at || ' 00:00:00'")
            ->orderBy('id')
            ->get([
                'id', 'user_id', 'account_id', 'posted_at', 'booked_at',
                'amount_minor', 'currency', 'counterparty_normalized',
            ]);
    }

    private function offsetSeconds(object $row): ?int
    {
        $postedAt = self::toString($row->posted_at ?? null);
        $bookedAt = self::toString($row->booked_at ?? null);

        if ($postedAt === '' || ! str_starts_with($bookedAt, $postedAt.' ')) {
            return null;
        }

        $midnight = strtotime($postedAt.' 00:00:00');
        $booked = strtotime($bookedAt);

        if ($midnight === false || $booked === false) {
            return null;
        }

        $seconds = $booked - $midnight;

        return $seconds >= 0 && $seconds < 86400 ? $seconds : null;
    }

    private function rewrite(object $row, int $seconds, FingerprintComposer $composer): void
    {
        $postedAt = self::toString($row->posted_at ?? null);
        $midnight = $postedAt.' 00:00:00';

        $fingerprint = $composer->composeTuple(new FingerprintTuple(
            self::toInt($row->user_id ?? null),
            self::toInt($row->account_id ?? null),
            $postedAt,
            $midnight,
            self::toInt($row->amount_minor ?? null),
            self::toString($row->currency ?? null),
            self::toString($row->counterparty_normalized ?? null),
            $seconds,
        ));

        $this->connection()->table('transactions')
            ->where('id', self::toInt($row->id ?? null))
            ->update([
                'booked_at' => $midnight,
                'occurrence_ordinal' => $seconds,
                'fingerprint' => $fingerprint,
            ]);
    }

    private function connection(): Connection
    {
        return $this->db()->connection();
    }

    private static function toInt(mixed $value): int
    {
        return is_numeric($value) ? (int) $value : 0;
    }

    private static function toString(mixed $value): string
    {
        return is_string($value) ? $value : '';
    }
};
