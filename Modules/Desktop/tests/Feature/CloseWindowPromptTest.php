<?php

declare(strict_types=1);

use Illuminate\Support\Facades\DB;
use Livewire\Livewire;
use Modules\Core\Models\User;
use Modules\Desktop\Internal\Http\Livewire\CloseWindowPrompt;
use Modules\Desktop\Internal\Native\WindowCloseBehavior;

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

    // A null column is the not-yet-decided state that triggers the prompt.
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
    // Without the modal-show dispatch on mount the flux:modal renders
    // invisible, so the prompt would be unreachable even after the
    // Electron-side close-intercept navigates the window here.
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

    // h-12 is the 48px minimum target size.
    $html = (string) $rendered->html();
    expect(substr_count($html, 'h-12'))->toBeGreaterThanOrEqual(2);

    // flux:modal rather than a native dialog, so the prompt inherits the dark theme.
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

    expect($this->user->fresh()->close_behavior)->toBeNull();

    Livewire::test(CloseWindowPrompt::class)
        ->set('rememberChoice', false)
        ->call('chooseQuit');

    expect($this->user->fresh()->close_behavior)->toBeNull();
})->group('phase-15');

it('rejects an invalid close_behavior value (validation guard)', function (): void {
    // The value arrives from the POSTed payload, so the allow-list has to be
    // enforced at the service rather than in the component.
    /** @var WindowCloseBehavior $behavior */
    $behavior = app(WindowCloseBehavior::class);

    expect(fn () => $behavior->persistChoice($this->user, 'garbage'))
        ->toThrow(InvalidArgumentException::class);

    expect($this->user->fresh()->close_behavior)->toBeNull();
})->group('phase-15');
