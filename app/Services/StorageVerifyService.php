<?php

namespace App\Services;

use App\Data\StorageParityItem;
use App\Data\StorageVerifyResult;
use Aws\S3\S3Client;
use League\Flysystem\AwsS3V3\AwsS3V3Adapter;
use League\Flysystem\FileAttributes;
use League\Flysystem\Filesystem;
use League\Flysystem\FilesystemOperator;
use League\Flysystem\StorageAttributes;
use RuntimeException;
use Throwable;

class StorageVerifyService
{
    /**
     * @param  (callable(FilesystemOperator, string, ?StorageAttributes, ?S3Client, ?string): ?string)|null  $checksumResolver
     */
    public function __construct(
        protected ?FilesystemOperator $source = null,
        protected ?FilesystemOperator $target = null,
        protected ?S3Client $sourceClient = null,
        protected ?S3Client $targetClient = null,
        protected string $sourceBucket = '',
        protected string $targetBucket = '',
        protected $checksumResolver = null,
    ) {}

    /**
     * Factory to instantiate StorageVerifyService with AWS S3 / S3-compatible configuration.
     *
     * @param  array{bucket: string, region?: string, endpoint?: string, key?: string, secret?: string, prefix?: string, use_path_style_endpoint?: bool}  $sourceConfig
     * @param  array{bucket: string, region?: string, endpoint?: string, key?: string, secret?: string, prefix?: string, use_path_style_endpoint?: bool}  $targetConfig
     */
    public static function createFromConfig(
        array $sourceConfig,
        array $targetConfig,
        ?callable $checksumResolver = null,
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
            checksumResolver: $checksumResolver,
        );
    }

    /**
     * Construct Aws S3Client with S3-compatible endpoints support.
     *
     * @param  array{region?: string, endpoint?: string, use_path_style_endpoint?: bool, key?: string, secret?: string}  $config
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
     * Normalize checksum or ETag string by stripping quotes, whitespace, and lowercasing.
     */
    public static function normalizeChecksum(?string $checksum): ?string
    {
        if ($checksum === null) {
            return null;
        }

        $trimmed = trim($checksum, " \t\n\r\0\x0B\"'");

        return $trimmed !== '' ? strtolower($trimmed) : null;
    }

    /**
     * Compare two checksums / ETags, handling quoted strings, case insensitivity,
     * and multipart ETag formats ("<hash>-<part_count>").
     */
    public function checksumsMatch(?string $sourceChecksum, ?string $targetChecksum): bool
    {
        $src = self::normalizeChecksum($sourceChecksum);
        $tgt = self::normalizeChecksum($targetChecksum);

        if ($src === null || $tgt === null) {
            return false;
        }

        return $src === $tgt;
    }

    /**
     * Retrieve MD5 or ETag checksum without downloading file content (zero-download guarantee).
     */
    public function getChecksum(
        FilesystemOperator $storage,
        string $path,
        ?StorageAttributes $attributes = null,
        ?S3Client $client = null,
        ?string $bucket = null,
    ): ?string {
        if ($this->checksumResolver !== null) {
            $resolved = ($this->checksumResolver)($storage, $path, $attributes, $client, $bucket);
            if ($resolved !== null) {
                return self::normalizeChecksum($resolved);
            }
        }

        // 1. Check if extraMetadata from listContents already includes ETag
        if ($attributes instanceof FileAttributes) {
            $extra = $attributes->extraMetadata();
            if (isset($extra['ETag']) && $extra['ETag'] !== '') {
                return self::normalizeChecksum((string) $extra['ETag']);
            }
            if (isset($extra['etag']) && $extra['etag'] !== '') {
                return self::normalizeChecksum((string) $extra['etag']);
            }
        }

        // 2. Query Flysystem checksum metadata (calls HeadObject on S3, or computes in memory)
        try {
            if (method_exists($storage, 'checksum')) {
                $sum = $storage->checksum($path, ['checksum_algo' => 'etag']);
                if ($sum !== null && $sum !== '') {
                    return self::normalizeChecksum($sum);
                }
            }
        } catch (Throwable) {
            try {
                if (method_exists($storage, 'checksum')) {
                    $sum = $storage->checksum($path);
                    if ($sum !== null && $sum !== '') {
                        return self::normalizeChecksum($sum);
                    }
                }
            } catch (Throwable) {
                // Ignore and try direct S3 HeadObject
            }
        }

        // 3. Query direct HeadObject on S3 client if available
        if ($client !== null && ! empty($bucket)) {
            try {
                $head = $client->headObject([
                    'Bucket' => $bucket,
                    'Key' => $path,
                ]);
                if (! empty($head['ETag'])) {
                    return self::normalizeChecksum((string) $head['ETag']);
                }
            } catch (Throwable) {
                // HeadObject failed
            }
        }

        return null;
    }

    /**
     * Verify parity for a single object without downloading file content.
     */
    public function verifyFile(
        string $path,
        ?StorageAttributes $sourceAttr = null,
        ?StorageAttributes $targetAttr = null,
    ): StorageParityItem {
        if ($this->source === null || $this->target === null) {
            throw new RuntimeException('Source and target storage operators must be configured.');
        }

        // 1. Source metadata
        try {
            $sourceSize = ($sourceAttr instanceof FileAttributes && $sourceAttr->fileSize() !== null)
                ? $sourceAttr->fileSize()
                : $this->source->fileSize($path);
        } catch (Throwable $e) {
            return StorageParityItem::error($path, "Failed reading source metadata: {$e->getMessage()}");
        }

        $sourceChecksum = $this->getChecksum(
            $this->source,
            $path,
            $sourceAttr,
            $this->sourceClient,
            $this->sourceBucket,
        );

        // 2. Check target existence
        $targetExists = false;
        if ($targetAttr !== null) {
            $targetExists = true;
        } else {
            try {
                $targetExists = $this->target->fileExists($path);
            } catch (Throwable $e) {
                return StorageParityItem::error($path, "Target existence check failed: {$e->getMessage()}", $sourceSize);
            }
        }

        if (! $targetExists) {
            return StorageParityItem::missingInTarget($path, $sourceSize, $sourceChecksum);
        }

        // 3. Target metadata
        try {
            $targetSize = ($targetAttr instanceof FileAttributes && $targetAttr->fileSize() !== null)
                ? $targetAttr->fileSize()
                : $this->target->fileSize($path);
        } catch (Throwable $e) {
            return StorageParityItem::error($path, "Failed reading target metadata: {$e->getMessage()}", $sourceSize);
        }

        $targetChecksum = $this->getChecksum(
            $this->target,
            $path,
            $targetAttr,
            $this->targetClient,
            $this->targetBucket,
        );

        // 4. Size parity check
        if ($sourceSize !== $targetSize) {
            return StorageParityItem::sizeMismatch(
                path: $path,
                sourceSize: $sourceSize,
                targetSize: $targetSize,
                sourceChecksum: $sourceChecksum,
                targetChecksum: $targetChecksum,
            );
        }

        // 5. Checksum parity check
        if (! $this->checksumsMatch($sourceChecksum, $targetChecksum)) {
            return StorageParityItem::checksumMismatch(
                path: $path,
                sourceSize: $sourceSize,
                targetSize: $targetSize,
                sourceChecksum: $sourceChecksum,
                targetChecksum: $targetChecksum,
            );
        }

        return StorageParityItem::matched(
            path: $path,
            sourceSize: $sourceSize,
            targetSize: $targetSize,
            sourceChecksum: $sourceChecksum,
            targetChecksum: $targetChecksum,
        );
    }

    /**
     * Verify all objects under prefix without downloading physical contents.
     *
     * @param  array{
     *     prefix?: string,
     *     progress?: ?callable(StorageParityItem $item, int $completed, int $total): void,
     *     paths?: array<string>,
     * }  $options
     */
    public function verify(array $options = []): StorageVerifyResult
    {
        $startTime = microtime(true);
        $prefix = $options['prefix'] ?? '';
        $progress = $options['progress'] ?? null;
        $explicitPaths = $options['paths'] ?? null;

        if ($this->source === null || $this->target === null) {
            throw new RuntimeException('Source and target storage operators must be configured.');
        }

        $sourceItems = [];
        $errors = [];

        // 1. Enumerate source files
        if (is_array($explicitPaths)) {
            foreach ($explicitPaths as $path) {
                $sourceItems[$path] = null;
            }
        } else {
            try {
                $sourceListing = $this->source->listContents($prefix, deep: true);
                /** @var StorageAttributes $item */
                foreach ($sourceListing as $item) {
                    if ($item->isFile()) {
                        $sourceItems[$item->path()] = $item;
                    }
                }
            } catch (Throwable $e) {
                $errors[] = "Failed listing source bucket [{$this->sourceBucket}]: {$e->getMessage()}";

                return new StorageVerifyResult(
                    sourceBucket: $this->sourceBucket,
                    targetBucket: $this->targetBucket,
                    totalObjects: 0,
                    durationSeconds: microtime(true) - $startTime,
                    errors: $errors,
                    prefix: $prefix,
                );
            }
        }

        // 2. Pre-index target objects for fast zero-download comparison
        $targetMap = [];
        try {
            $targetListing = $this->target->listContents($prefix, deep: true);
            /** @var StorageAttributes $tItem */
            foreach ($targetListing as $tItem) {
                if ($tItem->isFile()) {
                    $targetMap[$tItem->path()] = $tItem;
                }
            }
        } catch (Throwable) {
            // Target listing may fail if empty or restricted; fallback to direct per-file inspection
            $targetMap = null;
        }

        $totalObjects = count($sourceItems);
        $matchedCount = 0;
        $missingCount = 0;
        $sizeMismatchCount = 0;
        $checksumMismatchCount = 0;
        $errorCount = 0;
        $totalSourceBytes = 0;
        $totalTargetBytes = 0;
        $items = [];
        $completed = 0;

        // 3. Inspect metadata for each item
        foreach ($sourceItems as $path => $sourceAttr) {
            $targetAttr = $targetMap !== null ? ($targetMap[$path] ?? null) : null;
            $itemResult = $this->verifyFile($path, $sourceAttr, $targetAttr);
            $items[] = $itemResult;
            $completed++;

            $totalSourceBytes += $itemResult->sourceSize;
            if ($itemResult->targetSize !== null) {
                $totalTargetBytes += $itemResult->targetSize;
            }

            if ($itemResult->isMatched()) {
                $matchedCount++;
            } elseif ($itemResult->isMissingInTarget()) {
                $missingCount++;
            } elseif ($itemResult->isSizeMismatch()) {
                $sizeMismatchCount++;
            } elseif ($itemResult->isChecksumMismatch()) {
                $checksumMismatchCount++;
            } elseif ($itemResult->isError()) {
                $errorCount++;
                if ($itemResult->error) {
                    $errors[] = "Error [{$path}]: {$itemResult->error}";
                }
            }

            if ($progress) {
                $progress($itemResult, $completed, $totalObjects);
            }
        }

        $duration = microtime(true) - $startTime;

        return new StorageVerifyResult(
            sourceBucket: $this->sourceBucket,
            targetBucket: $this->targetBucket,
            totalObjects: $totalObjects,
            matchedCount: $matchedCount,
            missingCount: $missingCount,
            sizeMismatchCount: $sizeMismatchCount,
            checksumMismatchCount: $checksumMismatchCount,
            errorCount: $errorCount,
            totalSourceBytes: $totalSourceBytes,
            totalTargetBytes: $totalTargetBytes,
            durationSeconds: $duration,
            items: $items,
            errors: $errors,
            prefix: $prefix,
        );
    }

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

    public function getSourceClient(): ?S3Client
    {
        return $this->sourceClient;
    }

    public function getTargetClient(): ?S3Client
    {
        return $this->targetClient;
    }

    public function setChecksumResolver(?callable $resolver): self
    {
        $this->checksumResolver = $resolver;

        return $this;
    }
}
