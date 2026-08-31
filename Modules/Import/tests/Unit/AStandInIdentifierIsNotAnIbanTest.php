<?php

declare(strict_types=1);

use Modules\Core\Public\Support\Iban;
use Modules\Ingestion\Public\Enums\SyntheticIban;
use Modules\Ingestion\Public\Services\CsvPresetRegistry;

// Driven off the enum and the registry rather than off literals, so a fourth
// stand-in source is covered the day it is added rather than the day somebody
// remembers this file.
it('leaves every stand-in this app issues exactly as it arrived', function (): void {
    $identifiers = array_map(
        static fn (SyntheticIban $case): string => $case->value,
        SyntheticIban::cases(),
    );

    foreach (app(CsvPresetRegistry::class)->all() as $preset) {
        $identifiers[] = $preset->ownAccountIdentifier();
    }

    expect($identifiers)->not->toBe([]);

    foreach ($identifiers as $identifier) {
        expect(Iban::isIban($identifier))->toBeFalse($identifier.' was read as an IBAN.');
        expect(Iban::grouped($identifier))->toBe($identifier);
    }
});

it('still groups a real IBAN in fours', function (): void {
    expect(Iban::isIban('NL91ABNA0417164300'))->toBeTrue();
    expect(Iban::grouped('NL91ABNA0417164300'))->toBe('NL91 ABNA 0417 1643 00');
    expect(Iban::grouped('NL91 ABNA 0417 1643 00'))->toBe('NL91 ABNA 0417 1643 00');
    expect(Iban::grouped('NO9386011117947'))->toBe('NO93 8601 1117 947');
    expect(Iban::grouped('MT84MALT011000012345MTLCAST001S'))->toBe('MT84 MALT 0110 0001 2345 MTLC AST0 01S');
});

it('reads an empty value as neither an IBAN nor something to group', function (): void {
    expect(Iban::isIban(''))->toBeFalse();
    expect(Iban::grouped(''))->toBe('');
});
