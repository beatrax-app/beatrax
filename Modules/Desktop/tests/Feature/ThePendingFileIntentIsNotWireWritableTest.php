<?php

declare(strict_types=1);

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Testing\TestResponse;
use Livewire\Features\SupportLockedProperties\CannotUpdateLockedPropertyException;
use Livewire\Livewire;
use Modules\Core\Models\User;
use Modules\Core\Public\Support\PatternScan;
use Modules\Desktop\Internal\Http\Livewire\FileStagingPage;

uses(RefreshDatabase::class);

// `?array` is the whole of what the type guard checks, and `[]` satisfies it.
// The only legitimate writer is PendingFileIntent::pending(), which realpath()s
// the file and allow-lists the extension; render() then reads ['path'].

function pendingIntentSnapshot(string $pageHtml): string
{
    $matches = PatternScan::all('/wire:snapshot="([^"]*)"/', $pageHtml);
    foreach ($matches[1] as $encoded) {
        $snapshot = html_entity_decode($encoded, ENT_QUOTES);
        if (str_contains($snapshot, '"name":"desktop.file-staging-page"')) {
            return $snapshot;
        }
    }

    throw new RuntimeException('No wire:snapshot for the file staging page.');
}

/**
 * @param  array<string, mixed>  $updates
 */
function pendingIntentTamper(string $snapshot, array $updates): TestResponse
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
        'username' => 'pending-file-intent',
        'password' => 'fixture-password-12chars',
        'period_start_day' => 1,
    ]);
    $this->actingAs($this->user);

    $this->snapshot = pendingIntentSnapshot($this->get('/desktop/file-staging')->assertOk()->getContent());
});

it('refuses an intent with no file in it however the bundle was built', function (bool $debug): void {
    config()->set('app.debug', $debug);

    pendingIntentTamper($this->snapshot, ['pending' => []])->assertForbidden();
})->with([
    'debug build' => [true],
    'production build' => [false],
]);

it('refuses an intent naming a path of its own', function (): void {
    pendingIntentTamper($this->snapshot, [
        'pending' => ['path' => '/etc/passwd', 'extension' => 'csv'],
    ])->assertForbidden();
});

it('throws rather than accepting a write to the pending intent', function (): void {
    Livewire::test(FileStagingPage::class)->set('pending', []);
})->throws(CannotUpdateLockedPropertyException::class);
