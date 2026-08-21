<?php

declare(strict_types=1);

use Illuminate\Foundation\Testing\RefreshDatabase;
use Modules\Core\Models\User;
use Modules\Import\Internal\Detectors\PaypalCsvStartingBalanceDetector;

uses(RefreshDatabase::class);

// The PayPal Activity CSV carries no opening-balance signal, so the detector
// always declines and the wizard falls back to manual entry. A zero candidate
// instead would quietly persist €0.00 over the user's real opening balance.

it('returns the supports() flag only for the paypal-csv source format', function (): void {
    $detector = new PaypalCsvStartingBalanceDetector;

    expect($detector->supports('paypal-csv'))->toBeTrue();
    expect($detector->supports('camt053'))->toBeFalse();
    expect($detector->supports('mt940'))->toBeFalse();
    expect($detector->supports('ics-pdf'))->toBeFalse();
})->group('phase-16.1.1');

it('returns an empty list regardless of importRunIds payload (no Saldo-as-balance regression)', function (): void {
    $user = User::query()->create([
        'username' => 'paypal-detect',
        'password' => 'fixture-password',
        'period_start_day' => 1,
    ]);

    $detector = new PaypalCsvStartingBalanceDetector;

    expect($detector->detect([], $user))->toBe([]);
    expect($detector->detect([1], $user))->toBe([]);
    expect($detector->detect([1, 2, 3, 4, 5], $user))->toBe([]);
})->group('phase-16.1.1');
