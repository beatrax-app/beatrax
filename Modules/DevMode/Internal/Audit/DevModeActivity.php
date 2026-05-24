<?php

declare(strict_types=1);

namespace Modules\DevMode\Internal\Audit;

use Spatie\Activitylog\Models\Activity as SpatieActivity;

/**
 * Custom Activity model that targets the renamed `dev_mode_audit` table
 * (CONTEXT D-23). Required because spatie/laravel-activitylog v5
 * REMOVED the `table_name` config option (per the package's
 * UPGRADING.md: "If you need a custom table name or connection, create
 * a custom Activity model and set `$table` / `$connection` on it. Then
 * point `activity_model` to your custom model.").
 *
 * Registered via `config('activitylog.activity_model')` =
 * `\Modules\DevMode\Internal\Audit\DevModeActivity::class` so every
 * `activity()` / `ActivityLogger` call routes through this model.
 *
 * 16-03 published the table with the renamed name + set
 * `table_name='dev_mode_audit'` in config/activitylog.php, but the
 * package no longer reads that key in v5; this model is what
 * actually steers the writes to the renamed table.
 */
final class DevModeActivity extends SpatieActivity
{
    /** @var string|null */
    protected $table = 'dev_mode_audit';
}
