<?php

namespace App\Services;

use App\Data\SyncFileResult;
use App\Data\SyncResult;
use App\Exceptions\IntegrityException;
use Aws\S3\S3Client;
use League\Flysystem\AwsS3V3\AwsS3V3Adapter;
use League\Flysystem\Filesystem;
use League\Flysystem\FilesystemOperator;
use League\Flysystem\InMemory\InMemoryFilesystemAdapter;
use League\Flysystem\StorageAttributes;
use ReflectionClass;
use RuntimeException;
use Throwable;

class StorageSyncService
{
    public function __construct(
        protected ?FilesystemOperator $source = null,
        protected ?FilesystemOperator $target = null,
        protected ?S3Client $sourceClient = null,
        protected ?S3Client $targetClient = null,
        protected string $sourceBucket = '',
        protected string $targetBucket = '',
        protected int $maxRetries = 3,
        protected int $retryDelayMs = 200,
    ) {}

    /**
     * Factory to instantiate StorageSyncService with AWS S3 / S3-compatible configuration.
     *
     * @param  array{bucket: string, region?: string, endpoint?: string, key?: string, secret?: string, prefix?: string, use_path_style_endpoint?: bool}  $sourceConfig
     * @param  array{bucket: string, region?: string, endpoint?: string, key?: string, secret?: string, prefix?: string, use_path_style_endpoint?: bool}  $targetConfig
     */
    public static function createFromConfig(
        array $sourceConfig,
        array $targetConfig,
        int $maxRetries = 3,
        int $retryDelayMs = 200,
    ): self {
        $sourceClient = self::buildS3Client($sourceConfig);
        $targetClient = self::buildS3Client($targetConfig);

        $sourceBucket = $sourceConfig['bucket'];
        $targetBucket = $targetConfig['bucket'];

        $sourceAdapter = new AwsS3V3Adapter(
            client: $sourceClient,
            bucket: $sourceBucket,
            prefix: $sourceConfig['prefix'] ?? '',
        );

        $targetAdapter = new AwsS3V3Adapter(
            client: $targetClient,
            bucket: $targetBucket,
            prefix: $targetConfig['prefix'] ?? '',
        );

        $sourceFs = new Filesystem($sourceAdapter);
        $targetFs = new Filesystem($targetAdapter);

        return new self(
            source: $sourceFs,
            target: $targetFs,
            sourceClient: $sourceClient,
            targetClient: $targetClient,
            sourceBucket: $sourceBucket,
            targetBucket: $targetBucket,
            maxRetries: $maxRetries,
            retryDelayMs: $retryDelayMs,
        );
    }

    /**
     * Helper to construct Aws S3Client with S3-compatible endpoints support.
     */
    public static function buildS3Client(array $config): S3Client
    {
        $args = [
            'version' => 'latest',
            'region' => $config['region'] ?? 'us-east-1',
        ];

        if (! empty($config['endpoint'])) {
            $args['endpoint'] = $config['endpoint'];
            $args['use_path_style_endpoint'] = $config['use_path_style_endpoint'] ?? true;
        }

        if (! empty($config['key']) && ! empty($config['secret'])) {
            $args['credentials'] = [
                'key' => $config['key'],
                'secret' => $config['secret'],
            ];
        }

        return new S3Client($args);
    }

    /**
     * Synchronize objects from source to target storage.
     *
     * @param  array{
     *     prefix?: string,
     *     concurrency?: int,
     *     dry_run?: bool,
     *     delete_missing?: bool,
     *     progress?: ?callable(SyncFileResult $result, int $completed, int $total): void,
     * }  $options
     */
    public function sync(array $options = []): SyncResult
    {
        $startTime = microtime(true);
        $prefix = $options['prefix'] ?? '';
        $concurrency = max(1, (int) ($options['concurrency'] ?? 1));
        $dryRun = (bool) ($options['dry_run'] ?? false);
        $deleteMissing = (bool) ($options['delete_missing'] ?? false);
        $progress = $options['progress'] ?? null;

        if ($this->source === null || $this->target === null) {
            throw new RuntimeException('Source and target storage operators must be configured.');
        }

        // 1. List objects on source
        $listing = $this->source->listContents($prefix, deep: true);
        $paths = [];
        /** @var StorageAttributes $item */
        foreach ($listing as $item) {
            if ($item->isFile()) {
                $paths[] = $item->path();
            }
        }

        $totalFiles = count($paths);
        $totalBytes = 0;
        $transferredFiles = 0;
        $transferredBytes = 0;
        $skippedFiles = 0;
        $failedFiles = 0;
        $deletedFiles = 0;
        $fileResults = [];
        $errors = [];

        // 2. Process transfers (concurrency vs sequential)
        if ($concurrency > 1 && $this->canFork() && count($paths) > 1) {
            $fileResults = $this->runConcurrentFork($paths, $concurrency, $dryRun, $progress);
        } else {
            $completed = 0;
            foreach ($paths as $path) {
                $result = $this->syncFile($path, $dryRun);
                $fileResults[] = $result;
                $completed++;

                if ($progress) {
                    $progress($result, $completed, $totalFiles);
                }
            }
        }

        // Tally results
        foreach ($fileResults as $result) {
            $totalBytes += $result->size;
            if ($result->isTransferred()) {
                $transferredFiles++;
                $transferredBytes += $result->size;
            } elseif ($result->isSkipped()) {
                $skippedFiles++;
            } elseif ($result->isFailed()) {
                $failedFiles++;
                if ($result->error) {
                    $errors[] = "Failed [{$result->path}]: {$result->error}";
                }
            } elseif ($result->isPlanned()) {
                $transferredFiles++;
                $transferredBytes += $result->size;
            }
        }

        // 3. Handle delete-missing if requested
        if ($deleteMissing) {
            $deleteResults = $this->processDeleteMissing($paths, $prefix, $dryRun, $progress, count($fileResults), $totalFiles);
            foreach ($deleteResults as $delResult) {
                $fileResults[] = $delResult;
                if ($delResult->isDeleted()) {
                    $deletedFiles++;
                } elseif ($delResult->isFailed()) {
                    $failedFiles++;
                    if ($delResult->error) {
                        $errors[] = "Delete failed [{$delResult->path}]: {$delResult->error}";
                    }
                }
            }
        }

        $duration = microtime(true) - $startTime;

        return new SyncResult(
            sourceBucket: $this->sourceBucket,
            targetBucket: $this->targetBucket,
            totalFiles: $totalFiles,
            transferredFiles: $transferredFiles,
            skippedFiles: $skippedFiles,
            failedFiles: $failedFiles,
            deletedFiles: $deletedFiles,
            transferredBytes: $transferredBytes,
            totalBytes: $totalBytes,
            durationSeconds: $duration,
            fileResults: $fileResults,
            errors: $errors,
            dryRun: $dryRun,
        );
    }

    /**
     * Transfer a single file via streaming resource pointer without buffering to disk.
     */
    public function syncFile(string $path, bool $dryRun = false): SyncFileResult
    {
        try {
            $sourceSize = $this->source->fileSize($path);
        } catch (Throwable $e) {
            return SyncFileResult::failed($path, 0, "Unable to read source metadata: {$e->getMessage()}");
        }

        $sourceChecksum = $this->getChecksum($this->source, $path);

        // Check if target file exists
        try {
            $targetExists = $this->target->fileExists($path);
        } catch (Throwable $e) {
            return SyncFileResult::failed($path, $sourceSize, "Target existence check failed: {$e->getMessage()}");
        }

        if ($targetExists) {
            try {
                $targetSize = $this->target->fileSize($path);
                $targetChecksum = $this->getChecksum($this->target, $path);

                if ($sourceSize === $targetSize && $this->checksumsMatch($sourceChecksum, $targetChecksum)) {
                    return SyncFileResult::skipped($path, $sourceSize, $sourceChecksum);
                }
            } catch (Throwable) {
                // If checking existing target metadata fails, proceed to overwrite transfer
            }
        }

        if ($dryRun) {
            return SyncFileResult::planned(
                path: $path,
                size: $sourceSize,
                checksum: $sourceChecksum,
                action: $targetExists ? 'Update' : 'Create',
            );
        }

        // Direct stream transfer loop with retry on transient failure
        $attempt = 0;
        $lastError = null;

        while ($attempt < $this->maxRetries) {
            $attempt++;
            $stream = null;
            try {
                $stream = $this->source->readStream($path);
                if (! is_resource($stream)) {
                    throw new RuntimeException("Could not open read stream for [{$path}]");
                }

                $config = [];
                try {
                    $mimeType = $this->source->mimeType($path);
                    if ($mimeType) {
                        $config['ContentType'] = $mimeType;
                    }
                } catch (Throwable) {
                    // Ignore mime type lookup failure
                }

                // Direct streaming transfer to target
                $this->target->writeStream($path, $stream, $config);

                if (is_resource($stream)) {
                    @fclose($stream);
                    $stream = null;
                }

                // Verify integrity (size parity & checksum comparison)
                $this->verifyIntegrity($path, $sourceSize, $sourceChecksum);

                return SyncFileResult::transferred($path, $sourceSize, $sourceChecksum, $attempt);
            } catch (Throwable $e) {
                if (is_resource($stream)) {
                    @fclose($stream);
                }

                $lastError = $e->getMessage();

                if ($attempt < $this->maxRetries && $this->isTransientError($e)) {
                    usleep($this->retryDelayMs * 1000 * $attempt);

                    continue;
                }

                return SyncFileResult::failed($path, $sourceSize, $lastError, $attempt);
            }
        }

        return SyncFileResult::failed($path, $sourceSize, $lastError ?? 'Transfer failed', $attempt);
    }

    /**
     * Delete files on target that do not exist on source under the given prefix.
     *
     * @param  array<string>  $sourcePaths
     * @return array<SyncFileResult>
     */
    protected function processDeleteMissing(
        array $sourcePaths,
        string $prefix,
        bool $dryRun,
        ?callable $progress,
        int $startCount,
        int $totalFiles,
    ): array {
        $sourceMap = array_flip($sourcePaths);
        $targetListing = $this->target->listContents($prefix, deep: true);
        $deleteResults = [];
        $currentCount = $startCount;

        /** @var StorageAttributes $item */
        foreach ($targetListing as $item) {
            if ($item->isFile() && ! isset($sourceMap[$item->path()])) {
                $path = $item->path();
                $size = 0;
                try {
                    $size = $this->target->fileSize($path);
                } catch (Throwable) {
                    // Ignore size error
                }

                if ($dryRun) {
                    $delResult = SyncFileResult::deleted($path, $size, planned: true);
                } else {
                    try {
                        $this->target->delete($path);
                        $delResult = SyncFileResult::deleted($path, $size, planned: false);
                    } catch (Throwable $e) {
                        $delResult = SyncFileResult::failed($path, $size, "Delete failed: {$e->getMessage()}");
                    }
                }

                $deleteResults[] = $delResult;
                $currentCount++;

                if ($progress) {
                    $progress($delResult, $currentCount, $totalFiles);
                }
            }
        }

        return $deleteResults;
    }

    /**
     * Verify integrity after transfer using size parity and MD5/ETag checksum.
     */
    public function verifyIntegrity(string $path, int $sourceSize, ?string $sourceChecksum): void
    {
        $targetSize = $this->target->fileSize($path);
        if ($targetSize !== $sourceSize) {
            throw IntegrityException::sizeMismatch($path, $sourceSize, $targetSize);
        }

        if ($sourceChecksum !== null) {
            $targetChecksum = $this->getChecksum($this->target, $path);
            if ($targetChecksum !== null && ! $this->checksumsMatch($sourceChecksum, $targetChecksum)) {
                throw IntegrityException::checksumMismatch($path, $sourceChecksum, $targetChecksum);
            }
        }
    }

    /**
     * Retrieve MD5 or ETag checksum from storage using Flysystem or stream hashing.
     */
    public function getChecksum(FilesystemOperator $storage, string $path): ?string
    {
        try {
            if (method_exists($storage, 'checksum')) {
                $sum = $storage->checksum($path);
                if ($sum !== null && $sum !== '') {
                    return trim($sum, '"');
                }
            }
        } catch (Throwable) {
            // Checksum provider not supported or failed, fallback to stream calculation
        }

        // Direct stream MD5 calculation fallback without disk buffering
        try {
            $stream = $storage->readStream($path);
            if (is_resource($stream)) {
                $ctx = hash_init('md5');
                hash_update_stream($ctx, $stream);
                @fclose($stream);

                return hash_final($ctx);
            }
        } catch (Throwable) {
            // Cannot compute
        }

        return null;
    }

    /**
     * Compare two checksums / ETags, handling hex normalization and S3 multipart hashes.
     */
    public function checksumsMatch(?string $sourceChecksum, ?string $targetChecksum): bool
    {
        if ($sourceChecksum === null || $targetChecksum === null) {
            return false;
        }

        $src = strtolower(trim($sourceChecksum, '"'));
        $tgt = strtolower(trim($targetChecksum, '"'));

        if ($src === $tgt) {
            return true;
        }

        // If neither is a multipart ETag (does not contain -part suffix), they must match exactly
        if (! str_contains($src, '-') && ! str_contains($tgt, '-')) {
            return false;
        }

        return $src === $tgt;
    }

    /**
     * Determine if an exception represents a transient failure eligible for retry.
     */
    public function isTransientError(Throwable $e): bool
    {
        $msg = strtolower($e->getMessage());
        $transientKeywords = [
            'timeout',
            'timed out',
            'connection reset',
            'broken pipe',
            'slowdown',
            'requesttimeout',
            'throttling',
            '500 internal',
            '502 bad gateway',
            '503 service unavailable',
            '504 gateway timeout',
            'curl error 28',
            'curl error 56',
            'temporary',
            'transient',
            'integrity',
            'checksum mismatch',
            'size parity mismatch',
        ];

        foreach ($transientKeywords as $keyword) {
            if (str_contains($msg, $keyword)) {
                return true;
            }
        }

        return false;
    }

    /**
     * Partition paths list evenly among concurrency workers.
     *
     * @param  array<string>  $paths
     * @return array<int, array<string>>
     */
    public function partitionPaths(array $paths, int $concurrency): array
    {
        $partitions = array_fill(0, min($concurrency, count($paths)), []);
        $count = count($partitions);

        foreach ($paths as $idx => $path) {
            $partitions[$idx % $count][] = $path;
        }

        return $partitions;
    }

    /**
     * Execute parallel transfer via pcntl_fork.
     *
     * @param  array<string>  $paths
     * @return array<SyncFileResult>
     */
    protected function runConcurrentFork(
        array $paths,
        int $concurrency,
        bool $dryRun,
        ?callable $progress = null,
    ): array {
        $partitions = $this->partitionPaths($paths, $concurrency);
        $children = [];
        $tempFiles = [];

        foreach ($partitions as $workerId => $workerPaths) {
            $tmpFile = tempnam(sys_get_temp_dir(), 's3sync_w_'.$workerId.'_');
            $tempFiles[$workerId] = $tmpFile;

            $pid = pcntl_fork();
            if ($pid === -1) {
                // Fork failed, execute remaining synchronously
                return $this->runSynchronousList($paths, $dryRun, $progress);
            } elseif ($pid === 0) {
                // Child process
                $results = [];
                foreach ($workerPaths as $p) {
                    $res = $this->syncFile($p, $dryRun);
                    $results[] = [
                        'path' => $res->path,
                        'status' => $res->status,
                        'size' => $res->size,
                        'checksum' => $res->checksum,
                        'attempts' => $res->attempts,
                        'message' => $res->message,
                        'error' => $res->error,
                    ];
                }
                file_put_contents($tmpFile, json_encode($results));
                exit(0);
            } else {
                $children[$pid] = $workerId;
            }
        }

        // Wait for all child workers
        foreach (array_keys($children) as $childPid) {
            pcntl_waitpid($childPid, $status);
        }

        // Collect and aggregate results
        $aggregated = [];
        $completed = 0;
        $totalFiles = count($paths);

        foreach ($tempFiles as $workerId => $tmpFile) {
            if (file_exists($tmpFile)) {
                $data = json_decode((string) file_get_contents($tmpFile), true);
                if (is_array($data)) {
                    foreach ($data as $item) {
                        $res = new SyncFileResult(
                            path: $item['path'],
                            status: $item['status'],
                            size: (int) ($item['size'] ?? 0),
                            checksum: $item['checksum'] ?? null,
                            attempts: (int) ($item['attempts'] ?? 1),
                            message: $item['message'] ?? null,
                            error: $item['error'] ?? null,
                        );
                        $aggregated[] = $res;
                        $completed++;
                        if ($progress) {
                            $progress($res, $completed, $totalFiles);
                        }
                    }
                }
                @unlink($tmpFile);
            }
        }

        return $aggregated;
    }

    /**
     * Fallback synchronous execution.
     *
     * @param  array<string>  $paths
     * @return array<SyncFileResult>
     */
    protected function runSynchronousList(array $paths, bool $dryRun, ?callable $progress = null): array
    {
        $results = [];
        $completed = 0;
        $total = count($paths);
        foreach ($paths as $path) {
            $res = $this->syncFile($path, $dryRun);
            $results[] = $res;
            $completed++;
            if ($progress) {
                $progress($res, $completed, $total);
            }
        }

        return $results;
    }

    /**
     * Check if pcntl_fork is supported and safe to use.
     */
    protected function canFork(): bool
    {
        if (! function_exists('pcntl_fork')) {
            return false;
        }

        if ($this->isInMemory($this->source) || $this->isInMemory($this->target)) {
            return false;
        }

        return true;
    }

    /**
     * Determine if a FilesystemOperator utilizes in-memory adapter.
     */
    protected function isInMemory(?FilesystemOperator $storage): bool
    {
        if (! $storage) {
            return false;
        }

        if ($storage instanceof Filesystem) {
            try {
                $ref = new ReflectionClass($storage);
                if ($ref->hasProperty('adapter')) {
                    $prop = $ref->getProperty('adapter');
                    $prop->setAccessible(true);
                    $adapter = $prop->getValue($storage);

                    return $adapter instanceof InMemoryFilesystemAdapter;
                }
            } catch (Throwable) {
                return false;
            }
        }

        return false;
    }

    // Getters and Setters
    public function getSource(): ?FilesystemOperator
    {
        return $this->source;
    }

    public function getTarget(): ?FilesystemOperator
    {
        return $this->target;
    }

    public function getSourceBucket(): string
    {
        return $this->sourceBucket;
    }

    public function getTargetBucket(): string
    {
        return $this->targetBucket;
    }

    public function getMaxRetries(): int
    {
        return $this->maxRetries;
    }

    public function setMaxRetries(int $retries): self
    {
        $this->maxRetries = $retries;

        return $this;
    }

    public function setRetryDelayMs(int $delayMs): self
    {
        $this->retryDelayMs = $delayMs;

        return $this;
    }
}
