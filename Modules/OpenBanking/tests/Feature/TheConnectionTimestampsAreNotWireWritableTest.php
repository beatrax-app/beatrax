<?php

declare(strict_types=1);

use Carbon\CarbonImmutable;
use Illuminate\Database\DatabaseManager;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Testing\TestResponse;
use Livewire\Features\SupportLockedProperties\CannotUpdateLockedPropertyException;
use Livewire\Livewire;
use Modules\Core\Models\User;
use Modules\OpenBanking\Internal\Dto\OpenBankingCredentials;
use Modules\OpenBanking\Internal\Http\Livewire\OpenBankingSettingsPage;
use Modules\OpenBanking\Internal\Services\OpenBankingSecretsRepository;

uses(RefreshDatabase::class);

// $consentStatus beside them was already locked. The four timestamps were not,
// and the transparency panel reads three of them through CarbonImmutable.
const CONNECTION_TIMESTAMP_PROPERTIES = [
    'consentExpiresAtIso',
    'lastSuccessfulSyncAtIso',
    'lastAttemptAtIso',
    'lastAttemptStatus',
];

function connectionTimestampsSnapshot(string $pageHtml): string
{
    preg_match_all('/wire:snapshot="([^"]*)"/', $pageHtml, $matches);
    foreach ($matches[1] as $encoded) {
        $snapshot = html_entity_decode($encoded, ENT_QUOTES);
        if (str_contains($snapshot, '"name":"openbanking.open-banking-settings-page"')) {
            return $snapshot;
        }
    }

    throw new RuntimeException('No wire:snapshot for the open banking settings page.');
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

function connectionTimestampsSecretsPath(): string
{
    return storage_path('app/secrets/open-banking.json');
}

beforeEach(function (): void {
    $this->user = User::query()->create([
        'username' => 'connection-timestamps',
        'password' => 'fixture-password-12chars',
        'period_start_day' => 1,
    ]);
    $this->actingAs($this->user);

    @unlink(connectionTimestampsSecretsPath());
    app(OpenBankingSecretsRepository::class)->save(new OpenBankingCredentials(
        applicationId: 'fixture-application-id',
        privateKeyPem: 'fixture-pem',
        sessionId: 'fixture-session',
        consentExpiresAt: CarbonImmutable::now()->addDays(180),
        bankScaHost: 'sca.asnbank.example',
        institutionId: 'ASNBNL21',
    ));

    $now = CarbonImmutable::now()->toDateTimeString();
    app(DatabaseManager::class)->connection()->table('open_banking_connections')->insert([
        'user_id' => $this->user->id,
        'institution_id' => 'ASNBNL21',
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
    @unlink(connectionTimestampsSecretsPath());
    @unlink(connectionTimestampsSecretsPath().'.tmp');
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
    Livewire::test(OpenBankingSettingsPage::class)->set($property, 'zzz');
})->with(CONNECTION_TIMESTAMP_PROPERTIES)->throws(CannotUpdateLockedPropertyException::class);

// Belt and braces behind the lock. Read through the component rather than the
// wire, because the lock now refuses that write: the three formatters are what
// the panel calls, and each one has to answer a string it cannot read with no
// reading rather than with a stack trace.
it('reads an unparseable timestamp as no timestamp rather than throwing', function (): void {
    $component = Livewire::test(OpenBankingSettingsPage::class)->instance();

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
    Livewire::test(OpenBankingSettingsPage::class)->set('institutionId', 'ZZZ_BANK');
})->throws(CannotUpdateLockedPropertyException::class);
