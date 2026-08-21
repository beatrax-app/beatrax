<?php

declare(strict_types=1);

use Carbon\CarbonImmutable;
use Livewire\Livewire;
use Modules\Import\Internal\Http\Livewire\PreviewWizard;
use Modules\Import\Internal\Pipeline\PreviewCache;
use Modules\Import\Public\Dto\ImportPreviewResult;
use Modules\Import\Public\Dto\PreviewRowDto;
use Modules\Import\Public\Enums\PreviewRowStatus;
use Modules\Ledger\Models\ImportRun;

beforeEach(function (): void {
    $this->seedFixtureUserAndAccount();
    $this->actingAs($this->fixtureUser);
});

function seedPreviewWithThreeRows(int $userId): int
{
    /** @var ImportRun $run */
    $run = ImportRun::create([
        'user_id' => $userId,
        'source_format' => 'asn-csv',
        'raw_file_path' => '/tmp/inplace-'.bin2hex(random_bytes(4)).'.csv',
        'sha256' => hash('sha256', 'inplace-'.uniqid('', true)),
        'uploaded_at' => CarbonImmutable::parse('2026-05-15 12:00:00'),
        'status' => 'previewed',
    ]);

    $rows = [
        new PreviewRowDto(
            rowIndex: 0,
            status: PreviewRowStatus::NewRow,
            accountId: 1,
            bookedAt: '01-05-2026',
            counterpartyName: null,
            counterpartyIban: null,
            description: 'ROW-ZERO',
            categoryName: null,
            amountMinor: -100,
            currency: 'EUR',
            error: null,
            aliasFriendlyName: 'Already Aliased',
        ),
        new PreviewRowDto(
            rowIndex: 1,
            status: PreviewRowStatus::NewRow,
            accountId: 1,
            bookedAt: '02-05-2026',
            counterpartyName: null,
            counterpartyIban: null,
            description: 'ROW-ONE-RAW',
            categoryName: null,
            amountMinor: -200,
            currency: 'EUR',
            error: null,
            aliasFriendlyName: null,
        ),
        new PreviewRowDto(
            rowIndex: 2,
            status: PreviewRowStatus::NewRow,
            accountId: 1,
            bookedAt: '03-05-2026',
            counterpartyName: null,
            counterpartyIban: null,
            description: 'ROW-TWO',
            categoryName: null,
            amountMinor: -300,
            currency: 'EUR',
            error: null,
            aliasFriendlyName: null,
        ),
    ];

    /** @var PreviewCache $cache */
    $cache = app(PreviewCache::class);
    $cache->put(
        $run->id,
        new ImportPreviewResult(
            importRunId: $run->id,
            rows: $rows,
            accountsToName: [],
        ),
        canonical: [],
        enrichments: [],
    );

    return $run->id;
}

it('updates the affected row aliasFriendlyName in place when rename-counterparty:saved fires', function (): void {
    $importRunId = seedPreviewWithThreeRows($this->fixtureUser->id);

    Livewire::test(PreviewWizard::class, ['id' => $importRunId])
        ->dispatch('rename-counterparty:saved', rowIndex: 1, friendlyName: 'Shell Pieter')
        ->assertSee('Shell Pieter');

    /** @var PreviewCache $cache */
    $cache = app(PreviewCache::class);
    $refreshed = $cache->getPreview($importRunId);

    expect($refreshed)->not->toBeNull();
    expect($refreshed->rows[0]->aliasFriendlyName)->toBe('Already Aliased');
    expect($refreshed->rows[1]->aliasFriendlyName)->toBe('Shell Pieter');
    expect($refreshed->rows[2]->aliasFriendlyName)->toBeNull();
});

it('silently no-ops when rename-counterparty:saved fires with an out-of-bounds rowIndex', function (): void {
    $importRunId = seedPreviewWithThreeRows($this->fixtureUser->id);

    Livewire::test(PreviewWizard::class, ['id' => $importRunId])
        ->dispatch('rename-counterparty:saved', rowIndex: 99, friendlyName: 'Should Be Ignored');

    /** @var PreviewCache $cache */
    $cache = app(PreviewCache::class);
    $refreshed = $cache->getPreview($importRunId);

    expect($refreshed)->not->toBeNull();
    expect($refreshed->rows[0]->aliasFriendlyName)->toBe('Already Aliased');
    expect($refreshed->rows[1]->aliasFriendlyName)->toBeNull();
    expect($refreshed->rows[2]->aliasFriendlyName)->toBeNull();
});
