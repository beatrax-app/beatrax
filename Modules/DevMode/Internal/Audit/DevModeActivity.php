<?php

declare(strict_types=1);

namespace Modules\DevMode\Internal\Audit;

use Spatie\Activitylog\Models\Activity as SpatieActivity;

/**
 * Custom Activity model that targets the `dev_mode_audit` table.
 *
 * Required because spatie/laravel-activitylog v5 removed the
 * `table_name` config option (per the package's UPGRADING.md: "If
 * you need a custom table name or connection, create a custom
 * Activity model and set `$table` / `$connection` on it. Then point
 * `activity_model` to your custom model.").
 *
 * Registered via `config('activitylog.activity_model')` =
 * \\Modules\\DevMode\\Internal\\Audit\\DevModeActivity::class so
 * every activity() / ActivityLogger call routes through this model
 * and lands rows in the renamed table.
 */
final class DevModeActivity extends SpatieActivity
{
    /** @var string|null */
    protected $table = 'dev_mode_audit';
}
