<?php

declare(strict_types=1);

namespace Modules\Core\Public\Actions;

use Illuminate\Database\DatabaseManager;
use Modules\Core\Public\Contracts\Clock;
use Modules\Core\Public\Dto\UpdateManifestDto;
use Modules\Core\Public\Enums\SystemAlertSeverity;
use Modules\Core\Public\Enums\UpdateAlertKind;

final readonly class RecordUpdateAvailableAlert
{
    public function __construct(
        private DatabaseManager $db,
        private Clock $clock,
    ) {}

    public function __invoke(UpdateManifestDto $manifest): void
    {
        // Written with a null user_id so every account on the install sees the
        // one notification (SystemAlertQuery treats a null owner as system-wide);
        // the banner re-localises from metadata.latestVersion, so the stored
        // message is only the audit-row fallback.
        $this->db->connection()->table('system_alerts')->insert([
            'user_id' => null,
            'kind' => UpdateAlertKind::Available->value,
            'severity' => SystemAlertSeverity::Info->value,
            'message' => 'A new release of Beatrax is available — '.$manifest->latestVersion.'.',
            'metadata' => json_encode([
                'latestVersion' => $manifest->latestVersion,
                'channel' => $manifest->channel,
                'publishedAt' => $manifest->publishedAt->toIso8601String(),
            ], JSON_THROW_ON_ERROR),
            'created_at' => $this->clock->now(),
            'acknowledged_at' => null,
        ]);
    }
}
