<?php

declare(strict_types=1);

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Modules\Core\Models\User;
use Modules\Core\Public\Services\UserCountry;
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

function writeAsnCsvFixture(string $body): string
{
    $tmp = tempnam(sys_get_temp_dir(), 'cp-stage-').'.csv';
    file_put_contents($tmp, $body);
    register_shutdown_function(static function () use ($tmp): void {
        @unlink($tmp);
    });

    return $tmp;
}

// The header is the verbatim ASN export header; one row per resolver branch.
function asnCsvWithFourBranches(): string
{
    $header = 'Datum,Je rekening,Van / naar,Naam,Adres,Postcode,Woonplaats,Valuta saldo,Saldo voor boeking,Valuta,Bedrag bij / af,Verwerkingsdatum,Valutadatum,Code,Type,Volgnummer,Betalingskenmerk,Omschrijving,Afschriftnummer,Categorie';

    // Every row carries a distinct counterpartyName so the fingerprints differ
    // and the ledger's composite UNIQUE keeps all four.
    $rows = [
        // Row 1 — merchant
        '02-02-2026,NL57ASNB0123456789,NL68BANK0000000001,Netflix Intl,,,,EUR,1000.00,EUR,-12.99,02-02-2026,02-02-2026,9714,EIC,901001,,\'NETFLIX.COM AMSTERDAM\',3,\'Streaming\'',
        // Row 2 — government
        '03-02-2026,NL57ASNB0123456789,NL86INGB0002445588,Belastingdienst,,,,EUR,987.01,EUR,-100.00,03-02-2026,03-02-2026,9714,EIC,901002,,\'BELASTINGDIENST INKOMSTENBELASTING 2025\',3,\'Belasting\'',
        // Row 3 — bank bridge (PayPal Luxembourg IBAN)
        '04-02-2026,NL57ASNB0123456789,LU89751000135104200E,PayPal Europe S.a.r.l.,,,,EUR,887.01,EUR,-25.00,04-02-2026,04-02-2026,9714,BEA,901003,,\'PayPal funding pull\',3,\'Online\'',
        // Row 4 — self-account: the IBAN is the user's own savings account
        '05-02-2026,NL57ASNB0123456789,NL09ASNB0987654321,Own savings,,,,EUR,862.01,EUR,-50.00,05-02-2026,05-02-2026,9714,OVB,901004,,\'OVERBOEKING NAAR EIGEN SPAREKENING\',3,\'Spaargeld\'',
    ];

    return $header."\n".implode("\n", $rows)."\n";
}

beforeEach(function (): void {
    // The primary matches column 2 of every fixture row; the savings account
    // matches Row 4's counterparty IBAN, which is what makes it a self leg.
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

    // The known-counterparty bridge needs an account of the alias's target
    // kind to resolve into.
    makeCpStageAccount(
        $this->user,
        kind: 'paypal',
        iban: 'PAYPAL',
        slug: 'paypal-cpstage',
        name: 'PayPal Synthetic',
    );

    // Without this the PayPal LU IBAN on Row 3 has no bridge to resolve over.
    app(DefaultKnownCounterpartyIbansSeeder::class)->run($this->user);

    // And without a country the Belastingdienst row resolves to unknown: the
    // government and bank-fee tiers stay silent until a reader names one, and
    // this fixture is an ASN export, so the reader is Dutch.
    app(UserCountry::class)->store($this->user->id, 'nl');

    // And without this Row 1's description resolves to no friendly name.
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

    // Four rows in, three counterparties out: the self_account leg
    // short-circuits before the upsert.
    expect($rows->count())->toBe(3);

    $byType = $rows->groupBy('type');
    expect($byType->keys()->sort()->values()->all())->toBe(['bank', 'government', 'merchant']);

    // Row 1's Naam column is the name the preview column shows, so it is the
    // name the committed entity carries; the corpus hit on its description is
    // kept under merchant_name.
    expect($byType['merchant']->first()->display_name)->toBe('Netflix Intl');
    expect($byType['merchant']->first()->merchant_name)->toBe('Netflix');
    expect($byType['government']->first()->display_name)->toContain('Belastingdienst');
    expect($byType['bank']->first()->display_name)->toContain('PayPal');

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

    // Three of the four: the fourth is the self_account leg.
    $withCounterparty = Transaction::query()
        ->where('user_id', $this->user->id)
        ->whereNotNull('counterparty_id')
        ->count();
    expect($withCounterparty)->toBe(3);

    $netflixId = (int) DB::table('counterparties')
        ->where('user_id', $this->user->id)
        ->where('display_name', 'Netflix Intl')
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

    // The resolver does return a DTO for this leg; the stage attaches an id
    // only when that DTO's counterpartyId is non-null.
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

    // The transactions side dedupes on fingerprint, but the resolver upsert
    // still runs for every preview row and has to land on the same rows.
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
    // These are singletons that capture the Dispatcher at construction, so an
    // already-built one would keep dispatching past the fake. Forget them and
    // the next make() rebuilds against the faked dispatcher.
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

    // The self_account branch short-circuits before the upsert and dispatch.
    Event::assertDispatchedTimes(CounterpartyResolved::class, 3);

    Event::assertDispatched(CounterpartyResolved::class, fn (CounterpartyResolved $e): bool => $e->userId === $this->user->id && $e->type === 'merchant');
    Event::assertDispatched(CounterpartyResolved::class, fn (CounterpartyResolved $e): bool => $e->userId === $this->user->id && $e->type === 'government');
    Event::assertDispatched(CounterpartyResolved::class, fn (CounterpartyResolved $e): bool => $e->userId === $this->user->id && $e->type === 'bank');
});
