<?php

declare(strict_types=1);

use Livewire\Livewire;
use Modules\Core\Models\User;
use Modules\Import\Internal\Http\Livewire\PreviewWizard;
use Modules\Import\Internal\Pipeline\PreviewCache;
use Modules\Import\Public\Dto\ImportPreviewResult;
use Modules\Import\Public\Dto\UnknownIban;
use Modules\Ingestion\Public\Services\CsvPresetRegistry;
use Modules\Ledger\Models\Account;
use Modules\Ledger\Models\ImportRun;

// N26, Revolut and Wise export a single account and carry no own-IBAN column,
// so the preset issues its own identifier for the account instead. That
// identifier is a bank's name, and the naming prompt used to present it to the
// reader as an IBAN.
function presetPlaceholderPreview(string $username, string $identifier, string $format, string $sha): array
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
        'raw_file_path' => 'imports/'.$user->id.'/'.$sha.'.csv',
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

it('does not call a preset-issued account identifier an IBAN', function (): void {
    $registry = app(CsvPresetRegistry::class);
    $identifier = $registry->get(CsvPresetRegistry::N26)?->ownAccountIdentifier();

    expect($identifier)->toBeString();
    expect($registry->issuesOwnAccountIdentifier((string) $identifier))->toBeTrue();

    [$user, $runId] = presetPlaceholderPreview('preset-n26', (string) $identifier, CsvPresetRegistry::N26, str_repeat('c', 64));

    Livewire::actingAs($user)
        ->test(PreviewWizard::class, ['id' => $runId])
        ->assertSee((string) $identifier)
        ->assertDontSee(__('import::preview.unknown_iban_prefix'))
        ->assertSee(__('import::preview.unknown_account_prefix'));
});

it('still calls a real IBAN an IBAN', function (): void {
    [$user, $runId] = presetPlaceholderPreview('preset-real-iban', 'NL91ABNA0417164300', 'asn-csv', str_repeat('d', 64));

    Livewire::actingAs($user)
        ->test(PreviewWizard::class, ['id' => $runId])
        ->assertSee(__('import::preview.unknown_iban_prefix'))
        ->assertDontSee(__('import::preview.unknown_account_prefix'));
});
