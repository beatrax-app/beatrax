<?php

declare(strict_types=1);

namespace Modules\Receipts\Public\Support;

use Modules\Receipts\Public\Dto\CapturedReceipt;

// Collects what a single drop filed, for a caller that has to tell the reader
// about it afterwards. Bounded, because a mailbox archive is thousands of
// messages and this rides into the preview cache: the total is counted whole
// so the screen can say how much of the archive it is showing.
/**
 * @link ../../../../.docs/features/receipts/architecture.md#when-a-message-is-matched
 */
final class ReceiptCaptureLog
{
    public const int MAX_KEPT = 50;

    /** @var list<CapturedReceipt> */
    private array $kept = [];

    private int $total = 0;

    public function record(CapturedReceipt $capture): void
    {
        $this->total++;

        if (count($this->kept) < self::MAX_KEPT) {
            $this->kept[] = $capture;
        }
    }

    /**
     * @return list<CapturedReceipt>
     */
    public function kept(): array
    {
        return $this->kept;
    }

    public function total(): int
    {
        return $this->total;
    }
}
