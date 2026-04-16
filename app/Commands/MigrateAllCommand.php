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
use function Laravel\Prompts\spin;

class MigrateAllCommand extends Command
{
    protected $signature = 'migrate-all
                            {--source-token= : API token for the source organization}
                            {--target-token= : API token for the target organization}
                            {--dry-run : Show migration plans without making any changes}
                            {--migrate-db : Migrate database contents for each app (requires mysqldump installed locally)}
                            {--skip-data=* : Skip data migration for specific schemas (e.g. --skip-data=nerd)}
                            {--ignore-table=* : Exclude tables from data migration, format: schema.table (e.g. --ignore-table=dojo.nerd_daily_report_urls)}
                            {--move-domains : Move custom domains from source to target after migration}
                            {--deploy : Trigger a deployment on all environments after migration}
                            {--yes : Skip confirmation prompts and proceed automatically}';

    protected $description = 'Migrate all applications from one Laravel Cloud organization to another';

    public function handle(): int
    {
        $this->newLine();
        info('Laravel Cloud Migrator — Batch Mode');
        $this->newLine();

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

        try {
            $applications = spin(fn () => $source->getAll('applications'), 'Fetching source applications...');
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

        if (empty($applications)) {
            error('No applications found in source organization.');

            return self::FAILURE;
        }

        $this->newLine();
        $this->line('<fg=cyan;options=bold>Applications to migrate:</>');
        foreach ($applications as $app) {
            $this->line("  · {$app['attributes']['name']} ({$app['attributes']['slug']})");
        }
        $this->newLine();

        // One shared MigrationService for all apps — clusters/caches are deduplicated.
        $service = new MigrationService($source, $target);
        $clusterRegistry = [];
        $cacheRegistry = [];
        $service->useSharedRegistries($clusterRegistry, $cacheRegistry);

        // Build all plans up front so we can show a full preview before touching anything.
        $plans = [];
        foreach ($applications as $app) {
            $appId = $app['id'];
            $appName = $app['attributes']['name'];

            $this->line("Fetching plan for <fg=yellow>{$appName}</>...");
            $plan = $service->buildPlan($appId, function (string $msg) {
                $this->line("  <fg=gray>↳ {$msg}</>");
            });
            $plans[$appId] = $plan;

            if (! empty($plan->warnings)) {
                foreach ($plan->warnings as $w) {
                    $this->line("  <fg=yellow>⚠</> {$w}");
                }
            }
        }

        $this->newLine();
        $this->line('<fg=cyan;options=bold>Shared resource detection:</>');
        $this->detectAndShowSharedResources($plans);
        $this->newLine();

        if ($this->option('dry-run')) {
            note('Dry run complete — no changes were made.');

            return self::SUCCESS;
        }

        if (! $this->option('yes') && ! confirm('Proceed with migrating all '.count($applications).' application(s)?', default: false)) {
            info('Migration cancelled.');

            return self::SUCCESS;
        }

        $this->newLine();
        info('Starting batch migration...');

        $succeeded = [];
        $skipped = [];
        $failed = [];

        foreach ($applications as $app) {
            $appId = $app['id'];
            $appName = $app['attributes']['name'];
            $plan = $plans[$appId];

            $this->newLine();
            $this->line("<fg=cyan;options=bold>── Migrating: {$appName} ──</>");

            $alreadyExists = spin(
                fn () => $service->applicationExistsInTarget($plan->application->slug, $plan->application->name),
                'Checking target organization...'
            );

            if ($alreadyExists) {
                $this->line('  <fg=yellow>⚠</> Already exists in target — skipping migration.');
                $skipped[] = $appName;

                if ($this->option('move-domains')) {
                    $this->newLine();
                    $this->line('  <fg=cyan>Moving domains...</>');
                    $this->moveDomainForExistingApp($target, $service, $plan);
                }

                continue;
            }

            try {
                $newSlug = $service->execute($plan, function (string $message) {
                    $this->line("  <fg=green>✓</> {$message}");
                });

                $succeeded[] = ['name' => $appName, 'slug' => $newSlug];
                $this->line("  <fg=green>✓</> Done → cloud.laravel.com search \"<fg=yellow>{$newSlug}</>\"");

                if ($this->option('move-domains')) {
                    $this->newLine();
                    $this->line('  <fg=cyan>Moving domains...</>');
                    $service->moveDomains($plan, function (string $message) {
                        $this->line("  <fg=green>✓</> {$message}");
                    });
                }

                if ($this->option('migrate-db')) {
                    $this->newLine();
                    $this->line("  <fg=cyan>Migrating database data for {$appName}...</>");
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
                        $this->line("  <fg=red>✗</> DB data migration failed: {$e->getMessage()}");
                        $this->line('  <fg=yellow>Import data manually to continue.</>');
                    }
                }

                // Trigger deployment per-app immediately after migration.
                if ($this->option('deploy')) {
                    $this->newLine();
                    $this->line('  <fg=cyan>Triggering deployment...</>');
                    $this->line('  <fg=yellow>Note:</> Will fail if the repository is not yet connected in the target org.');
                    $service->triggerDeployments(function (string $message) {
                        $this->line("  <fg=green>✓</> {$message}");
                    });
                }

            } catch (RuntimeException $e) {
                $this->newLine();
                $this->line("  <fg=red>✗</> Failed: {$e->getMessage()}");
                $failed[] = $appName;

                // Only roll back the app itself — cluster/cache may be shared with others.
                if ($createdAppId = $service->getLastCreatedAppId()) {
                    try {
                        $target->delete("applications/{$createdAppId}");
                        $this->line('  <fg=yellow>↩</> Rolled back incomplete app.');
                    } catch (RuntimeException) {
                        $this->line('  <fg=red>✗</> Could not roll back app — remove it manually from the target org.');
                    }
                }
            }
        }

        $this->newLine();
        $this->line(str_repeat('─', 60));
        $this->newLine();

        if (! empty($succeeded)) {
            info(count($succeeded).' app(s) migrated successfully:');
            foreach ($succeeded as $s) {
                $this->line("  <fg=green>✓</> {$s['name']}");
            }
            $this->newLine();
        }

        if (! empty($skipped)) {
            $this->line('<fg=yellow>'.count($skipped).' app(s) already in target (skipped):</>');
            foreach ($skipped as $s) {
                $this->line("  <fg=yellow>·</> {$s}");
            }
            $this->newLine();
        }

        if (! empty($failed)) {
            $this->line('<fg=red>'.count($failed).' app(s) failed:</>');
            foreach ($failed as $f) {
                $this->line("  <fg=red>✗</> {$f}");
            }
            $this->newLine();
        }

        if (! empty($clusterRegistry)) {
            $this->line('<fg=gray>'.count($clusterRegistry).' database cluster(s) used in target (shared across apps).</>');
        }
        if (! empty($cacheRegistry)) {
            $this->line('<fg=gray>'.count($cacheRegistry).' cache cluster(s) used in target (shared across apps).</>');
        }

        if (! $this->option('migrate-db') && ! empty($succeeded)) {
            $this->newLine();
            note('Database data was NOT migrated — re-run with --migrate-db to transfer data.');
        }

        return empty($failed) ? self::SUCCESS : self::FAILURE;
    }

    private function detectAndShowSharedResources(array $plans): void
    {
        $clusterUsage = [];
        $cacheUsage = [];
        $appsWithData = [];
        $appsWithDeployments = [];

        foreach ($plans as $plan) {
            $appName = $plan->application->name;

            foreach ($plan->databases as $envId => $dbInfo) {
                $clusterId = $dbInfo['cluster']['id'] ?? null;
                $clusterName = $dbInfo['cluster']['attributes']['name'] ?? $clusterId;
                if ($clusterId) {
                    $clusterUsage[$clusterId] = $clusterUsage[$clusterId] ?? ['name' => $clusterName, 'apps' => []];
                    $clusterUsage[$clusterId]['apps'][] = $appName;
                }

                $rowCount = $plan->dbRowCounts[$envId] ?? null;
                if ($rowCount !== null && $rowCount > 0) {
                    $appsWithData[] = $appName.' (~'.number_format($rowCount).' rows)';
                }
            }

            foreach ($plan->caches as $cacheData) {
                $cacheId = $cacheData['id'] ?? null;
                $cacheName = $cacheData['attributes']['name'] ?? $cacheId;
                if ($cacheId) {
                    $cacheUsage[$cacheId] = $cacheUsage[$cacheId] ?? ['name' => $cacheName, 'apps' => []];
                    $cacheUsage[$cacheId]['apps'][] = $appName;
                }
            }

            foreach ($plan->environments as $env) {
                if ($plan->hasDeployments[$env->id] ?? false) {
                    $appsWithDeployments[] = $appName;
                    break;
                }
            }
        }

        $hasShared = false;

        foreach ($clusterUsage as $info) {
            if (count($info['apps']) > 1) {
                $hasShared = true;
                $appList = implode(', ', $info['apps']);
                $this->line("  <fg=green>DB cluster</> \"{$info['name']}\" shared by: {$appList}");
                $this->line('    <fg=gray>→ will create 1 cluster in target with '.count($info['apps']).' schemas</>');
            }
        }

        foreach ($cacheUsage as $info) {
            if (count($info['apps']) > 1) {
                $hasShared = true;
                $appList = implode(', ', $info['apps']);
                $this->line("  <fg=green>Cache</> \"{$info['name']}\" shared by: {$appList}");
                $this->line('    <fg=gray>→ will create 1 cache in target, linked to all apps</>');
            }
        }

        if (! $hasShared) {
            $this->line('  <fg=gray>No shared resources detected — each app has its own cluster/cache.</>');
        }

        if (! empty($appsWithData)) {
            $this->newLine();
            $this->line('<fg=cyan;options=bold>Recommendations:</>');
            $this->line('  <fg=yellow>⚑</> Source databases contain data:');
            foreach ($appsWithData as $entry) {
                $this->line("    · {$entry}");
            }
            $this->line('  <fg=yellow>  Add <options=bold>--migrate-db</> to transfer data. Use <options=bold>--skip-data=schema</> or <options=bold>--ignore-table=schema.table</> to exclude.</>');

        }

        if (! empty($appsWithDeployments) && ! $this->option('deploy')) {
            $this->newLine();
            if (empty($appsWithData)) {
                $this->line('<fg=cyan;options=bold>Recommendations:</>');
            }
            $this->line('  <fg=yellow>⚑</> '.count($appsWithDeployments).' app(s) have prior deployments — add <options=bold>--deploy</> to trigger on target.</>');
        }
    }

    /**
     * Move domains for an app that already exists in the target.
     * Builds the source→target env ID map by matching env names, then calls moveDomains().
     */
    private function moveDomainForExistingApp(
        CloudApiClient $target,
        MigrationService $service,
        MigrationPlan $plan,
    ): void {
        // Find target app by name.
        $targetApps = $target->getAll('applications');
        $targetApp = null;
        foreach ($targetApps as $app) {
            if (($app['attributes']['name'] ?? '') === $plan->application->name) {
                $targetApp = $app;
                break;
            }
        }

        if (! $targetApp) {
            $this->line("  <fg=red>✗</> Could not find \"{$plan->application->name}\" in target org.");

            return;
        }

        $targetAppId = $targetApp['id'];
        $targetEnvs = $target->getAll("applications/{$targetAppId}/environments");

        $targetEnvsByName = [];
        foreach ($targetEnvs as $env) {
            $targetEnvsByName[$env['attributes']['name']] = $env['id'];
        }

        $envIdMap = [];
        foreach ($plan->environments as $env) {
            if (isset($targetEnvsByName[$env->name])) {
                $envIdMap[$env->id] = $targetEnvsByName[$env->name];
            }
        }

        $service->setLastCreatedAppId($targetAppId);
        $service->setEnvIdMap($envIdMap);

        $service->moveDomains($plan, function (string $message) {
            $this->line("  <fg=green>✓</> {$message}");
        });
    }
}
