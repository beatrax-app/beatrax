<?php

declare(strict_types=1);

use Modules\Core\Models\User;
use Modules\Core\Public\Support\PatternScan;
use Modules\DevMode\Public\Contracts\DevCommandRegistry;
use Modules\DevMode\Public\Dto\CommandSpec;

function doctorRerunDeveloper(string $username = 'doctor-rerun-dev'): User
{
    return User::query()->create([
        'username' => $username,
        'password' => 'fixture-password',
        'period_start_day' => 1,
        'default_currency_view' => 'eur_only',
        'is_developer' => true,
    ]);
}

it('names a command ArtisanSpawnController will accept', function (): void {
    $user = doctorRerunDeveloper();

    $html = html_entity_decode(
        $this->actingAs($user)->get('/dev/doctor')->assertOk()->getContent(),
        ENT_QUOTES,
    );

    $m = PatternScan::first("/'\\/dev\\/artisan\\/spawn'.*?command:\\s*'([^']+)'/s", $html);
    expect($m)->not->toBe([]);

    /** @var DevCommandRegistry $registry */
    $registry = app(DevCommandRegistry::class);
    $safeNames = array_map(static fn (CommandSpec $spec): string => $spec->name, $registry->safe());

    expect($safeNames)->toContain($m[1]);
});
