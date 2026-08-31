<?php

declare(strict_types=1);

namespace Modules\OpenBanking\Internal\Dto;

use Modules\Import\Public\Dto\ImportPreviewResult;
use Modules\OpenBanking\Internal\Enums\SyncAttemptStatus;
use Throwable;

// What one attempt did, for two callers that want different things from it: a
// queued job with a retry envelope, and a button press without one. A null
// status is the attempt that never started, because another run held the lock.
final readonly class OpenBankingSyncOutcome
{
    private function __construct(
        public ?SyncAttemptStatus $status,
        public ?ImportPreviewResult $preview,
        public ?Throwable $failure,
        public bool $retryable = true,
    ) {}

    // Named for what it is rather than for how it went: a fetch that filed
    // none of its rows reached the end of the attempt too, and folding it into
    // Ok here is how it left the queue indistinguishable from a quiet week.
    public static function completed(OpenBankingFetchResult $result): self
    {
        return new self($result->attemptStatus(), $result->preview, null);
    }

    public static function failed(SyncAttemptStatus $status, Throwable $failure, bool $retryable = true): self
    {
        return new self($status, null, $failure, $retryable);
    }

    public static function alreadyRunning(): self
    {
        return new self(null, null, null);
    }

    public function isConsentFailure(): bool
    {
        return $this->status === SyncAttemptStatus::ConsentFailed;
    }

    // The bank had more pages than the walk asked for, so the rows on offer are
    // a part of the window and the reader has to be told which part they are
    // not seeing.
    public function isTruncated(): bool
    {
        return $this->status === SyncAttemptStatus::Truncated;
    }

    // The bank answered, the rows arrived, and not one of them could be filed.
    // Distinct from a fetch that came back empty, which is the same zero on
    // screen and the ordinary outcome of a daily sync.
    public function filedNothing(): bool
    {
        return $this->status === SyncAttemptStatus::NothingImported;
    }

    // A consent failure is terminal until the reader re-links, and a refused
    // confirm until they name the account the rows landed in. Neither is worth
    // handing back to the queue.
    public function retryableFailure(): ?Throwable
    {
        return $this->retryable && $this->status === SyncAttemptStatus::Error ? $this->failure : null;
    }
}
