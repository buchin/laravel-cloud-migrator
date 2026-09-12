<?php

namespace App\Commands;

use App\Services\CloudApiClient;
use LaravelZero\Framework\Commands\Command;
use RuntimeException;

use function Laravel\Prompts\confirm;
use function Laravel\Prompts\error;
use function Laravel\Prompts\info;
use function Laravel\Prompts\note;
use function Laravel\Prompts\password;
use function Laravel\Prompts\spin;
use function Laravel\Prompts\text;

class DecommissionCommand extends Command
{
    protected $signature = 'org:teardown
                            {--source-token= : API token for the source organization (to be cleaned up)}
                            {--target-token= : API token for the target organization (to verify migration)}
                            {--delete-clusters : Also delete source database clusters after apps are removed}
                            {--delete-caches : Also delete source cache clusters after apps are removed}
                            {--all : Delete apps, database clusters, and cache clusters in source org}
                            {--confirm= : Explicit typed confirmation phrase (must be "TEARDOWN-SOURCE")}
                            {--skip-health-check : Skip pre-teardown target HTTP health check verification}
                            {--dry-run : Show the decommission plan without making any changes}
                            {--yes : Skip confirmation prompts (requires valid --confirm)}';

    protected $aliases = ['org:decommission'];

    protected $description = 'Decommission source organization resources (apps, clusters, caches) with strict double confirmation and safety guards';

    public const CONFIRMATION_PHRASE = 'TEARDOWN-SOURCE';

    public function handle(): int
    {
        $this->newLine();
        info('Laravel Cloud Migrator — Production Source Org Teardown');
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

        // Safety Guard 1: Source and target tokens must not be identical
        if (trim($sourceToken) === trim($targetToken)) {
            error('Safety Violation: Source and target tokens must be distinct. Refusing to operate on the same organization.');

            return self::FAILURE;
        }

        $source = $this->makeClient($sourceToken);
        $target = $this->makeClient($targetToken);

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

        // Safety Guard 2: Target organization must have running applications
        if (empty($targetApps)) {
            error('Safety Violation: Target organization has no applications. Refusing to decommission source without verified target.');

            return self::FAILURE;
        }

        // Collect target IDs to prevent any accidental cross-deletion
        $targetAppIds = array_column($targetApps, 'id');
        $targetClusterIds = [];
        try {
            $targetClusters = $target->getAll('databases/clusters');
            $targetClusterIds = array_column($targetClusters, 'id');
        } catch (RuntimeException) {
        }

        $targetCacheIds = [];
        try {
            $targetCaches = $target->getAll('caches');
            $targetCacheIds = array_column($targetCaches, 'id');
        } catch (RuntimeException) {
        }

        $deleteClusters = (bool) ($this->option('delete-clusters') || $this->option('all'));
        $deleteCaches = (bool) ($this->option('delete-caches') || $this->option('all'));

        if (empty($sourceApps) && ! $deleteClusters && ! $deleteCaches) {
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
                // Safety Guard 3: Source app ID must never match target app ID
                if (in_array($app['id'], $targetAppIds, true)) {
                    error("Safety Violation: App {$name} ID {$app['id']} detected in target org! Aborting.");

                    return self::FAILURE;
                }
                $safeToDelete[] = $app;
            } else {
                $notMigrated[] = $name;
            }
        }

        // Fetch source clusters and caches if deletion requested
        $sourceClusters = [];
        if ($deleteClusters) {
            try {
                $sourceClusters = $source->getAll('databases/clusters');
            } catch (RuntimeException $e) {
                error('Could not fetch source database clusters: '.$e->getMessage());

                return self::FAILURE;
            }
        }

        $sourceCaches = [];
        if ($deleteCaches) {
            try {
                $sourceCaches = $source->getAll('caches');
            } catch (RuntimeException $e) {
                error('Could not fetch source caches: '.$e->getMessage());

                return self::FAILURE;
            }
        }

        // Show plan
        $this->newLine();
        $this->line('<fg=cyan;options=bold>Production Source Teardown Plan</>');
        $this->line(str_repeat('─', 70));
        $this->newLine();

        if (! empty($safeToDelete)) {
            $this->line('<fg=green;options=bold>Applications verified in target — scheduled for deletion from source:</>');
            foreach ($safeToDelete as $app) {
                $tgt = $targetByName[$app['attributes']['name']];
                $this->line("  <fg=red>✗</> {$app['attributes']['name']} <fg=gray>(source id: {$app['id']} → target id: {$tgt['id']})</>");
            }
        }

        if (! empty($notMigrated)) {
            $this->newLine();
            $this->line('<fg=yellow;options=bold>Applications NOT found in target — PROTECTED (will NOT be deleted):</>');
            foreach ($notMigrated as $name) {
                $this->line("  <fg=yellow>·</> {$name}");
            }
        }

        if ($deleteClusters) {
            $this->newLine();
            $this->line('<fg=yellow;options=bold>Database Clusters scheduled for deletion from source:</>');
            if (empty($sourceClusters)) {
                $this->line('  <fg=gray>None found.</>');
            } else {
                foreach ($sourceClusters as $cluster) {
                    $cName = $cluster['attributes']['name'] ?? $cluster['id'];
                    $this->line("  <fg=red>✗</> Cluster: {$cName} <fg=gray>(id: {$cluster['id']})</>");
                }
            }
        }

        if ($deleteCaches) {
            $this->newLine();
            $this->line('<fg=yellow;options=bold>Cache Clusters scheduled for deletion from source:</>');
            if (empty($sourceCaches)) {
                $this->line('  <fg=gray>None found.</>');
            } else {
                foreach ($sourceCaches as $cache) {
                    $cName = $cache['attributes']['name'] ?? $cache['id'];
                    $this->line("  <fg=red>✗</> Cache: {$cName} <fg=gray>(id: {$cache['id']})</>");
                }
            }
        }

        if (empty($safeToDelete) && empty($sourceClusters) && empty($sourceCaches)) {
            $this->newLine();
            info('No source resources eligible for decommission.');

            return self::SUCCESS;
        }

        if ($this->option('dry-run')) {
            $this->newLine();
            note('Dry run complete — no changes were made to source or target organizations.');

            return self::SUCCESS;
        }

        // Safety Guard 4: Pre-Teardown Target Health Check Gate
        if (! $this->option('skip-health-check')) {
            $this->newLine();
            $this->line('<fg=cyan>Running Pre-Teardown Target Health Check Gate...</>');
            $unhealthy = $this->verifyTargetHealth($target, $targetApps);
            if (! empty($unhealthy)) {
                error('Safety Gate Failed: Target environments are not healthy: '.implode(', ', $unhealthy));
                $this->line('<fg=yellow>Teardown aborted to protect production continuity.</>');

                return self::FAILURE;
            }
            $this->line('  <fg=green>✓</> Target health check passed — all target environments are responsive.');
        }

        // Safety Guard 5: Double Confirmation Protection
        $this->newLine();
        $this->line('<fg=red;options=bold>⚠  DANGER: This action is permanent and IRREVERSIBLE.</>');
        $this->line('<fg=red>Source applications, database clusters, and caches will be permanently destroyed.</>');
        $this->newLine();

        $cliConfirm = $this->option('confirm');
        $expectedPhrase = self::CONFIRMATION_PHRASE;

        if ($this->option('yes')) {
            if (! $cliConfirm || strtoupper(trim($cliConfirm)) !== $expectedPhrase) {
                error("Safety Violation: Non-interactive execution (--yes) requires explicit typing flag --confirm=\"{$expectedPhrase}\".");

                return self::FAILURE;
            }
        } else {
            // Confirmation Step 1: Boolean prompt
            if (! confirm('Proceed with permanent source organization decommissioning?', default: false)) {
                info('Decommissioning cancelled.');

                return self::SUCCESS;
            }

            // Confirmation Step 2: Strict manual typed phrase
            $typed = $cliConfirm ?: text(
                label: "Type \"{$expectedPhrase}\" to execute source organization teardown:",
                placeholder: $expectedPhrase,
                required: true,
                validate: fn ($val) => strtoupper(trim($val)) === $expectedPhrase ? null : "You must type \"{$expectedPhrase}\" exactly.",
            );

            if (strtoupper(trim($typed)) !== $expectedPhrase) {
                error('Double confirmation failed: Typed confirmation phrase did not match. Aborting.');

                return self::FAILURE;
            }
        }

        $this->newLine();
        $this->line('<fg=cyan;options=bold>Executing Controlled Teardown on Source Organization...</>');
        $this->line(str_repeat('─', 70));
        $anyFailed = false;

        // 1. Delete verified applications from source
        foreach ($safeToDelete as $app) {
            $name = $app['attributes']['name'];
            try {
                $source->delete("applications/{$app['id']}");
                $this->line("  <fg=green>✓</> Deleted source application: <fg=yellow>{$name}</> <fg=gray>({$app['id']})</>");
            } catch (RuntimeException $e) {
                $this->line("  <fg=red>✗</> Could not delete application {$name}: {$e->getMessage()}");
                $anyFailed = true;
            }
        }

        // 2. Delete database clusters from source
        if ($deleteClusters) {
            $this->newLine();
            foreach ($sourceClusters as $cluster) {
                $clusterName = $cluster['attributes']['name'] ?? $cluster['id'];

                // Safety Guard: ensure cluster is not in target
                if (in_array($cluster['id'], $targetClusterIds, true)) {
                    $this->line("  <fg=red>✗</> Safety violation: cluster {$clusterName} exists in target! Skipped.");
                    $anyFailed = true;

                    continue;
                }

                try {
                    // Clean up individual database schemas first
                    try {
                        $schemas = $source->getAll("databases/clusters/{$cluster['id']}/databases");
                        foreach ($schemas as $schema) {
                            try {
                                $source->delete("databases/clusters/{$cluster['id']}/databases/{$schema['id']}");
                            } catch (RuntimeException) {
                                // System/default schemas may not be deletable directly; proceed to cluster deletion
                            }
                        }
                    } catch (RuntimeException) {
                    }

                    $source->delete("databases/clusters/{$cluster['id']}");
                    $this->line("  <fg=green>✓</> Deleted source database cluster: <fg=yellow>{$clusterName}</> <fg=gray>({$cluster['id']})</>");
                } catch (RuntimeException $e) {
                    $this->line("  <fg=red>✗</> Could not delete database cluster {$clusterName}: {$e->getMessage()}");
                    $anyFailed = true;
                }
            }
        }

        // 3. Delete cache clusters from source
        if ($deleteCaches) {
            $this->newLine();
            foreach ($sourceCaches as $cache) {
                $cacheName = $cache['attributes']['name'] ?? $cache['id'];

                // Safety Guard: ensure cache is not in target
                if (in_array($cache['id'], $targetCacheIds, true)) {
                    $this->line("  <fg=red>✗</> Safety violation: cache {$cacheName} exists in target! Skipped.");
                    $anyFailed = true;

                    continue;
                }

                try {
                    $source->delete("caches/{$cache['id']}");
                    $this->line("  <fg=green>✓</> Deleted source cache cluster: <fg=yellow>{$cacheName}</> <fg=gray>({$cache['id']})</>");
                } catch (RuntimeException $e) {
                    $this->line("  <fg=red>✗</> Could not delete cache cluster {$cacheName}: {$e->getMessage()}");
                    $anyFailed = true;
                }
            }
        }

        $this->newLine();
        $this->line(str_repeat('─', 70));
        $this->newLine();

        if ($anyFailed) {
            $this->line('<fg=yellow>⚠  Some resources encountered errors during teardown — review output above.</>');
        } else {
            $this->line('<fg=green;options=bold>✓ Source organization successfully decommissioned with zero impact on target.</>');
        }

        $this->newLine();

        return $anyFailed ? self::FAILURE : self::SUCCESS;
    }

    /**
     * Probe target environments to verify health before executing destructive teardown.
     *
     * @return array<string> List of unhealthy environment names/urls
     */
    private function verifyTargetHealth(CloudApiClient $target, array $targetApps): array
    {
        $unhealthy = [];

        foreach ($targetApps as $app) {
            try {
                $envs = $target->getAll("applications/{$app['id']}/environments");
                foreach ($envs as $env) {
                    $vanity = $env['attributes']['vanity_domain'] ?? null;
                    if (! $vanity) {
                        continue;
                    }

                    $url = 'https://'.$vanity;
                    $ch = curl_init();
                    curl_setopt_array($ch, [
                        CURLOPT_URL => $url,
                        CURLOPT_RETURNTRANSFER => true,
                        CURLOPT_FOLLOWLOCATION => true,
                        CURLOPT_MAXREDIRS => 5,
                        CURLOPT_TIMEOUT => 15,
                        CURLOPT_CONNECTTIMEOUT => 10,
                        CURLOPT_USERAGENT => 'laravel-cloud-migrator/teardown-guard',
                        CURLOPT_SSL_VERIFYPEER => true,
                    ]);

                    curl_exec($ch);
                    $code = curl_getinfo($ch, CURLINFO_HTTP_CODE) ?: null;
                    curl_close($ch);

                    // Allow 2xx, 3xx, and 401/403 (protected/authenticated routes)
                    if ($code === null || $code >= 500) {
                        $unhealthy[] = "{$app['attributes']['name']}:{$env['attributes']['name']} ({$code})";
                    }
                }
            } catch (RuntimeException $e) {
                $unhealthy[] = "{$app['attributes']['name']} (API error: {$e->getMessage()})";
            }
        }

        return $unhealthy;
    }

    protected function makeClient(string $token): CloudApiClient
    {
        if ($this->laravel->has("CloudApiClient.{$token}")) {
            return $this->laravel->make("CloudApiClient.{$token}");
        }

        if ($this->laravel->has(CloudApiClient::class)) {
            return $this->laravel->make(CloudApiClient::class);
        }

        return new CloudApiClient($token);
    }
}
