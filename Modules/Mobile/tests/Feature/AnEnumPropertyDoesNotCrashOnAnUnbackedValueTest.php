<?php

declare(strict_types=1);

use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Features\SupportLockedProperties\CannotUpdateLockedPropertyException;
use Livewire\Livewire;
use Modules\Core\Models\User;
use Modules\Mobile\Internal\Http\Livewire\SetupProgressScreen;
use Modules\Mobile\Internal\Sync\SyncPhase;

uses(RefreshDatabase::class);

function enumProbeUser(): User
{
    return User::query()->create([
        'username' => 'enum-probe-'.bin2hex(random_bytes(4)),
        'password' => bcrypt('enum-probe-pass'),
        'period_start_day' => 1,
    ]);
}

function probeSetupScreen(string $property, mixed $value): Closure
{
    $user = enumProbeUser();

    return fn () => Livewire::actingAs($user)->test(SetupProgressScreen::class)->set($property, $value);
}

it('answers an unbacked enum value with the lock, not a fatal from the enum itself', function (): void {
    expect(probeSetupScreen('phase', '-1'))->toThrow(CannotUpdateLockedPropertyException::class);
    expect(probeSetupScreen('blocked', 'not-a-reason'))->toThrow(CannotUpdateLockedPropertyException::class);
    expect(probeSetupScreen('step', ['a' => 'b']))->toThrow(CannotUpdateLockedPropertyException::class);
    expect(probeSetupScreen('phase', ''))->toThrow(CannotUpdateLockedPropertyException::class);
});

it('refuses a backed value too, so the completion gate cannot be walked past', function (): void {
    expect(probeSetupScreen('phase', SyncPhase::Complete->value))
        ->toThrow(CannotUpdateLockedPropertyException::class);
});

it('still derives the phase from the puller on mount', function (): void {
    Livewire::actingAs(enumProbeUser())
        ->test(SetupProgressScreen::class)
        ->assertOk()
        ->assertSet('phase', SyncPhase::Pending);
});
