<?php

namespace App\Commands;

use App\Data\StorageParityItem;
use App\Data\StorageVerifyResult;
use App\Services\MigrationManifest;
use App\Services\StorageVerifyService;
use LaravelZero\Framework\Commands\Command;
use Throwable;

use function Laravel\Prompts\confirm;
use function Laravel\Prompts\error;
use function Laravel\Prompts\info;
use function Laravel\Prompts\note;
use function Laravel\Prompts\password;
use function Laravel\Prompts\text;

class VerifyStorageCommand extends Command
{
    protected $signature = 'storage:verify
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
                            {--prefix= : Only verify objects starting with this prefix}
                            {--manifest= : Path to declarative migration manifest (default: migration-plan.json if exists)}
                            {--json= : Export verification results to a JSON file (or storage-verify.json)}
                            {--only-mismatches : Only show mismatched or missing items}
                            {--yes : Proceed without confirmation prompt}';

    protected $description = 'Verify object storage / S3 bucket parity (size & ETag checksum) without downloading file bodies';

    public function handle(): int
    {
        $this->newLine();
        info('Laravel Cloud Migrator — Production Object Storage Parity Verification');
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

        if (! $this->laravel->bound(StorageVerifyService::class) && (! $sourceKey || ! $sourceSecret || ! $targetKey || ! $targetSecret)) {
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

        $prefix = (string) ($this->option('prefix') ?: '');

        // 3. Pre-flight plan display
        $this->line('<fg=yellow;options=bold>── Storage Parity Verification Plan ──</>');
        $this->line('  Bucket Pairs    : <fg=white>'.count($bucketPairs).' pair(s)</>');
        foreach ($bucketPairs as $idx => $pair) {
            $pairPrefix = ! empty($pair['prefix']) ? $pair['prefix'] : ($prefix ?: '(all)');
            $this->line('    [#'.($idx + 1)."] <fg=cyan>{$pair['source_bucket']}</> → <fg=green>{$pair['target_bucket']}</> (prefix: {$pairPrefix})");
        }
        $this->line("  Source Region   : <fg=white>{$sourceRegion}</>".($sourceEndpoint ? " (endpoint: {$sourceEndpoint})" : ''));
        $this->line("  Target Region   : <fg=white>{$targetRegion}</>".($targetEndpoint ? " (endpoint: {$targetEndpoint})" : ''));
        $this->line('  Inspection Mode : <fg=cyan>ZERO-DOWNLOAD AUDIT (metadata inspection & ETag parity)</>');
        $this->newLine();

        if (! $this->option('yes') && ! confirm('Proceed with storage parity verification?', true)) {
            note('Verification cancelled.');

            return self::SUCCESS;
        }

        $allResults = [];
        $hasDiscrepancies = false;

        // 4. Run verification for each bucket pair
        foreach ($bucketPairs as $pair) {
            $srcBucket = $pair['source_bucket'];
            $tgtBucket = $pair['target_bucket'];
            $effectivePrefix = ! empty($pair['prefix']) ? $pair['prefix'] : $prefix;

            $this->newLine();
            $this->line("<fg=cyan;options=bold>── Verifying {$srcBucket} vs {$tgtBucket} ──</>");

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
                $service = $this->getVerifyService($sourceConfig, $targetConfig);

                $result = $service->verify([
                    'prefix' => $effectivePrefix,
                    'progress' => function (StorageParityItem $item, int $completed, int $total) {
                        $this->renderProgressLine($item, $completed, $total);
                    },
                ]);

                $allResults[$srcBucket] = $result;
                if ($result->hasDiscrepancies()) {
                    $hasDiscrepancies = true;
                }

                $this->renderSummaryTable($result);

                if ($result->hasDiscrepancies()) {
                    $this->renderDiscrepancyTable($result);
                }
            } catch (Throwable $e) {
                error("Failed verifying bucket pair [{$srcBucket} vs {$tgtBucket}]: {$e->getMessage()}");
                $hasDiscrepancies = true;
            }
        }

        // 5. Handle JSON export if requested
        $hasJsonFlag = $this->input->hasParameterOption('--json');
        $jsonPathOption = $this->option('json');
        if ($hasJsonFlag || ($jsonPathOption !== null && $jsonPathOption !== '')) {
            $exportFile = ($jsonPathOption !== null && $jsonPathOption !== '') ? $jsonPathOption : 'storage-verify.json';
            $exportData = [
                'timestamp' => date('c'),
                'is_parity_100' => ! $hasDiscrepancies,
                'total_buckets' => count($allResults),
                'buckets' => array_map(fn (StorageVerifyResult $r) => $r->toArray(), $allResults),
            ];

            file_put_contents($exportFile, json_encode($exportData, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
            $this->newLine();
            info("Exported verification results to {$exportFile}");
        }

        $this->newLine();
        if ($hasDiscrepancies) {
            error('Storage verification failed with discrepancies detected. Parity is not 100%.');

            return self::FAILURE;
        }

        info('Storage verification completed successfully. 100% parity verified across all buckets.');

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
     * Resolve StorageVerifyService instance, supporting container injection for tests.
     */
    protected function getVerifyService(array $sourceConfig, array $targetConfig): StorageVerifyService
    {
        if ($this->laravel->bound(StorageVerifyService::class)) {
            return $this->laravel->make(StorageVerifyService::class);
        }

        return StorageVerifyService::createFromConfig($sourceConfig, $targetConfig);
    }

    /**
     * Render single file progress line.
     */
    protected function renderProgressLine(StorageParityItem $res, int $completed, int $total): void
    {
        $onlyMismatches = (bool) $this->option('only-mismatches');
        if ($onlyMismatches && $res->isMatched()) {
            return;
        }

        $prefix = "[{$completed}/{$total}]";
        $sizeFormatted = StorageVerifyResult::formatBytes($res->sourceSize);

        if ($res->isMatched()) {
            $etag = $res->sourceChecksum ? " (ETag: {$res->sourceChecksum})" : '';
            $this->line("  <fg=green>✓</> {$prefix} <fg=white>{$res->path}</> <fg=gray>({$sizeFormatted}{$etag})</>");
        } elseif ($res->isMissingInTarget()) {
            $this->line("  <fg=red>✗</> {$prefix} <fg=white>{$res->path}</> <fg=red>(MISSING IN TARGET)</>");
        } elseif ($res->isSizeMismatch()) {
            $tgtSizeFormatted = StorageVerifyResult::formatBytes($res->targetSize ?? 0);
            $this->line("  <fg=yellow>≠</> {$prefix} <fg=white>{$res->path}</> <fg=yellow>(SIZE MISMATCH: src {$sizeFormatted} vs tgt {$tgtSizeFormatted})</>");
        } elseif ($res->isChecksumMismatch()) {
            $this->line("  <fg=magenta>#</> {$prefix} <fg=white>{$res->path}</> <fg=magenta>(CHECKSUM MISMATCH: src {$res->sourceChecksum} vs tgt {$res->targetChecksum})</>");
        } elseif ($res->isError()) {
            $this->line("  <fg=red>!</> {$prefix} <fg=white>{$res->path}</> <fg=red>ERROR: {$res->error}</>");
        }
    }

    /**
     * Render formatted summary table for a bucket verify result.
     */
    protected function renderSummaryTable(StorageVerifyResult $result): void
    {
        $this->newLine();
        $statusDisplay = $result->isParity100()
            ? '<fg=green;options=bold>100% PARITY (MATCHED)</>'
            : '<fg=red;options=bold>DISCREPANCY DETECTED ('.$result->parityPercentage().'%)</>';

        $this->table(
            ['Metric', 'Value'],
            [
                ['Source Bucket', $result->sourceBucket],
                ['Target Bucket', $result->targetBucket],
                ['Prefix Filter', $result->prefix !== '' ? $result->prefix : '(all)'],
                ['Total Objects Inspected', (string) $result->totalObjects],
                ['Matched Objects (100%)', '<fg=green>'.$result->matchedCount.'</>'],
                ['Missing in Target', $result->missingCount > 0 ? '<fg=red>'.$result->missingCount.'</>' : '0'],
                ['Size Mismatches', $result->sizeMismatchCount > 0 ? '<fg=yellow>'.$result->sizeMismatchCount.'</>' : '0'],
                ['Checksum Mismatches', $result->checksumMismatchCount > 0 ? '<fg=magenta>'.$result->checksumMismatchCount.'</>' : '0'],
                ['Errors', $result->errorCount > 0 ? '<fg=red>'.$result->errorCount.'</>' : '0'],
                ['Total Source Data', $result->formattedSourceBytes()],
                ['Total Target Data', $result->formattedTargetBytes()],
                ['Inspection Duration', round($result->durationSeconds, 2).'s'],
                ['Parity Status', $statusDisplay],
            ]
        );
    }

    /**
     * Render detailed table for all discrepancy items in a bucket.
     */
    protected function renderDiscrepancyTable(StorageVerifyResult $result): void
    {
        $discrepancies = $result->getDiscrepancies();
        if (empty($discrepancies)) {
            return;
        }

        $this->newLine();
        $this->line('<fg=red;options=bold>── Discrepancy Detail Items ──</>');

        $rows = [];
        foreach ($discrepancies as $item) {
            $statusLabel = match ($item->status) {
                StorageParityItem::STATUS_MISSING_IN_TARGET => '<fg=red>Missing in Target</>',
                StorageParityItem::STATUS_SIZE_MISMATCH => '<fg=yellow>Size Mismatch</>',
                StorageParityItem::STATUS_CHECKSUM_MISMATCH => '<fg=magenta>Checksum Mismatch</>',
                StorageParityItem::STATUS_ERROR => '<fg=red>Error</>',
                default => $item->status,
            };

            $srcSize = StorageVerifyResult::formatBytes($item->sourceSize);
            $tgtSize = $item->targetSize !== null ? StorageVerifyResult::formatBytes($item->targetSize) : '—';
            $srcEtag = $item->sourceChecksum ?: '—';
            $tgtEtag = $item->targetChecksum ?: '—';

            $rows[] = [
                $statusLabel,
                $item->path,
                $srcSize,
                $tgtSize,
                $srcEtag,
                $tgtEtag,
            ];
        }

        $this->table(
            ['Status', 'Object Path', 'Source Size', 'Target Size', 'Source Checksum', 'Target Checksum'],
            $rows
        );
    }
}
