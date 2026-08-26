<?php

declare(strict_types=1);

use Modules\Ingestion\Public\Contracts\AccountResolver;
use Modules\Ingestion\Public\Dto\AccountResolution;
use Modules\Ingestion\Public\Dto\SourceTransactionDto;
use Modules\Ingestion\Public\Services\CsvPresetRegistry;
use Modules\Ingestion\Public\Services\SourceAdapterRegistry;

/**
 * @return list<SourceTransactionDto>
 */
function presetRows(string $format, string $fixture): array
{
    $resolver = new class implements AccountResolver
    {
        public function resolve(string $iban): AccountResolution
        {
            return AccountResolution::unknown($iban);
        }
    };

    return iterator_to_array(
        app(SourceAdapterRegistry::class)->for($format)->parse(
            base_path('Modules/Ingestion/tests/fixtures/csv/'.$fixture),
            $resolver,
        ),
        preserve_keys: false,
    );
}

it('reads the counterparty out of a Revolut export, which carries it in its only text column', function (): void {
    $rows = presetRows(CsvPresetRegistry::REVOLUT, 'revolut-sample.csv');

    expect($rows)->toHaveCount(2);
    expect($rows[0]->counterpartyName)->toBe('Spotify');
    expect($rows[1]->counterpartyName)->toBe('Top-Up via Apple Pay');
});

it('gives every CSV preset a column to read the counterparty from', function (): void {
    $registry = new CsvPresetRegistry;

    $blind = [];
    foreach ($registry->all() as $format => $preset) {
        if ($preset->counterpartyNameHeader === null) {
            $blind[] = $format;
        }
    }

    expect($blind)->toBe(
        [],
        "A preset with no counterparty column lands every row under one nameless\n".
        "counterparty: no merchant memory, no alias, no rule can match by who was\n".
        "paid, and recurring detection cannot cluster them. Presets missing one:\n  "
        .implode("\n  ", $blind),
    );
});

it('does not repeat the counterparty in the description when they would be the same column', function (): void {
    $rows = presetRows(CsvPresetRegistry::REVOLUT, 'revolut-sample.csv');

    expect($rows[0]->description)->toBeNull();
});
