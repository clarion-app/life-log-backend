<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Raw Measurement Retention
    |--------------------------------------------------------------------------
    |
    | The number of days to retain raw measurements before they are eligible
    | for cleanup. Set to null to disable automatic retention (keep forever).
    |
    */
    'raw_retention_days' => env('LIFE_LOG_RAW_RETENTION_DAYS', 90),

    /*
    |--------------------------------------------------------------------------
    | Raw Session Retention
    |--------------------------------------------------------------------------
    |
    | The number of days to retain raw health sessions before they are eligible
    | for cleanup. Set to null to disable automatic retention (keep forever).
    | Only sessions that have already been promoted are ever pruned.
    |
    */
    'raw_session_retention_days' => env('LIFE_LOG_RAW_SESSION_RETENTION_DAYS', 90),

    /*
    |--------------------------------------------------------------------------
    | Rollup Batch Size
    |--------------------------------------------------------------------------
    |
    | The number of raw measurements to process in a single rollup batch.
    | Larger batches are more efficient but consume more memory.
    |
    */
    'rollup_batch_size' => (int) env('LIFE_LOG_ROLLUP_BATCH_SIZE', 500),

    /*
    |--------------------------------------------------------------------------
    | Maximum Measurement Value
    |--------------------------------------------------------------------------
    |
    | The maximum allowed value for a measurement. This should match the
    | capacity of the decimal(16,4) column used for the value field.
    |
    */
    'value_max' => env('LIFE_LOG_VALUE_MAX', '999999999999.9999'),

];
