<?php

declare(strict_types=1);

use Illuminate\Database\DatabaseManager;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Modules\Core\Models\User;

uses(RefreshDatabase::class);

function fc404User(string $username): User
{
    return User::query()->create([
        'username' => $username,
        'password' => 'fixture-password',
        'period_start_day' => 1,
        'default_currency_view' => 'eur_only',
    ]);
}

function fc404Account(DatabaseManager $db, int $userId): int
{
    $suffix = bin2hex(random_bytes(4));

    return $db->connection()->table('accounts')->insertGetId([
        'user_id' => $userId,
        'name' => 'CrossUser Test',
        'slug' => 'fc404-'.$suffix,
        'kind' => 'bank',
        'iban' => 'NL00FC4'.strtoupper($suffix),
        'default_currency' => 'EUR',
        'opening_balance_minor' => 100000,
        'opening_balance_as_of_date' => '2026-05-01',
        'created_at' => '2026-05-01 00:00:00',
        'updated_at' => '2026-05-01 00:00:00',
    ]);
}

beforeEach(function (): void {
    /** @var DatabaseManager $db */
    $db = $this->app->make(DatabaseManager::class);
    $this->db = $db;

    $this->userA = fc404User('user-a');
    $this->userB = fc404User('user-b');

    $this->accountA = fc404Account($this->db, $this->userA->id);
    $this->accountB = fc404Account($this->db, $this->userB->id);
});

// The two cases a caller can ask about have to be answered the same way.
// A 404 for "exists, but not yours" and a rendered page for "exists for
// nobody" made the id space probeable: walking ?account= told an outsider
// exactly which ids another household owns.
it('answers an account id owned by user B exactly as it answers one owned by nobody', function (): void {
    $neverExisted = $this->accountA + $this->accountB + 1_000;

    $foreign = $this->actingAs($this->userA)->get('/forecast?account='.$this->accountB);
    $absent = $this->actingAs($this->userA)->get('/forecast?account='.$neverExisted);

    expect($foreign->getStatusCode())->toBe($absent->getStatusCode())
        ->and($foreign->getStatusCode())->toBe(200);
});

// The rendered page must not become the leak the 404 was: it answers with the
// reader's own aggregate and none of the neighbour's rows. The panel names the
// tab it belongs to, and that name is the only thing on the page the ownership
// walk decides — the tab strip itself is built from the reader's own accounts.
it('renders user A\'s own view rather than anything belonging to user B', function (): void {
    $response = $this->actingAs($this->userA)->get('/forecast?account='.$this->accountB);
    $content = (string) $response->getContent();

    $response->assertOk();

    expect($content)->toContain('aria-labelledby="forecast-account-tab-all"')
        ->and($content)->toContain('forecast-account-tab-'.$this->accountA)
        ->and($content)->not->toContain('forecast-account-tab-'.$this->accountB);
});

it('returns 200 when user A requests /forecast with their own account id', function (): void {
    $this->actingAs($this->userA)
        ->get('/forecast?account='.$this->accountA)
        ->assertOk();
});

it('returns 200 when user A requests /forecast with no account param (defaults to first owned account)', function (): void {
    $this->actingAs($this->userA)
        ->get('/forecast')
        ->assertOk();
});

it('redirects unauthenticated visits to /login', function (): void {
    $this->get('/forecast')->assertRedirect('/login');
});
