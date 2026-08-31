<?php

declare(strict_types=1);

namespace Modules\Core\Database\Seeders\Demo;

use Carbon\CarbonImmutable;
use Illuminate\Database\DatabaseManager;
use Modules\Core\Models\SystemAlert;
use Modules\Core\Models\User;
use Modules\Core\Public\Contracts\Clock;
use Modules\Core\Public\Enums\UpdateAlertKind;

// Materialises one row per `system_alerts.kind` (see ALERTS below) so the
// banner-rotation logic exercises every branch on a fresh install.
// `system_alerts` has no UNIQUE constraint, so this keys the upsert on
// `(user_id, kind, metadata->>$.seed_key)` — production rows never carry it.
final class DemoSystemAlertsSeeder
{
    /**
     * @var list<array{kind: string, severity: string, message: string, ageHours: int, acknowledgedAgeHours: ?int, seedKey: string, metadata?: array<string, mixed>}>
     */
    // Every kind here is one the app can actually raise. Two were not: a
    // `doctor_warning` carrying the wal_mode_missing sentence, and a
    // `force_password_change` for a rotation window this app has no feature
    // for. Both fell through and printed English on a Dutch screen.
    private const ALERTS = [
        [
            'kind' => 'backup_corrupt',
            'severity' => 'critical',
            'message' => 'The most recent SQLite backup failed checksum verification. Run `php artisan db:backup` to retry.',
            'ageHours' => 4,
            'acknowledgedAgeHours' => null,
            'seedKey' => 'backup-corrupt-current',
        ],
        [
            'kind' => 'wal_mode_missing',
            'severity' => 'warning',
            'message' => 'SQLite `journal_mode` is set to DELETE; WAL mode is recommended for the background scanner.',
            'ageHours' => 36,
            'acknowledgedAgeHours' => null,
            'seedKey' => 'doctor-warning-current',
            'metadata' => ['current_mode' => 'DELETE'],
        ],
        [
            'kind' => UpdateAlertKind::Available->value,
            'severity' => 'info',
            'message' => 'A new release of Beatrax is available — see Settings → About for release notes.',
            'ageHours' => 12,
            'acknowledgedAgeHours' => null,
            'seedKey' => 'update-available-current',
            'metadata' => ['latestVersion' => '0.1.0'],
        ],
        [
            'kind' => 'auth.recovery_code_failed',
            'severity' => 'critical',
            'message' => 'Failed recovery code attempt for demo-1.',
            'ageHours' => 8,
            'acknowledgedAgeHours' => null,
            'seedKey' => 'force-password-change-current',
            'metadata' => ['username' => 'demo-1'],
        ],
        [
            'kind' => UpdateAlertKind::Available->value,
            'severity' => 'info',
            'message' => 'A prior release update notification — kept as an acknowledged audit row.',
            'ageHours' => 240,
            'acknowledgedAgeHours' => 200,
            'seedKey' => 'update-available-prior',
            'metadata' => ['latestVersion' => '0.0.9'],
        ],
    ];

    public function __construct(
        private readonly DatabaseManager $db,
        private readonly Clock $clock,
    ) {}

    /**
     * @param  array<string, User>  $users
     */
    public function run(array $users): int
    {
        $primary = $users['demo-1'] ?? null;
        if ($primary !== null) {
            $now = $this->clock->now();
            foreach (self::ALERTS as $row) {
                $this->upsertAlert($primary, $row, $now);
            }
        }

        return SystemAlert::query()
            ->whereIn('user_id', array_map(static fn (User $u): int => $u->id, $users))
            ->count();
    }

    /**
     * @param  array{kind: string, severity: string, message: string, ageHours: int, acknowledgedAgeHours: ?int, seedKey: string, metadata?: array<string, mixed>}  $row
     */
    private function upsertAlert(User $user, array $row, CarbonImmutable $now): void
    {
        $existing = $this->db->connection()
            ->table('system_alerts')
            ->where('user_id', $user->id)
            ->where('kind', $row['kind'])
            ->whereRaw("json_extract(metadata, '$.seed_key') = ?", [$row['seedKey']])
            ->exists();

        if ($existing) {
            return;
        }

        $createdAt = $now->subHours($row['ageHours']);
        $acknowledgedAt = $row['acknowledgedAgeHours'] === null
            ? null
            : $now->subHours($row['acknowledgedAgeHours']);

        $alert = new SystemAlert;
        $alert->user_id = $user->id;
        $alert->kind = $row['kind'];
        $alert->severity = $row['severity'];
        $alert->message = $row['message'];
        // The banner renders by kind and reads its values out of metadata, so
        // a demo row without them renders the fallback rather than the copy the
        // reader would actually get: two buttons that only dismiss for an
        // update, and English for everything else.
        $metadata = array_merge(['seed_key' => $row['seedKey']], $row['metadata'] ?? []);

        $alert->metadata = $metadata;
        $alert->acknowledged_at = $acknowledgedAt;
        $alert->created_at = $createdAt;
        $alert->save();
    }
}
