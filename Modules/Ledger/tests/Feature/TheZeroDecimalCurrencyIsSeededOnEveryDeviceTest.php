<?php

declare(strict_types=1);

use Illuminate\Support\Facades\DB;

// `currencies` is what `base_currency` and SetAccountCurrency validate against,
// so a seed that silently does not land takes the only zero-decimal denomination
// out of reach of the UI without failing anything. SQLite DDL does not roll
// back, so a device that mis-runs this migration cannot be walked backwards.

it('carries JPY at minor_unit 0, so the one non-hundredth denomination is pickable', function (): void {
    $row = DB::table('currencies')->where('code', 'JPY')->first();

    expect($row)->not->toBeNull()
        ->and((int) $row->minor_unit)->toBe(0)
        ->and((string) $row->name)->toBe('Japanese Yen');
});
