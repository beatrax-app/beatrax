<?php

declare(strict_types=1);

namespace Modules\Forecasting\Internal\Exceptions;

use Modules\Forecasting\Public\Actions\SetAccountOpeningBalance;
use Modules\Forecasting\Public\Http\Livewire\OpeningBalanceEditor;
use RuntimeException;

/**
 * @see SetAccountOpeningBalance
 * @see OpeningBalanceEditor
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
