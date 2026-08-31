<?php

declare(strict_types=1);

namespace Modules\OpenBanking\Internal\Dto;

use Carbon\CarbonImmutable;
use Modules\Import\Public\Dto\ImportPreviewResult;
use Modules\OpenBanking\Internal\Enums\SyncAttemptStatus;

// What one fetch produced, and — separately — how far the connection may now
// claim to have read the bank: `committedThrough` is null unless the rows were
// durably written AND the walk reached the end of them.
/**
 * @link ../../../../.docs/features/open-banking/fetch-cursor.md#the-cursor-is-what-was-committed-not-what-was-fetched
 */
final readonly class OpenBankingFetchResult
{
    private function __construct(
        public ImportPreviewResult $preview,
        public FetchWalk $walk,
        public ?CarbonImmutable $committedThrough,
        public bool $filedNothing = false,
    ) {}

    public static function previewed(ImportPreviewResult $preview, FetchWalk $walk): self
    {
        return new self($preview, $walk, null);
    }

    public static function committed(ImportPreviewResult $preview, FetchWalk $walk, CarbonImmutable $through): self
    {
        return new self($preview, $walk, $walk->isComplete() ? $through : null);
    }

    // Rows arrived and every one of them was refused. Null cursor for the same
    // reason a truncated walk gets one: the window has to stay open, so the
    // rows are re-offered to a run that can actually file them.
    public static function filedNothing(ImportPreviewResult $preview, FetchWalk $walk): self
    {
        return new self($preview, $walk, null, filedNothing: true);
    }

    // The one place an attempt that ran to the end is named, so the status the
    // connection row keeps and the status the outcome hands its caller cannot
    // be two different readings of the same fetch.
    public function attemptStatus(): SyncAttemptStatus
    {
        return match (true) {
            $this->filedNothing => SyncAttemptStatus::NothingImported,
            $this->walk->isComplete() => SyncAttemptStatus::Ok,
            default => SyncAttemptStatus::Truncated,
        };
    }
}
