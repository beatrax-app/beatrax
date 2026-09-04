<?php

declare(strict_types=1);

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Testing\TestResponse;
use Livewire\Features\SupportLockedProperties\CannotUpdateLockedPropertyException;
use Livewire\Livewire;
use Modules\Categorization\Internal\Http\Livewire\TriageInbox;
use Modules\Core\Models\User;
use Modules\Core\Public\Support\PatternScan;

uses(RefreshDatabase::class);

// selectForRow() types both halves of the pair and save() hands them straight
// to AssignsCategory::__invoke(int, ?int, User). Under strict_types the string
// a payload can put in either slot is a TypeError inside a method body, which
// is a server fault the refusal seam deliberately does not map.

function triagePendingSnapshot(string $pageHtml): string
{
    $matches = PatternScan::all('/wire:snapshot="([^"]*)"/', $pageHtml);
    foreach ($matches[1] as $encoded) {
        $snapshot = html_entity_decode($encoded, ENT_QUOTES);
        if (str_contains($snapshot, '"name":"categorization.triage-inbox"')) {
            return $snapshot;
        }
    }

    throw new RuntimeException('No wire:snapshot for the triage inbox on /uncategorized.');
}

/**
 * @param  array<string, mixed>  $updates
 * @param  list<array<string, mixed>>  $calls
 */
function triagePendingTamper(string $snapshot, array $updates, array $calls = []): TestResponse
{
    return test()->withHeaders(['X-Livewire' => 'true'])->postJson(route('default-livewire.update'), [
        '_token' => csrf_token(),
        'components' => [[
            'snapshot' => $snapshot,
            'updates' => $updates,
            'calls' => $calls,
        ]],
    ]);
}

beforeEach(function (): void {
    $this->user = User::query()->create([
        'username' => 'triage-pending',
        'password' => 'fixture-password-12chars',
        'period_start_day' => 1,
    ]);
    $this->actingAs($this->user);

    $this->snapshot = triagePendingSnapshot($this->get('/uncategorized')->assertOk()->getContent());
});

it('refuses a pending pair the payload wrote however the bundle was built', function (bool $debug): void {
    config()->set('app.debug', $debug);

    triagePendingTamper($this->snapshot, ['pending' => ['1' => '1']], [
        ['method' => 'save', 'params' => [], 'path' => ''],
    ])->assertForbidden();
})->with([
    'debug build' => [true],
    'production build' => [false],
]);

it('leaves the row picker the browser calls working', function (): void {
    triagePendingTamper($this->snapshot, [], [
        ['method' => 'selectForRow', 'params' => [1, null], 'path' => ''],
    ])->assertOk();
});

it('throws rather than accepting a write to the pending map', function (): void {
    Livewire::test(TriageInbox::class)->set('pending', ['1' => '1']);
})->throws(CannotUpdateLockedPropertyException::class);
