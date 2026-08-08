<?php

declare(strict_types=1);

use Illuminate\Support\Facades\DB;
use Livewire\Livewire;
use Modules\Core\Models\User;
use Modules\Desktop\Internal\Http\Livewire\CloseWindowPrompt;
use Modules\Desktop\Internal\Native\WindowCloseBehavior;

/*
 * Feature tests for the D-08 first-close prompt (Quit vs Keep-in-tray).
 *
 *   - users.close_behavior — nullable per-user preference. NULL means
 *     "no decision yet — prompt on close"; 'quit' / 'tray' map to the
 *     two actions.
 *   - WindowCloseBehavior — decides on each close whether to show the
 *     prompt, quit, or hide-to-tray based on the current user's value.
 *   - CloseWindowPrompt — Livewire flux:modal component with the
 *     UI-SPEC verbatim copy + instant-apply persistence on a remembered
 *     choice.
 */

beforeEach(function (): void {
    $this->user = User::query()->create([
        'username' => 'close-fixture',
        'password' => 'opensesame',
        'period_start_day' => 1,
        'default_currency_view' => 'eur_only',
    ]);
    $this->actingAs($this->user);
});

it('gives the users table a nullable close_behavior column', function (): void {
    expect(DB::getSchemaBuilder()->hasColumn('users', 'close_behavior'))->toBeTrue();

    // Fresh user with no choice yet — column is null, so the next
    // close shows the prompt.
    expect($this->user->fresh()->close_behavior)->toBeNull();
})->group('phase-15');

it('shouldPrompt() returns true when the user has no recorded close_behavior', function (): void {
    /** @var WindowCloseBehavior $behavior */
    $behavior = app(WindowCloseBehavior::class);

    expect($behavior->shouldPromptFor($this->user))->toBeTrue();
})->group('phase-15');

it('shouldPrompt() returns false once a choice has been recorded', function (): void {
    /** @var WindowCloseBehavior $behavior */
    $behavior = app(WindowCloseBehavior::class);

    $this->user->update(['close_behavior' => 'tray']);
    expect($behavior->shouldPromptFor($this->user->fresh()))->toBeFalse();

    $this->user->update(['close_behavior' => 'quit']);
    expect($behavior->shouldPromptFor($this->user->fresh()))->toBeFalse();
})->group('phase-15');

it('CloseWindowPrompt has NO constructor (Livewire strict-rules)', function (): void {
    $reflection = new ReflectionClass(CloseWindowPrompt::class);

    expect($reflection->getConstructor())->toBeNull();
})->group('phase-15');

it('auto-opens the flux:modal on mount so navigating to /desktop/close-prompt surfaces the dialog', function (): void {
    // Without the modal-show dispatch on mount, visiting the route
    // would render an invisible flux:modal element — the dialog would
    // never appear and the entire D-08 prompt UX would be unreachable
    // even after the Electron-side close-intercept navigates the
    // window here.
    Livewire::test(CloseWindowPrompt::class)
        ->assertDispatched('modal-show', name: CloseWindowPrompt::MODAL_NAME);
})->group('phase-15');

it('renders the verbatim UI-SPEC copy + flux:modal + h-12 buttons', function (): void {
    $rendered = Livewire::test(CloseWindowPrompt::class);

    $rendered
        ->assertSee('Keep Beatrax running?')
        ->assertSee('Closing the window can either quit Beatrax completely or keep it running quietly in the menu bar so scheduled email scans continue.')
        ->assertSee('Quit Beatrax')
        ->assertSee('Keep running in the tray')
        ->assertSee('Remember my choice');

    // Both buttons render at h-12 (48px target size per UI-SPEC
    // accessibility note).
    $html = (string) $rendered->html();
    expect(substr_count($html, 'h-12'))->toBeGreaterThanOrEqual(2);

    // flux:modal is preferred over a native dialog (UI-SPEC) so the
    // prompt inherits the dark theme — assert the modal name is in
    // the rendered output.
    expect($html)->toContain('close-window-prompt');
})->group('phase-15');

it('persists close_behavior=tray when "Keep running in the tray" is chosen with remember=true', function (): void {
    Livewire::test(CloseWindowPrompt::class)
        ->assertSet('rememberChoice', true)
        ->call('chooseKeepInTray');

    expect($this->user->fresh()->close_behavior)->toBe('tray');
})->group('phase-15');

it('persists close_behavior=quit when "Quit Beatrax" is chosen with remember=true', function (): void {
    Livewire::test(CloseWindowPrompt::class)
        ->assertSet('rememberChoice', true)
        ->call('chooseQuit');

    expect($this->user->fresh()->close_behavior)->toBe('quit');
})->group('phase-15');

it('does NOT persist a choice when remember=false (the next close re-prompts)', function (): void {
    Livewire::test(CloseWindowPrompt::class)
        ->set('rememberChoice', false)
        ->call('chooseKeepInTray');

    // No persistence — the next close will re-prompt.
    expect($this->user->fresh()->close_behavior)->toBeNull();

    Livewire::test(CloseWindowPrompt::class)
        ->set('rememberChoice', false)
        ->call('chooseQuit');

    expect($this->user->fresh()->close_behavior)->toBeNull();
})->group('phase-15');

it('rejects an invalid close_behavior value (validation guard)', function (): void {
    // The WindowCloseBehavior service applies a fixed allow-list when
    // persisting — passing an unsanctioned value via the URL/payload
    // never reaches the users row.
    /** @var WindowCloseBehavior $behavior */
    $behavior = app(WindowCloseBehavior::class);

    expect(fn () => $behavior->persistChoice($this->user, 'garbage'))
        ->toThrow(InvalidArgumentException::class);

    expect($this->user->fresh()->close_behavior)->toBeNull();
})->group('phase-15');
