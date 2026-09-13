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
    // 128 MB phone ceiling, which is why the re-derive sweep streams, and the
    // same eight columns are all this needs. The ids are capped; the walk is
    // not, because stopping it froze the denominator mid-scan.
    private const int NAME_AT_MOST = 50;

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

        [$checked, $drifting, $ids] = $drifted;

        if ($drifting === 0) {
            return ['severity' => 'ok', 'message' => sprintf('%s at the current version — each describes its own row', $checked)];
        }

        // Not a blocker: the rows are all there and the ledger adds up. What a
        // drifted digest costs is dedup, so the next import of the same
        // statement books a second copy of these.
        $rest = $drifting - count($ids);
        $shown = implode(', ', $ids).($rest > 0 ? sprintf(' (+%d more)', $rest) : '');

        return [
            'severity' => 'warning',
            'message' => $drifting.sprintf(' of %s no longer describe their row — re-import would duplicate them (ids %s); run beatrax:rederive-fingerprints', $checked, $shown),
        ];
    }

    // Both numbers describe the same population because the same pass produces
    // them: the sentence says how many of how many, and a walk that stopped at
    // the id cap left the second number counting only as far as it got.
    /**
     * @return array{0: int, 1: int, 2: list<int>}
     */
    private function drifted(): array
    {
        $checked = 0;
        $drifting = 0;
        $ids = [];

        $this->db->connection()->table('transactions')
            ->where('fingerprint_version', $this->composer->version())
            ->orderBy('id')
            ->select(self::READ)
            ->each(function (object $row) use (&$checked, &$drifting, &$ids): void {
                $checked++;

                if ($this->describesItsRow($row)) {
                    return;
                }

                $drifting++;

                if (count($ids) < self::NAME_AT_MOST) {
                    $ids[] = self::toInt($row->id ?? null);
                }
            });

        return [$checked, $drifting, $ids];
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
