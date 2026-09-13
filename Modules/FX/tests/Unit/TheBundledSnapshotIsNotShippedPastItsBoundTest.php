<?php

declare(strict_types=1);

use Carbon\CarbonImmutable;
use Illuminate\Console\Events\CommandStarting;
use Modules\Core\Public\Contracts\Clock;
use Modules\Core\Public\Support\SafeDate;
use Modules\FX\Internal\Build\BundledSnapshot;
use Modules\FX\Internal\Build\StaleBundledRatesException;
use Modules\FX\Internal\Listeners\RefuseToShipStaleBundledRates;
use Modules\FX\Internal\Providers\BundledSnapshotProvider;
use Symfony\Component\Console\Input\ArrayInput;
use Symfony\Component\Console\Output\BufferedOutput;

// fx_online_enabled is off by default, so on a stock install this file is not
// the fallback -- it is the only thing pricing a cross-currency roll-up. The
// reader is told the figures are old; nothing told the build.

function shippedRatesAt(string $date, string $body = '{"date":"%s","rates":{"USD":"1.1592"}}'): BundledSnapshot
{
    $path = tempnam(sys_get_temp_dir(), 'rates');
    file_put_contents($path, sprintf($body, $date));

    return new BundledSnapshot(new BundledSnapshotProvider($path), $path);
}

function shippedRatesClock(string $now): Clock
{
    return new class($now) implements Clock
    {
        public function __construct(private string $now) {}

        public function now(): CarbonImmutable
        {
            return CarbonImmutable::parse($this->now);
        }
    };
}

function shippedRatesPackaging(BundledSnapshot $snapshot, string $now, string $command): void
{
    (new RefuseToShipStaleBundledRates($snapshot, shippedRatesClock($now)))
        ->handle(new CommandStarting($command, new ArrayInput([]), new BufferedOutput));
}

it('passes a snapshot inside the bound, to the day', function (): void {
    $ninety = shippedRatesAt('2026-06-13')->staleness(CarbonImmutable::parse('2026-09-11 23:00:00'));

    expect($ninety)->toBeNull();
});

it('refuses a snapshot one day past the bound', function (): void {
    $stale = shippedRatesAt('2026-06-12')->staleness(CarbonImmutable::parse('2026-09-11 01:00:00'));

    expect($stale)->not->toBeNull()
        ->and($stale?->sentence('native:package'))->toContain('91 days old, against a bound of 90');
});

// The measured state of the shipped file before this guard existed: a hundred
// days, thirty-three times past the mark a reader is shown.
it('names the age and the file when the snapshot is a hundred days old', function (): void {
    $snapshot = shippedRatesAt('2026-06-05');

    expect(fn () => shippedRatesPackaging($snapshot, '2026-09-13 09:00:00', 'native:package'))
        ->toThrow(StaleBundledRatesException::class, '100 days old, against a bound of 90');
});

it('refuses a snapshot it cannot read a date out of', function (): void {
    $undated = shippedRatesAt('2026-09-11', '{"date":"the fifth of June %s","rates":{"USD":"1.1"}}');

    expect($undated->staleness(CarbonImmutable::parse('2026-09-11 09:00:00'))?->sentence('native:build'))
        ->toContain('that is not a Y-m-d day');
});

it('refuses a snapshot that is not there at all', function (): void {
    $missing = new BundledSnapshot(
        new BundledSnapshotProvider('/nowhere/rates-snapshot.json'),
        '/nowhere/rates-snapshot.json',
    );

    expect($missing->staleness(CarbonImmutable::parse('2026-09-11 09:00:00'))?->sentence('native:build'))
        ->toContain('nothing a rate can be read out of');
});

// native:run is not packaging: a developer iterating locally reads the same
// staleness mark every other reader does, and stopping the loop for it would
// trade a real cost for nothing.
it('lets a command that ships no bundle through however old the file is', function (): void {
    $snapshot = shippedRatesAt('2020-01-01');

    foreach (['native:run', 'migrate', 'beatrax:doctor'] as $command) {
        shippedRatesPackaging($snapshot, '2026-09-13 09:00:00', $command);
    }

    expect(RefuseToShipStaleBundledRates::SHIPS_THE_BUNDLED_SNAPSHOT)
        ->toBe(['mobile:package-android', 'native:build', 'native:package']);
});

it('stops every command that packages a bundle', function (): void {
    $snapshot = shippedRatesAt('2020-01-01');

    foreach (RefuseToShipStaleBundledRates::SHIPS_THE_BUNDLED_SNAPSHOT as $command) {
        expect(fn () => shippedRatesPackaging($snapshot, '2026-09-13 09:00:00', $command))
            ->toThrow(StaleBundledRatesException::class, 'php scripts/refresh_bundled_rates.php');
    }
});

// The file this repository actually ships, read the way the guard reads it.
// Its age is the build's question and is asked against the real clock there,
// deliberately not here: a bound measured from now() in the suite is the same
// expiry date this guard exists to take off the shipped file.
it('ships a snapshot the guard can read a day out of', function (): void {
    $provider = new BundledSnapshotProvider;
    $date = $provider->fetch()['date'];

    $refused = (new BundledSnapshot($provider, $provider->path()))
        ->staleness(CarbonImmutable::parse($date));

    expect(SafeDate::dayOrNull($date))->not->toBeNull()
        ->and($refused)->toBeNull();
});
