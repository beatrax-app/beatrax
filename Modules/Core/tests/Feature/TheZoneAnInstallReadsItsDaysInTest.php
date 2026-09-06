<?php

declare(strict_types=1);

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Event;
use Modules\Core\Models\User;
use Modules\Core\Public\Actions\WriteUserPreference;
use Modules\Core\Public\Services\InstallTimezone;
use Modules\Core\Public\Support\HostTimezone;
use Modules\Sync\Public\Events\EntityMutated;

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

function zaiUser(string $username): User
{
    return User::query()->create([
        'username' => $username,
        'password' => 'fixture-password',
        'period_start_day' => 1,
        'default_currency_view' => 'eur_only',
    ]);
}

it('reads the machine when nothing has been chosen', function (): void {
    zaiUser('zai-host');

    expect(app(InstallTimezone::class)->zone())->toBe('Pacific/Auckland');
});

it('reads the stored choice over the machine', function (): void {
    $owner = zaiUser('zai-stored');
    User::query()->whereKey($owner->id)->update(['timezone' => 'Asia/Tokyo']);

    expect(app(InstallTimezone::class)->zone())->toBe('Asia/Tokyo');
});

// A deployment naming a zone outright is saying what frame it runs in, and the
// test suite is saying the same thing. Both outrank a row.
it('reads an environment that names a zone over both', function (): void {
    $owner = zaiUser('zai-pinned');
    User::query()->whereKey($owner->id)->update(['timezone' => 'Asia/Tokyo']);
    config(['app.timezone_pinned' => 'Europe/Amsterdam']);

    expect(app(InstallTimezone::class)->zone())->toBe('Europe/Amsterdam');
});

// The positive control for the case above: with the pin removed the row is
// read, so "the pin won" is a difference and not the only answer available.
it('goes back to the row when the environment stops naming one', function (): void {
    $owner = zaiUser('zai-unpinned');
    User::query()->whereKey($owner->id)->update(['timezone' => 'Asia/Tokyo']);
    config(['app.timezone_pinned' => 'Europe/Amsterdam']);

    expect(app(InstallTimezone::class)->zone())->toBe('Europe/Amsterdam');

    config(['app.timezone_pinned' => null]);

    expect(app(InstallTimezone::class)->zone())->toBe('Asia/Tokyo');
});

it('ignores a stored value that is not a zone identifier', function (): void {
    $owner = zaiUser('zai-nonsense');
    User::query()->whereKey($owner->id)->update(['timezone' => 'GMT+02:00']);

    expect(app(InstallTimezone::class)->chosen())->toBeNull()
        ->and(app(InstallTimezone::class)->zone())->toBe('Pacific/Auckland');
});

// One answer per installation. A second reader opening the screen writes the
// owner's row, because two rows holding two zones would put one ledger in two
// frames.
it('writes the choice on the owner row whoever made it', function (): void {
    $owner = zaiUser('zai-owner');
    $partner = zaiUser('zai-partner');

    app(InstallTimezone::class)->choose(app(WriteUserPreference::class), 'Asia/Tokyo');

    expect(User::query()->find($owner->id)->timezone)->toBe('Asia/Tokyo')
        ->and(User::query()->find($partner->id)->timezone)->toBeNull();
});

it('hands the answer back to the machine when the choice is cleared', function (): void {
    $owner = zaiUser('zai-cleared');
    app(InstallTimezone::class)->choose(app(WriteUserPreference::class), 'Asia/Tokyo');

    app(InstallTimezone::class)->choose(app(WriteUserPreference::class), null);

    expect(User::query()->find($owner->id)->timezone)->toBeNull()
        ->and(app(InstallTimezone::class)->zone())->toBe('Pacific/Auckland');
});

// The column travels, so the write has to be announced or a paired device
// keeps reading its own day while this one has moved.
it('announces the change so it reaches a paired device', function (): void {
    Event::fake([EntityMutated::class]);
    $owner = zaiUser('zai-announced');

    app(InstallTimezone::class)->choose(app(WriteUserPreference::class), 'Asia/Tokyo');

    Event::assertDispatched(EntityMutated::class, function (EntityMutated $event) use ($owner): bool {
        return $event->table === 'users'
            && $event->pk === $owner->id
            && array_key_exists('timezone', $event->dirtyFields);
    });
});

it('refuses a zone the platform does not know rather than storing it', function (): void {
    $owner = zaiUser('zai-refused');

    app(InstallTimezone::class)->choose(app(WriteUserPreference::class), 'Europe/Nowhere');

    expect(User::query()->find($owner->id)->timezone)->toBeNull();
});

// Both, because they are read by different callers: Carbon and Instant take
// the process default, the framework's date casting takes the config value.
it('moves the process default and the config together', function (): void {
    zaiUser('zai-applied');

    app(InstallTimezone::class)->apply('Asia/Tokyo');

    expect(date_default_timezone_get())->toBe('Asia/Tokyo')
        ->and(config('app.timezone'))->toBe('Asia/Tokyo');
});
