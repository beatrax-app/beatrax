<?php

declare(strict_types=1);

namespace Modules\Onboarding\Models;

use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Model;
use Modules\Core\Public\Concerns\BelongsToUser;

/**
 * Eloquent model for the wizard_progress table — one row models the
 * state of one step of the first-run setup wizard for one user.
 *
 * Allowed `status` values: `pending`, `in_progress`, `done`, `skipped`.
 * The allowed set is enforced by paired BEFORE INSERT / BEFORE UPDATE
 * triggers on the wizard_progress table; a typo in the application
 * layer fails loud at the DB boundary rather than landing a silently-
 * broken row.
 *
 * The `data` JSON cast carries per-step opaque payload (chosen
 * connector, OAuth provider name, uploaded filename) so the wizard
 * resumes mid-flow after a relaunch without re-prompting.
 *
 * Cross-user posture: every production read / write path that
 * operates on this table carries an EXPLICIT `where('user_id', ...)`
 * filter through the raw query builder. The `BelongsToUser` global
 * scope is a SECONDARY guard that fires only when an Eloquent query
 * reaches the model inside an HTTP-bound request; it stays so a
 * future Eloquent surface gets the scope wired automatically, but
 * the explicit-filter rule still applies on top of it.
 *
 * @property int $id
 * @property int|null $user_id
 * @property string $step_key
 * @property string $status
 * @property array<string, mixed>|null $data
 * @property CarbonImmutable|null $completed_at
 * @property CarbonImmutable|null $created_at
 * @property CarbonImmutable|null $updated_at
 */
final class WizardProgress extends Model
{
    use BelongsToUser;

    /** @var string|null */
    protected $table = 'wizard_progress';

    /** @var list<string> */
    protected $fillable = [
        'user_id',
        'step_key',
        'status',
        'data',
        'completed_at',
    ];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'data' => 'array',
            'completed_at' => 'immutable_datetime',
            'created_at' => 'immutable_datetime',
            'updated_at' => 'immutable_datetime',
        ];
    }
}
