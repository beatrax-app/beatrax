<?php

declare(strict_types=1);

use Modules\Import\Internal\Services\StandInAccountName;
use Modules\Ingestion\Public\Enums\SyntheticIban;
use Modules\Ingestion\Public\Services\CsvPresetRegistry;

// A reader cannot act on a stand-in, so every screen that asks them to name the
// account resolves it to the name of the thing it stands for. Each sentinel is
// covered here rather than through the one screen that happens to draw it: the
// wizard routes each provider to its own prompt long before this is reached.

it('names each synthetic sentinel in the reader\'s own words', function (SyntheticIban $sentinel, string $key): void {
    expect(app(StandInAccountName::class)->for($sentinel->value))->toBe(__($key));
})->with([
    'ICS card' => [SyntheticIban::IcsCard, 'import::preview.ics.name'],
    'PayPal' => [SyntheticIban::Paypal, 'import::preview.paypal.name'],
    'Google Play' => [SyntheticIban::GooglePlay, 'import::preview.google_play.name'],
]);

it('names the bank behind a preset-issued identifier', function (): void {
    $preset = app(CsvPresetRegistry::class)->get(CsvPresetRegistry::REVOLUT);

    expect($preset)->not->toBeNull();
    expect(app(StandInAccountName::class)->for($preset->ownAccountIdentifier()))->toBe($preset->label);
});

it('stands in for nothing when the identifier is a real IBAN', function (): void {
    expect(app(StandInAccountName::class)->for('NL91ABNA0417164300'))->toBeNull();
});
