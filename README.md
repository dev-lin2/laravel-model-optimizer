# Laravel Model Analyzer

A Laravel package that scans your Eloquent models and validates their relationships against your actual database schema. It detects missing inverse relationships, circular dependencies, missing foreign key columns, missing indexes, and more — then reports a health score for your model layer.

# Demo

You can check a quick demo [here](http://optimizer.linaung.dev/)

## Requirements

| Dependency | Version |
|---|---|
| PHP | `^8.1` |
| Laravel / Illuminate | `^9.0` – `^12.0` |
| Symfony Finder | `^6.0` or `^7.0` |

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

### `model-analyzer:visualize`

Generates a visual diagram of your model relationships as a standalone file.

```bash
php artisan model-analyzer:visualize
```

**Options:**

| Option | Description |
|---|---|
| `--output=path` | Output file path (default: `model-relationships.html` or `model-erd.html`) |
| `--models=User,Post` | Comma-separated list of models to include |
| `--erd` | Generate an Entity Relationship Diagram instead of a force-directed graph |
| `--format=html` | Output format: `html` (interactive, D3.js) or `svg` (static, embeddable) |
| `--source=database` | Schema source for ERDs: `database`, `migrations`, or `both` |
| `--issues=all` | Which notices to print: `all`, `errors`, `warnings`, `none` |
| `--hide-errors` | Never print error notices |
| `--hide-warnings` | Never print warning notices |

**Examples:**

```bash
# Interactive HTML graph (default)
php artisan model-analyzer:visualize

# ERD with table boxes, columns, and crow's foot cardinality
php artisan model-analyzer:visualize --erd

# Static SVG — embeddable in docs, READMEs, presentations
php artisan model-analyzer:visualize --format=svg

# SVG ERD for specific models
php artisan model-analyzer:visualize --erd --format=svg --models=User,Post

# Custom output path
php artisan model-analyzer:visualize --format=svg --output=docs/models.svg

# ERD built from migration files - no database connection needed
php artisan model-analyzer:visualize --erd --source=migrations

# ERD built from the live connection
php artisan model-analyzer:visualize --erd --source=database
```

**`--source` and the ERD.** Without `--source`, the ERD is model-driven and shows one box per
Eloquent model. With `--source`, it becomes *table-first*: every table in the chosen schema
appears, including pivots and tables with no model, and models decorate the tables they map to.
`--source` applies to `--erd` only; the force-directed graph is always model-driven.

**HTML format** produces a standalone file with D3.js — drag nodes, zoom, hover for details.

**SVG format** produces a pure `<svg>` file with no JavaScript — lightweight, scalable, and works anywhere images are supported.

---

### `model-analyzer:docs`

Generates a **data dictionary** — a reference describing the schema as it is: tables, columns,
types, nullability, keys, indexes, foreign keys, and the Eloquent relationships of any model
that maps to a table.

```bash
php artisan model-analyzer:docs
```

**Options:**

| Option | Description |
|---|---|
| `--source=database` | Schema source: `database`, `migrations`, or `both` |
| `--format=md` | Output format: `md` (Markdown) or `html` |
| `--output=path` | Output file path (default: `schema-docs-<source>.<ext>`) |
| `--models=User,Post` | Restrict model enrichment to these models |
| `--issues=all` | Which notices to print: `all`, `errors`, `warnings`, `none` |
| `--hide-errors` | Never print error notices |
| `--hide-warnings` | Never print warning notices |

**Examples:**

```bash
# Markdown data dictionary from the live database
php artisan model-analyzer:docs --source=database

# From migration files only - works with no database connection
php artisan model-analyzer:docs --source=migrations --output=docs/schema.md

# Styled HTML, light and dark aware
php artisan model-analyzer:docs --format=html --output=public/schema.html
```

---

### `model-analyzer:report`

Reports **findings** rather than describing the schema: columns that should have a foreign key
but don't, foreign key candidates with no supporting index, and drift between two sources.

```bash
php artisan model-analyzer:report
```

**Options:**

| Option | Description |
|---|---|
| `--source=database` | Schema source: `database`, `migrations`, or `both` |
| `--format=cli` | Output format: `cli`, `md`, `json`, or `html` |
| `--output=path` | Write to a file instead of stdout |
| `--issues=all` | Which notices to include: `all`, `errors`, `warnings`, `none` |
| `--hide-errors` | Never include error notices |
| `--hide-warnings` | Never include warning notices |
| `--fail-on-findings` | Exit non-zero when findings exist (for CI) |

**Examples:**

```bash
# Human-readable summary in the terminal
php artisan model-analyzer:report

# Compare migrations against the live database and show drift
php artisan model-analyzer:report --source=both

# Machine-readable output for tooling
php artisan model-analyzer:report --format=json --output=build/schema.json

# Gate a pipeline on findings (opt-in; exits 0 otherwise)
php artisan model-analyzer:report --source=both --fail-on-findings
```

**Missing foreign key detection.** A column is reported when it looks like a foreign key
(`*_id`) **and** a plausible target table actually exists **and** no constraint already covers
it. Requiring the target to exist keeps false positives low: `logs.external_id` with no
`externals` table is skipped. Polymorphic columns (a `*_id` paired with a `*_type`) are excluded
by design, since they cannot carry a single-table constraint. Each finding includes the
migration line that would fix it.

---

## Schema Sources

Every schema-aware command accepts `--source`:

| Value | Reads from | Needs a database? |
|---|---|---|
| `database` (default) | The live connection, via `information_schema` or `sqlite_master` | Yes |
| `migrations` | Static parsing of your migration files — no code is executed | No |
| `both` | Both of the above, and reports drift between them | Partially |

`migrations` makes the whole toolset usable in CI and on a fresh checkout where no database has
been provisioned.

## Degraded Output, Never A Crash

These commands are built not to break. A missing database connection, an absent migration
directory, an unparseable migration, or an unwritable output path produces **empty or "missing"
output plus a notice** — never an exception, and never a non-zero exit code unless you opt in
with `--fail-on-findings`.

Notice visibility is controlled per run:

```bash
# Everything (default)
php artisan model-analyzer:report --issues=all

# Errors only, or warnings only
php artisan model-analyzer:report --issues=errors
php artisan model-analyzer:report --issues=warnings

# Silence notices entirely
php artisan model-analyzer:report --issues=none

# Or suppress one class at a time
php artisan model-analyzer:report --hide-warnings
```

---

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
