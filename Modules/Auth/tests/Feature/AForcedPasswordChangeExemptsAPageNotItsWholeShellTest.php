<?php

declare(strict_types=1);

use Illuminate\Database\DatabaseManager;
use Illuminate\Testing\TestResponse;
use Modules\Core\Models\User;
use Modules\Core\Public\Support\PatternScan;

// ForcePasswordChangeMiddleware is registered as Livewire persistent middleware
// so a flagged account cannot keep driving components whose snapshots it already
// holds. It took its answer from the page's route name, and the one page it
// exempts renders inside layouts.app -- which mounts nine other components
// beside the password form. Every one of them was reachable over the wire from
// the only screen the flag allows: the ledger search endpoint, the rule form,
// the community mapping publisher and the OAuth client wizard among them.

function forcedChangeSnapshot(string $pageHtml, string $component): string
{
    $matches = PatternScan::all('/wire:snapshot="([^"]*)"/', $pageHtml);

    foreach ($matches[1] as $encoded) {
        $snapshot = html_entity_decode($encoded, ENT_QUOTES);
        if (str_contains($snapshot, '"name":"'.$component.'"')) {
            return $snapshot;
        }
    }

    throw new RuntimeException('No wire:snapshot for '.$component.' on the rendered page.');
}

/**
 * @param  list<array<string, mixed>>  $calls
 * @param  array<string, mixed>  $updates
 */
function forcedChangeDrive(string $snapshot, array $calls, array $updates = []): TestResponse
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

function forcedChangeUser(bool $flagged): User
{
    /** @var User $user */
    $user = User::query()->create([
        'username' => 'forced-change-'.($flagged ? 'flagged' : 'clear'),
        'password' => bcrypt('forced-change-pass'),
        'period_start_day' => 1,
        'force_password_change_at_next_login' => $flagged,
    ]);

    app(DatabaseManager::class)->connection()->table('categories')->insert([
        'user_id' => $user->id,
        'name' => 'Groceries',
        'slug' => 'groceries-'.$user->id,
        'kind' => 'expense',
        'created_at' => now(),
        'updated_at' => now(),
    ]);

    return $user;
}

it('refuses a component the exempt page merely happens to mount', function (): void {
    $user = forcedChangeUser(flagged: true);
    $this->actingAs($user);

    $snapshot = forcedChangeSnapshot(
        $this->get('/change-password')->assertOk()->getContent(),
        'search.palette-search-endpoint',
    );

    $response = forcedChangeDrive($snapshot, [['path' => '', 'method' => 'search', 'params' => ['Groceries']]]);

    $response->assertRedirect(route('auth.change-password'));

    // Read off the body as well as the status: the ledger row is what the
    // refusal exists to withhold, and a 302 carrying it would still have leaked.
    expect($response->getContent())->not->toContain('Groceries');
});

it('still lets the password form itself finish over the same transport', function (): void {
    $user = forcedChangeUser(flagged: true);
    $this->actingAs($user);

    $snapshot = forcedChangeSnapshot(
        $this->get('/change-password')->assertOk()->getContent(),
        'auth.change-password-page',
    );

    forcedChangeDrive(
        $snapshot,
        [['path' => '', 'method' => 'submit', 'params' => []]],
        [
            'currentPassword' => 'forced-change-pass',
            'newPassword' => 'a-brand-new-password',
            'newPasswordConfirmation' => 'a-brand-new-password',
        ],
    )->assertOk();

    expect(User::query()->find($user->id)->force_password_change_at_next_login)->toBeFalse();
});

it('leaves an account with no flag driving the same component', function (): void {
    $user = forcedChangeUser(flagged: false);
    $this->actingAs($user);

    $snapshot = forcedChangeSnapshot(
        $this->get('/change-password')->assertOk()->getContent(),
        'search.palette-search-endpoint',
    );

    $response = forcedChangeDrive($snapshot, [['path' => '', 'method' => 'search', 'params' => ['Groceries']]]);

    $response->assertOk();
    expect($response->getContent())->toContain('Groceries');
});
