<?php

declare(strict_types=1);

use Illuminate\Contracts\Config\Repository;
use Livewire\Livewire;
use Modules\Core\Models\User;
use Modules\Core\Public\Contracts\Clock;
use Modules\Core\Public\Support\Lang;
use Modules\DevMode\Internal\Enums\CommandTier;
use Modules\DevMode\Internal\Exceptions\ProcessSpawningUnavailableException;
use Modules\DevMode\Internal\Http\Livewire\ArtisanRunnerPage;
use Modules\DevMode\Internal\Http\Livewire\CommandArgPromptModal;
use Modules\DevMode\Internal\Process\CommandSpawner;
use Modules\DevMode\Internal\Process\RunRegistry;
use Modules\DevMode\Public\Contracts\AuditWriter;
use Modules\DevMode\Public\Contracts\DevCommandRegistry;

function interpreterlessDeveloper(string $username): User
{
    return User::query()->create([
        'username' => $username,
        'password' => 'fixture-password',
        'period_start_day' => 1,
        'default_currency_view' => 'eur_only',
        'is_developer' => true,
    ]);
}

// PHP_BINARY is '' under the iOS `embed` SAPI, which is what the phone hands
// the spawner. Binding one built that way reproduces the device without one.
function bindInterpreterlessSpawner(): void
{
    app()->instance(CommandSpawner::class, new CommandSpawner(
        app(RunRegistry::class),
        app(Clock::class),
        app(DevCommandRegistry::class),
        app(AuditWriter::class),
        '',
    ));
}

it('CommandSpawner::start() reports the platform rather than building a command with no interpreter', function (): void {
    bindInterpreterlessSpawner();

    /** @var CommandSpawner $spawner */
    $spawner = app(CommandSpawner::class);

    expect(fn (): string => $spawner->start('cache:clear', [], 99, CommandTier::Safe))
        ->toThrow(ProcessSpawningUnavailableException::class);
});

it('leaves no run record and no audit row behind when the platform cannot spawn', function (): void {
    bindInterpreterlessSpawner();

    /** @var CommandSpawner $spawner */
    $spawner = app(CommandSpawner::class);

    try {
        $spawner->start('cache:clear', [], 99, CommandTier::Safe);
    } catch (ProcessSpawningUnavailableException) {
        // The assertions below are the point; the throw is asserted above.
    }

    expect(app('db')->connection()->table('dev_mode_audit')->count())->toBe(0);
});

it('answers POST /dev/artisan/spawn with 501 and the platform message, not a 500', function (): void {
    $user = interpreterlessDeveloper('spawn-no-interpreter');
    bindInterpreterlessSpawner();

    $response = $this->actingAs($user)->postJson('/dev/artisan/spawn', [
        'command' => 'cache:clear',
    ]);

    $response->assertStatus(501);
    $response->assertJson([
        'error' => 'spawning_unavailable',
        'message' => Lang::get('dev::runner.spawning_unavailable'),
    ]);
});

it('answers POST /dev/artisan/destructive-spawn with 501 and the platform message, not a 500', function (): void {
    $user = interpreterlessDeveloper('destructive-no-interpreter');
    /** @var Repository $config */
    $config = app(Repository::class);
    $config->set('app.dev_mode', true);
    bindInterpreterlessSpawner();

    $response = $this->actingAs($user)
        ->withSession(['dev_mode.advanced' => true])
        ->postJson('/dev/artisan/destructive-spawn', [
            'command' => 'migrate:fresh',
            'args' => [],
            'confirmed_typed' => 'Beatrax',
        ]);

    $response->assertStatus(501);
    $response->assertJson([
        'error' => 'spawning_unavailable',
        'message' => Lang::get('dev::runner.spawning_unavailable'),
    ]);
});

it('toasts the platform message from the runner page instead of failing the component', function (): void {
    $user = interpreterlessDeveloper('runner-no-interpreter');
    bindInterpreterlessSpawner();

    Livewire::actingAs($user)
        ->test(ArtisanRunnerPage::class)
        ->call('spawn', 'cache:clear', [])
        ->assertDispatched('toast', message: Lang::get('dev::runner.spawning_unavailable'));
});

it('names the platform, not the file, in the message the reader is given', function (): void {
    $message = Lang::get('dev::runner.spawning_unavailable');

    expect($message)->not->toBe('dev::runner.spawning_unavailable');
    expect($message)->toContain('process');
});

it('surfaces the platform message from the arg-prompt modal instead of throwing out of submit()', function (): void {
    $user = interpreterlessDeveloper('arg-modal-no-interpreter');
    bindInterpreterlessSpawner();

    // The phone's repro exactly: db:backup picked from the palette, whose only
    // arg is optional, submitted blank. The modal spawns directly rather than
    // dispatching to ArtisanRunnerPage, so it needs its own answer.
    $component = Livewire::actingAs($user)
        ->test(CommandArgPromptModal::class)
        ->dispatch('command-args:prompt', name: 'db:backup', tier: 'safe', prefill: [])
        ->set('values.destination', '')
        ->call('submit')
        ->assertNotDispatched('toast');

    expect($component->get('submitError'))->toBe(Lang::get('dev::runner.spawning_unavailable'));
});
