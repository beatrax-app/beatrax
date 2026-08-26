<?php

declare(strict_types=1);

use Modules\Ingestion\Internal\Adapters\Asn\AsnCsvAdapter;
use Modules\Ingestion\Public\Asn\AsnDescriptionDelimiters;
use Modules\Ingestion\Public\Contracts\AccountResolver;

// Read off an iPhone 12 mini after importing a real ASN export: the transaction
// detail page printed 'Rentevergoeding tweede kwartaal' — apostrophes and all.
// ASN wraps the Omschrijving field in them as a delimiter; the adapter already
// normalises that field's stray CR/LF, and this is the same kind of artifact.

beforeEach(function (): void {
    $this->adapter = app(AsnCsvAdapter::class);
    $this->resolver = Mockery::mock(AccountResolver::class);
    $this->resolver->shouldReceive('resolve')->andReturn(null);
});

it('drops the delimiter quotes ASN wraps a description in', function (): void {
    $dtos = iterator_to_array(
        $this->adapter->parse(base_path('tests/fixtures/asn-sample-1.csv'), $this->resolver),
        preserve_keys: false,
    );

    expect($dtos)->not->toBeEmpty();

    foreach ($dtos as $dto) {
        if ($dto->description === null) {
            continue;
        }

        expect($dto->description)->not->toStartWith("'")
            ->and($dto->description)->not->toEndWith("'");
    }
});

it('still keeps the narrative itself', function (): void {
    $dtos = iterator_to_array(
        $this->adapter->parse(base_path('tests/fixtures/asn-sample-1.csv'), $this->resolver),
        preserve_keys: false,
    );

    expect($dtos[0]->description)->toContain('Europese incasso');
});

// The audit copy is the one place the untouched source belongs.
it('leaves the raw payload exactly as the bank wrote it', function (): void {
    $dtos = iterator_to_array(
        $this->adapter->parse(base_path('tests/fixtures/asn-sample-1.csv'), $this->resolver),
        preserve_keys: false,
    );

    $descriptions = array_map(static fn (object $dto): string => (string) ($dto->rawPayload[17] ?? ''), $dtos);

    expect(array_filter($descriptions, static fn (string $d): bool => str_starts_with($d, "'")))
        ->not->toBeEmpty('The audit copy should still carry ASN\'s delimiters.');
});

// An apostrophe that is part of the text, or an unmatched one, is punctuation.
it('leaves an apostrophe that is not a matching wrapper alone', function (): void {
    expect(AsnDescriptionDelimiters::unwrap("Bakkerij 't Stoepje"))->toBe("Bakkerij 't Stoepje")
        ->and(AsnDescriptionDelimiters::unwrap("'unclosed"))->toBe("'unclosed")
        ->and(AsnDescriptionDelimiters::unwrap("unopened'"))->toBe("unopened'")
        ->and(AsnDescriptionDelimiters::unwrap("'"))->toBe("'")
        ->and(AsnDescriptionDelimiters::unwrap("'wrapped'"))->toBe('wrapped');
});

// The backfill over already-imported rows reads a STORED description, which
// the adapter may have joined from two wrapped fields. Both readings of the
// rule live in one class so they cannot drift apart.
it('unwraps a stored description without splitting a narrative that carries the separator', function (): void {
    expect(AsnDescriptionDelimiters::unwrapStored("'Rentevergoeding tweede kwartaal'"))
        ->toBe('Rentevergoeding tweede kwartaal')
        ->and(AsnDescriptionDelimiters::unwrapStored("'Europese incasso: NL-1234 / FEBRUARI 2026'"))
        ->toBe('Europese incasso: NL-1234 / FEBRUARI 2026')
        ->and(AsnDescriptionDelimiters::unwrapStored("'FACTUUR 88' / 'Maandtermijn'"))
        ->toBe('FACTUUR 88 / Maandtermijn')
        ->and(AsnDescriptionDelimiters::unwrapStored("Bakker's Delft"))
        ->toBe("Bakker's Delft")
        ->and(AsnDescriptionDelimiters::unwrapStored("'unclosed"))
        ->toBe("'unclosed")
        ->and(AsnDescriptionDelimiters::unwrapStored("unopened'"))
        ->toBe("unopened'")
        ->and(AsnDescriptionDelimiters::unwrapStored("''nested''"))
        ->toBe("''nested''")
        ->and(AsnDescriptionDelimiters::unwrapStored("''"))
        ->toBeNull();
});

it('is a fixpoint: unwrapping a stored description twice changes nothing the second time', function (): void {
    $once = AsnDescriptionDelimiters::unwrapStored("'Rentevergoeding tweede kwartaal'");

    expect($once)->toBe('Rentevergoeding tweede kwartaal')
        ->and(AsnDescriptionDelimiters::unwrapStored((string) $once))->toBe($once);
});
