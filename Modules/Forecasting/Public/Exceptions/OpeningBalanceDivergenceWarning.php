<?php

declare(strict_types=1);

namespace Modules\Forecasting\Public\Exceptions;

use RuntimeException;

/**
 * Soft-warning signal raised by `SetAccountOpeningBalance` when the
 * user's manually-entered opening balance diverges from the sum of
 * existing transactions on the account by more than the configured
 * threshold (€500).
 *
 * The warning is informational, not blocking — the calling Livewire
 * component catches the exception, renders a "Are you sure?" banner
 * with two chips (`Use beatrax's number` / `Use my number`), and
 * re-invokes the Action with `$allowDivergence=true` on the second
 * pass. The banner is the soft-warning soft-trigger surface.
 *
 * The exception carries the per-user computed divergence delta so the
 * banner copy can quote the exact value the user is overriding.
 */
final class OpeningBalanceDivergenceWarning extends RuntimeException
{
    public function __construct(
        public readonly int $diffMinor,
        public readonly int $sumOfTransactionsMinor,
        public readonly int $userValueMinor,
    ) {
        parent::__construct(
            'Opening balance diverges from the sum of imported transactions by more than the soft threshold.',
        );
    }
}
