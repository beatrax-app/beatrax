<?php

declare(strict_types=1);

use Carbon\CarbonImmutable;
use Illuminate\Database\DatabaseManager;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Testing\TestResponse;
use Livewire\Features\SupportLockedProperties\CannotUpdateLockedPropertyException;
use Livewire\Livewire;
use Modules\Core\Models\User;
use Modules\Core\Public\Support\PatternScan;
use Modules\OpenBanking\Internal\Http\Livewire\OpenBankingConnectionCard;
use Modules\OpenBanking\Tests\Support\OpenBankingSecretsFixture;

uses(RefreshDatabase::class);

// $consentStatus beside them was already locked. The four timestamps were not,
// and the transparency panel reads three of them through CarbonImmutable. They
// belong to one bank's card now, which is the component the wire addresses.
const CONNECTION_TIMESTAMP_PROPERTIES = [
    'consentExpiresAtIso',
    'lastSuccessfulSyncAtIso',
    'lastAttemptAtIso',
    'lastAttemptStatus',
];

function connectionTimestampsSnapshot(string $pageHtml): string
{
    $matches = PatternScan::all('/wire:snapshot="([^"]*)"/', $pageHtml);
    foreach ($matches[1] as $encoded) {
        $snapshot = html_entity_decode($encoded, ENT_QUOTES);
        if (str_contains($snapshot, '"name":"openbanking.open-banking-connection-card"')) {
            return $snapshot;
        }
    }

    throw new RuntimeException('No wire:snapshot for the open banking connection card.');
}

/**
 * @param  array<string, mixed>  $updates
 */
function connectionTimestampsTamper(string $snapshot, array $updates): TestResponse
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
        'username' => 'connection-timestamps',
        'password' => 'fixture-password-12chars',
        'period_start_day' => 1,
    ]);
    $this->actingAs($this->user);

    OpenBankingSecretsFixture::forget((int) $this->user->id);
    OpenBankingSecretsFixture::seed((int) $this->user->id);

    $now = CarbonImmutable::now()->toDateTimeString();
    $this->connectionId = (int) app(DatabaseManager::class)->connection()->table('open_banking_connections')->insertGetId([
        'user_id' => $this->user->id,
        'institution_id' => OpenBankingSecretsFixture::INSTITUTION_ID,
        'account_uid' => 'acc-uid-timestamps',
        'bank_display_name' => null,
        'enabled' => true,
        'consent_expires_at' => CarbonImmutable::now()->addDays(180)->toDateTimeString(),
        'last_successful_sync_at' => $now,
        'last_attempt_at' => $now,
        'last_attempt_status' => 'ok',
        'created_at' => $now,
        'updated_at' => $now,
    ]);

    $this->snapshot = connectionTimestampsSnapshot($this->get('/settings/open-banking')->assertOk()->getContent());
});

afterEach(function (): void {
    OpenBankingSecretsFixture::forget((int) $this->user->id);
});

it('refuses a timestamp no calendar answers to however the bundle was built', function (bool $debug): void {
    config()->set('app.debug', $debug);

    foreach (CONNECTION_TIMESTAMP_PROPERTIES as $property) {
        connectionTimestampsTamper($this->snapshot, [$property => 'zzz'])->assertForbidden();
    }
})->with([
    'debug build' => [true],
    'production build' => [false],
]);

it('throws rather than accepting a write to any of the four', function (string $property): void {
    Livewire::test(OpenBankingConnectionCard::class, ['connectionId' => $this->connectionId])->set($property, 'zzz');
})->with(CONNECTION_TIMESTAMP_PROPERTIES)->throws(CannotUpdateLockedPropertyException::class);

// Belt and braces behind the lock. Read through the component rather than the
// wire, because the lock now refuses that write: the three formatters are what
// the panel calls, and each one has to answer a string it cannot read with no
// reading rather than with a stack trace.
it('reads an unparseable timestamp as no timestamp rather than throwing', function (): void {
    $component = Livewire::test(OpenBankingConnectionCard::class, ['connectionId' => $this->connectionId])->instance();

    expect($component->lastSuccessfulSyncDisplay())->not->toBeNull();

    $component->lastSuccessfulSyncAtIso = 'zzz';
    $component->lastAttemptAtIso = 'zzz';

    expect($component->lastSuccessfulSyncDisplay())->toBeNull()
        ->and($component->lastSuccessfulSyncRelative())->toBeNull()
        ->and($component->lastAttemptDisplay())->toBeNull();
});

// reconnect() hands this id to the wizard as the bank to reconnect, so a
// payload that chose it would reopen the wizard pointed at an institution the
// reader never connected.
it('throws rather than accepting a write to the institution the connection names', function (): void {
    Livewire::test(OpenBankingConnectionCard::class, ['connectionId' => $this->connectionId])->set('institutionId', 'ZZZ_BANK');
})->throws(CannotUpdateLockedPropertyException::class);

// The card is addressed by a bare integer from the wire, so the id it was
// mounted with is the one thing a payload must not be able to re-point.
it('throws rather than accepting a write to the connection id itself', function (): void {
    Livewire::test(OpenBankingConnectionCard::class, ['connectionId' => $this->connectionId])->set('connectionId', $this->connectionId + 1);
})->throws(CannotUpdateLockedPropertyException::class);
