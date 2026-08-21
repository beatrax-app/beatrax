<?php

declare(strict_types=1);

use Modules\Import\Public\Dto\DuplicateDisposition;
use Modules\Import\Public\Dto\EnrichedDisposition;
use Modules\Import\Public\Dto\FingerprintDisposition;
use Modules\Import\Public\Dto\NewRowDisposition;
use Modules\Import\Public\Dto\PendingEnrichment;
use Modules\Import\Public\Enums\PreviewRowStatus;

it('constructs a new-row disposition via the named factory', function (): void {
    $d = FingerprintDisposition::newRow();

    expect($d)->toBeInstanceOf(NewRowDisposition::class);
    expect($d->status())->toBe(PreviewRowStatus::NewRow);
    expect($d->isNew())->toBeTrue();
    expect($d->isDuplicate())->toBeFalse();
    expect($d->isEnriched())->toBeFalse();
})->group('phase-2');

it('constructs a duplicate disposition via the named factory', function (): void {
    $d = FingerprintDisposition::duplicate();

    expect($d)->toBeInstanceOf(DuplicateDisposition::class);
    expect($d->status())->toBe(PreviewRowStatus::Duplicate);
    expect($d->isDuplicate())->toBeTrue();
    expect($d->isNew())->toBeFalse();
    expect($d->isEnriched())->toBeFalse();
})->group('phase-2');

it('constructs an enriched disposition with the named factory and exposes ref fields', function (): void {
    $d = FingerprintDisposition::enriched(existingId: 42, fromSourceRef: null, toSourceRef: 'EREF-XYZ');

    expect($d)->toBeInstanceOf(EnrichedDisposition::class);
    expect($d->status())->toBe(PreviewRowStatus::Enriched);
    expect($d->isEnriched())->toBeTrue();
    expect($d->isNew())->toBeFalse();
    expect($d->isDuplicate())->toBeFalse();
    expect($d->existingTransactionId)->toBe(42);
    expect($d->fromSourceRef)->toBeNull();
    expect($d->toSourceRef)->toBe('EREF-XYZ');
})->group('phase-2');

it('enriched disposition supports a non-null from source ref', function (): void {
    $d = FingerprintDisposition::enriched(existingId: 7, fromSourceRef: 'MT940-REF-A', toSourceRef: 'EREF-STRONGER');

    expect($d->fromSourceRef)->toBe('MT940-REF-A');
    expect($d->toSourceRef)->toBe('EREF-STRONGER');
})->group('phase-2');

it('pending enrichment carries the four required fields', function (): void {
    $p = new PendingEnrichment(
        existingTransactionId: 99,
        newSourceRef: 'EREF-ABC',
        importRunId: 51,
        sourceFormat: 'camt053',
    );

    expect($p->existingTransactionId)->toBe(99);
    expect($p->newSourceRef)->toBe('EREF-ABC');
    expect($p->importRunId)->toBe(51);
    expect($p->sourceFormat)->toBe('camt053');
})->group('phase-2');

it('disposition variants are final', function (string $variant): void {
    $reflection = new ReflectionClass($variant);

    expect($reflection->isFinal())->toBeTrue();
    expect($reflection->isAbstract())->toBeFalse();
})->with([
    'NewRowDisposition' => [NewRowDisposition::class],
    'DuplicateDisposition' => [DuplicateDisposition::class],
    'EnrichedDisposition' => [EnrichedDisposition::class],
])->group('phase-2');

it('FingerprintDisposition base is abstract', function (): void {
    $reflection = new ReflectionClass(FingerprintDisposition::class);

    expect($reflection->isAbstract())->toBeTrue();
})->group('phase-2');
