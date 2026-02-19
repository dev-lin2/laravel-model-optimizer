# Laravel Model Analyzer

A Laravel package that scans your Eloquent models and validates their relationships against your actual database schema. It detects missing inverse relationships, circular dependencies, missing foreign key columns, missing indexes, and more — then reports a health score for your model layer.

## Requirements

| Dependency | Version |
|---|---|
| PHP | `^7.4` or `^8.0` |
| Laravel / Illuminate | `^8.0` |
| Symfony Finder | `^5.0` |

## Installation

Install via Composer:

```bash
composer require devlin/laravel-model-analyzer
```

Laravel's package auto-discovery registers the service provider automatically. If you have auto-discovery disabled, add the provider manually in `config/app.php`:

```php
'providers' => [
    Devlin\ModelAnalyzer\ModelAnalyzerServiceProvider::class,
],
```

### Publish the config file

```bash
php artisan vendor:publish --provider="Devlin\ModelAnalyzer\ModelAnalyzerServiceProvider"
```

This creates `config/model-analyzer.php`.

## Configuration

```php
// config/model-analyzer.php

return [
    // Directories where your Eloquent models live
    'model_paths' => [
        app_path('Models'),
        app_path(), // Laravel < 8
    ],

    // Fully-qualified class names to skip
    'excluded_models' => [
        'Illuminate\Notifications\DatabaseNotification',
    ],

    // Database tables to skip
    'excluded_tables' => [
        'migrations',
        'failed_jobs',
        'password_resets',
        'personal_access_tokens',
    ],

    // Database connection to use (null = default connection)
    'database_connection' => null,

    // When true, warnings are treated as errors (non-zero exit code)
    'strict_mode' => env('MODEL_ANALYZER_STRICT', false),

    // Point weights used to calculate the 0–100 health score
    'health_weights' => [
        'has_inverse'     => 30,
        'no_circular'     => 30,
        'column_exists'   => 20,
        'has_index'       => 10,
        'has_foreign_key' => 10,
    ],
];
```

## Commands

### `model-analyzer:analyze`

Runs a full analysis of all discovered models and prints a report.

```bash
php artisan model-analyzer:analyze
```

**Options:**

| Option | Description |
|---|---|
| `--format=cli` | Output format: `cli` (default) or `json` |
| `--strict` | Exit with code `1` if any warnings are found |
| `--models=User,Post` | Analyze only the specified models (comma-separated) |

**Examples:**

```bash
# Default CLI report
php artisan model-analyzer:analyze

# JSON output (pipe-friendly, useful in CI)
php artisan model-analyzer:analyze --format=json

# Fail CI if any warnings exist
php artisan model-analyzer:analyze --strict

# Analyze a single model
php artisan model-analyzer:analyze --models=User
```

**Exit codes:**
- `0` — no errors (warnings are allowed unless `--strict` is used)
- `1` — errors found, or warnings found with `--strict`

---

### `model-analyzer:health`

Displays a summary health score and grouped recommendation report.

```bash
php artisan model-analyzer:health
```

**Exit codes:**
- `0` — no errors
- `1` — one or more errors detected

---

### `model-analyzer:list-models`

Lists all Eloquent models discovered in the configured paths.

```bash
php artisan model-analyzer:list-models
```

**Options:**

| Option | Description |
|---|---|
| `--with-relationships` | Show relationship count per model |
| `--json` | Output as a JSON array of fully-qualified class names |

**Examples:**

```bash
php artisan model-analyzer:list-models
php artisan model-analyzer:list-models --with-relationships
php artisan model-analyzer:list-models --json
```

## What It Detects

| Issue | Severity | Description |
|---|---|---|
| Missing inverse relationship | Warning | e.g. `User hasMany Post` exists but `Post belongsTo User` is missing |
| Circular dependency | Error | Two models reference each other in a way that creates a loop |
| Missing foreign key column | Error | A relationship references a column that does not exist in the database |
| Missing index on foreign key | Warning | A foreign key column has no index, which can hurt query performance |

## Health Score

The `model-analyzer:analyze` and `model-analyzer:health` commands calculate a **0–100 health score** based on the number and severity of issues found relative to the total number of relationships. A score of 100 means no issues were detected.

## CI Integration

Run the analyzer in strict mode to fail your pipeline when any issue is found:

```yaml
# GitHub Actions example
- name: Analyze models
  run: php artisan model-analyzer:analyze --strict
```

Or allow warnings but fail only on errors (the default):

```yaml
- name: Analyze models
  run: php artisan model-analyzer:analyze
```

## Running Tests

```bash
composer test
```

## License

MIT
