<?php

namespace App\Commands;

use App\Data\MigrationPlan;
use App\Services\CloudApiClient;
use App\Services\MigrationService;
use LaravelZero\Framework\Commands\Command;
use RuntimeException;

use function Laravel\Prompts\confirm;
use function Laravel\Prompts\error;
use function Laravel\Prompts\info;
use function Laravel\Prompts\note;
use function Laravel\Prompts\password;
use function Laravel\Prompts\search;
use function Laravel\Prompts\spin;

class MigrateCommand extends Command
{
    protected $signature = 'app:migrate
                            {--source-token= : API token for the source organization}
                            {--target-token= : API token for the target organization}
                            {--app= : Slug or name of the application to migrate}
                            {--dry-run : Show the migration plan without making any changes}
                            {--move-domains : Move custom domains from source to target after migration}
                            {--migrate-db : Migrate database contents (requires mysqldump/pg_dump installed locally)}
                            {--skip-data=* : Skip data migration for specific schemas (e.g. --skip-data=nerd)}
                            {--ignore-table=* : Exclude tables from data migration, format: schema.table (e.g. --ignore-table=dojo.nerd_daily_report_urls)}
                            {--deploy : Trigger a deployment on all environments after migration}
                            {--yes : Skip confirmation prompt and proceed automatically}';

    protected $description = 'Migrate a Laravel Cloud application from one organization to another';

    public function handle(): int
    {
        $this->newLine();
        info('Laravel Cloud Migrator');
        $this->newLine();

        // Step 1: Auth
        $sourceToken = $this->option('source-token') ?: password(
            label: 'Source organization API token',
            placeholder: 'Paste your token here...',
            hint: 'Get this from cloud.laravel.com → Your Org → Settings → API Tokens',
            required: true,
        );

        $targetToken = $this->option('target-token') ?: password(
            label: 'Target organization API token',
            placeholder: 'Paste your token here...',
            hint: 'Get this from cloud.laravel.com → Your Org → Settings → API Tokens',
            required: true,
        );

        $source = new CloudApiClient($sourceToken);
        $target = new CloudApiClient($targetToken);

        // Validate both tokens — source validation doubles as the app fetch
        try {
            $applications = spin(fn () => $source->getAll('applications'), 'Validating source token...');
        } catch (RuntimeException $e) {
            error('Source token is invalid: '.$e->getMessage());

            return self::FAILURE;
        }

        try {
            spin(fn () => $target->get('applications'), 'Validating target token...');
        } catch (RuntimeException $e) {
            error('Target token is invalid: '.$e->getMessage());

            return self::FAILURE;
        }

        // Step 2: Select app

        if (empty($applications)) {
            error('No applications found in source organization.');

            return self::FAILURE;
        }

        $appMap = [];
        foreach ($applications as $app) {
            $appMap[$app['id']] = $app['attributes']['name'].' ('.$app['attributes']['slug'].')';
        }

        if ($appOption = $this->option('app')) {
            $selectedAppId = null;
            foreach ($applications as $app) {
                if ($app['attributes']['slug'] === $appOption || $app['attributes']['name'] === $appOption) {
                    $selectedAppId = $app['id'];
                    break;
                }
            }

            if (! $selectedAppId) {
                error("No application found matching \"{$appOption}\". Available: ".implode(', ', array_column(array_column($applications, 'attributes'), 'slug')));

                return self::FAILURE;
            }
        } else {
            $selectedAppId = search(
                label: 'Select application to migrate',
                options: fn (string $value) => strlen($value) > 0
                    ? array_filter($appMap, fn ($name) => str_contains(strtolower($name), strtolower($value)))
                    : $appMap,
                placeholder: 'Type to search...',
            );
        }

        // Step 3: Build migration plan
        $service = new MigrationService($source, $target);

        $this->newLine();
        $this->line('Fetching application details...');
        $plan = $service->buildPlan($selectedAppId, function (string $msg) {
            $this->line("  <fg=gray>↳ {$msg}</>");
        });

        // Step 4: Dry-run preview
        $this->displayPlan($plan, (bool) $this->option('move-domains'), (bool) $this->option('migrate-db'), (bool) $this->option('deploy'));

        if (! empty($plan->warnings)) {
            $this->newLine();
            $this->line('<fg=yellow;options=bold>⚠  Some data could not be fetched:</>');
            foreach ($plan->warnings as $w) {
                $this->line("  <fg=yellow>·</> {$w}");
            }
            $this->newLine();
        }

        if ($this->option('dry-run')) {
            $this->newLine();
            note('Dry run complete — no changes were made.');

            return self::SUCCESS;
        }

        $alreadyExists = spin(
            fn () => $service->applicationExistsInTarget($plan->application->slug, $plan->application->name),
            'Checking target organization...'
        );

        if ($alreadyExists) {
            $this->newLine();
            error("An application named \"{$plan->application->name}\" already exists in the target organization. Remove or rename it first, then retry.");

            return self::FAILURE;
        }

        if (! $this->option('yes') && ! confirm('Proceed with migration?', default: false)) {
            info('Migration cancelled.');

            return self::SUCCESS;
        }

        $moveDomains = (bool) $this->option('move-domains');

        if ($moveDomains && ! $this->option('yes')) {
            $domainCount = array_sum(array_map('count', $plan->domains));
            if ($domainCount > 0) {
                $this->newLine();
                $this->line("  <fg=yellow>⚠  --move-domains will delete {$domainCount} domain(s) from the source org.</>");
                $this->line('  If the process crashes mid-way, affected domains may require manual recovery.');
                if (! confirm('Proceed with domain cutover?', default: false)) {
                    $moveDomains = false;
                }
            }
        }

        // Step 5: Execute
        $this->newLine();
        info('Starting migration...');
        $this->newLine();

        try {
            $newAppSlug = $service->execute($plan, function (string $message) {
                $this->line("  <fg=green>✓</> {$message}");
            });
        } catch (RuntimeException $e) {
            $this->newLine();
            error('Migration failed: '.$e->getMessage());

            $this->line('<fg=yellow>Rolling back...</>');
            $rollbackClean = true;

            if ($createdClusterId = $service->getLastCreatedClusterId()) {
                try {
                    $schemas = $target->getAll("databases/clusters/{$createdClusterId}/databases");
                    foreach ($schemas as $s) {
                        $target->delete("databases/clusters/{$createdClusterId}/databases/{$s['id']}");
                    }
                    $target->delete("databases/clusters/{$createdClusterId}");
                } catch (RuntimeException) {
                    $rollbackClean = false;
                    $this->line('<fg=red>✗</> Could not delete database cluster — remove it manually in the target org dashboard.');
                }
            }

            if ($createdAppId = $service->getLastCreatedAppId()) {
                try {
                    $target->delete("applications/{$createdAppId}");
                } catch (RuntimeException) {
                    $rollbackClean = false;
                    $this->line('<fg=red>✗</> Could not delete app — remove it manually in the target org dashboard.');
                }
            }

            if ($rollbackClean) {
                $this->line('<fg=green>✓</> Target organization is clean.');
            }

            return self::FAILURE;
        }

        if ($moveDomains) {
            $this->newLine();
            $this->line('<fg=cyan;options=bold>Moving domains...</>');
            $service->moveDomains($plan, function (string $message) {
                $this->line("  <fg=green>✓</> {$message}");
            });
        }

        if ($this->option('migrate-db')) {
            $this->newLine();
            $this->line('<fg=cyan;options=bold>Migrating database data...</>');
            $this->line('  <fg=yellow>Note:</> Data routes through this machine — large databases may take a while.');
            try {
                $service->migrateDbData(
                    progress: function (string $message) {
                        $this->line("  <fg=green>✓</> {$message}");
                    },
                    skipSchemas: (array) $this->option('skip-data'),
                    ignoreTables: (array) $this->option('ignore-table'),
                );
            } catch (RuntimeException $e) {
                $this->newLine();
                error('Database data migration failed: '.$e->getMessage());
                $this->line('<fg=yellow>The app was migrated successfully — import your data manually to continue.</>');
            }
        }

        $triggerDeploy = (bool) $this->option('deploy');

        if ($triggerDeploy) {
            $this->newLine();
            $this->line('<fg=cyan;options=bold>Triggering deployments...</>');
            $this->line('  <fg=yellow>Note:</> Deployments will fail if the repository is not yet connected in the target org.');
            $service->triggerDeployments(function (string $message) {
                $this->line("  <fg=green>✓</> {$message}");
            });
        }

        $this->newLine();
        info('Migration completed successfully!');
        $this->line("  <fg=cyan>Find your app:</> cloud.laravel.com → search \"<fg=yellow>{$newAppSlug}</>\"");
        $this->newLine();
        $this->line('<fg=cyan;options=bold>Next steps:</>');
        $step = 1;
        if (! $triggerDeploy) {
            $this->line("  {$step}. <fg=yellow>Push your code</> — trigger a deploy from your Git provider or the Cloud dashboard.");
            $step++;
        }
        if (! $moveDomains) {
            $this->line("  {$step}. <fg=yellow>Add custom domains</> — cloud.laravel.com → App → Environments → Domains.");
            $step++;
            $this->line("  {$step}. <fg=yellow>Update DNS records</> — point your domain to the new environment hostname.");
            $step++;
        }
        $this->line("  {$step}. <fg=yellow>Verify secrets</> — confirm all sensitive env vars migrated correctly in the dashboard.");
        $step++;
        $this->line("  {$step}. <fg=yellow>Test your app</> — visit the auto-generated cloud URL before switching DNS.");
        $this->newLine();
        $caveats = [];
        if (! $this->option('migrate-db')) {
            $caveats[] = 'Database data was NOT migrated — handle separately.';
        }
        if (! $moveDomains) {
            $caveats[] = 'Domains and DNS records were NOT migrated — add them manually or re-run with --move-domains.';
        }
        if ($caveats) {
            note(implode("\n", $caveats));
        }

        return self::SUCCESS;
    }

    private function displayPlan(MigrationPlan $plan, bool $moveDomains = false, bool $migrateDb = false, bool $deploy = false): void
    {
        $app = $plan->application;

        $this->newLine();
        $this->line('<fg=cyan;options=bold>Migration Plan (Dry Run)</>');
        $this->line(str_repeat('─', 60));
        $this->newLine();

        $this->line("<fg=yellow>Application:</> {$app->name}");
        $this->line("<fg=yellow>Repository:</> {$app->repository}");
        $this->line("<fg=yellow>Region:</> {$app->region}");
        $this->newLine();

        foreach ($plan->environments as $env) {
            $this->line("<fg=cyan>Environment: {$env->name}</> (branch: {$env->branch})");

            if ($env->phpVersion) {
                $this->line("  PHP: {$env->phpVersion}");
            }
            if ($env->nodeVersion) {
                $this->line("  Node: {$env->nodeVersion}");
            }
            if ($env->usesOctane) {
                $this->line('  Octane: enabled');
            }
            if ($env->buildCommand) {
                $this->line("  Build: {$env->buildCommand}");
            }
            if ($env->deployCommand) {
                $this->line("  Deploy: {$env->deployCommand}");
            }

            // Variables
            $vars = $plan->variables[$env->id] ?? [];
            if ($vars) {
                $keys = array_map(fn ($v) => $v['key'], $vars);
                $count = count($keys);
                $secretCount = count(array_filter($vars, fn ($v) => ! empty($v['is_secret'])));
                $secretNote = $secretCount > 0 ? " <fg=yellow>({$secretCount} secret)</>" : '';
                $this->line("  <fg=green>Env vars:</> {$count} variable(s){$secretNote}: ".implode(', ', $keys));
            } else {
                $this->line('  <fg=gray>No environment variables</>');
            }

            // Database
            if (isset($plan->databases[$env->id])) {
                $db = $plan->databases[$env->id];
                $clusterName = $db['cluster']['attributes']['name'] ?? 'unknown';
                $schemaName = $db['schema']['attributes']['name'] ?? 'unknown';
                $dataNote = $migrateDb ? 'data will be migrated' : 'structure only, no data';
                $this->line("  <fg=green>Database:</> {$clusterName} / {$schemaName} ({$dataNote})");
            }

            // Cache
            if (isset($plan->caches[$env->id])) {
                $cacheAttrs = $plan->caches[$env->id]['attributes'];
                $cacheName = $cacheAttrs['name'] ?? 'unknown';
                $cacheSize = isset($cacheAttrs['size']) ? " ({$cacheAttrs['size']})" : '';
                $this->line("  <fg=green>Cache:</> {$cacheName}{$cacheSize}");
            }

            // Instances
            $instanceSets = $plan->instances[$env->id] ?? [];
            foreach ($instanceSets as $set) {
                $attrs = $set['instance']['attributes'];
                $size = $attrs['size'] ?? '?';
                $min = $attrs['min_replicas'] ?? 1;
                $max = $attrs['max_replicas'] ?? 1;
                $this->line("  <fg=green>Instance:</> {$attrs['name']} ({$attrs['type']}) — size: {$size}, replicas: {$min}–{$max}");
                foreach ($set['background_processes'] as $proc) {
                    $cmd = $proc['attributes']['command'] ?? $proc['attributes']['type'];
                    $this->line("    <fg=gray>⤷ process:</> {$cmd}");
                }
            }

            // Domains
            $domainItems = $plan->domains[$env->id] ?? [];
            foreach ($domainItems as $domain) {
                $domainName = $domain['attributes']['name'] ?? $domain['id'];
                if ($moveDomains) {
                    $this->line("  <fg=green>Domain:</> {$domainName} <fg=yellow>→ will be moved</>");
                } else {
                    $this->line("  <fg=green>Domain:</> {$domainName} <fg=gray>(use --move-domains to transfer)</>");
                }
            }

            // Recommendations
            $rowCount = $plan->dbRowCounts[$env->id] ?? null;
            if ($rowCount !== null && $rowCount > 0 && ! $migrateDb) {
                $this->line('  <fg=yellow>⚑ Source DB has ~'.number_format($rowCount).' rows — add <options=bold>--migrate-db</> to include data</>');
            }
            if (($plan->hasDeployments[$env->id] ?? false) && ! $deploy) {
                $this->line('  <fg=yellow>⚑ Source has been deployed — add <options=bold>--deploy</> to trigger on target</>');
            }

            $this->newLine();
        }

        // Org-level buckets
        if (! empty($plan->buckets)) {
            $this->line('<fg=cyan;options=bold>Object Storage (org-level)</>');
            foreach ($plan->buckets as $bucket) {
                $attrs = $bucket['attributes'];
                $name = $attrs['name'] ?? $bucket['id'];
                $type = $attrs['type'] ?? 'unknown';
                $vis = $attrs['visibility'] ?? 'private';
                $this->line("  <fg=yellow>⚠</> {$name} ({$type}, {$vis}) — <fg=red>cannot be auto-migrated</> (requires Cloudflare R2 credentials)");
            }
            $this->newLine();
        }

        if (! $migrateDb) {
            $this->line('<fg=gray>Database data will NOT be migrated — use --migrate-db to include it.</>');
        }
        if (! $moveDomains) {
            $this->line('<fg=gray>Domains and DNS records will NOT be migrated.</>');
        }
        $this->newLine();
    }
}
