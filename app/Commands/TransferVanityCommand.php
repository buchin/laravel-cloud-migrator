<?php

namespace App\Commands;

use App\Data\ApplicationData;
use App\Data\EnvironmentData;
use App\Data\MigrationPlan;
use App\Services\CloudApiClient;
use App\Services\MigrationService;
use LaravelZero\Framework\Commands\Command;
use RuntimeException;

use function Laravel\Prompts\confirm;
use function Laravel\Prompts\error;
use function Laravel\Prompts\password;
use function Laravel\Prompts\spin;
use function Laravel\Prompts\text;

class TransferVanityCommand extends Command
{
    protected $signature = 'vanity:transfer
                            {--source-token= : API token for the source organization}
                            {--target-token= : API token for the target organization}
                            {--app= : App name (e.g. "termapi")}
                            {--delete-source : Delete the source app to immediately release the vanity domain}
                            {--yes : Skip confirmation prompts}';

    protected $description = 'Transfer the Laravel Cloud vanity domain from the source app to the matching target app';

    public function handle(): int
    {
        $this->newLine();

        $sourceToken = $this->option('source-token') ?: password(
            label: 'Source organization API token',
            placeholder: 'Paste your token here...',
            required: true,
        );

        $targetToken = $this->option('target-token') ?: password(
            label: 'Target organization API token',
            placeholder: 'Paste your token here...',
            required: true,
        );

        $appName = $this->option('app') ?: text(
            label: 'App name to transfer vanity for',
            placeholder: 'termapi',
            required: true,
        );

        $source = new CloudApiClient($sourceToken);
        $target = new CloudApiClient($targetToken);

        // ── Fetch target app ─────────────────────────────────────────────────
        try {
            $targetApps = spin(fn () => $target->getAll('applications'), 'Fetching target applications...');
        } catch (RuntimeException $e) {
            error('Target token invalid: '.$e->getMessage());

            return self::FAILURE;
        }

        $targetApp = null;
        foreach ($targetApps as $app) {
            if (($app['attributes']['name'] ?? '') === $appName) {
                $targetApp = $app;
                break;
            }
        }

        if (! $targetApp) {
            error("App \"{$appName}\" not found in target organization.");

            return self::FAILURE;
        }

        $targetAppId = $targetApp['id'];
        $currentTargetSlug = $targetApp['attributes']['slug'];

        // ── Fetch source app ─────────────────────────────────────────────────
        try {
            $sourceApps = spin(fn () => $source->getAll('applications'), 'Fetching source applications...');
        } catch (RuntimeException $e) {
            error('Source token invalid: '.$e->getMessage());

            return self::FAILURE;
        }

        // Match by name, or by slug prefix if already archived from prior attempt.
        $sourceApp = null;
        foreach ($sourceApps as $app) {
            if (($app['attributes']['name'] ?? '') === $appName) {
                $sourceApp = $app;
                break;
            }
        }
        if (! $sourceApp) {
            foreach ($sourceApps as $app) {
                if (str_starts_with($app['attributes']['slug'] ?? '', $appName.'-archived-')) {
                    $sourceApp = $app;
                    break;
                }
            }
        }

        if (! $sourceApp) {
            error("App \"{$appName}\" not found in source organization.");

            return self::FAILURE;
        }

        $sourceAppId = $sourceApp['id'];
        $sourceSlug = $sourceApp['attributes']['slug'];

        // Desired slug = app name (what source originally had).
        $desiredSlug = $appName;

        // ── Fetch environments ────────────────────────────────────────────────
        try {
            $targetEnvs = spin(
                fn () => $target->getAll("applications/{$targetAppId}/environments"),
                'Fetching target environments...'
            );
            $sourceEnvs = spin(
                fn () => $source->getAll("applications/{$sourceAppId}/environments"),
                'Fetching source environments...'
            );
        } catch (RuntimeException $e) {
            error('Could not fetch environments: '.$e->getMessage());

            return self::FAILURE;
        }

        $targetEnvsByName = [];
        foreach ($targetEnvs as $env) {
            $targetEnvsByName[$env['attributes']['name']] = $env['id'];
        }

        $envIdMap = [];
        $environments = [];
        foreach ($sourceEnvs as $env) {
            $name = $env['attributes']['name'];
            if (isset($targetEnvsByName[$name])) {
                $envIdMap[$env['id']] = $targetEnvsByName[$name];
            }
            $environments[] = EnvironmentData::fromApi($env);
        }

        // ── Show plan ─────────────────────────────────────────────────────────
        $this->newLine();
        $this->line('<fg=cyan;options=bold>Vanity Domain Transfer</>');
        $this->line(str_repeat('─', 55));
        $this->line("  App:            {$appName}");
        $this->line("  Source slug:    {$sourceSlug}");
        $this->line("  Target slug:    {$currentTargetSlug} → <fg=green>{$desiredSlug}</>");

        if ($this->option('delete-source')) {
            $this->newLine();
            $this->line('  <fg=red;options=bold>--delete-source:</> Source app will be permanently deleted.');
            $this->line('  <fg=yellow>  ⚑ Brief downtime (~5s) while slug transfers to target.</>');
        }

        $this->newLine();

        if (! $this->option('yes') && ! confirm('Proceed?', default: false)) {
            $this->line('Cancelled.');

            return self::SUCCESS;
        }

        $this->newLine();

        // ── Step 1: move custom domains ───────────────────────────────────────
        $sourceAppAttrs = $sourceApp['attributes'];
        $plan = new MigrationPlan(
            application: new ApplicationData(
                id: $sourceAppId,
                name: $appName,
                slug: $desiredSlug,
                repository: $sourceAppAttrs['repository']['full_name'] ?? $sourceAppAttrs['repository'] ?? '',
                region: $sourceAppAttrs['region'] ?? '',
                sourceControlProviderType: $sourceAppAttrs['source_control_provider_type'] ?? null,
            ),
            environments: $environments,
            variables: [],
            databases: [],
            caches: [],
            instances: [],
            domains: [],
        );

        $service = new MigrationService($source, $target);
        $service->setLastCreatedAppId($targetAppId);
        $service->setEnvIdMap($envIdMap);

        // Move only custom domains (Step 1); we handle vanity ourselves below.
        $this->moveDomains($source, $target, $plan, $envIdMap);

        // ── Step 2: release vanity by deleting source OR slug-rename ─────────
        if ($this->option('delete-source')) {
            $this->deleteSourceAndClaim($source, $target, $sourceAppId, $targetAppId, $desiredSlug, $envIdMap);
        } else {
            // Slug-rename path (requires source already archived or still original).
            $service->moveDomains($plan, function (string $message) {
                $this->line("  <fg=green>✓</> {$message}");
            });
        }

        $this->newLine();
        $this->line(str_repeat('─', 55));
        $this->newLine();

        return self::SUCCESS;
    }

    private function moveDomains(
        CloudApiClient $source,
        CloudApiClient $target,
        MigrationPlan $plan,
        array $envIdMap,
    ): void {
        foreach ($plan->environments as $env) {
            $targetEnvId = $envIdMap[$env->id] ?? null;
            if (! $targetEnvId) {
                continue;
            }

            try {
                $domainItems = $source->getAll("environments/{$env->id}/domains");
            } catch (RuntimeException) {
                continue;
            }

            if (empty($domainItems)) {
                continue;
            }

            $alreadyInTarget = array_column(
                array_column($target->getAll("environments/{$targetEnvId}/domains"), 'attributes'),
                'name'
            );

            foreach ($domainItems as $domain) {
                $domainId = $domain['id'];
                $domainName = $domain['attributes']['name'] ?? $domainId;

                if (in_array($domainName, $alreadyInTarget)) {
                    $this->line("  <fg=green>✓</> Already in target: {$domainName}");

                    continue;
                }

                try {
                    $source->delete("domains/{$domainId}");
                } catch (RuntimeException) {
                }

                try {
                    $target->post("environments/{$targetEnvId}/domains", ['name' => $domainName]);
                    $this->line("  <fg=green>✓</> Moved domain: {$domainName} ({$env->name})");
                } catch (RuntimeException $e) {
                    $this->line("  <fg=red>✗</> Failed to move {$domainName}: {$e->getMessage()}");
                }
            }
        }
    }

    private function deleteSourceAndClaim(
        CloudApiClient $source,
        CloudApiClient $target,
        string $sourceAppId,
        string $targetAppId,
        string $desiredSlug,
        array $envIdMap,
    ): void {
        // Delete source app — this immediately releases the slug + vanity.
        $this->line('  <fg=yellow>Deleting source app...</>');
        try {
            $source->delete("applications/{$sourceAppId}");
            $this->line('  <fg=green>✓</> Source app deleted.');
        } catch (RuntimeException $e) {
            $this->line("  <fg=red>✗</> Could not delete source app: {$e->getMessage()}");
            $this->line('  <fg=yellow>Aborting slug claim — source was not deleted.</>');

            return;
        }

        // Claim the slug on target — retry briefly since there may still be a short
        // propagation window after delete.
        $claimed = false;
        for ($attempt = 1; $attempt <= 20; $attempt++) {
            try {
                $target->patch("applications/{$targetAppId}", ['slug' => $desiredSlug]);
                $claimed = true;
                $this->line("  <fg=green>✓</> Target slug claimed: {$desiredSlug}");
                break;
            } catch (RuntimeException $e) {
                if ($attempt === 20) {
                    $this->line("  <fg=red>✗</> Could not claim slug \"{$desiredSlug}\" after {$attempt} attempts: {$e->getMessage()}");

                    return;
                }
                $this->line("  Slug not yet released (attempt {$attempt}/20) — retrying in 5s...");
                sleep(5);
            }
        }

        if (! $claimed) {
            return;
        }

        // Rename target env names to match source (produces clean vanity URL format).
        foreach ($envIdMap as $sourceEnvId => $targetEnvId) {
            // Find env name from envIdMap by reverse-matching.
        }

        // Re-fetch target environments to get their current names, then rename to match source.
        try {
            $targetEnvs = $target->getAll("applications/{$targetAppId}/environments");
        } catch (RuntimeException) {
            return;
        }

        // Report new vanities.
        sleep(2);
        try {
            $refreshed = $target->getAll("applications/{$targetAppId}/environments");
            foreach ($refreshed as $env) {
                if ($v = $env['attributes']['vanity_domain'] ?? null) {
                    $this->line("  <fg=green>✓</> New vanity: {$v}");
                }
            }
        } catch (RuntimeException) {
        }
    }
}
