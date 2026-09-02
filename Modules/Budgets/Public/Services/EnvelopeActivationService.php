<?php

declare(strict_types=1);

namespace Modules\Budgets\Public\Services;

use Illuminate\Database\DatabaseManager;
use Modules\Core\Models\User;
use Modules\Core\Public\Actions\WriteUserPreference;
use Modules\Core\Public\Contracts\Clock;
use Modules\Pots\Public\Enums\PotStatus;
use Modules\Pots\Public\Services\PotWriter;

final readonly class EnvelopeActivationService
{
    public function __construct(
        private DatabaseManager $db,
        private PotWriter $potWriter,
        private Clock $clock,
        private WriteUserPreference $preferences,
    ) {}

    public function activate(): void
    {
        $userIds = $this->db->connection()
            ->table('users')
            ->whereNull('envelope_activated_at')
            ->pluck('id')
            ->map(static fn (mixed $id): int => is_numeric($id) ? (int) $id : 0)
            ->filter(static fn (int $id): bool => $id > 0)
            ->values()
            ->all();

        foreach ($userIds as $userId) {
            $this->activateForUser($userId);
        }
    }

    // A no-op if another caller already claimed (and thus already fully
    // processed) this user. Public because the cutover sweep above is a walk
    // over the readers who existed when it ran, and a reader who signs up
    // afterwards needs the same stamp from the same code.
    public function activateForUser(int $userId): void
    {
        // Atomic claim BEFORE the walk: exactly one caller can flip this
        // row from NULL, so a concurrent or repeated activate() call
        // never double-archives.
        $claimed = $this->db->connection()
            ->table('users')
            ->where('id', $userId)
            ->whereNull('envelope_activated_at')
            ->update(['envelope_activated_at' => $this->clock->now()->toDateTimeString()]);

        if ($claimed === 0) {
            return;
        }

        // The carryover fold's genesis anchor, and a synced column. A peer that
        // paired before this claim reads every synced assignment as zero until
        // it hears the stamp, and the whole-row backfill only carries it for a
        // device that joined afterwards.
        $this->preferences->announce($userId, ['envelope_activated_at']);

        // No spanning transaction: PotWriter::archive() dispatches its events
        // after its own inner commit, and an outer one would defer them. A walk
        // that throws part-way unclaims the user instead, below.
        try {
            /** @var User|null $user */
            $user = User::query()->where('id', $userId)->first();
            if ($user === null) {
                return;
            }

            $potIds = $this->db->connection()
                ->table('pots')
                ->where('user_id', $userId)
                ->where('status', PotStatus::Active->value)
                ->whereNotNull('category_id')
                ->pluck('id');

            foreach ($potIds as $potId) {
                $potIdInt = is_numeric($potId) ? (int) $potId : 0;
                if ($potIdInt <= 0) {
                    continue;
                }

                $this->potWriter->archive($user, $potIdInt);
            }
        } catch (\Throwable $e) {
            $this->db->connection()
                ->table('users')
                ->where('id', $userId)
                ->update(['envelope_activated_at' => null]);

            $this->preferences->announce($userId, ['envelope_activated_at']);

            throw $e;
        }
    }
}
