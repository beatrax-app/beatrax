<?php

declare(strict_types=1);

use Modules\DevMode\Internal\Audit\DevModeActivity;
use Spatie\Activitylog\Actions\CleanActivityLogAction;
use Spatie\Activitylog\Actions\LogActivityAction;

return [

    /*
     * If set to false, no activities will be saved to the database.
     */
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

    /*
     * You can specify an auth driver here that gets user models.
     * If this is null we'll use the current Laravel auth driver.
     */
    'default_auth_driver' => null,

    /*
     * If set to true, the subject relationship on activities
     * will include soft deleted models.
     */
    'include_soft_deleted_subjects' => false,

    // Overrides $table since v5 dropped the `table_name` option; this is
    // the only thing keeping writes in `dev_mode_audit`.
    'activity_model' => DevModeActivity::class,

    /*
     * These attributes will be excluded from logging for all models.
     * Model-specific exclusions via logExcept() are merged with these.
     */
    'default_except_attributes' => [],

    /*
     * When enabled, activities are buffered in memory and inserted in a
     * single bulk query after the response has been sent to the client.
     * This can significantly reduce the number of database queries when
     * many activities are logged during a single request.
     *
     * Only enable this if your application logs a high volume of
     * activities per request. Buffered activities will not have an ID
     * until the buffer is flushed.
     */
    'buffer' => [
        'enabled' => env('ACTIVITYLOG_BUFFER_ENABLED', false),
    ],

    /*
     * These action classes can be overridden to customize how
     * activities are logged and cleaned. Your custom classes must
     * extend the originals.
     */
    'actions' => [
        'log_activity' => LogActivityAction::class,
        'clean_log' => CleanActivityLogAction::class,
    ],
];
