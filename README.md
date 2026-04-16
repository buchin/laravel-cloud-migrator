# Laravel Cloud Migrator

A CLI tool for migrating Laravel Cloud applications from one organization to another — including environments, environment variables, database clusters, caches, instances, background processes, and custom domains.

## Requirements

- PHP 8.2+
- Composer
- `mysqldump` and `mysql` (only required for `--migrate-db`)
- API tokens for both source and target Laravel Cloud organizations

## Installation

### Composer (recommended)

```bash
composer global require buchin/laravel-cloud-migrator
```

Make sure your Composer global bin directory is in your `PATH` (usually `~/.composer/vendor/bin` or `~/.config/composer/vendor/bin`):

```bash
export PATH="$PATH:$HOME/.composer/vendor/bin"
```

Then run from anywhere:

```bash
cloud-migrator migrate
```

### From source

```bash
git clone https://github.com/buchin/laravel-cloud-migrator
cd laravel-cloud-migrator
composer install
./cloud-migrator migrate
```

## Getting API Tokens

In Laravel Cloud, go to **Your Org → Settings → API Tokens** and create a token for both the source and target organizations.

---

## Commands

### `migrate`

Migrate a single application interactively.

```bash
./cloud-migrator migrate
./cloud-migrator migrate --app=myapp --source-token=xxx --target-token=yyy
./cloud-migrator migrate --app=myapp --source-token=xxx --target-token=yyy --dry-run
./cloud-migrator migrate --app=myapp --source-token=xxx --target-token=yyy --yes --migrate-db --move-domains --deploy
```

**Options:**

| Flag | Description |
|------|-------------|
| `--app` | App name or slug to migrate |
| `--source-token` | API token for the source org |
| `--target-token` | API token for the target org |
| `--dry-run` | Show the migration plan without making any changes |
| `--migrate-db` | Transfer database contents (routes through your machine) |
| `--skip-data=schema` | Skip data migration for a specific schema |
| `--ignore-table=schema.table` | Exclude a table from data migration |
| `--move-domains` | Move custom domains from source to target after migration |
| `--deploy` | Trigger a deployment on all environments after migration |
| `--yes` | Skip confirmation prompts |

**What gets migrated:** application, environments, environment variables, database clusters and schemas, cache clusters, instances, background processes, custom domains.

**What does NOT get migrated automatically:** database data (use `--migrate-db`), object storage bucket contents.

---

### `migrate-all`

Migrate all applications in a source organization at once. Shared database clusters and caches are deduplicated — if multiple apps use the same cluster, only one is created in the target and all apps are linked to it.

```bash
./cloud-migrator migrate-all --source-token=xxx --target-token=yyy
./cloud-migrator migrate-all --source-token=xxx --target-token=yyy --dry-run
./cloud-migrator migrate-all --source-token=xxx --target-token=yyy --yes --migrate-db --move-domains --deploy
```

Accepts the same flags as `migrate`. Apps already present in the target are skipped for migration but `--move-domains` still runs for them.

---

### `migrate-db`

Migrate database data for apps that were already migrated structurally. Useful when you ran `migrate-all` without `--migrate-db` and want to transfer data separately, or need to re-run data migration for specific schemas.

```bash
./cloud-migrator migrate-db --source-token=xxx --target-token=yyy
./cloud-migrator migrate-db --source-token=xxx --target-token=yyy --skip-data=logs
./cloud-migrator migrate-db --source-token=xxx --target-token=yyy --ignore-table=app.reports
```

Uses a parallel per-table dump engine (up to 4 concurrent workers) to avoid query timeouts on large databases. Data routes through your local machine.

| Flag | Description |
|------|-------------|
| `--skip-data=schema` | Skip an entire schema |
| `--ignore-table=schema.table` | Exclude a specific table |
| `--yes` | Skip confirmation prompts |

---

### `verify-db`

Verify migrated database contents with exact `COUNT(*)` per table. Flags missing tables, row count mismatches, and tables only present on one side. Transient tables (`jobs`, `cache`, `cache_locks`, `sessions`, `job_batches`) are marked as expected and not flagged as errors.

```bash
./cloud-migrator verify-db --source-token=xxx --target-token=yyy
./cloud-migrator verify-db --source-token=xxx --target-token=yyy --schema=myapp
./cloud-migrator verify-db --source-token=xxx --target-token=yyy --skip-schema=logs
```

---

### `health`

Check HTTP health of all environments in an organization, following redirects and reporting response time.

```bash
./cloud-migrator health --target-token=yyy
./cloud-migrator health --target-token=yyy --timeout=30
./cloud-migrator health --target-token=yyy --expect-2xx   # exits non-zero if any env is not 2xx (CI use)
```

| Icon | Meaning |
|------|---------|
| ✓ green | 2xx |
| ↪ cyan | 3xx redirect |
| 🔒 yellow | 401 / 403 |
| ⚠ yellow | other 4xx |
| ✗ red | 5xx or unreachable |

---

### `status`

Compare source and target organizations side by side to verify migration progress.

```bash
./cloud-migrator status --source-token=xxx --target-token=yyy
```

---

### `transfer-vanity`

Transfer a Laravel Cloud vanity domain (e.g. `myapp.laravel.cloud`) from the source app to the matching target app by renaming the app slug.

```bash
./cloud-migrator transfer-vanity --app=myapp --source-token=xxx --target-token=yyy
```

If the source app is being decommissioned anyway, use `--delete-source` to delete it first, which releases the slug immediately. There will be a brief window (~5s) while the slug transfers to the target.

```bash
./cloud-migrator transfer-vanity --app=myapp --source-token=xxx --target-token=yyy --delete-source --yes
```

> **Note:** Laravel Cloud holds slugs for an extended period after a rename or deletion. If claiming the slug fails, the command retries automatically for up to 5 minutes. If it still fails, re-run the command later — the "already renamed" state is detected and the rename step is skipped.

---

### `decommission`

Delete source org applications after verifying they exist in the target. Only apps confirmed present in the target by name are deleted — unmatched apps are never touched.

```bash
./cloud-migrator decommission --source-token=xxx --target-token=yyy
./cloud-migrator decommission --source-token=xxx --target-token=yyy --delete-clusters --delete-caches --yes
```

| Flag | Description |
|------|-------------|
| `--delete-clusters` | Also delete source database clusters |
| `--delete-caches` | Also delete source cache clusters |
| `--yes` | Skip confirmation prompts |

---

### `list-apps`

List all applications in a Laravel Cloud organization.

```bash
./cloud-migrator list-apps --token=xxx
```

---

## Typical Migration Workflow

```bash
# 1. Preview what will be migrated (no changes made)
./cloud-migrator migrate-all --source-token=xxx --target-token=yyy --dry-run

# 2. Migrate structure
./cloud-migrator migrate-all --source-token=xxx --target-token=yyy --yes

# 3. Transfer database data
./cloud-migrator migrate-db --source-token=xxx --target-token=yyy --yes

# 4. Verify row counts match
./cloud-migrator verify-db --source-token=xxx --target-token=yyy

# 5. Move custom domains (zero-downtime — same IP as source)
./cloud-migrator migrate-all --source-token=xxx --target-token=yyy --move-domains --yes

# 6. Health check the target
./cloud-migrator health --target-token=yyy

# 7. Decommission the source
./cloud-migrator decommission --source-token=xxx --target-token=yyy --yes

# 8. Transfer Laravel Cloud vanity domains (optional)
./cloud-migrator transfer-vanity --app=myapp --source-token=xxx --target-token=yyy
```

---

## Notes

**Zero-downtime domain moves.** All Laravel Cloud apps share the same IP address. Moving a custom domain only updates Laravel Cloud's internal routing — no DNS change is needed and there is no downtime.

**Database data routes through your machine.** `--migrate-db` runs `mysqldump` locally and imports into the target. Large databases will take time proportional to their size.

**Idempotent.** Most commands can be re-run safely. Already-migrated apps are skipped, already-moved domains are detected and skipped, and partial failures can be resumed.

**Rollback on failure.** If a migration fails mid-way, the incomplete target app is automatically deleted to leave the target org clean.

## License

MIT
