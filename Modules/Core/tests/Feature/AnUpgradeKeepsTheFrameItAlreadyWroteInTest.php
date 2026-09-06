<?php

declare(strict_types=1);

use Illuminate\Foundation\Testing\RefreshDatabase;
use Modules\Core\Models\User;
use Modules\Core\Public\Services\InstallTimezone;
use Modules\Core\Public\Support\HostTimezone;

uses(RefreshDatabase::class);

beforeEach(function (): void {
    $this->processZone = date_default_timezone_get();
    HostTimezone::fake('Pacific/Auckland');
    config(['app.timezone_pinned' => null]);
});

afterEach(function (): void {
    HostTimezone::fake(null);
    date_default_timezone_set($this->processZone);
});

function aukUser(string $username): User
{
    return User::query()->create([
        'username' => $username,
        'password' => 'fixture-password',
        'period_start_day' => 1,
        'default_currency_view' => 'eur_only',
    ]);
}

// The migration is what a real upgrade runs, so the case runs it rather than
// asserting about its source: put the install in the state an upgrade finds it
// in, then run the one migration forward over it.
function reRunTheFrameMigration(): void
{
    $migration = require base_path('Modules/Core/Database/Migrations/2026_09_06_000002_keep_the_frame_an_upgraded_install_already_wrote_in.php');
    $migration->up();
}

// An install that already holds an account wrote its rows at the zone every
// shipped bundle pinned. Letting the machine answer instead would move them.
it('pins the zone an install with an account already wrote in', function (): void {
    $owner = aukUser('auk-upgrade');
    User::query()->whereKey($owner->id)->update(['timezone' => null]);

    reRunTheFrameMigration();

    expect(User::query()->find($owner->id)->timezone)->toBe('Europe/Amsterdam')
        ->and(app(InstallTimezone::class)->zone())->toBe('Europe/Amsterdam');
});

// The positive control, in the order InstallCommand runs: migrate, then create
// the account. Reaching the migration with no account is what a first run looks
// like, and it must come out reading the machine rather than the Netherlands.
it('leaves a first run reading the machine it is on', function (): void {
    reRunTheFrameMigration();

    $owner = aukUser('auk-fresh');

    expect(User::query()->find($owner->id)->timezone)->toBeNull()
        ->and(app(InstallTimezone::class)->zone())->toBe('Pacific/Auckland');
});

it('does not talk over a zone the reader has already chosen', function (): void {
    $owner = aukUser('auk-chosen');
    User::query()->whereKey($owner->id)->update(['timezone' => 'Asia/Tokyo']);

    reRunTheFrameMigration();

    expect(User::query()->find($owner->id)->timezone)->toBe('Asia/Tokyo');
});

// One answer per installation, so a second reader's row stays empty: the
// owner's is the only one InstallTimezone reads.
it('writes the owner row and nobody else', function (): void {
    $owner = aukUser('auk-owner');
    $partner = aukUser('auk-partner');
    User::query()->whereKey($owner->id)->update(['timezone' => null]);

    reRunTheFrameMigration();

    expect(User::query()->find($owner->id)->timezone)->toBe('Europe/Amsterdam')
        ->and(User::query()->find($partner->id)->timezone)->toBeNull();
});
