<?php

declare(strict_types=1);

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Modules\Core\Models\User;
use Modules\Counterparties\Internal\Pipeline\ResolveCounterpartyStage;
use Modules\Counterparties\Internal\Resolver\CounterpartyResolverService;
use Modules\Counterparties\Public\Contracts\CounterpartyResolver;
use Modules\Counterparties\Public\Events\CounterpartyResolved;
use Modules\Counterparties\Public\Pipeline\ResolvesCounterparties;
use Modules\Import\Database\Seeders\DefaultKnownCounterpartyIbansSeeder;
use Modules\Import\Internal\Pipeline\ImportPipeline;
use Modules\Import\Public\Contracts\RunsImports;
use Modules\Import\Public\Enums\BankCsvFormatHint;
use Modules\Ledger\Models\Account;
use Modules\Ledger\Models\Transaction;

uses(RefreshDatabase::class);

/*
 * End-to-end Feature coverage for the wave-2 ResolveCounterpartyStage
 * inside the ImportPipeline. Each test drives the real ImportPipeline
 * via the RunsImports Public contract against a single inline CSV
 * fixture written to a temp file so the suite has no on-disk
 * fixture-file drift to maintain.
 *
 * The fixture exercises the load-bearing branches of the 7-step
 * resolver chain — a merchant row (Netflix), a government row
 * (Belastingdienst), a bank-bridge row (PayPal Luxembourg IBAN), and
 * a self-account leg whose counterparty IBAN equals one of the user's
 * own accounts. The assertions pin both the resolver's per-row DTO
 * shape (via the counterparty rows it materialises) and the pipeline's
 * persistence of counterparty_id onto every resolved transactions row.
 */
function makeCpStageUser(string $username = 'cp-stage-fixture'): User
{
    return User::query()->create([
        'username' => $username,
        'password' => 'fixture-password-12chars',
        'period_start_day' => 1,
    ]);
}

function makeCpStageAccount(User $user, string $kind, string $iban, string $slug, string $name): Account
{
    return Account::query()->create([
        'user_id' => $user->id,
        'name' => $name,
        'slug' => $slug,
        'kind' => $kind,
        'iban' => $iban,
        'default_currency' => 'EUR',
    ]);
}

/**
 * Writes the ASN-CSV fixture body to a temp file and returns the
 * absolute path; the file is unlinked at process shutdown. The header
 * mirrors the real ASN export with the columns the project's
 * AsnCsvAdapter reads.
 */
function writeAsnCsvFixture(string $body): string
{
    $tmp = tempnam(sys_get_temp_dir(), 'cp-stage-').'.csv';
    file_put_contents($tmp, $body);
    register_shutdown_function(static function () use ($tmp): void {
        @unlink($tmp);
    });

    return $tmp;
}

/**
 * The fixture CSV used by every test in this file. Header is the
 * verbatim ASN export header; each row exercises one resolver branch:
 *
 *   - Row 1 — merchant: NETFLIX.COM AMSTERDAM description
 *   - Row 2 — government: BELASTINGDIENST in the description
 *   - Row 3 — bank bridge: counterparty IBAN matches PayPal Luxembourg
 *     known-counterparty alias
 *   - Row 4 — self-account: counterparty IBAN matches one of the
 *     user's own accounts (the second ASN account seeded below)
 */
function asnCsvWithFourBranches(): string
{
    $header = 'Datum,Je rekening,Van / naar,Naam,Adres,Postcode,Woonplaats,Valuta saldo,Saldo voor boeking,Valuta,Bedrag bij / af,Verwerkingsdatum,Valutadatum,Code,Type,Volgnummer,Betalingskenmerk,Omschrijving,Afschriftnummer,Categorie';

    // Each row carries a unique counterpartyName so the
    // counterparty_normalized fingerprint differs even when other
    // columns repeat — keeps the four rows distinct in the ledger
    // composite-UNIQUE check.
    $rows = [
        // Row 1 — merchant
        '02-02-2026,NL57ASNB0123456789,NL68BANK0000000001,Netflix Intl,,,,EUR,1000.00,EUR,-12.99,02-02-2026,02-02-2026,9714,EIC,901001,,\'NETFLIX.COM AMSTERDAM\',3,\'Streaming\'',
        // Row 2 — government
        '03-02-2026,NL57ASNB0123456789,NL86INGB0002445588,Belastingdienst,,,,EUR,987.01,EUR,-100.00,03-02-2026,03-02-2026,9714,EIC,901002,,\'BELASTINGDIENST INKOMSTENBELASTING 2025\',3,\'Belasting\'',
        // Row 3 — bank bridge (PayPal Luxembourg IBAN)
        '04-02-2026,NL57ASNB0123456789,LU89751000135104200E,PayPal Europe S.a.r.l.,,,,EUR,887.01,EUR,-25.00,04-02-2026,04-02-2026,9714,BEA,901003,,\'PayPal funding pull\',3,\'Online\'',
        // Row 4 — self-account: counterparty IBAN belongs to user's
        // second ASN account (the savings account seeded in beforeEach)
        '05-02-2026,NL57ASNB0123456789,NL09ASNB0987654321,Own savings,,,,EUR,862.01,EUR,-50.00,05-02-2026,05-02-2026,9714,OVB,901004,,\'OVERBOEKING NAAR EIGEN SPAREKENING\',3,\'Spaargeld\'',
    ];

    return $header."\n".implode("\n", $rows)."\n";
}

beforeEach(function (): void {
    // Two ASN accounts: the primary (matches column 2 of every fixture
    // row) and the "own savings" target (matches Row 4's counterparty
    // IBAN — exercises the self_account branch).
    $this->user = makeCpStageUser();
    $this->primaryAccount = makeCpStageAccount(
        $this->user,
        kind: 'bank',
        iban: 'NL57ASNB0123456789',
        slug: 'asn-primary-cpstage',
        name: 'ASN Primary',
    );
    $this->savingsAccount = makeCpStageAccount(
        $this->user,
        kind: 'bank',
        iban: 'NL09ASNB0987654321',
        slug: 'asn-savings-cpstage',
        name: 'ASN Savings',
    );

    // PayPal-kind synthetic account so the known-counterparty bridge
    // has an account of the alias's target kind to resolve into.
    makeCpStageAccount(
        $this->user,
        kind: 'paypal',
        iban: 'PAYPAL',
        slug: 'paypal-cpstage',
        name: 'PayPal Synthetic',
    );

    // Seed the known-counterparty IBAN bridge data so step 2
    // (known-bridge) of the resolver can succeed for the PayPal LU
    // IBAN on Row 3.
    app(DefaultKnownCounterpartyIbansSeeder::class)->run($this->user);

    // Merchant alias so step 3 (merchant) of the resolver resolves
    // the Row 1 description to a friendly name.
    DB::table('merchant_aliases')->insert([
        'user_id' => $this->user->id,
        'pattern' => 'NETFLIX.COM AMSTERDAM',
        'generalized_pattern' => 'netflix',
        'friendly_name' => 'Netflix',
        'merged_from' => null,
        'created_at' => now()->toDateTimeString(),
        'updated_at' => now()->toDateTimeString(),
    ]);

    $this->importer = $this->app->make(RunsImports::class);
    $this->fixturePath = writeAsnCsvFixture(asnCsvWithFourBranches());
});

it('Test 1 — end-to-end ImportPipeline materialises a counterparty row of every active type (merchant, government, bank) and zero rows for the self_account leg', function (): void {
    $result = $this->importer->runAndConfirm(
        $this->fixturePath,
        'asn-csv',
        $this->user,
        'fixture.csv',
        BankCsvFormatHint::Asn,
    );

    expect($result->inserted)->toBe(4);
    expect($result->errors)->toBe(0);

    $rows = DB::table('counterparties')->where('user_id', $this->user->id)->get();

    // Three counterparties materialise (merchant, government, bank);
    // the self_account leg short-circuits the upsert and leaves
    // counterparties.count() at three for this user.
    expect($rows->count())->toBe(3);

    $byType = $rows->groupBy('type');
    expect($byType->keys()->sort()->values()->all())->toBe(['bank', 'government', 'merchant']);

    expect($byType['merchant']->first()->display_name)->toBe('Netflix');
    expect($byType['government']->first()->display_name)->toContain('Belastingdienst');
    expect($byType['bank']->first()->display_name)->toContain('PayPal');

    // Hard guarantee for the self_account branch: NO counterparty row
    // of type=self_account was ever written.
    expect(DB::table('counterparties')->where('user_id', $this->user->id)->where('type', 'self_account')->count())->toBe(0);
});

it('Test 2 — counterparty_id is populated on every resolved transaction row (except self_account)', function (): void {
    $this->importer->runAndConfirm(
        $this->fixturePath,
        'asn-csv',
        $this->user,
        'fixture.csv',
        BankCsvFormatHint::Asn,
    );

    // Three of the four imported rows must carry a non-null
    // counterparty_id (one per type — merchant, government, bank);
    // the fourth row is the self_account leg and stays null.
    $withCounterparty = Transaction::query()
        ->where('user_id', $this->user->id)
        ->whereNotNull('counterparty_id')
        ->count();
    expect($withCounterparty)->toBe(3);

    // Spot-check the merchant row points at the Netflix counterparty.
    $netflixId = (int) DB::table('counterparties')
        ->where('user_id', $this->user->id)
        ->where('display_name', 'Netflix')
        ->value('id');
    expect($netflixId)->toBeGreaterThan(0);

    $netflixTxCount = Transaction::query()
        ->where('user_id', $this->user->id)
        ->where('counterparty_id', $netflixId)
        ->count();
    expect($netflixTxCount)->toBe(1);
});

it('Test 3 — self_account leg leaves transactions.counterparty_id NULL', function (): void {
    $this->importer->runAndConfirm(
        $this->fixturePath,
        'asn-csv',
        $this->user,
        'fixture.csv',
        BankCsvFormatHint::Asn,
    );

    // The self-account leg is the row whose counterparty_iban is the
    // user's savings IBAN. It carries no counterparty_id even though
    // the resolver returned a (non-null, type=self_account) DTO,
    // because the stage attaches an ID only when the DTO carries
    // counterpartyId !== null.
    $selfLeg = Transaction::query()
        ->where('user_id', $this->user->id)
        ->where('counterparty_iban', 'NL09ASNB0987654321')
        ->first();

    expect($selfLeg)->not->toBeNull();
    expect($selfLeg->counterparty_id)->toBeNull();
});

it('Test 4 — re-importing the same fixture does NOT create duplicate counterparties (idempotent)', function (): void {
    $this->importer->runAndConfirm(
        $this->fixturePath,
        'asn-csv',
        $this->user,
        'fixture.csv',
        BankCsvFormatHint::Asn,
    );
    $firstCount = DB::table('counterparties')->where('user_id', $this->user->id)->count();
    expect($firstCount)->toBe(3);

    // Re-import the exact same file. The transactions side dedupes via
    // fingerprint (insertOrIgnore returns 0 inserted); the resolver
    // upsert still runs for each preview row and MUST land on the same
    // counterparties rows (firstOrCreate keyed on user_id+slug).
    $this->importer->runAndConfirm(
        $this->fixturePath,
        'asn-csv',
        $this->user,
        'fixture.csv',
        BankCsvFormatHint::Asn,
    );

    $secondCount = DB::table('counterparties')->where('user_id', $this->user->id)->count();
    expect($secondCount)->toBe($firstCount);
});

it('Test 5 — CounterpartyResolved event fires for every materialised counterparty', function (): void {
    // Fake the dispatcher BEFORE resolving any service that captures
    // the Dispatcher through DI. The CounterpartyResolverService and
    // the ResolveCounterpartyStage are bound as singletons on the
    // Counterparties service provider; once they construct they
    // cache the original Dispatcher and bypass the faked one. Flush
    // any cached singletons that capture the Dispatcher so the next
    // `app->make()` rebuilds them against the faked dispatcher.
    Event::fake([CounterpartyResolved::class]);
    $this->app->forgetInstance(CounterpartyResolver::class);
    $this->app->forgetInstance(CounterpartyResolverService::class);
    $this->app->forgetInstance(ResolvesCounterparties::class);
    $this->app->forgetInstance(ResolveCounterpartyStage::class);
    $this->app->forgetInstance(ImportPipeline::class);
    $this->app->forgetInstance(RunsImports::class);

    $importer = $this->app->make(RunsImports::class);

    $importer->runAndConfirm(
        $this->fixturePath,
        'asn-csv',
        $this->user,
        'fixture.csv',
        BankCsvFormatHint::Asn,
    );

    // Three materialised counterparties = three events; the
    // self_account branch short-circuits before the upsert + dispatch.
    Event::assertDispatchedTimes(CounterpartyResolved::class, 3);

    // Each event must carry the right (counterpartyId, userId, type)
    // tuple.
    Event::assertDispatched(CounterpartyResolved::class, fn (CounterpartyResolved $e): bool => $e->userId === $this->user->id && $e->type === 'merchant');
    Event::assertDispatched(CounterpartyResolved::class, fn (CounterpartyResolved $e): bool => $e->userId === $this->user->id && $e->type === 'government');
    Event::assertDispatched(CounterpartyResolved::class, fn (CounterpartyResolved $e): bool => $e->userId === $this->user->id && $e->type === 'bank');
});
