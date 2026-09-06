<?php

declare(strict_types=1);

use Carbon\CarbonImmutable;
use Illuminate\Database\DatabaseManager;
use Modules\Counterparties\Models\Counterparty;
use Modules\Ledger\Models\Account;
use Modules\Ledger\Public\Services\TransactionListQuery;

beforeEach(function (): void {
    $this->seedFixtureUserAndAccount();
    $this->actingAs($this->fixtureUser);
    CarbonImmutable::setTestNow(CarbonImmutable::parse('2026-05-15 12:00:00'));

    /** @var Account $account */
    $account = Account::query()->where('iban', 'NL57ASNB0123456789')->firstOrFail();
    $this->account = $account;
    $this->run = $this->makeImportRun($this->fixtureUser);
    $this->listQuery = $this->app->make(TransactionListQuery::class);
});

afterEach(function (): void {
    CarbonImmutable::setTestNow();
});

it('exposes the resolved counterparty slug on the row DTO when counterparty_id is set', function (): void {
    /** @var Counterparty $netflix */
    $netflix = Counterparty::create([
        'user_id' => $this->fixtureUser->id,
        'type' => 'merchant',
        'slug' => 'netflix',
        'display_name' => 'Netflix',
        'merchant_name' => 'NETFLIX',
    ]);

    $this->makeTransaction($this->fixtureUser, $this->account, $this->run, [
        'amount_minor' => -1299,
        'posted_at' => '2026-05-10',
        'booked_at' => '2026-05-10 12:00:00',
        'counterparty_name' => 'Netflix',
        'counterparty_normalized' => 'netflix',
        'counterparty_id' => $netflix->id,
    ]);

    $page = $this->listQuery->recent($this->fixtureUser, daysBack: 90);

    expect($page->rows)->toHaveCount(1);
    expect($page->rows[0]->counterpartySlug)->toBe('netflix');
});

it('renders the counterparty name as a link routing to counterparties.profile when resolved', function (): void {
    /** @var Counterparty $netflix */
    $netflix = Counterparty::create([
        'user_id' => $this->fixtureUser->id,
        'type' => 'merchant',
        'slug' => 'netflix',
        'display_name' => 'Netflix',
        'merchant_name' => 'NETFLIX',
    ]);

    $this->makeTransaction($this->fixtureUser, $this->account, $this->run, [
        'amount_minor' => -1299,
        'posted_at' => '2026-05-10',
        'booked_at' => '2026-05-10 12:00:00',
        'counterparty_name' => 'Netflix',
        'counterparty_normalized' => 'netflix',
        'counterparty_id' => $netflix->id,
    ]);

    $response = $this->get('/transactions');

    $response->assertOk();
    $response->assertSee('href="'.route('counterparties.profile', ['slug' => 'netflix']).'"', false);
});

it('renders the counterparty name as plain text when counterparty_id is null', function (): void {
    $this->makeTransaction($this->fixtureUser, $this->account, $this->run, [
        'amount_minor' => -1299,
        'posted_at' => '2026-05-10',
        'booked_at' => '2026-05-10 12:00:00',
        'counterparty_name' => 'Unresolved Vendor',
        'counterparty_normalized' => 'unresolved vendor',
        'counterparty_id' => null,
    ]);

    $response = $this->get('/transactions');

    $response->assertOk();
    $response->assertSee('Unresolved Vendor');
    $response->assertSee('data-testid="tx-row-counterparty-text-', false);
    $response->assertDontSee('data-testid="tx-row-counterparty-link-', false);
});

it('renders self-account counterparties as links pointing at counterparties.profile (which shows the stub)', function (): void {
    /** @var Counterparty $selfAccount */
    $selfAccount = Counterparty::create([
        'user_id' => $this->fixtureUser->id,
        'type' => 'merchant',
        'slug' => 'paypal',
        'display_name' => 'PayPal',
        'merchant_name' => 'PAYPAL',
    ]);

    $this->makeTransaction($this->fixtureUser, $this->account, $this->run, [
        'amount_minor' => -1299,
        'posted_at' => '2026-05-10',
        'booked_at' => '2026-05-10 12:00:00',
        'counterparty_name' => 'PayPal',
        'counterparty_normalized' => 'paypal',
        'counterparty_id' => $selfAccount->id,
    ]);

    $response = $this->get('/transactions');

    $response->assertOk();
    $response->assertSee('href="'.route('counterparties.profile', ['slug' => 'paypal']).'"', false);
});

it('renders the transaction-detail counterparty cell as a link routing to counterparties.profile when resolved', function (): void {
    /** @var Counterparty $netflix */
    $netflix = Counterparty::create([
        'user_id' => $this->fixtureUser->id,
        'type' => 'merchant',
        'slug' => 'netflix',
        'display_name' => 'Netflix',
        'merchant_name' => 'NETFLIX',
    ]);

    $tx = $this->makeTransaction($this->fixtureUser, $this->account, $this->run, [
        'amount_minor' => -1299,
        'posted_at' => '2026-05-10',
        'booked_at' => '2026-05-10 12:00:00',
        'counterparty_name' => 'Netflix',
        'counterparty_normalized' => 'netflix',
        'counterparty_id' => $netflix->id,
    ]);

    $response = $this->get(route('transactions.show', ['transactionId' => $tx->id]));

    $response->assertOk();
    $response->assertSee('href="'.route('counterparties.profile', ['slug' => 'netflix']).'"', false);
});

it('renders the transaction-detail counterparty cell as plain text when counterparty_id is null', function (): void {
    $tx = $this->makeTransaction($this->fixtureUser, $this->account, $this->run, [
        'amount_minor' => -1299,
        'posted_at' => '2026-05-10',
        'booked_at' => '2026-05-10 12:00:00',
        'counterparty_name' => 'Unresolved Vendor',
        'counterparty_normalized' => 'unresolved vendor',
        'counterparty_id' => null,
    ]);

    $response = $this->get(route('transactions.show', ['transactionId' => $tx->id]));

    $response->assertOk();
    $response->assertSee('Unresolved Vendor');
    $response->assertSee('data-testid="tx-detail-counterparty-text', false);
    $response->assertDontSee('data-testid="tx-detail-counterparty-link', false);
});

it('eager-loads counterparties in a single JOIN — no N+1 expansion across the page render', function (): void {
    /** @var Counterparty $netflix */
    $netflix = Counterparty::create([
        'user_id' => $this->fixtureUser->id,
        'type' => 'merchant',
        'slug' => 'netflix',
        'display_name' => 'Netflix',
        'merchant_name' => 'NETFLIX',
    ]);
    /** @var Counterparty $spotify */
    $spotify = Counterparty::create([
        'user_id' => $this->fixtureUser->id,
        'type' => 'merchant',
        'slug' => 'spotify',
        'display_name' => 'Spotify',
        'merchant_name' => 'SPOTIFY',
    ]);

    for ($i = 0; $i < 5; $i++) {
        $day = sprintf('%02d', 5 + $i);
        $this->makeTransaction($this->fixtureUser, $this->account, $this->run, [
            'amount_minor' => -(1000 + $i),
            'posted_at' => "2026-05-{$day}",
            'booked_at' => "2026-05-{$day} 12:00:00",
            'counterparty_name' => $i % 2 === 0 ? 'Netflix' : 'Spotify',
            'counterparty_normalized' => $i % 2 === 0 ? 'netflix' : 'spotify',
            'counterparty_id' => $i % 2 === 0 ? $netflix->id : $spotify->id,
        ]);
    }

    /** @var DatabaseManager $db */
    $db = $this->app->make(DatabaseManager::class);
    $queries = [];
    $db->connection()->listen(static function ($event) use (&$queries): void {
        $queries[] = $event->sql;
    });

    $page = $this->listQuery->recent($this->fixtureUser, daysBack: 90);

    expect($page->rows)->toHaveCount(5);
    $counterpartyQueries = array_filter($queries, static fn (string $sql): bool => stripos($sql, '"counterparties"') !== false);
    expect(count($counterpartyQueries))->toBeLessThanOrEqual(1);
});

// A description-only line names nobody, so its own column stays null and the
// resolver mints the row the app names itself. Reading only the transaction
// column drew an em dash over 72 of 229 rows on a real phone, while the
// counterparty screens named the very same row one click away.
it('names a row whose own column is null from the counterparty behind it', function (): void {
    /** @var Counterparty $unknown */
    $unknown = Counterparty::create([
        'user_id' => $this->fixtureUser->id,
        'type' => 'unknown',
        'slug' => 'unknown',
        'display_name' => 'Unknown',
        'metadata' => ['default_name' => 'unknown'],
    ]);

    $this->makeTransaction($this->fixtureUser, $this->account, $this->run, [
        'amount_minor' => -4500,
        'posted_at' => '2026-05-10',
        'booked_at' => '2026-05-10 12:00:00',
        'counterparty_name' => null,
        'counterparty_id' => $unknown->id,
    ]);

    $page = $this->listQuery->recent($this->fixtureUser, daysBack: 90);

    expect($page->rows)->toHaveCount(1)
        ->and($page->rows[0]->counterpartyName)->toBe('Unknown');
});

// The file's own wording is what that row is called, so renaming the
// counterparty it resolved to must not rewrite it.
it('keeps the wording the statement supplied over the counterparty it resolved to', function (): void {
    /** @var Counterparty $netflix */
    $netflix = Counterparty::create([
        'user_id' => $this->fixtureUser->id,
        'type' => 'merchant',
        'slug' => 'netflix-renamed',
        'display_name' => 'Netflix (renamed by the reader)',
        'merchant_name' => 'NETFLIX',
    ]);

    $this->makeTransaction($this->fixtureUser, $this->account, $this->run, [
        'amount_minor' => -1299,
        'posted_at' => '2026-05-10',
        'booked_at' => '2026-05-10 12:00:00',
        'counterparty_name' => 'NETFLIX.COM',
        'counterparty_id' => $netflix->id,
    ]);

    $page = $this->listQuery->recent($this->fixtureUser, daysBack: 90);

    expect($page->rows[0]->counterpartyName)->toBe('NETFLIX.COM');
});

// The card is one click from the list and drew the same em dash, so the link
// it renders had no accessible name while pointing at a page titled "Unknown".
it('names the transaction-detail card from the counterparty when the row names nobody', function (): void {
    /** @var Counterparty $unknown */
    $unknown = Counterparty::create([
        'user_id' => $this->fixtureUser->id,
        'type' => 'unknown',
        'slug' => 'unknown',
        'display_name' => 'Unknown',
        'metadata' => ['default_name' => 'unknown'],
    ]);

    $tx = $this->makeTransaction($this->fixtureUser, $this->account, $this->run, [
        'amount_minor' => -4500,
        'posted_at' => '2026-05-10',
        'booked_at' => '2026-05-10 12:00:00',
        'counterparty_name' => null,
        'counterparty_id' => $unknown->id,
    ]);

    $response = $this->get(route('transactions.show', ['transactionId' => $tx->id]));

    $response->assertOk();
    $response->assertSee('>Unknown<', false);
});
