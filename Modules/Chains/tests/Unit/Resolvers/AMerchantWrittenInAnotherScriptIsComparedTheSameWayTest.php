<?php

declare(strict_types=1);

use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Modules\Chains\Internal\Resolvers\PaypalFundingResolver;
use Modules\Chains\Models\ChainLink;
use Modules\Core\Models\User;
use Modules\Ledger\Models\Account;
use Modules\Ledger\Models\ImportRun;
use Modules\Ledger\Models\Transaction;

// The fuzzy arm divided levenshtein()'s BYTE distance by the name's CHARACTER
// length, so the same two spellings scored differently depending on the script
// they were written in: "netflix" against "netflix int" is 0.636 and clears the
// 0.6 merchant floor, while the same four characters added to the same word in
// Greek scored 0.364 and were dropped. FingerprintComposer::normalize() strips
// diacritics but keeps every \p{L}, so Greek, Cyrillic and CJK names reach the
// comparison two and three bytes to the character.

uses(RefreshDatabase::class);

/**
 * @return array{user: User, paypal: Account, asn: Account, run: ImportRun}
 */
function scriptParityFixture(): array
{
    $user = User::query()->create([
        'username' => 'script-parity-'.bin2hex(random_bytes(3)),
        'password' => 'fixture-password',
        'period_start_day' => 1,
    ]);

    $paypal = Account::query()->create([
        'user_id' => $user->id,
        'name' => 'PayPal fixture',
        'slug' => 'sp-paypal',
        'kind' => 'paypal',
        'iban' => 'PAYPALSP',
        'default_currency' => 'EUR',
    ]);

    $asn = Account::query()->create([
        'user_id' => $user->id,
        'name' => 'ASN fixture',
        'slug' => 'sp-asn',
        'kind' => 'bank',
        'iban' => 'NL57ASNB0987654321',
        'default_currency' => 'EUR',
    ]);

    $run = ImportRun::query()->create([
        'user_id' => $user->id,
        'source_format' => 'paypal-csv',
        'raw_file_path' => '/tmp/script-parity.csv',
        'sha256' => str_repeat('s', 64),
        'uploaded_at' => CarbonImmutable::now(),
        'status' => 'previewed',
    ]);

    return ['user' => $user, 'paypal' => $paypal, 'asn' => $asn, 'run' => $run];
}

/**
 * @param  array{user: User, paypal: Account, asn: Account, run: ImportRun}  $fixture
 */
function scriptParityPair(array $fixture, string $expenseName, string $funderName, string $seed): int
{
    Transaction::query()->create([
        'user_id' => $fixture['user']->id,
        'account_id' => $fixture['paypal']->id,
        'type' => 'expense',
        'posted_at' => '2026-05-15',
        'booked_at' => '2026-05-15 12:00:00',
        'value_date' => '2026-05-15',
        'amount_minor' => -1999,
        'currency' => 'EUR',
        'settled_amount_minor' => -1999,
        'settled_currency' => 'EUR',
        'counterparty_name' => $expenseName,
        'counterparty_normalized' => mb_strtolower($expenseName),
        'normalization_version' => 3,
        'source_format' => 'paypal-csv',
        'import_run_id' => $fixture['run']->id,
        'source_row_index' => 2,
        'fingerprint' => str_pad($seed.'e', 64, 'e', STR_PAD_LEFT),
        'fingerprint_version' => 3,
    ]);

    Transaction::query()->create([
        'user_id' => $fixture['user']->id,
        'account_id' => $fixture['asn']->id,
        'type' => 'transfer_in',
        'posted_at' => '2026-05-15',
        'booked_at' => '2026-05-15 12:00:00',
        'value_date' => '2026-05-15',
        'amount_minor' => 1999,
        'currency' => 'EUR',
        'settled_amount_minor' => 1999,
        'settled_currency' => 'EUR',
        'counterparty_name' => $funderName,
        'counterparty_normalized' => mb_strtolower($funderName),
        'normalization_version' => 3,
        'source_format' => 'asn-csv',
        'import_run_id' => $fixture['run']->id,
        'source_row_index' => 7,
        'fingerprint' => str_pad($seed.'a', 64, 'a', STR_PAD_LEFT),
        'fingerprint_version' => 3,
    ]);

    app(PaypalFundingResolver::class)->resolveForUser($fixture['user']);

    return ChainLink::query()
        ->where('user_id', $fixture['user']->id)
        ->where('kind', 'paypal_funding')
        ->count();
}

it('links a funding leg whose merchant differs by four characters in the Latin script', function (): void {
    expect(scriptParityPair(scriptParityFixture(), 'netflix int', 'netflix', 'lat'))->toBe(1);
});

it('links the same four-character difference written in Greek', function (): void {
    expect(scriptParityPair(scriptParityFixture(), 'νετφλιξ ιντ', 'νετφλιξ', 'gre'))->toBe(1);
});
