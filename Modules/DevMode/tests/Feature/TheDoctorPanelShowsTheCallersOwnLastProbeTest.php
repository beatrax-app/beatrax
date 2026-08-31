<?php

declare(strict_types=1);

use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Modules\Core\Models\User;

function doctorScopeUser(string $username): User
{
    return User::query()->create([
        'username' => $username,
        'password' => 'fixture-password',
        'period_start_day' => 1,
        'default_currency_view' => 'eur_only',
        'is_developer' => true,
    ]);
}

function insertDoctorRun(User $causer, string $stdout, string $createdAt): void
{
    DB::table('dev_mode_audit')->insert([
        'log_name' => 'dev_mode',
        'description' => 'command_executed',
        'subject_type' => null,
        'subject_id' => null,
        'causer_type' => User::class,
        'causer_id' => $causer->id,
        'event' => null,
        'attribute_changes' => null,
        'properties' => json_encode([
            'command' => 'beatrax:doctor',
            'args' => [],
            'tier' => 'safe',
            'exit_code' => 0,
            'stdout_excerpt' => $stdout,
            'error_excerpt' => '',
        ], JSON_THROW_ON_ERROR),
        'created_at' => $createdAt,
        'updated_at' => $createdAt,
    ]);
}

it('does not show another developer probe output, filesystem paths and all', function (): void {
    $other = doctorScopeUser('doctor-other-dev');
    $viewer = doctorScopeUser('doctor-viewer');

    insertDoctorRun($other, "Backup freshness         ok       /Users/other/private/backups is fresh.\n", Carbon::now()->toDateTimeString());

    $response = $this->actingAs($viewer)->get('/dev/doctor');

    $response->assertOk();
    expect((string) $response->getContent())->not->toContain('/Users/other/private/backups');
});

it('shows the finalized probe rather than the empty eager row written in the same second', function (): void {
    $user = doctorScopeUser('doctor-same-second');
    $sameSecond = Carbon::now()->toDateTimeString();

    // The spawner writes the eager row first and FinalizeRunAudit's fallback
    // appends the finished one, so id is the only thing that orders them.
    insertDoctorRun($user, '', $sameSecond);
    insertDoctorRun($user, "PHP version              ok       PHP 8.5.0 is at or above 8.5.0.\n", $sameSecond);

    $response = $this->actingAs($user)->get('/dev/doctor');

    $response->assertOk();
    $html = (string) $response->getContent();
    expect($html)->toContain('PHP version');
    expect($html)->not->toContain('No probe output captured yet.');
});
