<?php

declare(strict_types=1);

namespace Modules\Sync\Public\Services;

use Modules\Sync\Internal\Transport\IntroductionOffers;
use Modules\Sync\Internal\Transport\WithheldLedger;

// What a peer last reported holding back that this device STILL cannot read.
// The ledger answers what the exchange said; this answers whether the reader
// can do anything about it, and every surface that mentions a hold reads it
// here — a status line and a device list disagreeing is the reader's question.
/**
 * @link ../../../../.docs/features/sync/introducing-a-device-nobody-can-pair-with.md
 */
final readonly class WithheldHistoryReport
{
    public function __construct(
        private WithheldLedger $ledger,
        private IntroductionOffers $offers,
    ) {}

    // Asked through IntroductionOffers rather than the registry, because that
    // is the class that decides which authors this device advertises it can
    // verify. A second spelling of the same set here would let the screen and
    // the request disagree about what is still being held.
    /**
     * @return list<array{peer_device_id: string, author_device_id: string, entry_count: int}>
     */
    public function stillHeldFor(int $userId): array
    {
        $verifiable = $this->offers->verifiableAuthorsFor($userId)->toWire() ?? [];

        return array_values(array_filter(
            $this->ledger->forUser($userId),
            static fn (array $row): bool => ! in_array($row['author_device_id'], $verifiable, true),
        ));
    }

    // The largest report per author, never the sum across peers. Two peers
    // holding the same author's work back are two accounts of one gap, and
    // adding them tells the reader they lost it twice.
    public function totalFor(int $userId): int
    {
        $perAuthor = [];

        foreach ($this->stillHeldFor($userId) as $row) {
            $author = $row['author_device_id'];
            $perAuthor[$author] = max($perAuthor[$author] ?? 0, $row['entry_count']);
        }

        return array_sum($perAuthor);
    }

    public function isHolding(int $userId): bool
    {
        return $this->stillHeldFor($userId) !== [];
    }
}
