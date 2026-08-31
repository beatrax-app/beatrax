<?php

declare(strict_types=1);

use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Livewire\Livewire;
use Modules\Core\Models\User;
use Modules\DevMode\Internal\Enums\CommandTier;
use Modules\DevMode\Internal\Http\Livewire\ArtisanRunnerPage;
use Modules\DevMode\Internal\Process\RunRecord;
use Modules\DevMode\Internal\Process\RunRegistry;

function argRuleUser(string $username): User
{
    return User::query()->create([
        'username' => $username,
        'password' => 'fixture-password',
        'period_start_day' => 1,
        'default_currency_view' => 'eur_only',
        'is_developer' => true,
    ]);
}

// The spawner writes its eager audit row before anything else, so an empty
// table is proof no child was started.
function spawnedRunCount(): int
{
    return DB::table('dev_mode_audit')->count();
}

it('refuses over HTTP and through Livewire alike a value the arg rules call impossible', function (): void {
    $user = argRuleUser('arg-rules-livewire');
    $tooLong = str_repeat('a', 5000);

    $this->actingAs($user)
        ->postJson('/dev/artisan/spawn', ['command' => 'config:show', 'args' => ['config' => $tooLong]])
        ->assertStatus(422);

    Livewire::actingAs($user)
        ->test(ArtisanRunnerPage::class)
        ->call('spawn', 'config:show', ['config' => $tooLong])
        ->assertDispatched(
            'toast',
            fn (string $event, array $params) => isset($params['message'])
                && is_string($params['message'])
                && str_contains($params['message'], 'config:show'),
        );

    expect(spawnedRunCount())->toBe(0);
});

it('refuses a positional value that Symfony Console would read as an option', function (): void {
    $user = argRuleUser('arg-rules-option-shaped');

    Livewire::actingAs($user)
        ->test(ArtisanRunnerPage::class)
        ->call('spawn', 'beatrax:failed-jobs', ['action' => '--force'])
        ->assertDispatched('toast');

    expect(spawnedRunCount())->toBe(0);
});

it('still spawns when every declared rule is satisfied', function (): void {
    if (! extension_loaded('posix')) {
        $this->markTestSkipped('posix extension required for spawn-then-tail');
    }

    $user = argRuleUser('arg-rules-satisfied');

    Livewire::actingAs($user)
        ->test(ArtisanRunnerPage::class)
        ->call('spawn', 'config:show', ['config' => 'app'])
        ->assertDispatched('toast');

    expect(spawnedRunCount())->toBe(1);
});

it('refuses to re-run another developer run, the way the stream and cancel endpoints do', function (): void {
    $owner = argRuleUser('rerun-owner');
    $intruder = argRuleUser('rerun-intruder');
    $runId = (string) Str::uuid();

    /** @var RunRegistry $registry */
    $registry = app(RunRegistry::class);
    $registry->store(new RunRecord(
        runId: $runId,
        pid: 999_999,
        command: 'cache:clear',
        args: [],
        startedAt: CarbonImmutable::now(),
        callerUserId: $owner->id,
        tier: CommandTier::Safe,
        status: 'done',
        outPath: sys_get_temp_dir().'/'.$runId.'.out',
    ));

    Livewire::actingAs($intruder)
        ->test(ArtisanRunnerPage::class)
        ->call('rerun', $runId)
        ->assertDispatched(
            'toast',
            fn (string $event, array $params) => isset($params['message'])
                && $params['message'] === 'That run belongs to another developer.',
        );

    // Nothing spawned, and no audit row attributing the owner's command to the
    // caller who asked for it.
    expect(spawnedRunCount())->toBe(0);
});
