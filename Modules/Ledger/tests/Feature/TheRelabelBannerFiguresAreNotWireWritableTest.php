<?php

declare(strict_types=1);

use Illuminate\Database\DatabaseManager;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Testing\TestResponse;
use Livewire\Features\SupportLockedProperties\CannotUpdateLockedPropertyException;
use Livewire\Livewire;
use Modules\Core\Models\User;
use Modules\Ledger\Public\Http\Livewire\AccountCurrencyEditor;

uses(RefreshDatabase::class);

// The per-account currency picker is mounted once per account on /settings.
// Only the <select> is bound; the warning banner beside it reads a stored code
// and a per-currency line map the Action wrote, and hands both to Money.

function relabelBannerSnapshot(string $pageHtml): string
{
    preg_match_all('/wire:snapshot="([^"]*)"/', $pageHtml, $matches);
    foreach ($matches[1] as $encoded) {
        $snapshot = html_entity_decode($encoded, ENT_QUOTES);
        if (str_contains($snapshot, '"name":"ledger.account-currency-editor"')) {
            return $snapshot;
        }
    }

    throw new RuntimeException('No wire:snapshot for the account currency editor on /settings.');
}

/**
 * @param  array<string, mixed>  $updates
 */
function relabelBannerTamper(string $snapshot, array $updates): TestResponse
{
    return test()->withHeaders(['X-Livewire' => 'true'])->postJson(route('default-livewire.update'), [
        '_token' => csrf_token(),
        'components' => [[
            'snapshot' => $snapshot,
            'updates' => $updates,
            'calls' => [],
        ]],
    ]);
}

beforeEach(function (): void {
    $this->user = User::query()->create([
        'username' => 'relabel-banner',
        'password' => 'fixture-password-12chars',
        'period_start_day' => 1,
    ]);
    $this->actingAs($this->user);

    app(DatabaseManager::class)->connection()->table('accounts')->insert([
        'user_id' => $this->user->id,
        'name' => 'ASN account',
        'slug' => 'relabel-banner-asn',
        'kind' => 'bank',
        'iban' => 'NL03ASNB0123450001',
        'default_currency' => 'EUR',
        'created_at' => '2026-01-01 00:00:00',
        'updated_at' => '2026-01-01 00:00:00',
    ]);

    $this->snapshot = relabelBannerSnapshot($this->get('/settings')->assertOk()->getContent());
});

it('refuses a denomination the payload chose for the banner however the bundle was built', function (bool $debug): void {
    config()->set('app.debug', $debug);

    relabelBannerTamper($this->snapshot, [
        'showingRelabelBanner' => true,
        'relabelBaselineMinor' => 1,
        'storedCurrency' => 'ZZZ',
    ])->assertForbidden();
})->with([
    'debug build' => [true],
    'production build' => [false],
]);

it('refuses a line map whose amounts are not amounts however the bundle was built', function (bool $debug): void {
    config()->set('app.debug', $debug);

    relabelBannerTamper($this->snapshot, [
        'showingRelabelBanner' => true,
        'relabelLines' => ['EUR' => []],
    ])->assertForbidden();
})->with([
    'debug build' => [true],
    'production build' => [false],
]);

it('leaves the one property the <select> is bound to writable', function (): void {
    relabelBannerTamper($this->snapshot, ['currency' => 'USD'])->assertOk();
});

it('throws rather than accepting a write to the stored code', function (): void {
    Livewire::test(AccountCurrencyEditor::class, [
        'accountId' => (int) app(DatabaseManager::class)->connection()->table('accounts')->value('id'),
        'accountName' => 'ASN account',
        'currency' => 'EUR',
    ])->set('storedCurrency', 'ZZZ');
})->throws(CannotUpdateLockedPropertyException::class);
