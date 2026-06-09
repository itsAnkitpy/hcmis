<?php

use App\Models\ActivityLog;
use Spatie\Activitylog\Actions\CleanActivityLogAction;
use Spatie\Activitylog\Actions\LogActivityAction;

return [

    /*
     * If set to false, no activities will be saved to the database.
     */
    'enabled' => env('ACTIVITYLOG_ENABLED', true),

    /*
     * Spatie's activitylog:clean command would delete rows older than this many
     * days. HCIMS does NOT use it: D-M7-3 keeps the audit log forever (the
     * >=12-month compliance floor is cleared trivially by never pruning at our
     * scale). The command is never scheduled, and UPDATE/DELETE are revoked on
     * activity_log at the DB (D-M7-5), so a manual run would error rather than
     * delete. This value is retained only to satisfy the package; it is unused.
     */
    'clean_after_days' => 365,

    /*
     * If no log name is passed to the activity() helper
     * we use this default log name.
     */
    'default_log_name' => 'default',

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

    /*
     * This model will be used to log activity.
     * It should implement the Spatie\Activitylog\Contracts\Activity interface
     * and extend Illuminate\Database\Eloquent\Model.
     *
     * HCIMS: our model folds the variant tenant scope + ownerless stamping onto
     * Spatie's Activity (D-M7-1).
     */
    'activity_model' => ActivityLog::class,

    /*
     * These attributes will be excluded from logging for all models.
     * Model-specific exclusions via logExcept() are merged with these.
     *
     * HCIMS: secrets are globally denied as defense-in-depth (D-M7-2). The
     * audited models already use logOnly() allowlists that never include these,
     * but this guarantees no future model can ever log them, even via logAll().
     */
    'default_except_attributes' => [
        'password',
        'remember_token',
        'app_authentication_secret',
        'app_authentication_recovery_codes',
    ],

    /*
     * When enabled, activities are buffered in memory and inserted in a
     * single bulk query after the response has been sent to the client.
     * This can significantly reduce the number of database queries when
     * many activities are logged during a single request.
     *
     * Only enable this if your application logs a high volume of activities
     * per request. Buffered activities will not have an ID until the
     * buffer is flushed.
     */
    'buffer' => [
        'enabled' => env('ACTIVITYLOG_BUFFER_ENABLED', false),
    ],

    /*
     * These action classes can be overridden to customize how activities
     * are logged and cleaned. Your custom classes must extend the originals.
     */
    'actions' => [
        'log_activity' => LogActivityAction::class,
        'clean_log' => CleanActivityLogAction::class,
    ],
];
