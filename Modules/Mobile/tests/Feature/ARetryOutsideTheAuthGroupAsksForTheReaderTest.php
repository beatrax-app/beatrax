<?php

declare(strict_types=1);

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Testing\TestResponse;
use Livewire\Component;
use Livewire\Livewire;
use Modules\Core\Models\User;
use Modules\Core\Public\Contracts\CurrentUser;
use Modules\Core\Public\Exceptions\NotAuthenticatedException;
use Modules\Core\Public\Support\LivewireClientRefusal;
use Modules\Core\Public\Support\PatternScan;

uses(RefreshDatabase::class);

// Stands in for the next action on an auth-free route that forgets to repeat
// mount()'s check: the seam behind the guard has to be provable without waiting
// for a second real one to ship.
final class ReaderlessCallProbe extends Component
{
    public function boom(CurrentUser $currentUser): void
    {
        $currentUser->user();
    }

    public function render(): string
    {
        return '<div></div>';
    }
}

// /mobile/import renders before any account exists, so it sits outside the auth
// group and mount() opens with an isAuthenticated() check for that reason. A
// `calls` entry reaches an action without going through mount() at all.

function importRetrySnapshot(string $pageHtml): string
{
    $matches = PatternScan::all('/wire:snapshot="([^"]*)"/', $pageHtml);
    foreach ($matches[1] as $encoded) {
        $snapshot = html_entity_decode($encoded, ENT_QUOTES);
        if (str_contains($snapshot, '"name":"mobile.import-bootstrap"')) {
            return $snapshot;
        }
    }

    throw new RuntimeException('No wire:snapshot for the import bootstrap on /mobile/import.');
}

function importRetryCall(string $snapshot): TestResponse
{
    return test()->withHeaders(['X-Livewire' => 'true'])->postJson(route('default-livewire.update'), [
        '_token' => csrf_token(),
        'components' => [[
            'snapshot' => $snapshot,
            'updates' => [],
            'calls' => [['method' => 'retryProvisioning', 'params' => [], 'path' => '']],
        ]],
    ]);
}

beforeEach(function (): void {
    // An owner has to exist or the database-ready gate redirects the page away
    // before the component renders; the request itself stays signed out.
    User::query()->create([
        'username' => 'import-retry-owner',
        'password' => 'fixture-password-12chars',
        'period_start_day' => 1,
    ]);

    $this->snapshot = importRetrySnapshot($this->get('/mobile/import')->assertOk()->getContent());
});

it('answers an unauthenticated retry without a server fault however the bundle was built', function (bool $debug): void {
    config()->set('app.debug', $debug);

    $response = importRetryCall($this->snapshot);

    expect($response->getStatusCode())->not->toBe(500);
    $response->assertOk();
})->with([
    'debug build' => [true],
    'production build' => [false],
]);

it('takes the guard rather than dereferencing the reader', function (): void {
    $this->withoutExceptionHandling();

    importRetryCall($this->snapshot)->assertOk();
});

// The guard on the action is the fix; this is the seam behind it, for the next
// method on an auth-free route that forgets to repeat the check.
it('refuses a call that assumed a reader, and only where a payload named it', function (): void {
    Livewire::component('mobile-tests.readerless-call-probe', ReaderlessCallProbe::class);

    try {
        Livewire::test(ReaderlessCallProbe::class)->call('boom');
    } catch (NotAuthenticatedException $raised) {
        $refusal = LivewireClientRefusal::refusal($raised);

        expect($refusal)->not->toBeNull(
            'A method an update payload named, asked for a reader the request does not have, is the client at fault.',
        );
        expect($refusal?->getStatusCode())->toBe(401);

        expect(LivewireClientRefusal::refusal(new NotAuthenticatedException('No authenticated user.')))->toBeNull(
            'The same throw from a scheduled task or a console command is the server at fault and has to stay a 500.',
        );

        return;
    }

    throw new RuntimeException('The probe resolved a reader that is not signed in.');
});
