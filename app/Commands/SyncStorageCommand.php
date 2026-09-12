<?php

namespace App\Commands;

use App\Data\SyncFileResult;
use App\Data\SyncResult;
use App\Services\MigrationManifest;
use App\Services\StorageSyncService;
use LaravelZero\Framework\Commands\Command;
use Throwable;

use function Laravel\Prompts\confirm;
use function Laravel\Prompts\error;
use function Laravel\Prompts\info;
use function Laravel\Prompts\note;
use function Laravel\Prompts\password;
use function Laravel\Prompts\text;

class SyncStorageCommand extends Command
{
    protected $signature = 'storage:sync
                            {--source-bucket= : Source S3 bucket name}
                            {--target-bucket= : Target S3 bucket name}
                            {--source-region= : Source AWS region (default: env SOURCE_AWS_DEFAULT_REGION or us-east-1)}
                            {--target-region= : Target AWS region (default: env TARGET_AWS_DEFAULT_REGION or us-east-1)}
                            {--source-endpoint= : Source custom S3 endpoint URL (for R2, MinIO, Wasabi)}
                            {--target-endpoint= : Target custom S3 endpoint URL (for R2, MinIO, Wasabi)}
                            {--source-key= : Source AWS access key ID (default: env SOURCE_AWS_ACCESS_KEY_ID or AWS_ACCESS_KEY_ID)}
                            {--source-secret= : Source AWS secret access key (default: env SOURCE_AWS_SECRET_ACCESS_KEY or AWS_SECRET_ACCESS_KEY)}
                            {--target-key= : Target AWS access key ID (default: env TARGET_AWS_ACCESS_KEY_ID or AWS_ACCESS_KEY_ID)}
                            {--target-secret= : Target AWS secret access key (default: env TARGET_AWS_SECRET_ACCESS_KEY or AWS_SECRET_ACCESS_KEY)}
                            {--buckets=* : Multi-bucket pairs in format source:target (e.g. --buckets=media:media-bak)}
                            {--prefix= : Only sync objects starting with this prefix}
                            {--concurrency=5 : Number of concurrent transfers (default: 5)}
                            {--dry-run : Simulate transfer without writing to target or deleting}
                            {--delete-missing : Delete objects on target that do not exist on source}
                            {--manifest= : Path to declarative migration manifest (default: migration-plan.json if exists)}
                            {--yes : Proceed without confirmation prompt}';

    protected $description = 'Synchronize object storage / S3 buckets using direct streaming transfer, checksum validation, and parallel processing';

    public function handle(): int
    {
        $this->newLine();
        info('Laravel Cloud Migrator — Object Storage / S3 Synchronization');
        $this->newLine();

        $manifestPath = $this->option('manifest');
        $manifest = null;
        if ($manifestPath) {
            if (! file_exists($manifestPath)) {
                error("Specified manifest file does not exist: {$manifestPath}");

                return self::FAILURE;
            }
            $manifest = MigrationManifest::fromFile($manifestPath);
            info("Loaded migration manifest from {$manifestPath}");
        } elseif (file_exists('migration-plan.json')) {
            $manifest = MigrationManifest::fromFile('migration-plan.json');
            info('Loaded declarative migration manifest from migration-plan.json');
        }

        // 1. Resolve bucket pairs
        $bucketPairs = $this->resolveBucketPairs($manifest);
        if (empty($bucketPairs)) {
            error('No source and target buckets specified. Provide --source-bucket and --target-bucket, --buckets, or a manifest.');

            return self::FAILURE;
        }

        // 2. Resolve credentials & endpoints
        $sourceKey = $this->option('source-key')
            ?: getenv('SOURCE_AWS_ACCESS_KEY_ID')
            ?: getenv('AWS_ACCESS_KEY_ID');

        $sourceSecret = $this->option('source-secret')
            ?: getenv('SOURCE_AWS_SECRET_ACCESS_KEY')
            ?: getenv('AWS_SECRET_ACCESS_KEY');

        $targetKey = $this->option('target-key')
            ?: getenv('TARGET_AWS_ACCESS_KEY_ID')
            ?: getenv('AWS_ACCESS_KEY_ID');

        $targetSecret = $this->option('target-secret')
            ?: getenv('TARGET_AWS_SECRET_ACCESS_KEY')
            ?: getenv('AWS_SECRET_ACCESS_KEY');

        if (! $sourceKey && ! $this->option('yes')) {
            $sourceKey = text(
                label: 'Source AWS Access Key ID',
                placeholder: 'AKIA...',
                required: true,
            );
        }

        if (! $sourceSecret && ! $this->option('yes')) {
            $sourceSecret = password(
                label: 'Source AWS Secret Access Key',
                placeholder: 'Secret key...',
                required: true,
            );
        }

        if (! $targetKey && ! $this->option('yes')) {
            $targetKey = text(
                label: 'Target AWS Access Key ID',
                placeholder: 'AKIA...',
                required: true,
            );
        }

        if (! $targetSecret && ! $this->option('yes')) {
            $targetSecret = password(
                label: 'Target AWS Secret Access Key',
                placeholder: 'Secret key...',
                required: true,
            );
        }

        if (! $this->laravel->bound(StorageSyncService::class) && (! $sourceKey || ! $sourceSecret || ! $targetKey || ! $targetSecret)) {
            error('Missing S3 credentials. Provide --source-key, --source-secret, --target-key, --target-secret or set matching env variables.');

            return self::FAILURE;
        }

        $sourceRegion = $this->option('source-region')
            ?: getenv('SOURCE_AWS_DEFAULT_REGION')
            ?: getenv('SOURCE_AWS_REGION')
            ?: getenv('AWS_DEFAULT_REGION')
            ?: 'us-east-1';

        $targetRegion = $this->option('target-region')
            ?: getenv('TARGET_AWS_DEFAULT_REGION')
            ?: getenv('TARGET_AWS_REGION')
            ?: getenv('AWS_DEFAULT_REGION')
            ?: 'us-east-1';

        $sourceEndpoint = $this->option('source-endpoint')
            ?: getenv('SOURCE_AWS_ENDPOINT')
            ?: getenv('AWS_ENDPOINT_URL');

        $targetEndpoint = $this->option('target-endpoint')
            ?: getenv('TARGET_AWS_ENDPOINT')
            ?: getenv('AWS_ENDPOINT_URL');

        $concurrency = max(1, (int) ($this->option('concurrency') ?: 5));
        $dryRun = (bool) $this->option('dry-run');
        $deleteMissing = (bool) $this->option('delete-missing');
        $prefix = (string) ($this->option('prefix') ?: '');

        // 3. Pre-flight plan display
        $this->line('<fg=yellow;options=bold>── Migration Pre-Flight Plan ──</>');
        $this->line('  Bucket Pairs    : <fg=white>'.count($bucketPairs).' pair(s)</>');
        foreach ($bucketPairs as $idx => $pair) {
            $pairPrefix = ! empty($pair['prefix']) ? $pair['prefix'] : ($prefix ?: '(all)');
            $this->line('    [#'.($idx + 1)."] <fg=cyan>{$pair['source_bucket']}</> → <fg=green>{$pair['target_bucket']}</> (prefix: {$pairPrefix})");
        }
        $this->line("  Source Region   : <fg=white>{$sourceRegion}</>".($sourceEndpoint ? " (endpoint: {$sourceEndpoint})" : ''));
        $this->line("  Target Region   : <fg=white>{$targetRegion}</>".($targetEndpoint ? " (endpoint: {$targetEndpoint})" : ''));
        $this->line("  Concurrency     : <fg=white>{$concurrency} worker(s)</>");
        $this->line('  Mode            : <fg=white>'.($dryRun ? '<fg=yellow>DRY-RUN (simulation only)</>' : '<fg=green>LIVE SYNC (direct streaming)</>').'</>');
        $this->line('  Delete Missing  : <fg=white>'.($deleteMissing ? '<fg=red>YES (delete orphan target objects)</>' : 'NO').'</>');
        $this->newLine();

        if (! $this->option('yes') && ! confirm('Proceed with storage synchronization?', true)) {
            note('Synchronization cancelled.');

            return self::SUCCESS;
        }

        $allResults = [];
        $hasFailures = false;

        // 4. Run synchronization for each bucket pair
        foreach ($bucketPairs as $pair) {
            $srcBucket = $pair['source_bucket'];
            $tgtBucket = $pair['target_bucket'];
            $effectivePrefix = ! empty($pair['prefix']) ? $pair['prefix'] : $prefix;

            $this->newLine();
            $this->line("<fg=cyan;options=bold>── Syncing {$srcBucket} → {$tgtBucket} ──</>");

            $sourceConfig = [
                'bucket' => $srcBucket,
                'region' => $pair['source_region'] ?? $sourceRegion,
                'endpoint' => $pair['source_endpoint'] ?? $sourceEndpoint,
                'key' => $sourceKey,
                'secret' => $sourceSecret,
                'prefix' => $effectivePrefix,
            ];

            $targetConfig = [
                'bucket' => $tgtBucket,
                'region' => $pair['target_region'] ?? $targetRegion,
                'endpoint' => $pair['target_endpoint'] ?? $targetEndpoint,
                'key' => $targetKey,
                'secret' => $targetSecret,
                'prefix' => $effectivePrefix,
            ];

            try {
                $service = $this->getSyncService($sourceConfig, $targetConfig);

                $result = $service->sync([
                    'prefix' => $effectivePrefix,
                    'concurrency' => $concurrency,
                    'dry_run' => $dryRun,
                    'delete_missing' => $deleteMissing,
                    'progress' => function (SyncFileResult $res, int $completed, int $total) {
                        $this->renderProgressLine($res, $completed, $total);
                    },
                ]);

                $allResults[$srcBucket] = $result;
                if ($result->hasFailures()) {
                    $hasFailures = true;
                }

                $this->renderSummaryTable($result);
            } catch (Throwable $e) {
                error("Failed syncing bucket [{$srcBucket}]: {$e->getMessage()}");
                $hasFailures = true;
            }
        }

        $this->newLine();
        if ($hasFailures) {
            error('Storage sync completed with errors. Please check the logs above.');

            return self::FAILURE;
        }

        info('Storage synchronization completed successfully.');

        return self::SUCCESS;
    }

    /**
     * Resolve bucket pairs from options, manifest, or interactive prompts.
     *
     * @return array<int, array{source_bucket: string, target_bucket: string, prefix?: string, source_region?: ?string, target_region?: ?string, source_endpoint?: ?string, target_endpoint?: ?string}>
     */
    protected function resolveBucketPairs(?MigrationManifest $manifest): array
    {
        $pairs = [];

        // 1. Check --buckets options
        $bucketsOption = (array) $this->option('buckets');
        if (! empty($bucketsOption)) {
            foreach ($bucketsOption as $item) {
                $parts = explode(',', (string) $item);
                foreach ($parts as $part) {
                    $part = trim($part);
                    if ($part === '') {
                        continue;
                    }
                    if (str_contains($part, ':')) {
                        [$src, $tgt] = explode(':', $part, 2);
                        $pairs[] = [
                            'source_bucket' => trim($src),
                            'target_bucket' => trim($tgt),
                            'prefix' => (string) ($this->option('prefix') ?: ''),
                        ];
                    } else {
                        $pairs[] = [
                            'source_bucket' => $part,
                            'target_bucket' => $part,
                            'prefix' => (string) ($this->option('prefix') ?: ''),
                        ];
                    }
                }
            }

            return $pairs;
        }

        // 2. Check --source-bucket and --target-bucket
        $srcBucket = $this->option('source-bucket');
        $tgtBucket = $this->option('target-bucket');

        if ($srcBucket && $tgtBucket) {
            return [[
                'source_bucket' => $srcBucket,
                'target_bucket' => $tgtBucket,
                'prefix' => (string) ($this->option('prefix') ?: ''),
            ]];
        }

        // 3. Check manifest storage configuration
        if ($manifest) {
            $manifestBuckets = $manifest->getStorageBuckets();
            if (! empty($manifestBuckets)) {
                return $manifestBuckets;
            }
        }

        // 4. Fallback to interactive prompts if not in non-interactive --yes mode
        if (! $this->option('yes')) {
            if (! $srcBucket) {
                $srcBucket = text(
                    label: 'Source S3 bucket name',
                    placeholder: 'my-source-bucket',
                    required: true,
                );
            }

            if (! $tgtBucket) {
                $tgtBucket = text(
                    label: 'Target S3 bucket name',
                    placeholder: 'my-target-bucket',
                    required: true,
                );
            }

            if ($srcBucket && $tgtBucket) {
                return [[
                    'source_bucket' => $srcBucket,
                    'target_bucket' => $tgtBucket,
                    'prefix' => (string) ($this->option('prefix') ?: ''),
                ]];
            }
        }

        return [];
    }

    /**
     * Resolve StorageSyncService instance, supporting container injection for tests.
     */
    protected function getSyncService(array $sourceConfig, array $targetConfig): StorageSyncService
    {
        if ($this->laravel->bound(StorageSyncService::class)) {
            return $this->laravel->make(StorageSyncService::class);
        }

        return StorageSyncService::createFromConfig($sourceConfig, $targetConfig);
    }

    /**
     * Render single file progress line.
     */
    protected function renderProgressLine(SyncFileResult $res, int $completed, int $total): void
    {
        $prefix = "[{$completed}/{$total}]";
        $sizeFormatted = SyncResult::formatBytes($res->size);

        if ($res->isTransferred()) {
            $attempts = $res->attempts > 1 ? " (attempt {$res->attempts})" : '';
            $this->line("  <fg=green>✓</> {$prefix} <fg=white>{$res->path}</> <fg=gray>({$sizeFormatted})</>{$attempts}");
        } elseif ($res->isSkipped()) {
            $this->line("  <fg=yellow>↷</> {$prefix} <fg=white>{$res->path}</> <fg=gray>(skipped: in sync)</>");
        } elseif ($res->isPlanned()) {
            $this->line("  <fg=cyan>→</> {$prefix} <fg=white>{$res->path}</> <fg=gray>({$sizeFormatted}, planned)</>");
        } elseif ($res->isDeleted()) {
            $this->line("  <fg=red>🗑</> {$prefix} <fg=white>{$res->path}</> <fg=gray>(deleted missing)</>");
        } elseif ($res->isFailed()) {
            $this->line("  <fg=red>✗</> {$prefix} <fg=white>{$res->path}</> <fg=red>FAILED: {$res->error}</>");
        }
    }

    /**
     * Render formatted summary table for a bucket sync result.
     */
    protected function renderSummaryTable(SyncResult $result): void
    {
        $this->newLine();
        $this->table(
            ['Metric', 'Value'],
            [
                ['Source Bucket', $result->sourceBucket],
                ['Target Bucket', $result->targetBucket],
                ['Total Objects Listed', (string) $result->totalFiles],
                ['Transferred / Synced', $result->transferredFiles.' ('.$result->formattedTransferredBytes().')'],
                ['Skipped (In Sync)', (string) $result->skippedFiles],
                ['Deleted (Missing)', (string) $result->deletedFiles],
                ['Failed', (string) $result->failedFiles],
                ['Duration', round($result->durationSeconds, 2).'s'],
                ['Throughput', $result->throughput()],
                ['Execution Mode', $result->dryRun ? 'DRY-RUN' : 'LIVE'],
            ]
        );
    }
}
