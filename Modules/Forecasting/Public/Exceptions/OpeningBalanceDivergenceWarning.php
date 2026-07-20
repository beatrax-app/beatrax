<?php

declare(strict_types=1);

namespace Modules\Forecasting\Public\Exceptions;

use Modules\Forecasting\Internal\Http\Livewire\OpeningBalanceEditor;
use Modules\Forecasting\Public\Actions\SetAccountOpeningBalance;
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
