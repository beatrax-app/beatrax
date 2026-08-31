<?php

declare(strict_types=1);

use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Modules\Chains\Internal\Resolvers\PaypalFundingResolver;
use Modules\Chains\Models\ChainLink;
use Modules\Chains\Public\Enums\ChainLinkState;
use Modules\Core\Models\User;
use Modules\Ledger\Models\Account;
use Modules\Ledger\Models\ImportRun;
use Modules\Ledger\Models\Transaction;

uses(RefreshDatabase::class);

function overclaimAccount(User $user, string $slug, string $kind, string $iban): Account
{
    return Account::query()->create([
        'user_id' => $user->id,
        'name' => 'overclaim '.$slug,
        'slug' => $slug,
        'kind' => $kind,
        'iban' => $iban,
        'default_currency' => 'EUR',
    ]);
}

/**
 * @param  array<string, mixed>  $overrides
 */
function overclaimTx(User $user, Account $account, ImportRun $run, array $overrides): Transaction
{
    static $row = 0;
    $row++;

    return Transaction::query()->create(array_merge([
        'user_id' => $user->id,
        'account_id' => $account->id,
        'type' => 'transfer_in',
        'posted_at' => '2026-05-15',
        'booked_at' => '2026-05-15 12:00:00',
        'value_date' => '2026-05-15',
        'amount_minor' => 5000,
        'currency' => 'EUR',
        'settled_amount_minor' => 5000,
        'settled_currency' => 'EUR',
        'counterparty_name' => 'PayPal',
        'counterparty_normalized' => 'paypal',
        'normalization_version' => 3,
        'source_format' => 'asn-csv',
        'import_run_id' => $run->id,
        'source_row_index' => $row,
        'fingerprint' => str_pad('ovc'.$row, 64, 'o', STR_PAD_LEFT),
        'fingerprint_version' => 3,
    ], $overrides));
}

/**
 * @return array<int|string, mixed>
 */
function overclaimWithdrawalPayload(string $iban): array
{
    return [
        'format' => 'paypal-csv',
        'language' => 'nl',
        'events' => [
            [
                'type' => 'Bankstorting',
                'row' => ['Naam' => 'Withdraw to bank '.$iban, 'Reference Txn ID' => 'TXN-OVC'],
            ],
        ],
    ];
}

beforeEach(function (): void {
    $this->user = User::query()->create([
        'username' => 'paypal-overclaim',
        'password' => 'fixture-password',
        'period_start_day' => 1,
    ]);

    $this->asn = overclaimAccount($this->user, 'ovc-asn', 'bank', 'NL57ASNB0123456789');
    $this->paypal = overclaimAccount($this->user, 'ovc-paypal', 'paypal', 'PAYPAL');

    $this->run = ImportRun::query()->create([
        'user_id' => $this->user->id,
        'source_format' => 'paypal-csv',
        'raw_file_path' => '/tmp/paypal-overclaim.csv',
        'sha256' => str_repeat('v', 64),
        'uploaded_at' => CarbonImmutable::now(),
        'status' => 'previewed',
    ]);

    /** @var PaypalFundingResolver $resolver */
    $resolver = $this->app->make(PaypalFundingResolver::class);
    $this->resolver = $resolver;
});

// The PayPal export names the destination ACCOUNT and nothing about the row on
// it. A second incoming row of the same size in the same window is a second
// answer, and the arm used to pick one and write it confirmed at 1.000 — a
// state the review queue never shows, so nobody could disagree with it.
it('hands a deterministic match with two possible counter-legs to the review queue', function (): void {
    overclaimTx($this->user, $this->paypal, $this->run, [
        'type' => 'transfer_out',
        'amount_minor' => -5000,
        'settled_amount_minor' => -5000,
        'counterparty_name' => 'Bankstorting destination',
        'counterparty_normalized' => 'paypal-withdrawal',
        'source_format' => 'paypal-csv',
        'raw_payload' => overclaimWithdrawalPayload('NL57ASNB0123456789'),
    ]);

    // The real deposit, and a webshop refund that happens to be the same size
    // in the same week.
    overclaimTx($this->user, $this->asn, $this->run, ['posted_at' => '2026-05-15', 'booked_at' => '2026-05-15 12:00:00']);
    overclaimTx($this->user, $this->asn, $this->run, [
        'posted_at' => '2026-05-16',
        'booked_at' => '2026-05-16 12:00:00',
        'counterparty_name' => 'Webshop retour',
        'counterparty_normalized' => 'webshop-retour',
    ]);

    $this->resolver->resolveForUser($this->user);

    /** @var ChainLink $link */
    $link = ChainLink::query()->where('user_id', $this->user->id)->firstOrFail();

    expect($link->state)->toBe(ChainLinkState::Candidate->value);
    expect((float) $link->confidence)->toBeLessThan(1.0);
});

// amount_minor is the row's NATIVE amount, so the two sides were compared as
// bare integers: 5000 USD answered 5000 EUR, confirmed, confidence 1.0.
it('does not read a USD deposit as the counter-leg of a EUR withdrawal of the same number', function (): void {
    overclaimTx($this->user, $this->paypal, $this->run, [
        'type' => 'transfer_out',
        'amount_minor' => -5000,
        'settled_amount_minor' => -5000,
        'counterparty_name' => 'Bankstorting destination',
        'counterparty_normalized' => 'paypal-withdrawal',
        'source_format' => 'paypal-csv',
        'raw_payload' => overclaimWithdrawalPayload('NL57ASNB0123456789'),
    ]);

    overclaimTx($this->user, $this->asn, $this->run, [
        'currency' => 'USD',
        'settled_currency' => 'USD',
        'counterparty_name' => 'Dollar deposit',
        'counterparty_normalized' => 'dollar-deposit',
    ]);

    $this->resolver->resolveForUser($this->user);

    expect(ChainLink::query()->where('user_id', $this->user->id)->count())->toBe(0);
});

// 0.3 for an exact amount plus 0.2 for the same day is 0.5 of a 0.6 bar, so a
// merchant similarity of 0.2 was enough. These two are real Dutch names, and
// they scored 0.727 together.
it('refuses a fuzzy match whose merchant names only resemble each other by accident', function (): void {
    overclaimTx($this->user, $this->paypal, $this->run, [
        'type' => 'expense',
        'amount_minor' => -4250,
        'settled_amount_minor' => -4250,
        'counterparty_name' => 'Coolblue BV',
        'counterparty_normalized' => 'coolblue-bv',
        'source_format' => 'paypal-csv',
    ]);

    overclaimTx($this->user, $this->asn, $this->run, [
        'amount_minor' => 4250,
        'settled_amount_minor' => 4250,
        'counterparty_name' => 'Bol com BV',
        'counterparty_normalized' => 'bol-com-bv',
    ]);

    $this->resolver->resolveForUser($this->user);

    expect(ChainLink::query()->where('user_id', $this->user->id)->count())->toBe(0);
});

// The ASN-direct arm excludes a counter-leg another link already claims; the
// fuzzy arm did not, so two PayPal expenses named the same ASN deposit and one
// transaction sat in two chains.
it('never lets two PayPal expenses claim the same funding leg', function (): void {
    overclaimTx($this->user, $this->paypal, $this->run, [
        'type' => 'expense',
        'amount_minor' => -1999,
        'settled_amount_minor' => -1999,
        'counterparty_name' => 'Spotify AB',
        'counterparty_normalized' => 'spotify-ab-1',
        'source_format' => 'paypal-csv',
    ]);
    overclaimTx($this->user, $this->paypal, $this->run, [
        'type' => 'expense',
        'amount_minor' => -1999,
        'settled_amount_minor' => -1999,
        'counterparty_name' => 'Spotify AB',
        'counterparty_normalized' => 'spotify-ab-2',
        'source_format' => 'paypal-csv',
    ]);

    $funder = overclaimTx($this->user, $this->asn, $this->run, [
        'amount_minor' => 1999,
        'settled_amount_minor' => 1999,
        'counterparty_name' => 'Spotify AB',
        'counterparty_normalized' => 'spotify-funder',
    ]);

    $this->resolver->resolveForUser($this->user);

    $claims = ChainLink::query()
        ->where('user_id', $this->user->id)
        ->where('to_transaction_id', $funder->id)
        ->count();

    expect($claims)->toBe(1);
});

// posted_at is a DATE column and the bounds were datetimes, so
// '2026-05-12' < '2026-05-12 00:00:00' as strings and the window ran [-2, +3]
// while CounterLegWindow::DEFAULT_DAYS says three either way.
it('matches a funding leg three days before the expense, not only three days after', function (): void {
    overclaimTx($this->user, $this->paypal, $this->run, [
        'type' => 'expense',
        'amount_minor' => -1999,
        'settled_amount_minor' => -1999,
        'counterparty_name' => 'Spotify AB',
        'counterparty_normalized' => 'spotify-ab',
        'source_format' => 'paypal-csv',
    ]);

    $earlyFunder = overclaimTx($this->user, $this->asn, $this->run, [
        'posted_at' => '2026-05-12',
        'booked_at' => '2026-05-12 12:00:00',
        'amount_minor' => 1999,
        'settled_amount_minor' => 1999,
        'counterparty_name' => 'Spotify AB',
        'counterparty_normalized' => 'spotify-funder',
    ]);

    $this->resolver->resolveForUser($this->user);

    /** @var ChainLink $link */
    $link = ChainLink::query()->where('user_id', $this->user->id)->firstOrFail();

    expect((int) $link->to_transaction_id)->toBe($earlyFunder->id);
});

// The candidate query joined on kind alone, so a rejected link took its source
// row out of every future pass — the real funder importing a week later was
// never looked at. The pre-insert guard is what keeps the rejected PAIR out.
it('looks at a source row again after its only link was rejected', function (): void {
    overclaimTx($this->user, $this->paypal, $this->run, [
        'type' => 'expense',
        'amount_minor' => -1999,
        'settled_amount_minor' => -1999,
        'counterparty_name' => 'Spotify AB',
        'counterparty_normalized' => 'spotify-ab',
        'source_format' => 'paypal-csv',
    ]);

    // A weaker candidate: same amount, three days out, so the date term is worth
    // nothing and the score sits under the exact-day match seeded later.
    overclaimTx($this->user, $this->asn, $this->run, [
        'posted_at' => '2026-05-18',
        'booked_at' => '2026-05-18 12:00:00',
        'amount_minor' => 1999,
        'settled_amount_minor' => 1999,
        'counterparty_name' => 'Spotify AB',
        'counterparty_normalized' => 'spotify-wrong',
    ]);

    $this->resolver->resolveForUser($this->user);

    /** @var ChainLink $wrong */
    $wrong = ChainLink::query()->where('user_id', $this->user->id)->firstOrFail();
    $wrong->state = ChainLinkState::Rejected->value;
    $wrong->save();

    $realFunder = overclaimTx($this->user, $this->asn, $this->run, [
        'amount_minor' => 1999,
        'settled_amount_minor' => 1999,
        'counterparty_name' => 'Spotify AB',
        'counterparty_normalized' => 'spotify-right',
    ]);

    $this->resolver->resolveForUser($this->user);

    expect(ChainLink::query()->where('user_id', $this->user->id)->where('state', ChainLinkState::Rejected->value)->count())->toBe(1);
    expect(
        ChainLink::query()
            ->where('user_id', $this->user->id)
            ->where('to_transaction_id', $realFunder->id)
            ->exists()
    )->toBeTrue();
});

// Every arm signs on the funding ACCOUNT, so three confirmations of one
// merchant on one account count together however they were found. The
// ASN-direct arm used to sign on the counterparty IBAN instead.
it('signs a funding account with a key that names it, IBAN or no IBAN', function (): void {
    $ibanless = overclaimAccount($this->user, 'ovc-ibanless', 'bank', '');

    overclaimTx($this->user, $this->paypal, $this->run, [
        'type' => 'expense',
        'amount_minor' => -1999,
        'settled_amount_minor' => -1999,
        'counterparty_name' => 'Spotify AB',
        'counterparty_normalized' => 'spotify-ab',
        'source_format' => 'paypal-csv',
    ]);
    overclaimTx($this->user, $ibanless, $this->run, [
        'amount_minor' => 1999,
        'settled_amount_minor' => 1999,
        'counterparty_name' => 'Spotify AB',
        'counterparty_normalized' => 'spotify-funder',
    ]);

    $this->resolver->resolveForUser($this->user);

    /** @var ChainLink $link */
    $link = ChainLink::query()->where('user_id', $this->user->id)->firstOrFail();

    expect($link->evidence['matched_iban'])->toBe('account='.$ibanless->id);
    expect($link->evidence['signature_hash'])
        ->not->toBe(hash('sha256', 'spotify-ab|'))
        ->and($link->evidence['signature_hash'])->toBe(hash('sha256', 'spotify-ab|account='.$ibanless->id));
});
