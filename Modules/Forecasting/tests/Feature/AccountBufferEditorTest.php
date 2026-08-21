<?php

declare(strict_types=1);

use Illuminate\Database\DatabaseManager;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Bus;
use Livewire\Livewire;
use Modules\Core\Models\User;
use Modules\Forecasting\Internal\Http\Livewire\AccountBufferEditor;
use Modules\Forecasting\Internal\Jobs\ProjectForecastJob;
use Modules\Forecasting\Public\Actions\SetAccountForecastBuffer;
use Modules\Ledger\Models\Account;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

uses(RefreshDatabase::class);

function abeUser(string $username): User
{
    return User::query()->create([
        'username' => $username,
        'password' => 'fixture-password',
        'period_start_day' => 1,
        'default_currency_view' => 'eur_only',
    ]);
}

function abeAccount(User $user, ?int $bufferMinor = null): Account
{
    /** @var Account $account */
    $account = Account::query()->create([
        'user_id' => $user->id,
        'name' => 'abe '.$user->id,
        'slug' => 'abe-'.$user->id.'-'.bin2hex(random_bytes(4)),
        'kind' => 'bank',
        'iban' => 'NL00ABE'.strtoupper(bin2hex(random_bytes(4))),
        'default_currency' => 'EUR',
        'forecast_min_buffer_minor' => $bufferMinor,
    ]);

    return $account;
}

beforeEach(function (): void {
    $this->user = abeUser('abe');
});

it('pre-populates the input from the account current buffer', function (): void {
    $account = abeAccount($this->user, 50000);
    $this->actingAs($this->user);

    Livewire::test(AccountBufferEditor::class, [
        'accountId' => $account->id,
        'currentBufferMinor' => 50000,
        'currency' => 'EUR',
        'accountName' => 'ASN',
    ])->assertSet('bufferInput', '500,00');
});

it('saves a new buffer + dispatches buffer-editor:saved and toast events', function (): void {
    Bus::fake();
    $account = abeAccount($this->user, null);
    $this->actingAs($this->user);

    /** @var DatabaseManager $db */
    $db = $this->app->make(DatabaseManager::class);

    Livewire::test(AccountBufferEditor::class, [
        'accountId' => $account->id,
        'currentBufferMinor' => null,
        'currency' => 'EUR',
        'accountName' => 'ASN',
    ])
        ->set('bufferInput', '750,00')
        ->call('save')
        ->assertDispatched('buffer-editor:saved')
        ->assertDispatched('toast');

    expect((int) $db->connection()->table('accounts')->where('id', $account->id)->value('forecast_min_buffer_minor'))
        ->toBe(75000);
});

it('rejects negative buffer input inline (UI-SPEC error copy)', function (): void {
    $account = abeAccount($this->user, 50000);
    $this->actingAs($this->user);

    Livewire::test(AccountBufferEditor::class, [
        'accountId' => $account->id,
        'currentBufferMinor' => 50000,
        'currency' => 'EUR',
        'accountName' => 'ASN',
    ])
        ->set('bufferInput', '-100')
        ->call('save')
        ->assertSet('errorMessage', 'Buffer must be zero or positive.');
});

it('clears the buffer when Clear is clicked', function (): void {
    Bus::fake();
    $account = abeAccount($this->user, 50000);
    $this->actingAs($this->user);

    /** @var DatabaseManager $db */
    $db = $this->app->make(DatabaseManager::class);

    Livewire::test(AccountBufferEditor::class, [
        'accountId' => $account->id,
        'currentBufferMinor' => 50000,
        'currency' => 'EUR',
        'accountName' => 'ASN',
    ])
        ->call('clear')
        ->assertDispatched('buffer-editor:saved');

    expect($db->connection()->table('accounts')->where('id', $account->id)->value('forecast_min_buffer_minor'))
        ->toBeNull();
});

it('rejects cross-user buffer save via SetAccountForecastBuffer Public Action (404)', function (): void {
    $other = abeUser('abe-other');
    $otherAccount = abeAccount($other, 50000);

    /** @var SetAccountForecastBuffer $action */
    $action = $this->app->make(SetAccountForecastBuffer::class);

    expect(fn () => $action($otherAccount->id, $this->user, 12345))
        ->toThrow(NotFoundHttpException::class, 'Account not found.');
});

it('rejects negative buffer via SetAccountForecastBuffer Public Action', function (): void {
    $account = abeAccount($this->user, null);

    /** @var SetAccountForecastBuffer $action */
    $action = $this->app->make(SetAccountForecastBuffer::class);

    expect(fn () => $action($account->id, $this->user, -100))
        ->toThrow(InvalidArgumentException::class, 'Buffer must be zero or positive.');
});

it('dispatches three ProjectForecastJobs (one per baseline horizon) on save', function (): void {
    Bus::fake();
    $account = abeAccount($this->user, null);
    $this->actingAs($this->user);

    Livewire::test(AccountBufferEditor::class, [
        'accountId' => $account->id,
        'currentBufferMinor' => null,
        'currency' => 'EUR',
        'accountName' => 'ASN',
    ])
        ->set('bufferInput', '500')
        ->call('save');

    Bus::assertDispatched(ProjectForecastJob::class, function (ProjectForecastJob $job): bool {
        return $job->scenarioId === null && in_array($job->horizonDays, [30, 60, 90], true);
    });
    Bus::assertDispatchedTimes(ProjectForecastJob::class, 3);
});
