<?php

declare(strict_types=1);

use Carbon\CarbonImmutable;
use Illuminate\Database\DatabaseManager;
use Livewire\Livewire;
use Modules\Core\Internal\Http\Livewire\NetWorthCard;
use Modules\Core\Models\User;

// Guarded against a redeclaration fatal when several test files load together.
if (! function_exists('nwCardAccount')) {
    function nwCardAccount(DatabaseManager $db, int $userId, string $name, string $kind, int $openingMinor, string $currency = 'EUR'): int
    {
        return $db->connection()->table('accounts')->insertGetId([
            'user_id' => $userId,
            'name' => $name,
            'slug' => strtolower($name).'-'.bin2hex(random_bytes(3)),
            'kind' => $kind,
            'iban' => 'NL00CARD'.str_pad((string) random_int(1, 99999999), 8, '0', STR_PAD_LEFT),
            'default_currency' => $currency,
            'opening_balance_minor' => $openingMinor,
            'opening_balance_as_of_date' => '2026-01-01',
            'created_at' => '2026-01-01 00:00:00',
            'updated_at' => '2026-01-01 00:00:00',
        ]);
    }
}

if (! function_exists('nwCardFxRate')) {
    function nwCardFxRate(DatabaseManager $db, string $quote, string $rate, string $date = '2026-06-05', string $source = 'ecb'): void
    {
        $db->connection()->table('exchange_rates')->updateOrInsert(
            ['base_currency' => 'EUR', 'quote_currency' => $quote, 'rate_date' => $date, 'source' => $source],
            ['rate' => $rate, 'created_at' => now(), 'updated_at' => now()],
        );
    }
}

beforeEach(function (): void {
    $this->db = app(DatabaseManager::class);
    $this->user = User::create([
        'username' => 'nwcard-fx-fixture',
        'password' => 'fixture-password-12chars',
        'period_start_day' => 1,
        'base_currency' => 'EUR',
    ]);
    $this->actingAs($this->user);

    // Freeze time so staleness checks are deterministic
    CarbonImmutable::setTestNow(CarbonImmutable::parse('2026-06-07 12:00:00'));
});

afterEach(function (): void {
    CarbonImmutable::setTestNow();
});

it('renders NO fx-disclosure-trigger and NO rates line for a passthrough (all-EUR) card', function (): void {
    // Only EUR accounts — ratesSource stays null → zero-overhead passthrough
    nwCardAccount($this->db, $this->user->id, 'Checking', 'bank', 150_000, 'EUR');

    Livewire::test(NetWorthCard::class)
        ->assertDontSee('fx-disclosure-trigger', escape: false)
        ->assertDontSee('rates as of');
})->group('phase-1');

it('renders the fx-disclosure-trigger and global rates line when conversion is active', function (): void {
    nwCardAccount($this->db, $this->user->id, 'Checking', 'bank', 200_000, 'EUR');
    nwCardAccount($this->db, $this->user->id, 'USD wallet', 'paypal', 10_000, 'USD');
    // Fresh rate (within 3-day threshold from 2026-06-07 → rate at 2026-06-05 is 2 days ago)
    nwCardFxRate($this->db, 'USD', '1.08', '2026-06-05', 'ecb');

    Livewire::test(NetWorthCard::class)
        ->assertSee('fx-disclosure-trigger', escape: false)
        ->assertSee('rates as of');
})->group('phase-1');

it('adds fx-icon--stale modifier when rates are stale or bundled', function (): void {
    nwCardAccount($this->db, $this->user->id, 'Checking', 'bank', 200_000, 'EUR');
    nwCardAccount($this->db, $this->user->id, 'USD wallet', 'paypal', 10_000, 'USD');
    // Old rate (> 3 days from 2026-06-07 → stale)
    nwCardFxRate($this->db, 'USD', '1.08', '2026-05-01', 'bundled');

    Livewire::test(NetWorthCard::class)
        ->assertSee('fx-icon--stale', escape: false);
})->group('phase-1');

it('does NOT add fx-icon--stale when rates are fresh', function (): void {
    nwCardAccount($this->db, $this->user->id, 'Checking', 'bank', 200_000, 'EUR');
    nwCardAccount($this->db, $this->user->id, 'USD wallet', 'paypal', 10_000, 'USD');
    // Fresh rate (2 days ago from 2026-06-07)
    nwCardFxRate($this->db, 'USD', '1.08', '2026-06-05', 'ecb');

    Livewire::test(NetWorthCard::class)
        ->assertDontSee('fx-icon--stale', escape: false);
})->group('phase-1');

it('renders the no-rate fallback copy when accountsWithoutRate > 0', function (): void {
    nwCardAccount($this->db, $this->user->id, 'Checking', 'bank', 200_000, 'EUR');
    nwCardAccount($this->db, $this->user->id, 'JPY wallet', 'paypal', 5_000_000, 'JPY'); // no JPY rate seeded

    Livewire::test(NetWorthCard::class)
        ->assertSee('not converted — no rate available');
})->group('phase-1');

it('does NOT render the old "excludes non-EUR balances" text', function (): void {
    nwCardAccount($this->db, $this->user->id, 'Checking', 'bank', 200_000, 'EUR');
    nwCardAccount($this->db, $this->user->id, 'JPY wallet', 'paypal', 5_000_000, 'JPY'); // no JPY rate

    Livewire::test(NetWorthCard::class)
        ->assertDontSee('excludes non-EUR balances');
})->group('phase-1');

it('renders the inline fx-disclosure-trigger--inline for non-base-currency accounts in the breakdown', function (): void {
    nwCardAccount($this->db, $this->user->id, 'Checking', 'bank', 200_000, 'EUR');
    nwCardAccount($this->db, $this->user->id, 'USD wallet', 'paypal', 10_000, 'USD');
    nwCardFxRate($this->db, 'USD', '1.08', '2026-06-05', 'ecb');

    Livewire::test(NetWorthCard::class)
        ->call('toggle')
        ->assertSee('fx-disclosure-trigger--inline', escape: false);
})->group('phase-1');

it('renders the REAL converted base equivalent in the breakdown, not the native amount relabelled', function (): void {
    nwCardAccount($this->db, $this->user->id, 'USD wallet', 'paypal', 10_000, 'USD');
    nwCardFxRate($this->db, 'USD', '1.08', '2026-06-05', 'ecb');

    // $100 at EUR/USD 1.08 is €92,59 — never the native 100,00 relabelled as
    // euros, which is the bug this guards.
    Livewire::test(NetWorthCard::class)
        ->call('toggle')
        ->assertSee('92,59');
})->group('phase-1');

it('renders the per-pair rate and a human-readable source label in the breakdown popover (UI-SPEC §5.4/§7.2)', function (): void {
    nwCardAccount($this->db, $this->user->id, 'USD wallet', 'paypal', 10_000, 'USD');
    nwCardFxRate($this->db, 'USD', '1.08', '2026-06-05', 'ecb');

    Livewire::test(NetWorthCard::class)
        ->call('toggle')
        ->assertSee('1 USD =')      // real rate line, e.g. "1 USD = 0.9259 EUR"
        ->assertSee('ECB');          // source label, not the raw "ecb"
})->group('phase-1');

it('anchors each popover to its trigger via inline CSS anchor positioning', function (): void {
    nwCardAccount($this->db, $this->user->id, 'USD wallet', 'paypal', 10_000, 'USD');
    nwCardFxRate($this->db, 'USD', '1.08', '2026-06-05', 'ecb');

    // The anchor-name / position-anchor pair must be emitted inline (the build
    // pipeline strips position-area from compiled CSS, so positioning lives on
    // the element). Guards against a regression back to the corner-pinned popover.
    Livewire::test(NetWorthCard::class)
        ->call('toggle')
        ->assertSee('anchor-name:', escape: false)
        ->assertSee('position-anchor:', escape: false)
        ->assertSee('position-area: bottom span-right', escape: false);
})->group('phase-1');
