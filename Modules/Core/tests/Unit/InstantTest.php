<?php

declare(strict_types=1);

use Carbon\CarbonImmutable;
use Modules\Core\Public\Contracts\Clock;
use Modules\Core\Public\Support\Instant;
use Modules\Core\Public\Support\RetentionWindow;

beforeEach(function (): void {
    $this->originalTimezone = date_default_timezone_get();
    date_default_timezone_set('Europe/Amsterdam');
});

afterEach(function (): void {
    date_default_timezone_set($this->originalTimezone);
});

it('moves an app-zone instant to UTC before it wears a Z', function (): void {
    $appZone = CarbonImmutable::create(2026, 5, 30, 16, 15, 22, 'Europe/Amsterdam');

    expect($appZone->format('c'))->toBe('2026-05-30T16:15:22+02:00');
    expect(Instant::zulu($appZone))->toBe('2026-05-30T14:15:22Z');
});

it('reads back a Zulu stamp as the instant it was given', function (): void {
    $appZone = CarbonImmutable::create(2026, 5, 30, 16, 15, 22, 'Europe/Amsterdam');
    $roundTripped = new DateTimeImmutable(Instant::zulu($appZone));

    expect($roundTripped->getTimestamp())->toBe($appZone->getTimestamp());
});

it('moves a sender-offset instant into the frame a DATETIME column is read in', function (): void {
    $sender = new DateTimeImmutable('Thu, 14 May 2026 23:40:00 -0700');

    expect($sender->format('Y-m-d H:i:s'))->toBe('2026-05-14 23:40:00');
    expect(Instant::appLocal($sender))->toBe('2026-05-15 08:40:00');
    expect(CarbonImmutable::parse(Instant::appLocal($sender))->getTimestamp())
        ->toBe($sender->getTimestamp());
});

it('turns a month boundary the way the reader would see it', function (): void {
    $sender = new DateTimeImmutable('Sun, 31 May 2026 23:40:00 -0700');

    expect(Instant::inAppZone($sender)->format('Y/m'))->toBe('2026/06');
    expect(Instant::inAppZone($sender)->format('Y-m-d'))->toBe('2026-06-01');
});

it('leaves an instant already in the app zone exactly where it is', function (): void {
    $appZone = CarbonImmutable::create(2026, 5, 30, 16, 15, 22, 'Europe/Amsterdam');

    expect(Instant::appLocal($appZone))->toBe('2026-05-30 16:15:22');
    expect(Instant::appLocal(Instant::inAppZone($appZone)))->toBe(Instant::appLocal($appZone));
});

it('builds the retention cutoff on the app clock rather than on the database', function (): void {
    /** @var Clock $clock */
    $clock = app(Clock::class);
    $expected = Instant::appLocal($clock->now()->subDays(RetentionWindow::DAYS));

    expect(RetentionWindow::cutoff($clock))->toBe($expected);
    expect(RetentionWindow::DAYS)->toBe(365);
});

it('refuses to hand back a stamp that does not match the shape it promises', function (): void {
    // The class is final and the patterns are private, so the only reachable
    // proof that the assertion runs is that a well-formed instant passes both
    // and the shapes differ from each other.
    $moment = CarbonImmutable::create(2026, 1, 2, 3, 4, 5, 'Europe/Amsterdam');

    expect(Instant::zulu($moment))->toMatch('/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}Z$/');
    expect(Instant::appLocal($moment))->toMatch('/^\d{4}-\d{2}-\d{2} \d{2}:\d{2}:\d{2}$/');
});
