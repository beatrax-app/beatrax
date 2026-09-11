<?php

declare(strict_types=1);

namespace Modules\Ledger\Public\Services;

use Illuminate\Database\DatabaseManager;
use Modules\Core\Public\Concerns\CoercesScalars;
use Modules\Ledger\Public\Dto\FingerprintTuple;
use Throwable;

// Lives in Public so Core's DoctorCommand can depend on it without crossing
// into Ledger Internal, and returns plain PHP values so that command builds its
// own ProbeResult. Mirrors FtsHealthCheck, which answers the same kind of
// question about the other derived thing a row carries.
final readonly class FingerprintHealthCheck
{
    use CoercesScalars;

    // Reading every column of a five-year ledger at once cost 52 MB against a
    // 128 MB phone ceiling, which is why the re-derive sweep streams. The same
    // eight columns are all this needs, and it stops at the first fifty.
    private const int REPORT_AT_MOST = 50;

    /** @var list<string> */
    private const array READ = [
        'id', 'user_id', 'account_id', 'posted_at', 'booked_at', 'amount_minor',
        'currency', 'counterparty_normalized', 'occurrence_ordinal', 'fingerprint',
    ];

    public function __construct(
        private DatabaseManager $db,
        private FingerprintComposer $composer,
    ) {}

    public function label(): string
    {
        return 'transaction fingerprints';
    }

    /**
     * @return 'ok'|'warning'
     */
    public function severity(): string
    {
        return $this->result()['severity'];
    }

    public function message(): string
    {
        return $this->result()['message'];
    }

    /**
     * @return array{severity: 'ok'|'warning', message: string}
     */
    private function result(): array
    {
        try {
            $drifted = $this->drifted();
        } catch (Throwable) {
            return ['severity' => 'warning', 'message' => 'could not be read — run php artisan migrate'];
        }

        [$checked, $ids] = $drifted;

        if ($ids === []) {
            return ['severity' => 'ok', 'message' => "{$checked} at the current version — each describes its own row"];
        }

        // Not a blocker: the rows are all there and the ledger adds up. What a
        // drifted digest costs is dedup, so the next import of the same
        // statement books a second copy of these.
        $shown = implode(', ', array_slice($ids, 0, 10));
        $more = count($ids) > 10 ? ' and more' : '';

        return [
            'severity' => 'warning',
            'message' => count($ids)." of {$checked} no longer describe their row — re-import would duplicate them (ids {$shown}{$more}); run beatrax:rederive-fingerprints",
        ];
    }

    /**
     * @return array{0: int, 1: list<int>}
     */
    private function drifted(): array
    {
        $checked = 0;
        $ids = [];

        $this->db->connection()->table('transactions')
            ->where('fingerprint_version', $this->composer->version())
            ->orderBy('id')
            ->select(self::READ)
            ->each(function (object $row) use (&$checked, &$ids): bool {
                $checked++;

                if ($this->describesItsRow($row)) {
                    return true;
                }

                $ids[] = self::toInt($row->id ?? null);

                return count($ids) < self::REPORT_AT_MOST;
            });

        return [$checked, $ids];
    }

    private function describesItsRow(object $row): bool
    {
        $composed = $this->composer->composeTuple(new FingerprintTuple(
            userId: self::toInt($row->user_id ?? null),
            accountId: self::toInt($row->account_id ?? null),
            postedAtDate: substr(self::toString($row->posted_at ?? null), 0, 10),
            bookedAtDateTime: self::toString($row->booked_at ?? null),
            amountMinor: self::toInt($row->amount_minor ?? null),
            currency: self::toString($row->currency ?? null),
            counterpartyNormalized: self::toString($row->counterparty_normalized ?? null),
            occurrenceOrdinal: self::toInt($row->occurrence_ordinal ?? null),
        ));

        return $composed === self::toString($row->fingerprint ?? null);
    }
}
