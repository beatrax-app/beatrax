<?php

declare(strict_types=1);

namespace Modules\DevMode\Internal\Audit;

use Spatie\Activitylog\Models\Activity as SpatieActivity;

// spatie/laravel-activitylog v5 dropped the `table_name` config option, so a
// custom table needs its own model, wired up via activitylog.activity_model.
final class DevModeActivity extends SpatieActivity
{
    /** @var string|null */
    protected $table = 'dev_mode_audit';
}
