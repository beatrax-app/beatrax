<?php

declare(strict_types=1);

use Carbon\CarbonImmutable;
use Modules\Sync\Public\Services\SyncStatusService;

/*
 * The sync panel's "synced N ago" came from a hand-rolled ladder returning
 * English literals, so a Dutch phone read "gesynchroniseerd 1h ago". Carbon
 * already knows all 26 languages and SetLocale already tells it which one.
 */

it('renders the sync gap in the active language', function (): void {
    $now = CarbonImmutable::parse('2026-08-17 12:00:00');
    $past = $now->subHours(3)->toDateTimeString();

    CarbonImmutable::setLocale('en');
    $english = SyncStatusService::relativeTime($now, $past);

    CarbonImmutable::setLocale('nl');
    $dutch = SyncStatusService::relativeTime($now, $past);

    CarbonImmutable::setLocale('en');

    expect($english)->toBeString()->not->toBe('')
        ->and($dutch)->toBeString()->not->toBe('')
        ->and($dutch)->not->toBe($english)
        ->and($dutch)->not->toContain('ago');
});

it('returns null for a timestamp it cannot parse', function (): void {
    expect(SyncStatusService::relativeTime(CarbonImmutable::parse('2026-08-17 12:00:00'), 'not-a-date'))
        ->toBeNull();
});
