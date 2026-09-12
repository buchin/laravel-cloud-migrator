<?php

namespace App\Commands;

use App\Services\CloudApiClient;
use App\Services\VanityTransferService;
use LaravelZero\Framework\Commands\Command;
use RuntimeException;

use function Laravel\Prompts\confirm;
use function Laravel\Prompts\error;
use function Laravel\Prompts\info;
use function Laravel\Prompts\note;
use function Laravel\Prompts\password;
use function Laravel\Prompts\spin;
use function Laravel\Prompts\text;

class VanityTransferCommand extends Command
{
    protected $signature = 'vanity:transfer
                            {--source-token= : API token for the source organization}
                            {--target-token= : API token for the target organization}
                            {--source-app= : Name or slug of the source application}
                            {--source-env=production : Name or slug of the source environment}
                            {--target-app= : Name or slug of the target application (defaults to source-app)}
                            {--target-env=production : Name or slug of the target environment}
                            {--vanity= : Vanity subdomain to transfer (auto-detected from source-env if omitted)}
                            {--app= : Alias for source-app (backward compatibility)}
                            {--delete-source : Delete source environment to release vanity immediately without cooldown lock}
                            {--max-retries=15 : Maximum polling retry attempts for reservation lock backoff}
                            {--retry-delay=2 : Initial retry delay in seconds}
                            {--yes : Skip confirmation prompts}';

    protected $description = 'Transfer or cutover *.laravel.cloud vanity domains between environments with dynamic cooldown backoff and collision safety';

    public function handle(): int
    {
        $this->newLine();
        info('Laravel Cloud Migrator — Vanity Domain Transfer');
        $this->newLine();

        $sourceToken = $this->option('source-token') ?: (getenv('CLOUD_SOURCE_TOKEN') ?: password(
            label: 'Source organization API token',
            placeholder: 'Paste your token here...',
            required: true,
        ));

        $targetToken = $this->option('target-token') ?: (getenv('CLOUD_TARGET_TOKEN') ?: password(
            label: 'Target organization API token',
            placeholder: 'Paste your token here...',
            required: true,
        ));

        $sourceAppName = $this->option('source-app') ?: ($this->option('app') ?: text(
            label: 'Source application name or slug',
            placeholder: 'termapi',
            required: true,
        ));

        $sourceEnvName = $this->option('source-env') ?: 'production';
        $targetAppName = $this->option('target-app') ?: $sourceAppName;
        $targetEnvName = $this->option('target-env') ?: $sourceEnvName;
        $requestedVanity = $this->option('vanity');
        $deleteSource = (bool) $this->option('delete-source');
        $maxRetries = max(1, (int) $this->option('max-retries'));
        $retryDelay = max(1, (int) $this->option('retry-delay'));

        $source = $this->makeClient($sourceToken);
        $target = $this->makeClient($targetToken);
        $service = $this->makeService();

        // ── Pre-flight inspection ───────────────────────────────────────────
        try {
            $sourceApp = spin(
                fn () => $service->findApp($source, $sourceAppName),
                "Fetching source application \"{$sourceAppName}\"..."
            );
        } catch (RuntimeException $e) {
            error("Source token invalid or API error: {$e->getMessage()}");

            return self::FAILURE;
        }

        if (! $sourceApp) {
            error("Source application \"{$sourceAppName}\" not found in source organization.");

            return self::FAILURE;
        }

        try {
            $sourceEnv = spin(
                fn () => $service->findEnvironment($source, $sourceApp['id'], $sourceEnvName),
                "Fetching source environment \"{$sourceEnvName}\"..."
            );
        } catch (RuntimeException $e) {
            error("Could not fetch source environment: {$e->getMessage()}");

            return self::FAILURE;
        }

        if (! $sourceEnv) {
            error("Source environment \"{$sourceEnvName}\" not found in application \"{$sourceAppName}\".");

            return self::FAILURE;
        }

        try {
            $targetApp = spin(
                fn () => $service->findApp($target, $targetAppName),
                "Fetching target application \"{$targetAppName}\"..."
            );
        } catch (RuntimeException $e) {
            error("Target token invalid or API error: {$e->getMessage()}");

            return self::FAILURE;
        }

        if (! $targetApp) {
            error("Target application \"{$targetAppName}\" not found in target organization.");

            return self::FAILURE;
        }

        try {
            $targetEnv = spin(
                fn () => $service->findEnvironment($target, $targetApp['id'], $targetEnvName),
                "Fetching target environment \"{$targetEnvName}\"..."
            );
        } catch (RuntimeException $e) {
            error("Could not fetch target environment: {$e->getMessage()}");

            return self::FAILURE;
        }

        if (! $targetEnv) {
            error("Target environment \"{$targetEnvName}\" not found in application \"{$targetAppName}\".");

            return self::FAILURE;
        }

        // Prevent self-transfer
        if ($sourceEnv['id'] === $targetEnv['id']) {
            error('Safety Violation: Source and target environment are identical.');

            return self::FAILURE;
        }

        // Auto-detect or normalize vanity
        $sourceCurrentVanity = $sourceEnv['attributes']['vanity_domain'] ?? null;
        if (empty($requestedVanity)) {
            if (! $sourceCurrentVanity) {
                error("Source environment \"{$sourceEnvName}\" does not have a vanity domain configured to transfer.");

                return self::FAILURE;
            }
            $vanity = $service->normalizeVanity($sourceCurrentVanity);
        } else {
            $vanity = $service->normalizeVanity($requestedVanity);
        }

        $fullVanity = "{$vanity}.laravel.cloud";
        $targetCurrentVanity = $targetEnv['attributes']['vanity_domain'] ?? null;

        // Check if target already has this vanity
        if ($targetCurrentVanity && $service->normalizeVanity($targetCurrentVanity) === $vanity) {
            $this->newLine();
            $this->line("  <fg=green>✓</> Target environment already possesses vanity domain: <fg=cyan>{$fullVanity}</>");
            $this->newLine();

            return self::SUCCESS;
        }

        // ── Collision check & warnings ──────────────────────────────────────
        $hasCollisionWarning = false;
        if ($sourceCurrentVanity && $service->normalizeVanity($sourceCurrentVanity) !== $vanity) {
            $hasCollisionWarning = true;
            $this->newLine();
            $this->line("  <fg=yellow;options=bold>⚠  Collision Alert:</> Source environment currently has <fg=cyan>{$sourceCurrentVanity}</>, which differs from requested <fg=cyan>{$fullVanity}</>.");
            $this->line('     Transferring this vanity may claim a domain not actively bound to the source environment.');
        }

        if ($targetCurrentVanity) {
            $this->newLine();
            $this->line("  <fg=yellow;options=bold>ℹ  Target Overwrite:</> Target environment currently uses <fg=cyan>{$targetCurrentVanity}</>, which will be replaced by <fg=cyan>{$fullVanity}</>.");
        }

        // ── Show Plan Table ─────────────────────────────────────────────────
        $this->newLine();
        $this->line('<fg=cyan;options=bold>Vanity Domain Transfer Plan</>');
        $this->line(str_repeat('─', 65));
        $this->table(
            ['Property', 'Source', 'Target'],
            [
                ['Application', "{$sourceAppName} ({$sourceApp['id']})", "{$targetAppName} ({$targetApp['id']})"],
                ['Environment', "{$sourceEnvName} ({$sourceEnv['id']})", "{$targetEnvName} ({$targetEnv['id']})"],
                ['Current Vanity', $sourceCurrentVanity ?? '(none)', $targetCurrentVanity ?? '(none)'],
                ['Vanity to Claim', '-', "<fg=cyan;options=bold>{$fullVanity}</>"],
                ['Release Mode', $deleteSource ? '<fg=red>Delete Source Env (Instant)</>' : '<fg=yellow>Rename + Cooldown Retry</>', '-'],
                ['Max Retry / Delay', "{$maxRetries} attempts ({$retryDelay}s initial backoff)", '-'],
            ]
        );

        if ($deleteSource) {
            note('WARNING: --delete-source is enabled. The source environment will be permanently deleted to immediately release the reservation lock.');
        } else {
            note('SAFE MODE: Source vanity will be renamed to an archived slug. Target will poll with dynamic backoff until reservation lock clears. If claim times out, source will automatically roll back.');
        }

        $this->newLine();

        if (! $this->option('yes')) {
            $promptQuestion = $hasCollisionWarning
                ? 'Collision detected! Are you certain you want to proceed with this vanity transfer?'
                : 'Proceed with vanity domain transfer?';

            if (! confirm($promptQuestion, default: false)) {
                $this->line('Transfer cancelled.');

                return self::SUCCESS;
            }
        }

        $this->newLine();
        $this->line('<fg=cyan;options=bold>Executing Transfer Pipeline...</>');
        $this->newLine();

        $result = $service->transfer(
            source: $source,
            target: $target,
            sourceAppName: $sourceAppName,
            sourceEnvName: $sourceEnvName,
            targetAppName: $targetAppName,
            targetEnvName: $targetEnvName,
            vanity: $vanity,
            deleteSource: $deleteSource,
            maxAttempts: $maxRetries,
            initialDelayMs: $retryDelay * 1000,
            logger: function (string $level, string $message): void {
                match ($level) {
                    'success' => $this->line("  <fg=green>✓</> {$message}"),
                    'warning' => $this->line("  <fg=yellow>⚠</> {$message}"),
                    'error' => $this->line("  <fg=red>✗</> {$message}"),
                    'notice' => $this->line("  <fg=cyan>ℹ</> {$message}"),
                    default => $this->line("  <fg=gray>→</> {$message}"),
                };
            }
        );

        $this->newLine();
        $this->line(str_repeat('─', 65));

        if ($result->success) {
            $this->newLine();
            $this->info("SUCCESS: Vanity domain {$result->fullVanityDomain()} successfully claimed on target environment!");
            $this->line("Duration: {$result->durationSeconds}s | Attempts: {$result->attempts}");
            $this->newLine();

            return self::SUCCESS;
        }

        if ($result->rolledBack) {
            $this->newLine();
            $this->warn("ABORTED WITH ROLLBACK: {$result->message}");
            $this->line('Source environment safely reclaimed its original vanity domain.');
            $this->newLine();

            return self::FAILURE;
        }

        $this->newLine();
        $this->error("FAILED: {$result->message}");
        $this->newLine();

        return self::FAILURE;
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

    protected function makeService(): VanityTransferService
    {
        if ($this->laravel->has(VanityTransferService::class)) {
            return $this->laravel->make(VanityTransferService::class);
        }

        return new VanityTransferService;
    }
}
