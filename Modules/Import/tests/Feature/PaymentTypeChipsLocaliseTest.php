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

// Read out of each locale's own array rather than asked of the translator.
// A namespaced key missing in one locale falls back to `en`, so the label came
// back as fluent English and never as the key: the only way this arm could go
// red was the key being absent from all twenty-six at once, which is the case
// the parity test already covers. Dropping `direct_debit` from `de` alone left
// it green while the German pill read "Direct debit".
it('resolves a real translation in every shipped locale', function (): void {
    $missing = [];
    $files = glob(base_path('Modules/Import/Resources/lang/*/payment_type.php')) ?: [];

    expect($files)->not->toBe([], 'No payment_type translation file was found, so this rule judges nothing.');

    foreach ($files as $file) {
        $locale = basename(dirname($file));

        /** @var array<string, mixed> $strings */
        $strings = require $file;

        foreach (PaymentType::cases() as $type) {
            $line = $strings[$type->value] ?? null;

            if (! is_string($line) || trim($line) === '') {
                $missing[] = $locale.'/'.$type->value;
            }
        }
    }

    expect($missing)->toBe([], implode("\n  ", [
        'These locales carry no line of their own for a payment-type chip, so the reader is shown '
        .'the English one:',
        ...$missing,
    ]));
});
