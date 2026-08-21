<?php

declare(strict_types=1);

use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Livewire\Livewire;
use Modules\Core\Models\User;
use Modules\Import\Internal\Http\Livewire\AliasesSettingsPage;
use Modules\Import\Public\Services\AliasMatchPreviewQuery;
use Modules\Import\Public\Services\MerchantNameResolver;
use Modules\Ledger\Models\Account;
use Modules\Ledger\Models\ImportRun;

// The preview exists to answer "what will this alias rename?". Two ways it
// answered a different question than the one the importer asks:
//
//  - it scanned counterparty_name when the description was empty, while every
//    caller of MerchantNameResolver::resolve() hands it the description and
//    nothing else, and skips a row whose description is blank;
//  - it enforced a three-character floor that the save path did not, so the
//    one pattern the reader was told could not be tested was also the one they
//    could save without ever seeing its effect.

beforeEach(function (): void {
    /** @var User $user */
    $user = User::create([
        'username' => 'alias-preview-truth',
        'password' => 'fixture-password-12chars',
        'period_start_day' => 1,
    ]);
    $this->user = $user;

    $this->account = Account::create([
        'user_id' => $user->id,
        'name' => 'ASN',
        'slug' => 'asn-alias-preview-truth',
        'kind' => 'bank',
        'iban' => 'NL16ASNB0000000009',
        'default_currency' => 'EUR',
    ]);

    $this->importRun = ImportRun::create([
        'source_format' => 'asn-csv',
        'raw_file_path' => '/tmp/alias-preview-truth.csv',
        'sha256' => str_repeat('q', 64),
        'uploaded_at' => CarbonImmutable::now(),
        'status' => 'previewed',
    ]);
});

function seedAliasTruthRow(string $description, string $counterpartyName, int $rowIndex): void
{
    DB::table('transactions')->insert([
        'user_id' => test()->user->id,
        'account_id' => test()->account->id,
        'type' => 'expense',
        'posted_at' => '2026-05-25',
        'booked_at' => '2026-05-25 10:00:00',
        'value_date' => '2026-05-25',
        'amount_minor' => -100 - $rowIndex,
        'currency' => 'EUR',
        'settled_amount_minor' => -100 - $rowIndex,
        'settled_currency' => 'EUR',
        'fx_rate_used' => null,
        'counterparty_name' => $counterpartyName,
        'counterparty_iban' => null,
        'counterparty_normalized' => mb_strtolower($counterpartyName),
        'normalization_version' => 1,
        'description' => $description,
        'category_id' => null,
        'source_format' => 'asn-csv',
        'import_run_id' => test()->importRun->id,
        'source_row_index' => $rowIndex,
        'source_ref' => 'alias-truth-'.$rowIndex,
        'fingerprint' => str_pad('t-'.$rowIndex, 64, 'z'),
        'fingerprint_version' => 3,
        'status' => 'cleared',
        'payment_type' => 'unknown',
        'created_at' => '2026-05-25 10:00:00',
        'updated_at' => '2026-05-25 10:00:00',
    ]);
}

it('does not count a row the alias can never reach, because its description is empty', function (): void {
    seedAliasTruthRow('', 'ALBERT HEIJN 1234', 0);

    /** @var MerchantNameResolver $resolver */
    $resolver = app(MerchantNameResolver::class);
    DB::table('merchant_aliases')->insert([
        'user_id' => $this->user->id,
        'pattern' => 'ALBERT HEIJN 1234',
        'generalized_pattern' => 'albert',
        'friendly_name' => 'Albert Heijn',
        'created_at' => '2026-05-25 10:00:00',
        'updated_at' => '2026-05-25 10:00:00',
    ]);

    // What the importer actually does with that row: it never asks.
    expect($resolver->resolve('', $this->user->id))->toBeNull();

    /** @var AliasMatchPreviewQuery $preview */
    $preview = app(AliasMatchPreviewQuery::class);

    expect($preview->preview('albert', $this->user->id)->total)->toBe(0);
});

it('still counts the row the alias will reach', function (): void {
    seedAliasTruthRow('BETAALAUTOMAAT ALBERT HEIJN 1234', 'ALBERT HEIJN 1234', 1);

    /** @var AliasMatchPreviewQuery $preview */
    $preview = app(AliasMatchPreviewQuery::class);

    expect($preview->preview('albert', $this->user->id)->total)->toBe(1);
});

it('refuses to save a pattern shorter than the one the preview will test', function (): void {
    $aliasId = (int) DB::table('merchant_aliases')->insertGetId([
        'user_id' => $this->user->id,
        'pattern' => 'NS-GROEP REIZEN',
        'generalized_pattern' => 'ns-groep',
        'friendly_name' => 'NS',
        'created_at' => '2026-05-25 10:00:00',
        'updated_at' => '2026-05-25 10:00:00',
    ]);

    $this->actingAs($this->user);

    Livewire::test(AliasesSettingsPage::class)
        ->call('startEdit', $aliasId)
        ->set('editingPattern', '-')
        ->call('saveAlias', $aliasId);

    expect(DB::table('merchant_aliases')->where('id', $aliasId)->value('generalized_pattern'))
        ->toBe('ns-groep');
});

it('accepts a pattern at the floor the preview tests', function (): void {
    $aliasId = (int) DB::table('merchant_aliases')->insertGetId([
        'user_id' => $this->user->id,
        'pattern' => 'NS-GROEP REIZEN',
        'generalized_pattern' => 'ns-groep',
        'friendly_name' => 'NS',
        'created_at' => '2026-05-25 10:00:00',
        'updated_at' => '2026-05-25 10:00:00',
    ]);

    $this->actingAs($this->user);

    Livewire::test(AliasesSettingsPage::class)
        ->call('startEdit', $aliasId)
        ->set('editingPattern', str_repeat('n', AliasMatchPreviewQuery::MIN_PATTERN_LENGTH))
        ->call('saveAlias', $aliasId);

    expect(DB::table('merchant_aliases')->where('id', $aliasId)->value('generalized_pattern'))
        ->toBe(str_repeat('n', AliasMatchPreviewQuery::MIN_PATTERN_LENGTH));
});
