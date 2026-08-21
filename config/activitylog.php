<?php

declare(strict_types=1);

use Modules\DevMode\Internal\Audit\DevModeActivity;
use Spatie\Activitylog\Actions\CleanActivityLogAction;
use Spatie\Activitylog\Actions\LogActivityAction;

return [

    'enabled' => env('ACTIVITYLOG_ENABLED', true),

    // Inert: v5 removed this option and the package no longer reads it.
    // `DevModeActivity::$table` is what actually binds the renamed table.
    'table_name' => 'dev_mode_audit',

    // Never reached: audit history is kept forever, so the clean command
    // is not scheduled.
    'clean_after_days' => 365,

    // One log_name for the whole Dev Console pipeline, so the audit view
    // can filter on it.
    'default_log_name' => 'dev_mode',

    'default_auth_driver' => null,

    'include_soft_deleted_subjects' => false,

    // Overrides $table since v5 dropped the `table_name` option; this is
    // the only thing keeping writes in `dev_mode_audit`.
    'activity_model' => DevModeActivity::class,

    'default_except_attributes' => [],

    'buffer' => [
        'enabled' => env('ACTIVITYLOG_BUFFER_ENABLED', false),
    ],

    'actions' => [
        'log_activity' => LogActivityAction::class,
        'clean_log' => CleanActivityLogAction::class,
    ],
];
