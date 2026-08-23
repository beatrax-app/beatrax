<?php

declare(strict_types=1);

use Livewire\Livewire;
use Modules\Core\Models\User;
use Modules\Ledger\Internal\Http\Livewire\ReconcilePage;

// An amount box shows the shape of the number it wants. Two of them spelled
// that shape out as "0,00", so an English install offered a comma placeholder
// directly above a "€0.00" it had just rendered with a point.

beforeEach(function (): void {
    $this->user = User::create([
        'username' => 'amount-placeholder-user',
        'password' => 'opensesame',
        'period_start_day' => 1,
        'default_currency_view' => 'eur_only',
    ]);
    $this->actingAs($this->user);
});

it('offers the English amount shape to an English reader on reconcile', function (): void {
    app()->setLocale('en');

    $html = Livewire::test(ReconcilePage::class)->html();

    expect($html)->toContain('placeholder="0.00"')
        ->and($html)->not->toContain('placeholder="0,00"');
});

it('offers the Dutch amount shape to a Dutch reader on reconcile', function (): void {
    app()->setLocale('nl');

    $html = Livewire::test(ReconcilePage::class)->html();

    expect($html)->toContain('placeholder="0,00"');
});

it('never hard-codes an amount placeholder in a Ledger template', function (): void {
    $offenders = [];
    foreach (glob(base_path('Modules/Ledger/Resources/views/livewire/**/*.blade.php')) ?: [] as $path) {
        if (str_contains((string) file_get_contents($path), 'placeholder="0,00"')) {
            $offenders[] = str_replace(base_path().'/', '', $path);
        }
    }
    foreach (glob(base_path('Modules/Ledger/Resources/views/livewire/*.blade.php')) ?: [] as $path) {
        if (str_contains((string) file_get_contents($path), 'placeholder="0,00"')) {
            $offenders[] = str_replace(base_path().'/', '', $path);
        }
    }

    expect(array_values(array_unique($offenders)))->toBe([]);
});
