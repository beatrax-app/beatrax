<?php

declare(strict_types=1);

namespace Modules\Core\Database\Seeders\Demo;

use Carbon\CarbonImmutable;
use Illuminate\Database\DatabaseManager;
use Modules\Core\Models\SystemAlert;
use Modules\Core\Models\User;
use Modules\Core\Public\Enums\UpdateAlertKind;

// Materialises one row per `system_alerts.kind` (see ALERTS below) so the
// banner-rotation logic exercises every branch on a fresh install.
// `system_alerts` has no UNIQUE constraint, so this keys the upsert on
// `(user_id, kind, metadata->>$.seed_key)` — production rows never carry it.
final class DemoSystemAlertsSeeder
{
    /**
     * @var list<array{kind: string, severity: string, message: string, ageHours: int, acknowledgedAgeHours: ?int, seedKey: string}>
     */
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
            'kind' => 'doctor_warning',
            'severity' => 'warning',
            'message' => 'SQLite `journal_mode` is set to DELETE; WAL mode is recommended for the background scanner.',
            'ageHours' => 36,
            'acknowledgedAgeHours' => null,
            'seedKey' => 'doctor-warning-current',
        ],
        [
            'kind' => UpdateAlertKind::Available->value,
            'severity' => 'info',
            'message' => 'A new release of beatrax is available — see Settings → About for release notes.',
            'ageHours' => 12,
            'acknowledgedAgeHours' => null,
            'seedKey' => 'update-available-current',
        ],
        [
            'kind' => 'force_password_change',
            'severity' => 'critical',
            'message' => 'Your account password is older than the configured rotation window. Please change it now.',
            'ageHours' => 8,
            'acknowledgedAgeHours' => null,
            'seedKey' => 'force-password-change-current',
        ],
        [
            'kind' => UpdateAlertKind::Available->value,
            'severity' => 'info',
            'message' => 'A prior release update notification — kept as an acknowledged audit row.',
            'ageHours' => 240,
            'acknowledgedAgeHours' => 200,
            'seedKey' => 'update-available-prior',
        ],
    ];

    public function __construct(
        private readonly DatabaseManager $db,
    ) {}

    /**
     * @param  array<string, User>  $users
     */
    public function run(array $users): int
    {
        $primary = $users['demo-1@beatrax.local'] ?? null;
        if ($primary !== null) {
            foreach (self::ALERTS as $row) {
                $this->upsertAlert($primary, $row);
            }
        }

        return SystemAlert::query()
            ->whereIn('user_id', array_map(static fn (User $u): int => $u->id, $users))
            ->count();
    }

    /**
     * @param  array{kind: string, severity: string, message: string, ageHours: int, acknowledgedAgeHours: ?int, seedKey: string}  $row
     */
    private function upsertAlert(User $user, array $row): void
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

        $now = CarbonImmutable::now();
        $createdAt = $now->subHours($row['ageHours']);
        $acknowledgedAt = $row['acknowledgedAgeHours'] === null
            ? null
            : $now->subHours($row['acknowledgedAgeHours']);

        $alert = new SystemAlert;
        $alert->user_id = $user->id;
        $alert->kind = $row['kind'];
        $alert->severity = $row['severity'];
        $alert->message = $row['message'];
        $alert->metadata = ['seed_key' => $row['seedKey']];
        $alert->acknowledged_at = $acknowledgedAt;
        $alert->created_at = $createdAt;
        $alert->save();
    }
}
