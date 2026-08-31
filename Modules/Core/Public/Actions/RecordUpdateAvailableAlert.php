<?php

declare(strict_types=1);

namespace Modules\Core\Public\Actions;

use Illuminate\Database\DatabaseManager;
use Modules\Core\Public\Contracts\Clock;
use Modules\Core\Public\Dto\UpdateManifestDto;
use Modules\Core\Public\Enums\UpdateAlertKind;
use Modules\Core\Public\Support\CopyLine;
use Modules\Core\Public\Support\StoredCopy;

final readonly class RecordUpdateAvailableAlert
{
    public function __construct(
        private DatabaseManager $db,
        private Clock $clock,
    ) {}

    public function __invoke(
        UpdateManifestDto $manifest,
        UpdateAlertKind $kind = UpdateAlertKind::Available,
        string $installedVersion = '',
    ): void {
        // Null user_id so every account sees the one notification. The banner
        // re-localises from the metadata versions; the stored line is only its
        // fallback, and it stays "available" for a stale row too — with no
        // recorded installed version there is no "you are on X" to say.
        $line = CopyLine::of('core::alerts.messages.update_available', ['version' => $manifest->latestVersion]);

        // The spec joins the blob after the filter rather than inside it: the
        // filter is there to drop an unset version string, and it is typed for
        // the strings it was written against.
        $versions = array_filter([
            'currentVersion' => $installedVersion === '' ? null : $installedVersion,
            'latestVersion' => $manifest->latestVersion,
            'channel' => $manifest->channel,
            'publishedAt' => $manifest->publishedAt->toIso8601String(),
        ], static fn (?string $value): bool => $value !== null);

        $this->db->connection()->table('system_alerts')->insert([
            'user_id' => null,
            'kind' => $kind->value,
            'severity' => $kind->severity()->value,
            'message' => $line->sentence(),
            'metadata' => json_encode(StoredCopy::inParams($line) + $versions, JSON_THROW_ON_ERROR),
            'created_at' => $this->clock->now(),
            'acknowledged_at' => null,
        ]);
    }
}
