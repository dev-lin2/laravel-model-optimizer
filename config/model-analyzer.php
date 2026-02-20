<?php

return [
    /*
    |--------------------------------------------------------------------------
    | Migration Directories
    |--------------------------------------------------------------------------
    | Directories where your Laravel migration files are located.
    | Set to an empty array to disable migration checking.
    */
    'migration_paths' => [
        database_path('migrations'),
    ],

    /*
    |--------------------------------------------------------------------------
    | Model Directories
    |--------------------------------------------------------------------------
    | Directories where your Eloquent models are located.
    */
    'model_paths' => [
        app_path('Models'),
        app_path(), // For Laravel < 8
    ],

    /*
    |--------------------------------------------------------------------------
    | Excluded Models
    |--------------------------------------------------------------------------
    | Fully-qualified class names to exclude from analysis.
    */
    'excluded_models' => [
        'Illuminate\Notifications\DatabaseNotification',
    ],

    /*
    |--------------------------------------------------------------------------
    | Excluded Tables
    |--------------------------------------------------------------------------
    | Tables to exclude from analysis.
    */
    'excluded_tables' => [
        'migrations',
        'failed_jobs',
        'password_resets',
        'personal_access_tokens',
    ],

    /*
    |--------------------------------------------------------------------------
    | Relationship Types
    |--------------------------------------------------------------------------
    | Eloquent relationship types to analyze.
    */
    'relationship_types' => [
        'hasOne',
        'hasMany',
        'belongsTo',
        'belongsToMany',
        'morphOne',
        'morphMany',
        'morphTo',
        'morphToMany',
        'morphedByMany',
    ],

    /*
    |--------------------------------------------------------------------------
    | Database Connection
    |--------------------------------------------------------------------------
    | Database connection to use for schema analysis.
    | null = use default connection.
    */
    'database_connection' => null,

    /*
    |--------------------------------------------------------------------------
    | Strict Mode
    |--------------------------------------------------------------------------
    | When enabled, warnings are treated as errors (non-zero exit code).
    */
    'strict_mode' => env('MODEL_ANALYZER_STRICT', false),

    /*
    |--------------------------------------------------------------------------
    | Health Score Weights
    |--------------------------------------------------------------------------
    | Point weights used when calculating the overall health score.
    */
    'health_weights' => [
        'has_inverse'      => 30,
        'no_circular'      => 30,
        'column_exists'    => 20,
        'has_index'        => 10,
        'has_foreign_key'  => 10,
    ],
];
