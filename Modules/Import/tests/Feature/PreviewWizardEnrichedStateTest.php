<?php

declare(strict_types=1);

use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Modules\Import\Internal\Http\Livewire\PreviewWizard;
use Modules\Import\Internal\Pipeline\PreviewCache;
use Modules\Import\Public\Dto\ImportPreviewResult;
use Modules\Import\Public\Dto\PreviewRowDto;
use Modules\Ledger\Models\ImportRun;

uses(RefreshDatabase::class);

/*
 * PreviewWizard rendering for status=enriched rows.
 *
 * Phase 16 UAT batch 7 locked the policy that statement-vs-statement
 * fingerprint collisions drop as DUPLICATE without upgrading
 * source_ref. The enriched-row UI state is therefore unreachable
 * through statement-only fixtures; it survives only on the receipt
 * path (one side is paypal-receipt / ics-receipt / google-play-receipt
 * — see Modules/Receipts/tests for the end-to-end happy paths through
 * the receipt matcher chain).
 *
 * These tests cover the PreviewWizard view's rendering of an
 * enriched-row payload independently of how the disposition was
 * produced. The preview is hand-assembled into the PreviewCache so
 * the test does not depend on a receipt fixture being present in the
 * import-side fixture tree; it asserts the Blade view renders the
 * Enriched badge + the from→to diff arrow + the empty-set placeholder
 * for a null from-ref.
 */

beforeEach(function (): void {
    $this->seedFixtureUserAndAccount();
    $this->actingAs($this->fixtureUser);
});

/**
 * Persist an ImportRun for the current fixture user and stage one
 * hand-built PreviewRowDto into the PreviewCache so the PreviewWizard
 * can render it without needing the full import pipeline to produce
 * an enriched disposition.
 *
 * @param  array{from: ?string, to: string}  $sourceRefDiff
 */
function seedPreviewWithEnrichedRow(array $sourceRefDiff, int $userId): int
{
    /** @var ImportRun $run */
    $run = ImportRun::create([
        'user_id' => $userId,
        'source_format' => 'paypal-receipt',
        'raw_file_path' => '/tmp/seeded-preview-'.bin2hex(random_bytes(4)).'.eml',
        'sha256' => hash('sha256', 'preview-'.uniqid('', true)),
        'uploaded_at' => CarbonImmutable::parse('2026-05-15 12:00:00'),
        'status' => 'previewed',
    ]);

    $row = new PreviewRowDto(
        rowIndex: 0,
        status: 'enriched',
        accountId: null,
        bookedAt: '15-05-2026',
        counterpartyName: 'Albert Heijn',
        counterpartyIban: null,
        description: null,
        categoryName: null,
        amountMinor: -1234,
        currency: 'EUR',
        error: null,
        diff: ['source_ref' => $sourceRefDiff],
    );

    /** @var PreviewCache $cache */
    $cache = app(PreviewCache::class);
    $cache->put(
        $run->id,
        new ImportPreviewResult(
            importRunId: $run->id,
            rows: [$row],
            accountsToName: [],
        ),
        canonical: [],
        enrichments: [],
    );

    return $run->id;
}

it('renders the Enriched badge for a row with status=enriched', function (): void {
    $importRunId = seedPreviewWithEnrichedRow(
        ['from' => 'O-00000000000000001', 'to' => 'PAYID-CANONICAL'],
        $this->fixtureUser->id,
    );

    Livewire::test(PreviewWizard::class, ['id' => $importRunId])
        ->assertSee('Enriched')
        ->assertSee('source_ref:')
        ->assertSee('→');
})->group('phase-2');

it('renders the empty-set placeholder when the enriched row carries a null from-ref', function (): void {
    $importRunId = seedPreviewWithEnrichedRow(
        ['from' => null, 'to' => 'PAYID-CANONICAL'],
        $this->fixtureUser->id,
    );

    Livewire::test(PreviewWizard::class, ['id' => $importRunId])
        ->assertSee('∅', false);
})->group('phase-2');
