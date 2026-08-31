<?php

declare(strict_types=1);

use Modules\Ledger\Public\Enums\ClearedStatus;
use Modules\Migration\Internal\Enums\YnabClearedFlag;

it('reads every Cleared spelling a YNAB register ships, in either case', function (): void {
    expect(YnabClearedFlag::statusFor('C'))->toBe(ClearedStatus::Cleared)
        ->and(YnabClearedFlag::statusFor('Cleared'))->toBe(ClearedStatus::Cleared)
        ->and(YnabClearedFlag::statusFor('R'))->toBe(ClearedStatus::Reconciled)
        ->and(YnabClearedFlag::statusFor('Reconciled'))->toBe(ClearedStatus::Reconciled)
        ->and(YnabClearedFlag::statusFor('reconciled'))->toBe(ClearedStatus::Reconciled)
        ->and(YnabClearedFlag::statusFor('U'))->toBe(ClearedStatus::Uncleared)
        ->and(YnabClearedFlag::statusFor('Uncleared'))->toBe(ClearedStatus::Uncleared);
});

it('reads an unrecognised or empty cell as uncleared rather than guessing', function (): void {
    expect(YnabClearedFlag::statusFor(''))->toBe(ClearedStatus::Uncleared)
        ->and(YnabClearedFlag::statusFor('N'))->toBe(ClearedStatus::Uncleared)
        ->and(YnabClearedFlag::statusFor('whatever'))->toBe(ClearedStatus::Uncleared);
});
