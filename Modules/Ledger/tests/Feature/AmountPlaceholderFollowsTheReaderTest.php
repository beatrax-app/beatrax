<?php

declare(strict_types=1);

use Livewire\Livewire;
use Modules\Core\Models\User;
use Modules\Ledger\Internal\Http\Livewire\ReconcilePage;
use Modules\Ledger\Models\Account;

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

    // The box whose shape this measures is inside the reconcile form, and the
    // form renders only once there is an account to hold a statement against.
    Account::create([
        'user_id' => $this->user->id,
        'name' => 'ASN Betalen',
        'slug' => 'amount-placeholder-account',
        'kind' => 'bank',
        'iban' => 'NL57ASNB0000000044',
        'default_currency' => 'EUR',
    ]);
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

// Walked rather than globbed, and over the whole view tree rather than the
// livewire/ subtree. `**` is not recursive in a glob pattern — it matches ONE
// directory, so the two patterns together covered exactly two levels and only
// because that is how deep the tree happens to be today. The templates beside
// livewire/ were never opened at all: a hard-coded placeholder in
// components/secondary-amount.blade.php passed.
//
// Both marks, too. A reader on an English locale is offered "0.00", so the
// point form is the same defect for them that the comma form is for a Dutch
// reader, and only one of the two was being looked for.
/** @return list<string> */
function ledgerViewTemplates(): array
{
    $paths = [];

    $walk = new RecursiveIteratorIterator(
        new RecursiveDirectoryIterator(base_path('Modules/Ledger/Resources/views'), FilesystemIterator::SKIP_DOTS),
    );

    /** @var SplFileInfo $file */
    foreach ($walk as $file) {
        if ($file->isFile() && str_ends_with($file->getPathname(), '.blade.php')) {
            $paths[] = $file->getPathname();
        }
    }

    sort($paths);

    return $paths;
}

it('never hard-codes an amount placeholder in a Ledger template', function (): void {
    $offenders = [];
    $templates = ledgerViewTemplates();

    foreach ($templates as $path) {
        if (preg_match('/placeholder\s*=\s*"0[.,]00"/', (string) file_get_contents($path)) === 1) {
            $offenders[] = str_replace(base_path().'/', '', $path);
        }
    }

    expect(count($templates))->toBeGreaterThan(
        5,
        'The walk opened '.count($templates).' Ledger templates, too few for a clean answer to mean anything.',
    );

    expect(array_values(array_unique($offenders)))->toBe([], implode("\n  ", [
        'These write an amount placeholder into the template, so it stays in one locale\'s marks '
        .'whoever is reading:',
        ...$offenders,
    ]));
});
