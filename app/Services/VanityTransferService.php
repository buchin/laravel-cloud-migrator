<?php

namespace App\Services;

use App\Data\VanityTransferResult;
use RuntimeException;

class VanityTransferService
{
    /** @var callable|null */
    private $sleeper;

    /**
     * @param  (callable(int): void)|null  $sleeper  Custom sleeper function accepting microseconds (defaults to usleep)
     */
    public function __construct(?callable $sleeper = null)
    {
        $this->sleeper = $sleeper;
    }

    /**
     * Transfer a vanity domain from a source environment to a target environment.
     *
     * @param  CloudApiClient  $source  API client for source organization
     * @param  CloudApiClient  $target  API client for target organization
     * @param  string  $sourceAppName  Name or slug of the source application
     * @param  string  $sourceEnvName  Name or slug of the source environment
     * @param  string  $targetAppName  Name or slug of the target application
     * @param  string  $targetEnvName  Name or slug of the target environment
     * @param  string|null  $vanity  Requested vanity subdomain (auto-detected if null)
     * @param  bool  $deleteSource  Whether to delete the source environment for instant release
     * @param  int  $maxAttempts  Maximum polling retry attempts for reservation lock
     * @param  int  $initialDelayMs  Initial retry delay in milliseconds
     * @param  float  $backoffMultiplier  Backoff multiplier for subsequent retries
     * @param  int  $maxDelayMs  Maximum retry delay ceiling in milliseconds
     * @param  (callable(string, string): void)|null  $logger  Progress logger callable(string $level, string $message)
     */
    public function transfer(
        CloudApiClient $source,
        CloudApiClient $target,
        string $sourceAppName,
        string $sourceEnvName = 'production',
        string $targetAppName = '',
        string $targetEnvName = 'production',
        ?string $vanity = null,
        bool $deleteSource = false,
        int $maxAttempts = 15,
        int $initialDelayMs = 1000,
        float $backoffMultiplier = 1.5,
        int $maxDelayMs = 5000,
        ?callable $logger = null,
    ): VanityTransferResult {
        $startTime = microtime(true);
        $logs = [];
        $log = function (string $level, string $message) use (&$logs, $logger): void {
            $logs[] = "[{$level}] {$message}";
            if ($logger) {
                $logger($level, $message);
            }
        };

        if (empty($targetAppName)) {
            $targetAppName = $sourceAppName;
        }

        $log('info', "Resolving source application \"{$sourceAppName}\"...");
        $sourceApp = $this->findApp($source, $sourceAppName);
        if (! $sourceApp) {
            return new VanityTransferResult(
                success: false,
                vanity: $vanity ?? '',
                sourceApp: $sourceAppName,
                sourceEnv: $sourceEnvName,
                targetApp: $targetAppName,
                targetEnv: $targetEnvName,
                status: 'failed',
                message: "Source application \"{$sourceAppName}\" not found in source organization.",
                durationSeconds: round(microtime(true) - $startTime, 3),
                logs: $logs,
            );
        }

        $log('info', "Resolving source environment \"{$sourceEnvName}\" in application \"{$sourceAppName}\"...");
        $sourceEnv = $this->findEnvironment($source, $sourceApp['id'], $sourceEnvName);
        if (! $sourceEnv) {
            return new VanityTransferResult(
                success: false,
                vanity: $vanity ?? '',
                sourceApp: $sourceAppName,
                sourceEnv: $sourceEnvName,
                targetApp: $targetAppName,
                targetEnv: $targetEnvName,
                status: 'failed',
                message: "Source environment \"{$sourceEnvName}\" not found in source application.",
                durationSeconds: round(microtime(true) - $startTime, 3),
                logs: $logs,
            );
        }

        $log('info', "Resolving target application \"{$targetAppName}\"...");
        $targetApp = $this->findApp($target, $targetAppName);
        if (! $targetApp) {
            return new VanityTransferResult(
                success: false,
                vanity: $vanity ?? '',
                sourceApp: $sourceAppName,
                sourceEnv: $sourceEnvName,
                targetApp: $targetAppName,
                targetEnv: $targetEnvName,
                status: 'failed',
                message: "Target application \"{$targetAppName}\" not found in target organization.",
                durationSeconds: round(microtime(true) - $startTime, 3),
                logs: $logs,
            );
        }

        $log('info', "Resolving target environment \"{$targetEnvName}\" in application \"{$targetAppName}\"...");
        $targetEnv = $this->findEnvironment($target, $targetApp['id'], $targetEnvName);
        if (! $targetEnv) {
            return new VanityTransferResult(
                success: false,
                vanity: $vanity ?? '',
                sourceApp: $sourceAppName,
                sourceEnv: $sourceEnvName,
                targetApp: $targetAppName,
                targetEnv: $targetEnvName,
                status: 'failed',
                message: "Target environment \"{$targetEnvName}\" not found in target application.",
                durationSeconds: round(microtime(true) - $startTime, 3),
                logs: $logs,
            );
        }

        // Prevent self-transfer loop
        if ($sourceEnv['id'] === $targetEnv['id']) {
            return new VanityTransferResult(
                success: false,
                vanity: $vanity ?? '',
                sourceApp: $sourceAppName,
                sourceEnv: $sourceEnvName,
                targetApp: $targetAppName,
                targetEnv: $targetEnvName,
                status: 'failed',
                message: 'Source and target environment are the same environment.',
                durationSeconds: round(microtime(true) - $startTime, 3),
                logs: $logs,
            );
        }

        // Resolve vanity subdomain
        if (empty($vanity)) {
            $sourceVanityDomain = $sourceEnv['attributes']['vanity_domain'] ?? null;
            if (! $sourceVanityDomain) {
                return new VanityTransferResult(
                    success: false,
                    vanity: '',
                    sourceApp: $sourceAppName,
                    sourceEnv: $sourceEnvName,
                    targetApp: $targetAppName,
                    targetEnv: $targetEnvName,
                    status: 'failed',
                    message: "Source environment \"{$sourceEnvName}\" has no vanity domain configured to transfer.",
                    durationSeconds: round(microtime(true) - $startTime, 3),
                    logs: $logs,
                );
            }
            $vanity = $this->normalizeVanity($sourceVanityDomain);
            $log('info', "Auto-detected vanity domain from source: \"{$vanity}.laravel.cloud\"");
        } else {
            $vanity = $this->normalizeVanity($vanity);
        }

        $fullVanity = "{$vanity}.laravel.cloud";

        // Check if target already has this vanity domain
        $targetCurrentVanity = $targetEnv['attributes']['vanity_domain'] ?? null;
        if ($targetCurrentVanity && $this->normalizeVanity($targetCurrentVanity) === $vanity) {
            $log('notice', "Target environment already has vanity domain \"{$fullVanity}\". No action needed.");

            return new VanityTransferResult(
                success: true,
                vanity: $vanity,
                sourceApp: $sourceAppName,
                sourceEnv: $sourceEnvName,
                targetApp: $targetAppName,
                targetEnv: $targetEnvName,
                status: 'already_set',
                message: "Target environment already possesses vanity domain \"{$fullVanity}\".",
                durationSeconds: round(microtime(true) - $startTime, 3),
                logs: $logs,
                targetEnvData: $targetEnv,
            );
        }

        // Collision check
        $sourceCurrentVanity = $sourceEnv['attributes']['vanity_domain'] ?? null;
        if ($sourceCurrentVanity && $this->normalizeVanity($sourceCurrentVanity) !== $vanity) {
            $log('warning', "Collision warning: Source environment currently has vanity \"{$sourceCurrentVanity}\", which differs from requested vanity \"{$fullVanity}\".");
        }

        // Step 1: Release vanity from source
        $releasedAs = null;
        $originalSourceVanity = $sourceCurrentVanity ? $this->normalizeVanity($sourceCurrentVanity) : null;

        if ($deleteSource) {
            $log('info', "Releasing vanity via deletion of source environment \"{$sourceEnv['id']}\"...");
            try {
                $source->delete("environments/{$sourceEnv['id']}");
                $releasedAs = 'deleted';
                $log('success', 'Source environment deleted. Vanity domain released globally.');
            } catch (RuntimeException $e) {
                // If environment deletion is restricted or fails, fallback to archive rename
                $log('warning', "Environment deletion failed ({$e->getMessage()}). Falling back to rename release...");
                $archivedName = $vanity.'-archived-'.bin2hex(random_bytes(3));
                $source->put("environments/{$sourceEnv['id']}/vanity-domain", ['name' => $archivedName]);
                $releasedAs = $archivedName;
                $log('success', "Source environment renamed to \"{$archivedName}\".");
            }
        } else {
            $archivedName = $vanity.'-archived-'.bin2hex(random_bytes(3));
            $log('info', "Releasing vanity on source by renaming to \"{$archivedName}\"...");
            $source->put("environments/{$sourceEnv['id']}/vanity-domain", ['name' => $archivedName]);
            $releasedAs = $archivedName;
            $log('success', "Source environment vanity renamed to \"{$archivedName}.laravel.cloud\".");
        }

        // Step 2: Claim vanity on target with dynamic retry backoff
        $delayMs = $initialDelayMs;
        $attempt = 1;
        $targetClaimSuccess = false;
        $targetEnvResult = null;

        $log('info', "Claiming vanity domain \"{$vanity}\" on target environment...");

        for ($attempt = 1; $attempt <= $maxAttempts; $attempt++) {
            try {
                $response = $target->put("environments/{$targetEnv['id']}/vanity-domain", [
                    'name' => $vanity,
                ]);
                $targetClaimSuccess = true;
                $targetEnvResult = $response['data'] ?? $response;
                $log('success', "Target environment successfully claimed vanity domain \"{$fullVanity}\" (attempt {$attempt}/{$maxAttempts})!");
                break;
            } catch (RuntimeException $e) {
                if ($this->isReservationLocked($e)) {
                    if ($attempt === $maxAttempts) {
                        $log('error', "Reservation cooldown lock still active after {$maxAttempts} attempts for \"{$fullVanity}\".");
                        break;
                    }
                    $log('warning', "Vanity domain locked by cooldown (attempt {$attempt}/{$maxAttempts}). Retrying in {$delayMs}ms...");
                    $this->sleepMs($delayMs);
                    $delayMs = (int) min($delayMs * $backoffMultiplier, $maxDelayMs);

                    continue;
                }

                // Unexpected error during claim
                $log('error', "Target claim failed with error: {$e->getMessage()}");
                break;
            }
        }

        // Step 3: Handle outcome and rollback if needed
        if ($targetClaimSuccess) {
            return new VanityTransferResult(
                success: true,
                vanity: $vanity,
                sourceApp: $sourceAppName,
                sourceEnv: $sourceEnvName,
                targetApp: $targetAppName,
                targetEnv: $targetEnvName,
                status: 'transferred',
                message: "Successfully transferred vanity domain \"{$fullVanity}\" to target environment.",
                attempts: $attempt,
                releasedAs: $releasedAs,
                rolledBack: false,
                durationSeconds: round(microtime(true) - $startTime, 3),
                logs: $logs,
                targetEnvData: $targetEnvResult,
            );
        }

        // Claim failed: attempt rollback to source if not deleted
        $rolledBack = false;
        if ($releasedAs !== 'deleted' && $originalSourceVanity) {
            $log('notice', "Attempting rollback: reclaiming original vanity \"{$originalSourceVanity}\" on source environment...");
            try {
                $source->put("environments/{$sourceEnv['id']}/vanity-domain", [
                    'name' => $originalSourceVanity,
                ]);
                $rolledBack = true;
                $log('success', "Rollback successful: source environment reclaimed \"{$originalSourceVanity}.laravel.cloud\".");
            } catch (RuntimeException $re) {
                $log('error', "Rollback to source failed: {$re->getMessage()}");
            }
        }

        $failureStatus = $rolledBack ? 'rolled_back' : 'failed';
        $failureMessage = $rolledBack
            ? "Transfer failed after {$attempt} attempts. Safety rollback restored \"{$originalSourceVanity}.laravel.cloud\" on source environment."
            : "Transfer failed after {$attempt} attempts. Could not claim vanity on target.";

        return new VanityTransferResult(
            success: false,
            vanity: $vanity,
            sourceApp: $sourceAppName,
            sourceEnv: $sourceEnvName,
            targetApp: $targetAppName,
            targetEnv: $targetEnvName,
            status: $failureStatus,
            message: $failureMessage,
            attempts: $attempt,
            releasedAs: $releasedAs,
            rolledBack: $rolledBack,
            durationSeconds: round(microtime(true) - $startTime, 3),
            logs: $logs,
        );
    }

    /**
     * Find application by name, slug, or ID.
     */
    public function findApp(CloudApiClient $client, string $nameOrSlug): ?array
    {
        $apps = $client->getAll('applications');
        foreach ($apps as $app) {
            $attrs = $app['attributes'] ?? [];
            if (($attrs['name'] ?? '') === $nameOrSlug ||
                ($attrs['slug'] ?? '') === $nameOrSlug ||
                ($app['id'] ?? '') === $nameOrSlug
            ) {
                return $app;
            }
        }

        return null;
    }

    /**
     * Find environment by name, slug, or ID within an application.
     */
    public function findEnvironment(CloudApiClient $client, string $appId, string $nameOrSlug): ?array
    {
        $envs = $client->getAll("applications/{$appId}/environments");
        foreach ($envs as $env) {
            $attrs = $env['attributes'] ?? [];
            if (($attrs['name'] ?? '') === $nameOrSlug ||
                ($attrs['slug'] ?? '') === $nameOrSlug ||
                ($env['id'] ?? '') === $nameOrSlug
            ) {
                return $env;
            }
        }

        return null;
    }

    /**
     * Normalize vanity domain string by stripping protocols and .laravel.cloud suffix.
     */
    public function normalizeVanity(string $vanity): string
    {
        $vanity = trim(strtolower($vanity));
        $vanity = preg_replace('#^https?://#', '', $vanity);
        $vanity = preg_replace('#\.laravel\.cloud.*$#', '', $vanity);

        return trim($vanity, '/.');
    }

    /**
     * Check if an exception indicates a reservation lock or collision (HTTP 422 domain already taken).
     */
    public function isReservationLocked(RuntimeException $e): bool
    {
        $msg = strtolower($e->getMessage());

        return $e->getCode() === 422 ||
            str_contains($msg, 'already taken') ||
            str_contains($msg, 'taken') ||
            str_contains($msg, 'cooldown') ||
            str_contains($msg, 'reserved');
    }

    /**
     * Sleep for the specified duration in milliseconds.
     */
    private function sleepMs(int $ms): void
    {
        if ($this->sleeper !== null) {
            ($this->sleeper)($ms * 1000);
        } else {
            usleep($ms * 1000);
        }
    }
}
