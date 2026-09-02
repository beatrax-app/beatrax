<?php

declare(strict_types=1);

use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Modules\Core\Public\Actions\RecordUpdateAvailableAlert;
use Modules\Core\Public\Dto\UpdateManifestDto;
use Modules\Core\Public\Enums\UpdateAlertKind;

uses(RefreshDatabase::class);

// system_alerts declares no unique index, so its id was the autoincrement and
// nothing else. Two devices polling the same feed each raised the same alert
// under the same id, and the arriving create was refused. The id is derived
// from the release now, which makes it the dedupe key as well: the poll runs
// on a schedule, and a plain insert wrote one banner row per poll.

function releaseManifest(string $version): UpdateManifestDto
{
    return new UpdateManifestDto(
        latestVersion: $version,
        sha512Hex: str_repeat('a', 128),
        publishedAt: CarbonImmutable::parse('2026-09-01 09:00:00'),
        channel: 'stable',
    );
}

it('raises one alert however often the same release is polled', function (): void {
    $record = app(RecordUpdateAvailableAlert::class);

    $record(releaseManifest('1.4.0'), UpdateAlertKind::Available, '1.3.0');
    $record(releaseManifest('1.4.0'), UpdateAlertKind::Available, '1.3.0');
    $record(releaseManifest('1.4.0'), UpdateAlertKind::Available, '1.3.0');

    expect(DB::table('system_alerts')->where('kind', UpdateAlertKind::Available->value)->count())->toBe(1);
});

it('still raises a second alert for a second release', function (): void {
    $record = app(RecordUpdateAvailableAlert::class);

    $record(releaseManifest('1.4.0'), UpdateAlertKind::Available, '1.3.0');
    $record(releaseManifest('1.5.0'), UpdateAlertKind::Available, '1.3.0');

    expect(DB::table('system_alerts')->where('kind', UpdateAlertKind::Available->value)->count())->toBe(2);
});

// Two devices poll the same feed and each raises the alert for itself. The id
// is a function of the release, so the second one to arrive is the row that is
// already here rather than a different row wearing its id.
it('gives the same release the same id on every device', function (): void {
    $record = app(RecordUpdateAvailableAlert::class);

    $record(releaseManifest('1.4.0'), UpdateAlertKind::Available, '1.3.0');
    $first = DB::table('system_alerts')->where('kind', UpdateAlertKind::Available->value)->value('id');

    DB::table('system_alerts')->delete();

    $record(releaseManifest('1.4.0'), UpdateAlertKind::Available, '1.2.0');
    $second = DB::table('system_alerts')->where('kind', UpdateAlertKind::Available->value)->value('id');

    expect($second)->toBe($first);
});
