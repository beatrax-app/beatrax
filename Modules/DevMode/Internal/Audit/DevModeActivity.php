<?php

declare(strict_types=1);

namespace Modules\DevMode\Internal\Audit;

use Spatie\Activitylog\Models\Activity as SpatieActivity;

// spatie/laravel-activitylog v5 removed the `table_name` config option,
// so a custom table needs its own Activity model with $table set.
// Registered via config('activitylog.activity_model') so every
// activity()/ActivityLogger call lands rows in the renamed table.
final class DevModeActivity extends SpatieActivity
{
    /** @var string|null */
    protected $table = 'dev_mode_audit';
}
