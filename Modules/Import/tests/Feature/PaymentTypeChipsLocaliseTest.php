<?php

declare(strict_types=1);

use Illuminate\Support\Facades\App;
use Modules\Import\Public\Enums\PaymentType;

it('translates every payment-type chip', function (): void {
    App::setLocale('nl');

    $labels = array_map(
        static fn (PaymentType $type): string => $type->chipLabel(),
        PaymentType::cases(),
    );

    expect($labels)->toContain('↔ Overboeking')
        ->and($labels)->toContain('⤓ Incasso')
        ->and($labels)->toContain('€ Contant')
        ->and($labels)->toContain('◷ Kosten')
        ->and($labels)->toContain('↺ Terugbetaling')
        ->and($labels)->toContain('? Onbekend');
});

it('leaves no chip reading the English source once the locale is Dutch', function (): void {
    App::setLocale('nl');

    foreach (PaymentType::cases() as $type) {
        expect($type->chipLabel())
            ->not->toContain('Direct debit')
            ->not->toContain('Unknown')
            ->not->toContain('Refund')
            ->not->toContain('Cash');
    }
});

// The glyph stays untranslated: it is the same mark in every language, and the
// chip colour mapping is keyed to it.
it('keeps the glyph in front of every label', function (): void {
    App::setLocale('nl');

    foreach (PaymentType::cases() as $type) {
        $label = $type->chipLabel();
        expect($label)->toMatch('/^\S+ \S/')
            ->and($label)->not->toContain('import::payment_type');
    }
});

it('resolves a real translation in every shipped locale', function (): void {
    $missing = [];
    foreach (glob(base_path('Modules/Import/Resources/lang/*/payment_type.php')) ?: [] as $file) {
        $locale = basename(dirname($file));
        App::setLocale($locale);
        foreach (PaymentType::cases() as $type) {
            if (str_contains($type->chipLabel(), 'import::payment_type')) {
                $missing[] = $locale.'/'.$type->value;
            }
        }
    }

    expect($missing)->toBe([]);
});
