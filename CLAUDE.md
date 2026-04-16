# CLAUDE.md

This file provides guidance to Claude Code (claude.ai/code) when working with code in this repository.

## Project Overview

Laravel Cloud Migrator is a **Laravel Zero CLI application** that migrates Laravel Cloud applications from one organization to another, including environments, environment variables, database clusters, caches, instances, and background processes.

**Not migrated:** database data, domains, DNS records, object storage bucket contents.

## Commands

```bash
composer install              # Install dependencies
./vendor/bin/pest             # Run all tests
./vendor/bin/pest tests/Unit/SomeTest.php  # Run a single test file
./vendor/bin/pint             # Lint / fix code style
./cloud-migrator migrate      # Run the migration wizard
box compile                   # Build standalone PHAR (requires box installed globally)
```

## Architecture

Three layers:

**Commands** (`app/Commands/`) — User interaction only. `MigrateCommand` orchestrates the full flow: prompt for tokens → select source app → build plan → show dry-run preview → confirm → execute.

**Services** (`app/Services/`):
- `CloudApiClient` — Guzzle-based HTTP client for `https://cloud.laravel.com/api`. Provides `get()`, `getAll()` (handles pagination automatically, 100 items/page), `post()`, `patch()`, `delete()` with bearer token auth. API responses follow JSON:API format with `data`, `attributes`, `relationships`, `included`, `links` keys.
- `MigrationService` — Builds a `MigrationPlan` from the source org and executes it against the target org.

**Data** (`app/Data/`) — Immutable DTOs: `MigrationPlan`, `ApplicationData`, `EnvironmentData`. Carry structured data between services and are displayed to the user before confirmation.

### Migration Execution Order

Application → Environments → Env vars → Database clusters → Caches → Link DB/cache to environments → Instances → Background processes

## Key Conventions

- This is a Laravel Zero app — bootstrap is in `bootstrap/app.php`, commands registered in `config/commands.php`.
- Use `laravel/prompts` for all interactive CLI prompts.
- API errors throw `RuntimeException` — let them bubble up to Laravel Zero's error handler.
- Tests use Pest with Mockery; see `tests/Pest.php` for global helpers.
- API docs: https://cloud.laravel.com/docs/api/introduction
