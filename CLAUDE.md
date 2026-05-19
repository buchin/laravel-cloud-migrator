# CLAUDE.md

This file provides guidance to Claude Code (claude.ai/code) when working with code in this repository.

## Project Overview

Laravel Cloud Migrator is a **Laravel Zero CLI application** that migrates Laravel Cloud applications from one organization to another, including environments, environment variables, database clusters, caches, instances, and background processes.

**Not migrated automatically:** database data (use `--migrate-db`), object storage bucket contents, DNS records, `*.laravel.cloud` vanity domains (org-specific, cannot be transferred — new app gets a new vanity URL).

## Commands

```bash
composer install              # Install dependencies
./vendor/bin/pest             # Run all tests
./vendor/bin/pest tests/Unit/SomeTest.php  # Run a single test file
./vendor/bin/pint             # Lint / fix code style
./cloud-migrator app:migrate  # Run the single-app migration wizard
box compile                   # Build standalone PHAR (requires box installed globally)
```

## Architecture

Three layers:

**Commands** (`app/Commands/`) — User interaction only. Each command validates tokens, delegates to `MigrationService`, and renders output. Commands never call the API directly except for rollback cleanup in `MigrateCommand`.

**Services** (`app/Services/`):
- `CloudApiClient` — Guzzle-based HTTP client for `https://cloud.laravel.com/api`. Provides `get()`, `getAll()` (auto-paginates at 100 items/page), `post()`, `patch()`, `delete()`. API responses follow JSON:API format with `data`, `attributes`, `relationships`, `included`, `links` keys.
- `MigrationService` — Builds a `MigrationPlan` from the source org and executes it against the target org. Holds internal state (`lastCreatedAppId`, `lastCreatedClusterId`, `envIdMap`) used for rollback and post-migration steps. Shared cluster/cache deduplication works via `useSharedRegistries()` — pass `array &$clusterRegistry` and `array &$cacheRegistry` by reference so multiple app migrations reuse already-created target resources.

**Data** (`app/Data/`) — Immutable readonly DTOs: `MigrationPlan`, `ApplicationData`, `EnvironmentData`. All arrays in `MigrationPlan` are keyed by source environment ID.

### Commands and Their Roles

| Command | Class | Purpose |
|---------|-------|---------|
| `app:migrate` | `MigrateCommand` | Single-app interactive wizard with rollback |
| `app:migrate-all` | `MigrateAllCommand` | Batch migrate all apps; deduplicates shared clusters/caches |
| `db:migrate` | `MigrateDbCommand` | Data-only migration for already-migrated apps |
| `db:verify` | `VerifyDbCommand` | Per-table `COUNT(*)` comparison between orgs |
| `org:health` | `HealthCommand` | HTTP health check for all environments in an org |
| `org:status` | `StatusCommand` | Side-by-side comparison of source vs target (env vars, DB rows, deployments) |
| `vanity:transfer` | `TransferVanityCommand` | Transfer `*.laravel.cloud` vanity slug from source to target |
| `org:decommission` | `DecommissionCommand` | Delete source apps after confirming they exist in target |
| `app:list` | `ListCommand` | List all apps in an org |

### Migration Execution Order

Application → Environments (PATCH settings) → Env vars → Database clusters (wait for `available` status) → Cache clusters (wait for `available`) → Link DB/cache to environments → Instances (PATCH auto-created) → Background processes

### Database Migration Engine

Both MySQL and PostgreSQL are supported. The engine runs in two phases:
1. **Schema-only dump** (blocking) → import into target
2. **Per-table data dumps** (parallel, up to 4 concurrent workers) — avoids `max_execution_time` limits on large datasets

Data routes through the local machine via `mysqldump`/`mysql` or `pg_dump`/`psql`. Binaries are located via `which` then a hardcoded fallback list of Homebrew paths.

## Key Conventions

- This is a Laravel Zero app — bootstrap is in `bootstrap/app.php`, commands registered in `config/commands.php`.
- Use `laravel/prompts` for all interactive CLI prompts (`password`, `search`, `confirm`, `spin`, `info`, `error`, `note`).
- API errors throw `RuntimeException` — let them bubble up to Laravel Zero's error handler unless the command needs to recover (e.g., cluster already exists).
- Tests use Pest with Mockery. The `Feature/` suite uses `TestCase`; `Unit/` tests are plain Pest.
- `MigrationService` is stateful — reset state is implied by calling `execute()`. For batch migrations, instantiate one service and call `useSharedRegistries()` before iterating.
- API docs: https://cloud.laravel.com/docs/api/introduction
