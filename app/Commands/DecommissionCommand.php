<?php

namespace App\Commands;

use App\Services\CloudApiClient;
use LaravelZero\Framework\Commands\Command;
use RuntimeException;

use function Laravel\Prompts\confirm;
use function Laravel\Prompts\error;
use function Laravel\Prompts\info;
use function Laravel\Prompts\password;
use function Laravel\Prompts\spin;

class DecommissionCommand extends Command
{
    protected $signature = 'org:decommission
                            {--source-token= : API token for the source organization (to be cleaned up)}
                            {--target-token= : API token for the target organization (to verify migration)}
                            {--delete-clusters : Also delete source database clusters after apps are removed}
                            {--delete-caches : Also delete source cache clusters after apps are removed}
                            {--yes : Skip confirmation prompts}';

    protected $description = 'Delete source org apps (and optionally clusters/caches) after verifying migration to target';

    public function handle(): int
    {
        $this->newLine();
        info('Laravel Cloud Migrator — Decommission Source Org');
        $this->newLine();

        $sourceToken = $this->option('source-token') ?: password(
            label: 'Source organization API token (to decommission)',
            placeholder: 'Paste your token here...',
            required: true,
        );

        $targetToken = $this->option('target-token') ?: password(
            label: 'Target organization API token (to verify against)',
            placeholder: 'Paste your token here...',
            required: true,
        );

        $source = new CloudApiClient($sourceToken);
        $target = new CloudApiClient($targetToken);

        try {
            $sourceApps = spin(fn () => $source->getAll('applications'), 'Fetching source applications...');
        } catch (RuntimeException $e) {
            error('Source token invalid: '.$e->getMessage());

            return self::FAILURE;
        }

        try {
            $targetApps = spin(fn () => $target->getAll('applications'), 'Fetching target applications...');
        } catch (RuntimeException $e) {
            error('Target token invalid: '.$e->getMessage());

            return self::FAILURE;
        }

        if (empty($sourceApps)) {
            info('No applications in source organization — nothing to decommission.');

            return self::SUCCESS;
        }

        // Index target apps by name
        $targetByName = [];
        foreach ($targetApps as $app) {
            $targetByName[$app['attributes']['name']] = $app;
        }

        $safeToDelete = [];
        $notMigrated = [];

        foreach ($sourceApps as $app) {
            $name = $app['attributes']['name'];
            if (isset($targetByName[$name])) {
                $safeToDelete[] = $app;
            } else {
                $notMigrated[] = $name;
            }
        }

        // Show plan
        $this->newLine();
        $this->line('<fg=cyan;options=bold>Decommission Plan</>');
        $this->line(str_repeat('─', 60));
        $this->newLine();

        if (! empty($safeToDelete)) {
            $this->line('<fg=green>Apps confirmed in target — safe to delete from source:</>');
            foreach ($safeToDelete as $app) {
                $tgt = $targetByName[$app['attributes']['name']];
                $this->line("  <fg=red>✗</> {$app['attributes']['name']} <fg=gray>(source slug: {$app['attributes']['slug']} → target: {$tgt['attributes']['slug']})</>");
            }
        }

        if (! empty($notMigrated)) {
            $this->newLine();
            $this->line('<fg=yellow>Not found in target — will NOT be deleted:</>');
            foreach ($notMigrated as $name) {
                $this->line("  <fg=yellow>·</> {$name}");
            }
        }

        if ($this->option('delete-clusters')) {
            $this->newLine();
            $this->line('<fg=yellow>⚠  --delete-clusters: source database clusters will also be deleted.</>');
        }
        if ($this->option('delete-caches')) {
            $this->newLine();
            $this->line('<fg=yellow>⚠  --delete-caches: source cache clusters will also be deleted.</>');
        }

        if (empty($safeToDelete) && ! $this->option('delete-clusters') && ! $this->option('delete-caches')) {
            $this->newLine();
            info('Nothing to decommission.');

            return self::SUCCESS;
        }

        $this->newLine();
        $this->line('<fg=red;options=bold>This is irreversible. Deleted apps cannot be recovered.</>');
        $this->newLine();

        if (! $this->option('yes') && ! confirm('Proceed with decommissioning?', default: false)) {
            info('Cancelled.');

            return self::SUCCESS;
        }

        $this->newLine();
        $anyFailed = false;

        // Delete apps
        foreach ($safeToDelete as $app) {
            $name = $app['attributes']['name'];
            try {
                $source->delete("applications/{$app['id']}");
                $this->line("  <fg=green>✓</> Deleted app: {$name}");
            } catch (RuntimeException $e) {
                $this->line("  <fg=red>✗</> Could not delete {$name}: {$e->getMessage()}");
                $anyFailed = true;
            }
        }

        // Delete clusters
        if ($this->option('delete-clusters')) {
            $this->newLine();
            try {
                $clusters = $source->getAll('databases/clusters');
                foreach ($clusters as $cluster) {
                    $clusterName = $cluster['attributes']['name'] ?? $cluster['id'];
                    try {
                        // Delete all schemas first
                        $schemas = $source->getAll("databases/clusters/{$cluster['id']}/databases");
                        foreach ($schemas as $schema) {
                            $source->delete("databases/clusters/{$cluster['id']}/databases/{$schema['id']}");
                        }
                        $source->delete("databases/clusters/{$cluster['id']}");
                        $this->line("  <fg=green>✓</> Deleted cluster: {$clusterName}");
                    } catch (RuntimeException $e) {
                        $this->line("  <fg=red>✗</> Could not delete cluster {$clusterName}: {$e->getMessage()}");
                        $anyFailed = true;
                    }
                }
            } catch (RuntimeException $e) {
                $this->line("  <fg=red>✗</> Could not fetch clusters: {$e->getMessage()}");
                $anyFailed = true;
            }
        }

        // Delete caches
        if ($this->option('delete-caches')) {
            $this->newLine();
            try {
                $caches = $source->getAll('caches');
                foreach ($caches as $cache) {
                    $cacheName = $cache['attributes']['name'] ?? $cache['id'];
                    try {
                        $source->delete("caches/{$cache['id']}");
                        $this->line("  <fg=green>✓</> Deleted cache: {$cacheName}");
                    } catch (RuntimeException $e) {
                        $this->line("  <fg=red>✗</> Could not delete cache {$cacheName}: {$e->getMessage()}");
                        $anyFailed = true;
                    }
                }
            } catch (RuntimeException $e) {
                $this->line("  <fg=red>✗</> Could not fetch caches: {$e->getMessage()}");
                $anyFailed = true;
            }
        }

        $this->newLine();
        $this->line(str_repeat('─', 60));
        $this->newLine();

        if ($anyFailed) {
            $this->line('<fg=yellow>⚠  Some resources could not be deleted — review above.</>');
        } else {
            $this->line('<fg=green;options=bold>✓ Source organization decommissioned.</>');
        }

        $this->newLine();

        return $anyFailed ? self::FAILURE : self::SUCCESS;
    }
}
