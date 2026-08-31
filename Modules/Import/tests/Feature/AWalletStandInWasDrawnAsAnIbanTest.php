<?php

declare(strict_types=1);

use Livewire\Livewire;
use Modules\Core\Models\User;
use Modules\Import\Internal\Http\Livewire\PreviewWizard;
use Modules\Import\Internal\Pipeline\PreviewCache;
use Modules\Import\Public\Dto\ImportPreviewResult;
use Modules\Import\Public\Dto\UnknownIban;
use Modules\Ingestion\Public\Enums\SourceFormat;
use Modules\Ingestion\Public\Enums\SyntheticIban;
use Modules\Ingestion\Public\Services\CsvPresetRegistry;
use Modules\Ledger\Models\ImportRun;

// A wallet export and a card statement carry no IBAN of the reader's own, so
// the source stands one in. Drawn in fours the way ISO 13616 prints a real
// IBAN, that stand-in reached a device as "REVO LUT".
function walletStandInPreview(string $username, string $identifier, string $format, string $sha): array
{
    /** @var User $user */
    $user = User::query()->create([
        'username' => $username,
        'password' => 'x',
        'period_start_day' => 1,
    ]);

    /** @var ImportRun $run */
    $run = ImportRun::query()->create([
        'user_id' => $user->id,
        'source_format' => $format,
        'raw_file_path' => 'imports/'.$user->id.'/'.$sha,
        'sha256' => $sha,
        'uploaded_at' => now(),
    ]);

    /** @var PreviewCache $cache */
    $cache = app(PreviewCache::class);
    $cache->put($run->id, new ImportPreviewResult(
        importRunId: $run->id,
        rows: [],
        accountsToName: [new UnknownIban($identifier, null)],
    ), []);

    return [$user, $run->id];
}

it('names the bank a single-account export belongs to rather than drawing its stand-in in fours', function (): void {
    $preset = app(CsvPresetRegistry::class)->get(CsvPresetRegistry::REVOLUT);

    expect($preset)->not->toBeNull();

    [$user, $runId] = walletStandInPreview(
        'stand-in-revolut',
        $preset->ownAccountIdentifier(),
        CsvPresetRegistry::REVOLUT,
        str_repeat('e', 64),
    );

    Livewire::actingAs($user)
        ->test(PreviewWizard::class, ['id' => $runId])
        ->assertDontSee('REVO LUT')
        ->assertSee($preset->label)
        ->assertSee(__('import::preview.unknown_account_prefix'))
        ->assertDontSee(__('import::preview.unknown_iban_prefix'));
});

// A receipt drop's format names its transport, so the wallet's own prompt is
// raised by the unknown-IBAN list instead. The stand-in reaches no caption at
// all on this path: the prompt that claims it says PayPal in its own words.
it('sends a receipt drop to the wallet prompt rather than drawing its stand-in in fours', function (): void {
    [$user, $runId] = walletStandInPreview(
        'stand-in-paypal',
        SyntheticIban::Paypal->value,
        SourceFormat::Eml->value,
        str_repeat('f', 64),
    );

    Livewire::actingAs($user)
        ->test(PreviewWizard::class, ['id' => $runId])
        ->assertDontSee('PAYP AL')
        ->assertSee(__('import::preview.paypal.heading'))
        ->assertDontSee(__('import::preview.unknown_account_prefix'))
        ->assertDontSee(__('import::preview.unknown_iban_prefix'));
});

it('sends a receipt drop to the card prompt rather than drawing its stand-in in fours', function (): void {
    [$user, $runId] = walletStandInPreview(
        'stand-in-ics',
        SyntheticIban::IcsCard->value,
        SourceFormat::Eml->value,
        str_repeat('9', 64),
    );

    Livewire::actingAs($user)
        ->test(PreviewWizard::class, ['id' => $runId])
        ->assertDontSee('ICS- CARD')
        ->assertSee(__('import::preview.ics.heading'))
        ->assertDontSee(__('import::preview.unknown_account_prefix'))
        ->assertDontSee(__('import::preview.unknown_iban_prefix'));
});

it('still draws a real IBAN in fours and still calls it one', function (): void {
    [$user, $runId] = walletStandInPreview(
        'stand-in-real-iban',
        'NL91ABNA0417164300',
        CsvPresetRegistry::ASN,
        str_repeat('8', 64),
    );

    Livewire::actingAs($user)
        ->test(PreviewWizard::class, ['id' => $runId])
        ->assertSee('NL91 ABNA 0417 1643 00')
        ->assertSee(__('import::preview.unknown_iban_prefix'))
        ->assertDontSee(__('import::preview.unknown_account_prefix'));
});
