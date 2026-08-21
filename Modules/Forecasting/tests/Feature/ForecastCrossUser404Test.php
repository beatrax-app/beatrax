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

it('returns 404 when user A requests /forecast with an account id owned by user B', function (): void {
    $this->actingAs($this->userA)
        ->get('/forecast?account='.$this->accountB)
        ->assertNotFound();
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
