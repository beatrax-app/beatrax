<?php

declare(strict_types=1);

use Livewire\Livewire;
use Modules\Core\Models\User;
use Modules\Core\Public\Contracts\Clock;
use Modules\Core\Public\Support\Lang;
use Modules\DevMode\Internal\Http\Livewire\DoctorPanelPage;
use Modules\DevMode\Internal\Process\CommandSpawner;
use Modules\DevMode\Internal\Process\RunRegistry;
use Modules\DevMode\Public\Contracts\AuditWriter;
use Modules\DevMode\Public\Contracts\DevCommandRegistry;

// The panel asked nobody whether a run was possible. On a phone the endpoint
// behind the button answers 501 every time, so the button could not work and
// the empty state told the reader to press it anyway.

function doctorDeveloper(string $username): User
{
    return User::query()->create([
        'username' => $username,
        'password' => 'fixture-password',
        'period_start_day' => 1,
        'default_currency_view' => 'eur_only',
        'is_developer' => true,
    ]);
}

// PHP_BINARY is '' under the embed SAPI both phones ship, which is what the
// device hands the spawner.
function bindSpawnerWithInterpreter(string $phpBinary): void
{
    app()->instance(CommandSpawner::class, new CommandSpawner(
        app(RunRegistry::class),
        app(Clock::class),
        app(DevCommandRegistry::class),
        app(AuditWriter::class),
        $phpBinary,
    ));
}

it('draws no re-run button on a runtime that cannot spawn', function (): void {
    bindSpawnerWithInterpreter('');

    Livewire::actingAs(doctorDeveloper('doctor-no-php'))
        ->test(DoctorPanelPage::class)
        ->assertDontSeeHtml('data-testid="doctor-rerun-button"')
        ->assertSeeHtml('data-testid="doctor-spawning-unavailable"')
        ->assertSee(Lang::get('dev::runner.spawning_unavailable'));
})->group('DoctorPanel');

it('does not tell the reader to press a button it did not draw', function (): void {
    bindSpawnerWithInterpreter('');

    // empty_html names the Re-run control; with no probe rows AND no button,
    // that sentence is an instruction the reader cannot carry out.
    Livewire::actingAs(doctorDeveloper('doctor-empty-copy'))
        ->test(DoctorPanelPage::class)
        ->assertDontSee(Lang::get('dev::doctor.rerun'));
})->group('DoctorPanel');

it('still draws the button where a run can actually happen', function (): void {
    // The control: a desktop has a real interpreter, and nothing about this
    // change may take the button away from it.
    bindSpawnerWithInterpreter(PHP_BINARY);

    Livewire::actingAs(doctorDeveloper('doctor-with-php'))
        ->test(DoctorPanelPage::class)
        ->assertSeeHtml('data-testid="doctor-rerun-button"')
        ->assertDontSeeHtml('data-testid="doctor-spawning-unavailable"');
})->group('DoctorPanel');
