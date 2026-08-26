<?php

declare(strict_types=1);

namespace Modules\Ledger\Internal\Exceptions;

use Modules\Ledger\Internal\Actions\SetAccountCurrency;
use Modules\Ledger\Public\Http\Livewire\AccountCurrencyEditor;
use RuntimeException;

/**
 * @see SetAccountCurrency
 * @see AccountCurrencyEditor
 */
final class AccountCurrencyRelabelWarning extends RuntimeException
{
    /**
     * @param  array<string, int>  $linesByCurrency
     */
    public function __construct(
        public readonly string $fromCurrency,
        public readonly string $toCurrency,
        public readonly ?int $baselineMinor,
        public readonly array $linesByCurrency,
    ) {
        parent::__construct(
            'Changing the account currency relabels its baseline and moves which balance line the account reports.',
        );
    }
}
