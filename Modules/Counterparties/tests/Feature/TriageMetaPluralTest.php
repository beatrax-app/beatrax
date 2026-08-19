<?php

declare(strict_types=1);

use Illuminate\Support\Facades\App;
use Modules\Core\Public\Support\Lang;

/*
 * The triage card's meta line read "1 transacties" — the count was
 * interpolated into a fixed plural. It is the line that tells the user how
 * much history they are labelling from, so the number and the noun have to
 * agree.
 */

it('says one transaction in the singular', function (): void {
    App::setLocale('nl');

    expect(Lang::choice('counterparties::triage.meta', 1, ['count' => 1, 'date' => '01-01-2026']))
        ->toContain('1 transactie ')
        ->not->toContain('transacties');
});

it('says many transactions in the plural', function (): void {
    App::setLocale('nl');

    expect(Lang::choice('counterparties::triage.meta', 7, ['count' => 7, 'date' => '01-01-2026']))
        ->toContain('7 transacties');
});

it('agrees in English too', function (): void {
    App::setLocale('en');

    expect(Lang::choice('counterparties::triage.meta', 1, ['count' => 1, 'date' => '2026-01-01']))
        ->toContain('1 transaction ')
        ->and(Lang::choice('counterparties::triage.meta', 4, ['count' => 4, 'date' => '2026-01-01']))
        ->toContain('4 transactions');
});

it('still carries the date in every shipped locale', function (): void {
    $problems = [];
    foreach (glob(base_path('Modules/Counterparties/Resources/lang/*/triage.php')) ?: [] as $file) {
        $locale = basename(dirname($file));
        App::setLocale($locale);
        foreach ([1, 2, 5, 21] as $count) {
            $line = Lang::choice('counterparties::triage.meta', $count, ['count' => $count, 'date' => '01-01-2026']);
            if (! str_contains($line, '01-01-2026') || ! str_contains($line, (string) $count)) {
                $problems[] = $locale.'/'.$count.': '.$line;
            }
        }
    }

    expect($problems)->toBe([]);
});
