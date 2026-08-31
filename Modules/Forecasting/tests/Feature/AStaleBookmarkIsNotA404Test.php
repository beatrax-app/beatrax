<?php

declare(strict_types=1);

use Illuminate\Database\DatabaseManager;
use Modules\Core\Models\User;
use Modules\Ledger\Models\Account;

function staleUser(): User
{
    return User::query()->create([
        'username' => 'stale-'.bin2hex(random_bytes(4)),
        'password' => 'fixture-password',
        'period_start_day' => 1,
        'default_currency_view' => 'eur_only',
    ]);
}

beforeEach(function (): void {
    /** @var DatabaseManager $db */
    $db = $this->app->make(DatabaseManager::class);
    $this->db = $db;
    $this->user = staleUser();
    Account::query()->create([
        'user_id' => $this->user->id,
        'name' => 'stale asn',
        'slug' => 'stale-asn-'.bin2hex(random_bytes(4)),
        'kind' => 'bank',
        'iban' => 'STALE-'.strtoupper(bin2hex(random_bytes(4))),
        'default_currency' => 'EUR',
    ]);
});

// The horizon and the non-numeric account already soft-reset; these two did
// not, so a bookmark outlived by its target was a hard 404.
it('renders the aggregate view for an account id that exists for nobody', function (): void {
    $this->actingAs($this->user)->get('/forecast?account=424242')->assertOk();
});

it('renders the baseline for a scenario deleted in another tab', function (): void {
    $this->actingAs($this->user)->get('/forecast?scenarioId=42')->assertOk();
});

// The soft reset covers a bookmark naming somebody else's row too. Refusing
// only that one told a caller the id exists, which is the whole of an
// existence oracle: the two cases have to be indistinguishable.
it('answers an account another reader owns the same way, rather than confirming it exists', function (): void {
    $other = staleUser();
    $account = Account::query()->create([
        'user_id' => $other->id,
        'name' => 'stale other',
        'slug' => 'stale-other-'.bin2hex(random_bytes(4)),
        'kind' => 'bank',
        'iban' => 'OTHR-'.strtoupper(bin2hex(random_bytes(4))),
        'default_currency' => 'EUR',
    ]);

    $this->actingAs($this->user)->get('/forecast?account='.$account->id)->assertOk();
});

it('still soft-resets the two the page already handled', function (): void {
    $this->actingAs($this->user)->get('/forecast?horizon=999')->assertOk();
    $this->actingAs($this->user)->get('/forecast?account=zzz')->assertOk();
});
